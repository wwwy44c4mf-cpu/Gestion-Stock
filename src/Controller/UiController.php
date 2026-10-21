<?php

namespace App\Controller;

use App\Ui\Navigation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class UiController extends AbstractController
{
    #[Route('/espace/{section}', name: 'ui_section', requirements: ['section' => 'articles|stocks|magasins|mouvements|consommations|inventaires|statistiques|alertes|administration'], methods: ['GET'])]
    public function section(string $section, \Symfony\Component\HttpFoundation\Request $request, \App\Ui\StoreContext $storeContext, \App\Security\AccessPolicy $policy): Response
    {
        if ($section === 'administration') {
            $this->denyAccessUnlessGranted('APP_ACCOUNTS_ADMIN');
            return $this->redirectToRoute('admin_users');
        }
        if ($section === 'consommations') { return $this->redirectToRoute('consumptions_list'); }
        if ($section === 'stocks') { return $this->redirectToRoute('stocks_list'); }
        if ($section === 'articles') { return $this->redirectToRoute('catalog_list'); }
        if ($section === 'magasins') { return $this->redirectToRoute('stores_list'); }
        $permission = match ($section) {
            'mouvements' => \App\Security\Permission::MOVEMENT_READ,
            'inventaires' => \App\Security\Permission::INVENTORY_READ,
            'alertes' => \App\Security\Permission::ALERT_READ,
            default => null,
        };
        if ($permission !== null) {
            $actor = $this->getUser();
            if (!$actor instanceof \App\Security\StockUser || !in_array($actor->role, $permission->roles(), true)) { throw $this->createAccessDeniedException(); }
            $context = $storeContext->read($request, $actor);
            $id = $context['selected'] ?? ($context['options'][0]['id'] ?? null);
            if ($id === null && $actor->role !== 'ADMIN') { throw $this->createAccessDeniedException(); }
            if ($id !== null) { $policy->assert($actor, $permission, \App\Security\AccessTarget::store($id)); }
        }
        if ($section === 'inventaires') { return $this->redirectToRoute('inventories_list'); }
        if ($section === 'mouvements') { return $this->redirectToRoute('movements_list'); }
        if ($section === 'alertes') { return $this->redirectToRoute('alerts_list'); }
        return $this->render('section.html.twig', ['section' => $section, 'title' => Navigation::SECTIONS[$section]]);
    }
}
