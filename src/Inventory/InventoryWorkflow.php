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
        if(!in_array($action,['start','submit','cancel','validate'],true)) {throw new ApiProblem(422,'invalid_transition','Transition d’inventaire invalide.');}
        $this->policy->assert($actor,Permission::INVENTORY_WRITE,AccessTarget::inventory($id));
        return $this->execute($actor,$action,strtolower($id),new \stdClass(),$key);
    }
    public function write(StockUser $actor,string $id,\stdClass $body,string $key): array
    {
        $this->identity->requireRole($actor,Permission::INVENTORY_WRITE);
        if(!$this->contract->valid($body,['$ref'=>'#/components/schemas/CountsWrite'])) {throw new ApiProblem(422,'validation_failed','Vérifiez les quantités et les versions des lignes.');}
        $this->policy->assert($actor,Permission::INVENTORY_WRITE,AccessTarget::inventory($id));
        $lines=[];foreach($body->lines as $line) {$line=clone $line;$line->article_id=strtolower($line->article_id);if(isset($lines[$line->article_id])) {throw new ApiProblem(422,'duplicate_article','Un article apparaît plusieurs fois dans le comptage.');}$lines[$line->article_id]=$line;}
        ksort($lines,SORT_STRING);return $this->execute($actor,'counts',strtolower($id),(object)['lines'=>array_values($lines)],$key);
    }
    private function execute(StockUser $actor,string $action,?string $id,\stdClass $body,string $key): array
    {
        if(!preg_match('/^[A-Za-z0-9._:-]{8,160}$/D',$key)) {throw new ApiProblem(422,'invalid_idempotency_key','Clé d’opération invalide.');}
        $fingerprint=hash_hmac('sha256',$this->keys->fingerprint($actor->id,'POST','/inventory-workflow/'.$action.'/'.$id,$body),$this->secret);
        return $this->db->transactional(function() use($actor,$action,$id,$body,$key,$fingerprint): array {
            // Global order matches all movement engines and account/catalog/store administration.
            $this->db->executeStatement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
            foreach(['user_stores','stores','articles'] as $table) {$this->db->executeStatement('LOCK TABLE '.$table.' IN SHARE MODE');}
            $this->db->executeStatement('LOCK TABLE inventories IN SHARE ROW EXCLUSIVE MODE');
            $this->identity->requireRole($actor,Permission::INVENTORY_WRITE);
            if($action==='create') {$this->policy->assert($actor,Permission::INVENTORY_WRITE,AccessTarget::store($body->store_id));$store=$body->store_id;}
            else {$this->policy->assert($actor,Permission::INVENTORY_WRITE,AccessTarget::inventory($id));$store=$this->db->fetchOne('SELECT store_id FROM inventories WHERE id=?',[$id]);}
            $this->db->fetchOne('SELECT id FROM stores WHERE id=? FOR UPDATE',[$store]);
            $previous=$this->db->fetchAssociative('SELECT request_fingerprint,response FROM inventory_requests WHERE actor_id=? AND request_key=?',[$actor->id,$key]);
            if($previous) {if(!hash_equals($previous['request_fingerprint'],$fingerprint)) {throw new ApiProblem(409,'idempotency_conflict','Cette clé a déjà servi pour une autre opération.');}return $this->replay(json_decode($previous['response'],true,flags:JSON_THROW_ON_ERROR),$action);}
            $context=[];
            if($action==='create') {
                $row=$this->db->fetchAssociative('INSERT INTO inventories (store_id,created_by,created_at) VALUES (?,?,clock_timestamp()) RETURNING *',[$store,$actor->id]);
            } else {
                $row=$this->db->fetchAssociative('SELECT * FROM inventories WHERE id=? FOR UPDATE',[$id]);
                $allowed=match($action) {'start'=>['draft'],'submit'=>['in_progress'],'counts'=>['in_progress'],'validate'=>['pending_validation'],'cancel'=>['draft','in_progress','pending_validation']};
                if(!in_array($row['status'],$allowed,true)) {throw new ApiProblem(409,'invalid_inventory_state','L’état de cet inventaire ne permet plus cette action.');}
                if($action==='start') {
                    if($this->db->fetchOne("SELECT 1 FROM inventories WHERE store_id=? AND status IN ('in_progress','pending_validation')",[$store])) {throw new ApiProblem(409,'inventory_in_progress','Un inventaire bloque déjà ce magasin.');}
                    $row=$this->db->fetchAssociative("UPDATE inventories SET status='in_progress',snapshot_at=clock_timestamp() WHERE id=? RETURNING *",[$id]);
                    $this->snapshot($id,$store);
                } elseif($action==='validate') {
                    $context['adjustment_ids']=$this->close($actor,$row,$fingerprint);
                    $row=$this->db->fetchAssociative("UPDATE inventories SET status='closed',validated_by=?,closed_at=clock_timestamp() WHERE id=? RETURNING *",[$actor->id,$id]);
                } elseif($action==='counts') {
                    $context['lines']=$this->count($actor,$id,$body->lines);
                } elseif($action==='submit') {
                    if($this->db->fetchOne('SELECT 1 FROM inventory_lines WHERE inventory_id=? AND counted_qty IS NULL',[$id])) {throw new ApiProblem(409,'incomplete_inventory','Toutes les quantités doivent être comptées avant la soumission.');}
                    $row=$this->db->fetchAssociative("UPDATE inventories SET status='pending_validation' WHERE id=? RETURNING *",[$id]);
                } else {$row=$this->db->fetchAssociative("UPDATE inventories SET status='cancelled',cancelled_at=clock_timestamp() WHERE id=? RETURNING *",[$id]);}
            }
            // AFTER triggers may erase old identities at closure; RETURNING
            // contains the pre-trigger row and must never enter the replay cache.
            if (in_array($action,['validate','cancel'],true)) { $row=$this->db->fetchAssociative('SELECT * FROM inventories WHERE id=?',[$id]); }
            $result=$action==='validate'?['data'=>['inventory'=>$this->directory->resource($row),'adjustment_ids'=>$context['adjustment_ids']]]:['data'=>$this->directory->resource($row)];
            $this->db->executeStatement("INSERT INTO audit_logs (actor_id,store_id,action,target_type,target_id,context) VALUES (?,?,?,'inventory',?,?::jsonb)",[$actor->id,$store,'inventory.'.$action,$row['id'],json_encode(['status'=>$row['status'],...$context],JSON_THROW_ON_ERROR)]);
            $this->db->executeStatement('INSERT INTO inventory_requests (actor_id,request_key,request_fingerprint,inventory_id,response) VALUES (?,?,?,?,?::jsonb)',[$actor->id,$key,$fingerprint,$row['id'],json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }
    private function replay(array $response,string $action): array
    {
        if($action==='validate') {return ['data'=>['inventory'=>$this->directory->resource($response['data']['inventory']),'adjustment_ids'=>$response['data']['adjustment_ids']]];}
        return ['data'=>$this->directory->resource($response['data'])];
    }
    private function close(StockUser $actor,array $inventory,string $fingerprint): array
    {
        if($this->db->fetchOne('SELECT 1 FROM stock_movements WHERE inventory_id=?',[$inventory['id']])) {throw new ApiProblem(409,'inventory_already_adjusted','Des ajustements existent déjà pour cette campagne. La clôture est refusée.');}
        $lines=$this->db->fetchAllAssociative('SELECT article_id,theoretical_qty,counted_qty,delta FROM inventory_lines WHERE inventory_id=? ORDER BY article_id FOR UPDATE',[$inventory['id']]);
        foreach($lines as $line) {if($line['counted_qty']===null) {throw new ApiProblem(409,'incomplete_inventory','Toutes les quantités doivent être comptées avant la validation.');}}
        // Validate and lock every existing balance before recording any adjustment.
        foreach($lines as $line) {
            $balance=$this->db->fetchAssociative('SELECT quantity,version FROM stock_levels WHERE article_id=? AND store_id=? FOR UPDATE',[$line['article_id'],$inventory['store_id']]);
            if((int)($balance['quantity']??0)!==(int)$line['theoretical_qty']) {throw new ApiProblem(409,'inventory_stock_drift','Un solde ne correspond plus à la référence figée. La clôture est refusée.');}
            if((int)$line['delta']!==0 && (string)($balance['version']??0)===(string)PHP_INT_MAX) {throw new ApiProblem(409,'stock_limit_reached','La limite de version d’un solde est atteinte.');}
        }
        $ids=[];
        foreach($lines as $line) {
            $delta=(int)$line['delta'];if($delta===0) {continue;}
            $this->db->executeStatement('INSERT INTO stock_levels (article_id,store_id) VALUES (?,?) ON CONFLICT DO NOTHING',[$line['article_id'],$inventory['store_id']]);
            $this->db->executeStatement('UPDATE stock_levels SET quantity=?,version=version+1 WHERE article_id=? AND store_id=?',[(int)$line['counted_qty'],$line['article_id'],$inventory['store_id']]);
            $movement=$this->db->fetchOne("INSERT INTO stock_movements (type,article_id,source_store_id,destination_store_id,quantity,author_id,inventory_id,reason,idempotency_key,request_fingerprint) VALUES ('adjustment',?,?,?,?,?,?,?, ?,?) RETURNING id",[$line['article_id'],$delta<0?$inventory['store_id']:null,$delta>0?$inventory['store_id']:null,abs($delta),$actor->id,$inventory['id'],'Écart constaté lors de la validation de l’inventaire',hash_hmac('sha256','inventory-adjustment:'.$inventory['id'].':'.$line['article_id'],$this->secret),$fingerprint]);
            $this->db->executeStatement('INSERT INTO stock_change_events (movement_id,article_id,store_ids) VALUES (?,?,?::uuid[])',[$movement,$line['article_id'],'{'.$inventory['store_id'].'}']);
            $this->db->executeStatement("INSERT INTO audit_logs (actor_id,store_id,action,target_type,target_id,context) VALUES (?,?,'stock.adjustment','stock_movement',?,?::jsonb)",[$actor->id,$inventory['store_id'],$movement,json_encode(['type'=>'adjustment','inventory_id'=>$inventory['id'],'delta'=>$delta],JSON_THROW_ON_ERROR)]);
            $ids[]=$movement;
        }
        return $ids;
    }
    private function count(StockUser $actor,string $id,array $lines): array
    {
        // Validate the whole batch under the campaign guard before changing any row.
        $changes=[];
        foreach($lines as $line) {
            $current=$this->db->fetchAssociative('SELECT version,counted_qty FROM inventory_lines WHERE inventory_id=? AND article_id=? FOR UPDATE',[$id,$line->article_id]);
            if(!$current) {throw new ApiProblem(422,'article_outside_inventory','Un article ne fait pas partie de la référence de cet inventaire.');}
            if((int)$current['version']!==$line->expected_version) {throw new ApiProblem(409,'stale_inventory_line','Un comptage a changé depuis l’ouverture de cet écran. Rechargez les lignes avant de réessayer.');}
            $changes[]=['article_id'=>$line->article_id,'previous_qty'=>$current['counted_qty']===null?null:(int)$current['counted_qty'],'counted_qty'=>$line->counted_qty,'previous_version'=>(int)$current['version'],'version'=>(int)$current['version']+1];
            if($line->expected_version>=9007199254740991) {throw new ApiProblem(409,'inventory_version_limit','La limite de version de ce comptage est atteinte.');}
        }
        foreach($lines as $line) {$this->db->executeStatement('UPDATE inventory_lines SET counted_qty=?,counted_by=?,counted_at=clock_timestamp() WHERE inventory_id=? AND article_id=?',[$line->counted_qty,$actor->id,$id,$line->article_id]);}
        return $changes;
    }
    private function snapshot(string $id,string $store): void
    {
        // No balance is created for an article whose theoretical quantity is zero.
        $this->db->executeStatement('INSERT INTO inventory_lines (inventory_id,article_id,theoretical_qty) SELECT ?,a.id,coalesce(sl.quantity,0) FROM articles a LEFT JOIN stock_levels sl ON sl.article_id=a.id AND sl.store_id=? WHERE a.active OR coalesce(sl.quantity,0)>0 ORDER BY a.id',[$id,$store]);
    }
}
