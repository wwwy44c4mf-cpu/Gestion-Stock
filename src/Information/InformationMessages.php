<?php

declare(strict_types=1);
namespace App\Information;
use App\Api\ApiProblem;
use App\Api\IdempotencyKey;
use App\Api\OpenApiContract;
use App\Api\Pagination;
use App\Notification\NotificationOutbox;
use App\Notification\NotificationPayload;
use App\Security\AccessIdentity;
use App\Security\Permission;
use App\Security\StockUser;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
final class InformationMessages
{
    public function __construct(private readonly Connection $db,private readonly AccessIdentity $identity,private readonly OpenApiContract $contract,private readonly IdempotencyKey $keys,private readonly NotificationOutbox $outbox,private readonly NotificationPayload $payloads,
        #[Autowire('%kernel.secret%')] private readonly string $secret) {}
    public function authorize(StockUser $actor): void {$this->identity->requireRole($actor,Permission::INFORMATION_SEND);}
    public function canonical(\stdClass $body): \stdClass
    {
        if(!$this->contract->valid($body,['$ref'=>'#/components/schemas/InformationCreate'])) {throw new ApiProblem(422,'validation_failed','Saisissez un objet, un message et au moins un magasin ou rôle.');}
        $stores=array_map(strtolower(...),$body->store_ids??[]);$roles=$body->roles??[];if(count(array_unique($stores))!==count($stores)) {throw new ApiProblem(422,'invalid_targets','Un magasin est présent plusieurs fois.');}sort($stores);sort($roles);
        $canonical=(object)['subject'=>trim($body->subject),'message'=>trim($body->message),'store_ids'=>$stores,'roles'=>$roles];
        try {$this->payloads->validate('information',['subject'=>$canonical->subject,'body'=>$canonical->message,'path'=>'/']);}catch(\InvalidArgumentException) {throw new ApiProblem(422,'invalid_message','Vérifiez l’objet et le message ; les caractères de contrôle ne sont pas acceptés.');}
        return $canonical;
    }
    /** Any selected store, any selected role; the two criteria intersect when both are supplied. */
    private function audience(\stdClass $body): array
    {
        foreach($body->store_ids as $store) {if(!$this->db->fetchOne('SELECT 1 FROM stores WHERE id=? AND active',[$store])) {throw new ApiProblem(422,'invalid_targets','Choisissez uniquement des magasins actifs existants.');}}
        $where=['u.active','u.anonymized_at IS NULL','NOT u.development_only','u.email IS NOT NULL'];$params=[];
        if($body->store_ids!==[]) {$where[]='EXISTS(SELECT 1 FROM user_stores us JOIN stores s ON s.id=us.store_id WHERE us.user_id=u.id AND s.active AND s.id=ANY(?::uuid[]))';$params[]='{'.implode(',',$body->store_ids).'}';}
        if($body->roles!==[]) {$where[]='r.code=ANY(?::text[])';$params[]='{'.implode(',',$body->roles).'}';}
        $rows=$this->db->fetchAllAssociative('SELECT u.id,u.name,u.email,r.code role FROM users u JOIN roles r ON r.id=u.role_id WHERE '.implode(' AND ',$where).' ORDER BY u.id LIMIT 1001',$params);
        if(count($rows)>1000) {throw new ApiProblem(422,'audience_too_large','Précisez la cible : la limite est de 1 000 destinataires.');}return $rows;
    }
    private function lock(StockUser $actor): void {foreach(['users','user_stores','stores'] as $table) {$this->db->executeStatement('LOCK TABLE '.$table.' IN SHARE MODE');}$this->authorize($actor);}
    private function key(string $key): void {if(!preg_match('/^[A-Za-z0-9._:-]{8,160}$/D',$key)) {throw new ApiProblem(422,'invalid_idempotency_key','Clé d’opération invalide.');}}
    private function fingerprint(StockUser $actor,\stdClass $body): string {return hash_hmac('sha256',$this->keys->fingerprint($actor->id,'POST','/notifications/information',$body),$this->secret);}
    private function token(StockUser $actor,\stdClass $body,string $key,int $expires,array $rows): string
    {
        return hash_hmac('sha256',json_encode([$actor->id,$actor->authVersion,$this->fingerprint($actor,$body),$key,$expires,$rows],JSON_THROW_ON_ERROR),$this->secret);
    }
    public function preview(StockUser $actor,\stdClass $body,string $key): array
    {
        $this->authorize($actor);$body=$this->canonical($body);$this->key($key);
        return $this->db->transactional(function() use($actor,$body,$key): array {$this->lock($actor);$rows=$this->audience($body);$expires=time()+1800;return ['body'=>$body,'recipients'=>$rows,'expires'=>$expires,'token'=>$this->token($actor,$body,$key,$expires,$rows)];});
    }
    /** API POST is an explicit send instruction; HTML must supply the signed preview. */
    public function queue(StockUser $actor,\stdClass $body,string $key,bool $requirePreview=false,?int $expires=null,?string $token=null): array
    {
        $this->authorize($actor);$body=$this->canonical($body);$this->key($key);$fingerprint=$this->fingerprint($actor,$body);
        return $this->db->transactional(function() use($actor,$body,$key,$fingerprint,$requirePreview,$expires,$token): array {
            $this->lock($actor);$this->db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['information-request:'.$actor->id.':'.$key]);
            $previous=$this->db->fetchAssociative('SELECT id,request_fingerprint FROM information_campaigns WHERE author_id=? AND request_key=?',[$actor->id,$key]);
            if($previous) {if(!hash_equals($previous['request_fingerprint'],$fingerprint)) {throw new ApiProblem(409,'idempotency_conflict','Cette clé a déjà été utilisée pour un autre message ou une autre cible.');}return $this->result($previous['id']);}
            $rows=$this->audience($body);
            if($requirePreview&&($expires===null||$expires<time()||$expires>time()+1800||$token===null||!hash_equals($this->token($actor,$body,$key,$expires,$rows),$token))) {throw new ApiProblem(409,'stale_preview','Le message, les destinataires ou la prévisualisation ont changé. Prévisualisez à nouveau avant de confirmer.');}
            if($rows===[]) {throw new ApiProblem(422,'empty_audience','Aucun compte actif ne correspond à cette cible.');}
            $id=$this->db->fetchOne('INSERT INTO information_campaigns(author_id,request_key,request_fingerprint,subject,message,store_ids,roles,recipient_count) VALUES (?,?,?,?,?,?::uuid[],?::text[],?) RETURNING id',[$actor->id,$key,$fingerprint,$body->subject,$body->message,'{'.implode(',',$body->store_ids).'}','{'.implode(',',$body->roles).'}',count($rows)]);
            foreach($rows as $person) {$event=$this->outbox->enqueue('information:'.$id.':'.$person['id'],'information',$person['id'],['subject'=>$body->subject,'body'=>$body->message,'path'=>'/']);$this->db->executeStatement('UPDATE notification_events SET information_id=? WHERE id=?',[$id,$event]);$this->db->insert('information_campaign_recipients',['campaign_id'=>$id,'user_id'=>$person['id'],'email_fingerprint'=>hash('sha256',strtolower(trim($person['email']))),'notification_id'=>$event]);}
            $this->db->executeStatement("INSERT INTO audit_logs(actor_id,action,target_type,target_id,context) VALUES (?,'information.queued','information_campaign',?,?::jsonb)",[$actor->id,$id,json_encode(['store_ids'=>$body->store_ids,'roles'=>$body->roles,'recipient_count'=>count($rows),'message_fingerprint'=>$fingerprint],JSON_THROW_ON_ERROR)]);
            return $this->result($id);
        });
    }
    private function result(string $id): array
    {
        return ['id'=>$id,'data'=>['event_ids'=>$this->db->fetchFirstColumn('SELECT notification_id FROM information_campaign_recipients WHERE campaign_id=? ORDER BY user_id',[$id]),'recipient_count'=>(int)$this->db->fetchOne('SELECT recipient_count FROM information_campaigns WHERE id=?',[$id])]];
    }
    public function receipt(StockUser $actor,string $id): array
    {
        $this->authorize($actor);if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di',$id)) {throw new ApiProblem(404,'not_found','Message introuvable.');}$row=$this->db->fetchAssociative('SELECT *,array_to_json(store_ids)::text stores_json,array_to_json(roles)::text roles_json FROM information_campaigns WHERE id=?',[$id]);if(!$row) {throw new ApiProblem(404,'not_found','Message introuvable.');}
        $row['stores']=$this->storeLabels(json_decode($row['stores_json'],true));$row['role_list']=json_decode($row['roles_json'],true);
        $row['recipients']=$this->db->fetchAllAssociative("SELECT coalesce(u.name,'Compte anonymisé') name,e.status,e.attempts,e.last_error_code,e.sent_at FROM information_campaign_recipients ir JOIN users u ON u.id=ir.user_id JOIN notification_events e ON e.id=ir.notification_id WHERE ir.campaign_id=? ORDER BY u.name NULLS LAST,ir.user_id",[$id]);return $row;
    }
    public function storeLabels(array $ids): array {$rows=[];foreach($ids as $id) {$rows[]=$this->db->fetchAssociative('SELECT id,name,active FROM stores WHERE id=?',[$id]);}return $rows;}
    public function listing(StockUser $actor,int $page=1,int $limit=25): array
    {
        $this->authorize($actor);$p=new Pagination($page,$limit);$row=$this->db->fetchAssociative("WITH page AS (SELECT id,subject,recipient_count,created_at FROM information_campaigns ORDER BY created_at DESC,id DESC LIMIT {$p->limit} OFFSET {$p->offset()}) SELECT (SELECT count(*) FROM information_campaigns) total,coalesce((SELECT json_agg(page ORDER BY created_at DESC,id DESC) FROM page),'[]'::json) rows");return $p->response(json_decode($row['rows'],true,flags:JSON_THROW_ON_ERROR),(int)$row['total']);
    }
}
