<?php

declare(strict_types=1);
namespace App\Tests;
use App\Security\StockUserProvider;
use App\Notification\NotificationOutbox;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationDeliveryHandler;
use App\Notification\NotificationRenderer;
use App\Notification\NotificationPayload;
use App\Notification\MailDelivery;
use App\Message\DeliverNotification;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Email;
use Psr\Log\NullLogger;
final class NotificationTest extends WebTestCase
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


    private function payload(): array {return ['article'=>'Article <script>','store'=>'Magasin A','quantity'=>0,'threshold'=>5,'occurred_at'=>'2026-10-20T07:30:00Z','path'=>'/stocks/'.$this->stores['A'].'/'.$this->article];}
    private function enqueue(string $key='event-test',string $type='stock_low',?array $payload=null): string {return static::getContainer()->get(NotificationOutbox::class)->enqueue($key,$type,$this->users['manager'],$payload??$this->payload(),$this->stores['A']);}
    private function dispatch(bool $enabled=true): int {return (new NotificationDispatcher($this->db,static::getContainer()->get(MessageBusInterface::class),$enabled))->dispatch();}
    private function handler(MailDelivery $delivery,bool $enabled=true): NotificationDeliveryHandler {return new NotificationDeliveryHandler($this->db,$delivery,static::getContainer()->get(NotificationRenderer::class),new NullLogger(),$enabled,'test');}
    private function fake(bool $fail=false): MailDelivery {return new class($fail) implements MailDelivery {public int $sent=0;public ?Email $email=null;public function __construct(private bool $fail) {} public function send(Email $email): void {$this->sent++;$this->email=$email;if($this->fail) {throw new \RuntimeException('smtp://private-user:private-password@example.test secret diagnostic');}}};}
    private function event(string $id): array {return $this->db->fetchAssociative('SELECT * FROM notification_events WHERE id=?',[$id]);}
    public function testBusinessRollbackAndIdempotency(): void {
        $this->db->beginTransaction();$id=$this->enqueue();self::assertSame($id,$this->enqueue());$this->db->rollBack();self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM notification_events'));
        $id=$this->enqueue();try {$this->enqueue(payload:array_replace($this->payload(),['quantity'=>2]));self::fail('Conflit attendu');}catch(\LogicException $e) {self::assertSame('notification_idempotency_conflict',$e->getMessage());}
        self::assertSame(1,$this->db->fetchOne('SELECT count(*) FROM notification_events'));self::assertSame('pending',$this->event($id)['status']);
    }
    public function testQueueAndStateAreAtomicAndContainOnlyOpaqueId(): void {
        $id=$this->enqueue();self::assertSame(0,$this->dispatch(false));self::assertSame('pending',$this->event($id)['status']);
        $this->db->beginTransaction();self::assertSame(1,$this->dispatch());self::assertSame('queued',$this->event($id)['status']);$this->db->rollBack();self::assertSame('pending',$this->event($id)['status']);self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM messenger_messages'));
        self::assertSame(1,$this->dispatch());self::assertSame(0,$this->dispatch());$raw=$this->db->fetchOne('SELECT body FROM messenger_messages');self::assertStringContainsString($id,$raw);self::assertStringNotContainsString('example.test',$raw);self::assertStringNotContainsString('Article',$raw);
    }
    public function testDeliveryTemplatesStableMessageIdAndReplay(): void {
        $id=$this->enqueue();$this->dispatch();$fake=$this->fake();$handler=$this->handler($fake);$handler(new DeliverNotification($id));$handler(new DeliverNotification($id));self::assertSame(1,$fake->sent);self::assertSame('sent',$this->event($id)['status']);self::assertNotNull($this->event($id)['sent_at']);self::assertSame(1,$this->db->fetchOne('SELECT count(*) FROM notification_delivery_attempts'));
        self::assertStringContainsString('&lt;script&gt;',$fake->email->getHtmlBody());self::assertStringNotContainsString('<script>',$fake->email->getHtmlBody());self::assertStringContainsString('0 unités',$fake->email->getTextBody());self::assertStringContainsString('09:30',$fake->email->getTextBody());self::assertStringContainsString($id,$fake->email->getHeaders()->get('Message-ID')->getBodyAsString());
    }
    public function testSmtpFailureDoesNotRollBackMovementAndRetriesAreBoundedAndSanitized(): void {
        $actor=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('admin@example.test');$this->db->beginTransaction();static::getContainer()->get(\App\Stock\StockMovementEngine::class)->record($actor,'entry',(object)['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>7,'reason'=>'Test SMTP isolé'],'mail-entry-atomic-test');$id=$this->enqueue();$this->db->commit();$this->dispatch();$fake=$this->fake(true);$handler=$this->handler($fake);
        for($i=1;$i<=4;$i++) {try {$handler(new DeliverNotification($id));self::fail('Échec attendu');}catch(\RuntimeException $e) {self::assertSame('smtp_delivery_failed',$e->getMessage());self::assertNull($e->getPrevious());self::assertSame($i===4,$e instanceof \Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException);}self::assertSame($i,$this->event($id)['attempts']);}
        self::assertSame('7',$this->db->fetchOne('SELECT quantity::text FROM stock_levels'));self::assertSame(1,$this->db->fetchOne('SELECT count(*) FROM stock_movements'));self::assertSame(4,$this->db->fetchOne('SELECT count(*) FROM notification_delivery_attempts'));self::assertSame('failed',$this->event($id)['status']);self::assertSame(0,$this->dispatch());
        try {$handler(new DeliverNotification($id));}catch(\RuntimeException $e) {self::assertSame('notification_attempts_exhausted',$e->getMessage());}self::assertSame(4,$fake->sent);
    }
    public function testRecipientRightsAreCheckedAtDeliveryAndDisabledDeliveryPreservesPending(): void {
        $id=$this->enqueue();$this->dispatch();$fake=$this->fake();$this->handler($fake,false)(new DeliverNotification($id));self::assertSame('pending',$this->event($id)['status']);self::assertSame(0,$this->event($id)['attempts']);$this->dispatch();
        $this->db->executeStatement('DELETE FROM user_stores WHERE user_id=?',[$this->users['manager']]);try {$this->handler($fake)(new DeliverNotification($id));self::fail('Accès retiré');}catch(\RuntimeException $e) {self::assertSame('recipient_unavailable',$e->getMessage());}self::assertSame(0,$fake->sent);
    }
    public function testAllTemplatesAndInvalidPayloads(): void {
        $renderer=static::getContainer()->get(NotificationRenderer::class);foreach(['stock_low'=>$this->payload(),'stock_critical'=>$this->payload(),'information'=>['subject'=>'Information','body'=>"Ligne 1\nLigne 2",'path'=>'/'],'password_reset'=>['path'=>'/mot-de-passe?token=opaque'],'daily_summary'=>['items'=>[$this->payload()]]] as $type=>$payload) {$id=$this->enqueue('template-'.$type,$type,$payload);$email=$renderer->render($this->event($id),'manager@example.test');self::assertNotEmpty($email->getHtmlBody());self::assertNotEmpty($email->getTextBody());self::assertSame(1,count($email->getTo()));}
        foreach([['quantity'=>1.5],['quantity'=>-1],['path'=>'//evil.test'],['path'=>"/\\evil"],['extra'=>'x'],['article'=>"Injection\r\nBcc: x@y.test"]] as $extra) {try {static::getContainer()->get(NotificationPayload::class)->validate('stock_low',array_replace($this->payload(),$extra));self::fail('Charge invalide acceptée');}catch(\InvalidArgumentException) {self::assertTrue(true);}}
    }
    public function testRealMessengerWorkerRetriesAndMovesToFailureQueue(): void {
        $id=$this->enqueue();$this->dispatch();$fake=$this->fake(true);static::getContainer()->set(NotificationDeliveryHandler::class,$this->handler($fake));
        $application=new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel);$command=$application->find('messenger:consume');
        for($attempt=1;$attempt<=4;$attempt++) {
            $this->db->executeStatement("UPDATE messenger_messages SET available_at=clock_timestamp() - interval '1 second' WHERE queue_name='soc01_test_notifications'");
            $tester=new \Symfony\Component\Console\Tester\CommandTester($command);self::assertSame(0,$tester->execute(['receivers'=>['notifications'],'--limit'=>1,'--time-limit'=>5,'--sleep'=>0.01],['interactive'=>false]));
            self::assertSame($attempt,$this->event($id)['attempts'],$tester->getDisplay());self::assertStringNotContainsString('private-password',$tester->getDisplay());
        }
        self::assertSame(4,$fake->sent);self::assertSame(0,$this->db->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name='soc01_test_notifications'"));self::assertSame(1,$this->db->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name='soc01_test_failed'"));
        $failed=$this->db->fetchAssociative("SELECT body,headers FROM messenger_messages WHERE queue_name='soc01_test_failed'");self::assertStringNotContainsString('private-password',json_encode($failed));self::assertStringContainsString('smtp_delivery_failed',$failed['body']);
    }

    public function testOperatorRetryKeepsHistoryAndDoesNotReplaySentEvents(): void {
        $id=$this->enqueue();$this->dispatch();$fake=$this->fake(true);try {$this->handler($fake)(new DeliverNotification($id));}catch(\RuntimeException) {}
        $command=new \App\Command\NotificationRetryCommand($this->db);$tester=new \Symfony\Component\Console\Tester\CommandTester($command);self::assertSame(0,$tester->execute(['id'=>$id]));self::assertSame('pending',$this->event($id)['status']);self::assertSame(0,$this->event($id)['attempts']);self::assertSame(1,$this->db->fetchOne('SELECT count(*) FROM notification_delivery_attempts'));self::assertSame(1,$this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE action='notification.retry'"));
        $this->dispatch();$this->handler($this->fake())(new DeliverNotification($id));self::assertSame(1,$tester->execute(['id'=>$id]));self::assertSame('sent',$this->event($id)['status']);self::assertSame(2,$this->db->fetchOne('SELECT count(*) FROM notification_delivery_attempts'));self::assertSame(2,$tester->execute(['id'=>'invalid']));
    }

}
