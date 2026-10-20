<?php

declare(strict_types=1);
namespace App\Inventory;
use App\Api\ApiProblem;
use App\Api\Pagination;
use App\Security\AccessPolicy;
use App\Security\AccessTarget;
use App\Security\Permission;
use App\Security\StockUser;
use Doctrine\DBAL\Connection;

final class InventoryDirectory
{
    public function __construct(private readonly Connection $db, private readonly AccessPolicy $policy) {}
    public function get(StockUser $actor,string $id): array
    {
        $this->policy->assert($actor,Permission::INVENTORY_READ,AccessTarget::inventory($id));
        return ['data'=>$this->resource($this->db->fetchAssociative('SELECT * FROM inventories WHERE id = ?',[$id]))];
    }
    public function listing(StockUser $actor,array $filters=[]): array
    {
        $scope=$this->policy->resourceScope($actor,Permission::INVENTORY_READ,'i');$where=[$scope->condition];$parameters=$scope->parameters;
        if(isset($filters['store_id'])) {$this->policy->assert($actor,Permission::INVENTORY_READ,AccessTarget::store($filters['store_id']));}
        foreach(['store_id','status'] as $key) {if(isset($filters[$key])) {$where[]='i.'.$key.' = ?';$parameters[]=$filters[$key];}}
        $order=$filters['order']??'desc';if(!in_array($order,['asc','desc'],true) || ($filters['sort']??'created_at')!=='created_at') {throw new ApiProblem(422,'invalid_query','Tri invalide.');}
        $pagination=new Pagination($filters['page']??1,$filters['limit']??25);$ordering='created_at '.strtoupper($order).',id '.strtoupper($order);
        $result=$this->db->fetchAssociative('WITH scoped AS (SELECT i.* FROM inventories i WHERE '.implode(' AND ',$where).'), page AS (SELECT * FROM scoped ORDER BY '.$ordering.' LIMIT '.$pagination->limit.' OFFSET '.$pagination->offset().') SELECT (SELECT count(*) FROM scoped) total,coalesce((SELECT json_agg(page ORDER BY '.$ordering.') FROM page),\'[]\'::json) rows',$parameters);
        $rows=json_decode($result['rows'],true,flags:JSON_THROW_ON_ERROR);
        return $pagination->response(array_map($this->resource(...),$rows),(int)$result['total']);
    }
    public function lines(StockUser $actor,string $id,array $filters=[]): array
    {
        $this->get($actor,$id);$pagination=new Pagination($filters['page']??1,$filters['limit']??25);
        $order=$filters['order']??'asc';if(!in_array($order,['asc','desc'],true) || ($filters['sort']??'sku')!=='sku') {throw new ApiProblem(422,'invalid_query','Tri invalide.');}
        $where='l.inventory_id = ?';$parameters=[$id];if(isset($filters['article_id'])) {$where.=' AND l.article_id = ?';$parameters[]=$filters['article_id'];}
        $ordering='sku '.strtoupper($order).',article_id '.strtoupper($order);
        $result=$this->db->fetchAssociative('WITH scoped AS (SELECT l.article_id,l.theoretical_qty,l.counted_qty,l.delta,l.counted_by,l.counted_at,l.version,CASE WHEN l.theoretical_qty>0 AND l.counted_qty IS NOT NULL THEN round((l.delta*100)::numeric(60,30)/l.theoretical_qty,2)::text END delta_percent,a.sku FROM inventory_lines l JOIN articles a ON a.id=l.article_id WHERE '.$where.'), page AS (SELECT * FROM scoped ORDER BY '.$ordering.' LIMIT '.$pagination->limit.' OFFSET '.$pagination->offset().') SELECT (SELECT count(*) FROM scoped) total,coalesce((SELECT json_agg(page ORDER BY '.$ordering.') FROM page),\'[]\'::json) rows',$parameters);
        $rows=json_decode($result['rows'],true,flags:JSON_THROW_ON_ERROR);
        foreach($rows as &$row) {unset($row['sku']);foreach(['theoretical_qty','counted_qty','delta','version'] as $key) {if($row[$key]!==null) {$row[$key]=(int)$row[$key];}}if($row['counted_at']!==null) {$row['counted_at']=(new \DateTimeImmutable($row['counted_at']))->format(DATE_RFC3339);}}unset($row);
        return $pagination->response($rows,(int)$result['total']);
    }
    /** Decimal arithmetic stays in PostgreSQL: no floating point quantity conversion. */
    public function progress(StockUser $actor,string $id): array
    {
        $this->get($actor,$id);$row=$this->db->fetchAssociative('SELECT count(*) total,count(counted_qty) counted FROM inventory_lines WHERE inventory_id=?',[$id]);
        return ['total'=>(int)$row['total'],'counted'=>(int)$row['counted'],'remaining'=>(int)$row['total']-(int)$row['counted']];
    }
    public function labels(array $inventories,array $lines=[]): array
    {
        $stores=[];$articles=[];
        foreach($inventories as $row) {if(!isset($stores[$row['store_id']])) {$stores[$row['store_id']]=$this->db->fetchAssociative('SELECT name,code,active FROM stores WHERE id=?',[$row['store_id']]);}}
        foreach($lines as $row) {$articles[$row['article_id']]=$this->db->fetchAssociative('SELECT name,sku,active FROM articles WHERE id=?',[$row['article_id']]);}
        return ['stores'=>$stores,'articles'=>$articles];
    }
    public function resource(array $row): array
    {
        $result=[];foreach(['id','store_id','status','created_by','validated_by','created_at','snapshot_at','closed_at','cancelled_at'] as $key) {$result[$key]=$row[$key];}
        foreach(['created_at','snapshot_at','closed_at','cancelled_at'] as $key) {if($result[$key]!==null) {$result[$key]=(new \DateTimeImmutable($result[$key]))->format(DATE_RFC3339);}}
        return $result;
    }
}
