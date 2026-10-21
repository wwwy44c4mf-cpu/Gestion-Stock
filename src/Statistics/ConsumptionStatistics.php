<?php

declare(strict_types=1);
namespace App\Statistics;
use App\Api\ApiProblem;
use App\History\MovementDates;
use App\Security\{AccessPolicy,AccessTarget,Permission,StockUser};
use Doctrine\DBAL\Connection;
/** Aggregate only authorized, still-valid events. Quantities stay decimal strings. */
final class ConsumptionStatistics
{
    public function __construct(private readonly Connection $db,private readonly AccessPolicy $policy,private readonly MovementDates $dates) {}
    public function read(StockUser $actor,array $filters=[]): array
    {
        $scope=$this->policy->consumptionScope($actor,Permission::STATISTICS_READ);
        foreach(['store_id','category_id'] as $key) {if(isset($filters[$key])) {if(!is_string($filters[$key])||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di',$filters[$key])) {throw new ApiProblem(422,'invalid_query','Filtre invalide.');}$filters[$key]=strtolower($filters[$key]);}}
        if(isset($filters['store_id'])) {
            $own=$actor->role==='USER'&&$this->db->fetchOne('SELECT 1 FROM stock_movements m WHERE m.source_store_id=? AND ('.$scope->condition.') LIMIT 1',[$filters['store_id'],...$scope->parameters]);
            if(!$own) {$this->policy->assert($actor,Permission::STOCK_READ,AccessTarget::store($filters['store_id']));}
        }
        $granularity=$filters['granularity']??'day';$top=$filters['top_limit']??10;
        if(!in_array($granularity,['day','week','month'],true)||!is_int($top)||$top<1||$top>100) {throw new ApiProblem(422,'invalid_query','Regroupement ou classement invalide.');}
        $bounds=$this->dates->bounds($filters,$actor->timezone);
        $params=[$actor->timezone,$granularity,$filters['date_from']??null,$filters['date_to']??null,...$scope->parameters];$where=['TRUE'];
        foreach(['store_id','category_id'] as $key) {if(isset($filters[$key])) {$where[]=$key.'=?';$params[]=$filters[$key];}}
        foreach($bounds as $key=>$instant) {$where[]='occurred_at '.($key==='date_from'?'>=':'<').' to_timestamp(?)';$params[]=$instant;}
        $sql=<<<'SQL'
WITH settings AS (SELECT ?::text tz,?::text granularity,?::date date_from,?::date date_to),
origins AS (SELECT m.* FROM stock_movements m WHERE %s AND NOT EXISTS(SELECT 1 FROM stock_movements u WHERE u.type='reversal' AND u.related_movement_id=m.id)),
activity AS (
 SELECT m.article_id,m.source_store_id store_id,m.consumer_id,m.created_at occurred_at,m.quantity::numeric consumed,0::numeric returned,1::bigint movement_count,0::bigint return_count FROM origins m
 UNION ALL
 SELECT m.article_id,m.source_store_id,m.consumer_id,r.created_at,0::numeric,r.quantity::numeric,0::bigint,1::bigint
 FROM stock_movements r JOIN origins m ON m.id=r.related_movement_id WHERE r.type='return' AND NOT EXISTS(SELECT 1 FROM stock_movements u WHERE u.type='reversal' AND u.related_movement_id=r.id)
), enriched AS (
 SELECT e.*,a.sku article_sku,a.name article_name,a.category_id,coalesce(c.name,'Sans catégorie') category_name,s.name store_name,
 CASE WHEN u.anonymized_at IS NULL THEN u.name ELSE 'Compte anonymisé' END consumer_name
 FROM activity e JOIN articles a ON a.id=e.article_id LEFT JOIN categories c ON c.id=a.category_id JOIN stores s ON s.id=e.store_id JOIN users u ON u.id=e.consumer_id
), filtered AS (SELECT * FROM enriched WHERE %s),
limits AS (SELECT coalesce(cfg.date_from,min((f.occurred_at AT TIME ZONE cfg.tz)::date),cfg.date_to) lo,
 coalesce(cfg.date_to,max((f.occurred_at AT TIME ZONE cfg.tz)::date),cfg.date_from) hi FROM settings cfg LEFT JOIN filtered f ON true GROUP BY cfg.date_from,cfg.date_to),
span AS (SELECT lo,hi,date_trunc(cfg.granularity,lo::timestamp) start,date_trunc(cfg.granularity,hi::timestamp) finish FROM limits CROSS JOIN settings cfg),
calendar_limits AS (SELECT span.*,CASE WHEN start IS NULL THEN 0 WHEN cfg.granularity='day' THEN finish::date-start::date+1
 WHEN cfg.granularity='week' THEN (finish::date-start::date)/7+1 ELSE (extract(year FROM finish)::int-extract(year FROM start)::int)*12+extract(month FROM finish)::int-extract(month FROM start)::int+1 END bucket_count FROM span CROSS JOIN settings cfg),
period_sums AS (SELECT date_trunc(cfg.granularity,f.occurred_at AT TIME ZONE cfg.tz)::date start_date,sum(consumed) qty,sum(returned) ret,sum(movement_count)::bigint movement_count
 FROM filtered f CROSS JOIN settings cfg GROUP BY 1),
calendar AS (SELECT g::date start_date,coalesce(p.qty,0) qty,coalesce(p.ret,0) ret,coalesce(p.movement_count,0)::bigint movement_count
 FROM calendar_limits c CROSS JOIN settings cfg CROSS JOIN LATERAL generate_series(CASE WHEN c.bucket_count<=10000 THEN c.start END,c.finish,('1 '||cfg.granularity)::interval) g
 LEFT JOIN period_sums p ON p.start_date=g::date),
scaled_calendar AS (SELECT *,round(qty/greatest(max(greatest(qty,ret)) OVER(),1)*10000)::int _qs,round(ret/greatest(max(greatest(qty,ret)) OVER(),1)*10000)::int _rs FROM calendar),
articles AS (SELECT article_id,article_sku,article_name,sum(consumed) qty,sum(returned) ret,sum(movement_count)::bigint movement_count FROM filtered GROUP BY article_id,article_sku,article_name),
ranked AS (SELECT *,round(qty/greatest(max(qty) OVER(),1)*10000)::int _qs,round(movement_count::numeric/greatest(max(movement_count) OVER(),1)*10000)::int _cs FROM articles WHERE movement_count>0)
SELECT jsonb_build_object('consumed_quantity',coalesce(sum(consumed),0)::text,'returned_quantity',coalesce(sum(returned),0)::text,
 'unit_code','unit','movement_count',coalesce(sum(movement_count),0)::bigint,'return_count',coalesce(sum(return_count),0)::bigint) totals,
 (SELECT coalesce(max(greatest(qty,ret)),0)::text FROM calendar) chart_max,(SELECT bucket_count FROM calendar_limits) bucket_count,(SELECT lo::text FROM limits) date_from,(SELECT hi::text FROM limits) date_to,
 (SELECT coalesce(jsonb_agg(jsonb_build_object('start_date',start_date,'consumed_quantity',qty::text,'returned_quantity',ret::text,'movement_count',movement_count,'_qs',_qs,'_rs',_rs) ORDER BY start_date),'[]'::jsonb) FROM scaled_calendar) buckets,
 %s top_articles,%s top_articles_by_count,%s by_user,%s by_store,%s by_category FROM filtered
SQL;
        $articleJson="jsonb_build_object('article_id',article_id,'article_sku',article_sku,'article_name',article_name,'quantity',qty::text,'return_quantity',ret::text,'movement_count',movement_count,'unit_code','unit','_qs',_qs,'_cs',_cs)";
        $quantityOrder='qty DESC,movement_count DESC,article_sku,article_id';$countOrder='movement_count DESC,qty DESC,article_sku,article_id';
        $rank=fn(string $order): string=>"(SELECT coalesce(jsonb_agg($articleJson ORDER BY $order),'[]'::jsonb) FROM (SELECT * FROM ranked ORDER BY $order LIMIT $top) r)";
        $row=$this->db->fetchAssociative(sprintf($sql,'('.$scope->condition.')',implode(' AND ',$where),$rank($quantityOrder),$rank($countOrder),$this->distribution('consumer_id','consumer_name'),$this->distribution('store_id','store_name'),$this->distribution('category_id','category_name')),$params);
        if((int)$row['bucket_count']>10000) {throw new ApiProblem(422,'statistics_range_too_large','Choisissez une période plus courte ou un regroupement par semaine ou mois.');}
        $data=[];$charts=['max_quantity'=>$row['chart_max']];
        foreach(['totals','top_articles','top_articles_by_count','buckets','by_user','by_store','by_category'] as $key) {$data[$key]=json_decode($row[$key],true,flags:JSON_THROW_ON_ERROR);if($key==='totals') {continue;}if(str_starts_with($key,'by_')&&count($data[$key])>1000) {throw new ApiProblem(422,'statistics_distribution_too_large','Précisez la période, le magasin ou la catégorie pour réduire le nombre de groupes.');}
            foreach($data[$key] as $index=>&$item) {$charts[$key][$index]=['_qs'=>$item['_qs']??0,'_rs'=>$item['_rs']??0,'_cs'=>$item['_cs']??0];unset($item['_qs'],$item['_rs'],$item['_cs']);}unset($item);
        }
        return ['data'=>$data,'charts'=>$charts,'period'=>['date_from'=>$row['date_from'],'date_to'=>$row['date_to'],'timezone'=>$actor->timezone,'granularity'=>$granularity]];
    }
    private function distribution(string $id,string $label): string
    {
        return "(SELECT coalesce(jsonb_agg(jsonb_build_object('id',id,'label',label,'quantity',qty::text,'returned_quantity',ret::text,'_qs',_qs,'_rs',_rs) ORDER BY qty DESC,label,id NULLS LAST),'[]'::jsonb) FROM (
         SELECT d.*,round(qty/greatest(max(greatest(qty,ret)) OVER(),1)*10000)::int _qs,round(ret/greatest(max(greatest(qty,ret)) OVER(),1)*10000)::int _rs FROM (
          SELECT $id id,$label label,sum(consumed) qty,sum(returned) ret FROM filtered GROUP BY $id,$label ORDER BY qty DESC,label,id NULLS LAST LIMIT 1001) d) scaled)";
    }
}
