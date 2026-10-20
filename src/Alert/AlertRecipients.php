<?php

declare(strict_types=1);
namespace App\Alert;
use App\Api\ApiProblem;
use App\Api\OpenApiContract;
use App\Api\Pagination;
use App\Security\AccessPolicy;
use App\Security\AccessTarget;
use App\Security\Permission;
use App\Security\StockUser;
use Doctrine\DBAL\Connection;
final class AlertRecipients
{
    private const TYPES=['low','critical','daily_summary'];
    public function __construct(private readonly Connection $db,private readonly AccessPolicy $policy,private readonly OpenApiContract $contract) {}
    public function state(StockUser $actor,string $store): array
    {
        $this->policy->assert($actor,Permission::ALERT_READ,AccessTarget::store($store));$store=strtolower($store);
        $config=$this->db->fetchAssociative('SELECT use_defaults,version FROM alert_recipient_configurations WHERE store_id=?',[$store]);
        $saved=$this->db->fetchAllAssociative('SELECT user_id,alert_type FROM alert_recipients WHERE store_id=? ORDER BY user_id,alert_type',[$store]);$options=$this->options($actor,$store);
        $effective=$this->db->fetchAllAssociative("SELECT r.user_id,t.alert_type FROM (VALUES ('low'),('critical'),('daily_summary')) t(alert_type) CROSS JOIN LATERAL effective_alert_recipients(?,t.alert_type) r ORDER BY r.user_id,t.alert_type",[$store]);
        return ['use_defaults'=>$config===false||$config['use_defaults'],'recipients'=>$effective,'options'=>$options,'revision'=>hash('sha256',json_encode([$store,$config,$saved,array_column($options,'user_id')],JSON_THROW_ON_ERROR))];
    }
    /** Never expose an address or memberships outside the requested authorized store. */
    public function options(StockUser $actor,string $store): array
    {
        $this->policy->assert($actor,Permission::ALERT_READ,AccessTarget::store($store));
        return $this->db->fetchAllAssociative("SELECT u.id user_id,u.name,r.code role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.active AND u.anonymized_at IS NULL AND NOT u.development_only AND EXISTS(SELECT 1 FROM stores s WHERE s.id=? AND s.active) AND (r.code='ADMIN' OR (r.code='MANAGER' AND EXISTS(SELECT 1 FROM user_stores us WHERE us.user_id=u.id AND us.store_id=?))) ORDER BY u.id",[$store,$store]);
    }
    public function page(StockUser $actor,string $store,array $filters=[],bool $options=false): array
    {
        $rows=$options?$this->options($actor,$store):$this->state($actor,$store)['recipients'];return $this->paginate($rows,$filters);
    }
    public function paginate(array $rows,array $filters=[]): array
    {
        $order=$filters['order']??'asc';if(($filters['sort']??'user_id')!=='user_id'||!in_array($order,['asc','desc'],true)) {throw new ApiProblem(422,'invalid_query','Tri invalide.');}
        if($order==='desc') {$rows=array_reverse($rows);}$p=new Pagination($filters['page']??1,$filters['limit']??25);return $p->response(array_values(array_slice($rows,$p->offset(),$p->limit)),count($rows));
    }
    public function assertWrite(StockUser $actor,string $store): void {$this->policy->assert($actor,Permission::ALERT_RECIPIENTS,AccessTarget::store($store));}
    public function replace(StockUser $actor,string $store,\stdClass $body,?string $revision=null): array
    {
        $this->assertWrite($actor,$store);if(!$this->contract->valid($body,['$ref'=>'#/components/schemas/RecipientsWrite'])) {throw new ApiProblem(422,'validation_failed','Vérifiez les destinataires et les types d’alerte.');}
        $defaults=$body->use_defaults??false;if($defaults&&$body->recipients!==[]) {throw new ApiProblem(422,'invalid_recipients','La restauration des valeurs par défaut nécessite une liste vide.');}
        return $this->db->transactional(function() use($actor,$store,$body,$defaults,$revision): array {
            foreach(['users','user_stores','stores'] as $table) {$this->db->executeStatement('LOCK TABLE '.$table.' IN SHARE MODE');}$this->assertWrite($actor,$store);$store=strtolower($store);
            $this->db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['alert-recipients:'.$store]);$state=$this->state($actor,$store);
            if($revision!==null&&(!preg_match('/^[a-f0-9]{64}$/D',$revision)||!hash_equals($state['revision'],$revision))) {throw new ApiProblem(409,'stale_recipients','La liste ou les droits ont changé. Rechargez les destinataires avant de réessayer.');}
            $allowed=array_column($state['options'],'user_id');$selected=[];
            foreach($body->recipients as $item) {$id=strtolower($item->user_id);$key=$id.':'.$item->alert_type;if(!in_array($id,$allowed,true)||isset($selected[$key])||!in_array($item->alert_type,self::TYPES,true)) {throw new ApiProblem(422,'invalid_recipients','Sélectionnez uniquement des personnes actives autorisées pour ce magasin, sans doublon.');}$selected[$key]=['user_id'=>$id,'alert_type'=>$item->alert_type];}
            ksort($selected);$selected=array_values($selected);$saved=$this->db->fetchAllAssociative('SELECT user_id,alert_type FROM alert_recipients WHERE store_id=? ORDER BY user_id,alert_type',[$store]);
            $response=['recipients'=>$selected,'use_defaults'=>$defaults];if($state['use_defaults']===$defaults&&$saved===$selected) {return $response;}
            $this->db->executeStatement('INSERT INTO alert_recipient_configurations(store_id,use_defaults) VALUES (?,?) ON CONFLICT(store_id) DO UPDATE SET use_defaults=EXCLUDED.use_defaults,version=alert_recipient_configurations.version+1',[$store,$defaults?'true':'false']);
            $this->db->executeStatement('DELETE FROM alert_recipients WHERE store_id=?',[$store]);foreach($selected as $item) {$this->db->insert('alert_recipients',['store_id'=>$store,...$item]);}
            $this->db->executeStatement("INSERT INTO audit_logs(actor_id,store_id,action,target_type,target_id,context) VALUES (?,?,'alert.recipients_updated','store',?,?::jsonb)",[$actor->id,$store,$store,json_encode(['before'=>['use_defaults'=>$state['use_defaults'],'recipients'=>$saved],'after'=>$response],JSON_THROW_ON_ERROR)]);
            return $response;
        });
    }
}
