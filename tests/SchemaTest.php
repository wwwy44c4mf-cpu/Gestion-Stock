<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DatabaseException;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** Chaque test migre un schéma isolé dans une transaction, annulée après le test. */
final class SchemaTest extends KernelTestCase
{
    private Connection $db;
    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->db->beginTransaction();
        $schema = 'soc02_'.bin2hex(random_bytes(6));
        $this->db->executeStatement('CREATE SCHEMA '.$schema);
        $this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        $this->application = new Application(self::$kernel);
        $this->application->setAutoExit(false);
        $this->migrate('latest');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function migrate(string $version): void
    {
        $output = new BufferedOutput();
        $code = $this->runMigration($version, $output);
        self::assertSame(0, $code, $output->fetch());
    }

    private function runMigration(string $version, BufferedOutput $output): int
    {
        // Une vraie invocation CLI crée une nouvelle factory ; ne pas réutiliser
        // l’instance de migration figée par l’exécution précédente dans ce test.
        $factory = DependencyFactory::fromConnection(new ConfigurationArray([
            'migrations_paths' => ['DoctrineMigrations' => dirname(__DIR__).'/migrations'],
            'all_or_nothing' => true,
        ]), new ExistingConnection($this->db));
        $command = new MigrateCommand($factory);
        $command->setName('doctrine:migrations:migrate');
        $this->application->addCommand($command);
        return $this->application->run(new ArrayInput([
            'command' => 'doctrine:migrations:migrate',
            'version' => $version,
            '--no-interaction' => true,
        ]), $output);
    }

    private function fixtures(): array
    {
        $user = $this->db->fetchOne("INSERT INTO users (email, name, password_hash, role_id) VALUES ('admin@example.test', 'Admin fictif', 'hash-fictif-non-utilisable', 1) RETURNING id");
        $consumer = $this->db->fetchOne("INSERT INTO users (email, name, password_hash, role_id) VALUES ('technicien@example.test', 'Technicien fictif', 'hash-fictif-non-utilisable', 3) RETURNING id");
        $a = $this->db->fetchOne("INSERT INTO stores (code, name) VALUES ('A', 'Magasin A') RETURNING id");
        $b = $this->db->fetchOne("INSERT INTO stores (code, name) VALUES ('B', 'Magasin B') RETURNING id");
        $article = $this->db->fetchOne("INSERT INTO articles (sku, name) VALUES ('REF-001', 'Article fictif') RETURNING id");
        return compact('user', 'consumer', 'a', 'b', 'article');
    }

    private function rejected(string $sql, array $params = []): void
    {
        // Une erreur SQL ne doit pas annuler la transaction d’isolation du test.
        try {
            $this->db->transactional(fn (Connection $db) => $db->executeStatement($sql, $params));
        } catch (DatabaseException) {
            self::assertTrue(true);
            return;
        }
        self::fail('La base aurait dû refuser cette écriture.');
    }

    private function movement(array $f, string $type, string $quantity, ?string $source, ?string $destination, ?string $related = null): string
    {
        return $this->db->fetchOne(
            'INSERT INTO stock_movements (type, article_id, quantity, source_store_id, destination_store_id, author_id, consumer_id, reason, related_movement_id, idempotency_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [$type, $f['article'], $quantity, $source, $destination, $f['user'], $type === 'consumption' ? $f['consumer'] : null, 'Motif fictif', $related, bin2hex(random_bytes(8))],
        );
    }

    public function testVirginMigrationCanBeReversedAndReapplied(): void
    {
        self::assertSame(3, (int) $this->db->fetchOne('SELECT count(*) FROM roles'));
        $this->migrate('0');
        self::assertNull($this->db->fetchOne("SELECT to_regclass(current_schema() || '.articles')") ?: null);
        $this->migrate('latest');
        self::assertSame(3, (int) $this->db->fetchOne('SELECT count(*) FROM roles'));
    }

    public function testMigrationReexecutionIsANoop(): void
    {
        $this->migrate('latest');
        self::assertSame(23, (int) $this->db->fetchOne('SELECT count(*) FROM doctrine_migration_versions'));
    }

