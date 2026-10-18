<?php

declare(strict_types=1);

namespace App\Stock;

use App\Api\ApiProblem;
use App\Api\IdempotencyKey;
use App\Api\OpenApiContract;
use App\Security\AccessIdentity;
use App\Security\AccessPolicy;
use App\Security\AccessTarget;
use App\Security\Permission;
use App\Security\StockUser;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Shared transactional entry point for the forthcoming movement controllers. */
final class StockMovementEngine
{
    private const MAX_QUANTITY = 9007199254740991;
    private const OPERATIONS = [
        'entry' => ['EntryCreate', Permission::ENTRY_CREATE],
        'consumption' => ['ConsumptionCreate', Permission::CONSUMPTION_CREATE],
        'transfer' => ['TransferCreate', Permission::TRANSFER_CREATE],
        'adjustment' => ['AdjustmentCreate', Permission::ADJUSTMENT_CREATE],
    ];

    public function __construct(private readonly Connection $db, private readonly AccessIdentity $identity,
        private readonly AccessPolicy $policy, private readonly OpenApiContract $contract, private readonly IdempotencyKey $keys,
        #[Autowire('%kernel.secret%')] private readonly string $secret) {}

    public function record(StockUser $actor, string $operation, \stdClass $body, string $key): array
    {
        [$schema, $permission] = self::OPERATIONS[$operation] ?? throw new ApiProblem(422, 'unsupported_movement', 'Ce type de mouvement n’est pas encore disponible.');
        $this->identity->requireRole($actor, $permission);
        $this->validate($body, $schema, $key);
        // Do not mutate the caller's object; canonical UUID casing also gives consistent replay keys.
        $body = clone $body;
        foreach (['article_id', 'store_id', 'source_store_id', 'destination_store_id', 'consumer_id'] as $field) {
            if (isset($body->$field)) { $body->$field = strtolower($body->$field); }
        }
        $fingerprint = hash_hmac('sha256', $this->keys->fingerprint($actor->id, 'POST', '/stock-engine/'.$operation, $body), $this->secret);
        return $this->db->transactional(function () use ($actor, $operation, $permission, $body, $key, $fingerprint): array {
            // Shared guards stabilize authorizations/statuses while allowing concurrent movements.
            // Administration takes an incompatible users lock first, in the same global order.
            foreach (['users', 'user_stores', 'stores', 'articles', 'inventories'] as $table) {
                $this->db->executeStatement('LOCK TABLE '.$table.' IN SHARE MODE');
            }
            $this->identity->requireRole($actor, $permission);
            $this->db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['stock-request:'.$actor->id.':'.$key]);
            [$source, $destination, $consumer] = $this->authorize($actor, $operation, $body);
            $previous = $this->db->fetchAssociative('SELECT * FROM stock_movements WHERE author_id = ? AND idempotency_key = ?', [$actor->id, $key]);
            if ($previous) {
                if ($previous['request_fingerprint'] === null || !hash_equals($previous['request_fingerprint'], $fingerprint)) {
                    throw new ApiProblem(409, 'idempotency_conflict', 'Cette clé a déjà été utilisée pour un autre contenu ou mouvement.');
                }
                return ['data' => $this->resource($previous)];
            }
            if ($operation === 'consumption') { $this->policy->assertConsumption($actor, $body->store_id, $consumer); }
            $article = $this->db->fetchAssociative('SELECT id,active,unit_code FROM articles WHERE id = ?', [$body->article_id]);
            if (!$article) { throw new ApiProblem(404, 'article_not_found', 'Article introuvable.'); }
            if ($article['unit_code'] !== 'unit' || (!$article['active'] && $operation !== 'adjustment')) {
                throw new ApiProblem(409, 'inactive_article', 'Cet article ne permet pas ce mouvement.');
            }
            $stores = array_values(array_filter([$source, $destination])); sort($stores, SORT_STRING);
            foreach ($stores as $store) {
                if ($this->db->fetchOne("SELECT 1 FROM inventories WHERE store_id = ? AND status IN ('in_progress','pending_validation')", [$store])) {
                    throw new ApiProblem(409, 'inventory_in_progress', 'Un inventaire bloque les mouvements de ce magasin.');
                }
            }
            $quantity = abs($body->quantity);
            foreach ($stores as $store) {
                // Sorting both creation and row locks avoids opposite-transfer deadlocks.
                $this->db->executeStatement('INSERT INTO stock_levels (article_id,store_id) VALUES (?,?) ON CONFLICT DO NOTHING', [$article['id'], $store]);
                $balance = $this->db->fetchAssociative('SELECT quantity,version FROM stock_levels WHERE article_id = ? AND store_id = ? FOR UPDATE', [$article['id'], $store]);
                $next = (int) $balance['quantity'] + ($store === $source ? -$quantity : $quantity);
                if ($next < 0) { throw new ApiProblem(409, 'insufficient_stock', 'Le stock disponible est insuffisant.'); }
                if ($next > self::MAX_QUANTITY || (string) $balance['version'] === (string) PHP_INT_MAX) {
                    throw new ApiProblem(409, 'stock_limit_reached', 'La limite du solde ou de sa version est atteinte.');
                }
                $this->db->executeStatement('UPDATE stock_levels SET quantity = ?,version = version + 1 WHERE article_id = ? AND store_id = ?', [$next, $article['id'], $store]);
            }
            $movement = $this->db->fetchAssociative('INSERT INTO stock_movements (type,article_id,source_store_id,destination_store_id,quantity,author_id,consumer_id,reason,reference,comment,idempotency_key,request_fingerprint) VALUES (?,?,?,?,?,?,?,?,?,?,?,?) RETURNING *', [
                $operation, $article['id'], $source, $destination, $quantity, $actor->id, $consumer,
                isset($body->reason) ? trim($body->reason) : null, $body->reference ?? null, $body->comment ?? null, $key, $fingerprint,
            ]);
            $this->db->executeStatement('INSERT INTO stock_change_events (movement_id,article_id,store_ids) VALUES (?,?,?::uuid[])', [$movement['id'], $article['id'], '{'.implode(',', $stores).'}']);
            $this->db->executeStatement('INSERT INTO audit_logs (actor_id,store_id,action,target_type,target_id,context) VALUES (?,?,?,?,?,?::jsonb)', [
                $actor->id, $source ?? $destination, 'stock.'.$operation, 'stock_movement', $movement['id'], json_encode(['type' => $operation], JSON_THROW_ON_ERROR),
            ]);
            return ['data' => $this->resource($movement)];
        });
    }

