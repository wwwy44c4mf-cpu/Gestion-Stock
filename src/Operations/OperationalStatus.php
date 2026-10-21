<?php

declare(strict_types=1);
namespace App\Operations;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** CLI-only aggregate diagnostics: never load queue bodies, recipients, or SMTP configuration. */
final class OperationalStatus
{
    public function __construct(private readonly Connection $db,
        #[Autowire('%env(bool:MAIL_DELIVERY_ENABLED)%')] private readonly bool $mailEnabled,
        #[Autowire('%env(int:OPS_QUEUE_MAX_AGE_SECONDS)%')] private readonly int $queueMaxAge=300) {}
    public function read(): array
    {
        $base=['timestamp'=>(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),'status'=>'unavailable'];
        if($this->queueMaxAge<1||$this->queueMaxAge>604800) {return $base+['code'=>'invalid_queue_age_threshold'];}
        $own=!$this->db->isTransactionActive();
        try {
            if($own) {$this->db->beginTransaction();$this->db->executeStatement("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY");$this->db->executeStatement("SET LOCAL statement_timeout='5000ms'");}
            $rows=$this->db->fetchAllAssociative(<<<'SQL'
WITH classified AS (
 SELECT CASE WHEN queue_name IN ('notifications','async','failed') THEN queue_name ELSE 'other' END AS queue,
 CASE WHEN delivered_at IS NOT NULL AND delivered_at>=CURRENT_TIMESTAMP-make_interval(secs=>CASE WHEN queue_name='notifications' THEN 120 ELSE 3600 END) THEN 'inflight'
      WHEN available_at>CURRENT_TIMESTAMP THEN 'delayed' ELSE 'due' END AS state,available_at
 FROM messenger_messages
)
SELECT queue,count(*) AS total,count(*) FILTER(WHERE state='due') AS due,count(*) FILTER(WHERE state='delayed') AS delayed,count(*) FILTER(WHERE state='inflight') AS inflight,
 COALESCE(GREATEST(0,EXTRACT(EPOCH FROM CURRENT_TIMESTAMP-min(available_at) FILTER(WHERE state='due')))::bigint,0) AS oldest_due_seconds
FROM classified GROUP BY queue ORDER BY queue
SQL);
            $queues=[];$signals=[];
            foreach($rows as $row) {$name=$row['queue'];unset($row['queue']);$queues[$name]=array_map('intval',$row);if($name==='failed'&&(int)$row['total']>0) {$signals[]='failed_transport_not_empty';}elseif((int)$row['oldest_due_seconds']>$this->queueMaxAge) {$signals[]='queue_overdue';}}
            $notifications=array_map('intval',$this->db->fetchAssociative("SELECT count(*) FILTER(WHERE status='pending') AS pending,count(*) FILTER(WHERE status='pending' AND available_at<=CURRENT_TIMESTAMP) AS pending_due,COALESCE(GREATEST(0,EXTRACT(EPOCH FROM CURRENT_TIMESTAMP-min(available_at) FILTER(WHERE status='pending' AND available_at<=CURRENT_TIMESTAMP)))::bigint,0) AS oldest_pending_seconds,count(*) FILTER(WHERE status='queued') AS queued,count(*) FILTER(WHERE status='failed') AS failed,count(*) FILTER(WHERE status='failed' AND attempts>=4) AS exhausted FROM notification_events"));
            $attempts=array_map('intval',$this->db->fetchAssociative("SELECT count(*) FILTER(WHERE result_code='smtp_delivery_failed') AS smtp_failures,count(*) FILTER(WHERE result_code='notification_render_failed') AS render_failures,count(*) FILTER(WHERE result_code='recipient_unavailable') AS recipient_unavailable FROM notification_delivery_attempts WHERE created_at>=CURRENT_TIMESTAMP-interval '24 hours'"));
            $outbox=array_map('intval',$this->db->fetchAssociative("SELECT count(*) AS pending,COALESCE(GREATEST(0,EXTRACT(EPOCH FROM CURRENT_TIMESTAMP-min(created_at)))::bigint,0) AS oldest_seconds FROM stock_change_events WHERE processed_at IS NULL"));
            $differences=(int)$this->db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference<>0');
            if($this->mailEnabled&&$notifications['oldest_pending_seconds']>$this->queueMaxAge) {$signals[]='notification_dispatch_overdue';}
            if($notifications['failed']>0) {$signals[]='notification_failures';}
            if($differences>0) {$signals[]='stock_reconciliation_mismatch';}
            if($own) {$this->db->rollBack();}
            return array_replace($base,['status'=>$signals?'degraded':'ok','database'=>'ok','mail_delivery_enabled'=>$this->mailEnabled,'queue_max_age_seconds'=>$this->queueMaxAge,'queues'=>(object)$queues,'notifications'=>$notifications,'delivery_last_24h'=>$attempts,'stock_events'=>$outbox,'stock_reconciliation_differences'=>$differences,'signals'=>array_values(array_unique($signals))]);
        } catch(\Throwable) {
            if($own&&$this->db->isTransactionActive()) {try {$this->db->rollBack();}catch(\Throwable) {}}
            return $base+['code'=>'database_or_schema_unavailable'];
        }
    }
}