    public function testReferencesAndStockPairsAreUnique(): void
    {
        $f = $this->fixtures();
        $this->rejected("INSERT INTO articles (sku, name) VALUES (' ref-001 ', 'Doublon')");
        $this->rejected("INSERT INTO stores (code, name) VALUES ('a', 'Doublon')");
        $this->rejected("INSERT INTO users (email, name, password_hash, role_id) VALUES ('ADMIN@example.test', 'Doublon', 'hash', 1)");
        $this->db->insert('stock_levels', ['article_id' => $f['article'], 'store_id' => $f['a']]);
        $this->rejected('INSERT INTO stock_levels (article_id, store_id) VALUES (?, ?)', [$f['article'], $f['a']]);
        $this->rejected('UPDATE articles SET sku = ? WHERE id = ?', ['AUTRE-REF', $f['article']]);
    }

    public function testQuantitiesRejectFractionsRatherThanRoundThem(): void
    {
        $f = $this->fixtures();
        foreach (['1.5', '0.000000001', '-1', 'NaN', 'Infinity', '9007199254740992'] as $invalid) {
            $this->rejected('INSERT INTO stock_levels (article_id, store_id, quantity) VALUES (?, ?, ?)', [$f['article'], $f['a'], $invalid]);
        }
        $this->rejected('INSERT INTO stock_levels (article_id, store_id, low_threshold) VALUES (?, ?, ?)', [$f['article'], $f['a'], '2.5']);
        $this->rejected('INSERT INTO stock_levels (article_id, store_id, low_threshold, critical_threshold) VALUES (?, ?, 2, 3)', [$f['article'], $f['a']]);
        $this->db->insert('stock_levels', ['article_id' => $f['article'], 'store_id' => $f['a'], 'quantity' => '9007199254740991', 'low_threshold' => '0']);
        self::assertSame('9007199254740991', $this->db->fetchOne('SELECT quantity FROM stock_levels'));
    }

    public function testJournalRejectsInvalidMovementShapesAndFractions(): void
    {
        $f = $this->fixtures();
        $sql = 'INSERT INTO stock_movements (type, article_id, quantity, source_store_id, destination_store_id, author_id, idempotency_key, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        $this->rejected($sql, ['entry', $f['article'], '0.5', null, $f['a'], $f['user'], 'fraction', 'motif']);
        $this->rejected($sql, ['entry', $f['article'], '0', null, $f['a'], $f['user'], 'zero', 'motif']);
        $this->rejected($sql, ['entry', $f['article'], '1', $f['a'], null, $f['user'], 'direction', 'motif']);
        $this->rejected($sql, ['transfer', $f['article'], '1', $f['a'], $f['a'], $f['user'], 'same-store', 'motif']);
        $this->rejected($sql, ['adjustment', $f['article'], '1', null, $f['a'], $f['user'], 'no-reason', null]);
        $this->rejected($sql, ['consumption', $f['article'], '1', $f['a'], null, $f['user'], 'no-consumer', null]);
    }

    public function testHistoryAndReferencedObjectsCannotBeDeleted(): void
    {
        $f = $this->fixtures();
        $id = $this->movement($f, 'entry', '10', null, $f['a']);
        $this->rejected('UPDATE stock_movements SET quantity = 5 WHERE id = ?', [$id]);
        $this->rejected('DELETE FROM stock_movements WHERE id = ?', [$id]);
        $this->rejected('DELETE FROM articles WHERE id = ?', [$f['article']]);
        $this->rejected('DELETE FROM stores WHERE id = ?', [$f['a']]);
        $this->rejected('DELETE FROM users WHERE id = ?', [$f['user']]);
        $this->db->executeStatement('UPDATE articles SET active = false WHERE id = ?', [$f['article']]);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM stock_movements'));
    }

