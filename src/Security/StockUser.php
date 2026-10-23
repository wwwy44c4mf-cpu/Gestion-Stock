<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class StockUser implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly string $name,
        public readonly string $role,
        public readonly bool $active,
        private string $password,
        public readonly string $authVersion,
        public readonly string $timezone,
        public readonly string $createdAt,
        public readonly array $storeIds = [],
        public readonly ?string $favoriteStoreId = null,
    ) {}

    public function getUserIdentifier(): string { return $this->email; }
    public function getPassword(): string { return $this->password; }
    public function getRoles(): array
    {
        return [match ($this->role) {
            'ADMIN' => 'ROLE_ADMIN', 'MANAGER' => 'ROLE_GESTIONNAIRE', default => 'ROLE_UTILISATEUR',
        }];
    }

    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self && $user->active && $this->id === $user->id
            && $this->email === $user->email && $this->role === $user->role
            && $this->authVersion === $user->authVersion
            && hash_equals($this->password, hash('sha256', $user->getPassword()));
    }

    // Only a password fingerprint enters the server-side session, never its hash.
    public function __serialize(): array
    {
        $data = get_object_vars($this);
        $data['password'] = hash('sha256', $this->password);
        return $data;
    }

    public function __unserialize(array $data): void
    {
        foreach ($data as $key => $value) { $this->$key = $value; }
    }

    public function replacePassword(string $hash): void { $this->password = $hash; }

    public function resource(): array
    {
        return ['data' => ['id' => $this->id, 'email' => $this->email, 'name' => $this->name,
            'role' => $this->role, 'active' => $this->active, 'store_ids' => $this->storeIds,
            'timezone' => $this->timezone, 'favorite_store_id' => $this->favoriteStoreId, 'created_at' => $this->createdAt]];
    }
}
