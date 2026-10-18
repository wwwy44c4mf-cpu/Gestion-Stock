<?php

declare(strict_types=1);

namespace App\Security;

use App\Api\ApiProblem;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class PermissionVoter extends Voter
{
    public function __construct(private readonly AccessPolicy $policy) {}
    protected function supports(string $attribute, mixed $subject): bool { return str_starts_with($attribute, 'APP_'); }
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?\Symfony\Component\Security\Core\Authorization\Voter\Vote $vote = null): bool
    {
        $permission = Permission::tryFrom($attribute); $actor = $token->getUser();
        if ($permission === null || !$actor instanceof StockUser || ($subject !== null && !$subject instanceof AccessTarget)) { return false; }
        try { $this->policy->assert($actor, $permission, $subject); return true; }
        catch (ApiProblem) { return false; }
    }
}