    public function testForeignKeysIdempotencyAndReturnOriginsAreEnforced(): void
    {
        $f = $this->fixtures();
        $this->rejected('INSERT INTO user_stores (user_id, store_id) VALUES (gen_random_uuid(), ?)', [$f['a']]);
        $entry = $this->movement($f, 'entry', '5', null, $f['a']);
        $this->rejected('INSERT INTO stock_movements (type, article_id, destination_store_id, quantity, author_id, reason, idempotency_key) SELECT type, article_id, destination_store_id, quantity, author_id, reason, idempotency_key FROM stock_movements WHERE id = ?', [$entry]);
        $sql = "INSERT INTO stock_movements (type, article_id, destination_store_id, quantity, author_id, related_movement_id, idempotency_key) VALUES ('return', ?, ?, 1, ?, ?, ?)";
        $this->rejected($sql, [$f['article'], $f['a'], $f['user'], $entry, 'wrong-original-type']);
        $consumption = $this->movement($f, 'consumption', '2', $f['a'], null);
        $this->db->executeStatement($sql, [$f['article'], $f['b'], $f['user'], $consumption, 'other-deposit-store']);
        self::assertSame($f['b'],$this->db->fetchOne("SELECT destination_store_id FROM stock_movements WHERE idempotency_key='other-deposit-store'"));
        // Aucun lien user_stores n’existe pour ce bénéficiaire : choix utilisateur.
        self::assertSame($f['consumer'], $this->db->fetchOne('SELECT consumer_id FROM stock_movements WHERE id = ?', [$consumption]));
        $return = $this->movement($f, 'return', '1', null, $f['a'], $consumption);
        self::assertSame($consumption, $this->db->fetchOne('SELECT related_movement_id FROM stock_movements WHERE id = ?', [$return]));
    }

    public function testLedgerConservesTransfersAndReconcilesAllBalances(): void
    {
        $f = $this->fixtures();
        $this->movement($f, 'entry', '10', null, $f['a']);
        $consumption = $this->movement($f, 'consumption', '4', $f['a'], null);
        $transfer = $this->movement($f, 'transfer', '3', $f['a'], $f['b']);
        $this->movement($f, 'return', '1', null, $f['a'], $consumption);
        $this->movement($f, 'adjustment', '1', $f['a'], null);
        self::assertSame('0', $this->db->fetchOne('SELECT sum(quantity) FROM stock_ledger WHERE movement_id = ?', [$transfer]));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance'));
        foreach ([$f['a'], $f['b']] as $store) {
            $this->db->insert('stock_levels', ['article_id' => $f['article'], 'store_id' => $store, 'quantity' => '3']);
        }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference <> 0'));
        $this->db->executeStatement('UPDATE stock_levels SET quantity = 2 WHERE store_id = ?', [$f['a']]);
        $output = new BufferedOutput();
        self::assertSame(1, $this->application->run(new ArrayInput(['command' => 'app:stock:reconcile']), $output));
        $result = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($result['consistent']);
        self::assertCount(1, $result['differences']);
        self::assertSame('2', $this->db->fetchOne('SELECT quantity FROM stock_levels WHERE store_id = ?', [$f['a']]));
    }

    public function testInventoryDistinguishesUncountedAndZero(): void
    {
        $f = $this->fixtures();
        $inventory = $this->db->fetchOne("INSERT INTO inventories (store_id, created_by, status, snapshot_at) VALUES (?, ?, 'in_progress', CURRENT_TIMESTAMP) RETURNING id", [$f['a'], $f['user']]);
        $this->rejected("INSERT INTO inventories (store_id, created_by, status, snapshot_at) VALUES (?, ?, 'in_progress', CURRENT_TIMESTAMP)", [$f['a'], $f['user']]);
        $this->db->insert('inventory_lines', ['inventory_id' => $inventory, 'article_id' => $f['article'], 'theoretical_qty' => '10']);
        self::assertNull($this->db->fetchOne('SELECT delta FROM inventory_lines'));
        $this->rejected('UPDATE inventory_lines SET counted_qty = 0.1, counted_by = ?, counted_at = CURRENT_TIMESTAMP', [$f['user']]);
        $this->db->executeStatement('UPDATE inventory_lines SET counted_qty = 0, counted_by = ?, counted_at = CURRENT_TIMESTAMP', [$f['user']]);
        self::assertSame('-10', $this->db->fetchOne('SELECT delta FROM inventory_lines'));
        $this->rejected("UPDATE inventories SET status = 'closed' WHERE id = ?", [$inventory]);
    }

