<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

final class WorkerHeartbeatSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [WorkerStartedEvent::class => 'heartbeat', WorkerRunningEvent::class => 'heartbeat'];
    }

    public function heartbeat(): void
    {
        touch('/tmp/worker-heartbeat');
    }
}
