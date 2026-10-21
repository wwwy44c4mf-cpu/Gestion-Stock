<?php

declare(strict_types=1);
namespace App\EventSubscriber;
use App\Privacy\RestoreGate;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

final class PrivacyRestoreSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly RestoreGate $gate) {}
    public static function getSubscribedEvents(): array
    {
        return ['kernel.request'=>['request',1000],WorkerStartedEvent::class=>['worker',1000],WorkerMessageReceivedEvent::class=>['worker',1000]];
    }
    public function request(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {return;}
        try { $closed=$this->gate->closed(); } catch (\Throwable) { $closed=true; }
        if ($closed) { $event->setResponse(new Response('Maintenance : retraitement des données requis.',503,['Cache-Control'=>'no-store','Retry-After'=>'60'])); }
    }
    public function worker(): void
    {
        if ($this->gate->closed()) { throw new \RuntimeException('privacy_restore_required'); }
    }
}
