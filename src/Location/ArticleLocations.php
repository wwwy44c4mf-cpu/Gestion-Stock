<?php

declare(strict_types=1);
namespace App\Location;
use App\Api\ApiProblem;
use App\Security\{AccessPolicy,AccessTarget,Permission,StockUser};
use Doctrine\DBAL\Connection;

/** Physical storage labels are independent of stock balances and movements. */
final class ArticleLocations
{
    public function __construct(private readonly Connection $db,private readonly AccessPolicy $policy) {}
    public function get(StockUser $actor,string $storeId,string $articleId): array
    {
        $this->authorize($actor,$storeId,$articleId,Permission::LOCATION_READ);
        $row=$this->db->fetchAssociative('SELECT location,version FROM article_locations WHERE store_id=? AND article_id=?',[$storeId,$articleId]);
        $active=(bool)$this->db->fetchOne('SELECT active FROM stores WHERE id=?',[$storeId]);
        return ['data'=>['store_id'=>$storeId,'article_id'=>$articleId,'location'=>$row?$row['location']:null,'version'=>$row?(int)$row['version']:0,'can_edit'=>$active&&in_array($actor->role,['ADMIN','MANAGER'],true)]];
    }
    public function save(StockUser $actor,string $storeId,string $articleId,?string $location,int $version): array
    {
        $location=$this->validate($location);
        if($version<0||$version>=9007199254740991) {throw new ApiProblem(422,'invalid_location_version','Rechargez le casage avant de le modifier.');}
        return $this->db->transactional(function() use($actor,$storeId,$articleId,$location,$version): array {
            // Same guards/order as catalogue, assignment changes and stock operations.
            $this->db->executeStatement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
            $this->db->executeStatement('LOCK TABLE stores IN SHARE MODE');
            $this->db->executeStatement('LOCK TABLE articles IN SHARE MODE');
            $this->authorize($actor,$storeId,$articleId,Permission::LOCATION_WRITE);
            $row=$this->db->fetchAssociative('SELECT location,version FROM article_locations WHERE store_id=? AND article_id=? FOR UPDATE',[$storeId,$articleId]);
            if(($row?$row['location']:null)===$location) {return $this->get($actor,$storeId,$articleId);}
            if(($row?(int)$row['version']:0)!==$version) {throw new ApiProblem(409,'location_changed','Le casage a été modifié. Rechargez la valeur actuelle avant de réessayer.');}
            $this->db->executeStatement('INSERT INTO article_locations(article_id,store_id,location) VALUES (?,?,?) ON CONFLICT(article_id,store_id) DO UPDATE SET location=excluded.location,version=article_locations.version+1,updated_at=clock_timestamp()',[$articleId,$storeId,$location]);
            $this->db->executeStatement("INSERT INTO audit_logs(actor_id,store_id,action,target_type,target_id,context) VALUES (?,?,'stock.location_updated','article',?,'{}')",[$actor->id,$storeId,$articleId]);
            return $this->get($actor,$storeId,$articleId);
        });
    }
    public function validate(?string $value): ?string
    {
        if($value===null) {return null;}
        if(!preg_match('//u',$value)||mb_strlen($value,'UTF-8')>160||preg_match('/[\x00-\x1f\x7f]/u',$value)) {throw new ApiProblem(422,'invalid_location','Le casage doit contenir au plus 160 caractères, sur une seule ligne.');}
        return trim($value)===''?null:trim($value);
    }
    private function authorize(StockUser $actor,string $storeId,string $articleId,Permission $permission): void
    {
        $this->policy->assert($actor,$permission,AccessTarget::store($storeId));
        if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di',$articleId)
            ||!$this->db->fetchOne('SELECT 1 FROM articles WHERE id=?',[$articleId])
            ||!$this->db->fetchOne('SELECT 1 FROM stores WHERE id=? AND deleted_at IS NULL',[$storeId])) {throw new ApiProblem(404,'resource_not_found','Ressource introuvable.');}
    }
}
