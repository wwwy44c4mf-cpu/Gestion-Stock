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
        // PHPUnit's isolated-process protocol treats stderr as a test error.
        // HTTP logging is covered separately; keep native cookie/session assertions intact.
        putenv('APP_LOG_LEVEL=emergency');
        $_ENV['APP_LOG_LEVEL'] = $_SERVER['APP_LOG_LEVEL'] = 'emergency';
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
            \App\Tests\CatalogDeletionSchema::apply($db);
            $db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
            require_once dirname(__DIR__).'/migrations/Version20261018120000.php';
            $migration = new \DoctrineMigrations\Version20261018120000($db, new \Psr\Log\NullLogger());
            $migration->up(new \Doctrine\DBAL\Schema\Schema());
            foreach ($migration->getSql() as $sql) { $db->executeStatement($sql->getStatement()); }
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
            $db->executeStatement('INSERT INTO users (email, name, password_hash, role_id, development_only) VALUES (?, ?, ?, 1, true)', ['admin.dev@example.test', 'Administrateur dev', $hash]);
            $csrf = $this->request($schema, 'GET', '/api/v1/auth/csrf', environment: 'dev');
            $devCookie = $csrf->headers->getCookies()[0]->getValue();
            $devToken = json_decode($csrf->getContent(), true)['token'];
            $devLogin = $this->request($schema, 'POST', '/api/v1/auth/login', $devCookie, $devToken, json_encode(['email' => 'admin.dev@example.test', 'password' => $password]), 'dev');
            self::assertSame(200, $devLogin->getStatusCode(), $devLogin->getContent());
            $devSession = $devLogin->headers->getCookies()[0]->getValue();
            self::assertSame(401, $this->request($schema, 'GET', '/api/v1/auth/me', $devSession)->getStatusCode());
            $csrf = $this->request($schema, 'GET', '/api/v1/auth/csrf');
            $prodCookie = $csrf->headers->getCookies()[0]->getValue();
            $prodToken = json_decode($csrf->getContent(), true)['token'];
            self::assertSame(401, $this->request($schema, 'POST', '/api/v1/auth/login', $prodCookie, $prodToken, json_encode(['email' => 'admin.dev@example.test', 'password' => $password]))->getStatusCode());

            [$demoSession, $demoCsrf] = $this->nativeLogin($schema, 'admin.dev@example.test', $password, 'dev');
            $demoPayload = json_encode(['email' => 'demo-child@example.test', 'name' => 'Compte issu du test', 'password' => $password, 'role' => 'USER', 'store_ids' => []]);
            $demoCreated = $this->request($schema, 'POST', '/api/v1/users', $demoSession, $demoCsrf, $demoPayload, 'dev');
            self::assertSame(201, $demoCreated->getStatusCode(), $demoCreated->getContent());
            self::assertTrue($db->fetchOne("SELECT development_only FROM users WHERE email = 'demo-child@example.test'"));
            [$childSession] = $this->nativeLogin($schema, 'demo-child@example.test', $password, 'dev');
            self::assertSame(401, $this->request($schema, 'GET', '/api/v1/auth/me', $childSession)->getStatusCode());
            $db->executeStatement("UPDATE users SET role_id = 1 WHERE email = 'native@example.test'");
            [$adminSession, $adminCsrf] = $this->nativeLogin($schema, 'native@example.test', $password);
            $payload = json_encode(['email' => 'new-native@example.test', 'name' => 'Compte natif créé', 'password' => $password, 'role' => 'USER', 'store_ids' => []]);
            $created = $this->request($schema, 'POST', '/api/v1/users', $adminSession, $adminCsrf, $payload);
            self::assertSame(201, $created->getStatusCode(), $created->getContent());
            $newId = json_decode($created->getContent(), true)['data']['id'];
            $replay = $this->request($schema, 'POST', '/api/v1/users', $adminSession, $adminCsrf, $payload);
            self::assertSame(201, $replay->getStatusCode());
            self::assertJsonStringEqualsJsonString($created->getContent(), $replay->getContent());
            [$firstSession] = $this->nativeLogin($schema, 'new-native@example.test', $password);
            [$untouchedSession] = $this->nativeLogin($schema, 'new-native@example.test', $password);
            $disabled = $this->request($schema, 'PATCH', '/api/v1/users/'.$newId, $adminSession, $adminCsrf, '{"active":false}');
            self::assertSame(200, $disabled->getStatusCode(), $disabled->getContent());
            self::assertSame(401, $this->request($schema, 'GET', '/api/v1/auth/me', $firstSession)->getStatusCode());
            $reactivated = $this->request($schema, 'PATCH', '/api/v1/users/'.$newId, $adminSession, $adminCsrf, '{"active":true}');
            self::assertSame(200, $reactivated->getStatusCode());
            self::assertSame(401, $this->request($schema, 'GET', '/api/v1/auth/me', $untouchedSession)->getStatusCode());
        } finally {
            $db->executeStatement('SET search_path TO public');
            $db->executeStatement('DROP SCHEMA '.$schema.' CASCADE');
            $setup->shutdown();
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPasswordResetRevokesAllNativeSessionsWithoutAutomaticLogin(): void
    {
        putenv('APP_LOG_LEVEL=emergency');$_ENV['APP_LOG_LEVEL']=$_SERVER['APP_LOG_LEVEL']='emergency';
        $schema='reset_native_'.bin2hex(random_bytes(6));$setup=new Kernel('prod',false);$setup->boot();
        $db=$setup->getContainer()->get('doctrine')->getConnection();$db->executeStatement('CREATE SCHEMA '.$schema);$db->executeStatement('SET search_path TO '.$schema);
        try {
            foreach(explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) {if(trim($sql)!=='') {$db->executeStatement($sql);}}
            \App\Tests\CatalogDeletionSchema::apply($db);
            $db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
            foreach(['20261018140000','20261018200000','20261018223000','20261020220000','20261020230000','20261021000000','20261021010000','20261021020000','20261021030000'] as $version) {
                require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';$class='DoctrineMigrations\\Version'.$version;$migration=new $class($db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) {$db->executeStatement($sql->getStatement());}
            }
            $id=$db->fetchOne("INSERT INTO users(email,name,password_hash,role_id) VALUES ('native@example.test','Fictif',?,3) RETURNING id",[password_hash('Old-native-password-2026!',PASSWORD_ARGON2ID)]);
            [$oldCookie]=$this->nativeLogin($schema,'native@example.test','Old-native-password-2026!');
            [$untouchedCookie]=$this->nativeLogin($schema,'native@example.test','Old-native-password-2026!');
            $seed=$this->request($schema,'GET','/api/v1/auth/csrf');$cookie=$seed->headers->getCookies()[0]->getValue();$csrf=json_decode($seed->getContent(),true)['token'];
            $response=$this->request($schema,'POST','/api/v1/auth/password-reset/request',$cookie,$csrf,'{"email":"native@example.test"}');self::assertSame(202,$response->getStatusCode(),$response->getContent());
            $hasher=new \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasher(new \Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory([\App\Security\StockUser::class=>['algorithm'=>'argon2id']]));
            $reset=new \App\Security\PasswordReset($db,new \App\Notification\NotificationOutbox($db,new \App\Notification\NotificationPayload()),$hasher,trim(file_get_contents(getenv('APP_SECRET_FILE'))),'prod');
            parse_str(parse_url($reset->deliveryPath($db->fetchOne('SELECT id FROM password_reset_tokens'),$id,'native@example.test'),PHP_URL_QUERY),$query);
            $response=$this->request($schema,'POST','/api/v1/auth/password-reset/confirm',$cookie,$csrf,json_encode(['token'=>$query['token'],'password'=>'New-native-password-2026!']));self::assertSame(204,$response->getStatusCode(),$response->getContent());
            foreach([$cookie,$oldCookie,$untouchedCookie] as $session) {self::assertSame(401,$this->request($schema,'GET','/api/v1/auth/me',$session)->getStatusCode());}
            $response=$this->request($schema,'POST','/api/v1/auth/password-reset/confirm',$cookie,$csrf,json_encode(['token'=>$query['token'],'password'=>'Another-native-password!']));self::assertSame(422,$response->getStatusCode());
            [$newCookie]=$this->nativeLogin($schema,'native@example.test','New-native-password-2026!');self::assertSame(200,$this->request($schema,'GET','/api/v1/auth/me',$newCookie)->getStatusCode());
        } finally {$db->executeStatement('SET search_path TO public');$db->executeStatement('DROP SCHEMA '.$schema.' CASCADE');$setup->shutdown();}
    }

    private function request(string $schema, string $method, string $path, ?string $cookie = null, ?string $csrf = null, ?string $body = null, string $environment = 'prod'): Response
    {
        // Fresh production kernel per request, actual PHP session files and cookies.
        // Only the test database schema differs from production configuration.
        $kernel = new Kernel($environment, false);
        $kernel->boot();
        $kernel->getContainer()->get('doctrine')->getConnection()->executeStatement('SET search_path TO '.$schema);
        $_COOKIE = $cookie ? ['GESTION_STOCK_SESSION' => $cookie] : [];
        session_id($cookie ?? '');
        $request = Request::create('https://localhost'.$path, $method, cookies: $_COOKIE,
            server: ['REMOTE_ADDR' => '192.0.2.64', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf ?? '', 'HTTP_IDEMPOTENCY_KEY' => 'native-create-user-123'], content: $body);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        $kernel->shutdown();
        return $response;
    }
    private function nativeLogin(string $schema, string $email, string $password, string $environment = 'prod'): array
    {
        $seed = $this->request($schema, 'GET', '/api/v1/auth/csrf', environment: $environment);
        $cookie = $seed->headers->getCookies()[0]->getValue();
        $token = json_decode($seed->getContent(), true)['token'];
        $login = $this->request($schema, 'POST', '/api/v1/auth/login', $cookie, $token, json_encode(['email' => $email, 'password' => $password]), $environment);
        self::assertSame(200, $login->getStatusCode(), $login->getContent());
        $cookie = $login->headers->getCookies()[0]->getValue();
        $csrf = $this->request($schema, 'GET', '/api/v1/auth/csrf', $cookie, environment: $environment);
        return [$cookie, json_decode($csrf->getContent(), true)['token']];
    }

}
