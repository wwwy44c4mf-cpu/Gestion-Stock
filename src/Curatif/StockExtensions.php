<?php

declare(strict_types=1);
namespace App\Curatif;
use App\Api\ApiProblem;
use App\Security\{AccessPolicy,AccessTarget,Permission,StockUser};
use Doctrine\DBAL\Connection;
final class StockExtensions
{
    public function __construct(private readonly Connection $db,private readonly AccessPolicy $policy) {}
    public function order(StockUser $actor,string $store,string $article,bool $ordered): void
    {
        $this->db->transactional(function() use($actor,$store,$article,$ordered): void {
            foreach(['users','user_stores','stores','articles'] as $table) {$this->db->executeStatement('LOCK TABLE '.$table.' IN SHARE MODE');}
            $this->policy->assert($actor,Permission::THRESHOLD_WRITE,AccessTarget::store($store));$this->article($article);
            if($ordered) {$this->db->executeStatement('INSERT INTO stock_orders(article_id,store_id,ordered_by) VALUES(?,?,?) ON CONFLICT(article_id,store_id) DO NOTHING',[$article,$store,$actor->id]);}
            else {$this->db->executeStatement('DELETE FROM stock_orders WHERE article_id=? AND store_id=?',[$article,$store]);}
            $this->db->executeStatement("INSERT INTO audit_logs(actor_id,store_id,action,target_type,target_id) VALUES (?,?,?,'article',?)",[$actor->id,$store,$ordered?'stock.ordered':'stock.order_cleared',$article]);
        });
    }
    /** A deposit without a recorded consumption is a separately identified positive ledger entry. */
    public function deposit(StockUser $actor,string $store,string $article,int $quantity,string $key,?string $comment=null): string
    {
        if($quantity<1||$quantity>9007199254740991||!preg_match('/^[a-f0-9]{32}$/D',$key)) {throw new ApiProblem(422,'invalid_deposit','Vérifiez la quantité et rechargez le formulaire.');}
        $fingerprint=hash('sha256',json_encode([$actor->id,$store,$article,$quantity,$comment]));
        return $this->db->transactional(function() use($actor,$store,$article,$quantity,$key,$fingerprint,$comment): string {
            foreach(['users','user_stores','stores','articles','inventories'] as $table) {$this->db->executeStatement('LOCK TABLE '.$table.' IN SHARE MODE');}
            $this->policy->assert($actor,Permission::RETURN_CREATE,AccessTarget::store($store));$this->article($article);
            $this->db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['unlinked-return:'.$actor->id.':'.$key]);
            if($this->db->fetchOne("SELECT 1 FROM articles a JOIN categories c ON c.id=a.category_id WHERE a.id=? AND coalesce((to_jsonb(c)->>'tracks_reels')::boolean,false)",[$article])) {throw new ApiProblem(409,'reel_workflow_required','Réceptionnez ce câble avec sa longueur dans Tourets de câble.');}
            $previous=$this->db->fetchAssociative('SELECT movement_id,fingerprint FROM unlinked_returns WHERE actor_id=? AND request_key=?',[$actor->id,$key]);
            if($previous) {if(!hash_equals($previous['fingerprint'],$fingerprint)) {throw new ApiProblem(409,'deposit_replay_conflict','Ce formulaire a déjà enregistré un autre dépôt.');}return $previous['movement_id'];}
            if($this->db->fetchOne("SELECT 1 FROM inventories WHERE store_id=? AND status IN ('in_progress','pending_validation')",[$store])) {throw new ApiProblem(409,'inventory_in_progress','Un inventaire bloque ce magasin.');}
            $this->db->executeStatement('INSERT INTO stock_levels(article_id,store_id) VALUES (?,?) ON CONFLICT DO NOTHING',[$article,$store]);
            $balance=$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE article_id=? AND store_id=? FOR UPDATE',[$article,$store]);
            if((int)$balance>9007199254740991-$quantity) {throw new ApiProblem(422,'stock_limit','La limite de stock serait dépassée.');}
            $this->db->executeStatement('UPDATE stock_levels SET quantity=quantity+?,version=version+1 WHERE article_id=? AND store_id=?',[$quantity,$article,$store]);
            $id=$this->db->fetchOne("INSERT INTO stock_movements(type,article_id,destination_store_id,quantity,author_id,reason,idempotency_key,comment) VALUES('entry',?,?,?,?,'Réintégration sans sortie préalable',?,?) RETURNING id",[$article,$store,$quantity,$actor->id,'unlinked:'.$key,$comment]);
            $this->db->insert('unlinked_returns',['movement_id'=>$id,'actor_id'=>$actor->id,'request_key'=>$key,'fingerprint'=>$fingerprint]);
            $this->db->executeStatement('INSERT INTO stock_change_events(movement_id,article_id,store_ids) VALUES(?,?,?::uuid[])',[$id,$article,'{'.$store.'}']);
            $this->db->executeStatement("INSERT INTO audit_logs(actor_id,store_id,action,target_type,target_id) VALUES (?,?,'stock.unlinked_return','stock_movement',?)",[$actor->id,$store,$id]);return $id;
        });
    }
    private function article(string $id): void {if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$id)||!$this->db->fetchOne('SELECT 1 FROM articles WHERE id=?',[$id])) {throw new ApiProblem(404,'article_not_found','Article introuvable.');}}
}
