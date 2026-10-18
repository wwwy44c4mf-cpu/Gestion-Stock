<?php

declare(strict_types=1);

namespace App\Security;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

final class LoginAudit
{
    public function __construct(private readonly Connection $db, #[Autowire('%kernel.secret%')] private readonly string $secret) {}

    public function key(string $value): string { return hash_hmac('sha256', $value, $this->secret); }

    public function record(Request $request, string $action, ?string $actor = null): void
    {
        // Never store supplied credentials, raw email, IP, cookies or tokens.
        $context = ['ip_key' => $this->key($request->getClientIp() ?? 'unknown')];
        if ($request->attributes->has('_login_email')) {
            $context['account_key'] = $this->key($request->attributes->get('_login_email'));
        }
        $this->db->executeStatement('INSERT INTO audit_logs (actor_id, action, target_type, target_id, context) VALUES (?, ?, ?, ?, ?::jsonb)',
            [$actor, $action, 'user', $actor, json_encode($context, JSON_THROW_ON_ERROR)]);
    }
}
