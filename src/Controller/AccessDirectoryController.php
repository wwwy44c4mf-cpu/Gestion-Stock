<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\AccessDirectory;
use App\Api\ApiProblem;
use App\Api\QueryParameters;
use App\Security\StockUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AccessDirectoryController extends AbstractController
{
    public function __construct(private readonly AccessDirectory $directory) {}
    #[Route('/api/v1/stores', name: 'api_stores_list', methods: ['GET'])]
    public function stores(Request $request, QueryParameters $query): JsonResponse
    {
        return $this->json($this->directory->stores($this->actor(), $query->read($request, 'listStores')));
    }
    #[Route('/api/v1/stores/{id}', name: 'api_stores_get', methods: ['GET'])]
    public function store(string $id): JsonResponse { return $this->json($this->directory->store($this->actor(), $id)); }
    #[Route('/api/v1/consumption-beneficiaries', name: 'api_beneficiaries_list', methods: ['GET'])]
    public function beneficiaries(Request $request, QueryParameters $query): JsonResponse
    {
        return $this->json($this->directory->beneficiaries($this->actor(), $query->read($request, 'listConsumptionBeneficiaries')));
    }
    private function actor(): StockUser
    {
        $user = $this->getUser();
        if (!$user instanceof StockUser) { throw new ApiProblem(401, 'authentication_required', 'Connectez-vous.'); }
        return $user;
    }
}
