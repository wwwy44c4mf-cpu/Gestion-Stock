<?php

declare(strict_types=1);

namespace App\Tests;

use App\Api\OpenApiContract;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StoreAdministrationTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private array $ids = [];
    private string $csrf;
    protected function setUp(): void
    {
        $this->client = static::createClient(); $this->client->disableReboot();
        $this->db = static::getContainer()->get(Connection::class); $this->db->beginTransaction();
        $schema='mag01_'.bin2hex(random_bytes(6)); $this->db->executeStatement('CREATE SCHEMA '.$schema); $this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        foreach (explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) { if (trim($sql)!=='') { $this->db->executeStatement($sql); } }
        \App\Tests\CatalogDeletionSchema::apply($this->db);
        $this->db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
        require_once dirname(__DIR__).'/migrations/Version20261018130000.php';
        $migration=new \DoctrineMigrations\Version20261018130000($this->db,new \Psr\Log\NullLogger()); $migration->up(new \Doctrine\DBAL\Schema\Schema());
        foreach ($migration->getSql() as $sql) { $this->db->executeStatement($sql->getStatement()); }
        $hash=password_hash('Store-test-2026!',PASSWORD_ARGON2ID);
        foreach (['admin'=>1,'manager'=>2,'user'=>3] as $name=>$role) {
            $this->ids[$name]=$this->db->fetchOne('INSERT INTO users (email,name,password_hash,role_id) VALUES (?,?,?,?) RETURNING id',[$name.'@example.test',ucfirst($name),$hash,$role]);
        }
    }
    protected function tearDown(): void { if ($this->db->isTransactionActive()) { $this->db->rollBack(); } parent::tearDown(); }
    private function login(string $name='admin'): void
    {
        $this->client->getCookieJar()->clear(); $this->client->loginUser(static::getContainer()->get(\App\Security\StockUserProvider::class)->loadUserByIdentifier($name.'@example.test'));
        $this->client->request('GET','/api/v1/auth/csrf'); $this->csrf=json_decode($this->client->getResponse()->getContent(),true)['token'];
    }
    private function send(string $method,string $path,mixed $body=null,string $key='store-creation-123'): array
    {
        $this->client->request($method,$path,server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>$key],content:$body===null?null:json_encode($body));
        return json_decode($this->client->getResponse()->getContent(),true)??[];
    }
    private function create(array $overrides=[],string $key='store-creation-123'): array
    {
        return $this->send('POST','/api/v1/stores',array_replace(['code'=>'DEPOT-A','name'=>'Dépôt A'],$overrides),$key);
    }
    public function testCreateDefaultsNormalizationContractAndDurableReplay(): void
    {
        $this->login(); $first=$this->create(['code'=>' DEPOT-A ','name'=>' Dépôt A ']); self::assertResponseStatusCodeSame(201);
        self::assertSame('DEPOT-A',$first['data']['code']); self::assertSame('Dépôt A',$first['data']['name']); self::assertSame('',$first['data']['description']); self::assertNull($first['data']['address']); self::assertTrue($first['data']['active']);
        self::assertSame('/api/v1/stores/'.$first['data']['id'],$this->client->getResponse()->headers->get('Location'));
        self::assertTrue(static::getContainer()->get(OpenApiContract::class)->valid(json_decode(json_encode($first)),['$ref'=>'#/components/schemas/StoreResource']));
        $this->send('PATCH','/api/v1/stores/'.$first['data']['id'],['name'=>'Nouveau nom','active'=>false]); self::assertResponseIsSuccessful();
        self::assertEquals($first,$this->create(['code'=>' DEPOT-A ','name'=>' Dépôt A '])); self::assertResponseStatusCodeSame(201);
        $this->create(['name'=>'Autre intention']); self::assertResponseStatusCodeSame(409);
        self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM stores')); self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM store_creation_requests'));
        self::assertSame(2,(int)$this->db->fetchOne('SELECT count(*) FROM audit_logs'));
    }
    public function testDuplicateCodeIncludesArchivedAndCaseInsensitiveMatches(): void
    {
        $this->login();$this->create(['active'=>false]);self::assertResponseStatusCodeSame(201);
        $body=$this->create(['code'=>' depot-a '],'another-key-123');self::assertResponseStatusCodeSame(409);self::assertSame('store_code_already_used',$body['code']);
        self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM stores'));self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM audit_logs'));
    }
    public function testInvalidPayloadsAndImmutableCodeAreRejected(): void
    {
        $this->login();
        foreach ([['code'=>'   '],['name'=>'   '],['code'=>str_repeat('a',41)],['name'=>str_repeat('é',161)],['description'=>str_repeat('é',5001)],['address'=>str_repeat('a',1001)],['name'=>"nul\0bad"],['active'=>'false'],['unknown'=>'value']] as $override) { $this->create($override);self::assertResponseStatusCodeSame(422); }
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stores'));
        $this->create([],'invalid');self::assertResponseStatusCodeSame(422);
        $result=$this->create();self::assertResponseStatusCodeSame(201);$id=$result['data']['id'];
        foreach ([(object)[],['code'=>'NEW'],['id'=>$id],['created_at'=>'2026-01-01'],['name'=>' '],['active'=>null]] as $body) { $this->send('PATCH','/api/v1/stores/'.$id,$body);self::assertResponseStatusCodeSame(422); }
        foreach (['not-a-uuid','11111111-1111-4111-8111-111111111111'] as $bad) { $this->send('PATCH','/api/v1/stores/'.$bad,['name'=>'New']);self::assertResponseStatusCodeSame(404); }
        self::assertSame('DEPOT-A',$this->db->fetchOne('SELECT code FROM stores'));
    }
    public function testArchivePreservesHistoryAndAssignmentsAndReactivationRestoresAccess(): void
    {
        $this->login();$result=$this->create();$id=$result['data']['id'];
        $this->db->insert('user_stores',['user_id'=>$this->ids['user'],'store_id'=>$id]);
        $article=$this->db->fetchOne("INSERT INTO articles (sku,name) VALUES ('TEST','Article') RETURNING id");
        $this->db->insert('stock_levels',['article_id'=>$article,'store_id'=>$id,'quantity'=>9]);
        $this->db->executeStatement("INSERT INTO stock_movements (type,article_id,destination_store_id,quantity,author_id,reason,idempotency_key) VALUES ('entry',?,?,9,?,'Test','movement-test')",[$article,$id,$this->ids['admin']]);
        $before=[];foreach (['stock_levels','stock_movements','user_stores'] as $table) { $before[$table]=$this->db->fetchAllAssociative('SELECT * FROM '.$table); }
        $this->send('PATCH','/api/v1/stores/'.$id,['name'=>'Dépôt renommé','description'=>'Texte <script>test</script>','address'=>' Adresse facultative ','active'=>false]);self::assertResponseIsSuccessful();
        foreach ($before as $table=>$rows) { self::assertSame($rows,$this->db->fetchAllAssociative('SELECT * FROM '.$table)); }
        self::assertSame('Adresse facultative',$this->db->fetchOne('SELECT address FROM stores'));self::assertSame('store.archived',$this->db->fetchOne("SELECT action FROM audit_logs WHERE action = 'store.archived'"));
        $this->send('DELETE','/api/v1/stores/'.$id);self::assertResponseStatusCodeSame(409);self::assertSame('store_not_empty',json_decode($this->client->getResponse()->getContent(),true)['code']);
        $this->login('user');$this->client->request('GET','/api/v1/stores/'.$id);self::assertResponseStatusCodeSame(404);
        $this->login();$this->send('PATCH','/api/v1/stores/'.$id,['active'=>true,'address'=>'']);self::assertResponseIsSuccessful();
        self::assertNull($this->db->fetchOne('SELECT address FROM stores'));
        $this->login('user');$this->client->request('GET','/api/v1/stores/'.$id);self::assertResponseIsSuccessful();
        self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM stock_movements'));
    }
    public function testInventoryMustBeCompletedOrCancelledBeforeArchiving(): void
    {
        $this->login();$id=$this->create()['data']['id'];
        $inventory=$this->db->fetchOne("INSERT INTO inventories (store_id,created_by,status,snapshot_at) VALUES (?,?,'in_progress',clock_timestamp()) RETURNING id",[$id,$this->ids['admin']]);
        foreach (['in_progress','pending_validation'] as $state) {
            $this->db->executeStatement('UPDATE inventories SET status = ? WHERE id = ?',[$state,$inventory]);
            $error=$this->send('PATCH','/api/v1/stores/'.$id,['active'=>false]);self::assertResponseStatusCodeSame(409);self::assertSame('inventory_in_progress',$error['code']);
            self::assertTrue($this->db->fetchOne('SELECT active FROM stores WHERE id = ?',[$id]));
        }
        $this->send('PATCH','/api/v1/stores/'.$id,['name'=>'Modification permise']);self::assertResponseIsSuccessful();
        $this->db->executeStatement("UPDATE inventories SET status='cancelled',cancelled_at=clock_timestamp() WHERE id = ?",[$inventory]);
        $this->send('PATCH','/api/v1/stores/'.$id,['active'=>false]);self::assertResponseIsSuccessful();
        self::assertSame($inventory,$this->db->fetchOne('SELECT id FROM inventories'));
    }
    public function testNonAdministratorsAndCsrfFailuresCannotWrite(): void
    {
        $this->login();$id=$this->create()['data']['id'];
        foreach (['manager','user'] as $name) {
            $this->login($name);$this->create(['code'=>'NEW']);self::assertResponseStatusCodeSame(403);
            $this->send('PATCH','/api/v1/stores/'.$id,['active'=>false]);self::assertResponseStatusCodeSame(403);
            foreach (['/administration/magasins/nouveau','/administration/magasins/'.$id.'/modifier'] as $path) { $this->client->request('GET',$path);self::assertResponseStatusCodeSame(403); }
        }
        $this->login();$this->client->request('POST','/api/v1/stores',server:['CONTENT_TYPE'=>'application/json','HTTP_IDEMPOTENCY_KEY'=>'csrf-test-123'],content:'{"code":"NEW","name":"New"}');self::assertResponseStatusCodeSame(403);
        self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM stores'));self::assertTrue($this->db->fetchOne('SELECT active FROM stores'));
    }
    public function testFormsWithoutJavascriptFiltersEscapingAndValidation(): void
    {
        $this->login();$crawler=$this->client->request('GET','/administration/magasins/nouveau');self::assertResponseIsSuccessful();
        $form=$crawler->selectButton('Créer le magasin')->form(['code'=>'UI-STORE','name'=>'Magasin <script>test</script>','description'=>'Description','address'=>'']);
        $this->client->submit($form);self::assertResponseStatusCodeSame(303);$this->client->followRedirect();self::assertResponseIsSuccessful();
        self::assertStringContainsString('Magasin &lt;script&gt;test&lt;/script&gt;', $this->client->getResponse()->getContent());
        self::assertSelectorNotExists('main script');
        self::assertSelectorExists('#store-code[disabled]');self::assertSelectorExists('nav a[aria-current="page"][href="/magasins"]');
        $form=$this->client->getCrawler()->selectButton('Enregistrer les modifications')->form(['name'=>'Magasin renommé']);$form['active']->untick();$this->client->submit($form);self::assertResponseStatusCodeSame(303);
        $this->client->request('GET','/magasins?status=inactive');self::assertResponseIsSuccessful();self::assertSelectorTextContains('tbody','Magasin renommé');
        $this->client->request('GET','/magasins?sort=invalid');self::assertResponseStatusCodeSame(422);self::assertSelectorExists('main .notice-error');
        $crawler=$this->client->request('GET','/administration/magasins/nouveau');$form=$crawler->selectButton('Créer le magasin')->form(['code'=>'UI-STORE','name'=>'Doublon']);$this->client->submit($form);self::assertResponseStatusCodeSame(409);self::assertSelectorTextContains('[role="alert"]','déjà utilisé');
        $this->client->request('POST','/administration/magasins/nouveau',['code'=>'NO-CSRF','name'=>'No token']);self::assertResponseStatusCodeSame(403);
        $this->client->request('GET','/espace/magasins');self::assertResponseRedirects('/magasins');
    }
    public function testNoOpDoesNotAuditAgainAndAuditContainsOnlyFieldNames(): void
    {
        $this->login();$id=$this->create(['description'=>'Contenu privé fictif'])['data']['id'];
        $this->send('PATCH','/api/v1/stores/'.$id,['name'=>'Dépôt A']);self::assertResponseIsSuccessful();self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM audit_logs'));
        $this->send('PATCH','/api/v1/stores/'.$id,['description'=>'Autre contenu fictif']);self::assertResponseIsSuccessful();
        $audit=$this->db->fetchFirstColumn('SELECT context::text FROM audit_logs');self::assertCount(2,$audit);self::assertStringNotContainsString('Contenu privé',implode(' ',$audit));self::assertStringNotContainsString('Autre contenu',implode(' ',$audit));
        self::assertSame(['fields'=>['description']],json_decode($audit[1],true));
    }
}
