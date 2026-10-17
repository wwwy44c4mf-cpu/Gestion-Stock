<?php

namespace App\MessageHandler;

use App\Message\InfrastructureProbe;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class InfrastructureProbeHandler
{
    public function __invoke(InfrastructureProbe $message): void
    {
        if (false === file_put_contents('/tmp/last-probe', (string) time())) {
            throw new \RuntimeException('Impossible de mettre à jour la sonde.');
        }
    }
}
