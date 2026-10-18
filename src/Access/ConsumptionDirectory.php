<?php

declare(strict_types=1);

namespace App\Access;

use App\Api\ApiProblem;
use App\Security\AccessPolicy;
use App\Security\AccessTarget;
use App\Security\Permission;
use App\Security\StockUser;
use Doctrine\DBAL\Connection;

/** A receipt is scoped independently of the stock screen, including personal reads after unassignment. */
final class ConsumptionDirectory
{
    public function __construct(private readonly Connection $db, private readonly AccessPolicy $policy) {}
    public function get(StockUser $actor, string $id): array
    {
        $this->policy->assert($actor, Permission::CONSUMPTION_READ, AccessTarget::consumption($id));
        $scope = $this->policy->consumptionScope($actor);
        $row = $this->db->fetchAssociative("SELECT m.id,m.article_id,m.source_store_id AS store_id,m.quantity,m.reference,m.comment,m.created_at,
            a.sku,a.name AS article_name,s.code AS store_code,s.name AS store_name,
            CASE WHEN author.anonymized_at IS NULL THEN author.name ELSE 'Compte anonymisé' END AS author_name,
            CASE WHEN consumer.anonymized_at IS NULL THEN consumer.name ELSE 'Compte anonymisé' END AS consumer_name
            FROM stock_movements m JOIN articles a ON a.id = m.article_id JOIN stores s ON s.id = m.source_store_id
            JOIN users author ON author.id = m.author_id JOIN users consumer ON consumer.id = m.consumer_id
            WHERE m.id = ? AND (".$scope->condition.')', [$id, ...$scope->parameters]);
        if (!$row) { throw new ApiProblem(404, 'consumption_not_found', 'Consommation introuvable.'); }
        $row['quantity'] = (int) $row['quantity'];
        $row['created_at'] = (new \DateTimeImmutable($row['created_at']))->format(DATE_RFC3339);
        return $row;
    }
}
