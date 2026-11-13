<?php

declare(strict_types=1);
namespace App\Curatif;
use App\Api\ApiProblem;
use App\Security\{AccessPolicy,AccessTarget,Permission,StockUser};
use App\Stock\StockMovementEngine;
use Doctrine\DBAL\Connection;
final class CableReels
{
    public function __construct(private readonly Connection $db,private readonly AccessPolicy $policy,private readonly StockMovementEngine $stock) {}
    public function listing(StockUser $actor,string $store): array
    {
        $this->policy->assert($actor,Permission::CONSUMPTION_CREATE,AccessTarget::store($store));
        return $this->db->fetchAllAssociative('SELECT r.*,a.name article_name,a.sku FROM cable_reels r JOIN articles a ON a.id=r.article_id WHERE r.store_id=? ORDER BY a.name,r.reference',[$store]);
    }
    public function receive(StockUser $actor,string $store,string $article,string $reference,int $length,string $key): string
    {
        if(trim($reference)===''||mb_strlen($reference)>80||$length<1||$length>9007199254740991) {throw new ApiProblem(422,'invalid_reel','Saisissez une référence individuelle et une longueur entière positive.');}
        return $this->db->transactional(function() use($actor,$store,$article,$reference,$length,$key): string {
            $this->lock();$this->policy->assert($actor,Permission::ENTRY_CREATE,AccessTarget::store($store));
            $hash=hash('sha256',json_encode([$store,$article,trim($reference),$length]));$old=$this->replay($actor,$key,$hash,'reels.receive');if($old!==null) {return $old;}
            if(!$this->db->fetchOne('SELECT 1 FROM articles a JOIN categories c ON c.id=a.category_id WHERE a.id=? AND a.active AND a.deleted_at IS NULL AND c.tracks_reels',[$article])) {throw new ApiProblem(422,'reel_category','Choisissez un article de la catégorie Touret avec suivi de longueur.');}
            if($this->db->fetchOne('SELECT 1 FROM cable_reels WHERE reference=?',[trim($reference)])) {throw new ApiProblem(409,'reel_reference_used','Cette référence de touret existe déjà.');}
            $this->stock->record($actor,'entry',(object)['store_id'=>$store,'article_id'=>$article,'quantity'=>1,'reason'=>'Réception du touret '.trim($reference)],'reel:'.$key,true);
            $id=$this->db->fetchOne('INSERT INTO cable_reels(article_id,store_id,reference,initial_length,remaining_length) VALUES(?,?,?,?,?) RETURNING id',[$article,$store,trim($reference),$length,$length]);
            $this->movement($actor,$id,'receipt',$length);$this->remember($actor,$key,$hash,'reels.receive',$id);return $id;
        });
    }
    public function useLength(StockUser $actor,string $store,string $id,int $length,string $key): void
    {
        if($length<1||$length>9007199254740991||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$id)) {throw new ApiProblem(422,'invalid_length','Saisissez des mètres entiers positifs.');}
        $this->db->transactional(function() use($actor,$store,$id,$length,$key): void {
            $this->lock();$this->policy->assert($actor,Permission::CONSUMPTION_CREATE,AccessTarget::store($store));
            $hash=hash('sha256',json_encode([$store,$id,$length]));if($this->replay($actor,$key,$hash,'reels.use')!==null) {return;}
            $reel=$this->db->fetchAssociative('SELECT * FROM cable_reels WHERE id=? AND store_id=? FOR UPDATE',[$id,$store]);if(!$reel) {throw new ApiProblem(404,'reel_not_found','Touret introuvable.');}
            if($this->db->fetchOne("SELECT 1 FROM inventories WHERE store_id=? AND status IN ('in_progress','pending_validation')",[$store])) {throw new ApiProblem(409,'inventory_in_progress','Un inventaire bloque ce magasin.');}
            if(!$this->db->fetchOne('SELECT 1 FROM articles WHERE id=? AND active AND deleted_at IS NULL',[$reel['article_id']])) {throw new ApiProblem(409,'article_inactive','Cet article est désactivé.');}
            if($length>(int)$reel['remaining_length']) {throw new ApiProblem(409,'insufficient_cable','La longueur demandée dépasse le câble restant.');}
            $remaining=(int)$reel['remaining_length']-$length;
            if($remaining===0) {$this->stock->record($actor,'consumption',(object)['store_id'=>$store,'article_id'=>$reel['article_id'],'quantity'=>1,'reference'=>'Touret épuisé '.$reel['reference']],'reel:'.$key,true);}
            $this->db->executeStatement('UPDATE cable_reels SET remaining_length=?,version=version+1 WHERE id=?',[$remaining,$id]);$this->movement($actor,$id,'use',$length);$this->remember($actor,$key,$hash,'reels.use',$id);
        });
    }
    private function movement(StockUser $actor,string $id,string $type,int $length): void
    {
        $this->db->insert('cable_reel_movements',['reel_id'=>$id,'actor_id'=>$actor->id,'type'=>$type,'length'=>$length]);
        $this->db->executeStatement("INSERT INTO audit_logs(actor_id,store_id,action,target_type,target_id,context) SELECT ?,store_id,?,'reel',id,?::jsonb FROM cable_reels WHERE id=?",[$actor->id,'reels.'.$type,json_encode(['metres'=>$length]),$id]);
    }
    private function replay(StockUser $actor,string $key,string $hash,string $kind): ?string
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$key)) {throw new ApiProblem(422,'invalid_form','Rechargez le formulaire.');}
        $this->db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['ca:'.$actor->id.':'.$key]);
        $row=$this->db->fetchAssociative('SELECT * FROM ca_requests WHERE actor_id=? AND request_key=?',[$actor->id,$key]);if(!$row) {return null;}
        if($row['kind']!==$kind||!hash_equals($row['fingerprint'],$hash)) {throw new ApiProblem(409,'replay_conflict','Ce formulaire a déjà enregistré une autre opération.');}return json_decode($row['result'],true)['reel_id'];
    }
    private function remember(StockUser $actor,string $key,string $hash,string $kind,string $id): void {$this->db->insert('ca_requests',['actor_id'=>$actor->id,'request_key'=>$key,'fingerprint'=>$hash,'kind'=>$kind,'result'=>json_encode(['reel_id'=>$id])]);}
    private function lock(): void {foreach(['users','user_stores','stores','articles','inventories'] as $table) {$this->db->executeStatement('LOCK TABLE '.$table.' IN SHARE MODE');}}
}
