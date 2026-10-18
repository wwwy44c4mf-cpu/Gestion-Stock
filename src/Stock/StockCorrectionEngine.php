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

/** Exact counter-entry and optional replacement, with only the net effect applied to balances. */
final class StockCorrectionEngine
{
    private const MAX_QUANTITY = 9007199254740991;
    public function __construct(private readonly Connection $db, private readonly AccessIdentity $identity,
        private readonly AccessPolicy $policy, private readonly OpenApiContract $contract, private readonly IdempotencyKey $keys,
        private readonly StockMovementEngine $movements, #[Autowire('%kernel.secret%')] private readonly string $secret) {}

    public function correct(StockUser $actor, string $id, \stdClass $body, string $key, bool $cancel = false): array
    {
        $this->identity->requireRole($actor, Permission::MOVEMENT_REVERSE);
        $schema = $cancel ? 'ReversalCreate' : 'MovementCorrectionCreate';
        if (!preg_match('/^[A-Za-z0-9._:-]{8,160}$/D',$key) || !$this->contract->valid($body,['$ref'=>'#/components/schemas/'.$schema])
            || !isset($body->reason) || trim($body->reason) === '' || str_contains($body->reason,"\0") || !preg_match('//u',$body->reason)
            || (!$cancel && !is_int($body->quantity))) {
            throw new ApiProblem(422,'invalid_correction','Saisissez un motif et une quantité entière valide pour la correction.');
        }
        $id = strtolower($id);
        $fingerprint = hash_hmac('sha256',$this->keys->fingerprint($actor->id,'POST','/movements/'.$id.($cancel ? '/reverse' : '/correct'),$body),$this->secret);
        return $this->db->transactional(function () use ($actor,$id,$body,$key,$cancel,$fingerprint): array {
            foreach (['users','user_stores','stores','articles','inventories'] as $table) { $this->db->executeStatement('LOCK TABLE '.$table.' IN SHARE MODE'); }
            $this->policy->assert($actor,Permission::MOVEMENT_REVERSE,AccessTarget::movement($id));
            $this->db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['stock-request:'.$actor->id.':'.$key]);
            $previous = $this->db->fetchAssociative('SELECT * FROM stock_movements WHERE author_id = ? AND idempotency_key = ?',[$actor->id,$key]);
            if ($previous) {
                if ($previous['request_fingerprint'] === null || !hash_equals($previous['request_fingerprint'],$fingerprint)) { throw new ApiProblem(409,'idempotency_conflict','Cette clé correspond à une autre opération.'); }
                return $cancel ? ['data'=>$this->movements->resource($previous)] : $this->result($id,$previous);
            }
            // All corrections/returns for the same origin must serialize on this row.
            $original = $this->db->fetchAssociative('SELECT * FROM stock_movements WHERE id = ? FOR UPDATE',[$id]);
            if ($original['type'] === 'reversal' || $original['inventory_id'] !== null) { throw new ApiProblem(409,'uncorrectable_movement','Ce mouvement ne peut pas être corrigé ici.'); }
            if ($this->db->fetchOne("SELECT 1 FROM stock_movements WHERE type = 'reversal' AND related_movement_id = ?",[$id])) { throw new ApiProblem(409,'already_reversed','Ce mouvement a déjà été annulé ou corrigé.'); }
            $target = $cancel ? 0 : $body->quantity;
            if ($target === (int)$original['quantity']) { throw new ApiProblem(422,'unchanged_quantity','La quantité correcte doit être différente de la quantité initiale.'); }
            if ($original['type'] === 'consumption' && $this->db->fetchOne("SELECT 1 FROM stock_movements r WHERE r.type = 'return' AND r.related_movement_id = ? AND NOT EXISTS (SELECT 1 FROM stock_movements undo WHERE undo.type = 'reversal' AND undo.related_movement_id = r.id)",[$id])) {
                throw new ApiProblem(409,'consumption_has_returns','Annulez les retours liés avant de corriger cette consommation.');
            }
            // A corrected return still belongs to a live consumption, within its remaining quantity.
            if ($original['type'] === 'return' && !$cancel) {
                $origin = $this->db->fetchAssociative("SELECT * FROM stock_movements WHERE id = ? FOR UPDATE",[$original['related_movement_id']]);
                $returned = (int)$this->db->fetchOne("SELECT coalesce(sum(r.quantity),0) FROM stock_movements r WHERE r.type = 'return' AND r.related_movement_id = ? AND r.id <> ? AND NOT EXISTS (SELECT 1 FROM stock_movements undo WHERE undo.type = 'reversal' AND undo.related_movement_id = r.id)",[$origin['id'],$id]);
                if ($this->db->fetchOne("SELECT 1 FROM stock_movements WHERE type = 'reversal' AND related_movement_id = ?",[$origin['id']]) || $target > (int)$origin['quantity'] - $returned) { throw new ApiProblem(409,'invalid_return_correction','Cette correction dépasse la consommation encore retournable.'); }
            }
            if (!$cancel && $original['type'] === 'consumption') {
                if (!$this->db->fetchOne('SELECT active FROM articles WHERE id = ?',[$original['article_id']])) { throw new ApiProblem(409,'inactive_article','Réactivez l’article avant de corriger la consommation, ou annulez-la.'); }
                $this->policy->assertConsumption($actor,$original['source_store_id'],$original['consumer_id']);
            }
            $stores = array_values(array_filter([$original['source_store_id'],$original['destination_store_id']])); sort($stores,SORT_STRING);
            $delta = $target - (int)$original['quantity'];
            foreach ($stores as $store) {
                if ($this->db->fetchOne("SELECT 1 FROM inventories WHERE store_id = ? AND status IN ('in_progress','pending_validation')",[$store])) { throw new ApiProblem(409,'inventory_in_progress','Un inventaire bloque les mouvements de ce magasin.'); }
                $this->db->executeStatement('INSERT INTO stock_levels (article_id,store_id) VALUES (?,?) ON CONFLICT DO NOTHING',[$original['article_id'],$store]);
                $balance = $this->db->fetchAssociative('SELECT quantity,version FROM stock_levels WHERE article_id = ? AND store_id = ? FOR UPDATE',[$original['article_id'],$store]);
                $next = (int)$balance['quantity'] + ($store === $original['source_store_id'] ? -$delta : $delta);
                if ($next < 0) { throw new ApiProblem(409,'insufficient_stock','Le stock disponible est insuffisant pour cette correction.'); }
                if ($next > self::MAX_QUANTITY || (string)$balance['version'] === (string)PHP_INT_MAX) { throw new ApiProblem(409,'stock_limit_reached','La limite du stock ou de sa version est atteinte.'); }
                $this->db->executeStatement('UPDATE stock_levels SET quantity = ?,version = version + 1 WHERE article_id = ? AND store_id = ?',[$next,$original['article_id'],$store]);
            }
            $reversal = $this->insert($actor,$original,'reversal',(int)$original['quantity'],$original['destination_store_id'],$original['source_store_id'],null,$id,trim($body->reason),$key,$fingerprint,$stores);
            if ($cancel) { return ['data'=>$this->movements->resource($reversal)]; }
            // A fresh UUID key is internal, never used as the public replay key.
            $replacement = $this->insert($actor,$original,$original['type'],$target,$original['source_store_id'],$original['destination_store_id'],$original['consumer_id'],$original['related_movement_id'],in_array($original['type'],['entry','adjustment'],true) ? trim($body->reason) : null,bin2hex(random_bytes(32)),null,$stores);
            $this->db->insert('movement_corrections',['original_id'=>$id,'reversal_id'=>$reversal['id'],'replacement_id'=>$replacement['id']]);
            return $this->result($id,$reversal,$replacement);
        });
    }

