<?php

declare(strict_types=1);
namespace App\Notification;
use App\Message\GenerateDailySummaries;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
#[AsMessageHandler]
final class DailySummaryGenerator
{
    public function __construct(private readonly Connection $db,private readonly NotificationOutbox $outbox) {}
    public function __invoke(GenerateDailySummaries $message): void {$this->generate();}
    /** Also called periodically for same-day catch-up. No SMTP or stock writes. */
    public function generate(?\DateTimeImmutable $now=null): int
    {
        $now=($now??new \DateTimeImmutable())->setTimezone(new \DateTimeZone('Europe/Paris'));
        if($now->format('H:i:s')<'08:00:00') {return 0;}$date=$now->format('Y-m-d');
        return $this->db->transactional(function() use($now,$date): int {
            $this->db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['daily-summary:'.$date]);
            if($this->db->fetchOne('SELECT 1 FROM daily_summary_batches WHERE local_date=?::date',[$date])) {return 0;}
            // A single statement observes a coherent committed stock/audience snapshot.
            // It takes no stock row locks and does not block normal movements.
            $rows=$this->db->fetchAllAssociative(<<<'SQL'
SELECT u.id recipient_id,u.email,a.id alert_id,ar.name article,s.name store,l.quantity,a.threshold,a.level,a.opened_at,a.store_id,a.article_id
FROM stock_alerts a JOIN stock_levels l ON l.article_id=a.article_id AND l.store_id=a.store_id
JOIN articles ar ON ar.id=a.article_id JOIN stores s ON s.id=a.store_id
CROSS JOIN LATERAL effective_alert_recipients(a.store_id,'daily_summary') e JOIN users u ON u.id=e.user_id
WHERE a.status='active' AND s.active
 AND NOT EXISTS(SELECT 1 FROM daily_notification_runs r WHERE r.recipient_id=u.id AND r.local_date=?::date)
ORDER BY u.id,s.name,s.id,ar.name,ar.id,a.id
SQL,[$date]);
            $people=[];foreach($rows as $row) {$people[$row['recipient_id']][]=$row;}
            $this->db->insert('daily_summary_batches',['local_date'=>$date,'generated_at'=>$now->format(DATE_RFC3339),'recipient_count'=>count($people),'item_count'=>count($rows)]);
            foreach($people as $person=>$items) {
                $payload=['items'=>array_map(DailySummaryAudience::item(...),$items)];
                $event=$this->outbox->enqueue('daily-summary:'.$date.':'.$person,'daily_summary',$person,$payload);
                $run=$this->db->fetchOne('INSERT INTO daily_notification_runs(recipient_id,local_date,event_id,batch_date,email_fingerprint) VALUES (?,?::date,?,?::date,?) RETURNING id',[$person,$date,$event,$date,hash('sha256',strtolower(trim($items[0]['email'])))]);
                foreach($items as $item) {$this->db->insert('daily_summary_items',['run_id'=>$run,'alert_id'=>$item['alert_id']]);}
            }
            $this->db->executeStatement("INSERT INTO audit_logs(action,target_type,context) VALUES ('notifications.daily_summary','daily_summary_batch',?::jsonb)",[json_encode(['local_date'=>$date,'timezone'=>'Europe/Paris','recipient_count'=>count($people),'item_count'=>count($rows)],JSON_THROW_ON_ERROR)]);
            return count($people);
        });
    }
}
