<?php

declare(strict_types=1);
namespace App\Access;
use App\Api\ApiProblem;
use App\Security\AccessIdentity;
use App\Security\AccessPolicy;
use App\Security\Permission;
use App\Security\StockUser;
use Doctrine\DBAL\Connection;

/** Both store scopes are applied in the final query, before any remote labels or author are exposed. */
final class TransferDirectory
{
    public function __construct(private readonly Connection $db,private readonly AccessPolicy $policy,private readonly AccessIdentity $identity) {}
    public function get(StockUser $actor,string $id): array
    {
        $this->identity->requireRole($actor,Permission::TRANSFER_CREATE);
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$id)) { $this->missing(); }
        $source=$this->policy->storeScope($actor,Permission::STOCK_READ,'s');$destination=$this->policy->storeScope($actor,Permission::STOCK_READ,'d');
        $row=$this->db->fetchAssociative("SELECT m.id,m.article_id,m.source_store_id,m.destination_store_id,m.quantity,m.reason,m.created_at,a.sku,a.name article_name,
            s.code source_code,s.name source_name,d.code destination_code,d.name destination_name,
            CASE WHEN author.anonymized_at IS NULL THEN author.name ELSE 'Compte anonymisé' END author_name,
            (SELECT r.id FROM stock_movements r WHERE r.type = 'reversal' AND r.related_movement_id = m.id) reversed_by,
            (SELECT c.replacement_id FROM movement_corrections c WHERE c.original_id = m.id) replacement_id
            FROM stock_movements m JOIN articles a ON a.id = m.article_id JOIN stores s ON s.id = m.source_store_id JOIN stores d ON d.id = m.destination_store_id JOIN users author ON author.id = m.author_id
            WHERE m.id = ? AND m.type = 'transfer' AND (".$source->condition.') AND ('.$destination->condition.')',[$id,...$source->parameters,...$destination->parameters]);
        if (!$row) { $this->missing(); }
        $row['quantity']=(int)$row['quantity'];$row['created_at']=(new \DateTimeImmutable($row['created_at']))->format(DATE_RFC3339);return $row;
    }
    public function links(StockUser $actor,array $rows): array
    {
        $links=[];
        foreach($rows as $row) {
            if ($row['type'] !== 'transfer') { continue; }
            try { $this->get($actor,$row['id']); $links[$row['id']]=true; }
            catch(ApiProblem) { /* The existing local projection stays available without remote details. */ }
        }
        return $links;
    }
    private function missing(): never { throw new ApiProblem(404,'transfer_not_found','Transfert introuvable.'); }
}
