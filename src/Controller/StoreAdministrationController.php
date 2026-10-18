<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\AccessDirectory;
use App\Administration\StoreAdministration;
use App\Api\ApiProblem;
use App\Api\IdempotencyKey;
use App\Api\JsonBody;
use App\Api\QueryParameters;
use App\Security\StockUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class StoreAdministrationController extends AbstractController
{
    public function __construct(private readonly StoreAdministration $stores, private readonly AccessDirectory $directory) {}

    #[Route('/api/v1/stores', name: 'api_stores_create', methods: ['POST'])]
    #[IsGranted('APP_STORES_ADMIN')]
    public function create(Request $request, JsonBody $json, IdempotencyKey $key): JsonResponse
    {
        $result = $this->stores->create($this->actor(), $json->read($request, 'StoreCreate'), $key->read($request));
        return $this->json($result, 201, ['Location' => '/api/v1/stores/'.$result['data']['id']]);
    }
    #[Route('/api/v1/stores/{id}', name: 'api_stores_update', methods: ['PATCH'])]
    #[IsGranted('APP_STORES_ADMIN')]
    public function update(string $id, Request $request, JsonBody $json): JsonResponse
    {
        return $this->json($this->stores->update($this->actor(), $id, $json->read($request, 'StorePatch')));
    }
    #[Route('/magasins', name: 'stores_list', methods: ['GET'])]
    public function index(Request $request, QueryParameters $query): Response
    {
        $filtered = $request->duplicate();
        if ($filtered->query->get('status') === '') { $filtered->query->remove('status'); }
        $filters = $query->read($filtered, 'listStores');
        return $this->render('stores/list.html.twig', ['result' => $this->directory->stores($this->actor(), $filters), 'filters' => $filters]);
    }
    #[Route('/administration/magasins/nouveau', name: 'stores_new', methods: ['GET', 'POST'])]
    #[Route('/administration/magasins/{id}/modifier', name: 'stores_edit', methods: ['GET', 'POST'])]
    #[IsGranted('APP_STORES_ADMIN')]
    public function form(Request $request, CsrfTokenManagerInterface $csrf, ?string $id = null): Response
    {
        $isNew = $id === null; $actor = $this->actor();
        $data = $isNew ? ['code' => '', 'name' => '', 'description' => '', 'address' => null, 'active' => true] : $this->stores->get($actor, $id)['data'];
        $error = null; $status = 200; $key = $isNew ? bin2hex(random_bytes(16)) : '';
        $tokenId = $isNew ? 'store_create' : 'store_edit_'.$id;
        if ($request->isMethod('POST')) {
            try {
                if (!$csrf->isTokenValid(new CsrfToken($tokenId, $request->request->getString('_csrf_token')))) { throw new ApiProblem(403, 'invalid_csrf_token', 'Formulaire expiré. Rechargez la page avant de réessayer.'); }
                if (strlen($request->getContent()) > 65536) { throw new ApiProblem(413, 'payload_too_large', 'Le formulaire est trop volumineux.'); }
                $key = $isNew ? $request->request->getString('_creation_key') : '';
                $body = (object) ['name' => $request->request->getString('name'), 'description' => $request->request->getString('description'), 'address' => $request->request->getString('address'), 'active' => $request->request->getBoolean('active')];
                if ($isNew) { $body->code = $request->request->getString('code'); }
                $data = array_replace($data, get_object_vars($body));
                $result = $isNew ? $this->stores->create($actor, $body, $key) : $this->stores->update($actor, $id, $body);
                $this->addFlash('success', $isNew ? 'Le magasin a été créé.' : 'Les modifications ont été enregistrées.');
                return $this->redirectToRoute('stores_edit', ['id' => $result['data']['id']], 303);
            } catch (ApiProblem $problem) { $error = $problem->getMessage(); $status = $problem->status; }
        }
        return $this->render('stores/form.html.twig', ['store' => $data, 'is_new' => $isNew, 'error' => $error, 'token_id' => $tokenId, 'creation_key' => $key], new Response(status: $status));
    }
    private function actor(): StockUser
    {
        $actor = $this->getUser();
        if (!$actor instanceof StockUser) { throw new ApiProblem(401, 'authentication_required', 'Connectez-vous.'); }
        return $actor;
    }
}
