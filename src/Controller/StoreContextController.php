<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiProblem;
use App\Security\StockUser;
use App\Ui\StoreContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class StoreContextController extends AbstractController
{
    #[Route('/contexte/magasin', name: 'store_context', methods: ['POST'])]
    public function select(Request $request, StoreContext $context, CsrfTokenManagerInterface $csrf): Response
    {
        if (!$csrf->isTokenValid(new CsrfToken('magasin', $request->request->getString('_csrf_token')))) {
            throw new ApiProblem(403, 'invalid_csrf_token', 'Formulaire expiré. Rechargez la page avant de réessayer.');
        }
        $actor = $this->getUser();
        if (!$actor instanceof StockUser) { throw new ApiProblem(401, 'authentication_required', 'Connectez-vous.'); }
        $context->select($request, $actor, $request->request->getString('store_id'));
        return $this->redirectToRoute('home', status: 303);
    }
}
