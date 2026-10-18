<?php

declare(strict_types=1);

namespace App\Tests;

use App\Kernel;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ApiCookieTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNativeProductionCookieIsSecureOnHttpsHttpOnlyAndSameSite(): void
    {
        // Processus séparé : session PHP native, aucun stockage simulé, aucun
        // en-tête déjà envoyé par l’affichage du processus PHPUnit principal.
        $kernel = new Kernel('prod', false);
        $request = Request::create('https://localhost/api/v1/auth/csrf');
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        self::assertSame(200, $response->getStatusCode());
        $cookies = $response->headers->getCookies();
        self::assertNotEmpty($cookies);
        $cookie = $cookies[0];
        self::assertSame('GESTION_STOCK_SESSION', $cookie->getName());
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame('lax', $cookie->getSameSite());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $kernel->shutdown();
    }
}
