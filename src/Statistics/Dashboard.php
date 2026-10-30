<?php

declare(strict_types=1);

namespace App\Statistics;

use App\Api\ApiProblem;
use App\History\MovementDates;
use App\Security\{AccessPolicy, AccessTarget, Permission, StockUser};
use Doctrine\DBAL\Connection;

/** Read-only indicators, scoped before aggregation. Current stock and period activity stay distinct. */
final class Dashboard
{
    public function __construct(private readonly Connection $db, private readonly AccessPolicy $policy, private readonly MovementDates $dates) {}

    public function storeOptions(StockUser $actor): array
    {
        $stores = $this->policy->storeScope($actor);
        $consumptions = $this->policy->consumptionScope($actor);
        return $this->db->fetchAllAssociative('SELECT s.id,s.code,s.name,s.active FROM stores s WHERE s.deleted_at IS NULL AND (('.$stores->condition.') OR (s.active AND EXISTS (SELECT 1 FROM stock_movements m WHERE m.source_store_id=s.id AND ('.$consumptions->condition.')))) ORDER BY s.name,s.id', [...$stores->parameters, ...$consumptions->parameters]);
    }

    public function read(StockUser $actor, array $filters = [], bool $personal = false): array
    {
        foreach (['store_id', 'category_id'] as $key) {
            if (isset($filters[$key])) {
                if (!is_string($filters[$key]) || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di', $filters[$key])) { throw new ApiProblem(422, 'invalid_query', 'Filtre invalide.'); }
                $filters[$key] = strtolower($filters[$key]);
            }
        }
        $consumption = $this->policy->consumptionScope($actor, Permission::STATISTICS_READ);
        // The personal projection is reserved to users; it never queries stock, alerts or the general journal.
        $personal = $personal && $actor->role === 'USER';
        $stores = $personal ? null : $this->policy->storeScope($actor, Permission::DASHBOARD_READ);
        if (isset($filters['store_id'])) {
            $own = $personal && $this->db->fetchOne('SELECT 1 FROM stock_movements m WHERE m.source_store_id=? AND ('.$consumption->condition.') LIMIT 1', [$filters['store_id'], ...$consumption->parameters]);
            if (!$own) { $this->policy->assert($actor, Permission::STOCK_READ, AccessTarget::store($filters['store_id'])); }
        }
        $bounds = $this->dates->bounds($filters, $actor->timezone);
        $eventWhere = ['TRUE']; $eventParams = [];
        foreach (['store_id', 'category_id'] as $key) {
            if (isset($filters[$key])) { $eventWhere[] = 'e.'.$key.'=?'; $eventParams[] = $filters[$key]; }
        }
        foreach ($bounds as $key => $instant) { $eventWhere[] = 'e.created_at '.($key === 'date_from' ? '>=' : '<').' to_timestamp(?)'; $eventParams[] = $instant; }
        $sql = "WITH origins AS (SELECT m.* FROM stock_movements m WHERE (".$consumption->condition.") AND NOT EXISTS (SELECT 1 FROM stock_movements r WHERE r.type='reversal' AND r.related_movement_id=m.id)),
            activity AS (SELECT m.article_id,m.source_store_id store_id,m.created_at,m.quantity::numeric consumed,0::numeric returned,1::bigint movement_count,0::bigint return_count FROM origins m
            UNION ALL SELECT m.article_id,m.source_store_id,r.created_at,0::numeric,r.quantity::numeric,0::bigint,1::bigint FROM origins m JOIN stock_movements r ON r.related_movement_id=m.id AND r.type='return'
            WHERE NOT EXISTS (SELECT 1 FROM stock_movements u WHERE u.type='reversal' AND u.related_movement_id=r.id)),
            events AS (SELECT e.*,a.category_id FROM activity e JOIN articles a ON a.id=e.article_id),
            consumed AS (SELECT coalesce(sum(consumed),0)::text consumed_quantity,coalesce(sum(returned),0)::text returned_quantity,'unit'::text unit_code,
            coalesce(sum(movement_count),0)::bigint movement_count,coalesce(sum(return_count),0)::bigint return_count FROM events e WHERE ".implode(' AND ', $eventWhere).')';
        $params = [...$consumption->parameters, ...$eventParams];
        if ($personal) {
            $row = $this->db->fetchAssociative($sql.' SELECT row_to_json(consumed) consumption FROM consumed', $params);
            return ['consumption' => json_decode($row['consumption'], true, flags: JSON_THROW_ON_ERROR)];
        }
        $storeWhere = [$stores->condition,'s.deleted_at IS NULL']; $storeParams = $stores->parameters;
        if (isset($filters['store_id'])) { $storeWhere[] = 's.id=?'; $storeParams[] = $filters['store_id']; }
        $categoryWhere = isset($filters['category_id']) ? 'a.category_id=?' : 'TRUE';
        $categoryParams = isset($filters['category_id']) ? [$filters['category_id']] : [];
        $movement = $this->policy->movementScope($actor);
        $movementWhere = [$movement->condition]; $movementParams = $movement->parameters;
        if (isset($filters['store_id'])) { $movementWhere[] = '(m.source_store_id=? OR m.destination_store_id=?)'; array_push($movementParams, $filters['store_id'], $filters['store_id']); }
        if (isset($filters['category_id'])) { $movementWhere[] = 'a.category_id=?'; $movementParams[] = $filters['category_id']; }
        foreach ($bounds as $key => $instant) { $movementWhere[] = 'm.created_at '.($key === 'date_from' ? '>=' : '<').' to_timestamp(?)'; $movementParams[] = $instant; }
        $sql .= ', allowed_stores AS (SELECT s.id FROM stores s WHERE '.implode(' AND ', $storeWhere).'),
            catalogue AS (SELECT a.* FROM articles a WHERE '.$categoryWhere.'),
            positions AS (SELECT s.id store_id,a.id article_id,coalesce(sl.quantity,0) quantity,sl.low_threshold FROM allowed_stores s CROSS JOIN catalogue a LEFT JOIN stock_levels sl ON sl.store_id=s.id AND sl.article_id=a.id),
            visible_movements AS (SELECT m.id FROM stock_movements m JOIN articles a ON a.id=m.article_id WHERE '.implode(' AND ', $movementWhere).'),
            alerts AS (SELECT al.* FROM stock_alerts al JOIN allowed_stores s ON s.id=al.store_id JOIN catalogue a ON a.id=al.article_id WHERE al.status=\'active\')
            SELECT (SELECT count(*) FROM catalogue WHERE active) active_articles,
            (SELECT count(*) FROM positions WHERE quantity=0) out_of_stock_references,
            (SELECT count(*) FROM positions WHERE low_threshold IS NOT NULL AND quantity<=low_threshold) below_threshold_references,
            (SELECT count(*) FROM visible_movements) movements_on_period,
            (SELECT count(*) FROM alerts WHERE level=\'low\') low_alerts,
            (SELECT count(*) FROM alerts WHERE level=\'critical\') critical_alerts,
            (SELECT row_to_json(consumed) FROM consumed) consumption';
        $row = $this->db->fetchAssociative($sql, [...$params, ...$storeParams, ...$categoryParams, ...$movementParams]);
        foreach ($row as $key => &$value) { $value = $key === 'consumption' ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : (int) $value; } unset($value);
        return $row;
    }
}
