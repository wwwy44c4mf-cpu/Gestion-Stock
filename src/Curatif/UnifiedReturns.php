<?php

declare(strict_types=1);
namespace App\Curatif;
use App\Api\{ApiProblem,OpenApiContract};
use App\Security\{AccessIdentity,AccessPolicy,AccessTarget,Permission,StockUser};
use App\Stock\ReturnBatchEngine;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** One deposit, allocated to known consumptions first; any remainder is traced separately. */
final class UnifiedReturns
{
    public function __construct(private readonly Connection $db,private readonly AccessIdentity $identity,private readonly AccessPolicy $policy,
        private readonly OpenApiContract $contract,private readonly ReturnBatchEngine $batches,private readonly StockExtensions $extensions,
        #[Autowire('%kernel.environment%')] private readonly string $environment,#[Autowire('%kernel.secret%')] private readonly string $secret) {}
    public function record(StockUser $actor,\stdClass $body,string $key): array
    {
        $this->identity->requireRole($actor,Permission::RETURN_CREATE);
        if(!preg_match('/^[A-Za-z0-9._:-]{8,160}$/D',$key)||!$this->contract->valid($body,['$ref'=>'#/components/schemas/ReturnBatchCreate'])||!is_int($body->quantity??null)) {throw new ApiProblem(422,'invalid_return','Vérifiez l’article, le magasin et la quantité entière.');}
        if(isset($body->comment)&&(str_contains($body->comment,"\0")||!preg_match('//u',$body->comment))) {throw new ApiProblem(422,'invalid_text','Commentaire invalide.');}
        $body=clone $body;foreach(['article_id','store_id'] as $f) {$body->$f=strtolower($body->$f);}$body->consumer_id=strtolower($body->consumer_id??$actor->id);
        $hash=hash_hmac('sha256',json_encode($body,JSON_THROW_ON_ERROR),$this->secret);
        return $this->db->transactional(function() use($actor,$body,$key,$hash): array {
            foreach(['users','user_stores','stores','articles','inventories'] as $t) {$this->db->executeStatement('LOCK TABLE '.$t.' IN SHARE MODE');}
            $this->identity->requireRole($actor,Permission::RETURN_CREATE);$this->policy->assert($actor,Permission::RETURN_CREATE,AccessTarget::store($body->store_id));
            if($body->consumer_id!==$actor->id) {$this->policy->assert($actor,Permission::CONSUMPTION_OTHER,AccessTarget::store($body->store_id));}
            $this->db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['ca:'.$actor->id.':'.$key]);
            $old=$this->db->fetchAssociative('SELECT * FROM ca_requests WHERE actor_id=? AND request_key=?',[$actor->id,$key]);
            if($old) {if($old['kind']!=='returns.unified'||!hash_equals($old['fingerprint'],$hash)) {throw new ApiProblem(409,'replay_conflict','Ce formulaire a déjà enregistré un autre retour.');}$result=json_decode($old['result'],true,flags:JSON_THROW_ON_ERROR);ksort($result);return $result;}
            if(!$this->db->fetchOne("SELECT 1 FROM users WHERE id=? AND active AND anonymized_at IS NULL AND (NOT development_only OR ?='dev')",[$body->consumer_id,$this->environment])) {throw new ApiProblem(422,'invalid_beneficiary','Choisissez une personne active.');}
            if(!$this->db->fetchOne('SELECT 1 FROM articles WHERE id=?',[$body->article_id])) {throw new ApiProblem(404,'article_not_found','Article introuvable.');}
            $ids=$this->db->fetchFirstColumn("SELECT id FROM stock_movements WHERE type='consumption' AND article_id=? AND consumer_id=? ORDER BY created_at,id FOR UPDATE",[$body->article_id,$body->consumer_id]);
            $left=$body->quantity;
            foreach($ids as $id) {
                $available=(int)$this->db->fetchOne("SELECT m.quantity-(SELECT coalesce(sum(r.quantity),0) FROM stock_movements r WHERE r.type='return' AND r.related_movement_id=m.id AND NOT EXISTS(SELECT 1 FROM stock_movements u WHERE u.type='reversal' AND u.related_movement_id=r.id)) FROM stock_movements m WHERE m.id=? AND NOT EXISTS(SELECT 1 FROM stock_movements u WHERE u.type='reversal' AND u.related_movement_id=m.id)",[$id]);
                $left-=min(max(0,$available),$left);if($left===0) {break;}
            }
            $linked=$body->quantity-$left;$group=null;$movement=null;
            if($linked>0) {$part=clone $body;$part->quantity=$linked;$group=$this->batches->record($actor,$part,'unified:'.hash('sha256',$key))['data']['id'];}
            if($left>0) {$movement=$this->extensions->deposit($actor,$body->store_id,$body->article_id,$left,substr(hash('sha256','unified:'.$key),0,32),$body->comment??null);}
            $result=['article_id'=>$body->article_id,'store_id'=>$body->store_id,'quantity'=>$body->quantity,'linked_quantity'=>$linked,'unlinked_quantity'=>$left,'group_id'=>$group,'movement_id'=>$movement];
            ksort($result);
            $this->db->insert('ca_requests',['actor_id'=>$actor->id,'request_key'=>$key,'fingerprint'=>$hash,'kind'=>'returns.unified','result'=>json_encode($result,JSON_THROW_ON_ERROR)]);return $result;
        });
    }
}
