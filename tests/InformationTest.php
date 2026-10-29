<?php

declare(strict_types=1);
namespace App\Tests;
use App\Security\StockUserProvider;
use App\Alert\AlertRecipients;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
final class InformationTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private array $users = [], $stores = [];
    private string $article, $inactive, $csrf;
    protected function setUp(): void
    {
        $this->client=static::createClient();$this->client->disableReboot();$this->db=static::getContainer()->get(Connection::class);$this->db->beginTransaction();
        $schema='stk03_'.bin2hex(random_bytes(6));$this->db->executeStatement('CREATE SCHEMA '.$schema);$this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        foreach(explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) { if(trim($sql)!=='') { $this->db->executeStatement($sql); } }
        \App\Tests\CatalogDeletionSchema::apply($this->db);
        $this->db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
        foreach(['20261018140000','20261018200000','20261018223000','20261020220000','20261020230000','20261021000000','20261021010000','20261021020000','20261021030000','20261021040000'] as $version) { require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';$class='DoctrineMigrations\\Version'.$version;$migration=new $class($this->db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) { $this->db->executeStatement($sql->getStatement()); } }
        static::getContainer()->get('messenger.transport.notifications')->setup();
        $hash=password_hash('Entry-test-2026!',PASSWORD_ARGON2ID);
        foreach(['admin'=>1,'manager'=>2,'user'=>3,'unassigned'=>2] as $name=>$role) { $this->users[$name]=$this->db->fetchOne('INSERT INTO users (email,name,password_hash,role_id) VALUES (?,?,?,?) RETURNING id',[$name.'@example.test',$name,$hash,$role]); }
        foreach(['A','B','D'] as $code) { $this->stores[$code]=$this->db->fetchOne('INSERT INTO stores (code,name) VALUES (?,?) RETURNING id',[$code,'Magasin '.$code]); }
        $this->db->executeStatement('UPDATE stores SET active = false WHERE id = ?',[$this->stores['D']]);
        foreach(['manager','user'] as $name) { foreach(['A','D'] as $code) { $this->db->insert('user_stores',['user_id'=>$this->users[$name],'store_id'=>$this->stores[$code]]); } }
        $this->article=$this->db->fetchOne("INSERT INTO articles (sku,name) VALUES ('ENTRY','Article <script>fictif</script>') RETURNING id");$this->inactive=$this->db->fetchOne("INSERT INTO articles (sku,name,active) VALUES ('ARCHIVE','Archivé',false) RETURNING id");
    }
    protected function tearDown(): void { if($this->db->isTransactionActive()) { $this->db->rollBack(); }parent::tearDown(); }
    private function login(string $name='manager'): void
    {
        $this->client->getCookieJar()->clear();$this->client->loginUser(static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier($name.'@example.test'));
        $this->client->request('GET','/api/v1/auth/csrf');$this->csrf=json_decode($this->client->getResponse()->getContent(),true)['token'];
    }




    private function service(): \App\Information\InformationMessages {return static::getContainer()->get(\App\Information\InformationMessages::class);}
    private function actor(): \App\Security\StockUser {return static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('admin@example.test');}
    private function body(array $roles=['MANAGER'],array $stores=[]): \stdClass {return (object)['subject'=>'Objet <script>test</script>','message'=>"Bonjour\nMessage de test",'roles'=>$roles,'store_ids'=>$stores];}
    private function send(\stdClass $body,string $key='campaign-test-01',array $headers=[]): void {$this->client->request('POST','/api/v1/notifications/information',server:array_replace(['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>$key,'REMOTE_ADDR'=>'192.0.2.'.random_int(1,250)],$headers),content:json_encode($body));}
    public function testIntersectionUnionDeduplicationAndPreviewDoesNotWrite(): void {
        $service=$this->service();$actor=$this->actor();
        $preview=$service->preview($actor,$this->body(stores:[$this->stores['A']]),'preview-key-01');self::assertSame([$this->users['manager']],array_column($preview['recipients'],'id'));
        $preview=$service->preview($actor,$this->body([],[$this->stores['A']]),'preview-key-02');self::assertCount(2,$preview['recipients']);self::assertNotContains($this->users['admin'],array_column($preview['recipients'],'id'));
        $this->db->insert('user_stores',['user_id'=>$this->users['manager'],'store_id'=>$this->stores['B']]);
        $preview=$service->preview($actor,$this->body(['MANAGER','USER'],[$this->stores['A'],$this->stores['B']]),'preview-key-03');self::assertCount(2,$preview['recipients']);
        $preview=$service->preview($actor,$this->body(),'preview-key-04');self::assertCount(2,$preview['recipients']);self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM information_campaigns'));self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM notification_events'));
    }
    public function testInactiveAndDevelopmentAccountsExcluded(): void {
        $this->db->executeStatement('UPDATE users SET development_only=true WHERE id=?',[$this->users['manager']]);$this->db->executeStatement('UPDATE users SET active=false WHERE id=?',[$this->users['unassigned']]);
        self::assertCount(0,$this->service()->preview($this->actor(),$this->body(),'preview-empty')['recipients']);
        try {$this->service()->queue($this->actor(),$this->body(),'empty-queue');self::fail();}catch(\App\Api\ApiProblem $e) {self::assertSame(422,$e->status);}self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM information_campaigns'));
    }
    public function testIdempotentQueueAuditAndReceiptSchema(): void {
        $this->login('admin');$body=$this->body(['MANAGER','USER'],[$this->stores['A'],$this->stores['B']]);$this->send($body);self::assertResponseStatusCodeSame(202);
        $first=json_decode($this->client->getResponse()->getContent(),true);self::assertSame(2,$first['data']['recipient_count']);self::assertTrue(static::getContainer()->get(\App\Api\OpenApiContract::class)->valid(json_decode($this->client->getResponse()->getContent()),['$ref'=>'#/components/schemas/QueuedInformationResource']));$location=$this->client->getResponse()->headers->get('Location');
        $body->store_ids=array_reverse($body->store_ids);$body->roles=array_reverse($body->roles);$this->send($body);self::assertResponseStatusCodeSame(202);self::assertSame($first,json_decode($this->client->getResponse()->getContent(),true));
        self::assertSame(1,$this->db->fetchOne('SELECT count(*) FROM information_campaigns'));self::assertSame(2,$this->db->fetchOne('SELECT count(*) FROM notification_events'));self::assertSame(1,$this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE action='information.queued'"));
        $this->client->request('GET',$location);self::assertResponseIsSuccessful();self::assertSelectorNotExists('main script');self::assertSelectorTextContains('main','En attente');
        $body->message='Autre message';$this->send($body);self::assertResponseStatusCodeSame(409);
        $this->client->request('GET','/administration/informations');self::assertSelectorTextContains('main','Objet <script>test</script>');
    }
    public function testPreviewCannotBeForgedOrReusedAfterAudienceChanges(): void {
        $service=$this->service();$actor=$this->actor();$body=$this->body(stores:[$this->stores['A']]);$key='stale-preview-key';$preview=$service->preview($actor,$body,$key);
        $this->db->executeStatement('UPDATE users SET email=? WHERE id=?',['changed@example.test',$this->users['manager']]);
        foreach([[$preview['expires'],$preview['token']],[time()-1,$preview['token']],[time()+1800,'forged']] as [$expiry,$token]) {
            try {$service->queue($actor,$body,$key,true,$expiry,$token);self::fail();}catch(\App\Api\ApiProblem $e) {self::assertSame(409,$e->status);}
        }
        self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM information_campaigns'));$preview=$service->preview($actor,$body,$key);$first=$service->queue($actor,$body,$key,true,$preview['expires'],$preview['token']);
        $this->db->executeStatement('DELETE FROM user_stores WHERE user_id=?',[$this->users['manager']]);self::assertSame($first,$service->queue($actor,$body,$key,true,time()-100,'expired'));
    }
    public function testHtmlRequiresPreviewConfirmationAndValidCsrf(): void {
        $this->login('admin');$url='/administration/informations/nouveau';$crawler=$this->client->request('GET',$url);self::assertResponseIsSuccessful();
        $form=$crawler->selectButton('Prévisualiser et vérifier les destinataires')->form();$values=$form->getPhpValues();$values=array_replace($values,['subject'=>'Bonjour','message'=>'Message','roles'=>['MANAGER'],'action'=>'preview']);
        $this->client->request('POST',$url,$values);self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','manager@example.test');self::assertSelectorTextNotContains('main','user@example.test');self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM information_campaigns'));
        $confirm=$this->client->getCrawler()->selectButton('Confirmer la mise en file')->form()->getPhpValues();$confirm['action']='confirm';
        $this->client->request('POST',$url,$confirm);self::assertResponseStatusCodeSame(422);
        foreach([['_complete'=>''],['unknown'=>'x'],['roles'=>[['bad']]],['_csrf_token'=>'bad']] as $extra) {$this->client->request('POST',$url,array_replace($values,$extra));self::assertResponseStatusCodeSame(isset($extra['_csrf_token'])?403:422);}
        $confirm['confirm']='1';$this->client->request('POST',$url,$confirm);self::assertResponseRedirects(null,303);self::assertSame(1,$this->db->fetchOne('SELECT count(*) FROM information_campaigns'));
    }
    public function testOnlyAdministratorsMaySendOrReadMessages(): void {
        foreach(['manager','user'] as $name) {$this->login($name);foreach(['/administration/informations','/administration/informations/nouveau','/administration/informations/00000000-0000-0000-0000-000000000000'] as $url) {$this->client->request('GET',$url);self::assertResponseStatusCodeSame(403);}$this->send($this->body());self::assertResponseStatusCodeSame(403);}
        self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM information_campaigns'));
    }
    public function testDeliveryRechecksOriginalAudienceAndAddress(): void {
        $service=$this->service();$result=$service->queue($this->actor(),$this->body(['MANAGER','USER'],[$this->stores['A']]),'delivery-key');
        $this->db->executeStatement("UPDATE notification_events SET status='queued'");$this->db->executeStatement('DELETE FROM user_stores WHERE user_id=?',[$this->users['manager']]);
        $fake=new class implements \App\Notification\MailDelivery {public int $sent=0;public function send(\Symfony\Component\Mime\Email $email): void {$this->sent++;}};
        $handler=new \App\Notification\NotificationDeliveryHandler($this->db,$fake,static::getContainer()->get(\App\Notification\NotificationRenderer::class),new \Psr\Log\NullLogger(),true,'test');
        foreach($result['data']['event_ids'] as $event) {try {$handler(new \App\Message\DeliverNotification($event));}catch(\RuntimeException $e) {self::assertSame('recipient_unavailable',$e->getMessage());}}self::assertSame(1,$fake->sent);
        $result=$service->queue($this->actor(),$this->body(['USER']),'delivery-email');$this->db->executeStatement("UPDATE notification_events SET status='queued' WHERE id=?",[$result['data']['event_ids'][0]]);$this->db->executeStatement('UPDATE users SET email=? WHERE id=?',['new@example.test',$this->users['user']]);self::assertFalse($this->db->fetchOne('SELECT information_recipient_eligible(?)',[$result['data']['event_ids'][0]]));
    }
    public function testAuditFailureRollsBackEntireCampaign(): void {
        $this->db->executeStatement("CREATE FUNCTION fail_information_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Injected information audit failure'; END $$");$this->db->executeStatement('CREATE TRIGGER information_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION fail_information_audit()');
        try {$this->service()->queue($this->actor(),$this->body(),'rollback-key');self::fail();}catch(\Doctrine\DBAL\Exception $e) {self::assertStringContainsString('Injected information audit failure',$e->getMessage());}
        foreach(['information_campaigns','information_campaign_recipients','notification_events'] as $table) {self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM '.$table));}
    }
    public function testInputValidationAndCsrfRejectBeforeQueueing(): void {
        $this->login('admin');foreach([(object)['subject'=>'','message'=>'x','roles'=>['USER']],(object)['subject'=>'x','message'=>'x'],(object)['subject'=>str_repeat('é',201),'message'=>'x','roles'=>['USER']],(object)['subject'=>"Hello\r\nHeader",'message'=>'x','roles'=>['USER']],(object)['subject'=>'x','message'=>'x','roles'=>['UNKNOWN']]] as $body) {$this->send($body,bin2hex(random_bytes(16)));self::assertResponseStatusCodeSame(422);}
        $this->send($this->body(),headers:['HTTP_X_CSRF_TOKEN'=>'bad']);self::assertResponseStatusCodeSame(403);self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM information_campaigns'));
    }
    public function testEveryEligibilityChangeBlocksDelivery(): void {
        $service=$this->service();$actor=$this->actor();$id=$this->users['manager'];$body=$this->body(stores:[$this->stores['A']]);
        foreach(['role','email','inactive','development','membership','store'] as $change) {
            $result=$service->queue($actor,$body,'eligibility-'.$change);$event=$result['data']['event_ids'][0];self::assertTrue($this->db->fetchOne('SELECT information_recipient_eligible(?)',[$event]));
            $this->db->beginTransaction();
            match($change) {
                'role'=>$this->db->executeStatement('UPDATE users SET role_id=3 WHERE id=?',[$id]),
                'email'=>$this->db->executeStatement('UPDATE users SET email=? WHERE id=?',['changed@example.test',$id]),
                'inactive'=>$this->db->executeStatement('UPDATE users SET active=false WHERE id=?',[$id]),
                'development'=>$this->db->executeStatement('UPDATE users SET development_only=true WHERE id=?',[$id]),
                'membership'=>$this->db->executeStatement('DELETE FROM user_stores WHERE user_id=?',[$id]),
                'store'=>$this->db->executeStatement('UPDATE stores SET active=false WHERE id=?',[$this->stores['A']])
            };
            self::assertFalse($this->db->fetchOne('SELECT information_recipient_eligible(?)',[$event]));$this->db->rollBack();
        }
    }
    public function testPreviewRejectsModifiedMessageAndExpiredSignature(): void {
        $service=$this->service();$actor=$this->actor();$body=$this->body();$preview=$service->preview($actor,$body,'signed-body-key');$body->message='Modifié';
        try {$service->queue($actor,$body,'signed-body-key',true,$preview['expires'],$preview['token']);self::fail();}catch(\App\Api\ApiProblem $e) {self::assertSame(409,$e->status);}
        self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM information_campaigns'));
    }
    public function testUnicodeLimitsAreCharactersAndHistoryCannotBeRewritten(): void {
        $body=$this->body(['USER']);$body->subject=str_repeat('é',200);$body->message=str_repeat('à',10000);$result=$this->service()->queue($this->actor(),$body,'unicode-message');self::assertSame(1,$result['data']['recipient_count']);
        foreach(["UPDATE information_campaigns SET subject='Changed'",'DELETE FROM information_campaign_recipients'] as $sql) {
            try {$this->db->transactional(fn()=>$this->db->executeStatement($sql));self::fail();}catch(\Doctrine\DBAL\Exception $e) {self::assertStringContainsString('immuable',strtolower($e->getMessage()));}
        }
        require_once dirname(__DIR__).'/migrations/Version20261021040000.php';$migration=new \DoctrineMigrations\Version20261021040000($this->db,new \Psr\Log\NullLogger());$migration->down(new \Doctrine\DBAL\Schema\Schema());
        try {$this->db->transactional(function() use($migration): void {foreach($migration->getSql() as $sql) {$this->db->executeStatement($sql->getStatement());}});self::fail();}catch(\Doctrine\DBAL\Exception $e) {self::assertStringContainsString('campagnes',$e->getMessage());}
        self::assertSame(1,$this->db->fetchOne('SELECT count(*) FROM information_campaigns'));
    }
    public function testSensitiveRouteRateLimitAndAnonymousAccess(): void {
        $this->login('admin');for($i=0;$i<6;$i++) {$this->send($this->body(),'limit-test-'.$i,['REMOTE_ADDR'=>'198.51.100.94']);self::assertResponseStatusCodeSame($i<5?202:429);}
        self::assertSame(5,$this->db->fetchOne('SELECT count(*) FROM information_campaigns'));
        $this->client->getCookieJar()->clear();$this->client->request('GET','/api/v1/auth/csrf');$this->csrf=json_decode($this->client->getResponse()->getContent(),true)['token'];$this->send($this->body());self::assertResponseStatusCodeSame(401);
    }

}
