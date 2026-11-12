<?php

declare(strict_types=1);
namespace App\Export;
use App\Api\ApiProblem;
use App\Security\{AccessPolicy,AccessTarget,Permission,StockUser};
use Doctrine\DBAL\Connection;
final class StoreStockSnapshot
{
    public function __construct(private readonly Connection $db,private readonly AccessPolicy $policy) {}
    public function read(StockUser $actor,string $storeId): array
    {
        if($this->db->isTransactionActive()) {throw new \LogicException('export_requires_own_read_snapshot');}
        $this->db->beginTransaction();
        try {
            $this->db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $this->policy->assert($actor,Permission::STOCK_EXPORT,AccessTarget::store($storeId));
            $row=$this->db->fetchAssociative(<<<'SQL'
SELECT row_to_json(s) store,statement_timestamp() captured_at,coalesce((
 SELECT json_agg(x ORDER BY x.sku,x.id) FROM (
  SELECT a.id,a.sku,a.name,a.description,a.active,a.deleted_at,a.unit_code,c.name category,
   coalesce(sl.quantity,0) quantity,sl.low_threshold,sl.critical_threshold,l.location,
   coalesce(to_jsonb(a)->'scan_codes','[]'::jsonb) scan_codes
  FROM articles a LEFT JOIN categories c ON c.id=a.category_id
  LEFT JOIN stock_levels sl ON sl.article_id=a.id AND sl.store_id=s.id
  LEFT JOIN article_locations l ON l.article_id=a.id AND l.store_id=s.id
 ) x
),'[]'::json) rows
FROM stores s WHERE s.id=? AND s.deleted_at IS NULL
SQL,[$storeId]);
            if(!$row) {throw new ApiProblem(404,'resource_not_found','Magasin introuvable.');}
            $result=['store'=>json_decode($row['store'],true,flags:JSON_THROW_ON_ERROR),'captured_at'=>$row['captured_at'],'rows'=>json_decode($row['rows'],true,flags:JSON_THROW_ON_ERROR),'timezone'=>$actor->timezone];
            if(count($result['rows'])>1048570) {throw new ApiProblem(422,'export_too_large','Le nombre d’articles dépasse la capacité d’une feuille Excel.');}
            $this->db->commit();return $result;
        } catch(\Throwable $e) {if($this->db->isTransactionActive()) {$this->db->rollBack();}throw $e;}
    }
}
