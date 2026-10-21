<?php

declare(strict_types=1);
namespace App\Tests;
use App\Operations\OperationalStatus;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class OperationalStatusTest extends KernelTestCase
{
    private Connection $db;
    protected function setUp(): void
    {
        self::bootKernel();$this->db=static::getContainer()->get(Connection::class);$this->db->beginTransaction();$schema='ops03_'.bin2hex(random_bytes(6));$this->db->executeStatement('CREATE SCHEMA '.$schema);$this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        $factory=DependencyFactory::fromConnection(new ConfigurationArray(['migrations_paths'=>['DoctrineMigrations'=>dirname(__DIR__).'/migrations'],'all_or_nothing'=>true]),new ExistingConnection($this->db));$command=new MigrateCommand($factory);$command->setName('doctrine:migrations:migrate');$out=new BufferedOutput();$input=new ArrayInput([]);$input->setInteractive(false);self::assertSame(0,$command->run($input,$out),$out->fetch());
        static::getContainer()->get('messenger.transport.notifications')->setup();
    }
    protected function tearDown(): void {if(isset($this->db)&&$this->db->isTransactionActive()) {$this->db->rollBack();}parent::tearDown();}
    private function message(string $queue,string $available='-10 seconds',?string $delivered=null): void
    {
        $this->db->executeStatement('INSERT INTO messenger_messages(body,headers,queue_name,created_at,available_at,delivered_at) VALUES (?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP+?::interval,CASE WHEN ?::text IS NULL THEN NULL ELSE CURRENT_TIMESTAMP+?::interval END)',['private-marker-recipient@example.test','{"password":"private-marker"}',$queue,$available,$delivered,$delivered]);
    }
    public function testQueueClassificationRedeliveryTimeoutsAndNoSensitiveData(): void
    {
        $this->message('notifications','-10 minutes');$this->message('notifications','+1 hour');$this->message('notifications','-10 minutes','-10 seconds');$this->message('notifications','-10 minutes','-3 minutes');$this->message('async','-10 minutes','-3 minutes');$this->message('private-marker-queue','+1 hour');
        $report=(new OperationalStatus($this->db,false,300))->read();self::assertSame('degraded',$report['status']);$q=(array)$report['queues'];self::assertSame(['total'=>4,'due'=>2,'delayed'=>1,'inflight'=>1,'oldest_due_seconds'=>600],$q['notifications']);self::assertSame(1,$q['async']['inflight']);self::assertSame(1,$q['other']['delayed']);self::assertContains('queue_overdue',$report['signals']);self::assertFalse($report['mail_delivery_enabled']);self::assertStringNotContainsString('private-marker',json_encode($report));self::assertTrue($this->db->isTransactionActive());self::assertSame(6,$this->db->fetchOne('SELECT count(*) FROM messenger_messages'));
    }
    public function testFailedTransportAndNotificationFailuresAreVisibleWithoutAddresses(): void
    {
        $this->message('failed');$user=$this->db->fetchOne("INSERT INTO users(email,name,password_hash,role_id) VALUES ('private-marker@example.test','private-marker','hash',1) RETURNING id");
        $this->db->executeStatement("INSERT INTO notification_events(type,event_key,recipient_id,payload,status,attempts,last_error_code) VALUES ('information','ops03-fixture',?,'{}','failed',4,'smtp_delivery_failed')",[$user]);
        $id=$this->db->fetchOne('SELECT id FROM notification_events');$this->db->executeStatement("INSERT INTO notification_delivery_attempts(event_id,attempt_number,result_code) VALUES (?,1,'smtp_delivery_failed')",[$id]);
        $report=(new OperationalStatus($this->db,true))->read();self::assertSame('degraded',$report['status']);self::assertContains('failed_transport_not_empty',$report['signals']);self::assertContains('notification_failures',$report['signals']);self::assertSame(1,$report['notifications']['exhausted']);self::assertSame(1,$report['delivery_last_24h']['smtp_failures']);self::assertStringNotContainsString('private-marker',json_encode($report));
    }
    public function testEmptyAndFutureQueuesRemainHealthy(): void
    {
        $this->message('notifications','+1 day');$report=(new OperationalStatus($this->db,false))->read();self::assertSame('ok',$report['status']);self::assertSame([],$report['signals']);self::assertSame(0,$report['stock_reconciliation_differences']);
    }
    public function testPendingDispatchIsOverdueOnlyWhenDeliveryIsEnabled(): void
    {
        $id=$this->db->fetchOne("INSERT INTO users(email,name,password_hash,role_id) VALUES ('ops-pending@example.test','Test','hash',1) RETURNING id");
        $this->db->executeStatement("INSERT INTO notification_events(type,event_key,recipient_id,payload,status,available_at) VALUES ('information','ops-pending',?,'{}','pending',CURRENT_TIMESTAMP-interval '10 minutes')",[$id]);
        $disabled=(new OperationalStatus($this->db,false))->read();self::assertSame('ok',$disabled['status']);self::assertSame(1,$disabled['notifications']['pending_due']);
        $enabled=(new OperationalStatus($this->db,true))->read();self::assertSame('degraded',$enabled['status']);self::assertContains('notification_dispatch_overdue',$enabled['signals']);self::assertSame(600,$enabled['notifications']['oldest_pending_seconds']);
    }
    public function testDatabaseFailureAndBadThresholdAreSanitized(): void
    {
        $db=$this->createStub(Connection::class);$db->method('isTransactionActive')->willReturn(false);$db->method('beginTransaction')->willThrowException(new \RuntimeException('private-marker password'));$report=(new OperationalStatus($db,false))->read();self::assertSame('unavailable',$report['status']);self::assertSame('database_or_schema_unavailable',$report['code']);self::assertStringNotContainsString('private-marker',json_encode($report));self::assertSame('invalid_queue_age_threshold',(new OperationalStatus($db,false,0))->read()['code']);
    }
}
