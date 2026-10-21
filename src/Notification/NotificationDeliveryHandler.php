<?php

declare(strict_types=1);
namespace App\Notification;
use App\Message\DeliverNotification;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
#[AsMessageHandler]
final class NotificationDeliveryHandler
{
    public function __construct(private readonly Connection $db,private readonly MailDelivery $delivery,private readonly NotificationRenderer $renderer,private readonly LoggerInterface $logger,
        #[Autowire('%env(bool:MAIL_DELIVERY_ENABLED)%')] private readonly bool $enabled,
        #[Autowire('%kernel.environment%')] private readonly string $environment) {}
    public function __invoke(DeliverNotification $message): void
    {
        if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di',$message->id)) {throw new UnrecoverableMessageHandlingException('notification_id_invalid');}
        $result=$this->db->transactional(function() use($message): array {
            $event=$this->db->fetchAssociative('SELECT * FROM notification_events WHERE id=? FOR UPDATE',[$message->id]);
            if(!$event||$event['status']==='sent'||$event['status']==='pending') {return ['code'=>null];}
            if(!$this->enabled) {$this->db->executeStatement("UPDATE notification_events SET status='pending' WHERE id=?",[$message->id]);return ['code'=>null];}
            if((int)$event['attempts']>=4) {return ['code'=>'notification_attempts_exhausted','terminal'=>true];}
            $recipient=$this->db->fetchAssociative('SELECT email,active,development_only FROM users WHERE id=?',[$event['recipient_id']]);
            $allowed=$recipient&&$recipient['active']&&$recipient['email']!==null&&($this->environment!=='prod'||!$recipient['development_only']);
            if($allowed&&$event['store_id']!==null) {$allowed=(bool)$this->db->fetchOne('SELECT active FROM stores WHERE id=?',[$event['store_id']]);}
            if($allowed&&in_array($event['type'],['stock_low','stock_critical','daily_summary'],true)&&$event['store_id']!==null) {
                $type=match($event['type']) {'stock_low'=>'low','stock_critical'=>'critical',default=>'daily_summary'};
                $allowed=(bool)$this->db->fetchOne('SELECT 1 FROM effective_alert_recipients(?,?) WHERE user_id=?',[$event['store_id'],$type,$event['recipient_id']]);
            }
            $attempt=(int)$event['attempts']+1;$code=null;
            if(!$allowed) {$code='recipient_unavailable';}
            else {try {$email=$this->renderer->render($event,$recipient['email']);}catch(\Throwable) {$code='notification_render_failed';}
                if($code===null) {try {$this->delivery->send($email);}catch(\Throwable) {$code='smtp_delivery_failed';}}
            }
            $this->db->executeStatement("UPDATE notification_events SET attempts=?,status=?,sent_at=CASE WHEN ? THEN clock_timestamp() ELSE NULL END,last_error_code=? WHERE id=?",[$attempt,$code===null?'sent':'failed',$code===null?'true':'false',$code,$message->id]);
            $this->db->executeStatement('INSERT INTO notification_delivery_attempts (event_id,attempt_number,result_code) VALUES (?,?,?)',[$message->id,$attempt,$code??'sent']);
            return ['code'=>$code,'attempt'=>$attempt,'terminal'=>$code!==null&&($code!=='smtp_delivery_failed'||$attempt>=4)];
        });
        if(isset($result['attempt'])) {$this->logger->notice('notification_delivery',['notification_id'=>$message->id,'attempt'=>$result['attempt'],'result_code'=>$result['code']??'sent']);}
        if($result['code']!==null) {
            // Intentionally no original exception or diagnostic text: Messenger also persists errors.
            if($result['terminal']) {throw new UnrecoverableMessageHandlingException($result['code']);}
            throw new \RuntimeException('smtp_delivery_failed');
        }
    }
}
