<?php

declare(strict_types=1);
namespace App\Administration;

use App\Api\{ApiProblem,OpenApiContract,Pagination};
use App\Notification\NotificationOutbox;
use App\Security\{AccessIdentity,PasswordReset,Permission,StockUser};
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class AccountRequests
{
    public const ACCEPTED='Votre demande a été prise en compte. Si elle est approuvée par un administrateur, vous recevrez un lien pour créer votre mot de passe.';
    public function __construct(private readonly Connection $db,private readonly UserAdministration $users,
        private readonly AccessIdentity $identity,private readonly PasswordReset $passwords,private readonly NotificationOutbox $outbox,
        private readonly OpenApiContract $contract,#[Autowire('%kernel.environment%')] private readonly string $environment) {}

    public function request(\stdClass $body): void
    {
        if(!$this->contract->valid($body,['$ref'=>'#/components/schemas/AccountRequestCreate'])) {throw new ApiProblem(422,'invalid_account_request','Renseignez votre nom, prénom et une adresse courriel valide.');}
        foreach(['first_name','last_name'] as $field) {
            if(trim($body->$field)==='' || !preg_match('//u',$body->$field) || preg_match('/[\x00-\x1f\x7f]/',$body->$field)) {throw new ApiProblem(422,'invalid_account_request','Renseignez un nom et un prénom valides.');}
        }
        if(!filter_var($body->email,FILTER_VALIDATE_EMAIL) || mb_strlen(trim($body->first_name).' '.trim($body->last_name))>160) {throw new ApiProblem(422,'invalid_account_request','Vérifiez votre adresse courriel et la longueur de votre nom.');}
        $started=hrtime(true);
        try {
            $this->db->transactional(function() use($body): void {
                // Same first guard as account creation/revocation and activation.
                $this->db->executeStatement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
                $email=mb_strtolower(trim($body->email),'UTF-8');
                if($this->db->fetchOne('SELECT 1 FROM users WHERE lower(btrim(email))=?',[$email])) {return;}
                if($this->db->fetchOne("SELECT 1 FROM account_requests WHERE lower(btrim(email))=? AND (status='pending' OR created_at>clock_timestamp()-interval '1 day')",[$email])) {return;}
                $id=$this->db->fetchOne('INSERT INTO account_requests(first_name,last_name,email) VALUES (?,?,?) RETURNING id',[trim($body->first_name),trim($body->last_name),$email]);
                foreach($this->db->fetchFirstColumn("SELECT id FROM users WHERE role_id=1 AND active AND anonymized_at IS NULL AND (NOT development_only OR ?='dev') ORDER BY id",[$this->environment]) as $admin) {
                    $this->outbox->enqueue('account-request:'.$id.':'.$admin,'information',$admin,[
                        'subject'=>'Nouvelle demande de compte','body'=>'Une demande de compte attend votre validation dans Gestion-Stock.',
                        'path'=>'/administration/demandes-comptes/'.$id,
                    ]);
                }
            });
        } finally { $remaining=150000-(int)((hrtime(true)-$started)/1000);if($remaining>0) {usleep($remaining);} }
    }
    public function listing(StockUser $actor,int $page=1): array
    {
        $this->identity->requireRole($actor,Permission::ACCOUNTS_ADMIN);
        $pagination=new Pagination($page,25);
        $total=(int)$this->db->fetchOne('SELECT count(*) FROM account_requests WHERE anonymized_at IS NULL');
        $rows=$this->db->fetchAllAssociative("SELECT * FROM account_requests WHERE anonymized_at IS NULL ORDER BY (status='pending') DESC,created_at DESC,id LIMIT 25 OFFSET ".$pagination->offset());
        return $pagination->response(array_map($this->resource(...),$rows),$total);
    }
    public function get(StockUser $actor,string $id): array
    {
        $this->identity->requireRole($actor,Permission::ACCOUNTS_ADMIN);
        if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di',$id)) {throw new ApiProblem(404,'account_request_not_found','Demande introuvable.');}
        $row=$this->db->fetchAssociative('SELECT * FROM account_requests WHERE id=? AND anonymized_at IS NULL',[$id]);
        if(!$row) {throw new ApiProblem(404,'account_request_not_found','Demande introuvable.');}
        return $this->resource($row);
    }
    public function decide(StockUser $actor,string $id,string $action,\stdClass $body): array
    {
        $this->identity->requireRole($actor,Permission::ACCOUNTS_ADMIN);
        if(!in_array($action,['approve','reject','resend'],true)) {throw new ApiProblem(422,'invalid_review','Décision invalide.');}
        if($action==='approve' && !$this->contract->valid($body,['$ref'=>'#/components/schemas/AccountRequestApprove'])) {throw new ApiProblem(422,'invalid_review','Vérifiez le rôle et les magasins choisis.');}
        if($action!=='approve' && get_object_vars($body)!==[]) {throw new ApiProblem(422,'invalid_review','Décision invalide.');}
        return $this->db->transactional(function() use($actor,$id,$action,$body): array {
            $this->db->executeStatement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
            $row=$this->get($actor,$id);
            if($action==='resend') {
                if($row['status']!=='approved' || $row['activated_at']!==null || $row['user_id']===null) {throw new ApiProblem(409,'invitation_unavailable','Cette demande ne permet pas de renvoyer une invitation.');}
                $this->passwords->invite($row['user_id']);return $this->get($actor,$id);
            }
            $expected=$action==='approve'?'approved':'rejected';
            if($row['status']===$expected) {return $row;}
            if($row['status']!=='pending') {throw new ApiProblem(409,'account_request_reviewed','Cette demande a déjà été traitée.');}
            if(new \DateTimeImmutable($row['created_at']) <= new \DateTimeImmutable('-1 year')) {throw new ApiProblem(409,'account_request_expired','Cette demande a expiré. La personne doit déposer une nouvelle demande.');}
            $user=null;
            if($action==='approve') {
                $account=$this->users->create($actor,(object)['email'=>$row['email'],'name'=>$row['first_name'].' '.$row['last_name'],
                    'role'=>$body->role,'store_ids'=>$body->store_ids,'password'=>bin2hex(random_bytes(32))],'account-request:'.$id);
                $user=$account['data']['id'];
                $this->db->executeStatement('UPDATE users SET active=false,auth_version=auth_version+1,updated_at=clock_timestamp() WHERE id=?',[$user]);
            }
            $this->db->executeStatement('UPDATE account_requests SET status=?,reviewed_by=?,reviewed_at=clock_timestamp(),user_id=? WHERE id=?',[$expected,$actor->id,$user,$row['id']]);
            if($user!==null) {$this->passwords->invite($user);}
            $this->db->executeStatement('INSERT INTO audit_logs(actor_id,action,target_type,target_id) VALUES (?,?,?,?)',[$actor->id,'account_request.'.$expected,'account_request',$row['id']]);
            return $this->get($actor,$id);
        });
    }
    private function resource(array $row): array
    {
        foreach(['created_at','reviewed_at','activated_at'] as $field) {$row[$field]=$row[$field]===null?null:(new \DateTimeImmutable($row[$field]))->format(DATE_RFC3339);}
        return $row;
    }
}
