<?php

declare(strict_types=1);
namespace App\Consumption;
use App\Api\{ApiProblem,Pagination};
use App\History\MovementDates;
use App\Security\{AccessPolicy,AccessTarget,Permission,StockUser};
use Doctrine\DBAL\Connection;
/** A personal consumption grants no access to stock balances or other consumers. */
final class ConsumptionHistory
{
    public function __construct(private readonly Connection $db,private readonly AccessPolicy $policy,private readonly MovementDates $dates) {}
    private function projection(StockUser $actor): array
    {
        $scope=$this->policy->consumptionScope($actor);
        return ["WITH scoped AS (SELECT m.* FROM stock_movements m WHERE ".$scope->condition."), visible AS (
            SELECT m.id,m.type,m.article_id,m.source_store_id,m.destination_store_id,m.quantity,m.author_id,m.consumer_id,
                m.related_movement_id,m.inventory_id,m.reason,m.reference,m.comment,m.created_at,
                a.sku article_sku,a.name article_name,a.category_id,c.name category_name,s.code store_code,s.name store_name,
                CASE WHEN au.anonymized_at IS NULL THEN au.name ELSE 'Compte anonymisé' END author_name,
                CASE WHEN cu.anonymized_at IS NULL THEN cu.name ELSE 'Compte anonymisé' END consumer_name,
                (SELECT coalesce(sum(r.quantity),0) FROM stock_movements r WHERE r.type='return' AND r.related_movement_id=m.id
                  AND NOT EXISTS(SELECT 1 FROM stock_movements u WHERE u.type='reversal' AND u.related_movement_id=r.id)) returned_quantity,
                (SELECT u.id FROM stock_movements u WHERE u.type='reversal' AND u.related_movement_id=m.id) reversed_by,
                (SELECT x.replacement_id FROM movement_corrections x WHERE x.original_id=m.id AND x.replacement_id IN(SELECT id FROM scoped)) replacement_id,
                CASE WHEN EXISTS(SELECT 1 FROM movement_corrections x WHERE x.original_id=m.id) THEN 'corrected'
                     WHEN EXISTS(SELECT 1 FROM stock_movements u WHERE u.type='reversal' AND u.related_movement_id=m.id) THEN 'cancelled' ELSE 'valid' END status
            FROM scoped m JOIN articles a ON a.id=m.article_id LEFT JOIN categories c ON c.id=a.category_id
            JOIN stores s ON s.id=m.source_store_id JOIN users au ON au.id=m.author_id JOIN users cu ON cu.id=m.consumer_id)",$scope->parameters];
    }
    public function listing(StockUser $actor,array $filters=[]): array
    {
        [$sql,$params]=$this->projection($actor);
        foreach(['article_id','category_id','store_id','consumer_id'] as $field) {if(isset($filters[$field])) {if(!is_string($filters[$field])||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di',$filters[$field])) {throw new ApiProblem(422,'invalid_query','Filtre invalide.');}$filters[$field]=strtolower($filters[$field]);}}
        if($actor->role==='USER'&&isset($filters['consumer_id'])&&$filters['consumer_id']!==$actor->id) {throw new ApiProblem(403,'personal_scope','Vous pouvez consulter uniquement vos propres consommations.');}
        if(isset($filters['store_id'])) {
            $own=$actor->role==='USER'&&$this->db->fetchOne($sql.' SELECT 1 FROM visible WHERE source_store_id=? LIMIT 1',[...$params,$filters['store_id']]);
            if(!$own) {$this->policy->assert($actor,Permission::STOCK_READ,AccessTarget::store($filters['store_id']));}
        }
        $where=['TRUE'];foreach(['article_id','category_id','consumer_id'] as $field) {if(isset($filters[$field])) {$where[]=$field.'=?';$params[]=$filters[$field];}}
        if(isset($filters['store_id'])) {$where[]='source_store_id=?';$params[]=$filters['store_id'];}
        foreach($this->dates->bounds($filters,$actor->timezone) as $field=>$instant) {$where[]='created_at '.($field==='date_from'?'>=':'<').' to_timestamp(?)';$params[]=$instant;}
        $sort=$filters['sort']??'created_at';$order=$filters['order']??'asc';if(!in_array($sort,['created_at','quantity'],true)||!in_array($order,['asc','desc'],true)) {throw new ApiProblem(422,'invalid_query','Tri invalide.');}
        $p=new Pagination($filters['page']??1,$filters['limit']??25);$ordering=$sort.' '.strtoupper($order).',id '.strtoupper($order);
        $row=$this->db->fetchAssociative($sql.', filtered AS (SELECT * FROM visible WHERE '.implode(' AND ',$where).'), page AS (SELECT * FROM filtered ORDER BY '.$ordering.' LIMIT '.$p->limit.' OFFSET '.$p->offset().") SELECT (SELECT count(*) FROM filtered) total,
            (SELECT coalesce(sum(quantity) FILTER(WHERE status='valid'),0)::text FROM filtered) consumed,
            (SELECT coalesce(sum(returned_quantity) FILTER(WHERE status='valid'),0)::text FROM filtered) returned,
            coalesce((SELECT json_agg(page ORDER BY ".$ordering.") FROM page),'[]'::json) rows",$params);
        $rows=json_decode($row['rows'],true,flags:JSON_THROW_ON_ERROR);foreach($rows as &$item) {$item['quantity']=(int)$item['quantity'];$item['returned_quantity']=(int)$item['returned_quantity'];$item['created_at']=(new \DateTimeImmutable($item['created_at']))->format(DATE_RFC3339);}unset($item);
        return $p->response($rows,(int)$row['total'])+['totals'=>['unit'=>'unit','consumed_quantity'=>$row['consumed'],'returned_quantity'=>$row['returned']]];
    }
    /** Names only, derived from the visible consumption scope, never the account directory. */
    public function options(StockUser $actor): array
    {
        [$sql,$params]=$this->projection($actor);$stores=$this->policy->storeScope($actor,Permission::STOCK_READ,'s');
        return [
            'people'=>$this->db->fetchAllAssociative($sql.' SELECT DISTINCT consumer_id id,consumer_name name FROM visible ORDER BY name,id',$params),
            'articles'=>$this->db->fetchAllAssociative($sql.' SELECT DISTINCT article_id id,article_sku sku,article_name name FROM visible ORDER BY sku,id',$params),
            'stores'=>$this->db->fetchAllAssociative($sql.' SELECT s.id,s.code,s.name,s.active FROM stores s WHERE ('.$stores->condition.") OR (s.active AND s.id IN(SELECT source_store_id FROM visible)) ORDER BY s.name,s.id",[...$params,...$stores->parameters])
        ];
    }
}
