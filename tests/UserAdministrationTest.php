<?php

declare(strict_types=1);

namespace App\Tests;

use App\Api\OpenApiContract;
use App\Security\StockUserProvider;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UserAdministrationTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private string $admin;
    private string $user;
    private string $manager;
    private string $password = 'Test-administration-2026!';
    private string $csrf;

    protected function setUp(): void
    {
        $this->client = static::createClient(); $this->client->disableReboot();
        $this->db = static::getContainer()->get(Connection::class);
        $this->db->beginTransaction();
        $schema = 'auth02_'.bin2hex(random_bytes(6));
        $this->db->executeStatement('CREATE SCHEMA '.$schema);
        $this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        foreach (explode('-- migrate:statement', file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) {
            if (trim($sql) !== '') { $this->db->executeStatement($sql); }
        }
        \App\Tests\CatalogDeletionSchema::apply($this->db);
        $this->db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
        require_once dirname(__DIR__).'/migrations/Version20261018120000.php';
        $migration = new \DoctrineMigrations\Version20261018120000($this->db, new \Psr\Log\NullLogger());
        $migration->up(new \Doctrine\DBAL\Schema\Schema());
        foreach ($migration->getSql() as $sql) { $this->db->executeStatement($sql->getStatement()); }
        $hash = password_hash($this->password, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1]);
        foreach (['admin' => 1, 'manager' => 2, 'user' => 3] as $name => $role) {
            $this->$name = $this->db->fetchOne('INSERT INTO users (email, name, password_hash, role_id) VALUES (?, ?, ?, ?) RETURNING id', [$name.'@example.test', ucfirst($name), $hash, $role]);
        }
        $this->client->setServerParameter('REMOTE_ADDR', '10.'.random_int(1,254).'.'.random_int(1,254).'.'.random_int(1,254));
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) { $this->db->rollBack(); }
        parent::tearDown();
    }

    private function login(string $name = 'admin', ?string $password = null): void
    {
        $this->client->request('GET', '/api/v1/auth/csrf');
        $token = json_decode($this->client->getResponse()->getContent(), true)['token'];
        $this->client->request('POST', '/api/v1/auth/login', server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$token], content:json_encode(['email'=>$name.'@example.test','password'=>$password ?? $this->password]));
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/v1/auth/csrf');
        $this->csrf = json_decode($this->client->getResponse()->getContent(), true)['token'];
    }

    private function send(string $method, string $path, mixed $body = null, string $key = 'new-user-key-123'): array
    {
        $this->client->request($method, $path, server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>$key], content:$body === null ? null : json_encode($body));
        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    private function newUser(array $overrides = []): array
    {
        return array_replace(['email'=>'new@example.test','name'=>'Nouveau <test>','password'=>'Initial-password-2026!','role'=>'USER','store_ids'=>[]], $overrides);
    }

    public function testAnonymousAndOtherRolesCannotAdministerAccounts(): void
    {
        $this->client->request('GET', '/api/v1/users'); self::assertResponseStatusCodeSame(401);
        $this->client->request('GET', '/administration/comptes'); self::assertResponseRedirects('/connexion');
        foreach (['user', 'manager'] as $name) {
            $this->client->getCookieJar()->clear(); $this->login($name);
            foreach (['/api/v1/users','/api/v1/users/'.$this->admin,'/administration/comptes','/administration/comptes/nouveau','/administration/comptes/'.$this->user.'/modifier','/espace/administration'] as $path) {
                $this->client->request('GET', $path); self::assertResponseStatusCodeSame(403);
            }
            $this->send('POST','/api/v1/users',$this->newUser()); self::assertResponseStatusCodeSame(403);
            $this->send('PATCH','/api/v1/users/'.$this->admin,['active'=>false]); self::assertResponseStatusCodeSame(403);
        }
        self::assertSame(3,(int)$this->db->fetchOne('SELECT count(*) FROM users'));
    }

    public function testCreateReadListAndExactIdempotentReplay(): void
    {
        $this->login();
        $store = $this->db->fetchOne("INSERT INTO stores (code,name) VALUES ('A','Magasin A') RETURNING id");
        $input = $this->newUser(['store_ids'=>[$store]]);
        $created = $this->send('POST','/api/v1/users',$input); self::assertResponseStatusCodeSame(201);
        $id = $created['data']['id']; self::assertResponseHeaderSame('Location','/api/v1/users/'.$id);
        self::assertSame([$store],$created['data']['store_ids']);
        $hash = $this->db->fetchOne('SELECT password_hash FROM users WHERE id = ?',[$id]);
        self::assertStringStartsWith('$argon2id$',$hash); self::assertTrue(password_verify($input['password'],$hash));
        self::assertTrue(static::getContainer()->get(OpenApiContract::class)->valid(json_decode(json_encode($created)),['$ref'=>'#/components/schemas/UserResource']));
        $this->send('PATCH','/api/v1/users/'.$id,['name'=>'Nom modifié']); self::assertResponseIsSuccessful();
        $replayed = $this->send('POST','/api/v1/users',$input); self::assertResponseStatusCodeSame(201);
        self::assertEquals($created,$replayed);
        self::assertSame(4,(int)$this->db->fetchOne('SELECT count(*) FROM users'));
        self::assertSame(1,(int)$this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE action = 'user.created'"));
        $this->send('POST','/api/v1/users',$this->newUser(['name'=>'Autre nom'])); self::assertResponseStatusCodeSame(409);
        $this->client->request('GET','/api/v1/users/'.$id); self::assertResponseIsSuccessful();
        self::assertSame('Nom modifié',json_decode($this->client->getResponse()->getContent(),true)['data']['name']);
        $audit = json_encode($this->db->fetchAllAssociative("SELECT * FROM audit_logs WHERE action LIKE 'user.%'"));
        $requests = json_encode($this->db->fetchAllAssociative('SELECT * FROM user_creation_requests'));
        foreach ([$input['password'],$hash] as $secret) { self::assertStringNotContainsString($secret,$audit); self::assertStringNotContainsString($secret,$requests); }
        self::assertStringNotContainsString($input['email'],$audit);
        $this->client->getCookieJar()->clear(); $this->login('new', $input['password']);
    }

    public function testFiltersSortingAndPaginationAreBounded(): void
    {
        $this->login();
        $this->client->request('GET','/api/v1/users?role=USER&status=active&limit=1&sort=email&order=desc');
        self::assertResponseIsSuccessful(); $result=json_decode($this->client->getResponse()->getContent(),true);
        self::assertSame(1,$result['meta']['total']); self::assertCount(1,$result['data']); self::assertSame($this->user,$result['data'][0]['id']);
        $this->client->request('GET','/api/v1/users?page=20&limit=1'); self::assertResponseIsSuccessful();
        $result=json_decode($this->client->getResponse()->getContent(),true);self::assertSame(3,$result['meta']['total']);self::assertSame([],$result['data']);
        foreach (['limit=101','sort=password_hash','role=ROOT','status=unknown','unexpected=1'] as $filter) { $this->client->request('GET','/api/v1/users?'.$filter);self::assertResponseStatusCodeSame(422); }
        $this->client->request('GET','/administration/comptes?role=&status=&sort=name');self::assertResponseIsSuccessful();
    }

    public function testValidationAndDuplicateEmailsNeverLeavePartialWrites(): void
    {
        $this->login();
        $inactive=$this->db->fetchOne("INSERT INTO stores (code,name,active) VALUES ('Z','Fermé',false) RETURNING id");
        foreach ([['name'=>'   '],['name'=>"Texte\0invalide"],['password'=>'short'],['role'=>'ROOT'],['store_ids'=>[$inactive]],['store_ids'=>['11111111-1111-4111-8111-111111111111']],['development_only'=>true]] as $override) {
            $this->send('POST','/api/v1/users',$this->newUser($override));self::assertResponseStatusCodeSame(422);
        }
        $this->send('POST','/api/v1/users',$this->newUser(['email'=>'ADMIN@example.test']));self::assertResponseStatusCodeSame(409);
        $this->send('POST','/api/v1/users',$this->newUser(), '');self::assertResponseStatusCodeSame(422);
        $this->send('PATCH','/api/v1/users/'.$this->user,['email'=>'manager@example.test']);self::assertResponseStatusCodeSame(409);
        $crawler = $this->client->request('GET', '/administration/comptes/nouveau');
        $form = $crawler->selectButton('Créer le compte')->form();
        $values = $form->getPhpValues();
        $values = array_replace($values, ['name' => "Invalid-\xC3\x28", 'email' => 'invalid-text@example.test', 'password' => 'Valid-password-2026!', 'role' => 'USER']);
        $this->client->request('POST', '/administration/comptes/nouveau', $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(3,(int)$this->db->fetchOne('SELECT count(*) FROM users'));
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM user_creation_requests'));
        self::assertSame(0,(int)$this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE action LIKE 'user.%'"));
    }

    public function testDeactivationImmediatelyRejectsSessionsAndNewLogins(): void
    {
        $this->login('user');$userCookie=clone $this->client->getCookieJar()->all()[0];
        $this->client->getCookieJar()->clear();$this->login();
        $result=$this->send('PATCH','/api/v1/users/'.$this->user,['active'=>false]);self::assertResponseIsSuccessful();self::assertFalse($result['data']['active']);
        self::assertSame('1',(string)$this->db->fetchOne('SELECT auth_version FROM users WHERE id = ?',[$this->user]));
        $this->client->getCookieJar()->clear();$this->client->getCookieJar()->set($userCookie);
        $this->client->request('GET','/api/v1/auth/me');self::assertResponseStatusCodeSame(401);
        $this->client->request('GET','/api/v1/auth/csrf');$csrf=json_decode($this->client->getResponse()->getContent(),true)['token'];
        $this->client->request('POST','/api/v1/auth/login',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$csrf],content:json_encode(['email'=>'user@example.test','password'=>$this->password]));self::assertResponseStatusCodeSame(401);
    }

    public function testReactivationDoesNotRestoreAnUntouchedOldSession(): void
    {
        $this->login('user');$userCookie=clone $this->client->getCookieJar()->all()[0];
        $this->client->getCookieJar()->clear();$this->login();
        $this->send('PATCH','/api/v1/users/'.$this->user,['active'=>false]);self::assertResponseIsSuccessful();
        $this->send('PATCH','/api/v1/users/'.$this->user,['active'=>true]);self::assertResponseIsSuccessful();
        $this->client->getCookieJar()->clear();$this->client->getCookieJar()->set($userCookie);
        $this->client->request('GET','/api/v1/auth/me');self::assertResponseStatusCodeSame(401);
        $this->login('user');
        self::assertSame(1,(int)$this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE action='user.reactivated'"));
    }

    public function testAssignmentsRoleAndIdentityAreUpdatedAndInvalidateSessions(): void
    {
        $this->login('user'); $oldCookie = clone $this->client->getCookieJar()->all()[0];
        $this->client->getCookieJar()->clear();
        $this->login();$store=$this->db->fetchOne("INSERT INTO stores (code,name) VALUES ('B','Magasin B') RETURNING id");
        $response=$this->send('PATCH','/api/v1/users/'.$this->user,['name'=>'Nom <modifié>','email'=>'renamed@example.test','role'=>'MANAGER','store_ids'=>[$store]]);self::assertResponseIsSuccessful();
        self::assertSame('MANAGER',$response['data']['role']);self::assertSame([$store],$response['data']['store_ids']);
        self::assertSame('renamed@example.test',$response['data']['email']);
        $this->send('PATCH','/api/v1/users/'.$this->user,['store_ids'=>[]]);self::assertResponseIsSuccessful();
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM user_stores'));
        $version=$this->db->fetchOne('SELECT auth_version FROM users WHERE id = ?',[$this->user]);
        $this->send('PATCH','/api/v1/users/'.$this->user,['store_ids'=>[]]);self::assertResponseIsSuccessful();
        self::assertSame($version,$this->db->fetchOne('SELECT auth_version FROM users WHERE id = ?',[$this->user]));
        $this->client->getCookieJar()->clear(); $this->client->getCookieJar()->set($oldCookie);
        $this->client->request('GET', '/api/v1/auth/me'); self::assertResponseStatusCodeSame(401);
    }

    public function testLastAdministratorAndSelfDeactivationAreProtected(): void
    {
        $this->login();$this->send('PATCH','/api/v1/users/'.$this->admin,['active'=>false]);self::assertResponseStatusCodeSame(409);
        $this->send('PATCH','/api/v1/users/'.$this->admin,['role'=>'USER']);self::assertResponseStatusCodeSame(409);
        self::assertSame(1,(int)$this->db->fetchOne('SELECT role_id FROM users WHERE id = ?',[$this->admin]));
        $this->send('PATCH','/api/v1/users/'.$this->manager,['role'=>'ADMIN']);self::assertResponseIsSuccessful();
        $this->send('PATCH','/api/v1/users/'.strtoupper($this->admin),['active'=>false]);self::assertResponseStatusCodeSame(409);
        $this->send('PATCH','/api/v1/users/'.$this->admin,['role'=>'USER']);self::assertResponseIsSuccessful();
        $this->client->request('GET','/api/v1/users');self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymizedAccountCannotBeReactivatedAndInvalidIdsAre404(): void
    {
        $this->db->executeStatement('UPDATE users SET active=false, email=NULL, name=NULL, password_hash=NULL, anonymized_at=clock_timestamp() WHERE id = ?',[$this->user]);
        $this->login();$this->send('PATCH','/api/v1/users/'.$this->user,['active'=>true]);self::assertResponseStatusCodeSame(409);
        foreach (['not-a-uuid','11111111-1111-4111-8111-111111111111'] as $id) { $this->client->request('GET','/api/v1/users/'.$id);self::assertResponseStatusCodeSame(404); }
        $this->client->request('GET','/administration/comptes/'.$this->user.'/modifier');self::assertResponseStatusCodeSame(409);
    }

    public function testFormsCreateEditDeactivateReactivateWithoutJavascript(): void
    {
        $this->login();$crawler=$this->client->request('GET','/administration/comptes/nouveau');self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Créer le compte')->form(['email'=>'form@example.test','name'=>'Compte <formulaire>','password'=>'Mot-de-passe-formulaire!','role'=>'USER']));self::assertResponseRedirects(null,303);
        $crawler=$this->client->followRedirect();self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[value="Compte <formulaire>"]');self::assertSelectorNotExists('input[type="password"]');
        $form=$crawler->selectButton('Enregistrer les modifications')->form();$form['active']->untick();$this->client->submit($form);self::assertResponseRedirects(null,303);
        $crawler=$this->client->followRedirect();self::assertSelectorNotExists('input[name="active"][checked]');
        $form=$crawler->selectButton('Enregistrer les modifications')->form();$form['active']->tick();$this->client->submit($form);self::assertResponseRedirects(null,303);
        $this->client->followRedirect();self::assertSelectorExists('input[name="active"][checked]');
        self::assertTrue($this->db->fetchOne("SELECT active FROM users WHERE email='form@example.test'"));
    }

    public function testCsrfAndPasswordAreNotBypassedOrEchoedByForms(): void
    {
        $this->login();$this->client->request('POST','/api/v1/users',server:['CONTENT_TYPE'=>'application/json'],content:json_encode($this->newUser()));self::assertResponseStatusCodeSame(403);
        $this->client->request('POST','/administration/comptes/nouveau',['email'=>'evil@example.test','password'=>'never-echo-password']);self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('never-echo-password',$this->client->getResponse()->getContent());
        $crawler=$this->client->request('GET','/administration/comptes/nouveau');
        $this->client->submit($crawler->selectButton('Créer le compte')->form(['email'=>'admin@example.test','name'=>'Doublon','password'=>'never-echo-password','role'=>'USER']));self::assertResponseStatusCodeSame(409);
        self::assertSelectorExists('input[type="password"]:not([value])');self::assertStringNotContainsString('never-echo-password',$this->client->getResponse()->getContent());
    }
    public function testCaseInsensitiveDuplicateStoreAndDevFlagsCannotBeWritten(): void
    {
        $this->login();
        $store = $this->db->fetchOne("INSERT INTO stores (code,name) VALUES ('DUP','Magasin') RETURNING id");
        $this->send('POST','/api/v1/users',$this->newUser(['store_ids'=>[$store,strtoupper($store)]]));self::assertResponseStatusCodeSame(422);
        $this->send('PATCH','/api/v1/users/'.$this->user,['development_only'=>false]);self::assertResponseStatusCodeSame(422);
        $this->send('PATCH','/api/v1/users/'.$this->user,['password'=>'replacement-password!']);self::assertResponseStatusCodeSame(422);
    }

}
