<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\AccessDirectory;
use App\Access\CatalogDirectory;
use App\Api\ApiProblem;
use App\Api\IdempotencyKey;
use App\Api\JsonBody;
use App\Security\AccessIdentity;
use App\Security\AccessPolicy;
use App\Security\AccessTarget;
use App\Security\Permission;
use App\Security\StockUser;
use App\Stock\StockMovementEngine;
use App\Ui\StoreContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ConsumptionController extends AbstractController
{
    public function __construct(private readonly StockMovementEngine $engine, private readonly AccessIdentity $identity,
        private readonly AccessPolicy $policy, private readonly AccessDirectory $directory, private readonly CatalogDirectory $catalog) {}

    #[Route('/api/v1/movements/consumptions', name: 'api_consumptions_create', methods: ['POST'])]
    public function create(Request $request, JsonBody $json, IdempotencyKey $key): JsonResponse
    {
        $actor = $this->actor();
        $result = $this->engine->record($actor, 'consumption', $json->read($request, 'ConsumptionCreate'), $key->read($request));
        return $this->json($result, 201, ['Location'=>$this->generateUrl('consumptions_receipt',['id'=>$result['data']['id']])]);
    }

    #[Route('/consommations/nouvelle', name: 'consumptions_new', priority: 10, methods: ['GET', 'POST'], defaults: ['_camera_scan'=>true])]
    public function form(Request $request, CsrfTokenManagerInterface $csrf, StoreContext $context): Response
    {
        $actor = $this->actor();
        $stores = $this->directory->storeOptions($actor, Permission::CONSUMPTION_CREATE);
        $articles = $this->catalog->activeArticleOptions($actor);
        $canChoose = in_array($actor->role,['ADMIN','MANAGER'],true);
        $beneficiaries = $canChoose && $stores !== [] ? $this->directory->beneficiaryOptions($actor) : [];
        $data = ['store_id'=>'', 'article_id'=>'', 'quantity'=>'', 'comment'=>'', 'reference'=>'', 'consumer_id'=>$actor->id];
        $key = bin2hex(random_bytes(16)); $error = null; $status = 200;
        if ($request->isMethod('GET')) {
            $selected = $actor->favoriteStoreId;
            if ($selected===null || !in_array($selected,array_column($stores,'id'),true)) { $selected = $context->read($request, $actor)['selected']; }
            if ($selected !== null && in_array($selected, array_column($stores,'id'), true)) { $data['store_id'] = $selected; }
            foreach ($request->query->all() as $name => $value) {
                if (!in_array($name, ['store_id','article_id'], true) || !is_string($value) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value)) {
                    throw new ApiProblem(422, 'invalid_query', 'La présélection du formulaire est invalide.');
                }
                $data[$name] = strtolower($value);
            }
            if ($data['store_id'] !== '') { $this->policy->assert($actor, Permission::CONSUMPTION_CREATE, AccessTarget::store($data['store_id'])); }
            if ($data['article_id'] !== '' && !$this->catalog->get($actor,'article',$data['article_id'])['data']['active']) {
                throw new ApiProblem(409, 'inactive_article', 'Cet article est désactivé. Choisissez un article actif pour une consommation.');
            }
        } else {
            try {
                if (strlen($request->getContent()) > 65536) { throw new ApiProblem(413, 'payload_too_large', 'Le formulaire est trop volumineux.'); }
                $raw = $request->request->all();
                $token = $raw['_csrf_token'] ?? '';
                if (!is_string($token) || !$csrf->isTokenValid(new CsrfToken('stock_consumption', $token))) { throw new ApiProblem(403, 'invalid_csrf_token', 'Formulaire expiré. Rechargez la page avant de réessayer.'); }
                if ($request->query->count() > 0 || $request->files->count() > 0) { throw new ApiProblem(422, 'invalid_form', 'Aucun paramètre supplémentaire ni fichier n’est accepté.'); }
                foreach ($raw as $name => $value) {
                    if (!in_array($name, ['_csrf_token','_movement_key',...array_keys($data)], true) || !is_string($value)) { throw new ApiProblem(422, 'invalid_form', 'Un champ du formulaire est invalide.'); }
                }
                $key = $raw['_movement_key'] ?? '';
                foreach ($data as $name => $_) { $data[$name] = $raw[$name] ?? ($name === 'consumer_id' ? $actor->id : ''); }
                if (!preg_match('/^[1-9][0-9]{0,15}$/D', $data['quantity'])) { throw new ApiProblem(422, 'invalid_quantity', 'Saisissez une quantité entière strictement positive, sans décimale ni exposant.'); }
                $body = (object) ['article_id'=>$data['article_id'],'store_id'=>$data['store_id'],'quantity'=>(int)$data['quantity'],'consumer_id'=>$data['consumer_id']];
                if ($data['comment'] !== '') { $body->comment = $data['comment']; }
                if ($data['reference'] !== '') { $body->reference = $data['reference']; }
                $result = $this->engine->record($actor, 'consumption', $body, $key)['data'];
                $this->addFlash('success', 'La consommation de '.$result['quantity'].' unité'.($result['quantity'] > 1 ? 's' : '').' a été enregistrée.');
                return $this->redirectToRoute('consumptions_receipt', ['id'=>$result['id']], 303);
            } catch (ApiProblem $problem) { $error = $problem->getMessage(); $status = $problem->status; }
        }
        return $this->render('consumptions/form.html.twig', ['can_choose_beneficiary'=>$canChoose,'beneficiaries'=>$beneficiaries,'item'=>$data,'movement_key'=>$key,'error'=>$error,'stores'=>$stores,'articles'=>$articles], new Response(status:$status));
    }

    #[Route('/consommations/{id}', name: 'consumptions_receipt', methods: ['GET'])]
    public function receipt(string $id, \App\Access\ConsumptionDirectory $receipts): Response
    {
        $row = $receipts->get($this->actor(), $id);
        return $this->render('consumptions/receipt.html.twig', ['consumption'=>$row,'consumption_target'=>AccessTarget::consumption($row['id']),'stock_target'=>AccessTarget::store($row['store_id'])]);
    }

    private function actor(): StockUser
    {
        $actor = $this->getUser();
        if (!$actor instanceof StockUser) { throw new ApiProblem(401, 'authentication_required', 'Connectez-vous.'); }
        $this->identity->requireRole($actor, Permission::CONSUMPTION_CREATE);
        return $actor;
    }
}
