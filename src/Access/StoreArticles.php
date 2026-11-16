<?php

declare(strict_types=1);
namespace App\Access;

/** An established store/article pair survives depletion; a catalogue row alone does not establish it. */
final class StoreArticles
{
    public function __construct(private readonly \Doctrine\DBAL\Connection $db,private readonly \App\Security\AccessPolicy $policy) {}
    public function establish(\App\Security\StockUser $actor,string $store,string $article): void
    {
        $this->db->transactional(function() use($actor,$store,$article): void {
            foreach(['users','stores','articles'] as $table) {$this->db->executeStatement('LOCK TABLE '.$table.' IN SHARE ROW EXCLUSIVE MODE');}
            $this->policy->assert($actor,\App\Security\Permission::LOCATION_WRITE,\App\Security\AccessTarget::store($store));
            if(!$this->db->fetchOne('SELECT 1 FROM articles WHERE id=? AND deleted_at IS NULL',[$article])) {throw new \App\Api\ApiProblem(404,'article_not_found','Article introuvable.');}
            if($this->db->executeStatement('INSERT INTO stock_levels(article_id,store_id) VALUES(?,?) ON CONFLICT DO NOTHING',[$article,$store])) {
                $this->db->executeStatement("INSERT INTO audit_logs(actor_id,store_id,action,target_type,target_id) VALUES(?,?,'stock.article_associated','article',?)",[$actor->id,$store,$article]);
            }
        });
    }
    public function memberships(\App\Security\StockUser $actor,array $stores): array
    {
        $ids=array_values(array_unique(array_column($stores,'id')));if($ids===[]) {return [];}
        foreach($ids as $id) {$this->policy->assert($actor,\App\Security\Permission::STOCK_READ,\App\Security\AccessTarget::store($id));}
        $rows=$this->db->fetchAllAssociative('SELECT a.id article_id,s.id store_id FROM articles a CROSS JOIN stores s WHERE s.id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND '.self::condition('a.id','s.id'),$ids);
        $result=[];foreach($rows as $row) {$result[$row['article_id']][]=$row['store_id'];}return $result;
    }
    public function assertMembership(\App\Security\StockUser $actor,string $store,string $article): void
    {
        foreach([$store,$article] as $id) {if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di',$id)) {throw new \App\Api\ApiProblem(422,'invalid_selection','Choisissez un magasin et un article valides.');}}
        $this->policy->assert($actor,\App\Security\Permission::STOCK_READ,\App\Security\AccessTarget::store($store));
        if(!$this->db->fetchOne('SELECT 1 FROM articles a WHERE a.id=? AND '.self::condition('a.id','?'),[$article,$store,$store,$store,$store])) {throw new \App\Api\ApiProblem(422,'article_outside_store','Cet article n’est pas référencé dans ce magasin. Utilisez une entrée ou un retour pour l’y ajouter.');}
    }
    public static function visible(string $article,string $store): string
    {
        return "NOT EXISTS(SELECT 1 FROM stock_levels withdrawn_level WHERE withdrawn_level.article_id=".$article." AND withdrawn_level.store_id=".$store." AND coalesce((to_jsonb(withdrawn_level)->>'withdrawn')::boolean,false))";
    }
    public static function condition(string $article,string $store): string
    {
        foreach([$article,$store] as $expression) {if($expression!=='?'&&!preg_match('/^[a-z_]+\.[a-z_]+$/D',$expression)) {throw new \InvalidArgumentException('Invalid SQL expression');}}
        return '(EXISTS(SELECT 1 FROM stock_levels established_level WHERE established_level.article_id='.$article.' AND established_level.store_id='.$store.')'
            .' OR EXISTS(SELECT 1 FROM article_locations established_location WHERE established_location.article_id='.$article.' AND established_location.store_id='.$store.')'
            .' OR EXISTS(SELECT 1 FROM stock_movements established_movement WHERE established_movement.article_id='.$article.' AND '.$store.' IN (established_movement.source_store_id,established_movement.destination_store_id))) AND '.self::visible($article,$store);
    }
}
