<?php

declare(strict_types=1);

namespace App\Administration;

use App\Api\ApiProblem;
use App\Api\IdempotencyKey;
use App\Api\OpenApiContract;
use App\Api\Pagination;
use App\Security\StockUser;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserAdministration
{
    private const ROLES = ['ADMIN' => 1, 'MANAGER' => 2, 'USER' => 3];
    private const SELECT = "SELECT u.id, u.email, u.name, r.code AS role, u.active, u.timezone, u.created_at,
        u.anonymized_at, u.development_only,
        COALESCE((SELECT json_agg(us.store_id ORDER BY us.store_id) FROM user_stores us JOIN stores s ON s.id = us.store_id WHERE us.user_id = u.id AND s.active), '[]'::json) AS store_ids
        FROM users u JOIN roles r ON r.id = u.role_id";

    public function __construct(private readonly Connection $db, private readonly UserPasswordHasherInterface $hasher,
        private readonly OpenApiContract $contract, private readonly IdempotencyKey $keys,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
        #[Autowire('%kernel.secret%')] private readonly string $secret) {}

    private function authorize(StockUser $actor): void
    {
        $valid = $this->db->fetchOne("SELECT count(*) FROM users u JOIN roles r ON r.id = u.role_id
            WHERE u.id = ? AND u.active AND u.anonymized_at IS NULL AND r.code = 'ADMIN' AND u.auth_version = ?
            AND (NOT u.development_only OR ? = 'dev')", [$actor->id, $actor->authVersion, $this->environment]);
        if (!(bool) $valid) { throw new ApiProblem(403, 'administrator_required', 'Cette opération est réservée à un administrateur actif.'); }
    }

    public function listing(StockUser $actor, array $filters): array
    {
        $this->authorize($actor);
        $where = ['(NOT u.development_only OR ? = \'dev\')'];
        $parameters = [$this->environment];
        if (isset($filters['role'])) { $where[] = 'r.code = ?'; $parameters[] = $filters['role']; }
        if (isset($filters['status'])) { $where[] = $filters['status'] === 'active' ? 'u.active' : 'NOT u.active'; }
        $sql = ' WHERE '.implode(' AND ', $where);
        $sort = ['name' => 'u.name', 'email' => 'u.email', 'created_at' => 'u.created_at'][$filters['sort'] ?? 'name'] ?? null;
        if ($sort === null || !in_array($filters['order'] ?? 'asc', ['asc', 'desc'], true)) { throw new ApiProblem(422, 'invalid_query', 'Tri invalide.'); }
        $pagination = new Pagination($filters['page'] ?? 1, $filters['limit'] ?? 25);
        $query = str_replace('SELECT u.id,', 'SELECT count(*) OVER() AS total_count, u.id,', self::SELECT);
        $rows = $this->db->fetchAllAssociative($query.$sql.' ORDER BY '.$sort.' '.($filters['order'] ?? 'asc').' NULLS LAST, u.id ASC LIMIT '.$pagination->limit.' OFFSET '.$pagination->offset(), $parameters);
        $total = $rows ? (int) $rows[0]['total_count'] : (int) $this->db->fetchOne('SELECT count(*) FROM users u JOIN roles r ON r.id = u.role_id'.$sql, $parameters);
        return $pagination->response(array_map($this->resource(...), $rows), $total);
    }

    public function get(StockUser $actor, string $id): array
    {
        $this->authorize($actor);
        return ['data' => $this->resource($this->row($id))];
    }

    public function storeOptions(StockUser $actor): array
    {
        $this->authorize($actor);
        return $this->db->fetchAllAssociative('SELECT id, code, name FROM stores WHERE active ORDER BY name, id');
    }

    public function create(StockUser $actor, \stdClass $body, string $key): array
    {
        $this->validate($body, 'UserCreate');
        if (!preg_match('/^[A-Za-z0-9._:-]{8,160}$/D', $key)) { throw new ApiProblem(422, 'invalid_idempotency_key', 'Clé de création invalide.'); }
        $fingerprint = hash_hmac('sha256', $this->keys->fingerprint($actor->id, 'POST', '/api/v1/users', $body), $this->secret);
        try {
            return $this->db->transactional(function () use ($actor, $body, $key, $fingerprint): array {
                $this->db->executeStatement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
                $this->authorize($actor);
                $previous = $this->db->fetchAssociative('SELECT request_fingerprint, response FROM user_creation_requests WHERE actor_id = ? AND request_key = ?', [$actor->id, $key]);
                if ($previous) {
                    if (!hash_equals($previous['request_fingerprint'], $fingerprint)) { throw new ApiProblem(409, 'idempotency_conflict', 'Cette clé a déjà servi pour une autre création.'); }
                    return json_decode($previous['response'], true, flags: JSON_THROW_ON_ERROR);
                }
                $this->validateStores($body->store_ids);
                $hash = $this->hasher->hashPassword(new StockUser('', $body->email, $body->name, $body->role, true, '', '0', 'Europe/Paris', ''), $body->password);
                $developmentOnly = (bool) $this->db->fetchOne('SELECT development_only FROM users WHERE id = ?', [$actor->id]);
                $id = $this->db->fetchOne('INSERT INTO users (email, name, password_hash, role_id, development_only) VALUES (?, ?, ?, ?, ?) RETURNING id',
                    [strtolower(trim($body->email)), trim($body->name), $hash, self::ROLES[$body->role], $developmentOnly],
                    [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::BOOLEAN]);
                $this->assignStores($id, $body->store_ids);
                $response = ['data' => $this->resource($this->row($id))];
                $this->audit($actor, $id, 'user.created', ['name', 'email', 'role', 'store_ids', 'active']);
                $this->db->executeStatement('INSERT INTO user_creation_requests (actor_id, request_key, request_fingerprint, user_id, response) VALUES (?, ?, ?, ?, ?::jsonb)', [$actor->id, $key, $fingerprint, $id, json_encode($response, JSON_THROW_ON_ERROR)]);
                return $response;
            });
        } catch (UniqueConstraintViolationException) { throw new ApiProblem(409, 'email_already_used', 'Ce courriel est déjà utilisé par un compte.'); }
    }

    public function update(StockUser $actor, string $id, \stdClass $body): array
    {
        $this->validate($body, 'UserPatch');
        try {
            return $this->db->transactional(function () use ($actor, $id, $body): array {
                $this->db->executeStatement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
                $this->authorize($actor);
                $before = $this->row($id);
                $id = $before['id'];
                if ($before['anonymized_at'] !== null) { throw new ApiProblem(409, 'anonymized_account', 'Un compte anonymisé ne peut pas être modifié ou réactivé.'); }
                $current = $this->resource($before);
                $next = array_replace($current, get_object_vars($body));
                if (isset($body->email)) { $next['email'] = strtolower(trim($body->email)); }
                if (isset($body->name)) { $next['name'] = trim($body->name); }
                $next['store_ids'] = array_map('strtolower', $next['store_ids']);
                if ($id === $actor->id && !$next['active']) { throw new ApiProblem(409, 'self_deactivation', 'Vous ne pouvez pas désactiver votre propre compte.'); }
                if ($before['active'] && $before['role'] === 'ADMIN' && (!$next['active'] || $next['role'] !== 'ADMIN')) {
                    $predicate = $before['development_only'] ? "(NOT development_only OR ? = 'dev')" : 'NOT development_only';
                    $params = $before['development_only'] ? [$id, $this->environment] : [$id];
                    $others = $this->db->fetchOne('SELECT count(*) FROM users WHERE id <> ? AND active AND role_id = 1 AND anonymized_at IS NULL AND '.$predicate, $params);
                    if (!(bool) $others) { throw new ApiProblem(409, 'last_administrator', 'Conservez au moins un administrateur actif avant cette modification.'); }
                }
                if (isset($body->store_ids)) { $this->validateStores($body->store_ids); }
                $changed = [];
                foreach (get_object_vars($body) as $field => $value) {
                    if ($field === 'store_ids') {
                        $a = $current[$field]; $b = $next[$field]; sort($a); sort($b);
                        if ($a !== $b) { $changed[] = $field; }
                    } elseif ($current[$field] !== $next[$field]) { $changed[] = $field; }
                }
                if ($changed !== []) {
                    $this->db->executeStatement('UPDATE users SET email = ?, name = ?, role_id = ?, active = ?, auth_version = auth_version + 1, updated_at = clock_timestamp() WHERE id = ?',
                        [$next['email'], $next['name'], self::ROLES[$next['role']], $next['active'], $id], [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::STRING]);
                    if (in_array('store_ids', $changed, true)) { $this->assignStores($id, $next['store_ids']); }
                    $action = in_array('active', $changed, true) ? ($next['active'] ? 'user.reactivated' : 'user.deactivated') : 'user.updated';
                    $this->audit($actor, $id, $action, $changed);
                }
                return ['data' => $this->resource($this->row($id))];
            });
        } catch (UniqueConstraintViolationException) { throw new ApiProblem(409, 'email_already_used', 'Ce courriel est déjà utilisé par un compte.'); }
    }

    private function validate(\stdClass $body, string $schema): void
    {
        foreach (['name', 'email', 'password'] as $field) {
            if (isset($body->$field) && is_string($body->$field) && (!preg_match('//u', $body->$field) || str_contains($body->$field, "\0"))) {
                throw new ApiProblem(422, 'invalid_text', 'Les champs texte doivent être en UTF-8 et ne pas contenir de caractère nul.');
            }
        }
        if (!$this->contract->valid($body, ['$ref' => '#/components/schemas/'.$schema])
            || (isset($body->name) && trim($body->name) === '')
            || (isset($body->email) && !filter_var($body->email, FILTER_VALIDATE_EMAIL))) {
            throw new ApiProblem(422, 'validation_failed', 'Vérifiez le courriel, le nom, les champs obligatoires et les valeurs autorisées.');
        }
    }

    private function validateStores(array $ids): void
    {
        if (count(array_unique(array_map('strtolower', $ids))) !== count($ids)) {
            throw new ApiProblem(422, 'duplicate_store', 'Un magasin ne peut être affecté qu’une fois.');
        }
        foreach ($ids as $id) {
            if (!$this->db->fetchOne('SELECT id FROM stores WHERE id = ? AND active FOR SHARE', [$id])) {
                throw new ApiProblem(422, 'invalid_store', 'Une affectation désigne un magasin absent ou inactif.');
            }
        }
    }

    private function assignStores(string $id, array $stores): void
    {
        $this->db->executeStatement('DELETE FROM user_stores WHERE user_id = ?', [$id]);
        foreach ($stores as $store) { $this->db->executeStatement('INSERT INTO user_stores (user_id, store_id) VALUES (?, ?)', [$id, $store]); }
    }

    private function row(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $id)) { throw new ApiProblem(404, 'user_not_found', 'Compte introuvable.'); }
        $row = $this->db->fetchAssociative(self::SELECT." WHERE u.id = ? AND (NOT u.development_only OR ? = 'dev')", [$id, $this->environment]);
        if (!$row) { throw new ApiProblem(404, 'user_not_found', 'Compte introuvable.'); }
        return $row;
    }

    private function resource(array $row): array
    {
        return ['id' => $row['id'], 'email' => $row['email'], 'name' => $row['name'], 'role' => $row['role'], 'active' => (bool) $row['active'],
            'store_ids' => json_decode($row['store_ids'], true, flags: JSON_THROW_ON_ERROR), 'timezone' => $row['timezone'],
            'created_at' => (new \DateTimeImmutable($row['created_at']))->format(DATE_RFC3339)];
    }

    private function audit(StockUser $actor, string $id, string $action, array $changed): void
    {
        $this->db->executeStatement('INSERT INTO audit_logs (actor_id, action, target_type, target_id, context) VALUES (?, ?, ?, ?, ?::jsonb)',
            [$actor->id, $action, 'user', $id, json_encode(['changed_fields' => $changed], JSON_THROW_ON_ERROR)]);
    }
}
