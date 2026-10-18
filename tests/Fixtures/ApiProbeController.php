<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Api\IdempotencyKey;
use App\Api\JsonBody;
use App\Api\Pagination;
use App\Api\QueryParameters;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Routes chargées uniquement dans APP_ENV=test, sans accès aux données métier. */
final class ApiProbeController
{
    #[Route('/api/v1/_test/echo', methods: ['POST'])]
    public function echo(Request $request, JsonBody $body, IdempotencyKey $keys): JsonResponse
    {
        $key = $keys->read($request);
        return new JsonResponse(['data' => $body->read($request, 'ConsumptionCreate'), 'key' => $key]);
    }

    #[Route('/api/v1/_test/list', methods: ['GET'])]
    public function list(Request $request, QueryParameters $query): JsonResponse
    {
        $parameters = $query->read($request, 'listConsumptions');
        $page = new Pagination($parameters['page'], $parameters['limit']);
        return new JsonResponse(['parameters' => $parameters, 'result' => $page->response([], 0), 'offset' => $page->offset()]);
    }

    #[Route('/api/v1/_test/limited', methods: ['POST'], defaults: ['_api_sensitive' => true])]
    public function limited(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/v1/_test/failure', methods: ['GET'])]
    public function failure(): never
    {
        throw new \RuntimeException('SQL secret-password-never-expose');
    }
}