    private function insert(StockUser $actor,array $original,string $type,int $quantity,?string $source,?string $destination,?string $consumer,?string $related,?string $reason,string $key,?string $fingerprint,array $stores): array
    {
        $row = $this->db->fetchAssociative('INSERT INTO stock_movements (type,article_id,source_store_id,destination_store_id,quantity,author_id,consumer_id,related_movement_id,reason,reference,comment,idempotency_key,request_fingerprint) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING *',[$type,$original['article_id'],$source,$destination,$quantity,$actor->id,$consumer,$related,$reason,$original['reference'],$original['comment'],$key,$fingerprint]);
        $this->db->executeStatement('INSERT INTO stock_change_events (movement_id,article_id,store_ids) VALUES (?,?,?::uuid[])',[$row['id'],$original['article_id'],'{'.implode(',',$stores).'}']);
        $this->db->executeStatement('INSERT INTO audit_logs (actor_id,store_id,action,target_type,target_id,context) VALUES (?,?,?,?,?,?::jsonb)',[$actor->id,$source ?? $destination,$type === 'reversal' ? 'stock.reversal' : 'stock.correction_replacement','stock_movement',$row['id'],json_encode(['original_id'=>$original['id'],'type'=>$type],JSON_THROW_ON_ERROR)]);
        return $row;
    }
    private function result(string $id,array $reversal,?array $replacement = null): array
    {
        $replacement ??= $this->db->fetchAssociative('SELECT m.* FROM stock_movements m JOIN movement_corrections c ON c.replacement_id = m.id WHERE c.original_id = ?',[$id]);
        return ['data'=>['original_movement_id'=>$id,'reversal'=>$this->movements->resource($reversal),'replacement'=>$this->movements->resource($replacement)]];
    }
}
