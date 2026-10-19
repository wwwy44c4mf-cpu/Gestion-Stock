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

final class StockTransferController extends AbstractController
{
    public function __construct(private readonly StockMovementEngine $engine, private readonly AccessIdentity $identity,
        private readonly AccessPolicy $policy, private readonly AccessDirectory $directory, private readonly CatalogDirectory $catalog) {}

    #[Route('/api/v1/movements/transfers', name: 'api_stock_transfers_create', methods: ['POST'])]
    public function create(Request $request, JsonBody $json, IdempotencyKey $key): JsonResponse
    {
        $actor = $this->actor();
        $result = $this->engine->record($actor, 'transfer', $json->read($request, 'TransferCreate'), $key->read($request));
        return $this->json($result, 201, ['Location'=>$this->generateUrl('stocks_transfer_receipt',['id'=>$result['data']['id']])]);
    }

    #[Route('/stocks/transfert', name: 'stocks_transfer_new', priority: 10, methods: ['GET', 'POST'])]
    public function form(Request $request, CsrfTokenManagerInterface $csrf, StoreContext $context): Response
    {
        $actor = $this->actor();
        $stores = $this->directory->storeOptions($actor, Permission::TRANSFER_CREATE);
        $articles = $this->catalog->activeArticleOptions($actor);
        $data = ['source_store_id'=>'', 'destination_store_id'=>'', 'article_id'=>'', 'quantity'=>'', 'reason'=>''];
        $key = bin2hex(random_bytes(16)); $error = null; $status = 200;
        if ($request->isMethod('GET')) {
            $selected = $context->read($request, $actor)['selected'];
            if ($selected !== null && in_array($selected, array_column($stores,'id'), true)) { $data['source_store_id'] = $selected; }
            foreach ($request->query->all() as $name => $value) {
                if (!in_array($name, ['source_store_id','destination_store_id','article_id'], true) || !is_string($value) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value)) {
                    throw new ApiProblem(422, 'invalid_query', 'La présélection du formulaire est invalide.');
                }
                $data[$name] = strtolower($value);
            }
            foreach (['source_store_id','destination_store_id'] as $field) {
                if ($data[$field] !== '' && !in_array($data[$field],array_column($stores,'id'),true)) { throw new ApiProblem(404,'resource_not_found','Magasin introuvable.'); }
            }
            if ($data['article_id'] !== '' && !$this->catalog->get($actor,'article',$data['article_id'])['data']['active']) {
                throw new ApiProblem(409, 'inactive_article', 'Cet article est désactivé. Choisissez un article actif pour un transfert.');
            }
        } else {
            try {
                if (strlen($request->getContent()) > 65536) { throw new ApiProblem(413, 'payload_too_large', 'Le formulaire est trop volumineux.'); }
                $raw = $request->request->all();
                $token = $raw['_csrf_token'] ?? '';
                if (!is_string($token) || !$csrf->isTokenValid(new CsrfToken('stock_transfer', $token))) { throw new ApiProblem(403, 'invalid_csrf_token', 'Formulaire expiré. Rechargez la page avant de réessayer.'); }
                if ($request->query->count() > 0 || $request->files->count() > 0) { throw new ApiProblem(422, 'invalid_form', 'Aucun paramètre supplémentaire ni fichier n’est accepté.'); }
                foreach ($raw as $name => $value) {
                    if (!in_array($name, ['_csrf_token','_movement_key',...array_keys($data)], true) || !is_string($value)) { throw new ApiProblem(422, 'invalid_form', 'Un champ du formulaire est invalide.'); }
                }
                $key = $raw['_movement_key'] ?? '';
                foreach ($data as $name => $_) { $data[$name] = $raw[$name] ?? ''; }
                if (!preg_match('/^[1-9][0-9]{0,15}$/D', $data['quantity'])) { throw new ApiProblem(422, 'invalid_quantity', 'Saisissez une quantité entière strictement positive, sans décimale ni exposant.'); }
                $body = (object) ['article_id'=>$data['article_id'],'source_store_id'=>$data['source_store_id'],'destination_store_id'=>$data['destination_store_id'],'quantity'=>(int)$data['quantity']];
                if ($data['reason'] !== '') { $body->reason = $data['reason']; }
                $result = $this->engine->record($actor, 'transfer', $body, $key)['data'];
                $this->addFlash('success', 'Le transfert de '.$result['quantity'].' unité'.($result['quantity'] > 1 ? 's' : '').' a été enregistré.');
                return $this->redirectToRoute('stocks_transfer_receipt', ['id'=>$result['id']], 303);
            } catch (ApiProblem $problem) { $error = $problem->getMessage(); $status = $problem->status; }
        }
        return $this->render('stocks/transfer.html.twig', ['item'=>$data,'movement_key'=>$key,'error'=>$error,'stores'=>$stores,'articles'=>$articles], new Response(status:$status));
    }

    #[Route('/stocks/transferts/{id}', name: 'stocks_transfer_receipt', priority: 10, methods: ['GET'])]
    public function receipt(string $id, \App\Access\TransferDirectory $transfers): Response
    {
        $actor = $this->actor(); $row = $transfers->get($actor,$id); $canCorrect = true;
        try { $this->policy->assert($actor,Permission::MOVEMENT_REVERSE,AccessTarget::movement($id)); }
        catch (ApiProblem) { $canCorrect = false; }
        return $this->render('stocks/transfer_receipt.html.twig',['transfer'=>$row,'can_correct'=>$canCorrect]);
    }

    private function actor(): StockUser
    {
        $actor = $this->getUser();
        if (!$actor instanceof StockUser) { throw new ApiProblem(401, 'authentication_required', 'Connectez-vous.'); }
        $this->identity->requireRole($actor, Permission::TRANSFER_CREATE);
        return $actor;
    }
}
