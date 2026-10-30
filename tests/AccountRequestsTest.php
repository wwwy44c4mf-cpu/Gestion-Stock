<?php

declare(strict_types=1);
namespace App\Tests;

use App\Administration\AccountRequests;
use App\Api\ApiProblem;
use App\Notification\{NotificationRenderer,NotificationDeliveryHandler,MailDelivery};
use App\Security\{PasswordReset,StockUser,StockUserProvider};
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class AccountRequestsTest extends WebTestCase
{
    private Connection $db;private string $schema;private KernelBrowser $client;private StockUser $admin;
    protected function setUp(): void
    {
        self::ensureKernelShutdown();$this->client=static::createClient();$this->client->disableReboot();
        $this->db=static::getContainer()->get(Connection::class);$this->schema='usr03_'.bin2hex(random_bytes(6));
        $this->db->executeStatement('CREATE SCHEMA '.$this->schema);$this->db->executeStatement('SET search_path TO '.$this->schema);
        $factory=DependencyFactory::fromConnection(new ConfigurationArray(['migrations_paths'=>['DoctrineMigrations'=>dirname(__DIR__).'/migrations'],'all_or_nothing'=>true]),new ExistingConnection($this->db));
        $command=new MigrateCommand($factory);$command->setName('doctrine:migrations:migrate');$input=new ArrayInput([]);$input->setInteractive(false);$output=new BufferedOutput();
        self::assertSame(0,$command->run($input,$output),$output->fetch());$this->db->beginTransaction();
        $this->db->executeStatement("INSERT INTO users(email,name,password_hash,role_id) VALUES ('admin@example.test','Admin','unused',1),('manager@example.test','Manager','unused',2)");
        $this->admin=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('admin@example.test');
        $this->client->setServerParameter('REMOTE_ADDR','10.'.random_int(1,254).'.'.random_int(1,254).'.'.random_int(1,254));
        // Initialize request globals before rendering a simulated mail in this shared test kernel.
        $this->client->request('GET','/api/v1/auth/csrf');
    }
    protected function tearDown(): void
    {
        if(isset($this->db)) {while($this->db->isTransactionActive()) {$this->db->rollBack();}$this->db->executeStatement('SET search_path TO public');$this->db->executeStatement('DROP SCHEMA '.$this->schema.' CASCADE');}parent::tearDown();
    }
    private function service(): AccountRequests {return static::getContainer()->get(AccountRequests::class);}
    private function submit(string $email='person@example.test'): string
    {
        $this->service()->request((object)['first_name'=>'Camille','last_name'=>'Exemple','email'=>$email]);
        return (string)$this->db->fetchOne('SELECT id FROM account_requests ORDER BY created_at DESC LIMIT 1');
    }
    private function approve(string $id): array {return $this->service()->decide($this->admin,$id,'approve',(object)['role'=>'USER','store_ids'=>[]]);}
    private function invitation(): array {return $this->db->fetchAssociative("SELECT e.* FROM notification_events e JOIN password_reset_tokens r ON r.id::text=e.payload->>'reset_id' WHERE e.type='account_activation' AND r.used_at IS NULL ORDER BY r.expires_at DESC LIMIT 1");}
    private function token(): string
    {
        $event=$this->invitation();$email=static::getContainer()->get(NotificationRenderer::class)->render($event,'person@example.test');
        self::assertStringContainsString('24 heures',$email->getTextBody());self::assertStringContainsString('Créer mon mot de passe',$email->getHtmlBody());
        preg_match('~token=([^\s]+)~',$email->getTextBody(),$matches);self::assertArrayHasKey(1,$matches);return rawurldecode($matches[1]);
    }
    private function invalid(string $token): void
    {
        try {static::getContainer()->get(PasswordReset::class)->confirm($token,'Fixture-password-2026!');self::fail('Invalid activation accepted');}
        catch(ApiProblem $e) {self::assertSame('invalid_reset_token',$e->errorCode);}
    }
    public function testPublicFormCollectsNamesAndEmailWithoutGrantingAccess(): void
    {
        $this->client->request('GET','/connexion');self::assertSelectorExists('a[href="/demander-un-compte"]');
        $this->client->clickLink('Demander un compte');self::assertResponseIsSuccessful();
        $this->client->submitForm('Envoyer ma demande',['first_name'=>'Camille','last_name'=>'Exemple','email'=>'person@example.test']);
        self::assertResponseIsSuccessful();self::assertSelectorTextContains('[role="status"]',AccountRequests::ACCEPTED);
        self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM account_requests'));
        self::assertSame(2,(int)$this->db->fetchOne('SELECT count(*) FROM users'));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM notification_events'));
        $event=$this->db->fetchAssociative('SELECT * FROM notification_events');self::assertSame($this->admin->id,$event['recipient_id']);
        self::assertStringNotContainsString('Camille',$event['payload']);self::assertStringNotContainsString('person@example.test',$event['payload']);
    }
    public function testPublicApiUsesGenericResponseForDuplicateAndExistingAccountAndRejectsExtraFields(): void
    {
        $this->client->request('GET','/api/v1/auth/csrf');$csrf=json_decode($this->client->getResponse()->getContent(),true)['token'];
        $send=function(array $body,string $token) { $this->client->request('POST','/api/v1/auth/account-requests',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$token],content:json_encode($body)); };
        $body=['first_name'=>'Camille','last_name'=>'Exemple','email'=>'person@example.test'];
        $send($body,'bad');self::assertResponseStatusCodeSame(403);
        $send($body,$csrf);self::assertResponseStatusCodeSame(202);$response=$this->client->getResponse()->getContent();
        $send($body,$csrf);self::assertResponseStatusCodeSame(202);self::assertSame($response,$this->client->getResponse()->getContent());
        $send(array_replace($body,['email'=>'admin@example.test']),$csrf);self::assertResponseStatusCodeSame(202);self::assertSame($response,$this->client->getResponse()->getContent());
        $send($body+['role'=>'ADMIN'],$csrf);self::assertResponseStatusCodeSame(422);
        self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM account_requests'));
    }
    public function testOnlyAdminReviewsAndCsrfIsRequired(): void
    {
        $id=$this->submit();$manager=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('manager@example.test');
        $this->client->loginUser($manager);$this->client->request('GET','/administration/demandes-comptes');self::assertResponseStatusCodeSame(403);
        try {$this->service()->decide($manager,$id,'approve',(object)['role'=>'ADMIN','store_ids'=>[]]);self::fail('Manager approved');}catch(ApiProblem $e) {self::assertSame(403,$e->status);}
        $this->client->loginUser($this->admin);$this->client->request('POST','/administration/demandes-comptes/'.$id,['decision'=>'approve','role'=>'USER']);self::assertResponseStatusCodeSame(403);
        self::assertSame('pending',$this->db->fetchOne('SELECT status FROM account_requests'));
    }
    public function testApprovalAndActivationAreSingleUseAndNoPasswordOrUsableTokenStoredInOutbox(): void
    {
        $id=$this->submit();$row=$this->approve($id);self::assertSame('approved',$row['status']);
        self::assertFalse($this->db->fetchOne('SELECT active FROM users WHERE id=?',[$row['user_id']]));
        $passwords=static::getContainer()->get(PasswordReset::class);$passwords->request('person@example.test');
        self::assertSame(0,(int)$this->db->fetchOne("SELECT count(*) FROM password_reset_tokens WHERE purpose='reset'"));
        $token=$this->token();$this->approve($id);self::assertSame(1,(int)$this->db->fetchOne("SELECT count(*) FROM password_reset_tokens"));
        foreach(['password_reset_tokens','notification_events','audit_logs','user_creation_requests'] as $table) {self::assertStringNotContainsString($token,$this->db->fetchOne('SELECT json_agg(t)::text FROM '.$table.' t'));}
        $passwords->confirm($token,'Fixture-password-2026!');
        $user=$this->db->fetchAssociative('SELECT * FROM users WHERE id=?',[$row['user_id']]);self::assertTrue($user['active']);self::assertTrue(password_verify('Fixture-password-2026!',$user['password_hash']));
        self::assertNotNull($this->db->fetchOne('SELECT activated_at FROM account_requests'));$this->invalid($token);
        try {$this->service()->decide($this->admin,$id,'resend',new \stdClass());self::fail('Activated account reinvited');}catch(ApiProblem $e) {self::assertSame(409,$e->status);}
    }
    public function testExpiredAndSupersededInvitationsAreRefusedAndCrossPurposeCannotBeDelivered(): void
    {
        $id=$this->submit();$this->approve($id);$old=$this->token();
        $this->db->executeStatement("UPDATE password_reset_tokens SET created_at=clock_timestamp()-interval '61 seconds'");
        $this->service()->decide($this->admin,$id,'resend',new \stdClass());$new=$this->token();self::assertNotSame($old,$new);$this->invalid($old);
        $event=$this->invitation();$event['type']='password_reset';
        try {static::getContainer()->get(NotificationRenderer::class)->render($event,'person@example.test');self::fail('Wrong purpose sent');}catch(\InvalidArgumentException) {self::assertTrue(true);}
        $this->db->executeStatement("UPDATE password_reset_tokens SET created_at=clock_timestamp()-interval '2 days',expires_at=clock_timestamp()-interval '1 day'");$this->invalid($new);
    }
    public function testAdminBrowserApprovalSetsSelectedRoleAndStores(): void
    {
        $id=$this->submit();$store=$this->db->fetchOne("INSERT INTO stores(code,name) VALUES ('USR03','Fictif') RETURNING id");
        $this->client->loginUser($this->admin);$this->client->request('GET','/administration/demandes-comptes/'.$id);self::assertResponseIsSuccessful();
        $this->client->submitForm('Approuver et inviter',['role'=>'MANAGER','store_ids'=>[$store]]);self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();self::assertSelectorTextContains('main','doit encore créer son mot de passe');
        $row=$this->service()->get($this->admin,$id);self::assertSame(2,(int)$this->db->fetchOne('SELECT role_id FROM users WHERE id=?',[$row['user_id']]));self::assertSame($store,$this->db->fetchOne('SELECT store_id FROM user_stores WHERE user_id=?',[$row['user_id']]));
    }
    public function testRejectedRequestCreatesNoUserAndConflictingApprovalIsRefused(): void
    {
        $id=$this->submit();$this->service()->decide($this->admin,$id,'reject',new \stdClass());
        self::assertSame(2,(int)$this->db->fetchOne('SELECT count(*) FROM users'));self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM password_reset_tokens'));
        try {$this->approve($id);self::fail('Rejected request approved');}catch(ApiProblem $e) {self::assertSame(409,$e->status);}
    }
    public function testInvitationBrowserFormActivatesAndAllowsNormalLogin(): void
    {
        $this->approve($this->submit());$token=$this->token();
        $this->client->request('GET','/mot-de-passe/reinitialiser?token='.rawurlencode($token));self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();self::assertSelectorTextContains('h1','Créer mon mot de passe');
        self::assertStringNotContainsString($token,$this->client->getResponse()->getContent());
        self::assertSelectorTextContains('main','renvoyer votre invitation');
        $this->client->submitForm('Enregistrer le mot de passe',['password'=>'Fixture-password-2026!','password_confirmation'=>'Fixture-password-2026!']);self::assertResponseRedirects('/connexion',303);
        $this->client->request('GET','/api/v1/auth/csrf');$csrf=json_decode($this->client->getResponse()->getContent(),true)['token'];
        $this->client->request('POST','/api/v1/auth/login',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$csrf],content:json_encode(['email'=>'person@example.test','password'=>'Fixture-password-2026!']));
        self::assertResponseIsSuccessful();self::assertSame('USER',json_decode($this->client->getResponse()->getContent(),true)['data']['role']);
    }
    public function testApprovalRollsBackAccountAndMailWhenAuditFails(): void
    {
        $id=$this->submit();
        $this->db->executeStatement("CREATE FUNCTION fail_account_review() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.action='account_request.approved' THEN RAISE EXCEPTION 'synthetic_failure'; END IF; RETURN NEW; END \$\$");
        $this->db->executeStatement('CREATE TRIGGER fail_account_review BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION fail_account_review()');
        try {$this->approve($id);self::fail('Failed approval committed');}catch(\Doctrine\DBAL\Exception) {self::assertTrue(true);}
        self::assertSame('pending',$this->db->fetchOne('SELECT status FROM account_requests'));
        self::assertSame(2,(int)$this->db->fetchOne('SELECT count(*) FROM users'));
        self::assertSame(0,(int)$this->db->fetchOne("SELECT count(*) FROM notification_events WHERE type='account_activation'"));
    }
    public function testInvitationCanUseNeutralMailDomain(): void
    {
        $this->approve($this->submit());
        $renderer=new NotificationRenderer(static::getContainer()->get(\Twig\Environment::class),new \App\Notification\NotificationPayload(),'nobody@example.invalid','https://origin.example.test','prod',static::getContainer()->get(PasswordReset::class),'https://neutral.example.test');
        $mail=$renderer->render($this->invitation(),'person@example.test');self::assertStringContainsString('https://neutral.example.test/mot-de-passe/',$mail->getTextBody());self::assertStringNotContainsString('origin.example.test',$mail->getTextBody());
    }

    public function testActivationMailCanBeDeliveredToInactiveApprovedAccountOnly(): void
    {
        $this->approve($this->submit());$event=$this->invitation();
        $this->db->executeStatement("UPDATE notification_events SET status='queued' WHERE id=?",[$event['id']]);
        $delivery=new class implements MailDelivery {public int $count=0;public function send(\Symfony\Component\Mime\Email $email): void {$this->count++;}};
        $handler=new NotificationDeliveryHandler($this->db,$delivery,static::getContainer()->get(NotificationRenderer::class),new \Psr\Log\NullLogger(),true,'test');
        $handler(new \App\Message\DeliverNotification($event['id']));$handler(new \App\Message\DeliverNotification($event['id']));self::assertSame(1,$delivery->count);
    }
}
