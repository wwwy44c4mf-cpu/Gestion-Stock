<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

final class BrowserSecuritySubscriber implements EventSubscriberInterface
{
    public const POLICY = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";

    public function __construct(#[Autowire('%env(bool:APP_REQUIRE_HTTPS)%')] private readonly bool $requireHttps) {}

    public static function getSubscribedEvents(): array
    {
        return ['kernel.request' => ['https', 250], 'kernel.response' => ['headers', -1024]];
    }

    public function https(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->requireHttps || $event->getRequest()->isSecure()) { return; }
        // Internal health probes carry no credentials and do not create sessions.
        if (in_array($event->getRequest()->getPathInfo(), ['/health/live', '/health/ready'], true)) { return; }
        // Refuse instead of forwarding a credential-bearing request over HTTP.
        $event->setResponse(new Response('Connexion HTTPS obligatoire.', 400, ['Content-Type' => 'text/plain; charset=UTF-8']));
    }

    public function headers(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }
        $response = $event->getResponse();
        $camera = $event->getRequest()->attributes->getBoolean('_camera_scan') && $response->isSuccessful();
        $response->headers->set('Content-Security-Policy', self::POLICY.($camera ? "; media-src 'self' blob:" : ''));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', str_starts_with($event->getRequest()->getPathInfo(), '/mot-de-passe/') ? 'no-referrer' : 'same-origin');
        $response->headers->set('Permissions-Policy', ($camera ? 'camera=(self)' : 'camera=()').', microphone=(), geolocation=()');
        if ($this->requireHttps) {
            $response->headers->set('Cache-Control', 'no-store, private');
            foreach ($response->headers->getCookies() as $cookie) {
                $response->headers->setCookie($cookie->withSecure(true));
            }
            if ($event->getRequest()->isSecure()) {
                $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
            }
        }
    }
}
