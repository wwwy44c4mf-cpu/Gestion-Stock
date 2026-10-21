<?php

declare(strict_types=1);
namespace App\Tests;
use App\Operations\StructuredLogger;
use App\EventSubscriber\OperationsSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final class OperationsLoggingTest extends TestCase
{
    public function testStructuredLoggerNeverSerializesExceptionMessagesBodiesOrUnknownObjects(): void
    {
        $stream=fopen('php://temp','w+');$stack=new RequestStack();$request=Request::create('/connexion?password=private-marker',server:['HTTP_AUTHORIZATION'=>'Bearer private-marker','HTTP_COOKIE'=>'private-marker']);$request->attributes->set('_request_id',str_repeat('a',32));$stack->push($request);
        $logger=new StructuredLogger($stack,'debug',$stream);
        $dangerous=new class implements \Stringable {public function __toString(): string {throw new \RuntimeException('must_not_be_called');}};
        $logger->error($dangerous,['exception'=>new \RuntimeException('smtp://private-marker:private-marker@secret.test'),'password'=>'private-marker','body'=>['email'=>'private-marker'],'command'=>'private-marker','object'=>$dangerous]);
        rewind($stream);$raw=stream_get_contents($stream);$row=json_decode($raw,true,flags:JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('private-marker',$raw);self::assertStringNotContainsString('secret.test',$raw);self::assertSame('runtime_exception',$row['event']);self::assertSame('error',$row['level']);self::assertSame(str_repeat('a',32),$row['request_id']);self::assertSame(\RuntimeException::class,$row['exception_class']);self::assertMatchesRegularExpression('/Z$/',$row['timestamp']);self::assertCount(5,$row);
    }
    public function testHttpIdentifierIsGeneratedByServerCorrelatesErrorsAndDoesNotTrustClient(): void
    {
        $stream=fopen('php://temp','w+');$stack=new RequestStack();$request=Request::create('/secret-path?token=private-marker','GET',server:['HTTP_X_REQUEST_ID'=>str_repeat('b',32)]);$stack->push($request);$logger=new StructuredLogger($stack,'info',$stream);$subscriber=new OperationsSubscriber($logger);$kernel=$this->createStub(HttpKernelInterface::class);
        $subscriber->identify(new RequestEvent($kernel,$request,HttpKernelInterface::MAIN_REQUEST));$id=$request->attributes->get('_request_id');self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D',$id);self::assertNotSame(str_repeat('b',32),$id);
        $response=new Response('',503);$subscriber->response(new ResponseEvent($kernel,$request,HttpKernelInterface::MAIN_REQUEST,$response));self::assertSame($id,$response->headers->get('X-Request-Id'));
        rewind($stream);$raw=stream_get_contents($stream);$row=json_decode($raw,true);self::assertSame($id,$row['request_id']);self::assertSame(503,$row['status']);self::assertSame('error',$row['level']);self::assertGreaterThanOrEqual(0,$row['duration_ms']);self::assertStringNotContainsString('secret-path',$raw);self::assertStringNotContainsString('private-marker',$raw);
    }
    public function testLevelsAndNotificationFailureCodesAreBounded(): void
    {
        $stream=fopen('php://temp','w+');$logger=new StructuredLogger(new RequestStack(),'warning',$stream);$logger->info('http_request');$logger->warning('notification_delivery',['attempt'=>2,'result_code'=>'smtp_delivery_failed','notification_id'=>'private-marker']);$logger->error('notification_delivery',['result_code'=>'private-marker']);rewind($stream);$lines=explode("\n",trim(stream_get_contents($stream)));self::assertCount(2,$lines);self::assertSame('smtp_delivery_failed',json_decode($lines[0],true)['result_code']);self::assertArrayNotHasKey('result_code',json_decode($lines[1],true));self::assertStringNotContainsString('private-marker',implode('',$lines));
    }
    public function testFailedMessengerMessageDoesNotLogItsPayloadOrException(): void
    {
        $stream=fopen('php://temp','w+');$subscriber=new OperationsSubscriber(new StructuredLogger(new RequestStack(),'info',$stream));
        $event=new WorkerMessageFailedEvent(new Envelope(new \App\Message\DeliverNotification('private-marker')),'notifications',new \RuntimeException('private-marker SMTP response'));
        $subscriber->failed($event);rewind($stream);$raw=stream_get_contents($stream);$row=json_decode($raw,true);self::assertSame('message_failed',$row['event']);self::assertSame('notification',$row['message_type']);self::assertSame('error',$row['level']);self::assertNull($row['request_id']);self::assertStringNotContainsString('private-marker',$raw);
    }
}
