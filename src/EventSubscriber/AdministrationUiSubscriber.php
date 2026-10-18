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
        $adminAlias = in_array($route, ['ui_section', 'store_context'], true);
        $proposals = str_starts_with($route, 'proposals_');
        $catalog = str_starts_with($route, 'catalog_') || $proposals;
        $stocks = str_starts_with($route, 'stocks_');
        $stores = str_starts_with($route, 'stores_');
        $accounts = str_starts_with($route, 'admin_users');
        if (!$event->isMainRequest() || (!$accounts && !$stores && !$catalog && !$stocks && !$adminAlias)) { return; }
        $returnRoute = $stocks ? 'stocks_list' : ($proposals ? 'proposals_list' : ($catalog ? (str_contains($route, 'categor') ? 'catalog_categories' : 'catalog_list') : ($stores ? 'stores_list' : 'admin_users')));
        $returnLabel = $stocks ? 'Retour aux stocks' : ($proposals ? 'Retour aux propositions' : ($catalog ? (str_contains($route, 'categor') ? 'Retour aux catégories' : 'Retour aux articles') : ($stores ? 'Retour aux magasins' : 'Retour aux comptes')));
        $error = $event->getThrowable();
        if ($error instanceof AccessDeniedHttpException) {
            $message = $proposals ? 'Vos droits ne permettent pas cette action sur les propositions.' : ($catalog ? 'Seul un administrateur peut modifier le catalogue.' : ($stores ? 'Seul un administrateur peut gérer les magasins.' : ($accounts || ($route === 'ui_section' && $event->getRequest()->attributes->get('section') === 'administration') ? 'Seul un administrateur peut gérer les comptes utilisateurs.' : 'Vos droits ne permettent pas de consulter cet espace.')));
            $event->setResponse(new Response($this->twig->render('admin/forbidden.html.twig', ['message' => $message]), 403));
            return;
        }
        if ($error instanceof ApiProblem && $error->status < 500) {
            $event->setResponse(new Response($this->twig->render('admin/error.html.twig', ['message' => $error->getMessage(), 'return_route' => $returnRoute, 'return_label' => $returnLabel]), $error->status));
        }
    }
}
