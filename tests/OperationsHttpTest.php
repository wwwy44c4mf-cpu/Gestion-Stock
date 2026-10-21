<?php

declare(strict_types=1);
namespace App\Tests;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OperationsHttpTest extends WebTestCase
{
    public function testHtmlAndApiErrorsHaveFreshServerIdsAndApiBodyMatchesHeader(): void
    {
        $client=static::createClient();$ids=[];
        foreach(['/connexion','/api/v1/health','/api/v1/_test/failure'] as $path) {
            $client->request('GET',$path,server:['HTTP_X_REQUEST_ID'=>str_repeat('b',32)]);$response=$client->getResponse();$id=$response->headers->get('X-Request-Id');self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D',$id);self::assertNotSame(str_repeat('b',32),$id);self::assertNotContains($id,$ids);$ids[]=$id;
            if(str_ends_with($path,'failure')) {self::assertSame(500,$response->getStatusCode());self::assertSame($id,json_decode($response->getContent(),true)['request_id']);self::assertStringNotContainsString('secret-password',$response->getContent());}
        }
    }
    public function testDiagnosticsAreNotExposedAsHttpEndpoints(): void
    {
        $client=static::createClient();$client->request('GET','/api/v1/ops/status');self::assertResponseStatusCodeSame(404);$client->request('GET','/ops/status');self::assertResponseStatusCodeSame(404);
    }
}
