<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\OpenApiContract;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ApiInfrastructureController
{
    #[Route('/api/v1/health', name: 'api_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/api/v1/openapi.json', name: 'api_openapi', methods: ['GET'])]
    public function openapi(OpenApiContract $contract): JsonResponse
    {
        return new JsonResponse($contract->document());
    }

    #[Route('/api/v1/auth/csrf', name: 'api_csrf', methods: ['GET'])]
    public function csrf(CsrfTokenManagerInterface $csrf): JsonResponse
    {
        return new JsonResponse(['token' => $csrf->getToken('api')->getValue()]);
    }
}
