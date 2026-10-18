<?php

declare(strict_types=1);

namespace App\Command;

use App\Security\StockUser;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:dev:create-admin', description: 'Créer l’administrateur de démonstration, uniquement avec APP_ENV=dev.')]
final class CreateDevelopmentAdminCommand extends Command
{
    public const EMAIL = 'admin.dev@example.test';
    // Public demonstration credential: its database account is rejected outside dev.
    public const PASSWORD = 'Dev-Admin-2026!';

    public function __construct(private readonly Connection $db, private readonly UserPasswordHasherInterface $hasher,
        #[Autowire('%kernel.environment%')] private readonly string $environment) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->environment !== 'dev') {
            $io->error('Ce compte de test est réservé à APP_ENV=dev. Aucune donnée modifiée.');
            return Command::FAILURE;
        }
        return $this->db->transactional(function () use ($io): int {
            $this->db->executeStatement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
            $existing = $this->db->fetchAssociative('SELECT development_only FROM users WHERE lower(btrim(email)) = ?', [self::EMAIL]);
            if ($existing) {
                if (!$existing['development_only']) {
                    $io->error('Ce courriel appartient déjà à un compte normal ; aucune modification effectuée.');
                    return Command::FAILURE;
                }
                $io->success('Le compte de développement existe déjà ; il est conservé sans modification.');
                return Command::SUCCESS;
            }
            $hash = $this->hasher->hashPassword(new StockUser('', self::EMAIL, 'Administrateur de test', 'ADMIN', true, '', '0', 'Europe/Paris', ''), self::PASSWORD);
            $id = $this->db->fetchOne('INSERT INTO users (email, name, password_hash, role_id, development_only) VALUES (?, ?, ?, 1, true) RETURNING id', [self::EMAIL, 'Administrateur de test', $hash]);
            $this->db->executeStatement("INSERT INTO audit_logs (actor_id, action, target_type, target_id) VALUES (?, 'auth.development_admin_created', 'user', ?)", [$id, $id]);
            $io->success('Administrateur de test créé. Identifiants disponibles dans docs/administrateur-test.md.');
            return Command::SUCCESS;
        });
    }
}