    private function authorize(StockUser $actor, string $operation, \stdClass $body): array
    {
        if ($operation === 'transfer') {
            if ($body->source_store_id === $body->destination_store_id) { throw new ApiProblem(422, 'same_store_transfer', 'Les magasins source et destination doivent être différents.'); }
            $this->policy->assert($actor, Permission::TRANSFER_CREATE, AccessTarget::transfer($body->source_store_id, $body->destination_store_id));
            return [$body->source_store_id, $body->destination_store_id, null];
        }
        if ($operation === 'consumption') {
            $consumer = $body->consumer_id ?? $actor->id;
            $this->policy->assert($actor, $consumer === $actor->id ? Permission::CONSUMPTION_CREATE : Permission::CONSUMPTION_OTHER, AccessTarget::store($body->store_id));
            return [$body->store_id, null, $consumer];
        }
        $this->policy->assert($actor, self::OPERATIONS[$operation][1], AccessTarget::store($body->store_id));
        return $operation === 'adjustment' && $body->quantity < 0 ? [$body->store_id, null, null] : [null, $body->store_id, null];
    }

    private function validate(\stdClass $body, string $schema, string $key): void
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{8,160}$/D', $key)) { throw new ApiProblem(422, 'invalid_idempotency_key', 'Clé de mouvement invalide.'); }
        foreach (get_object_vars($body) as $value) {
            if (is_string($value) && (str_contains($value, "\0") || !preg_match('//u', $value))) { throw new ApiProblem(422, 'invalid_text', 'Un texte du mouvement est invalide.'); }
        }
        if (!isset($body->quantity) || !is_int($body->quantity) || !$this->contract->valid($body, ['$ref' => '#/components/schemas/'.$schema]) || (isset($body->reason) && trim($body->reason) === '')) {
            throw new ApiProblem(422, 'invalid_movement', 'Vérifiez les champs du mouvement et la quantité entière.');
        }
    }

    public function resource(array $row): array
    {
        $result = array_intersect_key($row, array_flip(['id','type','article_id','source_store_id','destination_store_id','quantity','author_id','consumer_id','related_movement_id','inventory_id','reason','reference','comment','created_at']));
        $result['quantity'] = (int) $result['quantity'];
        $result['created_at'] = (new \DateTimeImmutable($result['created_at']))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_RFC3339);
        // Descriptive names belong to authorized presentation, not the durable replay result.
        $result['author_name'] = null; $result['consumer_name'] = null;
        return $result;
    }
}
