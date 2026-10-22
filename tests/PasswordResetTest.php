<?php

declare(strict_types=1);
namespace App\Tests;

use App\Api\ApiProblem;
use App\Security\PasswordReset;
use App\Security\StockUserProvider;
use App\Notification\NotificationRenderer;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class PasswordResetTest extends WebTestCase
{
    private Connection $db;
    private string $schema;
    private KernelBrowser $client;
    protected function setUp(): void
    {
        self::ensureKernelShutdown(); $this->client=static::createClient(); $this->client->disableReboot(); 
        $this->db = static::getContainer()->get(Connection::class);
        $this->schema = 'auth04_'.bin2hex(random_bytes(6));
        $this->db->executeStatement('CREATE SCHEMA '.$this->schema);
        $this->db->executeStatement('SET search_path TO '.$this->schema);
        $factory = DependencyFactory::fromConnection(new ConfigurationArray(['migrations_paths'=>['DoctrineMigrations'=>dirname(__DIR__).'/migrations'],'all_or_nothing'=>true]),new ExistingConnection($this->db));
        $command = new MigrateCommand($factory);$command->setName('doctrine:migrations:migrate');
        $out = new BufferedOutput();$input = new ArrayInput([]);$input->setInteractive(false);
        self::assertSame(0,$command->run($input,$out),$out->fetch());
        $this->db->beginTransaction();
        $this->db->executeStatement("INSERT INTO users(email,name,password_hash,role_id) VALUES ('person@example.test','Fictif',?,3)", [password_hash('Old-test-password-2026!', PASSWORD_ARGON2ID)]);
        static::getContainer()->get('messenger.transport.notifications')->setup();
        $this->client->setServerParameter('REMOTE_ADDR','10.'.random_int(1,254).'.'.random_int(1,254).'.'.random_int(1,254));
        $this->csrf();
    }
    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->isTransactionActive()) { $this->db->rollBack(); }
            $this->db->executeStatement('SET search_path TO public');
            $this->db->executeStatement('DROP SCHEMA '.$this->schema.' CASCADE');
        }
        parent::tearDown();
    }

    private function service(): PasswordReset { return static::getContainer()->get(PasswordReset::class); }
    private function event(): array { return $this->db->fetchAssociative("SELECT e.* FROM notification_events e JOIN password_reset_tokens r ON r.id::text=(e.payload->>'reset_id') WHERE e.type='password_reset' ORDER BY r.expires_at DESC LIMIT 1"); }
    private function link(): string
    {
        $email=static::getContainer()->get(NotificationRenderer::class)->render($this->event(),'person@example.test');
        self::assertStringContainsString('Réinitialisation', $email->getHtmlBody());
        self::assertStringContainsString('ignorez ce courriel', $email->getTextBody());
        preg_match('~https?://[^\s]+/mot-de-passe/reinitialiser\?token=([^\s]+)~', $email->getTextBody(), $matches);
        self::assertArrayHasKey(1,$matches);
        return rawurldecode($matches[1]);
    }
    private function csrf(): string
    {
        $this->client->request('GET','/api/v1/auth/csrf');
        return json_decode($this->client->getResponse()->getContent(),true)['token'];
    }
    private function api(string $operation,array $body,?string $csrf=null): void
    {
        $this->client->request('POST','/api/v1/auth/password-reset/'.$operation,server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$csrf??$this->csrf()],content:json_encode($body));
    }
    private function expectInvalid(string $token): void
    {
        try { $this->service()->confirm($token,'New-test-password-2026!'); self::fail('Invalid link accepted'); }
        catch(ApiProblem $e) {self::assertSame(422,$e->status);self::assertSame('invalid_reset_token',$e->errorCode);}
    }
    public function testGenericRequestCooldownAndNoRedeemableSecretInDatabaseOrQueue(): void
    {
        $this->api('request',['email'=>'PERSON@example.test']);self::assertResponseStatusCodeSame(202);$response=$this->client->getResponse()->getContent();
        $token=$this->link();$this->api('request',['email'=>'person@example.test']);self::assertResponseStatusCodeSame(202);
        self::assertSame($response,$this->client->getResponse()->getContent());
        $this->api('request',['email'=>'absent@example.test']);self::assertResponseStatusCodeSame(202);self::assertSame($response,$this->client->getResponse()->getContent());
        self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM password_reset_tokens'));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM notification_events'));
        $dispatcher=new \App\Notification\NotificationDispatcher($this->db,static::getContainer()->get(\Symfony\Component\Messenger\MessageBusInterface::class),true);
        self::assertSame(1,$dispatcher->dispatch());
        foreach(['password_reset_tokens','notification_events','messenger_messages','audit_logs'] as $table) {
            $data=$this->db->fetchOne('SELECT coalesce(json_agg(t)::text,\'[]\') FROM '.$table.' t');
            self::assertStringNotContainsString($token,$data);self::assertStringNotContainsString('Old-test-password-2026!',$data);
        }
        self::assertSame(hash('sha256',$token),$this->db->fetchOne('SELECT token_hash FROM password_reset_tokens'));
        $this->expectInvalid(json_decode($this->event()['payload'],true)['reset_id']);
    }
    public function testUnknownInactiveAnonymizedAndDevelopmentAccountsDoNotReceiveLinks(): void
    {
        $this->db->executeStatement("UPDATE users SET active=false WHERE email='person@example.test'");
        $this->api('request',['email'=>'person@example.test']);self::assertResponseStatusCodeSame(202);
        $this->db->executeStatement('UPDATE users SET active=true,development_only=true');
        $this->api('request',['email'=>'person@example.test']);self::assertResponseStatusCodeSame(202);
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM password_reset_tokens'));
    }
    public function testApiCsrfValidationAndSharedIpRateLimit(): void
    {
        $this->api('request',['email'=>'person@example.test'],'bad');self::assertResponseStatusCodeSame(403);
        $this->api('request',['email'=>'not-an-email']);self::assertResponseStatusCodeSame(422);
        $this->api('request',['email'=>'person@example.test','unexpected'=>'x']);self::assertResponseStatusCodeSame(422);
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM password_reset_tokens'));
        for($i=0;$i<2;$i++) {$this->api('request',['email'=>'absent@example.test']);self::assertResponseStatusCodeSame(202);}
        $this->api('request',['email'=>'absent@example.test']);self::assertResponseStatusCodeSame(429);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }
    public function testConfirmIsSingleUseHashesPasswordAndRevokesExistingSession(): void
    {
        $old=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('person@example.test');
        $this->client->loginUser($old);$this->service()->request('person@example.test');$token=$this->link();
        $this->api('confirm',['token'=>$token,'password'=>'New-test-password-2026!']);self::assertResponseStatusCodeSame(204);
        $user=$this->db->fetchAssociative('SELECT * FROM users');
        self::assertTrue(password_verify('New-test-password-2026!',$user['password_hash']));self::assertStringStartsWith('$argon2id$',$user['password_hash']);
        self::assertFalse(password_verify('Old-test-password-2026!',$user['password_hash']));self::assertSame(1,(int)$user['auth_version']);self::assertSame(3,$user['role_id']);
        self::assertNotNull($this->db->fetchOne('SELECT used_at FROM password_reset_tokens'));
        $this->client->request('GET','/api/v1/auth/me');self::assertResponseStatusCodeSame(401);
        $this->expectInvalid($token);
        self::assertSame(1,(int)$this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE action='auth.password_reset_completed'"));
        self::assertSame(1,(int)$this->db->fetchOne("SELECT count(*) FROM notification_events WHERE type='information'"));
    }
    public function testExpiredReplacedAndChangedAccountLinksAreRefused(): void
    {
        $this->service()->request('person@example.test');$old=$this->link();
        $this->db->executeStatement("UPDATE password_reset_tokens SET created_at=clock_timestamp()-interval '61 seconds'");
        $this->service()->request('person@example.test');$this->expectInvalid($old);$new=$this->link();self::assertNotSame($old,$new);
        $this->db->executeStatement('UPDATE users SET auth_version=auth_version+1');$this->expectInvalid($new);
        $this->db->executeStatement('UPDATE users SET auth_version=auth_version-1');
        $this->db->executeStatement("UPDATE password_reset_tokens SET expires_at=created_at+interval '1 microsecond'");$this->expectInvalid($new);
    }
    public function testEmailPasswordAndActivityChangesBlockDeliveryAndConfirmation(): void
    {
        $this->service()->request('person@example.test');$token=$this->link();
        foreach(["email='other@example.test'","active=false","development_only=true","password_hash='changed-hash'"] as $change) {
            $this->db->beginTransaction();$this->db->executeStatement('UPDATE users SET '.$change);$this->expectInvalid($token);
            try {static::getContainer()->get(NotificationRenderer::class)->render($this->event(),'person@example.test');self::fail('Stale message sent');}
            catch(\InvalidArgumentException $e) {self::assertSame('password_reset_unavailable',$e->getMessage());}
            $this->db->rollBack();
        }
    }
    public function testBrowserFormWorksWithoutJavascriptAndRemovesUrlSecret(): void
    {
        $this->client->request('GET','/mot-de-passe/oublie');self::assertResponseIsSuccessful();
        $this->client->submitForm('Demander un lien',['email'=>'person@example.test']);self::assertResponseIsSuccessful();self::assertSelectorTextContains('[role="status"]',PasswordReset::ACCEPTED);
        $token=$this->link();$this->client->request('GET','/mot-de-passe/reinitialiser?token='.rawurlencode($token));self::assertResponseRedirects('/mot-de-passe/reinitialiser',303);
        self::assertSame('no-referrer',$this->client->getResponse()->headers->get('Referrer-Policy'));self::assertStringNotContainsString($token,$this->client->getResponse()->getContent());
        $this->client->followRedirect();self::assertResponseIsSuccessful();self::assertStringNotContainsString($token,$this->client->getResponse()->getContent());
        self::assertNull($this->db->fetchOne('SELECT used_at FROM password_reset_tokens'));
        $this->client->submitForm('Enregistrer le mot de passe',['password'=>'New-test-password-2026!','password_confirmation'=>'Mismatch-password!']);self::assertResponseStatusCodeSame(422);
        $this->client->submitForm('Enregistrer le mot de passe',['password'=>'New-test-password-2026!','password_confirmation'=>'New-test-password-2026!']);self::assertResponseRedirects('/connexion',303);
        $this->client->followRedirect();self::assertSelectorTextContains('h1','Connexion');
        $this->api('confirm',['token'=>$token,'password'=>'Another-test-password!']);self::assertResponseStatusCodeSame(422);
    }
    public function testConfirmationRollbackAndInvalidPasswordPreserveUsableToken(): void
    {
        $this->service()->request('person@example.test');$token=$this->link();
        $this->api('confirm',['token'=>$token,'password'=>'short']);self::assertResponseStatusCodeSame(422);
        $this->db->executeStatement("CREATE FUNCTION fail_reset_audit() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.action='auth.password_reset_completed' THEN RAISE EXCEPTION 'synthetic_reset_failure'; END IF; RETURN NEW; END \$\$");
        $this->db->executeStatement('CREATE TRIGGER fail_reset BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION fail_reset_audit()');
        try {$this->service()->confirm($token,'New-test-password-2026!');self::fail('Expected rollback');}catch(\Doctrine\DBAL\Exception) {self::assertTrue(true);}
        self::assertNull($this->db->fetchOne('SELECT used_at FROM password_reset_tokens'));
        self::assertTrue(password_verify('Old-test-password-2026!',$this->db->fetchOne('SELECT password_hash FROM users')));
    }
    public function testDurableDeliveryIsReplaySafeAndAnExpiredLinkIsNotSent(): void
    {
        $this->service()->request('person@example.test');$id=$this->event()['id'];
        $dispatcher=new \App\Notification\NotificationDispatcher($this->db,static::getContainer()->get(\Symfony\Component\Messenger\MessageBusInterface::class),true);
        self::assertSame(1,$dispatcher->dispatch());
        $delivery=new class implements \App\Notification\MailDelivery {
            public int $sent=0;public ?\Symfony\Component\Mime\Email $email=null;
            public function send(\Symfony\Component\Mime\Email $email): void {$this->sent++;$this->email=$email;}
        };
        $handler=new \App\Notification\NotificationDeliveryHandler($this->db,$delivery,static::getContainer()->get(NotificationRenderer::class),new \Psr\Log\NullLogger(),true,'test');
        $handler(new \App\Message\DeliverNotification($id));$handler(new \App\Message\DeliverNotification($id));
        self::assertSame(1,$delivery->sent);self::assertStringContainsString('?token=',$delivery->email->getTextBody());
        $this->db->executeStatement("UPDATE password_reset_tokens SET created_at=clock_timestamp()-interval '61 seconds'");
        $this->service()->request('person@example.test');$expired=$this->event()['id'];
        $this->db->executeStatement("UPDATE password_reset_tokens SET expires_at=created_at+interval '1 microsecond'");$dispatcher->dispatch();
        try {$handler(new \App\Message\DeliverNotification($expired));self::fail('Expired link sent');}catch(\Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException $e) {self::assertSame('notification_render_failed',$e->getMessage());}
        self::assertSame(1,$delivery->sent);
    }
    public function testRequestRollbackAndRendererRejectsCrossAccountSelector(): void
    {
        $this->db->beginTransaction();$this->service()->request('person@example.test');$this->db->rollBack();
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM notification_events'));self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM password_reset_tokens'));
        $this->service()->request('person@example.test');$event=$this->event();
        $other=$this->db->fetchOne("INSERT INTO users(email,name,password_hash,role_id) VALUES ('other@example.test','Other','hash',3) RETURNING id");
        $event['recipient_id']=$other;
        $this->expectException(\InvalidArgumentException::class);
        static::getContainer()->get(NotificationRenderer::class)->render($event,'other@example.test');
    }
}
