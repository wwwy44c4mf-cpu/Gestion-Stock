<?php

declare(strict_types=1);

namespace App\Tests;

use App\Api\ApiProblem;
use App\Api\OpenApiContract;
use App\Security\AccessIdentity;
use App\Security\AccessPolicy;
use App\Security\AccessTarget;
use App\Security\Permission;
use App\Security\StockUser;
use App\Security\StockUserProvider;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Real PostgreSQL boundaries: no production records or mutable fixture routes. */
final class AccessControlTest extends WebTestCase
{
    private Connection $db;
    private KernelBrowser $client;
    private AccessPolicy $policy;
    private array $users = [];
    private array $stores = [];
    private string $article;
    private string $own;
    private string $foreign;
    private string $transfer;
    protected function setUp(): void
    {
        $this->client = static::createClient(); $this->client->disableReboot();
        $this->db = static::getContainer()->get(Connection::class); $this->db->beginTransaction();
        $schema = 'auth03_'.bin2hex(random_bytes(6));
        $this->db->executeStatement('CREATE SCHEMA '.$schema); $this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        foreach (explode('-- migrate:statement', file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) {
            if (trim($sql) !== '') { $this->db->executeStatement($sql); }
        }
        \App\Tests\CatalogDeletionSchema::apply($this->db);
        $this->db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
        $hash = password_hash('Test-access-2026!', PASSWORD_ARGON2ID);
        foreach (['admin' => 1, 'manager' => 2, 'user' => 3, 'outsider' => 3] as $name => $role) {
            $id = $this->db->fetchOne('INSERT INTO users (email, name, role_id, password_hash) VALUES (?, ?, ?, ?) RETURNING id', [$name.'@example.test', ucfirst($name), $role, $hash]);
            $this->users[$name] = static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier($name.'@example.test');
        }
        foreach (['A', 'B', 'C', 'D'] as $code) {
            $this->stores[$code] = $this->db->fetchOne('INSERT INTO stores (code, name, active) VALUES (?, ?, ?) RETURNING id', [$code, 'Magasin '.$code, $code !== 'D'], [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::BOOLEAN]);
        }
        foreach (['manager' => ['A','B','D'], 'user' => ['A','D']] as $name => $codes) {
            foreach ($codes as $code) { $this->db->insert('user_stores', ['user_id' => $this->users[$name]->id, 'store_id' => $this->stores[$code]]); }
        }
        $this->article = $this->db->fetchOne("INSERT INTO articles (sku, name) VALUES ('TEST', 'Article') RETURNING id");
        foreach (['A' => 5, 'B' => 7, 'C' => 100, 'D' => 1000] as $code => $qty) { $this->db->insert('stock_levels', ['article_id' => $this->article, 'store_id' => $this->stores[$code], 'quantity' => $qty]); }
        $this->own = $this->movement('consumption', 'C', null, 'user');
        $this->foreign = $this->movement('consumption', 'C', null, 'outsider');
        $this->transfer = $this->movement('transfer', 'A', 'C');
        $this->policy = static::getContainer()->get(AccessPolicy::class);
    }
    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) { $this->db->rollBack(); }
        parent::tearDown();
    }
    private function movement(string $type, ?string $source, ?string $destination, ?string $consumer = null): string
    {
        return $this->db->fetchOne('INSERT INTO stock_movements (type, article_id, source_store_id, destination_store_id, quantity, author_id, consumer_id, idempotency_key) VALUES (?, ?, ?, ?, 1, ?, ?, ?) RETURNING id', [$type, $this->article, $source === null ? null : $this->stores[$source], $destination === null ? null : $this->stores[$destination], $this->users['admin']->id, $consumer === null ? null : $this->users[$consumer]->id, bin2hex(random_bytes(10))]);
    }
    private function denied(callable $action, int $status = 404): void
    {
        $before = $this->db->fetchAllAssociative('SELECT * FROM stock_levels ORDER BY store_id');
        try { $action(); self::fail('Access should have been refused.'); }
        catch (ApiProblem $problem) { self::assertSame($status, $problem->status); }
        self::assertSame($before, $this->db->fetchAllAssociative('SELECT * FROM stock_levels ORDER BY store_id'));
    }
    private function login(string $name): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($this->users[$name]);
    }
    public function testStoreDirectoryCountsAndDirectIdsRespectAssignmentsAndInactiveState(): void
    {
        foreach (['admin' => 4, 'manager' => 2, 'user' => 1, 'outsider' => 0] as $name => $total) {
            $this->login($name); $this->client->request('GET', '/api/v1/stores?limit=1'); self::assertResponseIsSuccessful();
            $body = json_decode($this->client->getResponse()->getContent(), true); self::assertSame($total, $body['meta']['total']);
            self::assertCount(min(1, $total), $body['data']);
            self::assertTrue(static::getContainer()->get(OpenApiContract::class)->valid((object) ['data'=>array_map(fn($r)=>(object)$r,$body['data']), 'meta'=>(object)$body['meta']], ['$ref'=>'#/components/schemas/StorePage']));
            $this->client->request('GET', '/api/v1/stores/'.$this->stores['D']); self::assertResponseStatusCodeSame($name === 'admin' ? 200 : 404);
            $this->client->request('GET', '/api/v1/stores/'.$this->stores['C']); self::assertResponseStatusCodeSame($name === 'admin' ? 200 : 404);
            $this->client->request('GET', '/api/v1/stores?status=inactive'); self::assertResponseIsSuccessful();
            self::assertSame($name === 'admin' ? 1 : 0, json_decode($this->client->getResponse()->getContent(),true)['meta']['total']);
        }
        foreach (['not-an-id', '11111111-1111-4111-8111-111111111111'] as $id) {
            $this->client->request('GET','/api/v1/stores/'.$id); self::assertResponseStatusCodeSame(404);
        }
        $this->client->request('GET', '/api/v1/stores?store_id='.$this->stores['C']); self::assertResponseStatusCodeSame(422);
    }
    public function testScopesApplyBeforeSumsCountsAndPagination(): void
    {
        foreach (['admin'=>1112, 'manager'=>12, 'user'=>5, 'outsider'=>0] as $name=>$expected) {
            $scope = $this->policy->stockScope($this->users[$name]);
            self::assertSame($expected, (int)$this->db->fetchOne('SELECT COALESCE(sum(sl.quantity),0) FROM stock_levels sl WHERE '.$scope->condition, $scope->parameters));
        }
        $scope = $this->policy->movementScope($this->users['manager']);
        self::assertSame([$this->transfer], $this->db->fetchFirstColumn('SELECT m.id FROM stock_movements m WHERE '.$scope->condition, $scope->parameters));
        $scope = $this->policy->consumptionScope($this->users['user'], Permission::STATISTICS_READ);
        self::assertSame([$this->own], $this->db->fetchFirstColumn('SELECT m.id FROM stock_movements m WHERE '.$scope->condition, $scope->parameters));
        $this->db->executeStatement('UPDATE stores SET active = false WHERE id = ?', [$this->stores['C']]);
        self::assertSame([], $this->db->fetchFirstColumn('SELECT m.id FROM stock_movements m WHERE '.$scope->condition, $scope->parameters));
    }
    public function testPersonalHistoryDoesNotGrantStocksOrAnotherPersonsHistory(): void
    {
        $this->policy->assert($this->users['user'], Permission::CONSUMPTION_READ, AccessTarget::consumption($this->own));
        $this->denied(fn()=> $this->policy->assert($this->users['user'], Permission::STOCK_READ, AccessTarget::store($this->stores['C'])));
        $this->denied(fn()=> $this->policy->assert($this->users['user'], Permission::CONSUMPTION_READ, AccessTarget::consumption($this->foreign)));
        $this->db->executeStatement('UPDATE stores SET active = false WHERE id = ?',[$this->stores['C']]);
        $this->denied(fn()=> $this->policy->assert($this->users['user'], Permission::CONSUMPTION_READ, AccessTarget::consumption($this->own)));
        $this->policy->assert($this->users['admin'], Permission::CONSUMPTION_READ, AccessTarget::consumption($this->own));
    }
    public function testMutationBoundariesAndBothTransferStores(): void
    {
        $this->policy->assert($this->users['manager'], Permission::TRANSFER_CREATE, AccessTarget::transfer($this->stores['A'],$this->stores['B']));
        $this->denied(fn()=> $this->policy->assert($this->users['manager'], Permission::TRANSFER_CREATE, AccessTarget::transfer($this->stores['A'],$this->stores['C'])));
        $this->denied(fn()=> $this->policy->assert($this->users['manager'], Permission::MOVEMENT_REVERSE, AccessTarget::movement($this->transfer)));
        $this->denied(fn()=> $this->policy->assert($this->users['user'], Permission::ENTRY_CREATE, AccessTarget::store($this->stores['A'])),403);
        $this->denied(fn()=> $this->policy->assert($this->users['admin'], Permission::ENTRY_CREATE, AccessTarget::store($this->stores['D'])));
        $this->denied(fn()=> $this->policy->assert($this->users['manager'], Permission::TRANSFER_CREATE));
        $this->policy->assert($this->users['admin'], Permission::RETURN_CREATE, AccessTarget::store($this->stores['A']));
        $this->policy->assertConsumption($this->users['manager'],$this->stores['A'],$this->users['outsider']->id);
        $this->denied(fn()=> $this->policy->assertConsumption($this->users['user'],$this->stores['A'],$this->users['outsider']->id),403);
        $this->db->executeStatement('UPDATE users SET active = false WHERE id = ?',[$this->users['outsider']->id]);
        $this->denied(fn()=> $this->policy->assertConsumption($this->users['manager'],$this->stores['A'],$this->users['outsider']->id),422);
    }
    public function testOwnReturnSurvivesAssignmentRemovalButNotStoreDeactivation(): void
    {
        $this->policy->assert($this->users['user'], Permission::RETURN_CREATE, AccessTarget::consumption($this->own));
        $this->denied(fn()=> $this->policy->assert($this->users['user'], Permission::RETURN_CREATE, AccessTarget::consumption($this->foreign)));
        $this->denied(fn()=> $this->policy->assert($this->users['manager'], Permission::RETURN_CREATE, AccessTarget::consumption($this->own)));
        $this->denied(fn()=> $this->policy->assert($this->users['user'], Permission::RETURN_CREATE, AccessTarget::movement($this->transfer)));
        $local=$this->movement('consumption','A',null,'user');
        $this->db->executeStatement('DELETE FROM user_stores WHERE user_id = ?',[$this->users['user']->id]);
        $this->policy->assert($this->users['user'], Permission::RETURN_CREATE, AccessTarget::consumption($local));
        $this->policy->assert($this->users['user'], Permission::CONSUMPTION_CREATE, AccessTarget::store($this->stores['A']));
        $this->denied(fn()=> $this->policy->assert($this->users['user'], Permission::STOCK_READ, AccessTarget::store($this->stores['A'])));
        $this->db->executeStatement('UPDATE stores SET active = false WHERE id = ?',[$this->stores['C']]);
        $this->denied(fn()=> $this->policy->assert($this->users['user'], Permission::RETURN_CREATE, AccessTarget::consumption($this->own)));
        $this->denied(fn()=> $this->policy->assert($this->users['admin'], Permission::RETURN_CREATE, AccessTarget::consumption($this->own)));
    }
    public function testRevokedAssignmentAndStaleIdentityFailClosed(): void
    {
        // StockUser was loaded before assignments were created; only database assignments count.
        self::assertSame([], $this->users['manager']->storeIds);
        $this->policy->assert($this->users['manager'],Permission::STOCK_READ,AccessTarget::store($this->stores['A']));
        $this->db->executeStatement('DELETE FROM user_stores WHERE user_id = ? AND store_id = ?',[$this->users['manager']->id,$this->stores['A']]);
        $this->denied(fn()=> $this->policy->assert($this->users['manager'],Permission::STOCK_READ,AccessTarget::store($this->stores['A'])));
        $this->db->executeStatement('UPDATE users SET auth_version = auth_version + 1 WHERE id = ?',[$this->users['manager']->id]);
        $this->denied(fn()=> $this->policy->stockScope($this->users['manager']),401);
    }
    public function testBeneficiaryDirectoryIsMinimalAndDoesNotOpenProfiles(): void
    {
        $this->login('manager'); $this->client->request('GET','/api/v1/consumption-beneficiaries?q=Out'); self::assertResponseIsSuccessful();
        $body=json_decode($this->client->getResponse()->getContent(),true);
        self::assertSame([['id'=>$this->users['outsider']->id,'name'=>'Outsider']],$body['data']);
        $this->client->request('GET','/api/v1/users/'.$this->users['outsider']->id); self::assertResponseStatusCodeSame(403);
        $this->client->request('GET','/api/v1/consumption-beneficiaries?q=%20%20'); self::assertResponseStatusCodeSame(422);
        $this->client->request('GET','/api/v1/consumption-beneficiaries?q=%25'); self::assertResponseIsSuccessful(); self::assertSame(0,json_decode($this->client->getResponse()->getContent(),true)['meta']['total']);
        $this->db->executeStatement('UPDATE users SET development_only = true WHERE id = ?',[$this->users['outsider']->id]);
        $this->client->request('GET','/api/v1/consumption-beneficiaries?q=Out'); self::assertResponseIsSuccessful(); self::assertSame(0,json_decode($this->client->getResponse()->getContent(),true)['meta']['total']);
        $this->db->executeStatement('DELETE FROM user_stores WHERE user_id = ?',[$this->users['manager']->id]);
        $this->client->request('GET','/api/v1/consumption-beneficiaries'); self::assertResponseStatusCodeSame(403);
        $this->login('user'); $this->client->request('GET','/api/v1/consumption-beneficiaries'); self::assertResponseStatusCodeSame(403);
    }
    public function testContextSelectionHasCsrfAndCannotElevateAccess(): void
    {
        $this->login('manager'); $crawler=$this->client->request('GET','/'); self::assertResponseIsSuccessful();
        self::assertSelectorCount(3,'#magasin option'); // all + A/B + inactive D is absent
        $form=$crawler->selectButton('Choisir')->form(); $form['store_id']=$this->stores['B']; $this->client->submit($form); self::assertResponseRedirects('/',303);
        $this->client->followRedirect(); self::assertSelectorExists('#magasin option[selected][value="'.$this->stores['B'].'"]');
        $this->client->request('POST','/contexte/magasin',['store_id'=>$this->stores['C'],'_csrf_token'=>$form['_csrf_token']->getValue()]); self::assertResponseStatusCodeSame(404);
        $this->client->request('POST','/contexte/magasin',['store_id'=>$this->stores['A']]); self::assertResponseStatusCodeSame(403);
        $this->db->executeStatement('UPDATE stores SET active = false WHERE id = ?',[$this->stores['B']]);
        $this->client->request('GET','/'); self::assertResponseIsSuccessful(); self::assertSelectorNotExists('#magasin option[selected]');
        $this->login('user'); foreach (['mouvements','inventaires','alertes','administration'] as $section) { $this->client->request('GET','/espace/'.$section); self::assertResponseStatusCodeSame(403); }
    }
    public function testResourceOwnersAndGlobalAuditAreLoadedServerSide(): void
    {
        $ids=[];
        foreach (['A','C','D'] as $code) {
            $ids[$code]['inventory']=$this->db->fetchOne('INSERT INTO inventories (store_id,created_by) VALUES (?,?) RETURNING id',[$this->stores[$code],$this->users['admin']->id]);
            $ids[$code]['alert']=$this->db->fetchOne("INSERT INTO stock_alerts (article_id,store_id,level,threshold) VALUES (?,?,'low',5) RETURNING id",[$this->article,$this->stores[$code]]);
            $ids[$code]['audit']=$this->db->fetchOne("INSERT INTO audit_logs (store_id,action,target_type) VALUES (?,'test','store') RETURNING id",[$this->stores[$code]]);
        }
        $global=$this->db->fetchOne("INSERT INTO audit_logs (action,target_type) VALUES ('account.test','user') RETURNING id");
        foreach (['inventory'=>Permission::INVENTORY_READ,'alert'=>Permission::ALERT_READ,'audit'=>Permission::AUDIT_READ] as $kind=>$permission) {
            $this->policy->assert($this->users['manager'],$permission,AccessTarget::$kind($ids['A'][$kind]));
            foreach (['C','D'] as $code) { $this->denied(fn()=> $this->policy->assert($this->users['manager'],$permission,AccessTarget::$kind($ids[$code][$kind]))); }
            $this->policy->assert($this->users['admin'],$permission,AccessTarget::$kind($ids['D'][$kind]));
            $table=['inventory'=>'inventories','alert'=>'stock_alerts','audit'=>'audit_logs'][$kind];
            $scope=$this->policy->resourceScope($this->users['manager'],$permission,'r');
            self::assertSame([$ids['A'][$kind]],$this->db->fetchFirstColumn('SELECT r.id FROM '.$table.' r WHERE '.$scope->condition,$scope->parameters));
        }
        $this->denied(fn()=> $this->policy->assert($this->users['manager'],Permission::AUDIT_READ,AccessTarget::audit($global)));
        $this->policy->assert($this->users['admin'],Permission::AUDIT_READ,AccessTarget::audit($global));
        $own=$this->db->fetchOne("INSERT INTO article_proposals (operation,payload,proposed_by) VALUES ('create','{}',?) RETURNING id",[$this->users['manager']->id]);
        $other=$this->db->fetchOne("INSERT INTO article_proposals (operation,payload,proposed_by) VALUES ('create','{}',?) RETURNING id",[$this->users['admin']->id]);
        $this->policy->assert($this->users['manager'],Permission::PROPOSAL_READ,AccessTarget::proposal($own));
        $this->denied(fn()=> $this->policy->assert($this->users['manager'],Permission::PROPOSAL_READ,AccessTarget::proposal($other)));
        $scope=$this->policy->proposalScope($this->users['manager']);
        self::assertSame([$own],$this->db->fetchFirstColumn('SELECT p.id FROM article_proposals p WHERE '.$scope->condition,$scope->parameters));
        $this->db->executeStatement('UPDATE stores SET active = false WHERE id = ?',[$this->stores['C']]);
        $scope=$this->policy->movementScope($this->users['manager']);
        self::assertSame([],$this->db->fetchFirstColumn('SELECT m.id FROM stock_movements m WHERE '.$scope->condition,$scope->parameters));
        $this->denied(fn()=> $this->policy->assert($this->users['manager'],Permission::MOVEMENT_READ,AccessTarget::movement($this->transfer)));
    }
    public function testForgedRolesAndPasswordFingerprintsCannotGrantPrivileges(): void
    {
        $user=$this->users['user'];
        $forged=new StockUser($user->id,$user->email,$user->name,'ADMIN',true,$user->getPassword(),$user->authVersion,$user->timezone,$user->createdAt,[$this->stores['C']]);
        $this->denied(fn()=> $this->policy->assert($forged,Permission::ACCOUNTS_ADMIN),401);
        $admin=$this->users['admin'];
        $this->db->executeStatement('UPDATE users SET password_hash = ? WHERE id = ?',['unusable-new-hash',$admin->id]);
        $this->denied(fn()=> $this->policy->assert($admin,Permission::ACCOUNTS_ADMIN),401);
    }
    public function testRoleMatrixAndVoterDenyUnknownOrWrongTargets(): void
    {
        foreach (['admin','manager','user'] as $name) {
            foreach (Permission::cases() as $permission) {
                if (!in_array($this->users[$name]->role,$permission->roles(),true)) {
                    $this->denied(fn()=>static::getContainer()->get(AccessIdentity::class)->requireRole($this->users[$name],$permission),403);
                }
            }
        }
        $this->login('manager'); $security=static::getContainer()->get('security.authorization_checker');
        self::assertTrue($security->isGranted('APP_STOCK_READ',AccessTarget::store($this->stores['A'])));
        self::assertFalse($security->isGranted('APP_STOCK_READ',AccessTarget::store($this->stores['C'])));
        self::assertFalse($security->isGranted('APP_STOCK_READ',['id'=>$this->stores['A']]));
        self::assertFalse($security->isGranted('APP_UNKNOWN'));
        self::assertFalse($security->isGranted('APP_STOCK_READ'));
    }
}
