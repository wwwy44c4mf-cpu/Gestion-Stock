<?php

declare(strict_types=1);
namespace App\Administration;
use App\Api\ApiProblem;
use App\Security\{AccessIdentity,Permission,StockUser};
use Doctrine\DBAL\Connection;

/** Read state is personal to each administrator, independent of the review decision. */
final class AdminInbox
{
    public function __construct(private readonly Connection $db,private readonly AccessIdentity $identity) {}
    public function items(StockUser $actor): array
    {
        $this->identity->requireRole($actor,Permission::ACCOUNTS_ADMIN);
        $rows=$this->db->fetchAllAssociative("SELECT p.id,'article_proposal' kind,p.created_at,coalesce(p.payload->>'name','Proposition d’article') title, r.read_at FROM article_proposals p LEFT JOIN administration_reads r ON r.user_id=? AND r.kind='article_proposal' AND r.target_id=p.id WHERE p.status='pending'",[$actor->id]);
        // Narrow historical fixtures predate USR-03; account requests have no rows there.
        if($this->db->fetchOne("SELECT to_regclass('account_requests')")!==null) {
            $rows=array_merge($rows,$this->db->fetchAllAssociative("SELECT a.id,'account_request' kind,a.created_at,a.first_name||' '||a.last_name title,r.read_at FROM account_requests a LEFT JOIN administration_reads r ON r.user_id=? AND r.kind='account_request' AND r.target_id=a.id WHERE a.status='pending' AND a.anonymized_at IS NULL",[$actor->id]));
        }
        usort($rows,static fn(array $a,array $b):int=>[(int)($a['read_at']!==null),$a['created_at'],$a['id']]<=>[(int)($b['read_at']!==null),$b['created_at'],$b['id']]);
        return $rows;
    }
    public function count(StockUser $actor): int
    {
        $this->identity->requireRole($actor,Permission::ACCOUNTS_ADMIN);
        $total=(int)$this->db->fetchOne("SELECT count(*) FROM article_proposals p WHERE p.status='pending' AND NOT EXISTS(SELECT 1 FROM administration_reads r WHERE r.user_id=? AND r.kind='article_proposal' AND r.target_id=p.id)",[$actor->id]);
        if($this->db->fetchOne("SELECT to_regclass('account_requests')")!==null) {
            $total+=(int)$this->db->fetchOne("SELECT count(*) FROM account_requests a WHERE a.status='pending' AND a.anonymized_at IS NULL AND NOT EXISTS(SELECT 1 FROM administration_reads r WHERE r.user_id=? AND r.kind='account_request' AND r.target_id=a.id)",[$actor->id]);
        }
        return $total;
    }
    public function read(StockUser $actor,string $kind,string $id): void
    {
        $this->db->transactional(function() use($actor,$kind,$id): void {
            $this->db->executeStatement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
            $this->identity->requireRole($actor,Permission::ACCOUNTS_ADMIN);
            $sql=match($kind) {'account_request'=>"SELECT 1 FROM account_requests WHERE id=? AND status='pending' AND anonymized_at IS NULL",'article_proposal'=>"SELECT 1 FROM article_proposals WHERE id=? AND status='pending'",default=>throw new ApiProblem(422,'invalid_notification','Notification invalide.')};
            if($this->db->fetchOne($sql,[$id])) {$this->db->executeStatement('INSERT INTO administration_reads(user_id,kind,target_id) VALUES (?,?,?) ON CONFLICT DO NOTHING',[$actor->id,$kind,$id]);}
        });
    }
}
