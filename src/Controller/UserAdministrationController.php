<?php

declare(strict_types=1);

namespace App\Controller;

use App\Administration\UserAdministration;
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

#[IsGranted('ROLE_ADMIN')]
final class UserAdministrationController extends AbstractController
{
    public function __construct(private readonly UserAdministration $users) {}

    #[Route('/api/v1/users', name: 'api_users_list', methods: ['GET'])]
    public function apiList(Request $request, QueryParameters $query): JsonResponse
    {
        return $this->json($this->users->listing($this->actor(), $query->read($request, 'listUsers')));
    }

    #[Route('/api/v1/users', name: 'api_users_create', methods: ['POST'])]
    public function apiCreate(Request $request, JsonBody $json, IdempotencyKey $key): JsonResponse
    {
        $result = $this->users->create($this->actor(), $json->read($request, 'UserCreate'), $key->read($request));
        return $this->json($result, 201, ['Location' => '/api/v1/users/'.$result['data']['id']]);
    }

    #[Route('/api/v1/users/{id}', name: 'api_users_get', methods: ['GET'])]
    public function apiGet(string $id): JsonResponse { return $this->json($this->users->get($this->actor(), $id)); }

    #[Route('/api/v1/users/{id}', name: 'api_users_update', methods: ['PATCH'])]
    public function apiUpdate(string $id, Request $request, JsonBody $json): JsonResponse
    {
        return $this->json($this->users->update($this->actor(), $id, $json->read($request, 'UserPatch')));
    }

    #[Route('/administration/comptes', name: 'admin_users', methods: ['GET'])]
    public function index(Request $request, QueryParameters $query): Response
    {
        $filteredRequest = $request->duplicate();
        foreach (['role', 'status'] as $name) {
            if ($filteredRequest->query->get($name) === '') { $filteredRequest->query->remove($name); }
        }
        try { $filters = $query->read($filteredRequest, 'listUsers'); }
        catch (ApiProblem $error) { return $this->render('admin/error.html.twig', ['message' => $error->getMessage()], new Response(status: $error->status)); }
        return $this->render('admin/users.html.twig', ['result' => $this->users->listing($this->actor(), $filters), 'filters' => $filters]);
    }

    #[Route('/administration/comptes/nouveau', name: 'admin_users_new', methods: ['GET', 'POST'])]
    #[Route('/administration/comptes/{id}/modifier', name: 'admin_users_edit', methods: ['GET', 'POST'])]
    public function form(Request $request, CsrfTokenManagerInterface $csrf, ?string $id = null): Response
    {
        $actor = $this->actor();
        $isNew = $id === null;
        $data = $isNew ? ['name' => '', 'email' => '', 'role' => 'USER', 'store_ids' => [], 'active' => true] : $this->users->get($actor, $id)['data'];
        if (!$isNew && $data['email'] === null) { return $this->render('admin/error.html.twig', ['message' => 'Un compte anonymisé ne peut pas être modifié.'], new Response(status: 409)); }
        $error = null; $status = 200;
        $key = $isNew ? bin2hex(random_bytes(16)) : '';
        $tokenId = $isNew ? 'admin_user_create' : 'admin_user_edit_'.$id;
        if ($request->isMethod('POST')) {
            try {
                if (!$csrf->isTokenValid(new CsrfToken($tokenId, $request->request->getString('_csrf_token')))) {
                    throw new ApiProblem(403, 'invalid_csrf_token', 'Formulaire expiré. Rechargez la page avant de réessayer.');
                }
                if (strlen($request->getContent()) > 65536) { throw new ApiProblem(413, 'payload_too_large', 'Le formulaire est trop volumineux.'); }
                $key = $isNew ? $request->request->getString('_creation_key') : '';
                $data = ['email' => $request->request->getString('email'), 'name' => $request->request->getString('name'), 'role' => $request->request->getString('role'), 'store_ids' => array_values($request->request->all('store_ids'))];
                if ($isNew) { $data['password'] = $request->request->getString('password'); }
                else { $data['active'] = $request->request->getBoolean('active'); }
                $body = (object) $data;
                $result = $isNew ? $this->users->create($actor, $body, $key) : $this->users->update($actor, $id, $body);
                $this->addFlash('success', $isNew ? 'Le compte a été créé.' : 'Le compte a été modifié.');
                if ($id === $actor->id) { return $this->redirectToRoute('login', status: 303); }
                return $this->redirectToRoute('admin_users_edit', ['id' => $result['data']['id']], 303);
            } catch (ApiProblem $problem) { $error = $problem->getMessage(); $status = $problem->status; }
            finally { unset($data['password']); }
        }
        return $this->render('admin/user_form.html.twig', ['account' => $data, 'is_new' => $isNew, 'editing_self' => $id === $actor->id, 'error' => $error,
            'stores' => $this->users->storeOptions($actor), 'token_id' => $tokenId, 'creation_key' => $key], new Response(status: $status));
    }

    private function actor(): StockUser
    {
        $user = $this->getUser();
        if (!$user instanceof StockUser) { throw new ApiProblem(401, 'authentication_required', 'Connectez-vous.'); }
        return $user;
    }
}
