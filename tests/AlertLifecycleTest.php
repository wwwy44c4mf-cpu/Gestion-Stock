<?php

declare(strict_types=1);
namespace App\Tests;
use App\Security\StockUserProvider;
use App\Stock\StockMovementEngine;
use App\Stock\StockThresholds;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
final class AlertLifecycleTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private array $users = [], $stores = [];
    private string $article, $inactive, $csrf;
    protected function setUp(): void
    {
        $this->client=static::createClient();$this->client->disableReboot();$this->db=static::getContainer()->get(Connection::class);$this->db->beginTransaction();
        $schema='stk03_'.bin2hex(random_bytes(6));$this->db->executeStatement('CREATE SCHEMA '.$schema);$this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        foreach(explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) { if(trim($sql)!=='') { $this->db->executeStatement($sql); } }
        $this->db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
        foreach(['20261018140000','20261018200000','20261018223000','20261020220000','20261020230000','20261021000000','20261021010000','20261021020000','20261021030000'] as $version) { require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';$class='DoctrineMigrations\\Version'.$version;$migration=new $class($this->db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) { $this->db->executeStatement($sql->getStatement()); } }
        static::getContainer()->get('messenger.transport.notifications')->setup();
        $hash=password_hash('Entry-test-2026!',PASSWORD_ARGON2ID);
        foreach(['admin'=>1,'manager'=>2,'user'=>3,'unassigned'=>2] as $name=>$role) { $this->users[$name]=$this->db->fetchOne('INSERT INTO users (email,name,password_hash,role_id) VALUES (?,?,?,?) RETURNING id',[$name.'@example.test',$name,$hash,$role]); }
        foreach(['A','B','D'] as $code) { $this->stores[$code]=$this->db->fetchOne('INSERT INTO stores (code,name) VALUES (?,?) RETURNING id',[$code,'Magasin '.$code]); }
        $this->db->executeStatement('UPDATE stores SET active = false WHERE id = ?',[$this->stores['D']]);
        foreach(['manager','user'] as $name) { foreach(['A','D'] as $code) { $this->db->insert('user_stores',['user_id'=>$this->users[$name],'store_id'=>$this->stores[$code]]); } }
        $this->article=$this->db->fetchOne("INSERT INTO articles (sku,name) VALUES ('ENTRY','Article <script>fictif</script>') RETURNING id");$this->inactive=$this->db->fetchOne("INSERT INTO articles (sku,name,active) VALUES ('ARCHIVE','Archivé',false) RETURNING id");
    }
    protected function tearDown(): void { if($this->db->isTransactionActive()) { $this->db->rollBack(); }parent::tearDown(); }
    private function login(string $name='manager'): void
    {
        $this->client->getCookieJar()->clear();$this->client->loginUser(static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier($name.'@example.test'));
        $this->client->request('GET','/api/v1/auth/csrf');$this->csrf=json_decode($this->client->getResponse()->getContent(),true)['token'];
    }



    private int $sequence=0;
    private function actor(): \App\Security\StockUser {return static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('admin@example.test');}
    private function move(string $type,int $quantity,string $store='A',array $extra=[]): array {
        $body=['article_id'=>$this->article,'quantity'=>$quantity,'reason'=>'Test alerte isolé'];
        if($type==='transfer') {$body+=['source_store_id'=>$this->stores['A'],'destination_store_id'=>$this->stores['B']];}else {$body['store_id']=$this->stores[$store];}if($type==='consumption') {$body['consumer_id']=$this->users['user'];unset($body['reason']);}
        return static::getContainer()->get(StockMovementEngine::class)->record($this->actor(),$type,(object)array_replace($body,$extra),'alert-test-move-'.++$this->sequence)['data'];
    }
    private function thresholds(?int $low,?int $critical,string $store='A'): void {static::getContainer()->get(StockThresholds::class)->update($this->actor(),$this->stores[$store],$this->article,(object)['low_threshold'=>$low,'critical_threshold'=>$critical]);}
    private function level(string $store='A'): string {return $this->db->fetchOne("SELECT level FROM stock_alerts WHERE store_id=? AND article_id=? AND status='active'",[$this->stores[$store],$this->article])?:'normal';}
    private function transitions(): int {return $this->db->fetchOne('SELECT count(*) FROM stock_alert_transitions');}
    public function testExactThresholdsCriticalPriorityAndEveryDirection(): void {
        $this->move('entry',10);$this->thresholds(5,2);self::assertSame(0,$this->transitions());
        $this->move('consumption',5);self::assertSame('low',$this->level());$this->move('consumption',1);self::assertSame(1,$this->transitions());
        $this->move('consumption',2);self::assertSame('critical',$this->level());$this->move('entry',2);self::assertSame('low',$this->level());$this->move('entry',6);self::assertSame('normal',$this->level());
        self::assertSame(['normal>low','low>critical','critical>low','low>normal'],$this->db->fetchFirstColumn("SELECT from_level||'>'||to_level FROM stock_alert_transitions ORDER BY created_at"));self::assertSame(3,$this->db->fetchOne("SELECT count(*) FROM stock_alerts WHERE status='resolved' AND closed_at>=opened_at"));self::assertSame(6,$this->db->fetchOne('SELECT count(*) FROM notification_events'));
    }
    public function testZeroNullAndSameLevelThresholdChanges(): void {
        $this->thresholds(0,0);self::assertSame('critical',$this->level());self::assertSame(1,$this->transitions());$this->thresholds(0,0);self::assertSame(1,$this->transitions());
        $this->thresholds(5,null);self::assertSame('low',$this->level());$alert=$this->db->fetchOne("SELECT id FROM stock_alerts WHERE status='active'");$this->thresholds(6,null);self::assertSame($alert,$this->db->fetchOne("SELECT id FROM stock_alerts WHERE status='active'"));self::assertSame('6',$this->db->fetchOne("SELECT threshold::text FROM stock_alerts WHERE status='active'"));self::assertSame(2,$this->transitions());
        $this->thresholds(null,null);self::assertSame('normal',$this->level());self::assertSame(3,$this->transitions());self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM stock_movements'));self::assertSame(0,$this->db->fetchOne('SELECT version FROM stock_levels'));
    }
    public function testReadsAndIdenticalWritesDoNotCreateEvents(): void {
        $this->thresholds(5,0);$before=$this->transitions();$count=$this->db->fetchOne('SELECT count(*) FROM notification_events');$this->login();
        for($i=0;$i<3;$i++) {$this->client->request('GET','/api/v1/stores/'.$this->stores['A'].'/stocks');self::assertResponseIsSuccessful();$this->client->request('GET','/stocks/'.$this->stores['A'].'/'.$this->article);self::assertResponseIsSuccessful();}
        $this->thresholds(5,0);self::assertSame($before,$this->transitions());self::assertSame($count,$this->db->fetchOne('SELECT count(*) FROM notification_events'));
    }
    public function testTransferCorrectionAndReturnUseFinalBalances(): void {
        $this->move('entry',10);$this->move('entry',10,'B');$this->thresholds(5,2);$this->thresholds(5,2,'B');
        $transfer=$this->move('transfer',6);self::assertSame('low',$this->level());self::assertSame('normal',$this->level('B'));
        static::getContainer()->get(\App\Stock\StockCorrectionEngine::class)->correct($this->actor(),$transfer['id'],(object)['quantity'=>4,'reason'=>'Quantité correcte'],'alert-transfer-correct');self::assertSame('normal',$this->level());self::assertSame(2,$this->transitions());
        $consumption=$this->move('consumption',5);self::assertSame('critical',$this->level());static::getContainer()->get(\App\Stock\StockReturnEngine::class)->record($this->actor(),(object)['consumption_id'=>$consumption['id'],'quantity'=>4],'alert-return-test');self::assertSame('low',$this->level());
        $this->move('adjustment',1);self::assertSame('normal',$this->level());self::assertSame(5,$this->transitions());
    }
    public function testNotificationRecipientsAndPayloadAreScopedAndRenderable(): void {
        $this->db->insert('user_stores',['user_id'=>$this->users['unassigned'],'store_id'=>$this->stores['B']]);$this->db->executeStatement('UPDATE articles SET name=? WHERE id=?',[str_repeat('é',160),$this->article]);$this->thresholds(5,0);
        $events=$this->db->fetchAllAssociative('SELECT * FROM notification_events');self::assertCount(2,$events);self::assertEqualsCanonicalizing([$this->users['admin'],$this->users['manager']],array_column($events,'recipient_id'));
        foreach($events as $event) {$mail=static::getContainer()->get(\App\Notification\NotificationRenderer::class)->render($event,'fake@example.test');self::assertStringContainsString('Stock critique',$mail->getSubject());self::assertSame($this->stores['A'],$event['store_id']);self::assertSame('pending',$event['status']);}
        $this->thresholds(5,0,'B');$ids=$this->db->fetchFirstColumn('SELECT recipient_id FROM notification_events WHERE store_id=?',[$this->stores['B']]);self::assertEqualsCanonicalizing([$this->users['admin'],$this->users['unassigned']],$ids);
    }
    public function testInjectedNotificationFailureRollsBackMovementAndAlertAtomically(): void {
        $this->move('entry',10);$this->thresholds(5,2);$this->db->executeStatement("CREATE FUNCTION fail_alert_notification() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Injected alert notification failure'; END $$");$this->db->executeStatement('CREATE TRIGGER alert_notification_failure BEFORE INSERT ON notification_events FOR EACH ROW EXECUTE FUNCTION fail_alert_notification()');
        try {$this->move('consumption',8);self::fail('Panne attendue');}catch(\Doctrine\DBAL\Exception $e) {self::assertStringContainsString('Injected alert notification failure',$e->getMessage());}
        self::assertSame('10',$this->db->fetchOne('SELECT quantity::text FROM stock_levels'));self::assertSame('normal',$this->level());self::assertSame(0,$this->transitions());self::assertSame(1,$this->db->fetchOne('SELECT count(*) FROM stock_movements'));self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM notification_events'));
    }
    public function testResolvedAlertsAndTransitionsAreImmutable(): void {
        $this->thresholds(0,0);$this->move('entry',1);
        foreach(['UPDATE stock_alert_transitions SET quantity=9','DELETE FROM stock_alert_transitions',"UPDATE stock_alerts SET threshold=8 WHERE status='resolved'",'DELETE FROM stock_alerts'] as $sql) {try {$this->db->transactional(fn()=> $this->db->executeStatement($sql));self::fail('Historique altéré');}catch(\Doctrine\DBAL\Exception) {self::assertTrue(true);}}
        self::assertSame(2,$this->transitions());self::assertSame(1,$this->db->fetchOne("SELECT count(*) FROM stock_alerts WHERE status='resolved'"));
    }
    public function testInventoryAdjustmentCrossesThresholdOnlyAtValidation(): void {
        $this->move('entry',10);$this->thresholds(5,2);$workflow=static::getContainer()->get(\App\Inventory\InventoryWorkflow::class);$actor=$this->actor();
        $id=$workflow->create($actor,(object)['store_id'=>$this->stores['A']],'alert-inventory-create')['data']['id'];$workflow->transition($actor,$id,'start','alert-inventory-start');$workflow->write($actor,$id,(object)['lines'=>[(object)['article_id'=>$this->article,'counted_qty'=>2,'expected_version'=>0]]],'alert-inventory-count');self::assertSame(0,$this->transitions());$workflow->transition($actor,$id,'submit','alert-inventory-submit');$workflow->transition($actor,$id,'validate','alert-inventory-validate');self::assertSame('critical',$this->level());self::assertSame(1,$this->transitions());self::assertSame('2',$this->db->fetchOne('SELECT quantity::text FROM stock_levels'));
        $workflow->transition($actor,$id,'validate','alert-inventory-validate');self::assertSame(1,$this->transitions());
    }
    public function testBackfillPreservesQuantitiesVersionsAndLedger(): void {
        $class='DoctrineMigrations\\Version20261021020000';$migration=new $class($this->db,new \Psr\Log\NullLogger());$migration->down(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) {$this->db->executeStatement($sql->getStatement());}
        $this->move('entry',2);$this->thresholds(5,1);$before=$this->db->fetchAssociative('SELECT quantity,version FROM stock_levels');$migration=new $class($this->db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) {$this->db->executeStatement($sql->getStatement());}
        self::assertSame($before,$this->db->fetchAssociative('SELECT quantity,version FROM stock_levels'));self::assertSame('low',$this->level());self::assertSame('initialization',$this->db->fetchOne('SELECT cause FROM stock_alert_transitions'));self::assertSame(1,$this->db->fetchOne('SELECT count(*) FROM stock_movements'));self::assertSame(2,$this->db->fetchOne('SELECT count(*) FROM notification_events'));
    }
    public function testSmtpFailureKeepsValidatedMovementAndDurableNotification(): void {
        $this->move('entry',10);$this->thresholds(5,2);$this->move('consumption',8);$event=$this->db->fetchOne('SELECT id FROM notification_events ORDER BY id LIMIT 1');$this->db->executeStatement("UPDATE notification_events SET status='queued' WHERE id=?",[$event]);
        $delivery=new class implements \App\Notification\MailDelivery {public function send(\Symfony\Component\Mime\Email $email): void {throw new \RuntimeException('SMTP indisponible');}};
        $handler=new \App\Notification\NotificationDeliveryHandler($this->db,$delivery,static::getContainer()->get(\App\Notification\NotificationRenderer::class),new \Psr\Log\NullLogger(),true,'test');
        try {$handler(new \App\Message\DeliverNotification($event));self::fail('SMTP indisponible attendu');}catch(\RuntimeException $e) {self::assertSame('smtp_delivery_failed',$e->getMessage());}
        self::assertSame('2',$this->db->fetchOne('SELECT quantity::text FROM stock_levels'));self::assertSame(2,$this->db->fetchOne('SELECT count(*) FROM stock_movements'));self::assertSame('critical',$this->level());self::assertSame('failed',$this->db->fetchOne('SELECT status FROM notification_events WHERE id=?',[$event]));
    }
    public function testInactiveAndDevelopmentRecipientsDoNotPreventTransitions(): void {
        $this->move('entry',10);$this->thresholds(5,2);$this->db->executeStatement('UPDATE users SET active=false WHERE role_id IN (1,2)');$this->db->executeStatement("INSERT INTO users(email,name,password_hash,role_id,development_only) VALUES('dev@example.test','Dev','fake',1,true)");$actor=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('user@example.test');static::getContainer()->get(StockMovementEngine::class)->record($actor,'consumption',(object)['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>8],'alert-no-recipients');self::assertSame('critical',$this->level());self::assertSame(1,$this->transitions());self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM notification_events'));
    }

    public function testConsumptionCorrectionAndCancellationAvoidTransientAlerts(): void {
        $this->move('entry',10);$this->thresholds(5,2);$origin=$this->move('consumption',8);$engine=static::getContainer()->get(\App\Stock\StockCorrectionEngine::class);$result=$engine->correct($this->actor(),$origin['id'],(object)['quantity'=>5,'reason'=>'Correction isolée'],'alert-consumption-correct');self::assertSame('low',$this->level());self::assertSame(2,$this->transitions());
        $engine->correct($this->actor(),$result['data']['replacement']['id'],(object)['reason'=>'Annulation isolée'],'alert-consumption-reverse',true);self::assertSame('normal',$this->level());self::assertSame(3,$this->transitions());self::assertSame('10',$this->db->fetchOne('SELECT quantity::text FROM stock_levels'));self::assertSame(4,$this->db->fetchOne('SELECT count(*) FROM notification_events'));
    }

}
