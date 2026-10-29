<?php

declare(strict_types=1);

namespace App\Security;

use App\Api\ApiProblem;
use Doctrine\DBAL\Connection;

/** Authorization is checked against live database state, independently of the selected UI store. */
final class AccessPolicy
{
    public function __construct(private readonly Connection $db, private readonly AccessIdentity $identity,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.environment%')] private readonly string $environment) {}

    public function assert(StockUser $actor, Permission $permission, ?AccessTarget $target = null): void
    {
        $identity = $this->identity->requireRole($actor, $permission);
        $global = [Permission::ACCOUNTS_ADMIN, Permission::STORES_ADMIN, Permission::CATALOG_READ,
            Permission::CATALOG_ADMIN, Permission::CATALOG_DELETE, Permission::CATALOG_PROPOSE, Permission::INFORMATION_SEND];
        if (in_array($permission, $global, true)) {
            if ($target !== null) { $this->missing(); }
            return;
        }
        if ($permission === Permission::BENEFICIARIES_READ && $target === null) {
            if ($identity['role'] !== 'ADMIN' && !$this->db->fetchOne('SELECT 1 FROM user_stores us JOIN stores s ON s.id = us.store_id WHERE us.user_id = ? AND s.active LIMIT 1', [$identity['id']])) {
                throw new ApiProblem(403, 'permission_denied', 'Aucun magasin actif ne vous est affecté.');
            }
            return;
        }
        if ($target === null || !$this->uuid($target->id)) { $this->missing(); }
        $storePermissions = [Permission::STOCK_READ, Permission::ENTRY_CREATE, Permission::CONSUMPTION_CREATE, Permission::RETURN_CREATE,
            Permission::CONSUMPTION_OTHER, Permission::ADJUSTMENT_CREATE, Permission::INVENTORY_READ,
            Permission::INVENTORY_WRITE, Permission::THRESHOLD_WRITE, Permission::ALERT_READ,
            Permission::ALERT_RECIPIENTS, Permission::AUDIT_READ, Permission::STATISTICS_READ,
            Permission::DASHBOARD_READ, Permission::MOVEMENT_READ, Permission::BENEFICIARIES_READ];
        if ($target->kind === 'store' && in_array($permission, $storePermissions, true)) {
            if ($identity['role']==='USER' && in_array($permission,[Permission::CONSUMPTION_CREATE,Permission::RETURN_CREATE],true)) { $this->activeStore($target->id); }
            else { $this->store($identity, $target->id, $permission->writesStock()); }
            return;
        }
        if ($target->kind === 'transfer' && $permission === Permission::TRANSFER_CREATE && $target->otherId !== null && $this->uuid($target->otherId)) {
            $this->store($identity, $target->id, true); $this->store($identity, $target->otherId, true);
            return;
        }
        if ($target->kind === 'proposal' && $permission === Permission::PROPOSAL_READ) {
            $row = $this->db->fetchAssociative('SELECT proposed_by FROM article_proposals WHERE id = ?', [$target->id]);
            if (!$row || ($identity['role'] !== 'ADMIN' && $row['proposed_by'] !== $identity['id'])) { $this->missing(); }
            return;
        }
        if ($target->kind === 'consumption' && in_array($permission, [Permission::CONSUMPTION_READ, Permission::RETURN_CREATE], true)) {
            $row = $this->db->fetchAssociative("SELECT source_store_id, consumer_id FROM stock_movements WHERE id = ? AND type = 'consumption'", [$target->id]);
            if (!$row) { $this->missing(); }
            if ($identity['role'] === 'USER') {
                if ($row['consumer_id'] !== $identity['id']) { $this->missing(); }
                // Personal reads and returns survive assignment removal, but require an active origin.
                $this->activeStore($row['source_store_id']);
                return;
            }
            $this->store($identity, $row['source_store_id'], $permission->writesStock());
            return;
        }
        if ($target->kind === 'movement' && in_array($permission, [Permission::MOVEMENT_READ, Permission::MOVEMENT_REVERSE], true)) {
            $row = $this->db->fetchAssociative('SELECT source_store_id, destination_store_id FROM stock_movements WHERE id = ?', [$target->id]);
            if (!$row) { $this->missing(); }
            $stores = array_values(array_filter($row));
            if ($permission === Permission::MOVEMENT_REVERSE) {
                foreach ($stores as $store) { $this->store($identity, $store, true); }
                return;
            }
            if ($identity['role'] !== 'ADMIN') { foreach ($stores as $store) { $this->activeStore($store); } }
            foreach ($stores as $store) { if ($this->canStore($identity, $store, false)) { return; } }
            $this->missing();
        }
        $resources = ['inventory' => ['inventories', [Permission::INVENTORY_READ, Permission::INVENTORY_WRITE]],
            'alert' => ['stock_alerts', [Permission::ALERT_READ]], 'audit' => ['audit_logs', [Permission::AUDIT_READ]]];
        if (isset($resources[$target->kind]) && in_array($permission, $resources[$target->kind][1], true)) {
            $row = $this->db->fetchAssociative('SELECT store_id FROM '.$resources[$target->kind][0].' WHERE id = ?', [$target->id]);
            if (!$row) { $this->missing(); }
            if ($row['store_id'] === null && $identity['role'] === 'ADMIN') { return; }
            if ($row['store_id'] === null) { $this->missing(); }
            $this->store($identity, $row['store_id'], $permission->writesStock());
            return;
        }
        $this->missing();
    }

    /** Validate the actor, actual store and beneficiary before a consumption write. */
    public function assertConsumption(StockUser $actor, string $storeId, string $consumerId): void
    {
        $this->assert($actor, $consumerId === $actor->id ? Permission::CONSUMPTION_CREATE : Permission::CONSUMPTION_OTHER, AccessTarget::store($storeId));
        if (!$this->uuid($consumerId) || !$this->db->fetchOne("SELECT 1 FROM users WHERE id = ? AND active AND anonymized_at IS NULL AND (NOT development_only OR ? = 'dev')", [$consumerId, $this->environment])) {
            throw new ApiProblem(422, 'invalid_beneficiary', 'Le bénéficiaire doit être un compte actif autorisé.');
        }
    }

    public function storeScope(StockUser $actor, Permission $permission = Permission::STOCK_READ, string $alias = 's'): SqlScope
    {
        $identity = $this->identity->requireRole($actor, $permission);
        $this->alias($alias);
        if ($identity['role']==='USER' && in_array($permission,[Permission::CONSUMPTION_CREATE,Permission::RETURN_CREATE],true)) { return new SqlScope($alias.'.active'); }
        return $this->stores($identity, $alias.'.id', $permission->writesStock());
    }

    public function stockScope(StockUser $actor, string $alias = 'sl'): SqlScope
    {
        $identity = $this->identity->requireRole($actor, Permission::STOCK_READ); $this->alias($alias);
        return $this->stores($identity, $alias.'.store_id', false);
    }

    public function consumptionScope(StockUser $actor, Permission $permission = Permission::CONSUMPTION_READ, string $alias = 'm'): SqlScope
    {
        if (!in_array($permission, [Permission::CONSUMPTION_READ, Permission::STATISTICS_READ], true)) { throw new \InvalidArgumentException('Invalid consumption permission.'); }
        $identity = $this->identity->requireRole($actor, $permission); $this->alias($alias);
        if ($identity['role'] === 'USER') {
            return new SqlScope("$alias.type = 'consumption' AND $alias.consumer_id = ? AND EXISTS (SELECT 1 FROM stores access_store WHERE access_store.id = $alias.source_store_id AND access_store.active)", [$identity['id']]);
        }
        $scope = $this->stores($identity, $alias.'.source_store_id', false);
        return new SqlScope("$alias.type = 'consumption' AND (".$scope->condition.')', $scope->parameters);
    }

    public function movementScope(StockUser $actor, string $alias = 'm'): SqlScope
    {
        $identity = $this->identity->requireRole($actor, Permission::MOVEMENT_READ); $this->alias($alias);
        $source = $this->stores($identity, $alias.'.source_store_id', false);
        $destination = $this->stores($identity, $alias.'.destination_store_id', false);
        $active = $identity['role'] === 'ADMIN' ? '' : " AND NOT EXISTS (SELECT 1 FROM stores inactive_store WHERE NOT inactive_store.active AND inactive_store.id IN ($alias.source_store_id, $alias.destination_store_id))";
        return new SqlScope('((' . $source->condition . ') OR (' . $destination->condition . '))' . $active, [...$source->parameters, ...$destination->parameters]);
    }

    public function resourceScope(StockUser $actor, Permission $permission, string $alias): SqlScope
    {
        if (!in_array($permission, [Permission::INVENTORY_READ, Permission::ALERT_READ, Permission::AUDIT_READ], true)) { throw new \InvalidArgumentException('Invalid resource scope.'); }
        $identity = $this->identity->requireRole($actor, $permission); $this->alias($alias);
        if ($permission === Permission::AUDIT_READ && $identity['role'] === 'ADMIN') { return new SqlScope('TRUE'); }
        return $this->stores($identity, $alias.'.store_id', false);
    }

    public function proposalScope(StockUser $actor, string $alias = 'p'): SqlScope
    {
        $identity = $this->identity->requireRole($actor, Permission::PROPOSAL_READ); $this->alias($alias);
        return $identity['role'] === 'ADMIN' ? new SqlScope('TRUE') : new SqlScope($alias.'.proposed_by = ?', [$identity['id']]);
    }

    private function stores(array $identity, string $column, bool $write): SqlScope
    {
        $active = $write || $identity['role'] !== 'ADMIN' ? ' AND access_store.active' : '';
        $assignment = $identity['role'] === 'ADMIN' ? '' : ' AND EXISTS (SELECT 1 FROM user_stores access_assignment WHERE access_assignment.store_id = access_store.id AND access_assignment.user_id = ?)';
        return new SqlScope("EXISTS (SELECT 1 FROM stores access_store WHERE access_store.id = $column$active$assignment)", $identity['role'] === 'ADMIN' ? [] : [$identity['id']]);
    }

    private function canStore(array $identity, string $id, bool $write): bool
    {
        $scope = $this->stores($identity, 's.id', $write);
        return (bool) $this->db->fetchOne('SELECT 1 FROM stores s WHERE s.id = ? AND ('.$scope->condition.')', [$id, ...$scope->parameters]);
    }
    private function store(array $identity, string $id, bool $write): void { if (!$this->canStore($identity, $id, $write)) { $this->missing(); } }
    private function activeStore(string $id): void { if (!$this->db->fetchOne('SELECT 1 FROM stores WHERE id = ? AND active', [$id])) { $this->missing(); } }
    private function uuid(string $id): bool { return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $id) === 1; }
    private function alias(string $alias): void { if (!preg_match('/^[a-z][a-z0-9_]*$/iD', $alias)) { throw new \InvalidArgumentException('Invalid SQL alias.'); } }
    private function missing(): never { throw new ApiProblem(404, 'resource_not_found', 'Ressource introuvable.'); }
}
