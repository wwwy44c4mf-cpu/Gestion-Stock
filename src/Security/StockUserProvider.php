<?php

declare(strict_types=1);

namespace App\Security;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/** @implements UserProviderInterface<StockUser> */
final class StockUserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(private readonly Connection $db, #[Autowire('%kernel.environment%')] private readonly string $environment) {}

    public function loadUserByIdentifier(string $identifier): StockUser
    {
        return $this->load('lower(btrim(u.email)) = lower(btrim(?))', $identifier);
    }

    public function refreshUser(UserInterface $user): StockUser
    {
        if (!$user instanceof StockUser) { throw new UnsupportedUserException(); }
        return $this->load('u.id = ?', $user->id);
    }

    private function load(string $where, string $value): StockUser
    {
        $row = $this->db->fetchAssociative('SELECT u.*, r.code AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE '.$where." AND u.anonymized_at IS NULL AND (NOT u.development_only OR ? = 'dev')", [$value, $this->environment]);
        if (!$row) { throw new UserNotFoundException(); }
        $stores = $this->db->fetchFirstColumn('SELECT s.id FROM stores s JOIN user_stores us ON us.store_id = s.id WHERE us.user_id = ? AND s.active ORDER BY s.id', [$row['id']]);
        return new StockUser($row['id'], $row['email'], $row['name'], $row['role'], (bool) $row['active'], $row['password_hash'], (string) $row['auth_version'], $row['timezone'], (new \DateTimeImmutable($row['created_at']))->format(DATE_RFC3339), $stores);
    }

    public function supportsClass(string $class): bool { return $class === StockUser::class; }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof StockUser) { throw new UnsupportedUserException(); }
        $this->db->executeStatement('UPDATE users SET password_hash = ?, updated_at = clock_timestamp() WHERE id = ? AND password_hash = ?', [$newHashedPassword, $user->id, $user->getPassword()]);
        $user->replacePassword($newHashedPassword);
    }
}
