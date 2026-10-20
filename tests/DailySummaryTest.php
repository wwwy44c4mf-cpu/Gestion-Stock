<?php

declare(strict_types=1);
namespace App\Tests;
use App\Security\StockUserProvider;
use App\Alert\AlertRecipients;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
final class DailySummaryTest extends WebTestCase
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
        foreach(['20261018140000','20261018200000','20261018223000','20261020220000','20261020230000','20261021000000','20261021010000','20261021020000','20261021030000','20261021040000','20261021050000'] as $version) { require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';$class='DoctrineMigrations\\Version'.$version;$migration=new $class($this->db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) { $this->db->executeStatement($sql->getStatement()); } }
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




    private function generator(): \App\Notification\DailySummaryGenerator {return static::getContainer()->get(\App\Notification\DailySummaryGenerator::class);}
    private function at(string $time='08:00:00',string $date='2026-10-21'): \DateTimeImmutable {return new \DateTimeImmutable($date.' '.$time,new \DateTimeZone('Europe/Paris'));}
    private function seed(): void {
        foreach(['A','B','D'] as $code) {$this->db->insert('stock_levels',['article_id'=>$this->article,'store_id'=>$this->stores[$code],'quantity'=>2,'low_threshold'=>5,'critical_threshold'=>1]);}
    }
    private function summaries(): array {return $this->db->fetchAllAssociative("SELECT * FROM notification_events WHERE type='daily_summary' ORDER BY recipient_id");}
    private function payload(array $event): array {return json_decode($event['payload'],true,flags:JSON_THROW_ON_ERROR);}
    private function audience(array $event,?\DateTimeImmutable $now=null): ?array {return (new \App\Notification\DailySummaryAudience($this->db))->payload($event,$now??$this->at('09:00:00'));}
    public function testOneSummaryPerPersonAcrossStoresAndEmptyScopesExcluded(): void {
        $this->seed();$before=$this->db->fetchOne("SELECT count(*) FROM notification_events WHERE type<>'daily_summary'");self::assertSame(2,$this->generator()->generate($this->at()));$events=$this->summaries();self::assertCount(2,$events);
        foreach($events as $event) {$items=$this->payload($event)['items'];self::assertNull($event['store_id']);self::assertCount($event['recipient_id']===$this->users['admin']?2:1,$items);foreach($items as $item) {self::assertSame('low',$item['level']);self::assertSame(2,$item['quantity']);self::assertSame(5,$item['threshold']);self::assertStringNotContainsString($this->stores['D'],$item['path']);}}
        self::assertSame(3,$this->db->fetchOne('SELECT count(*) FROM daily_summary_items'));self::assertSame($before,$this->db->fetchOne("SELECT count(*) FROM notification_events WHERE type<>'daily_summary'"));
        self::assertSame(0,$this->generator()->generate($this->at('20:30:00')));self::assertSame(2,$this->db->fetchOne('SELECT count(*) FROM daily_notification_runs'));self::assertSame(1,$this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE action='notifications.daily_summary'"));
    }
    public function testNoGenerationBeforeEightCatchUpAndNoPreviousDays(): void {
        $this->seed();self::assertSame(0,$this->generator()->generate($this->at('07:59:59')));self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM daily_summary_batches'));
        self::assertSame(2,$this->generator()->generate($this->at('15:42:00')));self::assertSame(['2026-10-21'],$this->db->fetchFirstColumn('SELECT local_date::text FROM daily_summary_batches'));
        self::assertSame(0,$this->generator()->generate($this->at('23:59:59')));self::assertSame(0,$this->generator()->generate($this->at('07:59:59','2026-10-22')));self::assertSame(2,$this->generator()->generate($this->at('08:00:00','2026-10-22')));
        self::assertSame(['2026-10-21','2026-10-22'],$this->db->fetchFirstColumn('SELECT local_date::text FROM daily_summary_batches ORDER BY local_date'));
    }
    public function testEmptyMorningIsRecordedWithoutLateUnscheduledSummary(): void {
        self::assertSame(0,$this->generator()->generate($this->at()));self::assertSame(1,$this->db->fetchOne('SELECT count(*) FROM daily_summary_batches'));self::assertSame(0,$this->db->fetchOne('SELECT recipient_count FROM daily_summary_batches'));
        $this->seed();self::assertSame(0,$this->generator()->generate($this->at('11:00:00')));self::assertCount(0,$this->summaries());self::assertGreaterThan(0,$this->db->fetchOne("SELECT count(*) FROM notification_events WHERE type='stock_low'"));self::assertSame(2,$this->generator()->generate($this->at('08:00:00','2026-10-22')));
    }
    public function testPreferencesAndInactiveDevelopmentUsersAreRespected(): void {
        $this->seed();$this->db->insert('alert_recipient_configurations',['store_id'=>$this->stores['A'],'use_defaults'=>'false']);$this->db->insert('alert_recipients',['store_id'=>$this->stores['A'],'user_id'=>$this->users['manager'],'alert_type'=>'daily_summary']);
        $this->db->executeStatement('UPDATE users SET development_only=true WHERE id=?',[$this->users['admin']]);self::assertSame(1,$this->generator()->generate($this->at()));self::assertSame($this->users['manager'],$this->summaries()[0]['recipient_id']);
        $this->db->executeStatement('UPDATE users SET active=false WHERE id=?',[$this->users['manager']]);self::assertSame(0,$this->generator()->generate($this->at('08:00:00','2026-10-22')));
    }
    public function testResolvedAlertsExcludedAndCurrentQuantitiesUsedAtDelivery(): void {
        $this->seed();$this->generator()->generate($this->at());$event=array_values(array_filter($this->summaries(),fn($e)=>$e['recipient_id']===$this->users['admin']))[0];
        $this->db->executeStatement('UPDATE stock_levels SET quantity=10 WHERE store_id=?',[$this->stores['B']]);$this->db->executeStatement('UPDATE stock_levels SET quantity=3 WHERE store_id=?',[$this->stores['A']]);$payload=$this->audience($event);self::assertCount(1,$payload['items']);self::assertSame(3,$payload['items'][0]['quantity']);self::assertStringContainsString($this->stores['A'],$payload['items'][0]['path']);self::assertCount(2,$this->payload($event)['items']);
        $this->db->executeStatement('UPDATE stock_levels SET quantity=10');self::assertNull($this->audience($event));
        $this->db->executeStatement('UPDATE stock_levels SET quantity=0 WHERE store_id=?',[$this->stores['A']]);self::assertNull($this->audience($event));
    }
    public function testDeliveryFiltersRemovedAccessPreferencesStoresAndEmail(): void {
        $this->seed();$this->generator()->generate($this->at());$event=array_values(array_filter($this->summaries(),fn($e)=>$e['recipient_id']===$this->users['manager']))[0];self::assertCount(1,$this->audience($event)['items']);
        foreach(['membership','role','preference','store','email','development','inactive'] as $change) {
            $this->db->beginTransaction();$id=$this->users['manager'];match($change) {
                'membership'=>$this->db->executeStatement('DELETE FROM user_stores WHERE user_id=?',[$id]),'role'=>$this->db->executeStatement('UPDATE users SET role_id=3 WHERE id=?',[$id]),
                'preference'=>$this->db->insert('alert_recipient_configurations',['store_id'=>$this->stores['A'],'use_defaults'=>'false']),'store'=>$this->db->executeStatement('UPDATE stores SET active=false WHERE id=?',[$this->stores['A']]),
                'email'=>$this->db->executeStatement('UPDATE users SET email=? WHERE id=?',['other@example.test',$id]),'development'=>$this->db->executeStatement('UPDATE users SET development_only=true WHERE id=?',[$id]),'inactive'=>$this->db->executeStatement('UPDATE users SET active=false WHERE id=?',[$id])};
            self::assertNull($this->audience($event));$this->db->rollBack();
        }
        self::assertNull($this->audience($event,$this->at('08:00:00','2026-10-22')));
    }
    public function testAtomicRollbackOnAuditFailureAndSafeRetry(): void {
        $this->seed();$before=$this->db->fetchOne('SELECT count(*) FROM notification_events');$this->db->executeStatement("CREATE FUNCTION fail_daily_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.action='notifications.daily_summary' THEN RAISE EXCEPTION 'Injected daily audit failure'; END IF; RETURN NEW; END $$");$this->db->executeStatement('CREATE TRIGGER daily_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION fail_daily_audit()');
        try {$this->generator()->generate($this->at());self::fail();}catch(\Doctrine\DBAL\Exception $e) {self::assertStringContainsString('Injected daily audit failure',$e->getMessage());}
        foreach(['daily_summary_batches','daily_notification_runs','daily_summary_items'] as $table) {self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM '.$table));}self::assertSame($before,$this->db->fetchOne('SELECT count(*) FROM notification_events'));$this->db->executeStatement('DROP TRIGGER daily_audit_failure ON audit_logs');self::assertSame(2,$this->generator()->generate($this->at()));
    }
    public function testScheduleAtEightParisAcrossDstAndCatchUpPolling(): void {
        $schedule=(new \App\Scheduler\InfrastructureSchedule())->getSchedule();$messages=$schedule->getRecurringMessages();self::assertCount(4,$messages);$daily=$messages[0]->getTrigger();$poll=$messages[1]->getTrigger();
        foreach([['2026-03-28T08:00:00Z','2026-03-29T06:00:00+00:00'],['2026-10-24T08:00:00Z','2026-10-25T07:00:00+00:00']] as [$from,$expected]) {self::assertSame($expected,$daily->getNextRunDate(new \DateTimeImmutable($from))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_RFC3339));}
        self::assertSame('2026-10-21T13:31:00+00:00',$poll->getNextRunDate(new \DateTimeImmutable('2026-10-21T13:30:12Z'))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_RFC3339));
        self::assertNotSame($messages[0]->getId(),$messages[1]->getId());
    }
    public function testLocalDateUsesParisNotUtcOrServerTimezone(): void {
        $this->seed();self::assertSame(0,$this->generator()->generate(new \DateTimeImmutable('2026-07-01T05:59:59Z')));self::assertSame(2,$this->generator()->generate(new \DateTimeImmutable('2026-07-01T06:00:00Z')));
        self::assertSame(0,$this->generator()->generate(new \DateTimeImmutable('2026-07-01T22:30:00Z')));self::assertSame(['2026-07-01'],$this->db->fetchFirstColumn('SELECT local_date::text FROM daily_summary_batches'));
        self::assertSame(2,$this->generator()->generate(new \DateTimeImmutable('2026-12-01T07:00:00Z')));
    }
    public function testRealDeliveryPathFiltersResolvedItemsAndEscapesTemplates(): void {
        $this->seed();$today=new \DateTimeImmutable('today 08:00:00',new \DateTimeZone('Europe/Paris'));$this->generator()->generate($today);
        $event=array_values(array_filter($this->summaries(),fn($e)=>$e['recipient_id']===$this->users['admin']))[0];$this->db->executeStatement("UPDATE notification_events SET status='queued' WHERE id=?",[$event['id']]);$this->db->executeStatement('UPDATE stock_levels SET quantity=10 WHERE store_id=?',[$this->stores['B']]);
        $fake=new class implements \App\Notification\MailDelivery {public int $sent=0;public ?\Symfony\Component\Mime\Email $email=null;public function send(\Symfony\Component\Mime\Email $email): void {$this->sent++;$this->email=$email;}};
        $handler=new \App\Notification\NotificationDeliveryHandler($this->db,$fake,static::getContainer()->get(\App\Notification\NotificationRenderer::class),new \Psr\Log\NullLogger(),true,'test');$handler(new \App\Message\DeliverNotification($event['id']));$handler(new \App\Message\DeliverNotification($event['id']));self::assertSame(1,$fake->sent);self::assertStringNotContainsString('Magasin B',$fake->email->getTextBody());self::assertStringContainsString('Stock bas',$fake->email->getTextBody());self::assertStringNotContainsString('<script>',$fake->email->getHtmlBody());self::assertStringContainsString('&lt;script&gt;',$fake->email->getHtmlBody());
    }
    public function testNoSilentTruncationAboveOneThousandAlerts(): void {
        $this->db->executeStatement("INSERT INTO articles(sku,name) SELECT 'BIG-'||n,'Article '||n FROM generate_series(1,1001) n");
        $this->db->executeStatement("INSERT INTO stock_levels(article_id,store_id,quantity,low_threshold) SELECT id,?::uuid,0,5 FROM articles WHERE sku LIKE 'BIG-%'",[$this->stores['B']]);
        self::assertSame(1,$this->generator()->generate($this->at()));$events=$this->summaries();self::assertCount(1,$events);self::assertCount(1001,$this->payload($events[0])['items']);self::assertSame(1001,$this->db->fetchOne('SELECT count(*) FROM daily_summary_items'));
    }
    public function testSummaryWithNoRemainingItemsIsNotSentAndOldDaysAreNotSent(): void {
        $this->seed();$today=new \DateTimeImmutable('today 08:00:00',new \DateTimeZone('Europe/Paris'));$this->generator()->generate($today->modify('-1 day'));$this->generator()->generate($today);
        $this->db->executeStatement("UPDATE notification_events SET status='queued' WHERE type='daily_summary'");$fake=new class implements \App\Notification\MailDelivery {public int $sent=0;public function send(\Symfony\Component\Mime\Email $email): void {$this->sent++;}};
        $handler=new \App\Notification\NotificationDeliveryHandler($this->db,$fake,static::getContainer()->get(\App\Notification\NotificationRenderer::class),new \Psr\Log\NullLogger(),true,'test');
        foreach($this->db->fetchAllAssociative("SELECT * FROM notification_events WHERE type='daily_summary' ORDER BY event_key") as $event) {if(str_contains($event['event_key'],$today->format('Y-m-d'))) {$this->db->executeStatement('UPDATE stock_levels SET quantity=10');}try {$handler(new \App\Message\DeliverNotification($event['id']));self::fail();}catch(\Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException $e) {self::assertSame('recipient_unavailable',$e->getMessage());}}
        self::assertSame(0,$fake->sent);self::assertSame(4,$this->db->fetchOne("SELECT count(*) FROM notification_events WHERE type='daily_summary' AND status='failed'"));
    }
    public function testBatchAndRunCannotBeRewrittenAndRollbackProtectsHistory(): void {
        $this->seed();$this->generator()->generate($this->at());
        foreach(['DELETE FROM daily_summary_batches','DELETE FROM daily_notification_runs','DELETE FROM daily_summary_items'] as $sql) {try {$this->db->transactional(fn()=>$this->db->executeStatement($sql));self::fail();}catch(\Doctrine\DBAL\Exception $e) {self::assertStringContainsString('immuable',$e->getMessage());}}
        require_once dirname(__DIR__).'/migrations/Version20261021050000.php';$migration=new \DoctrineMigrations\Version20261021050000($this->db,new \Psr\Log\NullLogger());$migration->down(new \Doctrine\DBAL\Schema\Schema());
        try {$this->db->transactional(function() use($migration): void {foreach($migration->getSql() as $sql) {$this->db->executeStatement($sql->getStatement());}});self::fail();}catch(\Doctrine\DBAL\Exception $e) {self::assertStringContainsString('récapitulatifs',$e->getMessage());}
        self::assertSame(1,$this->db->fetchOne('SELECT count(*) FROM daily_summary_batches'));
    }

    public function testPreferenceRemovalPrunesOnlyTheRevokedStore(): void {
        $this->seed();$this->generator()->generate($this->at());$event=array_values(array_filter($this->summaries(),fn($e)=>$e['recipient_id']===$this->users['admin']))[0];
        $this->db->insert('alert_recipient_configurations',['store_id'=>$this->stores['A'],'use_defaults'=>'false']);$payload=$this->audience($event);self::assertCount(1,$payload['items']);self::assertStringContainsString($this->stores['B'],$payload['items'][0]['path']);self::assertStringNotContainsString($this->stores['A'],json_encode($payload));
    }

}
