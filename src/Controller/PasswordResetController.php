<?php

declare(strict_types=1);
namespace App\Controller;

use App\Api\ApiProblem;
use App\Api\JsonBody;
use App\Api\OpenApiContract;
use App\Security\PasswordReset;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class PasswordResetController extends AbstractController
{
    public function __construct(private readonly PasswordReset $reset, private readonly CsrfTokenManagerInterface $csrf,
        private readonly OpenApiContract $contract,
        #[Autowire(service: 'limiter.api_sensitive')] private readonly RateLimiterFactory $limiter) {}

    #[Route('/api/v1/auth/password-reset/request', name: 'api_password_reset_request', methods: ['POST'], defaults: ['_api_sensitive'=>true])]
    public function requestApi(Request $request, JsonBody $json): JsonResponse
    {
        $body = $json->read($request, 'ResetRequest');
        $this->reset->request($body->email);
        return new JsonResponse(['message'=>PasswordReset::ACCEPTED], 202);
    }

    #[Route('/api/v1/auth/password-reset/confirm', name: 'api_password_reset_confirm', methods: ['POST'], defaults: ['_api_sensitive'=>true])]
    public function confirmApi(Request $request, JsonBody $json): Response
    {
        $body = $json->read($request, 'ResetConfirm');
        $this->reset->confirm($body->token, $body->password);
        return new Response(status: 204);
    }

    #[Route('/mot-de-passe/oublie', name: 'password_reset_request', methods: ['GET','POST'])]
    public function requestForm(Request $request): Response
    {
        $error = null; $accepted = false; $status = 200;
        if ($request->isMethod('POST')) {
            try {
                $this->protect($request, 'request');
                $email = $request->request->getString('email');
                if (!$this->contract->valid((object) ['email'=>$email], ['$ref'=>'#/components/schemas/ResetRequest'])) {
                    throw new ApiProblem(422, 'invalid_email', 'Saisissez une adresse courriel valide.');
                }
                $this->reset->request($email); $accepted = true;
            } catch (ApiProblem $exception) { $status=$exception->status; $error=$exception->getMessage(); }
        }
        return $this->render('security/reset_request.html.twig', ['error'=>$error, 'accepted'=>$accepted, 'message'=>PasswordReset::ACCEPTED], new Response(status: $status));
    }

    #[Route('/mot-de-passe/reinitialiser', name: 'password_reset_confirm', methods: ['GET','POST'])]
    public function confirmForm(Request $request): Response
    {
        $session=$request->getSession();
        $previous=$session->get('_password_reset_forms', []);
        $forms=array_filter($previous, static fn (array $form): bool => $form['created_at'] > time()-1800);
        $nonce=$request->isMethod('POST') ? $request->request->getString('form_nonce') : $request->query->getString('form');
        if (!preg_match('/^[a-f0-9]{32}$/D', $nonce) || !isset($forms[$nonce])) { $nonce=''; }
        // Each tab keeps its own selector; the URL never carries the usable token again.
        if ($request->isMethod('GET') && $request->query->has('token')) {
            $token=$request->query->getString('token');
            $nonce=bin2hex(random_bytes(16));
            $forms[$nonce]=['token'=>preg_match('/^[a-f0-9-]{36}\.[a-f0-9]{64}$/D', $token) ? $token : '', 'created_at'=>time(),'invitation'=>$this->reset->isInvitation($token)];
            $forms=array_slice($forms, -10, null, true);
            foreach (array_diff_key($previous, $forms) as $oldNonce=>$unused) { $this->csrf->removeToken('password_reset_confirm_'.$oldNonce); }
            $session->set('_password_reset_forms', $forms);
            $session->remove('_password_reset_token');
            return $this->redirectToRoute('password_reset_confirm', ['form'=>$nonce], status: 303)->setPrivate();
        }
        foreach (array_diff_key($previous, $forms) as $oldNonce=>$unused) { $this->csrf->removeToken('password_reset_confirm_'.$oldNonce); }
        $session->set('_password_reset_forms', $forms);
        $error=null; $status=200;
        if ($request->isMethod('POST')) {
            try {
                $this->protect($request, 'confirm', $nonce);
                $password=$request->request->getString('password');
                if (!hash_equals($password, $request->request->getString('password_confirmation'))) {
                    throw new ApiProblem(422, 'password_mismatch', 'Les deux mots de passe doivent être identiques.');
                }
                $this->reset->confirm($forms[$nonce]['token'] ?? '', $password);
                unset($forms[$nonce]);
                $session->set('_password_reset_forms', $forms);
                $this->csrf->removeToken('password_reset_confirm_'.$nonce);
                $this->addFlash('success', 'Votre mot de passe est enregistré. Vous pouvez maintenant vous connecter.');
                return $this->redirectToRoute('login', status: 303);
            } catch (ApiProblem $exception) { $status=$exception->status; $error=$exception->getMessage(); }
        }
        return $this->render('security/reset_confirm.html.twig', ['error'=>$error, 'form_nonce'=>$nonce,'invitation'=>$forms[$nonce]['invitation']??false], new Response(status: $status));
    }

    private function protect(Request $request, string $operation, string $nonce=''): void
    {
        $csrfId='password_reset_'.$operation.($operation==='confirm' ? '_'.$nonce : '');
        if (!$this->csrf->isTokenValid(new CsrfToken($csrfId, $request->request->getString('_csrf_token')))) {
            throw new ApiProblem(403, 'invalid_csrf_token', 'Formulaire expiré. Rechargez la page.');
        }
        $key=hash('sha256', $request->getClientIp().'|api_password_reset_'.$operation);
        if (!$this->limiter->create($key)->consume()->isAccepted()) {
            throw new ApiProblem(429, 'reset_throttled', 'Trop de tentatives. Réessayez dans une minute.');
        }
    }
}
