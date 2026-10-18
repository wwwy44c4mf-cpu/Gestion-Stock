<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiProblem;
use App\Api\JsonBody;
use App\Security\LoginAudit;
use App\Security\StockUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AuthController extends AbstractController
{
    #[Route('/connexion', name: 'login', methods: ['GET', 'POST'])]
    public function login(): Response
    {
        if ($this->getUser()) { return $this->redirectToRoute('home'); }
        return $this->render('security/login.html.twig');
    }

    #[Route('/api/v1/auth/login', name: 'api_login', methods: ['POST'])]
    public function apiLogin(): never
    {
        throw new \LogicException('La connexion doit être traitée par le pare-feu.');
    }

    #[Route('/profil', name: 'profile', methods: ['GET', 'POST'])]
    public function profile(Request $request, Connection $db, CsrfTokenManagerInterface $csrf): Response
    {
        $user = $this->current();
        $error = null;
        $status = 200;
        if ($request->isMethod('POST')) {
            if (!$csrf->isTokenValid(new CsrfToken('profile', $request->request->getString('_csrf_token')))) {
                $status = 403;
                $error = 'Formulaire expiré. Rechargez la page puis réessayez.';
            } else {
                $zone = $request->request->getString('timezone');
                if (!in_array($zone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
                    $status = 422;
                    $error = 'Sélectionnez un fuseau horaire valide.';
                } else {
                    $this->updateTimezone($db, $user, $zone);
                    $this->addFlash('success', 'Votre fuseau horaire a été enregistré.');
                    return $this->redirectToRoute('profile', status: 303);
                }
            }
        }
        return $this->render('security/profile.html.twig', ['profile' => $user, 'timezones' => \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), 'error' => $error], new Response(status: $status));
    }

    #[Route('/api/v1/auth/me', name: 'api_me', methods: ['GET'])]
    public function me(): JsonResponse { return new JsonResponse($this->current()->resource()); }

    #[Route('/api/v1/auth/me', name: 'api_profile_update', methods: ['PATCH'])]
    public function patch(Request $request, JsonBody $json, Connection $db): JsonResponse
    {
        $body = $json->read($request, 'ProfilePatch');
        if (!in_array($body->timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new ApiProblem(422, 'invalid_timezone', 'Le fuseau horaire doit être un identifiant IANA valide.');
        }
        $user = $this->current();
        $this->updateTimezone($db, $user, $body->timezone);
        $resource = $user->resource();
        $resource['data']['timezone'] = $body->timezone;
        return new JsonResponse($resource);
    }

    private function updateTimezone(Connection $db, StockUser $user, string $timezone): void
    {
        $count = $db->executeStatement('UPDATE users SET timezone = ?, updated_at = clock_timestamp() WHERE id = ? AND active AND auth_version = ?', [$timezone, $user->id, $user->authVersion]);
        if ($count !== 1) { throw new ApiProblem(401, 'authentication_required', 'Reconnectez-vous.'); }
    }

    #[Route('/deconnexion', name: 'logout', methods: ['POST'])]
    #[Route('/api/v1/auth/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(Request $request, TokenStorageInterface $tokens, CsrfTokenManagerInterface $csrf, LoginAudit $audit): Response
    {
        if ($request->attributes->get('_route') === 'logout' && !$csrf->isTokenValid(new CsrfToken('logout', $request->request->getString('_csrf_token')))) {
            return new Response('Formulaire expiré. Revenez à votre profil pour vous déconnecter.', 403);
        }
        $audit->record($request, 'auth.logout', $this->current()->id);
        $tokens->setToken(null);
        $request->getSession()->invalidate();
        $request->attributes->set('_auth_clear_cookie', true);
        return $request->attributes->get('_route') === 'api_logout' ? new Response(status: 204) : $this->redirectToRoute('login', status: 303);
    }

    private function current(): StockUser
    {
        $user = $this->getUser();
        if (!$user instanceof StockUser) { throw new ApiProblem(401, 'authentication_required', 'Connectez-vous.'); }
        return $user;
    }
}
