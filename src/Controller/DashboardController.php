<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\CatalogDirectory;
use App\Api\{ApiProblem, QueryParameters};
use App\Security\StockUser;
use App\Statistics\Dashboard;
use App\Ui\StoreContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(private readonly Dashboard $dashboard) {}

    #[Route('/api/v1/dashboard', name: 'api_dashboard', methods: ['GET'])]
    public function api(Request $request, QueryParameters $query): JsonResponse
    {
        return $this->json(['data' => $this->dashboard->read($this->actor(), $query->read($request, 'dashboard'))]);
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(Request $request, QueryParameters $query, CatalogDirectory $catalog, StoreContext $context): Response
    {
        $actor = $this->actor(); $copy = $request->duplicate();
        foreach ($request->query->all() as $key => $value) { if (in_array($key, ['date_from','date_to','store_id','category_id'], true) && $value === '') { $copy->query->remove($key); } }
        if (!$request->query->has('date_from') && !$request->query->has('date_to')) {
            $today = new \DateTimeImmutable('now', new \DateTimeZone($actor->timezone));
            $copy->query->set('date_from', $today->format('Y-m-01')); $copy->query->set('date_to', $today->format('Y-m-d'));
        }
        $filters = $query->read($copy, 'dashboard');
        foreach (['store_id','category_id'] as $key) { if (isset($filters[$key])) { $filters[$key] = strtolower($filters[$key]); } }
        if (!$request->query->has('store_id') && ($selected = $context->read($request, $actor)['selected']) !== null) { $filters['store_id'] = $selected; }
        return $this->render('home.html.twig', ['data' => $this->dashboard->read($actor, $filters, true), 'filters' => $filters,
            'stores' => $this->dashboard->storeOptions($actor), 'categories' => $catalog->categoryOptions($actor)]);
    }

    private function actor(): StockUser
    {
        $actor = $this->getUser();
        if (!$actor instanceof StockUser) { throw new ApiProblem(401, 'authentication_required', 'Connectez-vous.'); }
        return $actor;
    }
}
