<?php

namespace App\Controller;

use App\Ui\Navigation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class UiController extends AbstractController
{
    #[Route('/espace/{section}', name: 'ui_section', requirements: ['section' => 'articles|magasins|mouvements|consommations|inventaires|statistiques|alertes|administration'], methods: ['GET'])]
    public function section(string $section): Response
    {
        if ($section === 'administration') {
            $this->denyAccessUnlessGranted('ROLE_ADMIN');
            return $this->redirectToRoute('admin_users');
        }
        // No business data is exposed here. Each future module enforces its own access rules.
        return $this->render('section.html.twig', ['section' => $section, 'title' => Navigation::SECTIONS[$section]]);
    }
}
