<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class LoginThrottle
{
    public function __construct(
        #[Autowire(service: 'limiter.login_account')] private readonly RateLimiterFactory $account,
        #[Autowire(service: 'limiter.login_ip')] private readonly RateLimiterFactory $ip,
        private readonly LoginAudit $audit,
    ) {}

    public function consume(Request $request, string $email): void
    {
        $request->attributes->set('_login_email', $email);
        $ip = $this->audit->key($request->getClientIp() ?? 'unknown');
        $key = $this->audit->key($ip.'|'.$email);
        $request->attributes->set('_login_limit_key', $key);
        $limits = [$this->ip->create($ip)->consume(), $this->account->create($key)->consume()];
        $retry = 0;
        foreach ($limits as $limit) {
            if (!$limit->isAccepted()) { $retry = max($retry, $limit->getRetryAfter()->getTimestamp() - time(), 1); }
        }
        if ($retry > 0) {
            $this->audit->record($request, 'auth.login_throttled');
            throw new TooManyRequestsHttpException($retry, 'Trop de tentatives. Réessayez plus tard.');
        }
    }

    public function success(Request $request): void
    {
        $this->account->create($request->attributes->get('_login_limit_key'))->reset();
    }
}
