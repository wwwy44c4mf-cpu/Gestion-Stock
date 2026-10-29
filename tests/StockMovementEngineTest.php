<?php

declare(strict_types=1);

namespace App\Tests;

use App\Api\ApiProblem;
use App\Api\OpenApiContract;
use App\Command\ReconcileStockCommand;
use App\Security\StockUser;
use App\Security\StockUserProvider;
use App\Stock\StockMovementEngine;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DatabaseException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class StockMovementEngineTest extends KernelTestCase
{
    private Connection $db;
    private StockMovementEngine $engine;
    private array $actors = [], $stores = [];
    private string $article;
    protected function setUp(): void
    {
        self::bootKernel(); $this->db = static::getContainer()->get(Connection::class); $this->db->beginTransaction();
        $schema = 'stk02_'.bin2hex(random_bytes(6)); $this->db->executeStatement('CREATE SCHEMA '.$schema); $this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        foreach (explode('-- migrate:statement', file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) { if (trim($sql) !== '') { $this->db->executeStatement($sql); } }
        \App\Tests\CatalogDeletionSchema::apply($this->db);
        $this->db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
        foreach (['20261018140000','20261018200000'] as $version) {
            require_once dirname(__DIR__).'/migrations/Version'.$version.'.php'; $class = 'DoctrineMigrations\\Version'.$version;
            $migration = new $class($this->db, new \Psr\Log\NullLogger()); $migration->up(new \Doctrine\DBAL\Schema\Schema()); foreach ($migration->getSql() as $sql) { $this->db->executeStatement($sql->getStatement()); }
        }
        $hash = password_hash('Stock-engine-2026!', PASSWORD_ARGON2ID);
        foreach (['admin'=>1,'manager'=>2,'user'=>3,'other'=>3] as $name=>$role) {
            $this->db->executeStatement('INSERT INTO users (email,name,password_hash,role_id) VALUES (?,?,?,?)', [$name.'@example.test', $name, $hash, $role]);
            $this->actors[$name] = static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier($name.'@example.test');
        }
        foreach (['A','B','C'] as $code) { $this->stores[$code] = $this->db->fetchOne('INSERT INTO stores (code,name) VALUES (?,?) RETURNING id', [$code, 'Magasin '.$code]); }
        foreach (['manager'=>['A','B'],'user'=>['A']] as $name=>$codes) { foreach ($codes as $code) { $this->db->insert('user_stores',['user_id'=>$this->actors[$name]->id,'store_id'=>$this->stores[$code]]); } }
        $this->article = $this->db->fetchOne("INSERT INTO articles (sku,name) VALUES ('ENGINE','Article moteur') RETURNING id");
        $this->engine = static::getContainer()->get(StockMovementEngine::class);
    }
    protected function tearDown(): void { if ($this->db->isTransactionActive()) { $this->db->rollBack(); } parent::tearDown(); }
    private function post(string $operation, array $body = [], string $actor = 'admin', string $key = 'engine-test-key'): array
    {
        $default = ['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>1];
        if (in_array($operation,['entry','adjustment'],true)) { $default['reason'] = 'Motif fictif confidentiel'; }
        if ($operation === 'transfer') { unset($default['store_id']); $default['source_store_id']=$this->stores['A']; $default['destination_store_id']=$this->stores['B']; }
        return $this->engine->record($this->actors[$actor],$operation,(object)array_replace($default,$body),$key);
    }
    private function denied(callable $action, int $status, string $code): void
    {
        $before = $this->snapshot();
        try { $action(); } catch (ApiProblem $error) { self::assertSame($status,$error->status);self::assertSame($code,$error->errorCode);self::assertSame($before,$this->snapshot());return; }
        self::fail('A problem was expected.');
    }
    private function snapshot(): array
    {
        $result=[];foreach(['stock_levels','stock_movements','stock_change_events','audit_logs'] as $table) { $result[$table]=$this->db->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY 1'); }return $result;
    }
    private function quantity(string $code='A'): int { return (int)$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE article_id = ? AND store_id = ?',[$this->article,$this->stores[$code]]); }
    private function consistent(): void
    {
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference <> 0'));
        $tester=new CommandTester(static::getContainer()->get(ReconcileStockCommand::class));self::assertSame(0,$tester->execute([]));self::assertTrue(json_decode($tester->getDisplay(),true)['consistent']);
    }
    public function testEntryConsumptionExactJournalBalancesAuditAndEventsAreAtomic(): void
    {
        $before = new \DateTimeImmutable('now',new \DateTimeZone('UTC'));
        $first=$this->post('entry',['quantity'=>5,'reference'=>'chantier fictif'],'manager','initial-entry-key');$this->post('entry',['quantity'=>10],'manager','second-entry-key');self::assertSame(15,$this->quantity());
        $out=$this->post('consumption',['quantity'=>4,'consumer_id'=>$this->actors['other']->id,'comment'=>'Texte privé fictif'],'manager','first-consumption-key');self::assertSame(11,$this->quantity());self::assertSame($this->actors['manager']->id,$out['data']['author_id']);self::assertSame($this->actors['other']->id,$out['data']['consumer_id']);
        self::assertSame(3,(int)$this->db->fetchOne('SELECT version FROM stock_levels'));self::assertSame(3,(int)$this->db->fetchOne('SELECT count(*) FROM stock_movements'));self::assertSame(3,(int)$this->db->fetchOne('SELECT count(*) FROM stock_change_events'));self::assertSame(3,(int)$this->db->fetchOne('SELECT count(*) FROM audit_logs'));
        self::assertTrue(static::getContainer()->get(OpenApiContract::class)->valid(json_decode(json_encode($out)),['$ref'=>'#/components/schemas/MovementResource']));
        $date=new \DateTimeImmutable($first['data']['created_at']);self::assertGreaterThanOrEqual($before->getTimestamp(),$date->getTimestamp());self::assertSame(0,$date->getOffset());
        self::assertArrayNotHasKey('request_fingerprint',$out['data']);self::assertArrayNotHasKey('idempotency_key',$out['data']);
        $audit=implode(' ',$this->db->fetchFirstColumn('SELECT context::text FROM audit_logs'));foreach(['Motif fictif','chantier fictif','Texte privé'] as $text) { self::assertStringNotContainsString($text,$audit); }
        $this->consistent();
    }
    public function testInsufficientStockCannotLeaveBalancesMovementsOrEventsBehind(): void
    {
        $this->denied(fn()=> $this->post('consumption',[], 'user'),409,'insufficient_stock');self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_levels'));
        $this->post('entry',['quantity'=>11],key:'seed-eleven-key');$this->denied(fn()=> $this->post('consumption',['quantity'=>20],'user'),409,'insufficient_stock');self::assertSame(11,$this->quantity());$this->consistent();
    }
    public function testReplayIsDurableAcrossSubsequentWritesAndMutableBusinessState(): void
    {
        $first=$this->post('entry',['quantity'=>5],key:'durable-entry-key');$consumption=$this->post('consumption',['quantity'=>1],'user','later-consumption');$this->db->executeStatement('UPDATE articles SET active = false WHERE id = ?',[$this->article]);
        $this->db->executeStatement("INSERT INTO inventories (store_id,status,created_by,snapshot_at) VALUES (?,'in_progress',?,clock_timestamp())",[$this->stores['A'],$this->actors['admin']->id]);
        $snapshot=$this->snapshot();self::assertSame($first,$this->post('entry',['quantity'=>5],key:'durable-entry-key'));self::assertSame($snapshot,$this->snapshot());
        $this->denied(fn()=> $this->post('entry',['quantity'=>6],key:'durable-entry-key'),409,'idempotency_conflict');$this->denied(fn()=> $this->post('adjustment',['quantity'=>5],key:'durable-entry-key'),409,'idempotency_conflict');
        $this->db->executeStatement('DELETE FROM user_stores WHERE user_id = ?',[$this->actors['user']->id]);self::assertSame($consumption,$this->post('consumption',['quantity'=>1],'user','later-consumption'));self::assertSame($snapshot,$this->snapshot());$this->consistent();
    }
    public function testConsumptionReplaySurvivesBeneficiaryDeactivationWithoutNewWrites(): void
    {
        $this->post('entry',['quantity'=>3],key:'beneficiary-seed-key');
        $body=['consumer_id'=>$this->actors['other']->id]; $first=$this->post('consumption',$body,'manager','beneficiary-replay-key');
        $this->db->executeStatement('UPDATE users SET active = false WHERE id = ?',[$this->actors['other']->id]);
        $before=$this->snapshot();self::assertSame($first,$this->post('consumption',$body,'manager','beneficiary-replay-key'));self::assertSame($before,$this->snapshot());
        $this->denied(fn()=> $this->post('consumption',$body,'manager','beneficiary-new-key'),422,'invalid_beneficiary');$this->consistent();
    }

    public function testKeysAreScopedToActorAndCanonicalBodyOrderAndUuidCase(): void
    {
        $body=(object)['quantity'=>2,'reason'=>'Idem','store_id'=>strtoupper($this->stores['A']),'article_id'=>strtoupper($this->article)];
        $first=$this->engine->record($this->actors['admin'],'entry',$body,'canonical-body-key');
        $second=$this->engine->record($this->actors['admin'],'entry',(object)['article_id'=>$this->article,'store_id'=>$this->stores['A'],'reason'=>'Idem','quantity'=>2],'canonical-body-key');self::assertSame($first,$second);
        $this->post('entry',['quantity'=>1],'manager','canonical-body-key');self::assertSame(3,$this->quantity());self::assertSame(2,(int)$this->db->fetchOne('SELECT count(*) FROM stock_movements'));$this->consistent();
    }
    public function testRolePerimeterBeneficiaryAndCurrentIdentityAreCheckedServerSide(): void
    {
        $this->denied(fn()=> $this->post('entry',[],'user'),403,'permission_denied');$this->denied(fn()=> $this->post('entry',['store_id'=>$this->stores['C']],'manager'),404,'resource_not_found');
        $this->post('entry',['quantity'=>3],key:'seed-role-key');$this->denied(fn()=> $this->post('consumption',['consumer_id'=>$this->actors['other']->id],'user'),403,'permission_denied');
        $this->db->executeStatement('UPDATE users SET active = false WHERE id = ?',[$this->actors['other']->id]);$this->denied(fn()=> $this->post('consumption',['consumer_id'=>$this->actors['other']->id],'manager'),422,'invalid_beneficiary');
        $this->db->executeStatement('UPDATE stores SET active = false WHERE id = ?',[$this->stores['A']]);$this->denied(fn()=> $this->post('entry'),404,'resource_not_found');
        $this->db->executeStatement('UPDATE users SET auth_version = auth_version + 1 WHERE id = ?',[$this->actors['manager']->id]);$this->denied(fn()=> $this->post('entry',[],'manager'),401,'authentication_required');
    }
    public function testInventoryLocksEitherTransferEndAndReleasesAfterCancellation(): void
    {
        $this->post('entry',['quantity'=>5],key:'inventory-seed-key');
        foreach(['A','B'] as $code) { foreach(['in_progress','pending_validation'] as $status) {
            $id=$this->db->fetchOne('INSERT INTO inventories (store_id,status,created_by,snapshot_at) VALUES (?,?,?,clock_timestamp()) RETURNING id',[$this->stores[$code],$status,$this->actors['admin']->id]);
            $this->denied(fn()=> $this->post('transfer',['quantity'=>2]),409,'inventory_in_progress');$this->denied(fn()=> $this->post('entry',['store_id'=>$this->stores[$code]]),409,'inventory_in_progress');
            if ($code === 'A') { $this->denied(fn()=> $this->post('consumption',[],'user'),409,'inventory_in_progress');$this->denied(fn()=> $this->post('adjustment',['quantity'=>-1]),409,'inventory_in_progress'); }
            $this->db->executeStatement("UPDATE inventories SET status = 'cancelled', cancelled_at = clock_timestamp() WHERE id = ?",[$id]);
        } }
        $this->post('transfer',['quantity'=>2],key:'released-transfer-key');self::assertSame(3,$this->quantity());self::assertSame(2,$this->quantity('B'));$this->consistent();
    }
    public function testTransfersAndAdjustmentsStayBalancedAndEnforceLimits(): void
    {
        $this->post('entry',['quantity'=>5],key:'transfer-seed-key');$this->post('transfer',['quantity'=>3],key:'transfer-three-key');self::assertSame(2,$this->quantity());self::assertSame(3,$this->quantity('B'));$this->consistent();
        $this->post('adjustment',['quantity'=>-1],key:'adjust-down-key');$this->post('adjustment',['quantity'=>2],key:'adjust-up-key');self::assertSame(3,$this->quantity());$this->consistent();
        $this->denied(fn()=> $this->post('transfer',['destination_store_id'=>$this->stores['A']]),422,'same_store_transfer');$this->denied(fn()=> $this->post('transfer',['destination_store_id'=>$this->stores['C']],'manager'),404,'resource_not_found');
        $this->post('entry',['store_id'=>$this->stores['C'],'quantity'=>9007199254740991],key:'maximum-stock-key');$this->denied(fn()=> $this->post('transfer',['destination_store_id'=>$this->stores['C']]),409,'stock_limit_reached');self::assertSame(3,$this->quantity());$this->consistent();
        $this->db->executeStatement('UPDATE stock_levels SET version = 9223372036854775807 WHERE store_id = ?',[$this->stores['A']]);$this->denied(fn()=> $this->post('entry'),409,'stock_limit_reached');
    }
    public function testArchivedArticlesBlockNewLogisticsButAllowMotivatedCorrections(): void
    {
        $this->post('entry',['quantity'=>3],key:'archive-seed-key');$this->db->executeStatement('UPDATE articles SET active = false WHERE id = ?',[$this->article]);
        foreach (['entry','consumption','transfer'] as $operation) { $this->denied(fn()=> $this->post($operation),409,'inactive_article'); }
        $this->post('adjustment',['quantity'=>-1],key:'archived-adjust-key');self::assertSame(2,$this->quantity());$this->consistent();
    }

    public function testInvalidNumbersDatesTextsAndIdentifiersNeverWrite(): void
    {
        foreach([0,-1,1.0,1.5,'1',true,null,9007199254740992] as $quantity) { $this->denied(fn()=> $this->post('entry',['quantity'=>$quantity]),422,'invalid_movement'); }
        foreach([['created_at'=>'2000-01-01T00:00:00Z'],['reason'=>'   '],['unknown'=>'x'],['article_id'=>'bad'],['reason'=>str_repeat('a',2001)]] as $body) { $this->denied(fn()=> $this->post('entry',$body),422,'invalid_movement'); }
        foreach(["bad\0text", "\xff"] as $reason) { $this->denied(fn()=> $this->post('entry',['reason'=>$reason]),422,'invalid_text'); }
        $this->denied(fn()=> $this->post('entry',key:'bad'),422,'invalid_idempotency_key');$this->denied(fn()=> $this->post('entry',['article_id'=>'11111111-1111-4111-8111-111111111111']),404,'article_not_found');
        $this->denied(fn()=> $this->engine->record($this->actors['admin'],'return',(object)[],'unsupported-key'),422,'unsupported_movement');
    }
    public function testLateDatabaseFailureRollsBackBalancesMovementEventAndAuditTogether(): void
    {
        $this->post('entry',['quantity'=>3],key:'rollback-seed-key');$before=$this->snapshot();
        $this->db->executeStatement("CREATE FUNCTION fail_stock_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.action LIKE 'stock.%' THEN RAISE EXCEPTION 'Injected audit failure'; END IF; RETURN NEW; END $$");$this->db->executeStatement('CREATE TRIGGER fail_stock_audit BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION fail_stock_audit()');
        try { $this->post('consumption',[],'user','rollback-consumption');self::fail('A database failure was expected.'); } catch(DatabaseException) { self::assertSame($before,$this->snapshot()); }
        $this->db->executeStatement('DROP TRIGGER fail_stock_audit ON audit_logs');$this->post('consumption',[],'user','rollback-consumption');self::assertSame(2,$this->quantity());$this->consistent();
    }
    public function testMovementImmutabilityAndReconciliationDetectUnauthorizedSqlChanges(): void
    {
        $movement=$this->post('entry',['quantity'=>5],key:'immutable-seed-key')['data']['id'];
        foreach(['UPDATE stock_movements SET quantity = 1 WHERE id = ?','DELETE FROM stock_movements WHERE id = ?'] as $sql) { try { $this->db->transactional(fn()=> $this->db->executeStatement($sql,[$movement]));self::fail('History must be immutable.'); } catch(DatabaseException) { self::assertSame(5,$this->quantity()); } }
        $this->consistent();$this->db->executeStatement('UPDATE stock_levels SET quantity = 4 WHERE article_id = ?',[$this->article]);$tester=new CommandTester(static::getContainer()->get(ReconcileStockCommand::class));self::assertSame(1,$tester->execute([]));self::assertFalse(json_decode($tester->getDisplay(),true)['consistent']);self::assertSame(4,$this->quantity());
    }
}
