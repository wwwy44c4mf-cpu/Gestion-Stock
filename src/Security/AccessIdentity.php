<?php

declare(strict_types=1);

namespace App\Security;

use App\Api\ApiProblem;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Fresh server-side identity; never trust a role or store list supplied by a caller. */
final class AccessIdentity
{
    public function __construct(private readonly Connection $db,
        #[Autowire('%kernel.environment%')] private readonly string $environment) {}

    public function read(StockUser $actor): array
    {
        $row = $this->db->fetchAssociative("SELECT u.id, r.code AS role, u.auth_version, u.password_hash FROM users u JOIN roles r ON r.id = u.role_id
            WHERE u.id = ? AND u.active AND u.anonymized_at IS NULL AND (NOT u.development_only OR ? = 'dev')", [$actor->id, $this->environment]);
        if (!$row || (string) $row['auth_version'] !== $actor->authVersion || $row['role'] !== $actor->role || !hash_equals($row['password_hash'], $actor->getPassword())) {
            throw new ApiProblem(401, 'authentication_required', 'Votre accès a changé. Reconnectez-vous.');
        }
        return ['id' => $row['id'], 'role' => $row['role']];
    }

    public function requireRole(StockUser $actor, Permission $permission): array
    {
        $identity = $this->read($actor);
        if (!in_array($identity['role'], $permission->roles(), true)) {
            throw new ApiProblem(403, 'permission_denied', 'Votre rôle ne permet pas cette opération.');
        }
        return $identity;
    }
}
