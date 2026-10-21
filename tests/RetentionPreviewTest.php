<?php

declare(strict_types=1);

namespace App\Tests;

use App\Privacy\RetentionPreview;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class RetentionPreviewTest extends KernelTestCase
{
    private Connection $db;
    private string $schema;
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->schema = 'privacy_preview_'.bin2hex(random_bytes(6));
        $this->db->executeStatement('CREATE SCHEMA '.$this->schema);
        $this->db->executeStatement('SET search_path TO '.$this->schema);
        $factory = DependencyFactory::fromConnection(new ConfigurationArray(['migrations_paths'=>['DoctrineMigrations'=>dirname(__DIR__).'/migrations'],'all_or_nothing'=>true]),new ExistingConnection($this->db));
        $command = new MigrateCommand($factory);$command->setName('doctrine:migrations:migrate');
        $out = new BufferedOutput();$input = new ArrayInput([]);$input->setInteractive(false);
        self::assertSame(0,$command->run($input,$out),$out->fetch());
    }
    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->isTransactionActive()) { $this->db->rollBack(); }
            $this->db->executeStatement('SET search_path TO public');
            $this->db->executeStatement('DROP SCHEMA '.$this->schema.' CASCADE');
        }
        parent::tearDown();
    }
    private function fixture(string $date, bool $open): void
    {
        $user=$this->db->fetchOne("INSERT INTO users(email,name,password_hash,role_id) VALUES (?,'confidential-marker','hash',1) RETURNING id",[bin2hex(random_bytes(6)).'@example.test']);
        $store=$this->db->fetchOne("INSERT INTO stores(code,name) VALUES (?,'Fixture') RETURNING id",[bin2hex(random_bytes(6))]);
        $article=$this->db->fetchOne("INSERT INTO articles(sku,name) VALUES (?,'Fixture') RETURNING id",[bin2hex(random_bytes(6))]);
        $this->db->executeStatement("INSERT INTO stock_movements(type,article_id,destination_store_id,quantity,author_id,reason,reference,comment,created_at,idempotency_key) VALUES ('entry',?,?,2,?,'confidential-marker','confidential-marker','confidential-marker',?,'fixture-key')",[$article,$store,$user,$date]);
        $this->db->executeStatement("INSERT INTO audit_logs(actor_id,action,target_type,target_id,context,created_at) VALUES (?,'fixture','user',?,'{\"name\":\"confidential-marker\"}',?)",[$user,$user,$date]);
        $this->db->executeStatement("INSERT INTO inventories(store_id,created_by,created_at,status,cancelled_at) VALUES (?,?,?, ?,?::timestamptz)",[$store,$user,$date,$open?'draft':'cancelled',$open?null:$date]);
        $this->db->executeStatement("INSERT INTO article_proposals(operation,payload,proposed_by,created_at,status,reviewed_by,reviewed_at) VALUES ('create','{\"name\":\"confidential-marker\"}',?,?,?,?::uuid,?::timestamptz)",[$user,$date,$open?'pending':'rejected',$open?null:$user,$open?null:$date]);
    }
    private function snapshot(): array
    {
        $snapshot=[];
        foreach (['users','stock_movements','inventories','article_proposals','audit_logs'] as $table) {
            $snapshot[$table]=$this->db->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }
        return $snapshot;
    }
    public function testAnniversaryInclusiveNoMutationAndNoPersonalDataInReport(): void
    {
        $this->fixture('2025-10-22 12:00:00+00',false);
        $this->fixture('2025-10-22 12:00:00.000001+00',true);
        $this->fixture('2025-10-22 11:59:59.999999+00',true);
        $before=$this->snapshot();
        $preview=new RetentionPreview($this->db);
        $report=$preview->read(new \DateTimeImmutable('2026-10-22 14:00:00+02:00'));
        self::assertSame('2026-10-22T12:00:00.000000Z',$report['as_of']);
        self::assertSame(2,$report['counts']['movements_due']);
        self::assertSame(2,$report['counts']['audit_rows_due']);
        self::assertSame(1,$report['counts']['closed_inventories_creator_due']);
        self::assertSame(1,$report['counts']['open_inventories_deferred']);
        self::assertSame(1,$report['counts']['closed_proposals_author_due']);
        self::assertSame(1,$report['counts']['open_proposals_deferred']);
        self::assertFalse($report['execution_available']);
        self::assertStringNotContainsString('confidential-marker',json_encode($report));
        foreach($before['users'] as $user) {self::assertStringNotContainsString($user['id'],json_encode($report));}
        self::assertSame($before,$this->snapshot());
        self::assertSame($report,$preview->read(new \DateTimeImmutable('2026-10-22 12:00:00Z')));
        self::assertFalse($this->db->isTransactionActive());
    }
    public function testLeapDayUsesCalendarYearInsteadOf365Days(): void
    {
        $this->fixture('2024-02-29 08:15:00Z',false);
        $preview=new RetentionPreview($this->db);
        self::assertSame(0,$preview->read(new \DateTimeImmutable('2025-02-28 08:14:59.999999Z'))['counts']['movements_due']);
        self::assertSame(1,$preview->read(new \DateTimeImmutable('2025-02-28 08:15:00Z'))['counts']['movements_due']);
    }
    public function testRecentCorrectionOfOldConsumptionIsReportedUsingTheSameClock(): void
    {
        $this->fixture('2025-01-01 00:00:00Z',false);
        $ids=$this->db->fetchAssociative('SELECT author_id,article_id,destination_store_id FROM stock_movements LIMIT 1');
        $original=$this->db->fetchOne("INSERT INTO stock_movements(type,article_id,source_store_id,quantity,author_id,consumer_id,created_at,idempotency_key) VALUES ('consumption',?,?,2,?,?,'2025-01-01','old-consumption') RETURNING id",[$ids['article_id'],$ids['destination_store_id'],$ids['author_id'],$ids['author_id']]);
        $reversal=$this->db->fetchOne("INSERT INTO stock_movements(type,article_id,destination_store_id,quantity,author_id,related_movement_id,reason,created_at,idempotency_key) VALUES ('reversal',?,?,2,?,?,'Correction','2025-12-01','recent-reversal') RETURNING id",[$ids['article_id'],$ids['destination_store_id'],$ids['author_id'],$original]);
        $replacement=$this->db->fetchOne("INSERT INTO stock_movements(type,article_id,source_store_id,quantity,author_id,consumer_id,created_at,idempotency_key) VALUES ('consumption',?,?,3,?,?,'2025-12-01','recent-replacement') RETURNING id",[$ids['article_id'],$ids['destination_store_id'],$ids['author_id'],$ids['author_id']]);
        $this->db->executeStatement('INSERT INTO movement_corrections VALUES (?,?,?)',[$original,$reversal,$replacement]);
        $preview=new RetentionPreview($this->db);
        self::assertSame(1,$preview->read(new \DateTimeImmutable('2026-01-01Z'))['counts']['mixed_age_consumption_corrections']);
        self::assertSame(0,$preview->read(new \DateTimeImmutable('2027-01-01Z'))['counts']['mixed_age_consumption_corrections']);
    }
    public function testOwnTransactionRequiredWithoutRollingBackCallersWork(): void
    {
        $this->db->beginTransaction();
        try {(new RetentionPreview($this->db))->read();self::fail('Expected isolation refusal');}
        catch (\LogicException $error) {self::assertSame('privacy_preview_requires_own_transaction',$error->getMessage());self::assertTrue($this->db->isTransactionActive());}
    }
    public function testReadOnlyTransactionAndRollbackOnFailure(): void
    {
        $db=$this->createMock(Connection::class);
        $db->method('isTransactionActive')->willReturnOnConsecutiveCalls(false,true);
        $db->expects(self::once())->method('beginTransaction');
        $db->expects(self::exactly(2))->method('executeStatement')->willReturnCallback(static function(string $sql): int {self::assertContains($sql,['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY',"SET LOCAL TIME ZONE 'UTC'"]);return 0;});
        $db->method('fetchOne')->willThrowException(new \RuntimeException('fixture-failure'));
        $db->expects(self::once())->method('rollBack');
        $this->expectException(\RuntimeException::class);
        (new RetentionPreview($db))->read();
    }
}
