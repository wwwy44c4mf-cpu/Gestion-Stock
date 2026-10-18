<?php

declare(strict_types=1);
namespace App\Access;
use App\Security\AccessPolicy;
use App\Security\AccessTarget;
use App\Security\Permission;
use App\Security\StockUser;
use Doctrine\DBAL\Connection;

/** Editing requires live write access to every affected store, including a transfer's other end. */
final class CorrectionDirectory
{
    public function __construct(private readonly Connection $db,private readonly AccessPolicy $policy) {}
    public function get(StockUser $actor,string $id): array
    {
        $this->policy->assert($actor,Permission::MOVEMENT_REVERSE,AccessTarget::movement($id));
        $row=$this->db->fetchAssociative('SELECT m.id,m.type,m.article_id,m.source_store_id,m.destination_store_id,m.quantity,m.consumer_id,m.inventory_id,m.reason,m.reference,m.comment,m.created_at,a.sku,a.name article_name,s.name source_name,d.name destination_name,(SELECT r.id FROM stock_movements r WHERE r.type = \'reversal\' AND r.related_movement_id = m.id) reversed_by,(SELECT r.reason FROM stock_movements r WHERE r.type = \'reversal\' AND r.related_movement_id = m.id) correction_reason,(SELECT c.replacement_id FROM movement_corrections c WHERE c.original_id = m.id) replacement_id FROM stock_movements m JOIN articles a ON a.id = m.article_id LEFT JOIN stores s ON s.id = m.source_store_id LEFT JOIN stores d ON d.id = m.destination_store_id WHERE m.id = ?',[$id]);
        $row['quantity']=(int)$row['quantity'];
        $row['editable']=$row['type']!=='reversal' && $row['inventory_id']===null && $row['reversed_by']===null;
        return $row;
    }
    /** Additional UI projection; no new IDs are added to the local stock API. */
    public function links(StockUser $actor,array $rows): array
    {
        $result=[];
        foreach($rows as $row) {
            try { $item=$this->get($actor,$row['id']); $result[$row['id']]=$item; }
            catch(\App\Api\ApiProblem) { /* No action or remote details outside the complete write perimeter. */ }
        }
        return $result;
    }
}
