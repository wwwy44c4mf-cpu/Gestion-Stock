<?php

declare(strict_types=1);
namespace App\Access;
use App\Api\ApiProblem;
use App\Api\Pagination;
use App\Security\AccessIdentity;
use App\Security\AccessPolicy;
use App\Security\Permission;
use App\Security\StockUser;
use Doctrine\DBAL\Connection;

final class ReturnDirectory
{
    public function __construct(private readonly Connection $db,private readonly AccessPolicy $policy,private readonly AccessIdentity $identity) {}
    public function eligible(StockUser $actor,int $page=1,?string $articleId=null): array
    {
        $this->identity->requireRole($actor,Permission::RETURN_CREATE);$scope=$this->policy->consumptionScope($actor);$pagination=new Pagination($page,25);
        $parameters=$scope->parameters;$articleFilter='';
        if ($articleId!==null) { if (!preg_match('/^[a-f0-9-]{36}$/iD',$articleId)) { throw new ApiProblem(422,'invalid_query','Article invalide.'); }$articleFilter=' AND m.article_id=?';$parameters[]=$articleId; }
        $sql="WITH scoped AS (SELECT m.id,m.quantity,m.created_at,m.reference,a.sku,a.name article_name,s.code store_code,s.name store_name,
            CASE WHEN u.anonymized_at IS NULL THEN u.name ELSE 'Compte anonymisé' END consumer_name,
            m.quantity - (SELECT coalesce(sum(r.quantity),0) FROM stock_movements r WHERE r.type = 'return' AND r.related_movement_id = m.id AND NOT EXISTS (SELECT 1 FROM stock_movements undo WHERE undo.type = 'reversal' AND undo.related_movement_id = r.id)) remaining
            FROM stock_movements m JOIN articles a ON a.id = m.article_id JOIN stores s ON s.id = m.source_store_id JOIN users u ON u.id = m.consumer_id
            WHERE s.active AND (".$scope->condition.")".$articleFilter." AND NOT EXISTS (SELECT 1 FROM stock_movements undo WHERE undo.type = 'reversal' AND undo.related_movement_id = m.id)), eligible AS (SELECT * FROM scoped WHERE remaining > 0), page AS (SELECT * FROM eligible ORDER BY created_at DESC,id DESC LIMIT ".$pagination->limit.' OFFSET '.$pagination->offset().") SELECT (SELECT count(*) FROM eligible) total,coalesce((SELECT json_agg(page ORDER BY created_at DESC,id DESC) FROM page),'[]'::json) rows";
        $result=$this->db->fetchAssociative($sql,$parameters);$rows=json_decode($result['rows'],true,flags:JSON_THROW_ON_ERROR);
        foreach($rows as &$row) { $row['quantity']=(int)$row['quantity'];$row['remaining']=(int)$row['remaining']; }unset($row);
        return $pagination->response($rows,(int)$result['total']);
    }
    public function receipt(StockUser $actor,string $id): array
    {
        $this->identity->requireRole($actor,Permission::RETURN_CREATE);
        if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$id)) { $this->missing(); }
        $scope=$actor->role==='USER' ? new \App\Security\SqlScope("m.consumer_id=? AND s.active",[$actor->id]) : $this->policy->storeScope($actor,Permission::STOCK_READ,'s');
        $row=$this->db->fetchAssociative("SELECT r.id,r.quantity,r.comment,r.created_at,r.related_movement_id consumption_id,r.article_id,r.destination_store_id store_id,
            a.sku,a.name article_name,s.code store_code,s.name store_name,
            CASE WHEN author.anonymized_at IS NULL THEN author.name ELSE 'Compte anonymisé' END author_name,
            CASE WHEN consumer.anonymized_at IS NULL THEN consumer.name ELSE 'Compte anonymisé' END consumer_name,
            (SELECT undo.id FROM stock_movements undo WHERE undo.type = 'reversal' AND undo.related_movement_id = r.id) reversed_by,
            (SELECT c.replacement_id FROM movement_corrections c WHERE c.original_id = r.id) replacement_id
            FROM stock_movements r JOIN stock_movements m ON m.id = r.related_movement_id JOIN articles a ON a.id = r.article_id JOIN stores s ON s.id = r.destination_store_id
            JOIN users author ON author.id = r.author_id JOIN users consumer ON consumer.id = m.consumer_id
            WHERE r.id = ? AND r.type = 'return' AND (".$scope->condition.')',[$id,...$scope->parameters]);
        if(!$row) { $this->missing(); }$row['quantity']=(int)$row['quantity'];return $row;
    }
    private function missing(): never { throw new ApiProblem(404,'return_not_found','Retour introuvable.'); }
}
