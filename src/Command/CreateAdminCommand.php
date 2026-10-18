<?php

declare(strict_types=1);

namespace App\Command;

use App\Security\StockUser;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:user:create-admin', description: 'Créer le premier administrateur, uniquement si aucun compte n’existe.')]
final class CreateAdminCommand extends Command
{
    public function __construct(private readonly Connection $db, private readonly UserPasswordHasherInterface $hasher) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Courriel du premier administrateur');
        $this->addArgument('name', InputArgument::REQUIRED, 'Nom affiché');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = strtolower(trim($input->getArgument('email')));
        $name = trim($input->getArgument('name'));
        if (!$input->isInteractive() || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || mb_strlen($name) > 160) {
            $io->error('Un courriel valide, un nom et une saisie interactive du mot de passe sont requis.');
            return Command::INVALID;
        }
        if ($this->db->fetchOne('SELECT count(*) FROM users WHERE NOT development_only') > 0) {
            $io->error('Un compte existe déjà. Utilisez l’administration des comptes.');
            return Command::FAILURE;
        }
        $validator = static function (#[\SensitiveParameter] ?string $value): string {
            if ($value === null || mb_strlen($value) < 12 || mb_strlen($value) > 128 || str_contains($value, "\0")) {
                throw new \RuntimeException('Choisissez un mot de passe de 12 à 128 caractères.');
            }
            return $value;
        };
        $question = (new Question('Mot de passe : '))->setHidden(true)->setHiddenFallback(false)->setValidator($validator)->setMaxAttempts(3);
        $password = $io->askQuestion($question);
        $confirmation = $io->askQuestion((new Question('Confirmer le mot de passe : '))->setHidden(true)->setHiddenFallback(false));
        if (!hash_equals($password, $confirmation ?? '')) {
            $io->error('Les mots de passe ne correspondent pas.');
            return Command::INVALID;
        }
        $hash = $this->hasher->hashPassword(new StockUser('', $email, $name, 'ADMIN', true, '', '0', 'Europe/Paris', ''), $password);
        unset($password, $confirmation);
        return $this->db->transactional(function () use ($email, $name, $hash, $io): int {
            $this->db->executeStatement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
            if ($this->db->fetchOne('SELECT count(*) FROM users WHERE NOT development_only') > 0) {
                $io->error('Un compte a déjà été créé.');
                return Command::FAILURE;
            }
            $id = $this->db->fetchOne('INSERT INTO users (email, name, password_hash, role_id) VALUES (?, ?, ?, 1) RETURNING id', [$email, $name, $hash]);
            $this->db->executeStatement("INSERT INTO audit_logs (actor_id, action, target_type, target_id) VALUES (?, 'auth.bootstrap_admin', 'user', ?)", [$id, $id]);
            $io->success('Premier administrateur créé. Vous pouvez vous connecter.');
            return Command::SUCCESS;
        });
    }
}
