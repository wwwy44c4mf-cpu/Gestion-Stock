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

final class InventoryValidationTest extends WebTestCase
{
    private $client;private Connection $db;private array $users=[], $stores=[];private string $csrf,$article;
    protected function setUp(): void
    {
        $this->client=static::createClient();$this->client->disableReboot();$this->db=static::getContainer()->get(Connection::class);$this->db->beginTransaction();
        $schema='inv01_'.bin2hex(random_bytes(6));$this->db->executeStatement('CREATE SCHEMA '.$schema);$this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        foreach(explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) {if(trim($sql)!=='') {$this->db->executeStatement($sql);}}
        $this->db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
        foreach(['20261018140000','20261018200000','20261018223000','20261020220000','20261020230000','20261021000000'] as $version) {require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';$class='DoctrineMigrations\\Version'.$version;$migration=new $class($this->db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) {$this->db->executeStatement($sql->getStatement());}}
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
    private function started(): string
    {
        $this->login();$id=$this->create()['data']['id'];$this->change($id,'start','count-start-key');self::assertResponseIsSuccessful();return $id;
    }
    private function write(string $id,array $lines,string $key='inventory-count-key'): array
    {
        $this->client->request('POST','/api/v1/inventories/'.$id.'/lines',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>$key],content:json_encode(['lines'=>$lines],JSON_PRESERVE_ZERO_FRACTION));return json_decode($this->client->getResponse()->getContent(),true)??[];
    }
    private function input(int $quantity=0,int $version=0,?string $article=null): array {return ['article_id'=>$article??$this->article,'counted_qty'=>$quantity,'expected_version'=>$version];}
    private function row(string $id): array {return $this->db->fetchAssociative('SELECT counted_qty,counted_by,counted_at,version,delta FROM inventory_lines WHERE inventory_id=? AND article_id=?',[$id,$this->article]);}
    private function seed(int $quantity=10,?string $article=null,string $key='validation-seed'): array
    {
        return static::getContainer()->get(StockMovementEngine::class)->record($this->actor(),'entry',(object)['article_id'=>$article??$this->article,'store_id'=>$this->stores['A'],'quantity'=>$quantity,'reason'=>'Stock initial'],$key)['data'];
    }
    private function pending(int $quantity=8): string
    {
        $this->seed();$id=$this->started();$this->write($id,[$this->input($quantity)]);self::assertResponseIsSuccessful();$this->change($id,'submit','validation-submit');self::assertResponseIsSuccessful();return $id;
    }
    public function testTenToEightClosesWithExactlyOneLinkedAdjustmentAndReleasesStore(): void
    {
        $id=$this->pending();$count=$this->row($id);$this->login('admin');$before=time();$result=$this->change($id,'validate','validation-close-key');self::assertResponseIsSuccessful();
        $inventory=$result['data']['inventory'];self::assertSame('closed',$inventory['status']);self::assertSame($this->users['manager'],$inventory['created_by']);self::assertSame($this->users['admin'],$inventory['validated_by']);self::assertGreaterThanOrEqual($before,(new \DateTimeImmutable($inventory['closed_at']))->getTimestamp());self::assertCount(1,$result['data']['adjustment_ids']);
        $movement=$this->db->fetchAssociative('SELECT * FROM stock_movements WHERE id=?',[$result['data']['adjustment_ids'][0]]);self::assertSame('adjustment',$movement['type']);self::assertSame($id,$movement['inventory_id']);self::assertSame($this->stores['A'],$movement['source_store_id']);self::assertNull($movement['destination_store_id']);self::assertSame('2',$movement['quantity']);self::assertSame($this->users['admin'],$movement['author_id']);self::assertNotEmpty($movement['reason']);
        self::assertSame('8',$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE article_id=?',[$this->article]));self::assertSame($count,$this->row($id));self::assertSame(2,(int)$this->db->fetchOne('SELECT count(*) FROM stock_change_events'));self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference<>0'));
        self::assertTrue(static::getContainer()->get(OpenApiContract::class)->valid(json_decode(json_encode($result)),['$ref'=>'#/components/schemas/InventoryValidationResource']));
        self::assertSame($result,$this->change($id,'validate','validation-close-key'));self::assertSame(2,(int)$this->db->fetchOne('SELECT count(*) FROM stock_movements'));$this->change($id,'validate','validation-new-key');self::assertResponseStatusCodeSame(409);
        static::getContainer()->get(StockMovementEngine::class)->record($this->actor(),'entry',(object)['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>1,'reason'=>'Après clôture'],'validation-after-close');self::assertSame('9',$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE article_id=?',[$this->article]));
        self::assertSame($result,$this->change($id,'validate','validation-close-key'));self::assertSame('9',$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE article_id=?',[$this->article]));
    }
    public function testPositiveNegativeUnchangedAndInactiveArticlesAreHandledWithoutZeroMovements(): void
    {
        $zero=$this->db->fetchOne("INSERT INTO articles (sku,name) VALUES ('ZERO','Sans stock') RETURNING id");$same=$this->db->fetchOne("INSERT INTO articles (sku,name) VALUES ('SAME','Sans écart') RETURNING id");$inactive=$this->db->fetchOne("INSERT INTO articles (sku,name) VALUES ('INACTIVE','À sortir') RETURNING id");$this->seed();$this->seed(4,$same,'validation-same-seed');$this->seed(5,$inactive,'validation-inactive-seed');$this->db->executeStatement('UPDATE articles SET active=false WHERE id=?',[$inactive]);$id=$this->started();
        $this->write($id,[$this->input(8),$this->input(3,0,$zero),$this->input(4,0,$same),$this->input(0,0,$inactive)]);self::assertResponseIsSuccessful();$this->change($id,'submit','validation-mixed-submit');$result=$this->change($id,'validate','validation-mixed-close');self::assertResponseIsSuccessful();self::assertCount(3,$result['data']['adjustment_ids']);
        $balances=$this->db->fetchAllKeyValue('SELECT article_id,quantity FROM stock_levels WHERE store_id=?',[$this->stores['A']]);self::assertSame('8',$balances[$this->article]);self::assertSame('3',$balances[$zero]);self::assertSame('4',$balances[$same]);self::assertSame('0',$balances[$inactive]);self::assertFalse($this->db->fetchOne('SELECT 1 FROM stock_movements WHERE inventory_id=? AND article_id=?',[$id,$same]));self::assertSame(1,(int)$this->db->fetchOne('SELECT version FROM stock_levels WHERE article_id=?',[$same]));self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference<>0'));
    }
    public function testNoDifferencesClosesWithoutCreatingBalanceOrMovement(): void
    {
        $id=$this->started();$this->write($id,[$this->input(0)]);$this->change($id,'submit','validation-zero-submit');$result=$this->change($id,'validate','validation-zero-close');self::assertResponseIsSuccessful();self::assertSame([],$result['data']['adjustment_ids']);self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_movements'));self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_levels'));self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_change_events'));
    }
    public function testIncompleteDriftingAndVersionLimitedBalancesFailWithoutPartialClosure(): void
    {
        $this->seed();$id=$this->started();$this->change($id,'validate','validation-before-submit');self::assertResponseStatusCodeSame(409);$this->db->executeStatement("UPDATE inventories SET status='pending_validation' WHERE id=?",[$id]);$this->change($id,'validate','validation-incomplete');self::assertResponseStatusCodeSame(409);self::assertSame('incomplete_inventory',json_decode($this->client->getResponse()->getContent(),true)['code']);
        $this->change($id,'cancel','validation-cancel-incomplete');$next=static::getContainer()->get(InventoryWorkflow::class)->create($this->actor(),(object)['store_id'=>$this->stores['A']],'validation-recreate')['data']['id'];$this->change($next,'start','validation-restart');$this->write($next,[$this->input(8)],'validation-recount');$this->change($next,'submit','validation-resubmit');
        $this->db->executeStatement('UPDATE stock_levels SET quantity=9 WHERE article_id=?',[$this->article]);$this->change($next,'validate','validation-drift');self::assertResponseStatusCodeSame(409);self::assertSame('inventory_stock_drift',json_decode($this->client->getResponse()->getContent(),true)['code']);self::assertSame('pending_validation',$this->db->fetchOne('SELECT status FROM inventories WHERE id=?',[$next]));self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM stock_movements'));
        $this->db->executeStatement('UPDATE stock_levels SET quantity=10,version=? WHERE article_id=?',[(string)PHP_INT_MAX,$this->article]);$this->change($next,'validate','validation-limit');self::assertResponseStatusCodeSame(409);self::assertSame('stock_limit_reached',json_decode($this->client->getResponse()->getContent(),true)['code']);
    }
    public function testAuditFailureRollsBackBalancesMovementsEventsClosureAndRequest(): void
    {
        $id=$this->pending();$before=$this->db->fetchAssociative('SELECT quantity,version FROM stock_levels WHERE article_id=?',[$this->article]);$count=$this->row($id);
        $this->db->executeStatement("CREATE FUNCTION fail_validation_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.action='inventory.validate' THEN RAISE EXCEPTION 'Injected closure audit failure'; END IF; RETURN NEW; END $$");$this->db->executeStatement('CREATE TRIGGER validation_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION fail_validation_audit()');
        try {static::getContainer()->get(InventoryWorkflow::class)->transition($this->actor(),$id,'validate','validation-atomic-key');self::fail('Panne masquée');}catch(\Doctrine\DBAL\Exception $e) {self::assertStringContainsString('Injected closure audit failure',$e->getMessage());}
        self::assertSame($before,$this->db->fetchAssociative('SELECT quantity,version FROM stock_levels WHERE article_id=?',[$this->article]));self::assertSame($count,$this->row($id));self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM stock_movements'));self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM stock_change_events'));self::assertSame('pending_validation',$this->db->fetchOne('SELECT status FROM inventories WHERE id=?',[$id]));self::assertNull($this->db->fetchOne('SELECT validated_by FROM inventories WHERE id=?',[$id]));self::assertNull($this->db->fetchOne('SELECT closed_at FROM inventories WHERE id=?',[$id]));
        $this->db->executeStatement('DROP TRIGGER validation_audit_failure ON audit_logs');$this->change($id,'validate','validation-atomic-key');self::assertResponseIsSuccessful();self::assertSame(2,(int)$this->db->fetchOne('SELECT count(*) FROM stock_movements'));
    }
    public function testRightsFormsConfirmationAndImmutableLinks(): void
    {
        $id=$this->pending();$this->login('user');$this->change($id,'validate','validation-user');self::assertResponseStatusCodeSame(403);$this->login('unassigned');$this->change($id,'validate','validation-unassigned');self::assertResponseStatusCodeSame(404);
        $this->login();$crawler=$this->client->request('GET','/inventaires/'.$id);self::assertResponseIsSuccessful();$form=$crawler->selectButton('Valider les écarts et clôturer')->form();$this->client->submit($form);self::assertResponseStatusCodeSame(422);self::assertSame('pending_validation',$this->db->fetchOne('SELECT status FROM inventories WHERE id=?',[$id]));
        $crawler=$this->client->request('GET','/inventaires/'.$id);$this->client->submit($crawler->selectButton('Valider les écarts et clôturer')->form(['confirm'=>'1']));self::assertResponseStatusCodeSame(303);$this->client->followRedirect();self::assertSelectorTextContains('main','Inventaire clôturé');self::assertSelectorTextContains('main','Validé par');self::assertSelectorNotExists('button:contains("Valider les écarts")');
        $movement=$this->db->fetchOne('SELECT id FROM stock_movements WHERE inventory_id=?',[$id]);self::assertSelectorExists('a[href="/mouvements/'.$movement.'"]');$this->client->request('GET','/mouvements/'.$movement);self::assertResponseIsSuccessful();self::assertSelectorExists('a[href="/inventaires/'.$id.'"]');
        try {static::getContainer()->get(\App\Stock\StockCorrectionEngine::class)->correct($this->actor(),$movement,(object)['reason'=>'Annulation'],'validation-forbidden-reversal',true);self::fail('Ajustement d’inventaire annulé');}catch(ApiProblem $e) {self::assertSame('uncorrectable_movement',$e->errorCode);}
    }
    public function testDatabaseGuardsRejectIncoherentAndDuplicateAdjustmentsAndIncompleteClosure(): void
    {
        $id=$this->pending();$sql="INSERT INTO stock_movements (type,article_id,source_store_id,quantity,author_id,inventory_id,reason,idempotency_key) VALUES ('adjustment',?,?,?,?,?,'Fixture',?)";
        $this->rejected($sql,[$this->article,$this->stores['A'],1,$this->users['manager'],$id,'guard-wrong-adjustment']);
        $this->rejected("UPDATE inventories SET status='closed',validated_by=?,closed_at=clock_timestamp() WHERE id=?",[$this->users['manager'],$id]);
        $this->db->executeStatement($sql,[$this->article,$this->stores['A'],2,$this->users['manager'],$id,'guard-valid-adjustment']);
        $this->rejected($sql,[$this->article,$this->stores['A'],2,$this->users['manager'],$id,'guard-duplicate-adjustment']);
        $this->change($id,'validate','validation-partial-history');self::assertResponseStatusCodeSame(409);self::assertSame('inventory_already_adjusted',json_decode($this->client->getResponse()->getContent(),true)['code']);self::assertSame('10',$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE article_id=?',[$this->article]));
    }
    public function testCompleteCampaignAcrossPagesAndEmptyBodyContract(): void
    {
        for($n=0;$n<30;$n++) {$this->db->executeStatement('INSERT INTO articles (sku,name) VALUES (?,?)',['MANY-'.$n,'Article '.$n]);}
        $id=$this->started();$articles=$this->db->fetchFirstColumn('SELECT article_id FROM inventory_lines WHERE inventory_id=? ORDER BY article_id',[$id]);$this->write($id,array_map(fn($article)=>$this->input(1,0,$article),$articles),'validation-many-counts');self::assertResponseIsSuccessful();$this->change($id,'submit','validation-many-submit');
        $this->client->request('POST','/api/v1/inventories/'.$id.'/validate',server:['HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>'validation-body-key'],content:'{}');self::assertResponseStatusCodeSame(422);
        $this->client->request('POST','/api/v1/inventories/'.$id.'/validate',server:['HTTP_IDEMPOTENCY_KEY'=>'validation-no-csrf']);self::assertResponseStatusCodeSame(403);
        $result=$this->change($id,'validate','validation-many-close');self::assertResponseIsSuccessful();self::assertCount(31,$result['data']['adjustment_ids']);self::assertSame(31,(int)$this->db->fetchOne('SELECT count(*) FROM stock_levels WHERE quantity=1'));self::assertSame(31,(int)$this->db->fetchOne('SELECT count(*) FROM stock_change_events'));
        $this->db->executeStatement('DELETE FROM user_stores WHERE user_id=?',[$this->users['manager']]);$this->change($id,'validate','validation-many-close');self::assertResponseStatusCodeSame(404);
    }
    private function rejected(string $sql,array $parameters): void
    {
        $this->db->beginTransaction();try {$this->db->executeStatement($sql,$parameters);self::fail('Mutation incohérente acceptée');}catch(\Doctrine\DBAL\Exception) {self::assertTrue(true);}finally {$this->db->rollBack();}
    }

}
