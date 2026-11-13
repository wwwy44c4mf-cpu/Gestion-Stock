<?php

declare(strict_types=1);
namespace App\Tests;
use App\Administration\ArticlePurge;
use App\Api\ApiProblem;
use App\Security\{StockUser,StockUserProvider};
use App\Stock\StockMovementEngine;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
final class ArticlePurgeTest extends WebTestCase
{
    private KernelBrowser $client;private Connection $db;private StockUser $admin;private ArticlePurge $cleanup;
    private string $schema;private string $testArticle;private string $realArticle;private array $stores=[];
    protected function setUp(): void
    {
        $this->client=static::createClient();$this->client->disableReboot();$this->db=static::getContainer()->get(Connection::class);
        $this->schema='cleanup_'.bin2hex(random_bytes(5));$this->db->executeStatement('CREATE SCHEMA '.$this->schema);$this->db->executeStatement('SET search_path TO '.$this->schema);
        foreach(glob(dirname(__DIR__).'/migrations/Version*.php') as $file) {require_once $file;$class='DoctrineMigrations\\'.basename($file,'.php');$m=new $class($this->db,new \Psr\Log\NullLogger());$m->up(new \Doctrine\DBAL\Schema\Schema());foreach($m->getSql() as $sql) {$this->db->executeStatement($sql->getStatement());}}
        $this->db->executeStatement("CREATE TABLE messenger_messages(id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,body text NOT NULL,headers text NOT NULL DEFAULT '{}',queue_name text NOT NULL DEFAULT 'notifications',created_at timestamp NOT NULL DEFAULT clock_timestamp(),available_at timestamp NOT NULL DEFAULT clock_timestamp(),delivered_at timestamp)");
        foreach(['admin'=>1,'manager'=>2,'user'=>3] as $name=>$role) {$this->db->insert('users',['name'=>$name,'email'=>$name.'@example.test','role_id'=>$role,'password_hash'=>password_hash('Fictitious-cleanup-only!',PASSWORD_ARGON2ID)]);}
        foreach(['A','B'] as $code) {$this->stores[$code]=$this->db->fetchOne('INSERT INTO stores(code,name) VALUES(?,?) RETURNING id',[$code,'Magasin '.$code]);}
        $this->testArticle=$this->db->fetchOne("INSERT INTO articles(sku,name,scan_codes) VALUES('ESSAI','Article fictif','[\"CODE-ESSAI\"]') RETURNING id");
        $this->realArticle=$this->db->fetchOne("INSERT INTO articles(sku,name) VALUES('REEL','Article conservé') RETURNING id");
        $this->admin=$this->actor('admin');$this->cleanup=static::getContainer()->get(ArticlePurge::class);$this->client->loginUser($this->admin);
        foreach([$this->testArticle,$this->realArticle] as $id) {$this->move('entry',['article_id'=>$id,'store_id'=>$this->stores['A'],'quantity'=>20,'reason'=>'Fictif']);}
    }
    protected function tearDown(): void {if($this->db->isTransactionActive()) {$this->db->rollBack();}$this->db->executeStatement('SET search_path TO public');$this->db->executeStatement('DROP SCHEMA '.$this->schema.' CASCADE');parent::tearDown();}
    private function actor(string $name): StockUser {return static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier($name.'@example.test');}
    private function move(string $kind,array $body): array {return static::getContainer()->get(StockMovementEngine::class)->record($this->admin,$kind,(object)$body,bin2hex(random_bytes(16)))['data'];}
    private function purge(?array $preview=null): array {$p=$preview??$this->cleanup->preview($this->admin,$this->testArticle);return $this->cleanup->purge($this->admin,$this->testArticle,$p['token'],$p['at'],'ESSAI',true);}
    private function denied(callable $call,int $status): void {try {$call();self::fail('Refus attendu');}catch(ApiProblem $e) {self::assertSame($status,$e->status);}}
    private function assertGuardsActive(): void
    {
        self::assertSame(0,(int)$this->db->fetchOne("SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgrelid IN (SELECT oid FROM pg_class WHERE relnamespace=?::regnamespace) AND tgenabled<>'O'",[$this->schema]));
        try {$this->db->transactional(fn()=> $this->db->executeStatement('DELETE FROM stock_movements WHERE article_id=?',[$this->realArticle]));self::fail('Historique réel protégé');}catch(\Doctrine\DBAL\Exception) {self::assertTrue(true);}
    }
    public function testPurgeRemovesAllStoresTransfersReturnsCorrectionsAndKeepsOtherArticleExact(): void
    {
        $this->move('transfer',['article_id'=>$this->testArticle,'source_store_id'=>$this->stores['A'],'destination_store_id'=>$this->stores['B'],'quantity'=>5]);
        $out=$this->move('consumption',['article_id'=>$this->testArticle,'store_id'=>$this->stores['A'],'quantity'=>3]);
        static::getContainer()->get(\App\Stock\StockReturnEngine::class)->record($this->admin,(object)['consumption_id'=>$out['id'],'store_id'=>$this->stores['B'],'quantity'=>1],bin2hex(random_bytes(16)));
        static::getContainer()->get(\App\Stock\ReturnBatchEngine::class)->record($this->admin,(object)['article_id'=>$this->testArticle,'store_id'=>$this->stores['B'],'consumer_id'=>$this->admin->id,'quantity'=>1],bin2hex(random_bytes(16)));
        $entry=$this->move('entry',['article_id'=>$this->testArticle,'store_id'=>$this->stores['A'],'quantity'=>2,'reason'=>'Essai correction']);
        static::getContainer()->get(\App\Stock\StockCorrectionEngine::class)->correct($this->admin,$entry['id'],(object)['quantity'=>1,'reason'=>'Correction fictive'],bin2hex(random_bytes(16)));
        $this->db->insert('article_locations',['article_id'=>$this->testArticle,'store_id'=>$this->stores['A'],'location'=>'Case fictive']);
        $before=$this->db->fetchAllAssociative('SELECT * FROM stock_movements WHERE article_id=? ORDER BY id',[$this->realArticle]);
        $counts=$this->purge();self::assertGreaterThan(5,$counts['movements']);
        foreach(['articles'=>'id','stock_levels'=>'article_id','stock_movements'=>'article_id','article_locations'=>'article_id','article_scan_codes'=>'article_id','stock_change_events'=>'article_id'] as $table=>$column) {self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM '.$table.' WHERE '.$column.'=?',[$this->testArticle]));}
        self::assertSame($before,$this->db->fetchAllAssociative('SELECT * FROM stock_movements WHERE article_id=? ORDER BY id',[$this->realArticle]));
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference<>0'));
        self::assertSame(1,(int)$this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE action='article.purged' AND target_id IS NULL"));$this->assertGuardsActive();
        $this->denied(fn()=> $this->purge(),404);
    }
    public function testPreviewChangeForgeryWrongReferenceAndMissingConfirmationNeverDelete(): void
    {
        $p=$this->cleanup->preview($this->admin,$this->testArticle);
        $this->denied(fn()=> $this->cleanup->purge($this->admin,$this->testArticle,$p['token'],$p['at'],'ESSAI',false),422);
        $this->denied(fn()=> $this->cleanup->purge($this->admin,$this->testArticle,$p['token'],$p['at'],'AUTRE',true),422);
        $this->denied(fn()=> $this->cleanup->purge($this->admin,$this->testArticle,str_repeat('0',64),$p['at'],'ESSAI',true),409);
        $this->move('entry',['article_id'=>$this->testArticle,'store_id'=>$this->stores['A'],'quantity'=>1,'reason'=>'Ajout après aperçu']);
        $this->denied(fn()=> $this->purge($p),409);self::assertSame(21,(int)$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE article_id=?',[$this->testArticle]));$this->assertGuardsActive();
    }
    public function testOrdinaryUsersAreForbiddenAndConfirmationRequiresCsrf(): void
    {
        foreach(['user'] as $role) {$this->denied(fn()=> $this->cleanup->preview($this->actor($role),$this->testArticle),403);$this->client->loginUser($this->actor($role));$this->client->request('GET','/articles/suppression-definitive');self::assertResponseStatusCodeSame(403);}
        $this->client->loginUser($this->admin);$url='/articles/'.$this->testArticle.'/effacer-definitivement';$crawler=$this->client->request('GET',$url);self::assertResponseIsSuccessful();self::assertSelectorTextContains('h1','ESSAI');
        $this->client->request('POST',$url,['sku'=>'ESSAI','confirmed'=>'1']);self::assertResponseStatusCodeSame(403);
        $crawler=$this->client->request('GET',$url);$form=$crawler->selectButton('Supprimer définitivement cet article')->form(['sku'=>'ESSAI','confirmed'=>'1']);$this->client->submit($form);self::assertResponseStatusCodeSame(303);
        self::assertFalse($this->db->fetchOne('SELECT 1 FROM articles WHERE id=?',[$this->testArticle]));
    }
    public function testFailureRestoresEveryRowAndEveryImmutabilityGuard(): void
    {
        $this->db->executeStatement("CREATE FUNCTION refuse_cleanup_test() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'fixture rollback' USING ERRCODE='23514'; END $$");
        $this->db->executeStatement('CREATE TRIGGER refuse_cleanup_test BEFORE DELETE ON articles FOR EACH ROW EXECUTE FUNCTION refuse_cleanup_test()');
        $this->denied(fn()=> $this->purge(),409);
        self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM stock_movements WHERE article_id=?',[$this->testArticle]));self::assertSame(20,(int)$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE article_id=?',[$this->testArticle]));$this->assertGuardsActive();
    }
    public function testOpenInventoryOrPendingProposalBlocksCleanup(): void
    {
        $workflow=static::getContainer()->get(\App\Inventory\InventoryWorkflow::class);$inv=$workflow->create($this->admin,(object)['store_id'=>$this->stores['A']],bin2hex(random_bytes(16)))['data'];$workflow->transition($this->admin,$inv['id'],'start',bin2hex(random_bytes(16)));
        self::assertNotEmpty($this->cleanup->preview($this->admin,$this->testArticle)['blocked']);$this->denied(fn()=> $this->purge(),409);
        $workflow->transition($this->admin,$inv['id'],'cancel',bin2hex(random_bytes(16)));
        $this->db->insert('article_proposals',['operation'=>'update','article_id'=>$this->testArticle,'base_version'=>1,'payload'=>'{"name":"Nom"}','proposed_by'=>$this->admin->id]);
        self::assertNotEmpty($this->cleanup->preview($this->admin,$this->testArticle)['blocked']);$this->denied(fn()=> $this->purge(),409);$this->assertGuardsActive();
    }
    public function testSharedClosedInventoryKeepsRealLinesAdjustmentsAndReplay(): void
    {
        $w=static::getContainer()->get(\App\Inventory\InventoryWorkflow::class);$inv=$w->create($this->admin,(object)['store_id'=>$this->stores['A']],bin2hex(random_bytes(16)))['data'];$w->transition($this->admin,$inv['id'],'start',bin2hex(random_bytes(16)));
        $lines=$this->db->fetchAllAssociative('SELECT article_id,version FROM inventory_lines WHERE inventory_id=?',[$inv['id']]);
        $w->write($this->admin,$inv['id'],(object)['lines'=>array_map(fn($l)=>(object)['article_id'=>$l['article_id'],'expected_version'=>(int)$l['version'],'counted_qty'=>19],$lines)],bin2hex(random_bytes(16)));
        $w->transition($this->admin,$inv['id'],'submit',bin2hex(random_bytes(16)));$key=bin2hex(random_bytes(16));$w->transition($this->admin,$inv['id'],'validate',$key);
        $this->purge();self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM inventory_lines WHERE inventory_id=? AND article_id=?',[$inv['id'],$this->realArticle]));
        $replay=$w->transition($this->admin,$inv['id'],'validate',$key);self::assertCount(1,$replay['data']['adjustment_ids']);self::assertSame($this->realArticle,$this->db->fetchOne('SELECT article_id FROM stock_movements WHERE id=?',[$replay['data']['adjustment_ids'][0]]));$this->assertGuardsActive();
    }
    public function testSharedDailySummaryKeepsRealAlertPayloadQueueAndSchedulingTombstone(): void
    {
        $thresholds=static::getContainer()->get(\App\Stock\StockThresholds::class);
        foreach([$this->testArticle,$this->realArticle] as $id) {$thresholds->update($this->admin,$this->stores['A'],$id,(object)['low_threshold'=>20,'critical_threshold'=>0]);}
        $generator=static::getContainer()->get(\App\Notification\DailySummaryGenerator::class);self::assertSame(1,$generator->generate(new \DateTimeImmutable('2026-11-12T23:00:00+01:00')));
        $event=$this->db->fetchOne("SELECT id FROM notification_events WHERE type='daily_summary'");$this->db->insert('messenger_messages',['body'=>'DeliverNotification '.$event]);
        $this->purge();
        $payload=json_decode($this->db->fetchOne('SELECT payload FROM notification_events WHERE id=?',[$event]),true);self::assertCount(1,$payload['items']);self::assertStringEndsWith('/'.$this->realArticle,$payload['items'][0]['path']);
        self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM messenger_messages'));self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM daily_summary_items'));self::assertSame(1,(int)$this->db->fetchOne('SELECT item_count FROM daily_summary_batches'));self::assertSame(0,$generator->generate(new \DateTimeImmutable('2026-11-12T23:30:00+01:00')));
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_alerts WHERE article_id=?',[$this->testArticle]));$this->assertGuardsActive();
    }
    public function testEmptyDailySummaryAndItsQueuedEmailAreRemovedWithoutReopeningDailyBatch(): void
    {
        static::getContainer()->get(\App\Stock\StockThresholds::class)->update($this->admin,$this->stores['A'],$this->testArticle,(object)['low_threshold'=>20,'critical_threshold'=>0]);
        static::getContainer()->get(\App\Notification\DailySummaryGenerator::class)->generate(new \DateTimeImmutable('2026-11-12T23:00:00+01:00'));
        foreach($this->db->fetchFirstColumn('SELECT id FROM notification_events') as $event) {$this->db->insert('messenger_messages',['body'=>'DeliverNotification '.$event]);}
        $this->purge();
        foreach(['stock_alerts','notification_events','daily_notification_runs','daily_summary_items','messenger_messages'] as $table) {self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM '.$table));}
        self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM daily_summary_batches'));self::assertSame(0,(int)$this->db->fetchOne('SELECT item_count FROM daily_summary_batches'));$this->assertGuardsActive();
    }
    public function testClosedProposalAndCreationReplaysAreRemovedAndCodesCanBeReused(): void
    {
        $catalog=static::getContainer()->get(\App\Administration\CatalogAdministration::class);
        $a=$catalog->create($this->admin,'article',(object)['sku'=>'CACHE-TEST','name'=>'Création fictive','scan_codes'=>['CODE-CACHE-TEST']],bin2hex(random_bytes(16)))['data'];$this->testArticle=$a['id'];
        $this->db->insert('article_proposals',['operation'=>'update','article_id'=>$a['id'],'published_article_id'=>$a['id'],'base_version'=>1,'payload'=>'{"name":"Nom fictif"}','status'=>'approved','proposed_by'=>$this->admin->id,'reviewed_by'=>$this->admin->id,'reviewed_at'=>'2026-11-12 22:00:00+00']);
        $p=$this->cleanup->preview($this->admin,$a['id']);$this->cleanup->purge($this->admin,$a['id'],$p['token'],$p['at'],'CACHE-TEST',true);
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM catalog_creation_requests WHERE article_id=?',[$a['id']]));self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM article_proposals'));
        $again=$catalog->create($this->admin,'article',(object)['sku'=>'CACHE-TEST','name'=>'Nouvelle fiche fictive','scan_codes'=>['CODE-CACHE-TEST']],bin2hex(random_bytes(16)))['data'];self::assertNotSame($a['id'],$again['id']);$this->assertGuardsActive();
    }

    private function assignManager(string $store): void
    {
        $this->db->executeStatement('INSERT INTO user_stores(user_id,store_id) VALUES (?,?) ON CONFLICT DO NOTHING',[$this->actor('manager')->id,$this->stores[$store]]);
    }
    public function testManagerMayDeleteRealArticleWhenEveryStoreIsAssigned(): void
    {
        $this->assignManager('A');$this->assignManager('B');
        $this->move('transfer',['article_id'=>$this->realArticle,'source_store_id'=>$this->stores['A'],'destination_store_id'=>$this->stores['B'],'quantity'=>2]);
        $manager=$this->actor('manager');$p=$this->cleanup->preview($manager,$this->realArticle);self::assertCount(2,$p['stores']);
        $this->client->loginUser($manager);
        $crawler=$this->client->request('GET','/articles/'.$this->realArticle.'/effacer-definitivement');self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role=alert]','suppression définitive et irréversible');
        self::assertSelectorTextContains('[role=alert]','tous ses stocks et tout son historique');
        $this->client->submit($crawler->selectButton('Supprimer définitivement cet article')->form(['sku'=>'REEL','confirmed'=>'1']));self::assertResponseStatusCodeSame(303);
        self::assertFalse($this->db->fetchOne('SELECT 1 FROM articles WHERE id=?',[$this->realArticle]));self::assertSame(20,(int)$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE article_id=?',[$this->testArticle]));
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference<>0'));
    }
    public function testManagerCannotPreviewOrDeleteArticleInAnUnassignedStoreEvenWithZeroBalance(): void
    {
        $this->assignManager('A');$this->db->insert('stock_levels',['article_id'=>$this->testArticle,'store_id'=>$this->stores['B'],'quantity'=>0]);$manager=$this->actor('manager');
        self::assertNotContains($this->testArticle,array_column($this->cleanup->listing($manager),'id'));$this->denied(fn()=> $this->cleanup->preview($manager,$this->testArticle),404);
        $p=$this->cleanup->preview($this->admin,$this->testArticle);$this->denied(fn()=> $this->cleanup->purge($manager,$this->testArticle,$p['token'],$p['at'],'ESSAI',true),404);
        $this->client->loginUser($manager);$this->client->request('GET','/articles/'.$this->testArticle.'/effacer-definitivement');self::assertResponseStatusCodeSame(404);
    }
    public function testManagerAssignmentRevokedAfterPreviewPreventsDeletion(): void
    {
        $this->assignManager('A');$manager=$this->actor('manager');$p=$this->cleanup->preview($manager,$this->testArticle);
        $this->db->executeStatement('DELETE FROM user_stores WHERE user_id=?',[$manager->id]);
        $this->denied(fn()=> $this->cleanup->purge($manager,$this->testArticle,$p['token'],$p['at'],'ESSAI',true),404);
        self::assertTrue((bool)$this->db->fetchOne('SELECT 1 FROM articles WHERE id=?',[$this->testArticle]));$this->assertGuardsActive();
    }
    public function testClosedInventoryWithoutBalanceStillRequiresItsStoreAssignment(): void
    {
        $this->assignManager('A');$inventory=$this->db->fetchOne("INSERT INTO inventories(store_id,created_by) VALUES (?,?) RETURNING id",[$this->stores['B'],$this->admin->id]);
        $this->db->executeStatement("UPDATE inventories SET status='in_progress',snapshot_at=clock_timestamp() WHERE id=?",[$inventory]);
        $this->db->insert('inventory_lines',['inventory_id'=>$inventory,'article_id'=>$this->testArticle,'theoretical_qty'=>0]);
        $this->db->executeStatement("UPDATE inventories SET status='cancelled',cancelled_at=clock_timestamp() WHERE id=?",[$inventory]);
        $this->denied(fn()=> $this->cleanup->preview($this->actor('manager'),$this->testArticle),404);
        $p=$this->cleanup->preview($this->admin,$this->testArticle);self::assertCount(2,$p['stores']);
    }

    public function testNewUnassignedStoreAfterPreviewPreventsPurge(): void
    {
        $this->assignManager('A');$manager=$this->actor('manager');$p=$this->cleanup->preview($manager,$this->testArticle);
        $this->move('transfer',['article_id'=>$this->testArticle,'source_store_id'=>$this->stores['A'],'destination_store_id'=>$this->stores['B'],'quantity'=>2]);
        $this->denied(fn()=> $this->cleanup->purge($manager,$this->testArticle,$p['token'],$p['at'],'ESSAI',true),404);
        self::assertSame(2,(int)$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE article_id=? AND store_id=?',[$this->testArticle,$this->stores['B']]));$this->assertGuardsActive();
    }

}
