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
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class AccessDirectory
{
    public function __construct(private readonly Connection $db, private readonly AccessPolicy $policy,
        #[Autowire('%kernel.environment%')] private readonly string $environment) {}

    public function stores(StockUser $actor, array $filters = []): array
    {
        $scope = $this->policy->storeScope($actor);
        $where = $scope->condition; $parameters = $scope->parameters;
        if (isset($filters['status'])) {
            if (!in_array($filters['status'], ['active', 'inactive'], true)) { throw new ApiProblem(422, 'invalid_query', 'État invalide.'); }
            $where .= $filters['status'] === 'active' ? ' AND s.active' : ' AND NOT s.active';
        }
        $sort = ['code' => 's.code', 'name' => 's.name'][$filters['sort'] ?? 'code'] ?? null;
        $order = $this->order($filters);
        if ($sort === null) { throw new ApiProblem(422, 'invalid_query', 'Tri invalide.'); }
        $pagination = new Pagination($filters['page'] ?? 1, $filters['limit'] ?? 25);
        $total = (int) $this->db->fetchOne('SELECT count(*) FROM stores s WHERE '.$where, $parameters);
        $rows = $this->db->fetchAllAssociative('SELECT s.id, s.code, s.name, s.description, s.address, s.active, s.created_at FROM stores s WHERE '.$where.' ORDER BY '.$sort.' '.$order.', s.id LIMIT '.$pagination->limit.' OFFSET '.$pagination->offset(), $parameters);
        return $pagination->response(array_map($this->storeRow(...), $rows), $total);
    }

    public function store(StockUser $actor, string $id): array
    {
        $this->policy->assert($actor, Permission::STOCK_READ, AccessTarget::store($id));
        $scope = $this->policy->storeScope($actor);
        $row = $this->db->fetchAssociative('SELECT s.id, s.code, s.name, s.description, s.address, s.active, s.created_at FROM stores s WHERE s.id = ? AND ('.$scope->condition.')', [$id, ...$scope->parameters]);
        if (!$row) { throw new ApiProblem(404, 'resource_not_found', 'Ressource introuvable.'); }
        return ['data' => $this->storeRow($row)];
    }

    /** All options, without the API pagination limit, but always scoped first. */
    public function storeOptions(StockUser $actor, Permission $permission = Permission::STOCK_READ): array
    {
        $scope = $this->policy->storeScope($actor, $permission);
        return $this->db->fetchAllAssociative('SELECT s.id, s.code, s.name, s.active FROM stores s WHERE '.$scope->condition.' ORDER BY s.name, s.id', $scope->parameters);
    }

    public function beneficiaries(StockUser $actor, array $filters): array
    {
        $this->policy->assert($actor, Permission::BENEFICIARIES_READ);
        $where = "u.active AND u.anonymized_at IS NULL AND (NOT u.development_only OR ? = 'dev')";
        $parameters = [$this->environment];
        if (isset($filters['q'])) {
            $query = trim($filters['q']);
            if ($query === '' || mb_strlen($query) > 160) { throw new ApiProblem(422, 'invalid_query', 'Recherche invalide.'); }
            $where .= ' AND strpos(lower(u.name), lower(?)) > 0'; $parameters[] = $query;
        }
        if (($filters['sort'] ?? 'name') !== 'name') { throw new ApiProblem(422, 'invalid_query', 'Tri invalide.'); }
        $order = $this->order($filters); $pagination = new Pagination($filters['page'] ?? 1, $filters['limit'] ?? 25);
        $total = (int) $this->db->fetchOne('SELECT count(*) FROM users u WHERE '.$where, $parameters);
        $rows = $this->db->fetchAllAssociative('SELECT u.id, u.name FROM users u WHERE '.$where.' ORDER BY u.name '.$order.', u.id LIMIT '.$pagination->limit.' OFFSET '.$pagination->offset(), $parameters);
        return $pagination->response($rows, $total);
    }

    /** Complete minimal selector, with the same scope as the paginated beneficiary API. */
    public function beneficiaryOptions(StockUser $actor): array
    {
        $this->policy->assert($actor, Permission::BENEFICIARIES_READ);
        return $this->db->fetchAllAssociative("SELECT id,name FROM users WHERE active AND anonymized_at IS NULL AND (NOT development_only OR ? = 'dev') ORDER BY name,id", [$this->environment]);
    }

    private function order(array $filters): string
    {
        $order = $filters['order'] ?? 'asc';
        if (!in_array($order, ['asc', 'desc'], true)) { throw new ApiProblem(422, 'invalid_query', 'Tri invalide.'); }
        return strtoupper($order);
    }
    private function storeRow(array $row): array
    {
        $row['active'] = (bool) $row['active']; $row['created_at'] = (new \DateTimeImmutable($row['created_at']))->format(DATE_RFC3339);
        return $row;
    }
}
