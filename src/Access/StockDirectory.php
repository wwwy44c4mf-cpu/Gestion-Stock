<?php

declare(strict_types=1);

namespace App\Access;

use App\Api\ApiProblem;
use App\Api\Pagination;
use App\Security\AccessPolicy;
use App\Security\AccessTarget;
use App\Security\Permission;
use App\Security\StockUser;
use Doctrine\DBAL\Connection;

/** Scope is applied before totals, filtering and pagination. Reads never create stock rows. */
final class StockDirectory
{
    public function __construct(private readonly Connection $db, private readonly AccessPolicy $policy, private readonly CatalogDirectory $catalog) {}

    public function listing(StockUser $actor, array $filters = []): array
    {
        return $this->read($actor, $filters)['page'];
    }

    public function article(StockUser $actor, string $id, array $filters = []): array
    {
        $article = $this->catalog->get($actor, 'article', $id)['data'];
        $result = $this->read($actor, [...$filters, 'article_id' => $article['id']]);
        return ['data' => ['article_id' => $article['id'], 'total_quantity' => $result['sum'], 'unit_code' => 'unit', 'stores' => $result['page']]];
    }

    public function detail(StockUser $actor, string $storeId, string $articleId): array
    {
        $this->policy->assert($actor, Permission::STOCK_READ, AccessTarget::store($storeId));
        $article = $this->catalog->get($actor, 'article', $articleId)['data'];
        $rows=$this->read($actor,['store_id'=>$storeId,'article_id'=>$article['id']])['page']['data'];
        if($rows===[]) {throw new ApiProblem(404,'resource_not_found','Ressource introuvable.');}
        return ['data'=>$rows[0]];
    }

    /** Local ledger only: no actors, beneficiaries or remote-store identifiers. */
    public function history(StockUser $actor, string $storeId, string $articleId, array $filters = []): array
    {
        $this->policy->assert($actor, Permission::MOVEMENT_READ, AccessTarget::store($storeId));
        $article = $this->catalog->get($actor, 'article', $articleId)['data'];
        $pagination = new Pagination($filters['page'] ?? 1, $filters['limit'] ?? 25);
        $where = 'article_id = ? AND (source_store_id = ? OR destination_store_id = ?)';
        $parameters = [$article['id'], $storeId, $storeId];
        $result = $this->db->fetchAssociative('WITH scoped AS (SELECT id,type,created_at,CASE WHEN source_store_id = ? THEN -quantity ELSE quantity END quantity FROM stock_movements WHERE '.$where.'), page AS (SELECT * FROM scoped ORDER BY created_at DESC,id LIMIT '.$pagination->limit.' OFFSET '.$pagination->offset().') SELECT (SELECT count(*) FROM scoped) total, coalesce((SELECT json_agg(page ORDER BY created_at DESC,id) FROM page),\'[]\'::json) rows', [$storeId, ...$parameters]);
        $total = (int) $result['total']; $rows = json_decode($result['rows'], true, flags: JSON_THROW_ON_ERROR);
        foreach ($rows as &$row) { $row['quantity'] = (int) $row['quantity']; $row['created_at'] = (new \DateTimeImmutable($row['created_at']))->format(DATE_RFC3339); } unset($row);
        return $pagination->response($rows, $total);
    }

    private function read(StockUser $actor, array $filters): array
    {
        $scope = $this->policy->storeScope($actor);
        if (isset($filters['store_id'])) { $this->policy->assert($actor, Permission::STOCK_READ, AccessTarget::store($filters['store_id'])); }
        $where = [$scope->condition,'s.deleted_at IS NULL']; $parameters = $scope->parameters;
        foreach (['store_id' => 's.id', 'article_id' => 'a.id', 'category_id' => 'a.category_id'] as $key => $column) {
            if (isset($filters[$key])) { $where[] = $column.' = ?'; $parameters[] = $filters[$key]; }
        }
        if (isset($filters['q'])) {
            $q = trim($filters['q']);
            if ($q === '' || mb_strlen($q) > 160 || str_contains($q, "\0") || !preg_match('//u', $q)) { throw new ApiProblem(422, 'invalid_query', 'Recherche invalide.'); }
            $where[] = '(strpos(lower(a.sku), lower(?)) > 0 OR strpos(lower(a.name), lower(?)) > 0)'; $parameters[] = $q; $parameters[] = $q;
        }
        $where[] = match ($filters['status'] ?? 'all') {
            'all' => 'TRUE', 'out_of_stock' => 'coalesce(sl.quantity,0) = 0',
            'below_threshold' => 'sl.low_threshold IS NOT NULL AND coalesce(sl.quantity,0) <= sl.low_threshold',
            default => throw new ApiProblem(422, 'invalid_query', 'Disponibilité invalide.'),
        };
        $sort = match ($filters['sort'] ?? 'sku') {
            'sku' => 'sku', 'store_code' => 'store_code', 'quantity' => 'quantity',
            default => throw new ApiProblem(422, 'invalid_query', 'Tri invalide.'),
        };
        $order = $filters['order'] ?? 'asc';
        if (!in_array($order, ['asc', 'desc'], true)) { throw new ApiProblem(422, 'invalid_query', 'Ordre invalide.'); }
        $pagination = new Pagination($filters['page'] ?? 1, $filters['limit'] ?? 25);
        $from = ' FROM stores s CROSS JOIN articles a LEFT JOIN stock_levels sl ON sl.store_id = s.id AND sl.article_id = a.id WHERE '.implode(' AND ', $where);
        // A single SQL statement keeps page, count and arbitrary-precision total in one MVCC snapshot.
        $sql = 'WITH scoped AS (SELECT a.id article_id, s.id store_id, a.sku, s.code store_code, coalesce(sl.quantity,0) quantity, sl.low_threshold, sl.critical_threshold'.$from.'), page AS (SELECT * FROM scoped ORDER BY '.$sort.' '.strtoupper($order).', article_id, store_id LIMIT '.$pagination->limit.' OFFSET '.$pagination->offset().') SELECT (SELECT count(*) FROM scoped) total, (SELECT coalesce(sum(quantity),0)::text FROM scoped) sum, coalesce((SELECT json_agg(page ORDER BY '.$sort.' '.strtoupper($order).', article_id, store_id) FROM page),\'[]\'::json) rows';
        $result = $this->db->fetchAssociative($sql, $parameters);
        $rows = json_decode($result['rows'], true, flags: JSON_THROW_ON_ERROR);
        $items = array_map(static fn(array $row): array => [
            'article_id' => $row['article_id'], 'store_id' => $row['store_id'], 'quantity' => (int) $row['quantity'],
            'low_threshold' => $row['low_threshold'] === null ? null : (int) $row['low_threshold'],
            'critical_threshold' => $row['critical_threshold'] === null ? null : (int) $row['critical_threshold'], 'unit_code' => 'unit',
        ], $rows);
        return ['page' => $pagination->response($items, (int) $result['total']), 'sum' => $result['sum']];
    }

    /** Bounded display labels are loaded only for already scoped page rows. */
    public function labels(array $rows): array
    {
        $articles = []; $stores = [];
        foreach ($rows as $row) {
            if (!isset($articles[$row['article_id']])) { $articles[$row['article_id']] = $this->db->fetchAssociative('SELECT sku,name,active FROM articles WHERE id = ?', [$row['article_id']]); }
            if (!isset($stores[$row['store_id']])) { $stores[$row['store_id']] = $this->db->fetchAssociative('SELECT code,name,active FROM stores WHERE id = ?', [$row['store_id']]); }
        }
        return ['articles' => $articles, 'stores' => $stores];
    }
}
