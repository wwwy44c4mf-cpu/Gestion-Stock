<?php

declare(strict_types=1);
namespace App\Notification;
use Doctrine\DBAL\Connection;
/** Rebuild only the original alert set, excluding resolved alerts and lost access. */
final class DailySummaryAudience
{
    public function __construct(private readonly Connection $db) {}
    public static function item(array $row): array
    {
        return ['article'=>preg_replace('/[\x00-\x1f\x7f]/u',' ',$row['article']),'store'=>preg_replace('/[\x00-\x1f\x7f]/u',' ',$row['store']),
            'quantity'=>(int)$row['quantity'],'threshold'=>(int)$row['threshold'],'level'=>$row['level'],
            'occurred_at'=>(new \DateTimeImmutable($row['opened_at']))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_RFC3339),
            'path'=>'/stocks/'.$row['store_id'].'/'.$row['article_id']];
    }
    public function payload(array $event,?\DateTimeImmutable $now=null): ?array
    {
        $today=($now??new \DateTimeImmutable())->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d');
        $rows=$this->db->fetchAllAssociative(<<<'SQL'
SELECT ar.name article,s.name store,l.quantity,a.threshold,a.level,a.opened_at,a.store_id,a.article_id
FROM daily_notification_runs r JOIN users u ON u.id=r.recipient_id
JOIN daily_summary_items i ON i.run_id=r.id JOIN stock_alerts a ON a.id=i.alert_id
JOIN stock_levels l ON l.store_id=a.store_id AND l.article_id=a.article_id
JOIN articles ar ON ar.id=a.article_id JOIN stores s ON s.id=a.store_id
WHERE r.event_id=? AND r.batch_date IS NOT NULL AND r.local_date=?::date
 AND u.active AND u.anonymized_at IS NULL AND NOT u.development_only AND u.email IS NOT NULL
 AND encode(sha256(convert_to(lower(btrim(u.email)),'UTF8')),'hex')=r.email_fingerprint
 AND a.status='active' AND s.active
 AND EXISTS(SELECT 1 FROM effective_alert_recipients(a.store_id,'daily_summary') e WHERE e.user_id=r.recipient_id)
ORDER BY s.name,s.id,ar.name,ar.id,a.id
SQL,[$event['id'],$today]);
        return $rows===[]?null:['items'=>array_map(self::item(...),$rows)];
    }
}
