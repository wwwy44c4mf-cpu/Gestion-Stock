<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Api\ApiProblem;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ApiSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CsrfTokenManagerInterface $csrf,
        #[Autowire(service: 'limiter.api_sensitive')] private readonly RateLimiterFactory $limiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'kernel.request' => [['identify', 256], ['protect', 8]],
            'kernel.exception' => ['problem', 64],
            'kernel.response' => ['headers', -64],
        ];
    }

    private function isApi(Request $request): bool
    {
        $path = $request->getPathInfo();
        return $path === '/api/v1' || str_starts_with($path, '/api/v1/');
    }

    public function identify(RequestEvent $event): void
    {
        if ($event->isMainRequest() && $this->isApi($event->getRequest())) {
            $event->getRequest()->attributes->set('_request_id', bin2hex(random_bytes(16)));
        }
    }

    public function protect(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->isApi($request)) {
            return;
        }
        $sensitive = $request->attributes->getBoolean('_api_sensitive') || in_array($request->getPathInfo(), [
            '/api/v1/auth/login', '/api/v1/auth/password-reset/request', '/api/v1/auth/password-reset/confirm',
            '/api/v1/notifications/information',
        ], true);
        if ($sensitive && !$request->isMethodSafe()) {
            $key = hash('sha256', $request->getClientIp().'|'.$request->attributes->get('_route'));
            $limit = $this->limiter->create($key)->consume();
            if (!$limit->isAccepted()) {
                throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
            }
        }
        if (!$request->isMethodSafe()) {
            $value = $request->headers->get('X-CSRF-Token', '');
            if (!$this->csrf->isTokenValid(new CsrfToken('api', $value))) {
                throw new ApiProblem(403, 'invalid_csrf_token', 'Jeton CSRF absent ou invalide.');
            }
        }
    }

    public function problem(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->isApi($request)) {
            return;
        }
        $error = $event->getThrowable();
        $status = $error instanceof ApiProblem ? $error->status : ($error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500);
        $titles = [400 => 'Requête invalide', 401 => 'Authentification requise', 403 => 'Accès refusé', 404 => 'Ressource introuvable', 405 => 'Méthode non autorisée', 409 => 'Conflit', 413 => 'Corps trop volumineux', 415 => 'Format non accepté', 422 => 'Données invalides', 429 => 'Trop de requêtes'];
        $title = $titles[$status] ?? 'Erreur du serveur';
        $code = $error instanceof ApiProblem ? $error->errorCode : 'http_'.$status;
        $detail = $error instanceof ApiProblem && $status < 500 ? $error->getMessage() : $title.'.';
        if ($status >= 500) {
            $this->logger->error('Erreur API interne', ['request_id' => $request->attributes->get('_request_id'), 'exception_class' => $error::class]);
        }
        $body = ['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail, 'code' => $code,
            'request_id' => $request->attributes->get('_request_id'), 'violations' => $error instanceof ApiProblem && $status < 500 ? $error->violations : []];
        $headers = $error instanceof HttpExceptionInterface ? $error->getHeaders() : [];
        $headers['Content-Type'] = 'application/problem+json';
        $event->setResponse(new JsonResponse($body, $status, $headers));
    }

    public function headers(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && $this->isApi($event->getRequest())) {
            $event->getResponse()->headers->set('X-Request-Id', $event->getRequest()->attributes->get('_request_id'));
            $event->getResponse()->headers->set('Cache-Control', 'no-store, private');
            $event->getResponse()->headers->set('X-Content-Type-Options', 'nosniff');
        }
    }
}
