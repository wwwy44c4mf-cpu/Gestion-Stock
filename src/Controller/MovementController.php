<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\AccessDirectory;
use App\Access\CatalogDirectory;
use App\Access\CorrectionDirectory;
use App\Api\ApiProblem;
use App\Api\QueryParameters;
use App\History\MovementDirectory;
use App\Security\Permission;
use App\Security\StockUser;
use App\Ui\StoreContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MovementController extends AbstractController
{
    public function __construct(private readonly MovementDirectory $movements, private readonly AccessDirectory $directory, private readonly CatalogDirectory $catalog, private readonly CorrectionDirectory $corrections) {}

    #[Route('/api/v1/movements', name: 'api_movements_list', methods: ['GET'])]
    public function listing(Request $request, QueryParameters $query): JsonResponse
    {
        return $this->json($this->movements->listing($this->actor(), $query->read($request, 'listMovements')));
    }

    #[Route('/api/v1/movements/{id}', name: 'api_movements_detail', requirements: ['id' => '[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}'], methods: ['GET'])]
    public function resource(string $id, Request $request, QueryParameters $query): JsonResponse
    {
        $query->read($request, 'getMovement');
        return $this->json($this->movements->get($this->actor(), $id));
    }

    #[Route('/mouvements', name: 'movements_list', methods: ['GET'])]
    public function index(Request $request, QueryParameters $query, StoreContext $context): Response
    {
        $filtered = $request->duplicate();
        foreach (['date_from','date_to','article_id','category_id','store_id','type','author_id','consumer_id'] as $key) { if ($filtered->query->get($key) === '') { $filtered->query->remove($key); } }
        if (!$filtered->query->has('order')) { $filtered->query->set('order', 'desc'); }
        $filters = $query->read($filtered, 'listMovements'); $actor = $this->actor();
        if (!$request->query->has('store_id') && ($selected = $context->read($request, $actor)['selected']) !== null) { $filters['store_id'] = $selected; }
        $result = $this->movements->listing($actor, $filters);
        return $this->render('movements/list.html.twig', ['result' => $result, 'filters' => $filters,
            'categories' => $this->catalog->categoryOptions($actor), 'stores' => $this->directory->storeOptions($actor, Permission::MOVEMENT_READ), 'articles' => $this->catalog->articleOptions($actor), 'people' => $this->movements->people($actor)]);
    }

    #[Route('/mouvements/{id}', name: 'movements_detail', methods: ['GET'])]
    public function detail(string $id, Request $request, QueryParameters $query): Response
    {
        $query->read($request, 'getMovement'); $actor = $this->actor();
        $movement = $this->movements->get($actor, $id)['data'];
        return $this->render('movements/detail.html.twig', ['movement' => $movement,
            'correction' => $movement['restricted'] ? null : ($this->corrections->links($actor, [$movement])[$id] ?? null)]);
    }

    private function actor(): StockUser
    {
        $actor = $this->getUser();
        if (!$actor instanceof StockUser) { throw new ApiProblem(401, 'authentication_required', 'Connectez-vous.'); }
        return $actor;
    }
}
