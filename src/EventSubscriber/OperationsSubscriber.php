<?php

declare(strict_types=1);
namespace App\EventSubscriber;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

final class OperationsSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly LoggerInterface $logger) {}
    public static function getSubscribedEvents(): array
    {
        return ['kernel.request'=>['identify',1024],'kernel.response'=>['response',-2048],'console.error'=>'consoleError',WorkerStartedEvent::class=>'started',WorkerStoppedEvent::class=>'stopped',WorkerMessageFailedEvent::class=>'failed',WorkerMessageHandledEvent::class=>'handled'];
    }
    public function identify(RequestEvent $event): void
    {
        if(!$event->isMainRequest()) {return;}
        $event->getRequest()->attributes->set('_request_id',bin2hex(random_bytes(16)));
        $event->getRequest()->attributes->set('_ops_started',hrtime(true));
    }
    public function response(ResponseEvent $event): void
    {
        if(!$event->isMainRequest()) {return;}
        $request=$event->getRequest();$status=$event->getResponse()->getStatusCode();
        $id=$request->attributes->get('_request_id');if(is_string($id)) {$event->getResponse()->headers->set('X-Request-Id',$id);}
        $level=$status>=500?'error':($status>=400?'warning':'info');
        $this->logger->log($level,'http_request',['request_id'=>$id,'status'=>$status,'method'=>$request->getMethod(),'route'=>$request->attributes->get('_route','unmatched'),'duration_ms'=>(int)max(0,(hrtime(true)-$request->attributes->get('_ops_started',hrtime(true)))/1000000)]);
    }
    public function started(WorkerStartedEvent $event): void {$this->logger->info('worker_started');}
    public function stopped(WorkerStoppedEvent $event): void {$this->logger->info('worker_stopped');}
    public function failed(WorkerMessageFailedEvent $event): void {$this->logger->error('message_failed',['message_type'=>$this->type($event->getEnvelope()->getMessage()),'exception_class'=>$event->getThrowable()::class]);}
    public function handled(WorkerMessageHandledEvent $event): void {$this->logger->info('message_handled',['message_type'=>$this->type($event->getEnvelope()->getMessage())]);}
    private function type(object $message): string {return $message instanceof \App\Message\DeliverNotification?'notification':($message instanceof \App\Message\InfrastructureProbe?'infrastructure':'other');}
    public function consoleError(ConsoleErrorEvent $event): void {$this->logger->error('console_failed',['exception_class'=>$event->getError()::class,'exit_code'=>max(0,$event->getExitCode())]);}
}
