<?php

declare(strict_types=1);
namespace App\Tests;
use App\Api\ApiProblem;
use App\Api\OpenApiContract;
use App\Inventory\InventoryWorkflow;
use App\Security\StockUserProvider;
use App\Stock\StockMovementEngine;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InventoryWorkflowTest extends WebTestCase
{
    private $client;private Connection $db;private array $users=[], $stores=[];private string $csrf,$article;
    protected function setUp(): void
    {
        $this->client=static::createClient();$this->client->disableReboot();$this->db=static::getContainer()->get(Connection::class);$this->db->beginTransaction();
        $schema='inv01_'.bin2hex(random_bytes(6));$this->db->executeStatement('CREATE SCHEMA '.$schema);$this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        foreach(explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) {if(trim($sql)!=='') {$this->db->executeStatement($sql);}}
        \App\Tests\CatalogDeletionSchema::apply($this->db);
        $this->db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
        foreach(['20261018140000','20261018200000','20261018223000','20261020220000','20261020230000'] as $version) {require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';$class='DoctrineMigrations\\Version'.$version;$migration=new $class($this->db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) {$this->db->executeStatement($sql->getStatement());}}
        $hash=password_hash('Inventory-test-2026!',PASSWORD_ARGON2ID);
        foreach(['admin'=>1,'manager'=>2,'user'=>3,'unassigned'=>2] as $name=>$role) {$this->users[$name]=$this->db->fetchOne('INSERT INTO users (email,name,password_hash,role_id) VALUES (?,?,?,?) RETURNING id',[$name.'@example.test',$name,$hash,$role]);}
        foreach(['A','B','D'] as $code) {$this->stores[$code]=$this->db->fetchOne('INSERT INTO stores (code,name) VALUES (?,?) RETURNING id',[$code,'Magasin '.$code]);}
        $this->db->executeStatement('UPDATE stores SET active=false WHERE id=?',[$this->stores['D']]);
        $this->db->insert('user_stores',['user_id'=>$this->users['manager'],'store_id'=>$this->stores['A']]);
        $this->article=$this->db->fetchOne("INSERT INTO articles (sku,name) VALUES ('INV','Article <script>test</script>') RETURNING id");
    }
    protected function tearDown(): void {if($this->db->isTransactionActive()) {$this->db->rollBack();}parent::tearDown();}
    private function actor(string $name='manager') {return static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier($name.'@example.test');}
    private function login(string $name='manager'): void
    {
        $this->client->getCookieJar()->clear();$this->client->loginUser($this->actor($name));$this->client->request('GET','/api/v1/auth/csrf');$this->csrf=json_decode($this->client->getResponse()->getContent(),true)['token'];
    }
    private function create(string $store='A',string $key='inventory-create-key'): array
    {
        $this->client->request('POST','/api/v1/inventories',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>$key],content:json_encode(['store_id'=>$this->stores[$store]]));return json_decode($this->client->getResponse()->getContent(),true)??[];
    }
    private function change(string $id,string $action,string $key): array
    {
        $this->client->request('POST','/api/v1/inventories/'.$id.'/'.$action,server:['HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>$key]);return json_decode($this->client->getResponse()->getContent(),true)??[];
    }
    public function testDraftCreationCancellationIdempotencyAndNoStockChanges(): void
    {
        $this->login();$first=$this->create();self::assertResponseStatusCodeSame(201);$id=$first['data']['id'];self::assertSame('draft',$first['data']['status']);self::assertNull($first['data']['snapshot_at']);self::assertSame('/api/v1/inventories/'.$id,$this->client->getResponse()->headers->get('Location'));
        self::assertSame($first,$this->create());self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM inventories'));
        $this->create('B');self::assertResponseStatusCodeSame(404);
        $cancel=$this->change($id,'cancel','inventory-cancel-key');self::assertResponseIsSuccessful();self::assertSame('cancelled',$cancel['data']['status']);self::assertNotNull($cancel['data']['cancelled_at']);self::assertSame($cancel,$this->change($id,'cancel','inventory-cancel-key'));
        self::assertSame($first,$this->create());$this->change($id,'start','inventory-start-after-cancel');self::assertResponseStatusCodeSame(409);
        self::assertSame(2,(int)$this->db->fetchOne('SELECT count(*) FROM audit_logs'));self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_movements'));self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_levels'));
        self::assertTrue(static::getContainer()->get(OpenApiContract::class)->valid(json_decode(json_encode($cancel)),['$ref'=>'#/components/schemas/InventoryResource']));
    }
    public function testPermissionsAndScopedPaginatedReads(): void
    {
        $this->login('admin');$a=$this->create('A','create-inventory-a')['data']['id'];$this->create('B','create-inventory-b');self::assertResponseStatusCodeSame(201);$this->create('D','create-inventory-d');self::assertResponseStatusCodeSame(404);
        $this->login();$this->client->request('GET','/api/v1/inventories?limit=1');self::assertResponseIsSuccessful();$result=json_decode($this->client->getResponse()->getContent(),true);self::assertSame(1,$result['meta']['total']);self::assertSame($a,$result['data'][0]['id']);
        $this->client->request('GET','/api/v1/inventories?store_id='.$this->stores['B']);self::assertResponseStatusCodeSame(404);
        $this->login('unassigned');$this->client->request('GET','/api/v1/inventories');self::assertSame(0,json_decode($this->client->getResponse()->getContent(),true)['meta']['total']);$this->change($a,'cancel','unassigned-inventory-cancel');self::assertResponseStatusCodeSame(404);
        $this->login('user');$this->client->request('GET','/inventaires');self::assertResponseStatusCodeSame(403);$this->create();self::assertResponseStatusCodeSame(403);
    }
    public function testPendingValidationKeepsFreezeAndCancellationReleasesIt(): void
    {
        $id=$this->db->fetchOne("INSERT INTO inventories (store_id,created_by,status,snapshot_at) VALUES (?,?,'in_progress',clock_timestamp()) RETURNING id",[$this->stores['A'],$this->users['manager']]);
        $this->db->insert('inventory_lines',['inventory_id'=>$id,'article_id'=>$this->article,'theoretical_qty'=>0]);$this->login();$this->change($id,'submit','submit-incomplete-inventory');self::assertResponseStatusCodeSame(409);
        $this->db->executeStatement('UPDATE inventory_lines SET counted_qty=0,counted_by=?,counted_at=clock_timestamp() WHERE inventory_id=?',[$this->users['manager'],$id]);
        self::assertSame('pending_validation',$this->change($id,'submit','submit-complete-inventory')['data']['status']);self::assertResponseIsSuccessful();
        $engine=static::getContainer()->get(StockMovementEngine::class);$body=(object)['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>1,'reason'=>'Livraison'];
        try {$engine->record($this->actor(),'entry',$body,'entry-frozen-inventory');self::fail('Mouvement autorisé pendant gel');}catch(ApiProblem $e) {self::assertSame('inventory_in_progress',$e->errorCode);}
        $this->change($id,'cancel','cancel-pending-inventory');self::assertResponseIsSuccessful();$result=$engine->record($this->actor(),'entry',$body,'entry-after-inventory');self::assertSame(1,$result['data']['quantity']);
        self::assertSame(0,(int)$this->db->fetchOne('SELECT theoretical_qty FROM inventory_lines WHERE inventory_id=?',[$id]));
        $this->client->request('GET','/api/v1/inventories/'.$id.'/lines');self::assertResponseIsSuccessful();self::assertSame(0,json_decode($this->client->getResponse()->getContent(),true)['data'][0]['counted_qty']);
    }
    public function testFormsAndQueryValidation(): void
    {
        $this->login();$crawler=$this->client->request('GET','/inventaires/nouveau');self::assertResponseIsSuccessful();$form=$crawler->selectButton('Créer le brouillon')->form(['store_id'=>$this->stores['A']]);$this->client->submit($form);self::assertResponseStatusCodeSame(303);$this->client->followRedirect();self::assertSelectorTextContains('h1','Magasin A');self::assertSelectorExists('button');
        $crawler=$this->client->getCrawler();$this->client->submit($crawler->selectButton('Annuler l’inventaire')->form());self::assertResponseStatusCodeSame(303);$this->client->followRedirect();self::assertSelectorTextContains('main','Inventaire annulé');self::assertSelectorNotExists('button:contains("Démarrer et bloquer")');
        $this->client->request('GET','/inventaires');self::assertResponseIsSuccessful();self::assertSelectorExists('a[href="/inventaires"][aria-current]');
        $this->client->request('GET','/api/v1/inventories?status=wrong');self::assertResponseStatusCodeSame(422);
    }
    public function testStartSnapshotsSelectedArticlesAndFreezesEveryMovementEngine(): void
    {
        $engine=static::getContainer()->get(StockMovementEngine::class);$actor=$this->actor();$admin=$this->actor('admin');
        $entry=$engine->record($actor,'entry',(object)['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>10,'reason'=>'Initialisation'],'inventory-entry-seed')['data'];
        $consumption=$engine->record($actor,'consumption',(object)['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>2],'inventory-consumption-seed')['data'];
        $zero=$this->db->fetchOne("INSERT INTO articles (sku,name) VALUES ('ZERO','Actif sans solde') RETURNING id");
        $inactive=$this->db->fetchOne("INSERT INTO articles (sku,name) VALUES ('ARCHIVE','Désactivé avec stock') RETURNING id");
        $excluded=$this->db->fetchOne("INSERT INTO articles (sku,name,active) VALUES ('EXCLUDED','Désactivé sans stock',false) RETURNING id");
        $engine->record($actor,'entry',(object)['article_id'=>$inactive,'store_id'=>$this->stores['A'],'quantity'=>3,'reason'=>'Archive'],'inventory-archive-seed');
        $this->db->executeStatement('UPDATE articles SET active=false WHERE id=?',[$inactive]);
        $this->login();$draft=$this->create()['data'];
        $engine->record($actor,'entry',(object)['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>2,'reason'=>'Avant comptage'],'inventory-draft-entry');
        $started=$this->change($draft['id'],'start','inventory-start-key');self::assertResponseIsSuccessful();self::assertSame('in_progress',$started['data']['status']);self::assertNotNull($started['data']['snapshot_at']);self::assertSame($started,$this->change($draft['id'],'start','inventory-start-key'));
        $quantities=$this->db->fetchAllKeyValue('SELECT article_id,theoretical_qty FROM inventory_lines WHERE inventory_id=?',[$draft['id']]);self::assertCount(3,$quantities);self::assertSame('10',$quantities[$this->article]);self::assertSame('0',$quantities[$zero]);self::assertSame('3',$quantities[$inactive]);self::assertArrayNotHasKey($excluded,$quantities);
        self::assertFalse($this->db->fetchOne('SELECT 1 FROM stock_levels WHERE article_id=?',[$zero]));
        $jobs=[['entry',['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>1,'reason'=>'Entrée']],['consumption',['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>1]],['adjustment',['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>1,'reason'=>'Ajustement']],['transfer',['article_id'=>$this->article,'source_store_id'=>$this->stores['A'],'destination_store_id'=>$this->stores['B'],'quantity'=>1]],['transfer',['article_id'=>$this->article,'source_store_id'=>$this->stores['B'],'destination_store_id'=>$this->stores['A'],'quantity'=>1]]];
        foreach($jobs as $n=>[$kind,$body]) {try {$engine->record($admin,$kind,(object)$body,'inventory-blocked-'.$n);self::fail('Mouvement passé pendant inventaire');}catch(ApiProblem $e) {self::assertSame('inventory_in_progress',$e->errorCode);}}
        try {static::getContainer()->get(\App\Stock\StockReturnEngine::class)->record($admin,(object)['consumption_id'=>$consumption['id'],'quantity'=>1],'inventory-return-blocked');self::fail('Retour autorisé');}catch(ApiProblem $e) {self::assertSame('inventory_in_progress',$e->errorCode);}
        try {static::getContainer()->get(\App\Stock\StockCorrectionEngine::class)->correct($admin,$entry['id'],(object)['reason'=>'Correction'],'inventory-reverse-blocked',true);self::fail('Annulation autorisée');}catch(ApiProblem $e) {self::assertSame('inventory_in_progress',$e->errorCode);}
        try {static::getContainer()->get(\App\Administration\StoreAdministration::class)->update($admin,$this->stores['A'],(object)['active'=>false]);self::fail('Magasin désactivé pendant comptage');}catch(ApiProblem $e) {self::assertSame('inventory_in_progress',$e->errorCode);}
        $engine->record($admin,'entry',(object)['article_id'=>$this->article,'store_id'=>$this->stores['B'],'quantity'=>1,'reason'=>'Autre magasin'],'inventory-other-store');
        $other=$this->create('A','inventory-second-draft')['data'];$this->change($other['id'],'start','inventory-second-start');self::assertResponseStatusCodeSame(409);self::assertSame('draft',$this->db->fetchOne('SELECT status FROM inventories WHERE id=?',[$other['id']]));
        $this->client->request('GET','/inventaires/'.$draft['id']);self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Référence figée');self::assertStringNotContainsString('<script>test</script>',$this->client->getResponse()->getContent());
        $this->client->request('GET','/api/v1/inventories/'.$draft['id'].'/lines?article_id='.$inactive);$lines=json_decode($this->client->getResponse()->getContent(),true);self::assertSame(1,$lines['meta']['total']);self::assertSame(3,$lines['data'][0]['theoretical_qty']);self::assertTrue(static::getContainer()->get(OpenApiContract::class)->valid(json_decode(json_encode($lines)),['$ref'=>'#/components/schemas/InventoryLinePage']));
        $this->change($draft['id'],'cancel','inventory-unfreeze-key');self::assertResponseIsSuccessful();self::assertSame($quantities,$this->db->fetchAllKeyValue('SELECT article_id,theoretical_qty FROM inventory_lines WHERE inventory_id=?',[$draft['id']]));
    }
    public function testInvalidRequestsCsrfIdempotencyAndRevocation(): void
    {
        $this->login('admin');$draft=$this->create()['data'];$this->create('B');self::assertResponseStatusCodeSame(409);
        $this->client->request('POST','/api/v1/inventories/'.$draft['id'].'/start',server:['HTTP_IDEMPOTENCY_KEY'=>'inventory-without-csrf']);self::assertResponseStatusCodeSame(403);
        $this->client->request('POST','/api/v1/inventories/'.$draft['id'].'/start',server:['HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>'inventory-extra-body'],content:'{}');self::assertResponseStatusCodeSame(422);
        $this->change($draft['id'],'start','bad');self::assertResponseStatusCodeSame(422);
        $this->client->request('GET','/api/v1/inventories/bad');self::assertResponseStatusCodeSame(404);
        $this->client->request('GET','/api/v1/inventories/'.$draft['id'].'/lines?sort=quantity');self::assertResponseStatusCodeSame(422);
        $this->login();$own=$this->create('A','manager-inventory-create')['data'];$this->db->executeStatement('DELETE FROM user_stores WHERE user_id=?',[$this->users['manager']]);$this->change($own['id'],'cancel','revoked-inventory-cancel');self::assertResponseStatusCodeSame(404);
        self::assertSame('draft',$this->db->fetchOne('SELECT status FROM inventories WHERE id=?',[$own['id']]));
    }
    public function testDatabaseProtectsReferenceAndTerminalCampaignAndAuditFailureRollsBackStart(): void
    {
        $this->login();$draft=$this->create()['data'];
        $this->db->executeStatement("CREATE FUNCTION fail_inventory_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.action='inventory.start' THEN RAISE EXCEPTION 'Injected audit failure'; END IF; RETURN NEW; END $$");
        $this->db->executeStatement('CREATE TRIGGER inventory_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION fail_inventory_audit()');
        try {static::getContainer()->get(InventoryWorkflow::class)->transition($this->actor(),$draft['id'],'start','inventory-atomic-start');self::fail('Panne non propagée');}catch(\Doctrine\DBAL\Exception $e) {self::assertStringContainsString('Injected audit failure',$e->getMessage());}
        self::assertSame('draft',$this->db->fetchOne('SELECT status FROM inventories WHERE id=?',[$draft['id']]));self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM inventory_lines'));self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM inventory_requests'));
        $this->db->executeStatement('DROP TRIGGER inventory_audit_failure ON audit_logs');$this->change($draft['id'],'start','inventory-atomic-start');self::assertResponseIsSuccessful();
        $this->rejected('UPDATE inventory_lines SET theoretical_qty=4 WHERE inventory_id=?',[$draft['id']]);$this->rejected('DELETE FROM inventory_lines WHERE inventory_id=?',[$draft['id']]);
        $this->rejected("UPDATE inventories SET status='draft' WHERE id=?",[$draft['id']]);
        $this->change($draft['id'],'cancel','inventory-terminal-cancel');self::assertResponseIsSuccessful();
        $this->rejected('UPDATE inventory_lines SET counted_qty=0,counted_by=?,counted_at=clock_timestamp() WHERE inventory_id=?',[$this->users['manager'],$draft['id']]);
        $this->rejected('DELETE FROM inventories WHERE id=?',[$draft['id']]);$this->rejected("UPDATE inventories SET status='in_progress' WHERE id=?",[$draft['id']]);
        $this->rejected('DELETE FROM inventory_requests WHERE inventory_id=?',[$draft['id']]);
    }
    public function testClosedCampaignRemainsImmutableAndNoWorkflowCanReopenIt(): void
    {
        $this->login();$id=$this->create()['data']['id'];$this->change($id,'start','closed-inventory-start');self::assertResponseIsSuccessful();
        $this->db->executeStatement('UPDATE inventory_lines SET counted_qty=0,counted_by=?,counted_at=clock_timestamp() WHERE inventory_id=?',[$this->users['manager'],$id]);$this->change($id,'submit','closed-inventory-submit');self::assertResponseIsSuccessful();
        // Terminal fixture only: operational closure and its adjustments are implemented in INV-03.
        $this->db->executeStatement("UPDATE inventories SET status='closed',validated_by=?,closed_at=clock_timestamp() WHERE id=?",[$this->users['manager'],$id]);
        $this->change($id,'cancel','closed-inventory-cancel');self::assertResponseStatusCodeSame(409);$this->change($id,'start','closed-inventory-restart');self::assertResponseStatusCodeSame(409);
        $this->rejected('UPDATE inventories SET closed_at=clock_timestamp() WHERE id=?',[$id]);$this->rejected('DELETE FROM inventory_lines WHERE inventory_id=?',[$id]);
        $this->db->executeStatement('UPDATE stores SET active=false WHERE id=?',[$this->stores['A']]);$this->client->request('GET','/api/v1/inventories/'.$id);self::assertResponseStatusCodeSame(404);$this->login('admin');$this->client->request('GET','/inventaires/'.$id);self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Inventaire clôturé');
    }
    private function rejected(string $sql,array $parameters): void
    {
        $this->db->beginTransaction();try {$this->db->executeStatement($sql,$parameters);self::fail('Mutation interdite acceptée');}catch(\Doctrine\DBAL\Exception) {self::assertTrue(true);}finally {$this->db->rollBack();}
    }

}
