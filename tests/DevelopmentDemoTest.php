<?php

declare(strict_types=1);
namespace App\Tests;

use App\Command\LoadDevelopmentDemoCommand;
use App\Security\StockUserProvider;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

final class DevelopmentDemoTest extends KernelTestCase
{
    private Connection $db;
    private string $schema;
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->schema = 'demo_fixture_'.bin2hex(random_bytes(6));
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
    private function load(string $environment = 'dev', bool $mail = false): int
    {
        return (new CommandTester(new LoadDevelopmentDemoCommand($this->db, $environment, $mail)))->execute([]);
    }

    public function testGuardsRefuseBeforeWritingAndNeverReplaceExistingData(): void
    {
        foreach (['prod', 'test', 'staging'] as $env) { self::assertSame(1, $this->load($env)); }
        self::assertSame(1, $this->load('dev', true));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM users'));
        $this->db->executeStatement("INSERT INTO stores(code,name) VALUES ('REAL','Existing marker')");
        self::assertSame(1, $this->load());
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM users'));
        self::assertSame('Existing marker', $this->db->fetchOne('SELECT name FROM stores'));
    }

    public function testFixtureBalancesAccessMarkersAndSecondRunRefusal(): void
    {
        self::assertSame(0, $this->load());
        foreach (['users'=>5, 'stores'=>2, 'articles'=>20, 'categories'=>3, 'stock_movements'=>43, 'audit_logs'=>43, 'stock_change_events'=>43] as $table=>$count) {
            self::assertSame($count, (int) $this->db->fetchOne('SELECT count(*) FROM '.$table));
        }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference<>0'));
        foreach (['DEMO-001'=>3, 'DEMO-002'=>0, 'DEMO-003'=>18] as $sku=>$quantity) {
            self::assertSame($quantity, (int) $this->db->fetchOne("SELECT l.quantity FROM stock_levels l JOIN stores s ON s.id=l.store_id JOIN articles a ON a.id=l.article_id WHERE s.code='DEMO-A' AND a.sku=?", [$sku]));
        }
        foreach ($this->db->fetchAllAssociative('SELECT email,password_hash,development_only FROM users') as $user) {
            self::assertTrue($user['development_only']);
            self::assertTrue(password_verify(LoadDevelopmentDemoCommand::PASSWORD, $user['password_hash']));
            $dev = (new StockUserProvider($this->db, 'dev'))->loadUserByIdentifier($user['email']);
            foreach (['prod','test'] as $environment) {
                $provider = new StockUserProvider($this->db, $environment);
                foreach ([fn () => $provider->loadUserByIdentifier($user['email']), fn () => $provider->refreshUser($dev)] as $operation) {
                    try { $operation(); self::fail('Demo account accepted outside dev'); }
                    catch (UserNotFoundException) { self::assertTrue(true); }
                }
            }
        }
        self::assertSame(1, $this->load());
        self::assertSame(43, (int) $this->db->fetchOne('SELECT count(*) FROM stock_movements'));
        self::assertSame(6, (int) $this->db->fetchOne('SELECT count(*) FROM user_stores'));
    }

    public function testFailureRollsBackTheEntireDataset(): void
    {
        $this->db->executeStatement("CREATE FUNCTION reject_demo_article() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'synthetic_failure'; END \$\$");
        $this->db->executeStatement('CREATE TRIGGER reject_demo BEFORE INSERT ON articles FOR EACH ROW EXECUTE FUNCTION reject_demo_article()');
        try { $this->load(); self::fail('Expected synthetic failure'); }
        catch (\Doctrine\DBAL\Exception $exception) { self::assertStringContainsString('synthetic_failure', $exception->getMessage()); }
        foreach (['users','stores','categories','articles','stock_movements','audit_logs'] as $table) {
            self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM '.$table));
        }
    }
}
