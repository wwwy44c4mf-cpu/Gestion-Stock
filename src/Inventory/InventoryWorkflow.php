<?php

declare(strict_types=1);
namespace App\Inventory;
use App\Api\ApiProblem;
use App\Api\IdempotencyKey;
use App\Api\OpenApiContract;
use App\Security\AccessIdentity;
use App\Security\AccessPolicy;
use App\Security\AccessTarget;
use App\Security\Permission;
use App\Security\StockUser;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** The inventory table guard conflicts with every stock engine's shared guard. */
final class InventoryWorkflow
{
    public function __construct(private readonly Connection $db,private readonly AccessIdentity $identity,private readonly AccessPolicy $policy,
        private readonly InventoryDirectory $directory,private readonly OpenApiContract $contract,private readonly IdempotencyKey $keys,
        #[Autowire('%kernel.secret%')] private readonly string $secret) {}

    public function create(StockUser $actor,\stdClass $body,string $key): array
    {
        $this->identity->requireRole($actor,Permission::INVENTORY_WRITE);
        if(!$this->contract->valid($body,['$ref'=>'#/components/schemas/InventoryCreate'])) {throw new ApiProblem(422,'validation_failed','Choisissez un magasin autorisé.');}
        $body=clone $body;$body->store_id=strtolower($body->store_id);
        return $this->execute($actor,'create',null,$body,$key);
    }
    public function transition(StockUser $actor,string $id,string $action,string $key): array
    {
        $this->identity->requireRole($actor,Permission::INVENTORY_WRITE);
        if(!in_array($action,['start','submit','cancel'],true)) {throw new ApiProblem(422,'invalid_transition','Transition d’inventaire invalide.');}
        $this->policy->assert($actor,Permission::INVENTORY_WRITE,AccessTarget::inventory($id));
        return $this->execute($actor,$action,strtolower($id),new \stdClass(),$key);
    }
    private function execute(StockUser $actor,string $action,?string $id,\stdClass $body,string $key): array
    {
        if(!preg_match('/^[A-Za-z0-9._:-]{8,160}$/D',$key)) {throw new ApiProblem(422,'invalid_idempotency_key','Clé d’opération invalide.');}
        $fingerprint=hash_hmac('sha256',$this->keys->fingerprint($actor->id,'POST','/inventory-workflow/'.$action.'/'.$id,$body),$this->secret);
        return $this->db->transactional(function() use($actor,$action,$id,$body,$key,$fingerprint): array {
            // Global order matches all movement engines and account/catalog/store administration.
            foreach(['users','user_stores','stores','articles'] as $table) {$this->db->executeStatement('LOCK TABLE '.$table.' IN SHARE MODE');}
            $this->db->executeStatement('LOCK TABLE inventories IN SHARE ROW EXCLUSIVE MODE');
            $this->identity->requireRole($actor,Permission::INVENTORY_WRITE);
            if($action==='create') {$this->policy->assert($actor,Permission::INVENTORY_WRITE,AccessTarget::store($body->store_id));$store=$body->store_id;}
            else {$this->policy->assert($actor,Permission::INVENTORY_WRITE,AccessTarget::inventory($id));$store=$this->db->fetchOne('SELECT store_id FROM inventories WHERE id=?',[$id]);}
            $this->db->fetchOne('SELECT id FROM stores WHERE id=? FOR UPDATE',[$store]);
            $previous=$this->db->fetchAssociative('SELECT request_fingerprint,response FROM inventory_requests WHERE actor_id=? AND request_key=?',[$actor->id,$key]);
            if($previous) {if(!hash_equals($previous['request_fingerprint'],$fingerprint)) {throw new ApiProblem(409,'idempotency_conflict','Cette clé a déjà servi pour une autre opération.');}return ['data'=>$this->directory->resource(json_decode($previous['response'],true,flags:JSON_THROW_ON_ERROR)['data'])];}
            if($action==='create') {
                $row=$this->db->fetchAssociative('INSERT INTO inventories (store_id,created_by,created_at) VALUES (?,?,clock_timestamp()) RETURNING *',[$store,$actor->id]);
            } else {
                $row=$this->db->fetchAssociative('SELECT * FROM inventories WHERE id=? FOR UPDATE',[$id]);
                $allowed=match($action) {'start'=>['draft'],'submit'=>['in_progress'],'cancel'=>['draft','in_progress','pending_validation']};
                if(!in_array($row['status'],$allowed,true)) {throw new ApiProblem(409,'invalid_inventory_state','L’état de cet inventaire ne permet plus cette action.');}
                if($action==='start') {
                    if($this->db->fetchOne("SELECT 1 FROM inventories WHERE store_id=? AND status IN ('in_progress','pending_validation')",[$store])) {throw new ApiProblem(409,'inventory_in_progress','Un inventaire bloque déjà ce magasin.');}
                    $row=$this->db->fetchAssociative("UPDATE inventories SET status='in_progress',snapshot_at=clock_timestamp() WHERE id=? RETURNING *",[$id]);
                    $this->snapshot($id,$store);
                } elseif($action==='submit') {
                    if($this->db->fetchOne('SELECT 1 FROM inventory_lines WHERE inventory_id=? AND counted_qty IS NULL',[$id])) {throw new ApiProblem(409,'incomplete_inventory','Toutes les quantités doivent être comptées avant la soumission.');}
                    $row=$this->db->fetchAssociative("UPDATE inventories SET status='pending_validation' WHERE id=? RETURNING *",[$id]);
                } else {$row=$this->db->fetchAssociative("UPDATE inventories SET status='cancelled',cancelled_at=clock_timestamp() WHERE id=? RETURNING *",[$id]);}
            }
            $result=['data'=>$this->directory->resource($row)];
            $this->db->executeStatement("INSERT INTO audit_logs (actor_id,store_id,action,target_type,target_id,context) VALUES (?,?,?,'inventory',?,?::jsonb)",[$actor->id,$store,'inventory.'.$action,$row['id'],json_encode(['status'=>$row['status']],JSON_THROW_ON_ERROR)]);
            $this->db->executeStatement('INSERT INTO inventory_requests (actor_id,request_key,request_fingerprint,inventory_id,response) VALUES (?,?,?,?,?::jsonb)',[$actor->id,$key,$fingerprint,$row['id'],json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }
    private function snapshot(string $id,string $store): void
    {
        // No balance is created for an article whose theoretical quantity is zero.
        $this->db->executeStatement('INSERT INTO inventory_lines (inventory_id,article_id,theoretical_qty) SELECT ?,a.id,coalesce(sl.quantity,0) FROM articles a LEFT JOIN stock_levels sl ON sl.article_id=a.id AND sl.store_id=? WHERE a.active OR coalesce(sl.quantity,0)>0 ORDER BY a.id',[$id,$store]);
    }
}
