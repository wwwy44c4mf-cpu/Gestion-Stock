<?php

declare(strict_types=1);

namespace App\Security;

/** Apply BEFORE counting, pagination, grouping and aggregates. Values are bound. */
final readonly class SqlScope
{
    public function __construct(public string $condition, public array $parameters = []) {}
}
