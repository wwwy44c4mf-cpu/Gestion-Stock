<?php

declare(strict_types=1);

namespace App\Access;

use App\Api\ApiProblem;
use App\Api\Pagination;
use App\Security\AccessIdentity;
use App\Security\Permission;
use App\Security\StockUser;
use Doctrine\DBAL\Connection;

/** Global descriptive catalogue: never joins stock, movements or private user data. */
final class CatalogDirectory
{
    public function __construct(private readonly Connection $db, private readonly AccessIdentity $identity) {}
    public function listing(StockUser $actor, string $kind, array $filters = []): array
    {
        $this->identity->requireRole($actor, Permission::CATALOG_READ); $table = $this->table($kind);
        $where = ['TRUE']; $parameters = [];
        if (isset($filters['status'])) {
            if (!in_array($filters['status'], ['active','inactive'], true)) { $this->invalid(); }
            $where[] = $filters['status'] === 'active' ? 'r.active' : 'NOT r.active';
        }
        if ($kind === 'article' && isset($filters['category_id'])) {
            $this->uuid($filters['category_id']); $where[] = 'r.category_id = ?'; $parameters[] = $filters['category_id'];
        }
        if ($kind === 'article' && isset($filters['q'])) {
            $q = trim($filters['q']); if ($q === '' || mb_strlen($q) > 160 || str_contains($q, "\0") || !preg_match('//u', $q)) { $this->invalid(); }
            $where[] = '(strpos(lower(r.sku),lower(?)) > 0 OR strpos(lower(r.name),lower(?)) > 0)'; $parameters[] = $q; $parameters[] = $q;
        }
        $sorts = $kind === 'article' ? ['sku','name','created_at','category','status'] : ['name']; $sort = $filters['sort'] ?? $sorts[0]; $order = $filters['order'] ?? 'asc';
        if (!in_array($sort, $sorts, true) || !in_array($order, ['asc','desc'], true)) { $this->invalid(); }
        $sortExpression = match ($sort) { 'category' => '(SELECT c.name FROM categories c WHERE c.id = r.category_id)', 'status' => 'r.active', default => 'r.'.$sort };
        $pagination = new Pagination($filters['page'] ?? 1, $filters['limit'] ?? 25); $condition = implode(' AND ', $where);
        $total = (int) $this->db->fetchOne('SELECT count(*) FROM '.$table.' r WHERE '.$condition, $parameters);
        $rows = $this->db->fetchAllAssociative('SELECT r.* FROM '.$table.' r WHERE '.$condition.' ORDER BY '.$sortExpression.' '.strtoupper($order).', r.id LIMIT '.$pagination->limit.' OFFSET '.$pagination->offset(), $parameters);
        return $pagination->response(array_map(fn(array $row) => $this->resource($kind, $row), $rows), $total);
    }
    public function get(StockUser $actor, string $kind, string $id): array
    {
        $this->identity->requireRole($actor, Permission::CATALOG_READ); $this->uuid($id);
        $row = $this->db->fetchAssociative('SELECT * FROM '.$this->table($kind).' WHERE id = ?', [$id]);
        if (!$row) { throw new ApiProblem(404, 'catalog_resource_not_found', 'Ressource du catalogue introuvable.'); }
        return ['data' => $this->resource($kind, $row)];
    }
    public function categoryOptions(StockUser $actor): array
    {
        $this->identity->requireRole($actor, Permission::CATALOG_READ);
        return $this->db->fetchAllAssociative('SELECT id,name,parent_id,active FROM categories ORDER BY name,id');
    }
    /** Descriptive global options only; the engine rechecks active status on submission. */
    public function activeArticleOptions(StockUser $actor): array
    {
        $this->identity->requireRole($actor, Permission::CATALOG_READ);
        return $this->db->fetchAllAssociative('SELECT id,sku,name FROM articles WHERE active ORDER BY sku,id');
    }
    /** Adjustments may rectify the remaining balance of an archived article. */
    public function articleOptions(StockUser $actor): array
    {
        $this->identity->requireRole($actor, Permission::ADJUSTMENT_CREATE);
        return $this->db->fetchAllAssociative('SELECT id,sku,name,active FROM articles ORDER BY sku,id');
    }
    private function table(string $kind): string { return match ($kind) { 'article' => 'articles', 'category' => 'categories', default => throw new \InvalidArgumentException('Invalid catalogue kind.') }; }
    private function resource(string $kind, array $row): array
    {
        if ($kind === 'category') { return ['id'=>$row['id'],'name'=>$row['name'],'parent_id'=>$row['parent_id'],'active'=>(bool)$row['active']]; }
        return ['id'=>$row['id'],'sku'=>$row['sku'],'name'=>$row['name'],'description'=>$row['description'],'category_id'=>$row['category_id'],'unit_code'=>$row['unit_code'],'active'=>(bool)$row['active'],'version'=>(int)$row['version'],'created_at'=>(new \DateTimeImmutable($row['created_at']))->format(DATE_RFC3339)];
    }
    private function uuid(string $id): void { if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $id)) { throw new ApiProblem(404, 'catalog_resource_not_found', 'Ressource du catalogue introuvable.'); } }
    private function invalid(): never { throw new ApiProblem(422, 'invalid_query', 'Paramètre de recherche invalide.'); }
}
