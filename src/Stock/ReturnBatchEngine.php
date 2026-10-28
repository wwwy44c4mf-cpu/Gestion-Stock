<?php

declare(strict_types=1);
namespace App\Stock;
use App\Api\{ApiProblem,IdempotencyKey,OpenApiContract};
use App\Security\{AccessIdentity,AccessPolicy,AccessTarget,Permission,StockUser};
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** A single confirmed deposit; immutable allocations preserve the original consumptions. */
final class ReturnBatchEngine
{
    public function __construct(private readonly Connection $db, private readonly AccessIdentity $identity,
        private readonly AccessPolicy $policy, private readonly StockReturnEngine $returns,
        private readonly OpenApiContract $contract, private readonly IdempotencyKey $keys,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire('%kernel.environment%')] private readonly string $environment) {}

    public function record(StockUser $actor, \stdClass $body, string $key): array
    {
        $this->identity->requireRole($actor,Permission::RETURN_CREATE);
        if(!preg_match('/^[A-Za-z0-9._:-]{8,160}$/D',$key) || !$this->contract->valid($body,['$ref'=>'#/components/schemas/ReturnBatchCreate']) || !is_int($body->quantity??null)) {
            throw new ApiProblem(422,'invalid_return','Vérifiez l’article, le magasin de dépôt et la quantité entière.');
        }
        if(isset($body->comment) && (str_contains($body->comment,"\0") || !preg_match('//u',$body->comment))) { throw new ApiProblem(422,'invalid_text','Commentaire invalide.'); }
        $body=clone $body;
        foreach(['article_id','store_id'] as $field) { $body->$field=strtolower($body->$field); }
        $body->consumer_id=strtolower($body->consumer_id??$actor->id);
        $fingerprint=hash_hmac('sha256',$this->keys->fingerprint($actor->id,'POST','/stock-engine/return-batch',$body),$this->secret);
        return $this->db->transactional(function() use($actor,$body,$key,$fingerprint): array {
            foreach(['users','user_stores','stores','articles','inventories'] as $table) { $this->db->executeStatement('LOCK TABLE '.$table.' IN SHARE MODE'); }
            $this->identity->requireRole($actor,Permission::RETURN_CREATE);
            $this->policy->assert($actor,Permission::RETURN_CREATE,AccessTarget::store($body->store_id));
            if($body->consumer_id!==$actor->id) { $this->policy->assert($actor,Permission::CONSUMPTION_OTHER,AccessTarget::store($body->store_id)); }
            $this->db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['stock-request:'.$actor->id.':'.$key]);
            $previous=$this->db->fetchAssociative('SELECT return_group_id,request_fingerprint FROM stock_movements WHERE author_id=? AND idempotency_key=?',[$actor->id,$key]);
            if($previous) {
                if(!$previous['return_group_id'] || !$previous['request_fingerprint'] || !hash_equals($previous['request_fingerprint'],$fingerprint)) { throw new ApiProblem(409,'idempotency_conflict','Cette clé correspond à une autre opération.'); }
                return ['data'=>$this->resource($previous['return_group_id'])];
            }
            if(!$this->db->fetchOne("SELECT 1 FROM users WHERE id=? AND active AND anonymized_at IS NULL AND (NOT development_only OR ?='dev')",[$body->consumer_id,$this->environment])) {
                throw new ApiProblem(422,'invalid_beneficiary','Choisissez un bénéficiaire actif.');
            }
            if(!$this->db->fetchOne('SELECT 1 FROM articles WHERE id=?',[$body->article_id])) { throw new ApiProblem(404,'article_not_found','Article introuvable.'); }
            // Lock originals before calculating remaining quantities in a NEW snapshot.
            // A concurrent immutable return does not update its original row.
            $ids=$this->db->fetchFirstColumn("SELECT id FROM stock_movements WHERE type='consumption' AND article_id=? AND consumer_id=? ORDER BY created_at,id FOR UPDATE",[$body->article_id,$body->consumer_id]);
            $allocations=[];$left=$body->quantity;
            foreach($ids as $id) {
                $remaining=(int)$this->db->fetchOne("SELECT m.quantity-(SELECT coalesce(sum(r.quantity),0) FROM stock_movements r WHERE r.type='return' AND r.related_movement_id=m.id AND NOT EXISTS(SELECT 1 FROM stock_movements u WHERE u.type='reversal' AND u.related_movement_id=r.id)) FROM stock_movements m WHERE m.id=? AND NOT EXISTS(SELECT 1 FROM stock_movements u WHERE u.type='reversal' AND u.related_movement_id=m.id)",[$id]);
                if($remaining<=0) { continue; }
                $quantity=min($remaining,$left);$allocations[]=[$id,$quantity];$left-=$quantity;
                if($left===0) { break; }
            }
            if($left>0) { throw new ApiProblem(409,'excessive_return','La quantité dépasse les unités encore à rendre pour cet article et ce bénéficiaire.'); }
            $group=$this->db->fetchOne('INSERT INTO stock_return_groups(article_id,store_id,quantity) VALUES(?,?,?) RETURNING id',[$body->article_id,$body->store_id,$body->quantity]);
            foreach($allocations as $index=>[$origin,$quantity]) {
                $allocation=(object)['consumption_id'=>$origin,'store_id'=>$body->store_id,'quantity'=>$quantity];
                if(isset($body->comment)) { $allocation->comment=$body->comment; }
                // Each allocation needs its own key in the shared actor/request namespace.
                $allocationKey=$index===0?$key:'batch:'.hash('sha256',$group.':'.$index);
                $this->returns->record($actor,$allocation,$allocationKey,$group,$fingerprint);
            }
            return ['data'=>$this->resource($group)];
        });
    }
    private function resource(string $id): array
    {
        $row=$this->db->fetchAssociative('SELECT id,article_id,store_id,quantity,created_at FROM stock_return_groups WHERE id=?',[$id]);
        $row['quantity']=(int)$row['quantity'];$row['created_at']=(new \DateTimeImmutable($row['created_at']))->format(DATE_RFC3339);
        return $row;
    }
}
