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
            (SELECT coalesce(sum(r.quantity),0)::text FROM stock_movements r WHERE r.type = 'return' AND r.related_movement_id = m.id AND NOT EXISTS (SELECT 1 FROM stock_movements undo WHERE undo.type = 'reversal' AND undo.related_movement_id = r.id)) returned_quantity,
            (SELECT r.id FROM stock_movements r WHERE r.type = 'reversal' AND r.related_movement_id = m.id) AS reversed_by,
            (SELECT c.replacement_id FROM movement_corrections c WHERE c.original_id = m.id) AS replacement_id,
            CASE WHEN author.anonymized_at IS NULL THEN author.name ELSE 'Compte anonymisé' END AS author_name,
            CASE WHEN consumer.anonymized_at IS NULL THEN consumer.name ELSE 'Compte anonymisé' END AS consumer_name
            FROM stock_movements m JOIN articles a ON a.id = m.article_id JOIN stores s ON s.id = m.source_store_id
            JOIN users author ON author.id = m.author_id JOIN users consumer ON consumer.id = m.consumer_id
            WHERE m.id = ? AND (".$scope->condition.')', [$id, ...$scope->parameters]);
        if (!$row) { throw new ApiProblem(404, 'consumption_not_found', 'Consommation introuvable.'); }
        $row['quantity'] = (int) $row['quantity'];
        $row['returned_quantity'] = (int) $row['returned_quantity'];
        $row['returnable_quantity'] = $row['reversed_by'] === null ? max(0,$row['quantity']-$row['returned_quantity']) : 0;
        $row['created_at'] = (new \DateTimeImmutable($row['created_at']))->format(DATE_RFC3339);
        return $row;
    }
}
