<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

/** The environment is a container parameter, never a client-supplied value. */
final class TestFixtureRequestMatcher implements RequestMatcherInterface
{
    public function __construct(#[Autowire('%kernel.environment%')] private readonly string $environment) {}

    public function matches(Request $request): bool
    {
        return $this->environment === 'test' && str_starts_with($request->getPathInfo(), '/api/v1/_test/');
    }
}
