<?php

declare(strict_types=1);

namespace App\Administration;

use App\Access\AccessDirectory;
use App\Api\ApiProblem;
use App\Api\IdempotencyKey;
use App\Api\OpenApiContract;
use App\Security\AccessIdentity;
use App\Security\Permission;
use App\Security\StockUser;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Store changes never delete assignments, quantities, movements or inventories. */
final class StoreAdministration
{
    public function __construct(private readonly Connection $db, private readonly AccessIdentity $identity,
        private readonly AccessDirectory $directory, private readonly OpenApiContract $contract,
        private readonly IdempotencyKey $keys, #[Autowire('%kernel.secret%')] private readonly string $secret) {}

    public function get(StockUser $actor, string $id): array
    {
        $this->identity->requireRole($actor, Permission::STORES_ADMIN);
        return $this->directory->store($actor, $id);
    }

    public function create(StockUser $actor, \stdClass $body, string $key): array
    {
        $this->identity->requireRole($actor, Permission::STORES_ADMIN);
        $this->validate($body, 'StoreCreate');
        if (!preg_match('/^[A-Za-z0-9._:-]{8,160}$/D', $key)) { throw new ApiProblem(422, 'invalid_idempotency_key', 'Clé de création invalide.'); }
        $fingerprint = hash_hmac('sha256', $this->keys->fingerprint($actor->id, 'POST', '/api/v1/stores', $body), $this->secret);
        try {
            return $this->db->transactional(function () use ($actor, $body, $key, $fingerprint): array {
                $this->lockAndAuthorize($actor);
                $previous = $this->db->fetchAssociative('SELECT request_fingerprint, response FROM store_creation_requests WHERE actor_id = ? AND request_key = ?', [$actor->id, $key]);
                if ($previous) {
                    if (!hash_equals($previous['request_fingerprint'], $fingerprint)) { throw new ApiProblem(409, 'idempotency_conflict', 'Cette clé a déjà servi pour une autre création.'); }
                    return json_decode($previous['response'], true, flags: JSON_THROW_ON_ERROR);
                }
                $id = $this->db->fetchOne('INSERT INTO stores (code, name, description, address, active) VALUES (?, ?, ?, ?, ?) RETURNING id',
                    [trim($body->code), trim($body->name), $body->description ?? '', $this->address($body->address ?? null), $body->active ?? true],
                    [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::BOOLEAN]);
                $response = $this->directory->store($actor, $id);
                $this->audit($actor, $id, 'store.created', ['code', 'name', 'description', 'address', 'active']);
                $this->db->executeStatement('INSERT INTO store_creation_requests (actor_id, request_key, request_fingerprint, store_id, response) VALUES (?, ?, ?, ?, ?::jsonb)', [$actor->id, $key, $fingerprint, $id, json_encode($response, JSON_THROW_ON_ERROR)]);
                return $response;
            });
        } catch (UniqueConstraintViolationException) { throw new ApiProblem(409, 'store_code_already_used', 'Ce code de magasin est déjà utilisé, y compris par un magasin désactivé.'); }
    }

    public function update(StockUser $actor, string $id, \stdClass $body): array
    {
        $this->identity->requireRole($actor, Permission::STORES_ADMIN);
        $this->validate($body, 'StorePatch');
        return $this->db->transactional(function () use ($actor, $id, $body): array {
            $this->lockAndAuthorize($actor);
            $before = $this->directory->store($actor, $id)['data'];
            // Shared lock order: users then stores. Future inventory transitions lock their store first.
            $this->db->fetchOne('SELECT id FROM stores WHERE id = ? FOR UPDATE', [$id]);
            $next = array_replace($before, get_object_vars($body));
            $next['name'] = trim($next['name']); $next['address'] = $this->address($next['address']);
            if ($before['active'] && !$next['active'] && $this->db->fetchOne("SELECT 1 FROM inventories WHERE store_id = ? AND status IN ('in_progress', 'pending_validation') LIMIT 1", [$id])) {
                throw new ApiProblem(409, 'inventory_in_progress', 'Terminez ou annulez l’inventaire avant de désactiver ce magasin.');
            }
            $changed = [];
            foreach (get_object_vars($body) as $field => $value) { if ($before[$field] !== $next[$field]) { $changed[] = $field; } }
            if ($changed !== []) {
                $this->db->executeStatement('UPDATE stores SET name = ?, description = ?, address = ?, active = ?, updated_at = clock_timestamp() WHERE id = ?',
                    [$next['name'], $next['description'], $next['address'], $next['active'], $id],
                    [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::STRING]);
                $action = in_array('active', $changed, true) ? ($next['active'] ? 'store.reactivated' : 'store.archived') : 'store.updated';
                $this->audit($actor, $id, $action, $changed);
            }
            return $this->directory->store($actor, $id);
        });
    }

    private function lockAndAuthorize(StockUser $actor): void
    {
        // Same order as account administration; serializes replay keys and protects actor revocation.
        $this->db->executeStatement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
        $this->identity->requireRole($actor, Permission::STORES_ADMIN);
        $this->db->executeStatement('LOCK TABLE stores IN SHARE ROW EXCLUSIVE MODE');
    }
    private function validate(\stdClass $body, string $schema): void
    {
        foreach (get_object_vars($body) as $value) {
            if (is_string($value) && (!preg_match('//u', $value) || str_contains($value, "\0"))) {
                throw new ApiProblem(422, 'invalid_text', 'Les champs texte doivent être en UTF-8 et sans caractère nul.');
            }
        }
        if (!$this->contract->valid($body, ['$ref' => '#/components/schemas/'.$schema])
            || (isset($body->code) && trim($body->code) === '') || (isset($body->name) && trim($body->name) === '')) {
            throw new ApiProblem(422, 'validation_failed', 'Vérifiez les champs obligatoires et les valeurs autorisées.');
        }
    }
    private function address(?string $address): ?string { return $address === null || trim($address) === '' ? null : trim($address); }
    private function audit(StockUser $actor, string $id, string $action, array $fields): void
    {
        $this->db->executeStatement("INSERT INTO audit_logs (actor_id, store_id, action, target_type, target_id, context) VALUES (?, ?, ?, 'store', ?, ?::jsonb)", [$actor->id, $id, $action, $id, json_encode(['fields' => $fields], JSON_THROW_ON_ERROR)]);
    }
}
