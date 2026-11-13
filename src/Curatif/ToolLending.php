<?php

declare(strict_types=1);
namespace App\Curatif;
use App\Api\ApiProblem;
use App\Security\{AccessPolicy,AccessTarget,Permission,StockUser};
use Doctrine\DBAL\Connection;
final class ToolLending
{
    public function __construct(private readonly Connection $db,private readonly AccessPolicy $policy) {}
    public function items(StockUser $actor,string $store): array
    {
        $this->policy->assert($actor,Permission::CONSUMPTION_CREATE,AccessTarget::store($store));
        return $this->db->fetchAllAssociative("SELECT t.*,l.id loan_id,l.borrower_id,l.borrowed_at,CASE WHEN u.anonymized_at IS NULL THEN u.name ELSE 'Compte anonymisé' END borrower_name FROM tool_items t LEFT JOIN tool_loans l ON l.tool_id=t.id AND l.returned_at IS NULL LEFT JOIN users u ON u.id=l.borrower_id WHERE t.store_id=? AND t.active ORDER BY t.name,t.reference,t.id",[$store]);
    }
    public function add(StockUser $actor,string $store,string $name,string $reference,int $copies): void
    {
        if(trim($name)===''||mb_strlen($name)>160||trim($reference)===''||mb_strlen($reference)>70||$copies<1||$copies>50) {throw new ApiProblem(422,'invalid_tool','Renseignez un nom, une référence et de 1 à 50 exemplaires.');}
        $this->db->transactional(function() use($actor,$store,$name,$reference,$copies): void {
            $this->lock();$this->policy->assert($actor,Permission::ENTRY_CREATE,AccessTarget::store($store));
            $this->db->executeStatement('UPDATE stores SET ca_enabled=true WHERE id=?',[$store]);
            for($i=1;$i<=$copies;$i++) {$this->db->insert('tool_items',['store_id'=>$store,'name'=>trim($name),'reference'=>trim($reference).($copies>1?'-'.$i:'')]);}
            $this->audit($actor,$store,'tools.created',null,['copies'=>$copies]);
        });
    }
    public function borrow(StockUser $actor,string $store,array $ids,string $key): void
    {
        $ids=array_values(array_unique($ids));sort($ids);if(!$ids||count($ids)>50) {throw new ApiProblem(422,'select_tools','Cochez de 1 à 50 outils disponibles.');}
        $fingerprint=hash('sha256',json_encode([$store,$ids]));
        $this->db->transactional(function() use($actor,$store,$ids,$key,$fingerprint): void {
            $this->lock();$this->policy->assert($actor,Permission::CONSUMPTION_CREATE,AccessTarget::store($store));
            $this->db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['tools-person:'.$store.':'.$actor->id]);
            if($this->request($actor,$key,$fingerprint,'tools.borrow')) {return;}
            foreach($ids as $id) {
                $tool=$this->tool($id,$store);if(!$tool['active']||$this->db->fetchOne('SELECT 1 FROM tool_loans WHERE tool_id=? AND returned_at IS NULL',[$id])) {throw new ApiProblem(409,'tool_unavailable','Un outil sélectionné vient d’être emprunté. Rechargez la liste.');}
                $loan=$this->db->fetchOne('INSERT INTO tool_loans(tool_id,store_id,borrower_id) VALUES(?,?,?) RETURNING id',[$id,$store,$actor->id]);$this->audit($actor,$store,'tools.borrowed',$loan,[]);
            }
            $this->db->insert('ca_requests',['actor_id'=>$actor->id,'request_key'=>$key,'fingerprint'=>$fingerprint,'kind'=>'tools.borrow','result'=>'{}']);
        });
    }
    public function giveBackAll(StockUser $actor,string $store,string $borrower,array $expectedLoans): void
    {
        if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$borrower)||!$expectedLoans||count($expectedLoans)>500) {throw new ApiProblem(422,'invalid_tool_return','Rechargez la liste des outils à rendre.');}
        foreach($expectedLoans as $id) {if(!is_string($id)||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$id)) {throw new ApiProblem(422,'invalid_tool_return','Sélection invalide.');}}
        $this->db->transactional(function() use($actor,$store,$borrower,$expectedLoans): void {
            $this->lock();$this->policy->assert($actor,Permission::RETURN_CREATE,AccessTarget::store($store));
            if($actor->role==='USER'&&$borrower!==$actor->id) {throw new ApiProblem(403,'tool_return_forbidden','Vous ne pouvez rendre que vos propres outils.');}
            $this->db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['tools-person:'.$store.':'.$borrower]);
            $loans=$this->db->fetchAllAssociative('SELECT id,tool_id FROM tool_loans WHERE store_id=? AND borrower_id=? AND returned_at IS NULL ORDER BY tool_id',[$store,$borrower]);
            if(!$loans) {return;}
            $current=array_column($loans,'id');sort($current);$expected=array_values(array_unique($expectedLoans));sort($expected);
            if($current!==$expected) {throw new ApiProblem(409,'loans_changed','La liste des outils a changé. Rechargez-la avant de rendre les outils.');}
            foreach($loans as $loan) {$this->giveBack($actor,$store,$loan['tool_id'],$loan['id']);}
        });
    }
    public function giveBack(StockUser $actor,string $store,string $id,string $expectedLoan): void
    {
        $this->db->transactional(function() use($actor,$store,$id,$expectedLoan): void {
            $this->lock();$this->policy->assert($actor,Permission::RETURN_CREATE,AccessTarget::store($store));$this->tool($id,$store);
            $loan=$this->db->fetchAssociative('SELECT * FROM tool_loans WHERE tool_id=? AND returned_at IS NULL FOR UPDATE',[$id]);
            if(!$loan) {return;}
            if($loan['id']!==$expectedLoan) {throw new ApiProblem(409,'loan_changed','Cet emprunt a changé. Rechargez la liste.');}
            if($loan['store_id']!==$store||($actor->role==='USER'&&$loan['borrower_id']!==$actor->id)) {throw new ApiProblem(403,'tool_return_forbidden','Vous ne pouvez rendre que vos propres outils, dans leur magasin d’origine.');}
            $this->db->executeStatement('UPDATE tool_loans SET returned_at=clock_timestamp(),returned_by=? WHERE id=?',[$actor->id,$loan['id']]);$this->audit($actor,$store,'tools.returned',$loan['id'],[]);
            if($this->db->fetchOne("SELECT borrowed_at+interval '1 year'<=transaction_timestamp() FROM tool_loans WHERE id=?",[$loan['id']])) {
                $this->db->executeStatement("INSERT INTO users(id,active,anonymized_at,role_id) VALUES('00000000-0000-4000-8000-000000000001',false,transaction_timestamp(),3) ON CONFLICT(id) DO NOTHING");
                $this->db->executeStatement("UPDATE tool_loans SET borrower_id='00000000-0000-4000-8000-000000000001' WHERE id=?",[$loan['id']]);
            }
            if($this->db->fetchOne("SELECT to_regprocedure('privacy_release_accounts()')")) {$this->db->executeQuery('SELECT privacy_release_accounts()');}
        });
    }
    public function remove(StockUser $actor,string $store,string $id): void
    {
        $this->db->transactional(function() use($actor,$store,$id): void {
            $this->lock();$this->policy->assert($actor,Permission::ENTRY_CREATE,AccessTarget::store($store));$this->tool($id,$store);
            if($this->db->fetchOne('SELECT 1 FROM tool_loans WHERE tool_id=? AND returned_at IS NULL',[$id])) {throw new ApiProblem(409,'tool_on_loan','Cet outil est emprunté : il doit être rendu avant son retrait.');}
            $this->db->executeStatement('UPDATE tool_items SET active=false WHERE id=?',[$id]);$this->audit($actor,$store,'tools.removed',$id,[]);
        });
    }
    private function tool(string $id,string $store): array
    {
        if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$id)) {throw new ApiProblem(404,'tool_not_found','Outil introuvable.');}
        $row=$this->db->fetchAssociative('SELECT * FROM tool_items WHERE id=? AND store_id=? FOR UPDATE',[$id,$store]);if(!$row) {throw new ApiProblem(404,'tool_not_found','Outil introuvable dans ce magasin.');}return $row;
    }
    private function lock(): void {foreach(['users','user_stores','stores'] as $table) {$this->db->executeStatement('LOCK TABLE '.$table.' IN SHARE MODE');}}
    private function request(StockUser $actor,string $key,string $hash,string $kind): bool
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$key)) {throw new ApiProblem(422,'invalid_form','Rechargez le formulaire.');}
        $this->db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['ca:'.$actor->id.':'.$key]);
        $row=$this->db->fetchAssociative('SELECT fingerprint,kind FROM ca_requests WHERE actor_id=? AND request_key=?',[$actor->id,$key]);
        if($row&&($row['kind']!==$kind||!hash_equals($row['fingerprint'],$hash))) {throw new ApiProblem(409,'replay_conflict','Ce formulaire a déjà servi pour une autre opération.');}return (bool)$row;
    }
    private function audit(StockUser $actor,string $store,string $action,?string $id,array $context): void {$this->db->executeStatement("INSERT INTO audit_logs(actor_id,store_id,action,target_type,target_id,context) VALUES (?,?,?,'tool',?,?::jsonb)",[$actor->id,$store,$action,$id,json_encode((object)$context)]);}
}
