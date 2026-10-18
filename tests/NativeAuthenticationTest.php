<?php

declare(strict_types=1);

namespace App\Tests;

use App\Kernel;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class NativeAuthenticationTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNativeSessionRotationLogoutAndOldCookieReplay(): void
    {
        $schema = 'auth_native_'.bin2hex(random_bytes(6));
        $setup = new Kernel('prod', false);
        $setup->boot();
        $db = $setup->getContainer()->get('doctrine')->getConnection();
        $db->executeStatement('CREATE SCHEMA '.$schema);
        $db->executeStatement('SET search_path TO '.$schema);
        try {
            foreach (explode('-- migrate:statement', file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) {
                if (trim($sql) !== '') { $db->executeStatement($sql); }
            }
            $password = bin2hex(random_bytes(24));
            $hash = password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1]);
            $db->executeStatement('INSERT INTO users (email, name, password_hash, role_id) VALUES (?, ?, ?, 3)',
                ['native@example.test', 'Compte fictif natif', $hash]);
            $csrf = $this->request($schema, 'GET', '/api/v1/auth/csrf');
            self::assertSame(200, $csrf->getStatusCode());
            $preauth = $csrf->headers->getCookies()[0]->getValue();
            $token = json_decode($csrf->getContent(), true)['token'];
            $login = $this->request($schema, 'POST', '/api/v1/auth/login', $preauth, $token, json_encode(['email' => 'native@example.test', 'password' => $password]));
            self::assertSame(200, $login->getStatusCode(), $login->getContent());
            $cookie = $login->headers->getCookies()[0];
            self::assertTrue($cookie->isSecure());
            self::assertTrue($cookie->isHttpOnly());
            self::assertSame('lax', $cookie->getSameSite());
            $authenticated = $cookie->getValue();
            self::assertNotSame($preauth, $authenticated);
            $stored = file_get_contents(dirname(__DIR__).'/var/sessions/sess_'.$authenticated);
            self::assertStringNotContainsString($password, $stored);
            self::assertStringNotContainsString($hash, $stored);
            self::assertSame(401, $this->request($schema, 'GET', '/api/v1/auth/me', $preauth)->getStatusCode());
            self::assertSame(200, $this->request($schema, 'GET', '/api/v1/auth/me', $authenticated)->getStatusCode());
            $csrf = $this->request($schema, 'GET', '/api/v1/auth/csrf', $authenticated);
            $token = json_decode($csrf->getContent(), true)['token'];
            $logout = $this->request($schema, 'POST', '/api/v1/auth/logout', $authenticated, $token);
            self::assertSame(204, $logout->getStatusCode());
            $cookies = $logout->headers->getCookies();
            self::assertNotEmpty($cookies);
            self::assertTrue($cookies[0]->isCleared());
            self::assertSame(401, $this->request($schema, 'GET', '/api/v1/auth/me', $authenticated)->getStatusCode());
        } finally {
            $db->executeStatement('SET search_path TO public');
            $db->executeStatement('DROP SCHEMA '.$schema.' CASCADE');
            $setup->shutdown();
        }
    }

    private function request(string $schema, string $method, string $path, ?string $cookie = null, ?string $csrf = null, ?string $body = null): Response
    {
        // Fresh production kernel per request, actual PHP session files and cookies.
        // Only the test database schema differs from production configuration.
        $kernel = new Kernel('prod', false);
        $kernel->boot();
        $kernel->getContainer()->get('doctrine')->getConnection()->executeStatement('SET search_path TO '.$schema);
        $_COOKIE = $cookie ? ['GESTION_STOCK_SESSION' => $cookie] : [];
        session_id($cookie ?? '');
        $request = Request::create('https://localhost'.$path, $method, cookies: $_COOKIE,
            server: ['REMOTE_ADDR' => '192.0.2.64', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf ?? ''], content: $body);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        $kernel->shutdown();
        return $response;
    }
}
