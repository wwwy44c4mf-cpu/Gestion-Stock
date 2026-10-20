<?php

declare(strict_types=1);
namespace App\Stock;
use App\Access\StockDirectory;
use App\Api\ApiProblem;
use App\Api\OpenApiContract;
use App\Security\AccessIdentity;
use App\Security\AccessPolicy;
use App\Security\AccessTarget;
use App\Security\Permission;
use App\Security\StockUser;
use Doctrine\DBAL\Connection;

/** Threshold metadata never rewrites a balance, its movement version or the ledger. */
final class StockThresholds
{
    public function __construct(private readonly Connection $db,private readonly AccessIdentity $identity,private readonly AccessPolicy $policy,
        private readonly StockDirectory $stocks,private readonly OpenApiContract $contract) {}
    public function get(StockUser $actor,string $storeId,string $articleId): array
    {
        $this->identity->requireRole($actor,Permission::THRESHOLD_WRITE);
        $this->policy->assert($actor,Permission::THRESHOLD_WRITE,AccessTarget::store($storeId));
        return $this->stocks->detail($actor,$storeId,$articleId);
    }
    /** UI consistency token only: independent of quantity changes; authorization remains server-side. */
    public function revision(array $stock): string
    {
        return hash('sha256',json_encode([$stock['store_id'],$stock['article_id'],$stock['low_threshold'],$stock['critical_threshold']],JSON_THROW_ON_ERROR));
    }
    public function update(StockUser $actor,string $storeId,string $articleId,\stdClass $body,?string $expectedRevision=null): array
    {
        $this->identity->requireRole($actor,Permission::THRESHOLD_WRITE);
        if(!$this->contract->valid($body,['$ref'=>'#/components/schemas/ThresholdPatch'])) {throw new ApiProblem(422,'validation_failed','Saisissez des seuils entiers positifs ou nuls, ou laissez-les non configurés.');}
        if($expectedRevision!==null && !preg_match('/^[a-f0-9]{64}$/D',$expectedRevision)) {throw new ApiProblem(422,'invalid_form','Le formulaire doit être rechargé.');}
        return $this->db->transactional(function() use($actor,$storeId,$articleId,$body,$expectedRevision): array {
            // Same global order as movement engines; shared inventory guard also serializes with closure.
            foreach(['users','user_stores','stores','articles','inventories'] as $table) {$this->db->executeStatement('LOCK TABLE '.$table.' IN SHARE MODE');}
            $before=$this->get($actor,$storeId,$articleId)['data'];
            // A transaction-scoped pair lock covers the virtual stock before its first physical row exists.
            $this->db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['stock-thresholds:'.$before['store_id'].':'.$before['article_id']]);
            $this->db->fetchAssociative('SELECT quantity FROM stock_levels WHERE store_id=? AND article_id=? FOR UPDATE',[$before['store_id'],$before['article_id']]);
            $before=$this->get($actor,$storeId,$articleId)['data'];
            if($expectedRevision!==null && !hash_equals($this->revision($before),$expectedRevision)) {throw new ApiProblem(409,'stale_thresholds','Les seuils ont changé depuis l’ouverture du formulaire. Rechargez-les avant de réessayer.');}
            $low=$body->low_threshold;$critical=property_exists($body,'critical_threshold')?$body->critical_threshold:$before['critical_threshold'];
            if($critical!==null && ($low===null || $critical>$low)) {throw new ApiProblem(422,'invalid_thresholds','Le seuil critique nécessite un seuil bas et doit lui être inférieur ou égal.');}
            if($before['low_threshold']===$low && $before['critical_threshold']===$critical) {return ['data'=>$before];}
            // ON CONFLICT preserves a balance concurrently created by a movement after the missing-row read.
            $this->db->executeStatement('INSERT INTO stock_levels (article_id,store_id,low_threshold,critical_threshold) VALUES (?,?,?,?) ON CONFLICT (article_id,store_id) DO UPDATE SET low_threshold=EXCLUDED.low_threshold,critical_threshold=EXCLUDED.critical_threshold',[$before['article_id'],$before['store_id'],$low,$critical]);
            $this->db->executeStatement("INSERT INTO audit_logs (actor_id,store_id,action,target_type,target_id,context) VALUES (?,?,'stock.thresholds_updated','article',?,?::jsonb)",[$actor->id,$before['store_id'],$before['article_id'],json_encode(['before'=>['low_threshold'=>$before['low_threshold'],'critical_threshold'=>$before['critical_threshold']],'after'=>['low_threshold'=>$low,'critical_threshold'=>$critical]],JSON_THROW_ON_ERROR)]);
            return $this->stocks->detail($actor,$storeId,$articleId);
        });
    }
}
