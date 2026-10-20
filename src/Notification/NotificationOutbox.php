<?php

declare(strict_types=1);
namespace App\Notification;
use Doctrine\DBAL\Connection;
final class NotificationOutbox
{
    public function __construct(private readonly Connection $db,private readonly NotificationPayload $payloads) {}
    /** Must be called inside the business transaction; never contacts SMTP. */
    public function enqueue(string $key,string $type,string $recipientId,array $payload,?string $storeId=null,?string $alertId=null): string
    {
        if(!$this->db->isTransactionActive()) {throw new \LogicException('notification_transaction_required');}
        if(trim($key)===''||strlen($key)>255) {throw new \InvalidArgumentException('notification_key_invalid');}
        $this->payloads->validate($type,$payload);$json=json_encode($payload,JSON_THROW_ON_ERROR);
        $this->db->executeStatement('INSERT INTO notification_events (event_key,type,recipient_id,store_id,alert_id,payload) VALUES (?,?,?,?,?,?::jsonb) ON CONFLICT (event_key) DO NOTHING',[$key,$type,$recipientId,$storeId,$alertId,$json]);
        $row=$this->db->fetchAssociative('SELECT id,type,recipient_id,store_id,alert_id,payload FROM notification_events WHERE event_key=?',[$key]);
        $samePayload=$this->db->fetchOne('SELECT payload=?::jsonb FROM notification_events WHERE id=?',[$json,$row['id']]);
        if(!$samePayload || $row['type']!==$type||$row['recipient_id']!==strtolower($recipientId)||$row['store_id']!==($storeId===null?null:strtolower($storeId))||$row['alert_id']!==($alertId===null?null:strtolower($alertId))) {throw new \LogicException('notification_idempotency_conflict');}
        return $row['id'];
    }
}
