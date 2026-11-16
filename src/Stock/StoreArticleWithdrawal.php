<?php
declare(strict_types=1);
namespace App\Stock;
use App\Api\ApiProblem;
use App\Security\{AccessPolicy,AccessTarget,Permission,StockUser};
use Doctrine\DBAL\Connection;

final class StoreArticleWithdrawal
{
    public function __construct(private readonly Connection $db,private readonly AccessPolicy $policy) {}
    public function preview(StockUser $actor,string $store,string $article): array
    {
        foreach([$store,$article] as $id) {if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di',$id)) {throw new ApiProblem(404,'not_found','Référence introuvable.');}}
        $this->policy->assert($actor,Permission::LOCATION_WRITE,AccessTarget::store($store));
        $row=$this->db->fetchAssociative('SELECT a.id article_id,a.sku,a.name,s.id store_id,s.name store_name,coalesce(l.quantity,0) quantity FROM articles a CROSS JOIN stores s LEFT JOIN stock_levels l ON l.article_id=a.id AND l.store_id=s.id WHERE a.id=? AND s.id=? AND a.deleted_at IS NULL',[$article,$store]);
        if(!$row) {throw new ApiProblem(404,'not_found','Référence introuvable.');}
        return $row;
    }
    public function withdraw(StockUser $actor,string $store,string $article): void
    {
        $this->db->transactional(function() use($actor,$store,$article): void {
            // Same guard order as movements; row lock serializes a receipt with withdrawal.
            foreach(['users','user_stores','stores','articles','inventories'] as $table) {$this->db->executeStatement('LOCK TABLE '.$table.' IN SHARE MODE');}
            $this->preview($actor,$store,$article);
            if($this->db->fetchOne("SELECT 1 FROM inventories WHERE store_id=? AND status IN ('in_progress','pending_validation')",[$store])) {throw new ApiProblem(409,'inventory_in_progress','Terminez ou annulez l’inventaire avant de retirer cet article.');}
            $this->db->executeStatement('INSERT INTO stock_levels(article_id,store_id) VALUES(?,?) ON CONFLICT DO NOTHING',[$article,$store]);
            $row=$this->db->fetchAssociative('SELECT quantity,withdrawn FROM stock_levels WHERE article_id=? AND store_id=? FOR UPDATE',[$article,$store]);
            if((int)$row['quantity']!==0) {throw new ApiProblem(409,'stock_not_empty','Le stock doit être à zéro. Transférez ou sortez le matériel restant avant de retirer cet article du magasin.');}
            if($row['withdrawn']) {return;}
            $this->db->executeStatement('UPDATE stock_levels SET withdrawn=true WHERE article_id=? AND store_id=?',[$article,$store]);
            $this->db->executeStatement('DELETE FROM stock_orders WHERE article_id=? AND store_id=?',[$article,$store]);
            $this->db->executeStatement("INSERT INTO audit_logs(actor_id,store_id,action,target_type,target_id) VALUES(?,?,'stock.article_withdrawn','article',?)",[$actor->id,$store,$article]);
        });
    }
}
