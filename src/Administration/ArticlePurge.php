<?php

declare(strict_types=1);
namespace App\Administration;

use App\Api\ApiProblem;
use App\Security\{AccessIdentity,Permission,StockUser};
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Definitive article deletion with live authorization across its complete store history. */
final class ArticlePurge
{
    // Fixed identifiers only. Access-exclusive locks hide the short trigger suspension
    // from every other connection; PostgreSQL rolls it back on any error or crash.
    private const GUARDS=[
        'stock_movements'=>['movements_immutable'],
        'movement_corrections'=>['movement_corrections_immutable'],
        'stock_return_groups'=>['return_groups_immutable'],
        'stock_alert_transitions'=>['alert_transitions_immutable'],
        'stock_alerts'=>['alerts_history_guard'],
        'inventory_lines'=>['inventory_lines_guard'],
        'inventories'=>['inventories_transition_guard'],
        'inventory_requests'=>['inventory_requests_immutable'],
        'daily_summary_items'=>['daily_summary_items_immutable'],
        'daily_notification_runs'=>['daily_notification_runs_immutable'],
        'daily_summary_batches'=>['daily_summary_batches_immutable'],
        'audit_logs'=>['audit_immutable'],
        'cable_reel_movements'=>['cable_movements_immutable'],
    ];
    public function __construct(private readonly Connection $db,private readonly AccessIdentity $identity,private readonly \Psr\Log\LoggerInterface $logger,
        #[Autowire('%kernel.secret%')] private readonly string $secret) {}

    public function listing(StockUser $actor,string $query=''): array
    {
        $this->identity->requireRole($actor,Permission::ARTICLE_PURGE);
        if(mb_strlen($query)>160||str_contains($query,"\0")) {throw new ApiProblem(422,'invalid_query','Recherche invalide.');}
        $scope=$actor->role==='ADMIN'?'TRUE':$this->managerScope('a.id');
        return $this->db->fetchAllAssociative('SELECT a.id,a.sku,a.name,a.active,a.deleted_at FROM articles a WHERE (strpos(lower(a.sku),lower(?))>0 OR strpos(lower(a.name),lower(?))>0) AND ('.$scope.') ORDER BY a.sku,a.id LIMIT 51',$actor->role==='ADMIN'?[$query,$query]:[$query,$query,$actor->id]);
    }
    public function preview(StockUser $actor,string $id): array
    {
        $this->identity->requireRole($actor,Permission::ARTICLE_PURGE);
        $this->assertScope($actor,$id);
        $state=$this->state($id);$at=time();
        return $this->summary($state)+['at'=>$at,'token'=>$this->sign($actor,$state,$at)];
    }
    public function purge(StockUser $actor,string $id,string $token,int $at,string $sku,bool $confirmed): array
    {
        $this->identity->requireRole($actor,Permission::ARTICLE_PURGE);
        if(!$confirmed) {throw new ApiProblem(422,'purge_confirmation_required','Confirmez l’effacement définitif de la fiche, des stocks et de tout l’historique.');}
        if($at>time()+30||$at<time()-900) {throw new ApiProblem(409,'cleanup_preview_expired','Le récapitulatif a expiré. Rechargez-le.');}
        try {
            return $this->db->transactional(function() use($actor,$id,$token,$at,$sku): array {
                $this->db->executeStatement("SET LOCAL lock_timeout='3s'");
                $this->db->executeStatement("SET LOCAL statement_timeout='20s'");
                $this->db->executeStatement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
                $this->identity->requireRole($actor,Permission::ARTICLE_PURGE);
                // Same global order as stock/catalogue operations, before dependent tables.
                foreach(['user_stores','stores','categories','articles','inventories'] as $table) {$this->db->executeStatement('LOCK TABLE '.$table.' IN SHARE ROW EXCLUSIVE MODE');}
                foreach(array_unique([...array_keys(self::GUARDS),'notification_events','notification_delivery_attempts','messenger_messages','stock_levels','stock_change_events','article_locations','article_scan_codes','catalog_creation_requests','article_proposals','proposal_requests','administration_reads','stock_orders','unlinked_returns','cable_reels','ca_requests']) as $table) {$this->db->executeStatement('LOCK TABLE '.$table.' IN ACCESS EXCLUSIVE MODE');}
                $this->assertScope($actor,$id);
                $state=$this->state($id);$summary=$this->summary($state);
                if(!hash_equals($this->sign($actor,$state,$at),$token)) {throw new ApiProblem(409,'cleanup_changed','Les données ont changé. Vérifiez le nouveau récapitulatif avant de confirmer.');}
                if(!hash_equals($state['article']['sku'],$sku)) {throw new ApiProblem(422,'cleanup_sku_required','Recopiez exactement la référence de l’article.');}
                if($summary['blocked']!==[]) {throw new ApiProblem(409,'cleanup_blocked',implode(' ',$summary['blocked']));}
                $this->guards(false);
                $movements=array_column($state['movements'],'id');$alerts=array_column($state['alerts'],'id');
                // Summary emails can contain real articles too: retain all other items.
                $removedEvents=array_column($state['notifications'],'id');
                foreach($state['summaries'] as $event) {
                    $payload=json_decode($event['payload'],true,flags:JSON_THROW_ON_ERROR);
                    $items=array_values(array_filter($payload['items']??[],fn($item)=>!str_ends_with($item['path']??'','/'.$id)));
                    $this->db->executeStatement('DELETE FROM daily_summary_items WHERE run_id IN (SELECT id FROM daily_notification_runs WHERE event_id=?) AND alert_id IN (SELECT id FROM stock_alerts WHERE article_id=?)',[$event['id'],$id]);
                    if($items===[]) {$removedEvents[]=$event['id'];}
                    else {$this->db->executeStatement('UPDATE notification_events SET payload=?::jsonb WHERE id=?',[json_encode(['items'=>$items],JSON_THROW_ON_ERROR),$event['id']]);}
                }
                foreach(array_unique($removedEvents) as $event) {
                    $this->db->executeStatement('DELETE FROM messenger_messages WHERE position(? IN body)>0',[$event]);
                    $this->db->executeStatement('DELETE FROM notification_delivery_attempts WHERE event_id=?',[$event]);
                    $this->db->executeStatement('DELETE FROM daily_summary_items WHERE run_id IN (SELECT id FROM daily_notification_runs WHERE event_id=?)',[$event]);
                    $this->db->executeStatement('DELETE FROM daily_notification_runs WHERE event_id=?',[$event]);
                    $this->db->executeStatement('DELETE FROM notification_events WHERE id=?',[$event]);
                }
                // Preserve batch scheduling tombstones: no duplicate recap after the purge.
                foreach($state['batches'] as $batch) {
                    $count=$this->db->fetchAssociative('SELECT count(DISTINCT r.id) recipients,count(i.alert_id) items FROM daily_notification_runs r LEFT JOIN daily_summary_items i ON i.run_id=r.id WHERE r.batch_date=?',[$batch['local_date']]);
                    $this->db->executeStatement('UPDATE daily_summary_batches SET recipient_count=?,item_count=? WHERE local_date=?',[(int)$count['recipients'],(int)$count['items'],$batch['local_date']]);
                }
                $this->db->executeStatement('DELETE FROM stock_alert_transitions WHERE article_id=?',[$id]);
                $this->db->executeStatement('DELETE FROM daily_summary_items WHERE alert_id IN (SELECT id FROM stock_alerts WHERE article_id=?)',[$id]);
                $this->db->executeStatement('DELETE FROM stock_alerts WHERE article_id=?',[$id]);
                $this->db->executeStatement('DELETE FROM stock_change_events WHERE article_id=?',[$id]);
                $this->db->executeStatement('DELETE FROM movement_corrections WHERE original_id IN (SELECT id FROM stock_movements WHERE article_id=?)',[$id]);
                // Keep shared closed inventory requests, removing only erased adjustment IDs.
                foreach($state['inventory_requests'] as $request) {
                    $response=json_decode($request['response'],true,flags:JSON_THROW_ON_ERROR);
                    if(isset($response['data']['adjustment_ids'])) {
                        $response['data']['adjustment_ids']=array_values(array_diff($response['data']['adjustment_ids'],$movements));
                        $this->db->executeStatement('UPDATE inventory_requests SET response=?::jsonb WHERE actor_id=? AND request_key=?',[json_encode($response,JSON_THROW_ON_ERROR),$request['actor_id'],$request['request_key']]);
                    }
                }
                $this->db->executeStatement('DELETE FROM unlinked_returns WHERE movement_id IN (SELECT id FROM stock_movements WHERE article_id=?)',[$id]);
                foreach($state['reels'] as $reel) {
                    $this->db->executeStatement('DELETE FROM cable_reel_movements WHERE reel_id=?',[$reel['id']]);
                    $this->db->executeStatement("DELETE FROM ca_requests WHERE kind IN ('reels.receive','reels.use') AND result->>'reel_id'=?",[$reel['id']]);
                    $this->db->executeStatement('DELETE FROM audit_logs WHERE target_id=?',[$reel['id']]);
                }
                $this->db->executeStatement('DELETE FROM cable_reels WHERE article_id=?',[$id]);
                $this->db->executeStatement("DELETE FROM ca_requests WHERE kind='returns.unified' AND result->>'article_id'=?",[$id]);
                $this->db->executeStatement('DELETE FROM stock_orders WHERE article_id=?',[$id]);
                $this->db->executeStatement('DELETE FROM stock_movements WHERE article_id=?',[$id]);
                $this->db->executeStatement('DELETE FROM stock_return_groups WHERE article_id=?',[$id]);
                $this->db->executeStatement('DELETE FROM inventory_lines WHERE article_id=?',[$id]);
                $removedInventories=[];
                foreach($state['inventories'] as $inventory) {
                    if(!$this->db->fetchOne('SELECT 1 FROM inventory_lines WHERE inventory_id=?',[$inventory['id']])&&!$this->db->fetchOne('SELECT 1 FROM stock_movements WHERE inventory_id=?',[$inventory['id']])) {
                        $this->db->executeStatement('DELETE FROM inventory_requests WHERE inventory_id=?',[$inventory['id']]);
                        $this->db->executeStatement('DELETE FROM inventories WHERE id=?',[$inventory['id']]);$removedInventories[]=$inventory['id'];
                    }
                }
                $proposals=array_column($state['proposals'],'id');
                foreach($proposals as $proposal) {
                    $this->db->executeStatement("DELETE FROM administration_reads WHERE kind='article_proposal' AND target_id=?",[$proposal]);
                    $this->db->executeStatement('DELETE FROM proposal_requests WHERE proposal_id=?',[$proposal]);
                    $this->db->executeStatement('DELETE FROM article_proposals WHERE id=?',[$proposal]);
                }
                $this->db->executeStatement('DELETE FROM catalog_creation_requests WHERE article_id=?',[$id]);
                $this->db->executeStatement('DELETE FROM article_scan_codes WHERE article_id=?',[$id]);
                $this->db->executeStatement('DELETE FROM article_locations WHERE article_id=?',[$id]);
                $this->db->executeStatement('DELETE FROM stock_levels WHERE article_id=?',[$id]);
                foreach(array_unique([$id,...$movements,...$alerts,...$proposals,...$removedInventories,...array_column($state['transitions'],'id'),...array_column($state['return_groups'],'id')]) as $target) {$this->db->executeStatement('DELETE FROM audit_logs WHERE target_id=?',[$target]);}
                $this->db->executeStatement("DELETE FROM audit_logs WHERE context->>'article_id'=?",[$id]);
                // Shared inventory audit entries retain their other lines and adjustment IDs.
                foreach($state['inventories'] as $inventory) {
                    foreach($this->db->fetchAllAssociative("SELECT id,context FROM audit_logs WHERE target_type='inventory' AND target_id=?",[$inventory['id']]) as $audit) {
                        $context=json_decode($audit['context'],true,flags:JSON_THROW_ON_ERROR);
                        if(isset($context['lines'])) {$context['lines']=array_values(array_filter($context['lines'],fn($line)=>($line['article_id']??null)!==$id));}
                        if(isset($context['adjustment_ids'])) {$context['adjustment_ids']=array_values(array_diff($context['adjustment_ids'],$movements));}
                        $this->db->executeStatement('UPDATE audit_logs SET context=?::jsonb WHERE id=?',[json_encode((object)$context,JSON_THROW_ON_ERROR),$audit['id']]);
                    }
                }
                $this->db->executeStatement('DELETE FROM articles WHERE id=?',[$id]);
                $this->guards(true);
                $counts=['articles'=>1,'movements'=>count($movements),'balances'=>count($state['balances']),'inventory_lines'=>count($state['inventory_lines']),'alerts'=>count($alerts)];
                $this->db->executeStatement("INSERT INTO audit_logs(actor_id,action,target_type,context) VALUES (?,'article.purged','article_purge',?::jsonb)",[$actor->id,json_encode($counts,JSON_THROW_ON_ERROR)]);
                return $counts;
            });
        } catch(\Doctrine\DBAL\Exception $e) {
            $this->logger->warning('article_purge_cancelled',['exception_class'=>$e::class]);
            // This transaction restores data AND trigger state on FK errors, deadlocks or timeouts.
            throw new ApiProblem(409,'cleanup_retry','Suppression annulée : des données liées sont occupées ou ont changé. Rechargez le récapitulatif et réessayez.');
        }
    }
    private function guards(bool $enabled): void
    {
        foreach(self::GUARDS as $table=>$guards) {foreach($guards as $guard) {$this->db->executeStatement('ALTER TABLE '.$table.($enabled?' ENABLE':' DISABLE').' TRIGGER '.$guard);}}
    }
    private function state(string $id): array
    {
        if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$id)) {throw new ApiProblem(404,'article_not_found','Article introuvable.');}
        $article=$this->db->fetchAssociative('SELECT * FROM articles WHERE id=?',[$id]);if(!$article) {throw new ApiProblem(404,'article_not_found','Article introuvable.');}
        $state=['article'=>$article];
        $queries=[
            'reels'=>'SELECT * FROM cable_reels WHERE article_id=? ORDER BY id',
            'orders'=>'SELECT * FROM stock_orders WHERE article_id=? ORDER BY store_id',
            'balances'=>'SELECT * FROM stock_levels WHERE article_id=? ORDER BY store_id',
            'locations'=>'SELECT * FROM article_locations WHERE article_id=? ORDER BY store_id',
            'codes'=>'SELECT * FROM article_scan_codes WHERE article_id=? ORDER BY code',
            'movements'=>'SELECT * FROM stock_movements WHERE article_id=? ORDER BY id',
            'return_groups'=>'SELECT * FROM stock_return_groups WHERE article_id=? ORDER BY id',
            'changes'=>'SELECT * FROM stock_change_events WHERE article_id=? ORDER BY movement_id',
            'alerts'=>'SELECT * FROM stock_alerts WHERE article_id=? ORDER BY id',
            'transitions'=>'SELECT * FROM stock_alert_transitions WHERE article_id=? ORDER BY id',
            'inventory_lines'=>'SELECT * FROM inventory_lines WHERE article_id=? ORDER BY inventory_id',
            'inventories'=>'SELECT * FROM inventories WHERE id IN (SELECT inventory_id FROM inventory_lines WHERE article_id=?) ORDER BY id',
            'inventory_requests'=>'SELECT * FROM inventory_requests WHERE inventory_id IN (SELECT inventory_id FROM inventory_lines WHERE article_id=?) ORDER BY actor_id,request_key',
            'proposals'=>'SELECT * FROM article_proposals WHERE article_id=? OR published_article_id=? ORDER BY id',
            'notifications'=>'SELECT * FROM notification_events WHERE alert_id IN (SELECT id FROM stock_alerts WHERE article_id=?) ORDER BY id',
            'summaries'=>"SELECT n.* FROM notification_events n WHERE n.type='daily_summary' AND (EXISTS(SELECT 1 FROM jsonb_array_elements(n.payload->'items') i WHERE right(i->>'path',37)='/'||?) OR n.id IN (SELECT r.event_id FROM daily_notification_runs r JOIN daily_summary_items i ON i.run_id=r.id JOIN stock_alerts a ON a.id=i.alert_id WHERE a.article_id=?)) ORDER BY n.id",
            'batches'=>'SELECT * FROM daily_summary_batches WHERE local_date IN (SELECT r.batch_date FROM daily_notification_runs r JOIN daily_summary_items i ON i.run_id=r.id JOIN stock_alerts a ON a.id=i.alert_id WHERE a.article_id=?) ORDER BY local_date',
        ];
        foreach($queries as $name=>$sql) {$state[$name]=$this->db->fetchAllAssociative($sql,array_fill(0,substr_count($sql,'?'),$id));}
        $sql='SELECT s.id,s.code,s.name,s.active,coalesce(l.quantity,0) quantity FROM stores s LEFT JOIN stock_levels l ON l.store_id=s.id AND l.article_id=? WHERE s.id IN ('.$this->storeReferences('?').') ORDER BY s.code,s.id';
        $state['stores']=$this->db->fetchAllAssociative($sql,array_fill(0,substr_count($sql,'?'),$id));
        return $state;
    }
    /** All historically involved stores count, including zero balances and closed inventory lines. */
    private function storeReferences(string $article): string
    {
        return "SELECT store_id FROM stock_levels WHERE article_id=$article
 UNION SELECT store_id FROM article_locations WHERE article_id=$article
 UNION SELECT store_id FROM cable_reels WHERE article_id=$article
 UNION SELECT store_id FROM stock_orders WHERE article_id=$article
 UNION SELECT source_store_id FROM stock_movements WHERE article_id=$article
 UNION SELECT destination_store_id FROM stock_movements WHERE article_id=$article
 UNION SELECT i.store_id FROM inventory_lines l JOIN inventories i ON i.id=l.inventory_id WHERE l.article_id=$article
 UNION SELECT store_id FROM stock_return_groups WHERE article_id=$article
 UNION SELECT unnest(store_ids) FROM stock_change_events WHERE article_id=$article
 UNION SELECT store_id FROM stock_alerts WHERE article_id=$article
 UNION SELECT store_id FROM stock_alert_transitions WHERE article_id=$article
 UNION SELECT store_id FROM audit_logs WHERE target_type='article' AND target_id=$article";
    }
    private function managerScope(string $article): string
    {
        return "NOT EXISTS(SELECT 1 FROM stores involved WHERE involved.id IN (".$this->storeReferences($article).") AND
 (NOT involved.active OR involved.deleted_at IS NOT NULL OR NOT EXISTS(SELECT 1 FROM user_stores assignment WHERE assignment.store_id=involved.id AND assignment.user_id=?)))";
    }
    private function assertScope(StockUser $actor,string $id): void
    {
        if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$id)) {throw new ApiProblem(404,'article_not_found','Article introuvable.');}
        if($actor->role==='ADMIN') {return;}
        $sql='SELECT 1 FROM articles a WHERE a.id=? AND ('.$this->managerScope('a.id').')';
        if(!$this->db->fetchOne($sql,[$id,$actor->id])) {throw new ApiProblem(404,'article_not_found','Article introuvable ou hors de votre périmètre.');}
    }
    private function summary(array $state): array
    {
        $blocked=[];
        foreach($state['inventories'] as $inventory) {if(!in_array($inventory['status'],['closed','cancelled'],true)) {$blocked[]='Terminez ou annulez les inventaires ouverts concernant cet article.';break;}}
        foreach($state['proposals'] as $proposal) {if($proposal['status']==='pending') {$blocked[]='Traitez d’abord les propositions en attente concernant cet article.';break;}}
        foreach($state['stores'] as $store) {if($this->db->fetchOne("SELECT 1 FROM inventories WHERE store_id=? AND status IN ('in_progress','pending_validation')",[$store['id']])) {$blocked[]='Un inventaire en cours bloque le nettoyage de ce magasin.';break;}}
        return ['article'=>$state['article'],'stores'=>$state['stores'],'movements'=>count($state['movements']),'alerts'=>count($state['alerts']),'inventory_lines'=>count($state['inventory_lines']),'proposals'=>count($state['proposals']),'blocked'=>array_values(array_unique($blocked))];
    }
    private function sign(StockUser $actor,array $state,int $at): string {return hash_hmac('sha256',$actor->id.':'.$at.':'.json_encode($state,JSON_THROW_ON_ERROR),$this->secret);}
}
