<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Api\ApiProblem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class SessionSubscriber implements EventSubscriberInterface
{
    public const IDLE_SECONDS = 1800;
    public const MAX_SECONDS = 43200;

    public static function getSubscribedEvents(): array
    {
        return ['kernel.request' => ['expire', 20], 'kernel.response' => ['headers', -128], 'kernel.exception' => ['formError', 60]];
    }

    public function expire(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasPreviousSession()) { return; }
        $session = $request->getSession();
        if ($session->has('_auth_started')) {
            $now = time();
            if ($now - $session->get('_auth_seen', 0) >= self::IDLE_SECONDS || $now - $session->get('_auth_started') >= self::MAX_SECONDS) {
                $session->invalidate();
                $request->attributes->set('_auth_clear_cookie', true);
            } else {
                $session->set('_auth_seen', $now);
            }
        }
    }

    public function headers(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/assets/') && !str_starts_with($request->getPathInfo(), '/health/')) {
            $event->getResponse()->headers->set('Cache-Control', 'no-store, private');
        }
        if ($request->attributes->getBoolean('_auth_clear_cookie')) {
            $name = $request->getSession()->getName();
            $event->getResponse()->headers->clearCookie($name, '/', null, $request->isSecure(), true, Cookie::SAMESITE_LAX);
        }
    }

    public function formError(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->attributes->get('_route') !== 'login' || !$request->isMethod('POST')) { return; }
        $error = $event->getThrowable();
        if ($error instanceof ApiProblem || $error instanceof TooManyRequestsHttpException) {
            $request->getSession()->getFlashBag()->add('error', $error instanceof TooManyRequestsHttpException ? 'Trop de tentatives. Réessayez dans 15 minutes.' : $error->getMessage());
            $response = new RedirectResponse('/connexion', 303);
            if ($error instanceof TooManyRequestsHttpException) { $response->headers->add($error->getHeaders()); }
            $event->setResponse($response);
        }
    }
}
