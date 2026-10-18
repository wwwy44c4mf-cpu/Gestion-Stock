<?php

declare(strict_types=1);

namespace App\Api;

/** Les messages sont constants : ne jamais transmettre de valeurs ou secrets. */
final class ApiProblem extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $detail,
        public readonly array $violations = [],
    ) {
        parent::__construct($detail);
    }
}
