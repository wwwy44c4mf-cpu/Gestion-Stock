<?php

declare(strict_types=1);
namespace App\Operations;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/** Positive projection: no free text, request data, exception message, or object serialization. */
final class StructuredLogger extends AbstractLogger
{
    private const LEVELS=['debug'=>100,'info'=>200,'notice'=>250,'warning'=>300,'error'=>400,'critical'=>500,'alert'=>550,'emergency'=>600];
    public function __construct(private readonly RequestStack $requests, #[Autowire('%env(APP_LOG_LEVEL)%')] private readonly string $minimum='info', private mixed $stream=null) {}
    public function log($level, string|\Stringable $message, array $context=[]): void
    {
        if (!isset(self::LEVELS[$level])) {throw new InvalidArgumentException('invalid_log_level');}
        if (self::LEVELS[$level]<(self::LEVELS[$this->minimum]??200)) {return;}
        $known=['http_request','notification_delivery','worker_started','worker_stopped','message_failed','message_handled','console_failed','database_unavailable'];
        $event=is_string($message)&&in_array($message,$known,true)?$message:($message==='Erreur API interne'?'api_error':(isset($context['exception'])?'runtime_exception':'runtime_event'));
        $requestId=$this->requests->getMainRequest()?->attributes->get('_request_id')??($context['request_id']??null);
        $data=['timestamp'=>(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),'level'=>$level,'event'=>$event,'request_id'=>is_string($requestId)&&preg_match('/^[a-f0-9]{32}$/D',$requestId)?$requestId:null];
        if($event==='http_request'&&!in_array($context['method']??null,['GET','POST','PUT','PATCH','DELETE','HEAD','OPTIONS'],true)) {unset($context['method']);}
        if(!in_array($event,['http_request','notification_delivery','message_failed','message_handled','console_failed'],true)) {$context=array_intersect_key($context,array_flip(['exception','exception_class']));}
        foreach(['status','duration_ms','attempt','exit_code','retry_count'] as $key) {if(isset($context[$key])&&is_int($context[$key])&&$context[$key]>=0) {$data[$key]=$context[$key];}}
        foreach(['route','method','result_code','message_type'] as $key) {if(isset($context[$key])&&is_string($context[$key])&&preg_match('/^[A-Za-z0-9_:.-]{1,120}$/D',$context[$key])) {$data[$key]=$context[$key];}}
        // Even apparently safe free text must not enter an arbitrary caller-controlled context.
        if(isset($data['result_code'])&&!in_array($data['result_code'],['sent','smtp_delivery_failed','recipient_unavailable','notification_render_failed','notification_attempts_exhausted'],true)) {unset($data['result_code']);}
        $exception=$context['exception']??null;$class=$exception instanceof \Throwable?$exception::class:($context['exception_class']??null);
        if(is_string($class)&&preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,180}$/D',$class)) {$data['exception_class']=$class;}
        $line=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)."\n";
        $stream=$this->stream??fopen('php://stderr','w');
        if(is_resource($stream)) {@fwrite($stream,$line);if($this->stream===null) {fclose($stream);}}
    }
}
