<?php

declare(strict_types=1);

namespace App\History;

use App\Api\ApiProblem;
use App\Api\Pagination;
use App\Security\AccessPolicy;
use App\Security\AccessTarget;
use App\Security\Permission;
use App\Security\StockUser;
use Doctrine\DBAL\Connection;

/** Only the redacted projection may be filtered, counted or returned to a caller. */
final class MovementDirectory
{
    public function __construct(private readonly Connection $db, private readonly AccessPolicy $policy, private readonly MovementDates $dates) {}

    public function listing(StockUser $actor, array $filters = []): array
    {
        return $this->read($actor, $filters);
    }

    public function get(StockUser $actor, string $id): array
    {
        $this->policy->assert($actor, Permission::MOVEMENT_READ, AccessTarget::movement($id));
        $result = $this->read($actor, ['id' => $id]);
        if ($result['data'] === []) { throw new ApiProblem(404, 'resource_not_found', 'Ressource introuvable.'); }
        return ['data' => $result['data'][0]];
    }

    public function people(StockUser $actor): array
    {
        [$cte, $parameters] = $this->projection($actor);
        return $this->db->fetchAllAssociative($cte.' SELECT DISTINCT id,name FROM (SELECT author_id id,author_name name FROM visible UNION SELECT consumer_id,consumer_name FROM visible) people WHERE id IS NOT NULL ORDER BY name,id', $parameters);
    }

    private function projection(StockUser $actor): array
    {
        $scope = $this->policy->movementScope($actor);
        $stores = $this->policy->storeScope($actor, Permission::MOVEMENT_READ, 's');
        // Fully visible IDs are also used to protect operation links from exposing another perimeter.
        $sql = 'WITH allowed_stores AS (SELECT s.id FROM stores s WHERE '.$stores->condition.'), scoped AS (
            SELECT m.*,a.sku article_sku,a.name article_name,ss.name source_store_name,ds.name destination_store_name,
                CASE WHEN au.anonymized_at IS NULL THEN au.name ELSE \'Compte anonymisé\' END author_name,
                coalesce(m.consumer_id,o.consumer_id,p.consumer_id) beneficiary_id,
                CASE WHEN cu.anonymized_at IS NULL THEN cu.name ELSE \'Compte anonymisé\' END consumer_name,
                ((m.source_store_id IS NULL OR m.source_store_id IN (SELECT id FROM allowed_stores)) AND
                 (m.destination_store_id IS NULL OR m.destination_store_id IN (SELECT id FROM allowed_stores))) complete
            FROM stock_movements m JOIN articles a ON a.id=m.article_id
            LEFT JOIN stores ss ON ss.id=m.source_store_id LEFT JOIN stores ds ON ds.id=m.destination_store_id
            LEFT JOIN stock_movements o ON o.id=m.related_movement_id LEFT JOIN stock_movements p ON p.id=o.related_movement_id
            JOIN users au ON au.id=m.author_id LEFT JOIN users cu ON cu.id=coalesce(m.consumer_id,o.consumer_id,p.consumer_id)
            WHERE '.$scope->condition.'), visible AS (
            SELECT id,type,article_id,article_sku,article_name,quantity,created_at,NOT complete restricted,
                CASE WHEN source_store_id IN (SELECT id FROM allowed_stores) THEN source_store_id END source_store_id,
                CASE WHEN source_store_id IN (SELECT id FROM allowed_stores) THEN source_store_name END source_store_name,
                CASE WHEN destination_store_id IN (SELECT id FROM allowed_stores) THEN destination_store_id END destination_store_id,
                CASE WHEN destination_store_id IN (SELECT id FROM allowed_stores) THEN destination_store_name END destination_store_name,
                CASE WHEN complete THEN author_id END author_id,CASE WHEN complete THEN author_name END author_name,
                CASE WHEN complete THEN beneficiary_id END consumer_id,CASE WHEN complete THEN consumer_name END consumer_name,
                CASE WHEN complete AND related_movement_id IN (SELECT id FROM scoped WHERE complete) THEN related_movement_id END related_movement_id,
                CASE WHEN complete THEN inventory_id END inventory_id,CASE WHEN complete THEN reason END reason,
                CASE WHEN complete THEN reference END reference,CASE WHEN complete THEN comment END comment,
                CASE WHEN complete THEN (SELECT r.id FROM scoped r WHERE r.complete AND r.type = \'reversal\' AND r.related_movement_id = scoped.id) END reversed_by,
                CASE WHEN complete THEN (SELECT c.replacement_id FROM movement_corrections c WHERE c.original_id = scoped.id AND c.replacement_id IN (SELECT id FROM scoped WHERE complete)) END replacement_id,
                CASE WHEN complete THEN (SELECT c.original_id FROM movement_corrections c WHERE c.replacement_id = scoped.id AND c.original_id IN (SELECT id FROM scoped WHERE complete)) END corrects_id
            FROM scoped)';
        return [$sql, [...$stores->parameters, ...$scope->parameters]];
    }

    private function read(StockUser $actor, array $filters): array
    {
        [$sql, $parameters] = $this->projection($actor);
        if (isset($filters['store_id'])) { $this->policy->assert($actor, Permission::MOVEMENT_READ, AccessTarget::store($filters['store_id'])); }
        $where = ['TRUE'];
        foreach (['id','article_id','type','author_id','consumer_id'] as $key) {
            if (isset($filters[$key])) { $where[] = $key.' = ?'; $parameters[] = $filters[$key]; }
        }
        if (isset($filters['store_id'])) { $where[] = '(source_store_id = ? OR destination_store_id = ?)'; array_push($parameters, $filters['store_id'], $filters['store_id']); }
        foreach ($this->dates->bounds($filters, $actor->timezone) as $key => $timestamp) {
            $where[] = 'created_at '.($key === 'date_from' ? '>=' : '<').' to_timestamp(?)'; $parameters[] = $timestamp;
        }
        $sort = $filters['sort'] ?? 'created_at'; $order = $filters['order'] ?? 'asc';
        if (!in_array($sort, ['created_at','quantity'], true) || !in_array($order, ['asc','desc'], true)) { throw new ApiProblem(422, 'invalid_query', 'Tri invalide.'); }
        $pagination = new Pagination($filters['page'] ?? 1, $filters['limit'] ?? 25);
        $ordering = $sort.' '.strtoupper($order).',id '.strtoupper($order);
        $result = $this->db->fetchAssociative($sql.', filtered AS (SELECT * FROM visible WHERE '.implode(' AND ', $where).'), page AS (SELECT * FROM filtered ORDER BY '.$ordering.' LIMIT '.$pagination->limit.' OFFSET '.$pagination->offset().') SELECT (SELECT count(*) FROM filtered) total,coalesce((SELECT json_agg(page ORDER BY '.$ordering.') FROM page),\'[]\'::json) rows', $parameters);
        $rows = json_decode($result['rows'], true, flags: JSON_THROW_ON_ERROR);
        foreach ($rows as &$row) { $row['quantity'] = (int) $row['quantity']; $row['created_at'] = (new \DateTimeImmutable($row['created_at']))->format(DATE_RFC3339); } unset($row);
        return $pagination->response($rows, (int) $result['total']);
    }
}
