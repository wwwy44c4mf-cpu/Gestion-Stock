<?php

declare(strict_types=1);

namespace App\Tests;

use App\Command\CreateDevelopmentAdminCommand;
use App\Security\StockUserProvider;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

final class DevelopmentAdminTest extends KernelTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->db->beginTransaction();
        $this->db->executeStatement('CREATE SCHEMA devadmin_'.($suffix = bin2hex(random_bytes(6))));
        $this->db->executeStatement('SET LOCAL search_path TO devadmin_'.$suffix);
        foreach (explode('-- migrate:statement', file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) {
            if (trim($sql) !== '') { $this->db->executeStatement($sql); }
        }
        $this->db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) { $this->db->rollBack(); }
        parent::tearDown();
    }

    private function command(string $environment): CommandTester
    {
        return new CommandTester(new CreateDevelopmentAdminCommand($this->db, static::getContainer()->get(UserPasswordHasherInterface::class), $environment));
    }

    public function testCommandRefusesAllEnvironmentsExceptDevWithoutWriting(): void
    {
        foreach (['prod', 'test', 'development', 'staging'] as $environment) {
            self::assertSame(1, $this->command($environment)->execute([]));
        }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM users'));
    }

    public function testDevAdminIsHashedIdempotentAndRejectedOutsideDev(): void
    {
        $command = $this->command('dev');
        self::assertSame(0, $command->execute([]));
        $row = $this->db->fetchAssociative('SELECT * FROM users');
        self::assertTrue($row['development_only']);
        self::assertSame(1, $row['role_id']);
        self::assertStringStartsWith('$argon2id$', $row['password_hash']);
        self::assertTrue(password_verify(CreateDevelopmentAdminCommand::PASSWORD, $row['password_hash']));
        self::assertSame(0, $command->execute([]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM users'));
        self::assertSame($row['password_hash'], $this->db->fetchOne('SELECT password_hash FROM users'));
        $devUser = (new StockUserProvider($this->db, 'dev'))->loadUserByIdentifier(CreateDevelopmentAdminCommand::EMAIL);
        self::assertSame(['ROLE_ADMIN'], $devUser->getRoles());
        foreach (['prod', 'test', 'staging'] as $environment) {
            $provider = new StockUserProvider($this->db, $environment);
            foreach ([fn () => $provider->loadUserByIdentifier(CreateDevelopmentAdminCommand::EMAIL), fn () => $provider->refreshUser($devUser)] as $load) {
                try { $load(); self::fail('Le compte dev doit être refusé hors dev.'); }
                catch (UserNotFoundException) { self::assertTrue(true); }
            }
        }
        // Renaming the account cannot bypass the database marker.
        $this->db->executeStatement("UPDATE users SET email = 'renamed@example.test' WHERE id = ?", [$row['id']]);
        $this->expectException(UserNotFoundException::class);
        (new StockUserProvider($this->db, 'prod'))->loadUserByIdentifier('renamed@example.test');
    }

    public function testExistingNormalAccountIsNeverPromotedOrOverwritten(): void
    {
        $this->db->executeStatement('INSERT INTO users (email, name, password_hash, role_id) VALUES (?, ?, ?, 3)', [CreateDevelopmentAdminCommand::EMAIL, 'Compte normal', 'hash-original']);
        self::assertSame(1, $this->command('dev')->execute([]));
        $row = $this->db->fetchAssociative('SELECT * FROM users');
        self::assertFalse($row['development_only']);
        self::assertSame(3, $row['role_id']);
        self::assertSame('hash-original', $row['password_hash']);
    }
    public function testFirstNormalAdminCanStillBeBootstrapped(): void
    {
        self::assertSame(0, $this->command('dev')->execute([]));
        $tester = new CommandTester(new \App\Command\CreateAdminCommand($this->db, static::getContainer()->get(UserPasswordHasherInterface::class)));
        $tester->setInputs(['Real-test-password-123!', 'Real-test-password-123!']);
        self::assertSame(0, $tester->execute(['email' => 'real@example.test', 'name' => 'Premier compte normal']));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM users WHERE NOT development_only'));
        self::assertSame(['ROLE_ADMIN'], (new StockUserProvider($this->db, 'prod'))->loadUserByIdentifier('real@example.test')->getRoles());
    }

}
