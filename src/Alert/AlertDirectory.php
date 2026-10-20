<?php

declare(strict_types=1);
namespace App\Alert;
use App\Api\ApiProblem;
use App\Api\Pagination;
use App\Security\AccessPolicy;
use App\Security\AccessTarget;
use App\Security\Permission;
use App\Security\StockUser;
use Doctrine\DBAL\Connection;
final class AlertDirectory
{
    public function __construct(private readonly Connection $db,private readonly AccessPolicy $policy) {}
    public function listing(StockUser $actor,array $filters=[]): array
    {
        $scope=$this->policy->resourceScope($actor,Permission::ALERT_READ,'a');$where=[$scope->condition];$params=$scope->parameters;
        if(isset($filters['store_id'])) {$this->policy->assert($actor,Permission::ALERT_READ,AccessTarget::store($filters['store_id']));}
        foreach(['store_id','article_id','status','level'] as $key) {if(isset($filters[$key])) {$where[]='a.'.$key.'=?';$params[]=$filters[$key];}}
        $sort=match($filters['sort']??'opened_at') {'opened_at'=>'opened_at','level'=>"CASE level WHEN 'critical' THEN 2 ELSE 1 END",default=>throw new ApiProblem(422,'invalid_query','Tri invalide.')};
        $order=$filters['order']??'desc';if(!in_array($order,['asc','desc'],true)) {throw new ApiProblem(422,'invalid_query','Ordre invalide.');}$orderSql=$sort.' '.strtoupper($order).',id '.strtoupper($order);$p=new Pagination($filters['page']??1,$filters['limit']??25);
        $row=$this->db->fetchAssociative('WITH scoped AS (SELECT a.* FROM stock_alerts a WHERE '.implode(' AND ',$where).'), page AS (SELECT * FROM scoped ORDER BY '.$orderSql.' LIMIT '.$p->limit.' OFFSET '.$p->offset().') SELECT (SELECT count(*) FROM scoped) total,coalesce((SELECT json_agg(page ORDER BY '.$orderSql.') FROM page),\'[]\'::json) rows',$params);
        $items=json_decode($row['rows'],true,flags:JSON_THROW_ON_ERROR);foreach($items as &$item) {$item['threshold']=(int)$item['threshold'];foreach(['opened_at','closed_at'] as $field) {if($item[$field]!==null) {$item[$field]=(new \DateTimeImmutable($item[$field]))->format(DATE_RFC3339);}}}unset($item);
        return $p->response($items,(int)$row['total']);
    }
}
