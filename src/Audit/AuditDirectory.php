<?php

declare(strict_types=1);
namespace App\Audit;

use App\Api\{ApiProblem,Pagination};
use App\History\MovementDates;
use App\Security\{AccessPolicy,AccessTarget,Permission,StockUser};
use Doctrine\DBAL\Connection;

final class AuditDirectory
{
    public function __construct(private readonly Connection $db,private readonly AccessPolicy $policy,private readonly MovementDates $dates,private readonly AuditContext $context) {}

    private function projection(StockUser $actor): array
    {
        $scope=$this->policy->resourceScope($actor,Permission::AUDIT_READ,'a');
        $stores=$this->policy->storeScope($actor,Permission::AUDIT_READ,'s');
        $complete=$actor->role==='ADMIN'?'TRUE':"(a.target_type<>'stock_movement' OR (m.id IS NOT NULL AND (m.source_store_id IS NULL OR m.source_store_id IN(SELECT id FROM allowed_stores)) AND (m.destination_store_id IS NULL OR m.destination_store_id IN(SELECT id FROM allowed_stores))))";
        $sql='WITH allowed_stores AS (SELECT s.id FROM stores s WHERE '.$stores->condition.'), scoped AS (
            SELECT a.*,s.name store_name,CASE WHEN u.anonymized_at IS NOT NULL THEN \'Compte anonymisé\' ELSE u.name END actor_name,'.$complete.' complete
            FROM audit_logs a LEFT JOIN stores s ON s.id=a.store_id LEFT JOIN users u ON u.id=a.actor_id
            LEFT JOIN stock_movements m ON a.target_type=\'stock_movement\' AND m.id=a.target_id WHERE '.$scope->condition.'), visible AS (
            SELECT id,store_id,store_name,action,target_type,created_at,NOT complete restricted,
            CASE WHEN complete THEN actor_id END actor_id,CASE WHEN complete THEN actor_name END actor_name,
            CASE WHEN complete THEN target_id END target_id,CASE WHEN complete THEN context ELSE \'{}\'::jsonb END context FROM scoped)';
        return [$sql,[...$stores->parameters,...$scope->parameters]];
    }
    public function listing(StockUser $actor,array $filters=[]): array
    {
        [$sql,$params]=$this->projection($actor);
        foreach(['store_id','actor_id'] as $key) {if(isset($filters[$key])&&(!is_string($filters[$key])||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di',$filters[$key]))) {throw new ApiProblem(422,'invalid_query','Identifiant de filtre invalide.');}}
        if(isset($filters['store_id'])) {$this->policy->assert($actor,Permission::AUDIT_READ,AccessTarget::store($filters['store_id']));}
        $where=['TRUE'];foreach(['store_id','actor_id','action'] as $key) {if(isset($filters[$key])) {$where[]=$key.'=?';$params[]=$filters[$key];}}
        if(isset($filters['action'])&&(!is_string($filters['action'])||trim($filters['action'])===''||strlen($filters['action'])>80||str_contains($filters['action'],"\0"))) {throw new ApiProblem(422,'invalid_query','Action invalide.');}
        foreach($this->dates->bounds($filters,$actor->timezone) as $key=>$instant) {$where[]='created_at '.($key==='date_from'?'>=':'<').' to_timestamp(?)';$params[]=$instant;}
        $order=$filters['order']??'desc';if(($filters['sort']??'created_at')!=='created_at'||!in_array($order,['asc','desc'],true)) {throw new ApiProblem(422,'invalid_query','Tri invalide.');}
        $p=new Pagination($filters['page']??1,$filters['limit']??25);$ordering='created_at '.strtoupper($order).',id '.strtoupper($order);
        $row=$this->db->fetchAssociative($sql.', filtered AS (SELECT * FROM visible WHERE '.implode(' AND ',$where).'), page AS (SELECT * FROM filtered ORDER BY '.$ordering.' LIMIT '.$p->limit.' OFFSET '.$p->offset().') SELECT (SELECT count(*) FROM filtered) total,coalesce((SELECT json_agg(page ORDER BY '.$ordering.') FROM page),\'[]\'::json) rows',$params);
        $rows=json_decode($row['rows'],true,flags:JSON_THROW_ON_ERROR);
        foreach($rows as &$item) {$item['created_at']=(new \DateTimeImmutable($item['created_at']))->format(DATE_RFC3339);$item['context']=$this->context->project($item['action'],$item['context']);}unset($item);
        return $p->response($rows,(int)$row['total']);
    }
    public function options(StockUser $actor): array
    {
        [$sql,$params]=$this->projection($actor);
        return ['actors'=>$this->db->fetchAllAssociative($sql.' SELECT DISTINCT actor_id id,actor_name name FROM visible WHERE actor_id IS NOT NULL ORDER BY name,id',$params),
            'actions'=>$this->db->fetchFirstColumn($sql.' SELECT DISTINCT action FROM visible ORDER BY action',$params)];
    }
}
