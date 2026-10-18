<?php

declare(strict_types=1);

namespace App\Security;

/** IDs only. Ownership and actual stores must always be loaded from the database. */
final readonly class AccessTarget
{
    private function __construct(public string $kind, public string $id, public ?string $otherId = null) {}
    public static function store(string $id): self { return new self('store', $id); }
    public static function transfer(string $source, string $destination): self { return new self('transfer', $source, $destination); }
    public static function movement(string $id): self { return new self('movement', $id); }
    public static function consumption(string $id): self { return new self('consumption', $id); }
    public static function inventory(string $id): self { return new self('inventory', $id); }
    public static function alert(string $id): self { return new self('alert', $id); }
    public static function audit(string $id): self { return new self('audit', $id); }
    public static function proposal(string $id): self { return new self('proposal', $id); }
}
