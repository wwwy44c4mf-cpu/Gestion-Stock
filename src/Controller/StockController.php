<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\AccessDirectory;
use App\Access\CatalogDirectory;
use App\Access\StockDirectory;
use App\Api\ApiProblem;
use App\Api\QueryParameters;
use App\Security\StockUser;
use App\Security\Permission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StockController extends AbstractController
{
    public function __construct(private readonly StockDirectory $stocks, private readonly AccessDirectory $directory, private readonly CatalogDirectory $catalog) {}

    #[Route('/api/v1/stocks', name: 'api_stocks_list', methods: ['GET'])]
    #[Route('/api/v1/stores/{storeId}/stocks', name: 'api_store_stocks', methods: ['GET'])]
    public function listing(Request $request, QueryParameters $query, ?string $storeId = null): JsonResponse
    {
        $filters = $query->read($request, $storeId === null ? 'listStocks' : 'listStoreStocks');
        if ($storeId !== null) { $filters['store_id'] = $storeId; }
        return $this->json($this->stocks->listing($this->actor(), $filters));
    }

    #[Route('/api/v1/articles/{id}/stocks', name: 'api_article_stocks', methods: ['GET'])]
    public function article(string $id, Request $request, QueryParameters $query): JsonResponse
    {
        return $this->json($this->stocks->article($this->actor(), $id, $query->read($request, 'articleStocks')));
    }

    #[Route('/stocks', name: 'stocks_list', methods: ['GET'])]
    public function index(Request $request, QueryParameters $query, \App\Ui\StoreContext $context): Response
    {
        $filters = $this->filters($request, $query, 'listStocks');
        $actor = $this->actor();
        if (!$request->query->has('store_id') && ($selected = $context->read($request, $actor)['selected']) !== null) { $filters['store_id'] = $selected; }
        $result = $this->stocks->listing($actor, $filters);
        return $this->render('stocks/list.html.twig', [
            'result' => $result, 'filters' => $filters, 'labels' => $this->stocks->labels($result['data']),
            'can_register_consumption' => $this->directory->storeOptions($actor,Permission::CONSUMPTION_CREATE) !== [], 'can_register_entry' => in_array($actor->role,['ADMIN','MANAGER'],true) && $this->directory->storeOptions($actor,Permission::ENTRY_CREATE) !== [], 'stores' => $this->directory->storeOptions($actor), 'categories' => $this->catalog->categoryOptions($actor),
        ]);
    }

    #[Route('/stocks/{storeId}/{articleId}', name: 'stocks_detail', methods: ['GET'])]
    public function detail(string $storeId, string $articleId, Request $request, QueryParameters $query): Response
    {
        $filters = $query->read($request, 'stockHistory');
        $row = $this->stocks->detail($this->actor(), $storeId, $articleId)['data'];
        $labels = $this->stocks->labels([$row]);
        $canEnter = in_array($this->actor()->role,['ADMIN','MANAGER'],true) && $labels['articles'][$row['article_id']]['active'] && $labels['stores'][$row['store_id']]['active'];
        return $this->render('stocks/detail.html.twig', ['can_register_consumption' => $labels['articles'][$row['article_id']]['active'] && $labels['stores'][$row['store_id']]['active'], 'can_register_entry' => $canEnter, 'history' => $this->actor()->role === 'USER' ? null : $this->stocks->history($this->actor(), $storeId, $articleId, $filters), 'filters' => $filters, 'stock' => $row, 'labels' => $labels]);
    }

    #[Route('/api/v1/stores/{storeId}/stocks/{articleId}/movements', name: 'api_stock_history', methods: ['GET'])]
    public function history(string $storeId, string $articleId, Request $request, QueryParameters $query): JsonResponse
    {
        return $this->json($this->stocks->history($this->actor(), $storeId, $articleId, $query->read($request, 'stockHistory')));
    }

    private function filters(Request $request, QueryParameters $query, string $operation): array
    {
        $filtered = $request->duplicate();
        foreach (['q', 'store_id', 'article_id', 'category_id', 'status'] as $key) { if ($filtered->query->get($key) === '') { $filtered->query->remove($key); } }
        return $query->read($filtered, $operation);
    }

    private function actor(): StockUser
    {
        $actor = $this->getUser();
        if (!$actor instanceof StockUser) { throw new ApiProblem(401, 'authentication_required', 'Connectez-vous.'); }
        return $actor;
    }
}
