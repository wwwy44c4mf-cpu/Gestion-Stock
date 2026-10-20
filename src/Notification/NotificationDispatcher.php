<?php

declare(strict_types=1);
namespace App\Notification;
use App\Message\DeliverNotification;
use App\Message\DispatchNotifications;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
#[AsMessageHandler]
final class NotificationDispatcher
{
    public function __construct(private readonly Connection $db,private readonly MessageBusInterface $bus,
        #[Autowire('%env(bool:MAIL_DELIVERY_ENABLED)%')] private readonly bool $enabled) {}
    public function __invoke(DispatchNotifications $message): void {$this->dispatch();}
    public function dispatch(): int
    {
        if(!$this->enabled) {return 0;}
        return $this->db->transactional(function(): int {
            $ids=$this->db->fetchFirstColumn("SELECT id FROM notification_events WHERE status='pending' AND available_at<=clock_timestamp() ORDER BY created_at,id LIMIT 100 FOR UPDATE SKIP LOCKED");
            foreach($ids as $id) {
                // Doctrine Messenger uses this same DBAL connection: queue insert and state commit together.
                $this->bus->dispatch(new DeliverNotification($id));
                $this->db->executeStatement("UPDATE notification_events SET status='queued' WHERE id=?",[$id]);
            }
            return count($ids);
        });
    }
}
