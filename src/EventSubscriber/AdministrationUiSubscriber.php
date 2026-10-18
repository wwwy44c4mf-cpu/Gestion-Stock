<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Api\ApiProblem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Twig\Environment;

final class AdministrationUiSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Environment $twig) {}
    public static function getSubscribedEvents(): array { return ['kernel.exception' => ['problem', 0]]; }

    public function problem(ExceptionEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route', '');
        $adminAlias = $route === 'ui_section' && $event->getRequest()->attributes->get('section') === 'administration';
        if (!$event->isMainRequest() || (!str_starts_with($route, 'admin_users') && !$adminAlias)) { return; }
        $error = $event->getThrowable();
        if ($error instanceof AccessDeniedHttpException) {
            $event->setResponse(new Response($this->twig->render('admin/forbidden.html.twig'), 403));
            return;
        }
        if ($error instanceof ApiProblem && $error->status < 500) {
            $event->setResponse(new Response($this->twig->render('admin/error.html.twig', ['message' => $error->getMessage()]), $error->status));
        }
    }
}
