<?php

declare(strict_types=1);
namespace App\Security;

use App\Api\ApiProblem;
use App\Notification\NotificationOutbox;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordReset
{
    public const ACCEPTED = 'Si cette adresse correspond à un compte actif, un lien de réinitialisation vous sera envoyé.';
    public const INVALID = 'Ce lien est invalide ou expiré. Demandez un nouveau lien.';

    public function __construct(private readonly Connection $db, private readonly NotificationOutbox $outbox,
        private readonly UserPasswordHasherInterface $hasher,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire('%kernel.environment%')] private readonly string $environment) {}

    public function request(string $email): void
    {
        $started = hrtime(true);
        try {
            $this->db->transactional(function () use ($email): void {
                // Same guard/order as account administration; serializes requests and consumption.
                $this->db->executeStatement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
                $user = $this->db->fetchAssociative('SELECT * FROM users WHERE lower(btrim(email))=?', [mb_strtolower(trim($email), 'UTF-8')]);
                if (!$user || !$this->eligible($user)) { return; }
                // Separate from the IP limit: repeated requests never disclose this cooldown.
                if ($this->db->fetchOne("SELECT 1 FROM password_reset_tokens WHERE user_id=? AND created_at>clock_timestamp()-interval '1 minute'", [$user['id']])) { return; }
                $id = $this->db->fetchOne('SELECT gen_random_uuid()');
                $token = $this->token($id, $user);
                $this->db->executeStatement('UPDATE password_reset_tokens SET used_at=clock_timestamp() WHERE user_id=? AND used_at IS NULL', [$user['id']]);
                $this->db->executeStatement("INSERT INTO password_reset_tokens(id,user_id,token_hash,expires_at) VALUES (?,?,?,clock_timestamp()+interval '30 minutes')", [$id, $user['id'], hash('sha256', $token)]);
                // Only a public, non-redeemable selector enters the durable outbox.
                $this->outbox->enqueue('password-reset:'.$id, 'password_reset', $user['id'], ['reset_id'=>$id]);
                $this->db->executeStatement("INSERT INTO audit_logs(action,target_type,target_id) VALUES ('auth.password_reset_requested','user',?)", [$user['id']]);
            });
        } finally {
            // Known, unknown, inactive and throttled accounts share a minimum response duration.
            $remaining = 150000 - (int) ((hrtime(true) - $started) / 1000);
            if ($remaining > 0) { usleep($remaining); }
        }
    }

    /** Administrative approval only: caller holds the users guard in its transaction. */
    public function invite(string $userId): void
    {
        if (!$this->db->isTransactionActive()) { throw new \LogicException('invitation_requires_transaction'); }
        $user=$this->db->fetchAssociative('SELECT * FROM users WHERE id=?',[$userId]);
        if (!$user || !$this->activationEligible($user)) { throw new ApiProblem(409,'invitation_unavailable','Ce compte ne peut pas recevoir une invitation.'); }
        if ($this->db->fetchOne("SELECT 1 FROM password_reset_tokens WHERE user_id=? AND purpose='activation' AND used_at IS NULL AND expires_at>clock_timestamp() AND created_at>clock_timestamp()-interval '1 minute'",[$userId])) { return; }
        $id=$this->db->fetchOne('SELECT gen_random_uuid()');
        $token=$this->token($id,$user,'activation');
        $this->db->executeStatement('UPDATE password_reset_tokens SET used_at=clock_timestamp() WHERE user_id=? AND used_at IS NULL',[$userId]);
        $this->db->executeStatement("INSERT INTO password_reset_tokens(id,user_id,token_hash,expires_at,purpose) VALUES (?,?,?,clock_timestamp()+interval '24 hours','activation')",[$id,$userId,hash('sha256',$token)]);
        $this->outbox->enqueue('account-activation:'.$id,'account_activation',$userId,['reset_id'=>$id]);
    }

    private function activationEligible(array $user): bool
    {
        return !$user['active'] && $user['anonymized_at']===null && $user['email']!==null
            && (!$user['development_only'] || $this->environment==='dev')
            && (bool)$this->db->fetchOne("SELECT 1 FROM account_requests WHERE user_id=? AND status='approved' AND activated_at IS NULL AND anonymized_at IS NULL AND created_at>clock_timestamp()-interval '1 year'",[$user['id']]);
    }

    /** Presentation only: never grants access or consumes a link. */
    public function isInvitation(string $token): bool
    {
        $row=$this->db->fetchAssociative('SELECT * FROM password_reset_tokens WHERE token_hash=?',[hash('sha256',$token)]);
        return ($row['purpose']??'reset')==='activation';
    }

    public function confirm(string $token, string $password): void
    {
        $this->db->transactional(function () use ($token, $password): void {
            $this->db->executeStatement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
            $reset = $this->db->fetchAssociative('SELECT * FROM password_reset_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>clock_timestamp() FOR UPDATE', [hash('sha256', $token)]);
            $user = $reset ? $this->db->fetchAssociative('SELECT * FROM users WHERE id=?', [$reset['user_id']]) : false;
            $purpose=$reset['purpose']??'reset';
            if (!$reset || !$user || !($purpose==='activation'?$this->activationEligible($user):$this->eligible($user)) || !hash_equals($this->token($reset['id'], $user,$purpose), $token)) {
                throw new ApiProblem(422, 'invalid_reset_token', self::INVALID);
            }
            if (mb_strlen($password, 'UTF-8') < 12 || mb_strlen($password, 'UTF-8') > 128 || !preg_match('//u', $password) || str_contains($password, "\0")) {
                throw new ApiProblem(422, 'invalid_password', 'Le mot de passe doit contenir entre 12 et 128 caractères.');
            }
            $identity = new StockUser($user['id'], $user['email'], $user['name'], 'USER', true, '', (string) $user['auth_version'], $user['timezone'], $user['created_at']);
            $hash = $this->hasher->hashPassword($identity, $password);
            $this->db->executeStatement('UPDATE users SET password_hash=?,active=true,auth_version=auth_version+1,updated_at=clock_timestamp() WHERE id=?', [$hash, $user['id']]);
            if ($purpose==='activation') { $this->db->executeStatement('UPDATE account_requests SET activated_at=clock_timestamp() WHERE user_id=?',[$user['id']]); }
            $this->db->executeStatement('UPDATE password_reset_tokens SET used_at=clock_timestamp() WHERE user_id=? AND used_at IS NULL', [$user['id']]);
            $this->db->executeStatement("INSERT INTO audit_logs(action,target_type,target_id) VALUES (?,'user',?)", [$purpose==='activation'?'auth.account_activated':'auth.password_reset_completed',$user['id']]);
            $this->outbox->enqueue('password-reset-completed:'.$reset['id'], 'information', $user['id'], [
                'subject'=>$purpose==='activation'?'Votre compte Gestion-Stock est prêt':'Votre mot de passe a été modifié',
                'body'=>$purpose==='activation'?'Votre mot de passe a été créé. Vous pouvez maintenant vous connecter à Gestion-Stock.':'Le mot de passe de votre compte Gestion-Stock vient d’être modifié et vos anciennes sessions ont été fermées. Si vous n’êtes pas à l’origine de cette action, contactez votre administrateur.',
                'path'=>'/connexion',
            ]);
        });
    }

    /** Called only while preparing a delivery; never persisted or returned by an API. */
    public function deliveryPath(string $id, string $recipientId, string $email, string $expectedPurpose='reset'): string
    {
        $row = $this->db->fetchAssociative('SELECT r.*,u.*,r.id reset_id FROM password_reset_tokens r JOIN users u ON u.id=r.user_id WHERE r.id=? AND r.user_id=? AND r.used_at IS NULL AND r.expires_at>clock_timestamp()', [$id, $recipientId]);
        $purpose=$row['purpose']??'reset';
        if (!$row || $purpose!==$expectedPurpose || !($purpose==='activation'?$this->activationEligible($row):$this->eligible($row)) || $row['email'] !== $email || !hash_equals($row['token_hash'], hash('sha256', $token = $this->token($id, $row,$purpose)))) {
            throw new \InvalidArgumentException('password_reset_unavailable');
        }
        return '/mot-de-passe/reinitialiser?token='.rawurlencode($token);
    }

    private function eligible(array $user): bool
    {
        return $user['active'] && $user['anonymized_at'] === null && $user['email'] !== null
            && (!$user['development_only'] || $this->environment === 'dev');
    }

    private function token(string $id, array $user,string $purpose='reset'): string
    {
        // Random selector + secret-key PRF, bound to the current account state.
        // DB/queue possession alone cannot reconstruct the usable link.
        return $id.'.'.hash_hmac('sha256', json_encode([$purpose==='activation'?'account-activation-v1':'password-reset-v1', $id, $user['id'], $user['email'], (string) $user['auth_version'], $user['password_hash']], JSON_THROW_ON_ERROR), $this->secret);
    }
}
