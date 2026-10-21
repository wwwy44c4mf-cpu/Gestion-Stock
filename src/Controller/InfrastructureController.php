<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class InfrastructureController extends AbstractController
{
    #[Route('/health/live', name: 'health_live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/health/ready', name: 'health_ready', methods: ['GET'])]
    public function ready(Connection $connection, \Psr\Log\LoggerInterface $logger): JsonResponse
    {
        try {
            $connection->executeQuery('SELECT 1')->fetchOne();
        } catch (\Throwable) {
            $logger->error('database_unavailable');
            return new JsonResponse(['status' => 'unavailable'], 503);
        }

        return new JsonResponse(['status' => 'ok']);
    }
}
