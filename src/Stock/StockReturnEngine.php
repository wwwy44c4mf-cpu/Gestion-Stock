<?php

declare(strict_types=1);
namespace App\Stock;
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

/** The immutable origin serializes returns and corrections before the balance lock. */
final class StockReturnEngine
{
    private const MAX_QUANTITY = 9007199254740991;
    public function __construct(private readonly Connection $db,private readonly AccessIdentity $identity,private readonly AccessPolicy $policy,
        private readonly OpenApiContract $contract,private readonly IdempotencyKey $keys,private readonly StockMovementEngine $movements,
        #[Autowire('%kernel.secret%')] private readonly string $secret) {}
    public function record(StockUser $actor,\stdClass $body,string $key, ?string $groupId=null, ?string $batchFingerprint=null): array
    {
        $this->identity->requireRole($actor,Permission::RETURN_CREATE);
        if (!preg_match('/^[A-Za-z0-9._:-]{8,160}$/D',$key) || !$this->contract->valid($body,['$ref'=>'#/components/schemas/'.($groupId===null?'ReturnCreate':'ReturnAllocationCreate')]) || !isset($body->quantity) || !is_int($body->quantity)) {
            throw new ApiProblem(422,'invalid_return','Vérifiez la consommation et la quantité entière du retour.');
        }
        if (isset($body->comment) && (str_contains($body->comment,"\0") || !preg_match('//u',$body->comment))) { throw new ApiProblem(422,'invalid_text','Le commentaire est invalide.'); }
        $body=clone $body;$body->consumption_id=strtolower($body->consumption_id);
        if(isset($body->store_id)) { $body->store_id=strtolower($body->store_id); }
        $fingerprint=$batchFingerprint??hash_hmac('sha256',$this->keys->fingerprint($actor->id,'POST','/stock-engine/return',$body),$this->secret);
        return $this->db->transactional(function () use ($actor,$body,$key,$fingerprint,$groupId): array {
            foreach (['users','user_stores','stores','articles','inventories'] as $table) { $this->db->executeStatement('LOCK TABLE '.$table.' IN SHARE MODE'); }
            if($groupId===null) { $this->policy->assert($actor,isset($body->store_id)?Permission::CONSUMPTION_READ:Permission::RETURN_CREATE,AccessTarget::consumption($body->consumption_id)); }
            if(isset($body->store_id)) { $this->policy->assert($actor,Permission::RETURN_CREATE,AccessTarget::store($body->store_id)); }
            $this->db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['stock-request:'.$actor->id.':'.$key]);
            $previous=$this->db->fetchAssociative('SELECT * FROM stock_movements WHERE author_id = ? AND idempotency_key = ?',[$actor->id,$key]);
            if ($previous) {
                if ($previous['request_fingerprint']===null || !hash_equals($previous['request_fingerprint'],$fingerprint)) { throw new ApiProblem(409,'idempotency_conflict','Cette clé correspond à une autre opération.'); }
                return ['data'=>$this->movements->resource($previous)];
            }
            $origin=$this->db->fetchAssociative("SELECT * FROM stock_movements WHERE id = ? AND type = 'consumption' FOR UPDATE",[$body->consumption_id]);
            if ($this->db->fetchOne("SELECT 1 FROM stock_movements WHERE type = 'reversal' AND related_movement_id = ?",[$origin['id']])) { throw new ApiProblem(409,'consumption_reversed','Cette consommation a été annulée ou corrigée. Utilisez la consommation corrigée.'); }
            $returned=(int)$this->db->fetchOne("SELECT coalesce(sum(r.quantity),0) FROM stock_movements r WHERE r.type = 'return' AND r.related_movement_id = ? AND NOT EXISTS (SELECT 1 FROM stock_movements undo WHERE undo.type = 'reversal' AND undo.related_movement_id = r.id)",[$origin['id']]);
            if ($body->quantity>(int)$origin['quantity']-$returned) { throw new ApiProblem(409,'excessive_return','La quantité dépasse le nombre d’unités encore à rendre.'); }
            $store=$body->store_id??$origin['source_store_id'];
            if ($this->db->fetchOne("SELECT 1 FROM inventories WHERE store_id = ? AND status IN ('in_progress','pending_validation')",[$store])) { throw new ApiProblem(409,'inventory_in_progress','Un inventaire bloque les mouvements de ce magasin.'); }
            $this->db->executeStatement('INSERT INTO stock_levels (article_id,store_id) VALUES (?,?) ON CONFLICT DO NOTHING',[$origin['article_id'],$store]);
            $balance=$this->db->fetchAssociative('SELECT quantity,version FROM stock_levels WHERE article_id = ? AND store_id = ? FOR UPDATE',[$origin['article_id'],$store]);
            $next=(int)$balance['quantity']+$body->quantity;
            if ($next>self::MAX_QUANTITY || (string)$balance['version']===(string)PHP_INT_MAX) { throw new ApiProblem(409,'stock_limit_reached','La limite du stock ou de sa version est atteinte.'); }
            $this->db->executeStatement('UPDATE stock_levels SET quantity = ?,version = version + 1 WHERE article_id = ? AND store_id = ?',[$next,$origin['article_id'],$store]);
            if($groupId===null) {
                $movement=$this->db->fetchAssociative("INSERT INTO stock_movements (type,article_id,destination_store_id,quantity,author_id,related_movement_id,reference,comment,idempotency_key,request_fingerprint) VALUES ('return',?,?,?,?,?,?,?,?,?) RETURNING *",[$origin['article_id'],$store,$body->quantity,$actor->id,$origin['id'],$origin['reference'],$body->comment??null,$key,$fingerprint]);
            } else {
                $movement=$this->db->fetchAssociative("INSERT INTO stock_movements (type,article_id,destination_store_id,quantity,author_id,related_movement_id,comment,idempotency_key,request_fingerprint,return_group_id) VALUES ('return',?,?,?,?,?,?,?,?,?) RETURNING *",[$origin['article_id'],$store,$body->quantity,$actor->id,$origin['id'],$body->comment??null,$key,$fingerprint,$groupId]);
            }
            $this->db->executeStatement('INSERT INTO stock_change_events (movement_id,article_id,store_ids) VALUES (?,?,?::uuid[])',[$movement['id'],$origin['article_id'],'{'.$store.'}']);
            $this->db->executeStatement('INSERT INTO audit_logs (actor_id,store_id,action,target_type,target_id,context) VALUES (?,?,?,?,?,?::jsonb)',[$actor->id,$store,'stock.return','stock_movement',$movement['id'],json_encode($groupId===null?['consumption_id'=>$origin['id']]:['return_group_id'=>$groupId],JSON_THROW_ON_ERROR)]);
            return ['data'=>$this->movements->resource($movement)];
        });
    }
}
