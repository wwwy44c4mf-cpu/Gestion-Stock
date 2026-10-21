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
        $consumptions = str_starts_with($route, 'consumptions_');
        $statistics = str_starts_with($route, 'statistics_');
        $dashboard = $route === 'home';
        $alerts = str_starts_with($route, 'alerts_');
        $inventories = str_starts_with($route, 'inventories_');
        $movements = str_starts_with($route, 'movements_');
        $stocks = str_starts_with($route, 'stocks_');
        $stores = str_starts_with($route, 'stores_');
        $information = str_starts_with($route, 'information_');
        $accounts = str_starts_with($route, 'admin_users') || $information;
        if (!$event->isMainRequest() || (!$accounts && !$stores && !$catalog && !$stocks && !$consumptions && !$movements && !$inventories && !$alerts && !$statistics && !$dashboard && !$adminAlias)) { return; }
        $returnRoute = $consumptions ? (str_starts_with($route,'consumptions_return_') ? 'consumptions_return_list' : 'consumptions_list') : ($stocks ? 'stocks_list' : ($proposals ? 'proposals_list' : ($catalog ? (str_contains($route, 'categor') ? 'catalog_categories' : 'catalog_list') : ($stores ? 'stores_list' : 'admin_users'))));
        $returnLabel = $consumptions ? (str_starts_with($route,'consumptions_return_') ? 'Retour aux consommations à rendre' : 'Historique des consommations') : ($stocks ? 'Retour aux stocks' : ($proposals ? 'Retour aux propositions' : ($catalog ? (str_contains($route, 'categor') ? 'Retour aux catégories' : 'Retour aux articles') : ($stores ? 'Retour aux magasins' : 'Retour aux comptes'))));
        if ($alerts) { $returnRoute = 'alerts_list'; $returnLabel = 'Retour aux alertes'; }
        if ($inventories) { $returnRoute = 'inventories_list'; $returnLabel = 'Retour aux inventaires'; }
        if ($movements) { $returnRoute = 'movements_list'; $returnLabel = 'Retour aux mouvements'; }
        if ($statistics) { $returnRoute = 'statistics_consumptions'; $returnLabel = 'Retour aux statistiques'; }
        if ($dashboard) { $returnRoute = 'home'; $returnLabel = 'Retour au tableau de bord'; }
        if ($information) { $returnRoute = 'information_list'; $returnLabel = 'Retour aux messages'; }
        $error = $event->getThrowable();
        if ($error instanceof AccessDeniedHttpException) {
            $message = $proposals ? 'Vos droits ne permettent pas cette action sur les propositions.' : ($catalog ? 'Seul un administrateur peut modifier le catalogue.' : ($stores ? 'Seul un administrateur peut gérer les magasins.' : ($accounts || ($route === 'ui_section' && $event->getRequest()->attributes->get('section') === 'administration') ? 'Seul un administrateur peut gérer les comptes utilisateurs.' : 'Vos droits ne permettent pas de consulter cet espace.')));
            $event->setResponse(new Response($this->twig->render('admin/forbidden.html.twig', ['message' => $information ? 'Seul un administrateur peut gérer les messages d’information.' : $message]), 403));
            return;
        }
        if ($error instanceof ApiProblem && $error->status < 500) {
            $event->setResponse(new Response($this->twig->render('admin/error.html.twig', ['message' => $error->getMessage(), 'return_route' => $returnRoute, 'return_label' => $returnLabel]), $error->status));
        }
    }
}
