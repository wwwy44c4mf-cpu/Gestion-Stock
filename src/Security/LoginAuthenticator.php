<?php

declare(strict_types=1);

namespace App\Security;

use App\Api\ApiProblem;
use App\Api\JsonBody;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\PasswordUpgradeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class LoginAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly JsonBody $json, private readonly CsrfTokenManagerInterface $csrf,
        private readonly StockUserProvider $users, private readonly LoginThrottle $throttle, private readonly LoginAudit $audit, private readonly UrlGeneratorInterface $urls) {}

    public function supports(Request $request): ?bool
    {
        return $request->isMethod('POST') && in_array($request->attributes->get('_route'), ['login', 'api_login'], true);
    }

    public function authenticate(Request $request): Passport
    {
        if ($request->attributes->get('_route') === 'api_login') {
            $data = $this->json->read($request, 'Login');
            $email = strtolower(trim($data->email));
            $password = $data->password;
        } else {
            if (strlen($request->getContent()) > 65536) { throw new ApiProblem(413, 'payload_too_large', 'Le formulaire est trop volumineux.'); }
            if (!$this->csrf->isTokenValid(new CsrfToken('login', $request->request->getString('_csrf_token')))) {
                throw new ApiProblem(403, 'invalid_csrf_token', 'Formulaire expiré. Rechargez la page de connexion.');
            }
            $email = strtolower(trim($request->request->getString('email')));
            $password = $request->request->getString('password');
            if (strlen($email) > 254 || mb_strlen($password) > 128 || !preg_match('//u', $password) || $password === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new ApiProblem(422, 'validation_failed', 'Saisissez un courriel et un mot de passe valides.');
            }
        }
        $this->throttle->consume($request, $email);
        return new Passport(new UserBadge($email, function (string $identifier) use ($password): StockUser {
            try { return $this->users->loadUserByIdentifier($identifier); }
            catch (\Symfony\Component\Security\Core\Exception\UserNotFoundException $error) {
                // Match the production Argon2id work factor without retaining a real account hash.
                password_verify($password, '$argon2id$v=19$m=65536,t=4,p=1$bnJRTlhJVXd2TFhTMHgvMw$OKzr1G1PP2Oc3LHfxLntN3XGQQQvGIgAKvDlCUE8AlQ');
                throw $error;
            }
        }), new PasswordCredentials($password), [new PasswordUpgradeBadge($password, $this->users)]);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $request->getSession()->migrate(true);
        $this->throttle->success($request);
        $request->getSession()->set('_auth_started', time());
        $request->getSession()->set('_auth_seen', time());
        $this->csrf->removeToken('api');
        $this->csrf->removeToken('login');
        $user = $token->getUser();
        $this->audit->record($request, 'auth.login_succeeded', $user->id);
        return $request->attributes->get('_route') === 'api_login'
            ? new JsonResponse($user->resource()) : new RedirectResponse($this->urls->generate('home'), 303);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $this->audit->record($request, 'auth.login_failed');
        if ($request->attributes->get('_route') === 'api_login') {
            throw new ApiProblem(401, 'invalid_credentials', 'Courriel ou mot de passe incorrect.');
        }
        $request->getSession()->getFlashBag()->add('error', 'Courriel ou mot de passe incorrect.');
        return new RedirectResponse($this->urls->generate('login'), 303);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if (str_starts_with($request->getPathInfo(), '/api/v1')) {
            throw new ApiProblem(401, 'authentication_required', 'Connectez-vous pour accéder à cette ressource.');
        }
        return new RedirectResponse($this->urls->generate('login'));
    }
}
