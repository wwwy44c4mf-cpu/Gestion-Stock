<?php

declare(strict_types=1);

namespace App\Tests;

use App\Security\StockUser;
use App\Security\StockUserProvider;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private string $schema;
    private string $userId;
    private string $password = 'Mot-de-passe-fictif-123!';
    private string $email = 'technicien@example.test';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->db = static::getContainer()->get(Connection::class);
        $this->db->beginTransaction();
        $this->schema = 'auth01_'.bin2hex(random_bytes(6));
        $this->db->executeStatement('CREATE SCHEMA '.$this->schema);
        $this->db->executeStatement('SET LOCAL search_path TO '.$this->schema);
        foreach (explode('-- migrate:statement', file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) {
            if (trim($sql) !== '') { $this->db->executeStatement($sql); }
        }
        $this->db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
        $hash = static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword(new StockUser('unused', $this->email, 'Test', 'USER', true, '', '0', 'Europe/Paris', ''), $this->password);
        self::assertStringStartsWith('$argon2id$', $hash);
        $this->userId = $this->db->fetchOne('INSERT INTO users (email, name, password_hash, role_id) VALUES (?, ?, ?, 3) RETURNING id', [$this->email, 'Technicien <test>', $hash]);
        $this->client->setServerParameter('REMOTE_ADDR', '10.'.random_int(1, 254).'.'.random_int(1, 254).'.'.random_int(1, 254));
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->isTransactionActive()) { $this->db->rollBack(); }
        parent::tearDown();
    }

    private function token(): string
    {
        $this->client->request('GET', '/api/v1/auth/csrf');
        self::assertResponseIsSuccessful();
        return json_decode($this->client->getResponse()->getContent(), true)['token'];
    }

    private function login(?string $email = null, ?string $password = null): void
    {
        $token = $this->token();
        $this->client->request('POST', '/api/v1/auth/login', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: json_encode(['email' => $email ?? $this->email, 'password' => $password ?? $this->password]));
    }

    public function testLoginProfileAndLogoutWithSessionAndCsrfRotation(): void
    {
        $before = $this->token();
        $oldCookie = $this->client->getCookieJar()->all()[0]->getValue();
        $this->login('TECHNICIEN@example.test');
        self::assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($this->userId, $body['data']['id']);
        self::assertSame('USER', $body['data']['role']);
        self::assertArrayNotHasKey('password_hash', $body['data']);
        self::assertNotSame($oldCookie, $this->client->getCookieJar()->all()[0]->getValue());
        $this->client->request('GET', '/api/v1/auth/me');
        self::assertResponseIsSuccessful();
        $this->client->request('PATCH', '/api/v1/auth/me', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $before], content: '{"timezone":"UTC"}');
        self::assertResponseStatusCodeSame(403);
        $token = $this->token();
        $this->client->request('PATCH', '/api/v1/auth/me', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: '{"timezone":"America/Montreal"}');
        self::assertResponseIsSuccessful();
        self::assertSame('America/Montreal', $this->db->fetchOne('SELECT timezone FROM users WHERE id = ?', [$this->userId]));
        $this->client->request('GET', '/profil');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mon profil');
        self::assertStringNotContainsString('<test>', $this->client->getResponse()->getContent());
        $this->client->request('POST', '/api/v1/auth/logout', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/v1/auth/me');
        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousAccessAndInvalidCredentialsAreGenericAndAudited(): void
    {
        $this->client->request('GET', '/api/v1/auth/me');
        self::assertResponseStatusCodeSame(401);
        $this->client->request('GET', '/api/v1/stocks');
        // Stock consultation is implemented and requires authentication.
        self::assertResponseStatusCodeSame(401);
        $this->client->request('GET', '/profil');
        self::assertResponseRedirects('/connexion');
        $this->login(password: 'secret-incorrect');
        self::assertResponseStatusCodeSame(401);
        $known = json_decode($this->client->getResponse()->getContent(), true);
        $this->login('inconnu@example.test', 'secret-incorrect');
        self::assertResponseStatusCodeSame(401);
        $unknown = json_decode($this->client->getResponse()->getContent(), true);
        unset($known['request_id'], $unknown['request_id']);
        self::assertSame($known, $unknown);
        $this->db->executeStatement('UPDATE users SET active = false WHERE id = ?', [$this->userId]);
        $this->login();
        self::assertResponseStatusCodeSame(401);
        $inactive = json_decode($this->client->getResponse()->getContent(), true);
        unset($inactive['request_id']);
        self::assertSame($known, $inactive);
        $logs = $this->db->fetchAllAssociative('SELECT * FROM audit_logs');
        self::assertCount(3, $logs);
        $json = json_encode($logs);
        foreach ([$this->password, 'secret-incorrect', $this->email, 'inconnu@example.test', 'password_hash'] as $secret) { self::assertStringNotContainsString($secret, $json); }
        self::assertSame(['auth.login_failed'], array_values(array_unique(array_column($logs, 'action'))));
    }

    public function testFiveFailedAttemptsAreAllowedThenSixthIsThrottled(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            if ($i < 2) {
                $crawler = $this->client->request('GET', '/connexion');
                $this->client->submit($crawler->selectButton('Se connecter')->form(['email' => $this->email, 'password' => 'incorrect']));
                self::assertResponseRedirects('/connexion', 303);
            } else { $this->login(password: 'incorrect'); self::assertResponseStatusCodeSame(401); }
        }
        $this->login();
        self::assertResponseStatusCodeSame(429);
        self::assertResponseHasHeader('Retry-After');
        self::assertSame(5, (int) $this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE action = 'auth.login_failed'"));
        self::assertSame(1, (int) $this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE action = 'auth.login_throttled'"));
    }

    public function testIdleAndAbsoluteExpiration(): void
    {
        foreach (['_auth_seen' => time() - 1801, '_auth_started' => time() - 43201] as $key => $time) {
            $this->login(); self::assertResponseIsSuccessful();
            $session = $this->client->getRequest()->getSession();
            $session->set($key, $time); $session->save();
            $this->client->request('GET', '/api/v1/auth/me');
            self::assertResponseStatusCodeSame(401);
        }
    }

    public function testDatabaseChangesInvalidateExistingSessions(): void
    {
        foreach (["active = false", "auth_version = auth_version + 1", "password_hash = 'changed-password-hash'"] as $change) {
            $this->login(); self::assertResponseIsSuccessful();
            $hash = $this->db->fetchOne('SELECT password_hash FROM users WHERE id = ?', [$this->userId]);
            $this->db->executeStatement('UPDATE users SET '.$change.' WHERE id = ?', [$this->userId]);
            $this->client->request('GET', '/api/v1/auth/me');
            self::assertResponseStatusCodeSame(401);
            $this->db->executeStatement('UPDATE users SET active = true, password_hash = ? WHERE id = ?', [$hash, $this->userId]);
        }
    }

    public function testOnlyTimezoneCanBeChangedAndBadZonesAreRejected(): void
    {
        $this->login(); self::assertResponseIsSuccessful();
        $token = $this->token();
        foreach (['{"timezone":"Invalid/Zone"}', '{"timezone":"UTC","role":"ADMIN"}', '{"email":"other@example.test"}', '{}'] as $body) {
            $this->client->request('PATCH', '/api/v1/auth/me', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: $body);
            self::assertResponseStatusCodeSame(422);
        }
        self::assertSame(3, (int) $this->db->fetchOne('SELECT role_id FROM users WHERE id = ?', [$this->userId]));
    }

    public function testFrenchFormLoginAndLogoutWorkWithoutJavascript(): void
    {
        $crawler = $this->client->request('GET', '/connexion');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[autocomplete="current-password"]');
        $this->client->submit($crawler->selectButton('Se connecter')->form(['email' => $this->email, 'password' => $this->password]));
        self::assertResponseRedirects('/', 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorExists('a[href="/profil"]');
        self::assertSelectorNotExists('a[href="/administration/comptes"]');
        $this->client->submit($crawler->selectButton('Se déconnecter')->form());
        self::assertResponseRedirects('/connexion', 303);
        $this->client->request('GET', '/profil');
        self::assertResponseRedirects('/connexion');
    }

    public function testSessionSerializationContainsNoPasswordHash(): void
    {
        $user = static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier($this->email);
        $serialized = serialize($user);
        self::assertStringNotContainsString($user->getPassword(), $serialized);
        $restored = unserialize($serialized);
        self::assertTrue($restored->isEqualTo($user));
        self::assertSame($user->id, $restored->id);
    }
    public function testLoginRequiresCsrfAndStrictJson(): void
    {
        $this->client->request('POST', '/api/v1/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertResponseStatusCodeSame(403);
        $token = $this->token();
        foreach ([['{', 400], ['{}', 422], ['{"email":"bad","password":"x"}', 422], ['{"email":"a@example.test","password":"x","role":"ADMIN"}', 422]] as [$body, $status]) {
            $this->client->request('POST', '/api/v1/auth/login', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: $body);
            self::assertResponseStatusCodeSame($status);
        }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM audit_logs'));
    }

    public function testLogoutInvalidatesTheOldCookieAndRequiresPostAndCsrf(): void
    {
        $this->login(); self::assertResponseIsSuccessful();
        $cookie = clone $this->client->getCookieJar()->all()[0];
        $this->client->request('GET', '/api/v1/auth/logout');
        self::assertResponseStatusCodeSame(405);
        $this->client->request('POST', '/api/v1/auth/logout');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/api/v1/auth/me');
        self::assertResponseIsSuccessful();
        $token = $this->token();
        $this->client->request('POST', '/api/v1/auth/logout', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(204);
        $this->client->getCookieJar()->set($cookie);
        $this->client->request('GET', '/api/v1/auth/me');
        self::assertResponseStatusCodeSame(401);
    }

    public function testLegacyPasswordIsUpgradedWithoutBreakingTheSession(): void
    {
        $this->db->executeStatement('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($this->password, PASSWORD_BCRYPT), $this->userId]);
        $this->login(); self::assertResponseIsSuccessful();
        $hash = $this->db->fetchOne('SELECT password_hash FROM users WHERE id = ?', [$this->userId]);
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue(password_verify($this->password, $hash));
        $this->client->request('GET', '/api/v1/auth/me');
        self::assertResponseIsSuccessful();
    }

    public function testIdentityAndNavigationComeFromDatabaseNotClient(): void
    {
        foreach ([1 => 'ADMIN', 2 => 'MANAGER', 3 => 'USER'] as $id => $role) {
            $this->db->executeStatement('UPDATE users SET role_id = ? WHERE id = ?', [$id, $this->userId]);
            $this->login(); self::assertResponseIsSuccessful();
            $body = json_decode($this->client->getResponse()->getContent());
            self::assertSame($role, $body->data->role);
            self::assertTrue(static::getContainer()->get(\App\Api\OpenApiContract::class)->valid($body, ['$ref' => '#/components/schemas/UserResource']));
            $this->client->request('GET', '/?role=ADMIN&store_id=untrusted');
            self::assertResponseStatusCodeSame(422);
            $this->client->request('GET', '/');
            self::assertResponseIsSuccessful();
            if ($role === 'ADMIN') { self::assertSelectorExists('nav a[href="/administration/comptes"]'); }
            else { self::assertSelectorNotExists('nav a[href="/administration/comptes"]'); }
        }
    }

    public function testProfileFormAndItsCsrfProtection(): void
    {
        $this->login(); self::assertResponseIsSuccessful();
        $this->client->request('POST', '/profil', ['timezone' => 'UTC']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Europe/Paris', $this->db->fetchOne('SELECT timezone FROM users WHERE id = ?', [$this->userId]));
        $crawler = $this->client->request('GET', '/profil');
        $this->client->submit($crawler->selectButton('Enregistrer')->form(['timezone' => 'UTC']));
        self::assertResponseRedirects('/profil', 303);
        $this->client->followRedirect();
        self::assertSelectorExists('select#timezone option[value="UTC"][selected]');
    }

    public function testBootstrapCreatesOnlyFirstAdminWithHiddenPassword(): void
    {
        $this->db->executeStatement('DELETE FROM users');
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($application->find('app:user:create-admin'));
        $tester->setInputs([$this->password, $this->password]);
        $status = $tester->execute(['email' => 'admin@example.test', 'name' => 'Administrateur']);
        self::assertSame(0, $status, $tester->getDisplay());
        self::assertStringNotContainsString($this->password, $tester->getDisplay());
        $row = $this->db->fetchAssociative('SELECT * FROM users');
        self::assertSame(1, $row['role_id']);
        self::assertTrue(password_verify($this->password, $row['password_hash']));
        self::assertStringStartsWith('$argon2id$', $row['password_hash']);
        self::assertSame(1, $tester->execute(['email' => 'second@example.test', 'name' => 'Second']));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM users'));
    }

    public function testIpCeilingAppliesAcrossDifferentAccounts(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $this->login('unknown'.$i.'@example.test', 'incorrect');
            self::assertResponseStatusCodeSame(401);
        }
        $this->login('new-unknown@example.test', 'incorrect');
        self::assertResponseStatusCodeSame(429);
    }

    public function testStoreAssignmentsInProfileExcludeInactiveStores(): void
    {
        $active = $this->db->fetchOne("INSERT INTO stores (code, name) VALUES ('ACT', 'Magasin actif') RETURNING id");
        $inactive = $this->db->fetchOne("INSERT INTO stores (code, name, active) VALUES ('INA', 'Magasin fermé', false) RETURNING id");
        $this->db->executeStatement('INSERT INTO user_stores (user_id, store_id) VALUES (?, ?), (?, ?)', [$this->userId, $active, $this->userId, $inactive]);
        $this->login(); self::assertResponseIsSuccessful();
        self::assertSame([$active], json_decode($this->client->getResponse()->getContent(), true)['data']['store_ids']);
    }

}
