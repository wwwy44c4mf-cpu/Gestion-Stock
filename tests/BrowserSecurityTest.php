<?php

declare(strict_types=1);
namespace App\Tests;

use App\EventSubscriber\BrowserSecuritySubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class BrowserSecurityTest extends TestCase
{
    protected function tearDown(): void { Request::setTrustedProxies([], -1); }

    private function event(Request $request): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    public function testUntrustedClientCannotClaimHttpsAndRequestIsRefusedBeforeAuthentication(): void
    {
        $request = Request::create('http://stock.example.test/api/v1/auth/login', 'POST', server: ['REMOTE_ADDR'=>'203.0.113.8', 'HTTP_X_FORWARDED_PROTO'=>'https']);
        Request::setTrustedProxies(['192.0.2.10'], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
        $event = $this->event($request);
        (new BrowserSecuritySubscriber(true))->https($event);
        self::assertFalse($request->isSecure());
        self::assertSame(400, $event->getResponse()?->getStatusCode());
        self::assertFalse($request->hasSession());
    }

    public function testOnlyConfiguredProxyCanProvideSchemeAndClientIp(): void
    {
        Request::setTrustedProxies(['192.0.2.10'], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
        $request = Request::create('http://stock.example.test/connexion', server: ['REMOTE_ADDR'=>'192.0.2.10', 'HTTP_X_FORWARDED_PROTO'=>'https','HTTP_X_FORWARDED_FOR'=>'203.0.113.8','HTTP_X_FORWARDED_HOST'=>'attacker.test']);
        $event = $this->event($request);(new BrowserSecuritySubscriber(true))->https($event);
        self::assertTrue($request->isSecure());self::assertSame('203.0.113.8',$request->getClientIp());
        self::assertSame('stock.example.test',$request->getHost());self::assertFalse($event->hasResponse());
    }

    public function testHealthProbesAndDevelopmentHttpRemainAvailable(): void
    {
        foreach (['/health/live','/health/ready'] as $path) {
            $event=$this->event(Request::create('http://localhost'.$path));(new BrowserSecuritySubscriber(true))->https($event);self::assertFalse($event->hasResponse());
        }
        $event=$this->event(Request::create('http://localhost/connexion'));(new BrowserSecuritySubscriber(false))->https($event);self::assertFalse($event->hasResponse());
        $event=$this->event(Request::create('http://localhost/health/other'));(new BrowserSecuritySubscriber(true))->https($event);self::assertSame(400,$event->getResponse()?->getStatusCode());
    }

    public function testBrowserHeadersSecureCookiesAndHstsApplyToErrorsAsWellAsSuccess(): void
    {
        foreach ([200,400,403,500] as $status) {
            $request=Request::create('https://stock.example.test/connexion');$response=new Response('',$status);
            $response->headers->setCookie(Cookie::create('GESTION_STOCK_SESSION','opaque')->withHttpOnly(true)->withSameSite('lax'));
            $event=new ResponseEvent($this->createStub(HttpKernelInterface::class),$request,HttpKernelInterface::MAIN_REQUEST,$response);
            (new BrowserSecuritySubscriber(true))->headers($event);
            self::assertSame(BrowserSecuritySubscriber::POLICY,$response->headers->get('Content-Security-Policy'));
            self::assertStringNotContainsString("script-src 'self' 'unsafe-inline'",$response->headers->get('Content-Security-Policy'));
            self::assertSame('nosniff',$response->headers->get('X-Content-Type-Options'));self::assertSame('DENY',$response->headers->get('X-Frame-Options'));
            self::assertSame('same-origin',$response->headers->get('Referrer-Policy'));self::assertStringContainsString('camera=()',$response->headers->get('Permissions-Policy'));
            self::assertSame('max-age=31536000',$response->headers->get('Strict-Transport-Security'));self::assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
            self::assertTrue($response->headers->getCookies()[0]->isSecure());self::assertTrue($response->headers->getCookies()[0]->isHttpOnly());self::assertSame('lax',$response->headers->getCookies()[0]->getSameSite());
        }
        $response=new Response();$event=new ResponseEvent($this->createStub(HttpKernelInterface::class),Request::create('http://localhost'),HttpKernelInterface::MAIN_REQUEST,$response);
        (new BrowserSecuritySubscriber(false))->headers($event);self::assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    public function testCameraIsEnabledOnlyOnSuccessfulScannerResponses(): void
    {
        foreach ([200,403,500] as $status) {
            $request=Request::create('https://stock.example.test/consommations/nouvelle');$request->attributes->set('_camera_scan',true);
            $response=new Response('', $status);
            (new BrowserSecuritySubscriber(true))->headers(new ResponseEvent($this->createStub(HttpKernelInterface::class),$request,HttpKernelInterface::MAIN_REQUEST,$response));
            self::assertStringContainsString($status===200?'camera=(self)':'camera=()',$response->headers->get('Permissions-Policy'));
            self::assertStringContainsString('microphone=()',$response->headers->get('Permissions-Policy'));
        }
    }
}
