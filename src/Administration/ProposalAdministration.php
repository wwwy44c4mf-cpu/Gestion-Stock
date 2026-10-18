<?php

declare(strict_types=1);

namespace App\Administration;

use App\Access\CatalogDirectory;
use App\Api\ApiProblem;
use App\Api\IdempotencyKey;
use App\Api\OpenApiContract;
use App\Api\Pagination;
use App\Security\AccessIdentity;
use App\Security\AccessPolicy;
use App\Security\AccessTarget;
use App\Security\Permission;
use App\Security\StockUser;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ProposalAdministration
{
    public function __construct(private readonly Connection $db,private readonly AccessIdentity $identity,
        private readonly AccessPolicy $policy,private readonly CatalogAdministration $catalog,
        private readonly CatalogDirectory $directory,private readonly OpenApiContract $contract,
        private readonly IdempotencyKey $keys,#[Autowire('%kernel.secret%')] private readonly string $secret) {}

    public function listing(StockUser $actor,array $filters): array
    {
        $scope=$this->policy->proposalScope($actor);$where=$scope->condition;$parameters=$scope->parameters;
        if(isset($filters['status'])) { if(!in_array($filters['status'],['pending','approved','rejected'],true)) { throw new ApiProblem(422,'invalid_query','État de proposition invalide.'); }$where.=' AND p.status = ?';$parameters[]=$filters['status']; }
        $order=$filters['order']??'asc';if(($filters['sort']??'created_at')!=='created_at' || !in_array($order,['asc','desc'],true)) { throw new ApiProblem(422,'invalid_query','Tri invalide.'); }
        $page=new Pagination($filters['page']??1,$filters['limit']??25);
        $total=(int)$this->db->fetchOne('SELECT count(*) FROM article_proposals p WHERE '.$where,$parameters);
        $rows=$this->db->fetchAllAssociative('SELECT p.* FROM article_proposals p WHERE '.$where.' ORDER BY p.created_at '.strtoupper($order).',p.id LIMIT '.$page->limit.' OFFSET '.$page->offset(),$parameters);
        return $page->response(array_map($this->resource(...),$rows),$total);
    }
    public function get(StockUser $actor,string $id): array
    {
        $this->policy->assert($actor,Permission::PROPOSAL_READ,AccessTarget::proposal($id));
        return ['data'=>$this->resource($this->row($id))];
    }
    public function submit(StockUser $actor,\stdClass $body,string $key): array
    {
        $this->identity->requireRole($actor,Permission::CATALOG_PROPOSE);$this->validate($body,'ProposalCreate');
        $fingerprint=$this->fingerprint($actor,'/api/v1/article-proposals',$body,$key);
        return $this->db->transactional(function()use($actor,$body,$key,$fingerprint):array {
            $this->lock($actor,Permission::CATALOG_PROPOSE);
            if($previous=$this->replay($actor,'create',$key,$fingerprint)) { return $previous; }
            $this->catalog->checkArticleProposal($actor,$body->operation,$body->payload,$body->article_id??null,$body->base_version??null);
            $id=$this->db->fetchOne('INSERT INTO article_proposals (operation,article_id,base_version,payload,proposed_by) VALUES (?,?,?,?::jsonb,?) RETURNING id',[$body->operation,$body->article_id??null,$body->base_version??null,json_encode($body->payload,JSON_THROW_ON_ERROR),$actor->id]);
            $this->audit($actor,$id,'proposal.submitted',['operation','article_id','base_version','payload']);
            $result=['data'=>$this->resource($this->row($id))];$this->remember($actor,'create',$key,$fingerprint,$id,$result);return $result;
        });
    }
    public function review(StockUser $actor,string $id,\stdClass $body,string $key): array
    {
        $this->identity->requireRole($actor,Permission::CATALOG_ADMIN);$this->validate($body,'ProposalReview');
        $this->policy->assert($actor,Permission::PROPOSAL_READ,AccessTarget::proposal($id));$id=$this->row($id)['id'];
        $fingerprint=$this->fingerprint($actor,'/api/v1/article-proposals/'.$id.'/review',$body,$key);
        return $this->db->transactional(function()use($actor,$id,$body,$key,$fingerprint):array {
            $this->lock($actor,Permission::CATALOG_ADMIN);
            if($previous=$this->replay($actor,'review',$key,$fingerprint)) { return $previous; }
            $proposal=$this->row($id);if($proposal['status']!=='pending') { throw new ApiProblem(409,'proposal_already_reviewed','Cette proposition a déjà reçu une décision.'); }
            $published=null;
            if($body->decision==='approved') {
                $payload=json_decode($proposal['payload'],false,flags:JSON_THROW_ON_ERROR);
                try { $this->catalog->checkArticleProposal($actor,$proposal['operation'],$payload,$proposal['article_id'],$proposal['base_version']===null?null:(int)$proposal['base_version']); }
                catch(ApiProblem $error) { if($error->status===422) { throw new ApiProblem(409,'proposal_not_applicable','La proposition ne peut plus être appliquée. Vérifiez l’article et la catégorie, puis demandez une nouvelle proposition.'); }throw $error; }
                if($proposal['operation']==='create') { $result=$this->catalog->create($actor,'article',$payload,'proposal-approval-'.$id); }
                else { $payload->version=(int)$proposal['base_version'];$result=$this->catalog->update($actor,'article',$proposal['article_id'],$payload); }
                $published=$result['data']['id'];
            }
            $comment=isset($body->comment)&&trim($body->comment)!==''?trim($body->comment):null;
            $this->db->executeStatement('UPDATE article_proposals SET status = ?,reviewed_by = ?,reviewed_at = clock_timestamp(),review_comment = ?,published_article_id = ? WHERE id = ?',[$body->decision,$actor->id,$comment,$published,$id]);
            $this->audit($actor,$id,'proposal.'.$body->decision,['status','reviewed_by','reviewed_at','review_comment','published_article_id']);
            $result=['data'=>$this->resource($this->row($id))];$this->remember($actor,'review',$key,$fingerprint,$id,$result);return $result;
        });
    }
    /** Authorized UI context: minimal names and current catalogue values, never full profiles. */
    public function context(StockUser $actor,string $id): array
    {
        $proposal=$this->get($actor,$id)['data'];
        $names=$this->db->fetchAssociative('SELECT u.name AS author_name,r.name AS reviewer_name FROM article_proposals p JOIN users u ON u.id = p.proposed_by LEFT JOIN users r ON r.id = p.reviewed_by WHERE p.id = ?',[$proposal['id']]);
        $currentId=$proposal['article_id']??$proposal['published_article_id'];
        $current=$currentId===null?null:$this->directory->get($actor,'article',$currentId)['data'];$blocking=null;
        $displayPayload=$proposal['operation']==='create'?(object)array_replace(['description'=>'','category_id'=>null,'unit_code'=>'unit','active'=>true],get_object_vars($proposal['payload'])):$proposal['payload'];
        if($proposal['status']==='pending') { try { $this->catalog->checkArticleProposal($actor,$proposal['operation'],$proposal['payload'],$proposal['article_id'],$proposal['base_version']); }catch(ApiProblem $error) { $blocking=$error->getMessage(); } }
        return ['proposal'=>$proposal,'display_payload'=>$displayPayload,'current_article'=>$current,'author_name'=>$names['author_name']??'Compte anonymisé','reviewer_name'=>$names['reviewer_name']??'Compte anonymisé','blocking'=>$blocking];
    }
    private function lock(StockUser $actor,Permission $permission): void
    {
        $this->db->executeStatement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');$this->identity->requireRole($actor,$permission);
        $this->db->executeStatement('LOCK TABLE categories IN SHARE ROW EXCLUSIVE MODE');$this->db->executeStatement('LOCK TABLE articles IN SHARE ROW EXCLUSIVE MODE');$this->db->executeStatement('LOCK TABLE article_proposals IN SHARE ROW EXCLUSIVE MODE');
    }
    private function fingerprint(StockUser $actor,string $path,\stdClass $body,string $key): string
    {
        if(!preg_match('/^[A-Za-z0-9._:-]{8,160}$/D',$key)) { throw new ApiProblem(422,'invalid_idempotency_key','Clé de soumission invalide.'); }
        return hash_hmac('sha256',$this->keys->fingerprint($actor->id,'POST',$path,$body),$this->secret);
    }
    private function validate(\stdClass $body,string $schema): void
    {
        $checkText=function(mixed $value)use(&$checkText):void {
            if(is_string($value)&&(!preg_match('//u',$value)||str_contains($value,"\0"))) { throw new ApiProblem(422,'invalid_text','Les textes doivent être en UTF-8 et sans caractère nul.'); }
            if(is_object($value)||is_array($value)) { foreach($value as $child) { $checkText($child); } }
        };
        $checkText($body);
        if(!$this->contract->valid($body,['$ref'=>'#/components/schemas/'.$schema])) { throw new ApiProblem(422,'invalid_proposal','Vérifiez les champs de la proposition et de la décision.'); }
        if($schema==='ProposalReview'&&$body->decision==='rejected'&&trim($body->comment)==='') { throw new ApiProblem(422,'rejection_reason_required','Indiquez le motif du refus.'); }
    }
    private function row(string $id): array
    {
        $row=$this->db->fetchAssociative('SELECT * FROM article_proposals WHERE id = ?',[$id]);
        if(!$row) { throw new ApiProblem(404,'proposal_not_found','Proposition introuvable.'); }return $row;
    }
    private function resource(array $row): array
    {
        return ['id'=>$row['id'],'operation'=>$row['operation'],'article_id'=>$row['article_id'],'published_article_id'=>$row['published_article_id'],'base_version'=>$row['base_version']===null?null:(int)$row['base_version'],'payload'=>json_decode($row['payload'],false,flags:JSON_THROW_ON_ERROR),'status'=>$row['status'],'proposed_by'=>$row['proposed_by'],'reviewed_by'=>$row['reviewed_by'],'review_comment'=>$row['review_comment'],'created_at'=>(new \DateTimeImmutable($row['created_at']))->format(DATE_RFC3339),'reviewed_at'=>$row['reviewed_at']===null?null:(new \DateTimeImmutable($row['reviewed_at']))->format(DATE_RFC3339)];
    }
    private function replay(StockUser $actor,string $action,string $key,string $fingerprint): ?array
    {
        $row=$this->db->fetchAssociative('SELECT request_fingerprint,response FROM proposal_requests WHERE actor_id = ? AND action = ? AND request_key = ?',[$actor->id,$action,$key]);if(!$row) { return null; }
        if(!hash_equals($row['request_fingerprint'],$fingerprint)) { throw new ApiProblem(409,'idempotency_conflict','Cette clé a déjà servi pour une autre opération.'); }return json_decode($row['response'],true,flags:JSON_THROW_ON_ERROR);
    }
    private function remember(StockUser $actor,string $action,string $key,string $fingerprint,string $id,array $result): void
    {
        $this->db->executeStatement('INSERT INTO proposal_requests (actor_id,action,request_key,request_fingerprint,proposal_id,response) VALUES (?,?,?,?,?,?::jsonb)',[$actor->id,$action,$key,$fingerprint,$id,json_encode($result,JSON_THROW_ON_ERROR)]);
    }
    private function audit(StockUser $actor,string $id,string $action,array $fields): void
    {
        $this->db->executeStatement("INSERT INTO audit_logs (actor_id,action,target_type,target_id,context) VALUES (?,?,'article_proposal',?,?::jsonb)",[$actor->id,$action,$id,json_encode(['fields'=>$fields],JSON_THROW_ON_ERROR)]);
    }
}
