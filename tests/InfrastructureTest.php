<?php

namespace App\Tests;

use App\Message\InfrastructureProbe;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class InfrastructureTest extends WebTestCase
{
    public function testHomeRendersFrenchPage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Gestion de stock');
        self::assertSelectorExists('html[lang="fr"]');
    }

    public function testReadinessChecksPostgres(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health/ready');
        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"status":"ok"}', $client->getResponse()->getContent());
    }

    public function testLivenessDoesNotExposeTechnicalDetails(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health/live');
        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"status":"ok"}', $client->getResponse()->getContent());
    }

    public function testUnknownRouteReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/does-not-exist');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAsyncMessageIsPersistedAndCanBeAcknowledged(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var TransportInterface $transport */
        $transport = $container->get('messenger.transport.async');
        $container->get(MessageBusInterface::class)->dispatch(new InfrastructureProbe());
        $messages = iterator_to_array($transport->get());
        self::assertNotEmpty($messages);
        foreach ($messages as $envelope) {
            self::assertInstanceOf(InfrastructureProbe::class, $envelope->getMessage());
            $transport->ack($envelope);
        }
    }
}