    public function testAlertAndNotificationKeysPreventDuplicates(): void
    {
        $f = $this->fixtures();
        $this->db->insert('stock_levels', ['article_id' => $f['article'], 'store_id' => $f['a']]);
        $alert = 'INSERT INTO stock_alerts (article_id, store_id, level, threshold) VALUES (?, ?, ?, 0)';
        $this->db->executeStatement($alert, [$f['article'], $f['a'], 'low']);
        $this->rejected($alert, [$f['article'], $f['a'], 'critical']);
        $event = "INSERT INTO notification_events (event_key, type, recipient_id) VALUES ('event-unique', 'daily_summary', ?) RETURNING id";
        $id = $this->db->fetchOne($event, [$f['user']]);
        $this->rejected($event, [$f['user']]);
        $this->db->executeStatement('INSERT INTO daily_notification_runs (recipient_id, local_date, event_id) VALUES (?, CURRENT_DATE, ?)', [$f['user'], $id]);
        $second = $this->db->fetchOne("INSERT INTO notification_events (event_key, type, recipient_id) VALUES ('event-2', 'daily_summary', ?) RETURNING id", [$f['user']]);
        $this->rejected('INSERT INTO daily_notification_runs (recipient_id, local_date, event_id) VALUES (?, CURRENT_DATE, ?)', [$f['user'], $second]);
    }

    public function testProposalNeedsAnExplicitReviewBeforeApproval(): void
    {
        $f = $this->fixtures();
        $this->db->executeStatement("INSERT INTO article_proposals (operation, payload, proposed_by) VALUES ('create', '{\"sku\":\"NEW\"}', ?)", [$f['user']]);
        $this->rejected("UPDATE article_proposals SET status = 'approved'");
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM articles'));
        self::assertSame('pending', $this->db->fetchOne('SELECT status FROM article_proposals'));
    }

    public function testAnonymizationPreservesHistoryAndAuditCannotBeAltered(): void
    {
        $f = $this->fixtures();
        $this->movement($f, 'entry', '1', null, $f['a']);
        $this->db->insert('audit_logs', ['actor_id' => $f['user'], 'action' => 'fixture.created', 'target_type' => 'article', 'target_id' => $f['article']]);
        $this->rejected('UPDATE audit_logs SET action = ?', ['altered']);
        $this->rejected('DELETE FROM audit_logs');
        $this->db->executeStatement('UPDATE users SET active = false, email = NULL, name = NULL, password_hash = NULL, anonymized_at = CURRENT_TIMESTAMP WHERE id = ?', [$f['user']]);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM stock_movements WHERE author_id = ?', [$f['user']]));
    }

