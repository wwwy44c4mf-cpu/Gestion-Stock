<?php

declare(strict_types=1);

namespace App\Tests;

use App\Api\OpenApiContract;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiTest extends WebTestCase
{
    private function csrf(KernelBrowser $client): string
    {
        $client->request('GET', '/api/v1/auth/csrf');
        self::assertResponseIsSuccessful();
        return json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['token'];
    }

    private function post(KernelBrowser $client, string $body, string $token, array $server = []): void
    {
        $client->request('POST', '/api/v1/_test/echo', server: array_merge([
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token, 'HTTP_IDEMPOTENCY_KEY' => 'probe-key-123',
        ], $server), content: $body);
    }

    public function testContractOperationsDeclareTheirRightsAndProtections(): void
    {
        self::bootKernel();
        $contract = static::getContainer()->get(OpenApiContract::class);
        $ids = [];
        foreach ($contract->document()['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                self::assertNotContains($operation['operationId'], $ids);
                $ids[] = $operation['operationId'];
                self::assertArrayHasKey('x-implementation', $operation);
                self::assertArrayHasKey('x-roles', $operation);
                self::assertArrayHasKey('security', $operation);
                self::assertArrayHasKey('responses', $operation);
                if (in_array($method, ['post', 'put', 'patch'], true)) {
                    self::assertArrayHasKey('CsrfToken', $operation['security'][0]);
                }
                foreach ($operation['parameters'] as $parameter) {
                    $resolved = $contract->resolve($parameter);
                    if ($resolved['in'] === 'path') {
                        self::assertStringContainsString('{'.$resolved['name'].'}', $path);
                    }
                }
            }
        }
        self::assertCount(58, $ids);
    }

    public function testPublicInfrastructureAndContractAreAccessible(): void
    {
        $client = static::createClient();
        foreach (['/api/v1/health', '/api/v1/openapi.json'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'application/json');
            self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('private', $client->getResponse()->headers->get('Cache-Control'));
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $client->getResponse()->headers->get('X-Request-Id'));
        }
    }

    public function testAggregateQuantitiesPreserveExactLargeIntegers(): void
    {
        self::bootKernel();
        $contract = static::getContainer()->get(OpenApiContract::class);
        $value = json_decode('{"consumed_quantity":"18014398509481982","returned_quantity":"0","unit_code":"unit"}');
        self::assertTrue($contract->valid($value, ['$ref' => '#/components/schemas/ConsumptionTotals']));
        $value->consumed_quantity = 18014398509481982;
        self::assertFalse($contract->valid($value, ['$ref' => '#/components/schemas/ConsumptionTotals']));
    }

    public function testInformationRequiresAtLeastOneNonemptyTarget(): void
    {
        self::bootKernel();
        $contract = static::getContainer()->get(OpenApiContract::class);
        $value = json_decode('{"subject":"Information","message":"Message"}');
        $schema = ['$ref' => '#/components/schemas/InformationCreate'];
        self::assertFalse($contract->valid($value, $schema));
        $value->roles = [];
        self::assertFalse($contract->valid($value, $schema));
        $value->roles = ['MANAGER'];
        self::assertTrue($contract->valid($value, $schema));
        $value->roles = [];
        $value->store_ids = ['11111111-1111-4111-8111-111111111111'];
        self::assertTrue($contract->valid($value, $schema));
    }

    public function testUnknownRouteAndWrongMethodHaveNormalizedProblems(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/not-found', server: ['HTTP_X_REQUEST_ID' => 'untrusted-client-id']);
        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $body = json_decode($client->getResponse()->getContent(), false, flags: JSON_THROW_ON_ERROR);
        self::assertSame($client->getResponse()->headers->get('X-Request-Id'), $body->request_id);
        self::assertNotSame('untrusted-client-id', $body->request_id);
        self::assertTrue(static::getContainer()->get(OpenApiContract::class)->valid($body, ['$ref' => '#/components/schemas/Problem']));
        $client->request('POST', '/api/v1/health');
        self::assertResponseStatusCodeSame(405);
        self::assertResponseHasHeader('Allow');
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testCsrfProtectsWritesAndIsBoundToItsSession(): void
    {
        $client = static::createClient();
        $this->post($client, '{}', '');
        self::assertResponseStatusCodeSame(403);
        $token = $this->csrf($client);
        $body = '{"article_id":"11111111-1111-4111-8111-111111111111","store_id":"22222222-2222-4222-8222-222222222222","quantity":1,"comment":"1.5 et 1e3 sont du texte"}';
        $this->post($client, $body, $token);
        self::assertResponseIsSuccessful();
        self::assertSame(1, json_decode($client->getResponse()->getContent(), true)['data']['quantity']);
        $client->restart();
        $this->post($client, $body, $token);
        self::assertResponseStatusCodeSame(403);
    }

    public function testInvalidBodiesAreRejectedWithoutEchoingTheirContents(): void
    {
        $client = static::createClient();
        $token = $this->csrf($client);
        $prefix = '"article_id":"11111111-1111-4111-8111-111111111111","store_id":"22222222-2222-4222-8222-222222222222",';
        foreach (['{', '[]', 'null', 'true'] as $invalid) {
            $this->post($client, $invalid, $token);
            self::assertResponseStatusCodeSame(400);
        }
        foreach (['0', '-1', '1.5', '1.0000000000000000001', '1e0', '9007199254740992', '"1"', 'true'] as $quantity) {
            $this->post($client, '{'.$prefix.'"quantity":'.$quantity.'}', $token);
            self::assertResponseStatusCodeSame(422);
        }
        $this->post($client, '{'.$prefix.'"quantity":1,"password":"do-not-echo-this-value"}', $token);
        self::assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString('do-not-echo-this-value', $client->getResponse()->getContent());
        $this->post($client, '{}', $token, ['CONTENT_TYPE' => 'text/plain']);
        self::assertResponseStatusCodeSame(415);
        $this->post($client, '{"comment":"'.str_repeat('a', 65536).'"}', $token);
        self::assertResponseStatusCodeSame(413);
        $this->post($client, '{'.$prefix.'"quantity":1}', $token, ['HTTP_IDEMPOTENCY_KEY' => '']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testPaginationFiltersAndSortAreBounded(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/_test/list?page=2&limit=10&sort=quantity&order=desc&date_from=2026-10-01&date_to=2026-10-18');
        self::assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(10, $body['offset']);
        self::assertSame(2, $body['result']['meta']['page']);
        foreach (['page=0', 'page=10001', 'limit=101', 'limit=1.5', 'sort=password_hash', 'order=desc%3Bdrop', 'unknown=1', 'page[]=1', 'store_id=invalid', 'date_from=2026-02-30', 'date_from=2026-10-18&date_to=2026-10-01'] as $query) {
            $client->request('GET', '/api/v1/_test/list?'.$query);
            self::assertResponseStatusCodeSame(422);
        }
    }

    public function testSensitiveRouteLimitsAreEnforcedWithRetryAfter(): void
    {
        $client = static::createClient();
        $token = $this->csrf($client);
        for ($n = 0; $n < 5; ++$n) {
            $client->request('POST', '/api/v1/_test/limited', server: ['REMOTE_ADDR' => '192.0.2.71', 'HTTP_X_CSRF_TOKEN' => $token]);
            self::assertResponseIsSuccessful();
        }
        $client->request('POST', '/api/v1/_test/limited', server: ['REMOTE_ADDR' => '192.0.2.71', 'HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(429);
        self::assertResponseHasHeader('Retry-After');
        self::assertGreaterThanOrEqual(1, (int) $client->getResponse()->headers->get('Retry-After'));
    }

    public function testUnexpectedErrorsNeverExposeExceptionDetails(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/_test/failure');
        self::assertResponseStatusCodeSame(500);
        self::assertStringNotContainsString('secret-password', $client->getResponse()->getContent());
        self::assertStringNotContainsString('RuntimeException', $client->getResponse()->getContent());
    }

}
