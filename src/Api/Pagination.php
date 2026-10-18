<?php

declare(strict_types=1);

namespace App\Api;

final readonly class Pagination
{
    public function __construct(public int $page = 1, public int $limit = 25)
    {
        if ($page < 1 || $page > 10000 || $limit < 1 || $limit > 100) {
            throw new ApiProblem(422, 'invalid_pagination', 'Pagination hors limites.');
        }
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->limit;
    }

    public function response(array $items, int $total): array
    {
        if ($total < 0 || count($items) > $this->limit || !array_is_list($items) || count($items) > $total) {
            throw new \LogicException('Résultat paginé incohérent.');
        }
        return ['data' => $items, 'meta' => [
            'page' => $this->page, 'limit' => $this->limit, 'total' => $total,
            'total_pages' => intdiv($total, $this->limit) + ($total % $this->limit === 0 ? 0 : 1),
        ]];
    }
}