    public function testRollbackWithBusinessDataIsRefusedWithoutDataLoss(): void
    {
        $this->fixtures();
        // La commande capture l’erreur ; la transaction de migration est annulée.
        $output = new BufferedOutput();
        $code = $this->runMigration('0', $output);
        $text = $output->fetch();
        self::assertNotSame(0, $code, $text);
        self::assertStringContainsString('Retour arrière refusé', $text);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM articles'));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT count(*) FROM users'));
    }
    public function testDevelopmentProtectionCannotBeRolledBackWithDevAccounts(): void
    {
        $this->db->executeStatement("INSERT INTO users (email, name, password_hash, role_id, development_only) VALUES ('dev@example.test', 'Dev', 'fake-hash', 1, true)");
        $output = new BufferedOutput();
        self::assertNotSame(0, $this->runMigration('0', $output));
        self::assertStringContainsString('compte de développement présent', $output->fetch());
        self::assertTrue($this->db->fetchOne('SELECT development_only FROM users'));
    }

    public function testUserCreationReplayCannotBeDroppedByRollback(): void
    {
        $fixtures = $this->fixtures();
        $this->db->executeStatement('INSERT INTO user_creation_requests (actor_id, request_key, request_fingerprint, user_id, response) VALUES (?, ?, ?, ?, ?::jsonb)', [$fixtures['user'], 'schema-user-key', str_repeat('a', 64), $fixtures['consumer'], '{}']);
        $output = new BufferedOutput();
        self::assertNotSame(0, $this->runMigration('0', $output));
        self::assertStringContainsString('créations de comptes présentes', $output->fetch());
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM user_creation_requests'));
    }

    public function testStoreCreationReplaySurvivesRejectedRollback(): void
    {
        $f = $this->fixtures();
        $this->db->executeStatement('INSERT INTO store_creation_requests (actor_id,request_key,request_fingerprint,store_id,response) VALUES (?,?,?,?,?::jsonb)', [$f['user'],'schema-store-key',str_repeat('b',64),$f['a'],'{}']);
        $output = new BufferedOutput();
        self::assertNotSame(0, $this->runMigration('0', $output));
        self::assertStringContainsString('créations de magasins présentes', $output->fetch());
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM store_creation_requests'));
    }

    public function testCatalogReplayProtectsMigrationRollback(): void
    {
        $f = $this->fixtures();
        $this->db->executeStatement("INSERT INTO catalog_creation_requests (actor_id,resource_type,request_key,request_fingerprint,article_id,response) VALUES (?,'article',?,?,?,?::jsonb)", [$f['user'],'schema-catalog-key',str_repeat('c',64),$f['article'],'{}']);
        $output = new BufferedOutput();
        self::assertNotSame(0, $this->runMigration('0', $output));
        self::assertStringContainsString('créations de catalogue ou articles inactifs', $output->fetch());
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM catalog_creation_requests'));
    }

    public function testInactiveArticlesPreventRemovingConsumptionProtection(): void
    {
        $f = $this->fixtures();
        $this->db->executeStatement('UPDATE articles SET active = false WHERE id = ?', [$f['article']]);
        $output = new BufferedOutput();
        self::assertNotSame(0, $this->runMigration('0', $output));
        self::assertStringContainsString('créations de catalogue ou articles inactifs', $output->fetch());
        self::assertSame(1, (int) $this->db->fetchOne("SELECT count(*) FROM pg_trigger WHERE tgname = 'movements_active_article' AND tgrelid = 'stock_movements'::regclass"));
    }

    public function testProposalReplayProtectsMigrationRollback(): void
    {
        $f = $this->fixtures();
        $id = $this->db->fetchOne("INSERT INTO article_proposals (operation,payload,proposed_by) VALUES ('create','{}',?) RETURNING id", [$f['user']]);
        $this->db->executeStatement("INSERT INTO proposal_requests (actor_id,action,request_key,request_fingerprint,proposal_id,response) VALUES (?,'create',?,?,?,?::jsonb)", [$f['user'],'schema-proposal-key',str_repeat('d',64),$id,'{}']);
        $output = new BufferedOutput();
        self::assertNotSame(0, $this->runMigration('0', $output));
        self::assertStringContainsString('résultats de propositions présents', $output->fetch());
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM proposal_requests'));
    }

    public function testStockEventsPreventDestructiveMigrationRollback(): void
    {
        $f = $this->fixtures(); $movement = $this->movement($f,'entry','1',null,$f['a']);
        $this->db->executeStatement('INSERT INTO stock_change_events (movement_id,article_id,store_ids) VALUES (?,?,?::uuid[])',[$movement,$f['article'],'{'.$f['a'].'}']);
        $output = new BufferedOutput(); self::assertNotSame(0,$this->runMigration('DoctrineMigrations\\Version20261018150000',$output));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM stock_change_events'));self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM stock_movements'));
        $this->rejected('UPDATE stock_change_events SET store_ids = ?::uuid[] WHERE movement_id = ?',['{}',$movement]);
    }

    public function testCorrectionLinksAreImmutableValidatedAndProtectRollback(): void
    {
        $f=$this->fixtures();$original=$this->movement($f,'entry','10',null,$f['a']);$undo=$this->movement($f,'reversal','10',$f['a'],null,$original);$replacement=$this->movement($f,'entry','8',null,$f['a']);
        $this->db->insert('movement_corrections',['original_id'=>$original,'reversal_id'=>$undo,'replacement_id'=>$replacement]);
        $this->rejected('DELETE FROM movement_corrections');$this->rejected('UPDATE movement_corrections SET replacement_id = original_id');
        $other=$this->movement($f,'entry','3',null,$f['a']);$otherUndo=$this->movement($f,'reversal','3',$f['a'],null,$other);$foreign=$this->movement($f,'entry','2',null,$f['b']);
        $this->rejected('INSERT INTO movement_corrections (original_id,reversal_id,replacement_id) VALUES (?,?,?)',[$other,$otherUndo,$foreign]);
        $output=new BufferedOutput();self::assertNotSame(0,$this->runMigration('DoctrineMigrations\\Version20261018200000',$output));self::assertStringContainsString('corrections présentes',$output->fetch());self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM movement_corrections'));
    }

}
