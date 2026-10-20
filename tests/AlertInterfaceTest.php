<?php

declare(strict_types=1);
namespace App\Tests;
use App\Security\StockUserProvider;
use App\Alert\AlertRecipients;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
final class AlertInterfaceTest extends WebTestCase
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
        $this->db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
        foreach(['20261018140000','20261018200000','20261018223000','20261020220000','20261020230000','20261021000000','20261021010000','20261021020000','20261021030000'] as $version) { require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';$class='DoctrineMigrations\\Version'.$version;$migration=new $class($this->db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) { $this->db->executeStatement($sql->getStatement()); } }
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




    private function url(string $store='A'): string {return '/api/v1/stores/'.$this->stores[$store].'/alert-recipients';}
    private function formUrl(): string {return '/alertes/'.$this->stores['A'].'/destinataires';}
    private function put(array $rows=[],bool $defaults=false,string $store='A',?string $etag=null): void {$headers=['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf];if($etag!==null) {$headers['HTTP_IF_MATCH']=$etag;}$this->client->request('PUT',$this->url($store),server:$headers,content:json_encode(['recipients'=>$rows,'use_defaults'=>$defaults]));}
    private function item(string $user,string $type): array {return ['user_id'=>$this->users[$user],'alert_type'=>$type];}
    private function seed(): void {
        foreach(['A','B','D'] as $store) {$this->db->insert('stock_levels',['article_id'=>$this->article,'store_id'=>$this->stores[$store],'quantity'=>0,'low_threshold'=>5,'critical_threshold'=>0]);}
        $this->db->executeStatement('UPDATE stock_levels SET quantity=3 WHERE store_id=?',[$this->stores['A']]);
    }
    private function response(): array {return json_decode($this->client->getResponse()->getContent(),true,flags:JSON_THROW_ON_ERROR);}
    public function testScopedListFiltersStablePaginationAndHistory(): void {
        $this->seed();$this->login();$this->client->request('GET','/api/v1/alerts');self::assertResponseIsSuccessful();$page=$this->response();self::assertSame(2,$page['meta']['total']);foreach($page['data'] as $row) {self::assertSame($this->stores['A'],$row['store_id']);}
        $this->client->request('GET','/api/v1/alerts?status=active&level=low&limit=1');self::assertSame(1,$this->response()['meta']['total']);self::assertSame('low',$this->response()['data'][0]['level']);
        $this->client->request('GET','/api/v1/alerts?store_id='.$this->stores['B']);self::assertResponseStatusCodeSame(404);$this->login('admin');$this->client->request('GET','/api/v1/alerts?sort=level&order=desc&limit=1');self::assertSame(4,$this->response()['meta']['total']);self::assertSame('critical',$this->response()['data'][0]['level']);$first=$this->response()['data'][0]['id'];$this->client->request('GET','/api/v1/alerts?sort=level&order=desc&limit=1&page=2');self::assertNotSame($first,$this->response()['data'][0]['id']);
    }
    public function testUiUsesActiveDefaultEscapesNamesAndExcludesOutsideStores(): void {
        $this->seed();$this->login();$before=$this->db->fetchOne('SELECT count(*) FROM stock_alert_transitions');$this->client->request('GET','/alertes');self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','1 alerte');self::assertSelectorNotExists('main script');self::assertSelectorNotExists('option[value="'.$this->stores['B'].'"]');self::assertSelectorExists('nav a[aria-current="page"][href="/alertes"]');
        $this->client->request('GET','/alertes?status=resolved');self::assertResponseIsSuccessful();self::assertSelectorTextContains('tbody','Résolue');self::assertSame($before,$this->db->fetchOne('SELECT count(*) FROM stock_alert_transitions'));
        $this->login('admin');$this->client->request('GET','/alertes?store_id='.$this->stores['D']);self::assertResponseIsSuccessful();self::assertSelectorNotExists('a[href="/alertes/'.$this->stores['D'].'/destinataires"]');
    }
    public function testDefaultRecipientsAndPrivateEligibleOptions(): void {
        $this->login();$this->client->request('GET',$this->url());self::assertResponseIsSuccessful();self::assertSame(6,$this->response()['meta']['total']);self::assertSame('default',$this->client->getResponse()->headers->get('X-Recipients-Mode'));
        $this->client->request('GET','/api/v1/stores/'.$this->stores['A'].'/alert-recipient-options?limit=1');self::assertResponseIsSuccessful();$page=$this->response();self::assertSame(2,$page['meta']['total']);self::assertCount(1,$page['data']);self::assertStringNotContainsString('@example.test',$this->client->getResponse()->getContent());self::assertStringNotContainsString($this->users['unassigned'],$this->client->getResponse()->getContent());
    }
    public function testCustomListsEmptyListRestoreAndNoDuplicateAudit(): void {
        $this->login();$rows=[$this->item('admin','low'),$this->item('manager','critical')];$this->put($rows);self::assertResponseIsSuccessful();$version=$this->db->fetchOne('SELECT version FROM alert_recipient_configurations');$this->put($rows);self::assertResponseIsSuccessful();self::assertSame($version,$this->db->fetchOne('SELECT version FROM alert_recipient_configurations'));self::assertSame(1,$this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE action='alert.recipients_updated'"));
        $this->client->request('GET',$this->url());self::assertSame(2,$this->response()['meta']['total']);self::assertSame('custom',$this->client->getResponse()->headers->get('X-Recipients-Mode'));
        $this->put();$this->client->request('GET',$this->url());self::assertSame(0,$this->response()['meta']['total']);self::assertSame('custom',$this->client->getResponse()->headers->get('X-Recipients-Mode'));$this->put(defaults:true);self::assertResponseIsSuccessful();$this->client->request('GET',$this->url());self::assertSame(6,$this->response()['meta']['total']);self::assertSame('default',$this->client->getResponse()->headers->get('X-Recipients-Mode'));
    }
    public function testConfigurationControlsFutureThresholdNotificationsWithoutReplayingHistory(): void {
        $this->login();$this->put([$this->item('admin','low'),$this->item('manager','critical')]);$this->db->insert('stock_levels',['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>10,'low_threshold'=>5,'critical_threshold'=>2]);$this->db->executeStatement('UPDATE stock_levels SET quantity=4');self::assertSame([$this->users['admin']],$this->db->fetchFirstColumn('SELECT recipient_id FROM notification_events'));$this->db->executeStatement('UPDATE stock_levels SET quantity=2');self::assertSame(1,$this->db->fetchOne('SELECT count(*) FROM notification_events WHERE recipient_id=?',[$this->users['manager']]));
        $this->put(defaults:true);self::assertSame(2,$this->db->fetchOne('SELECT count(*) FROM notification_events'));$this->db->executeStatement('UPDATE stock_levels SET quantity=10');$this->db->executeStatement('UPDATE stock_levels SET quantity=2');self::assertSame(4,$this->db->fetchOne('SELECT count(*) FROM notification_events'));
    }
    public function testRecipientRemovedAfterQueueingIsNotSentAndKeptRecipientCanReceive(): void {
        $this->db->insert('stock_levels',['article_id'=>$this->article,'store_id'=>$this->stores['A'],'low_threshold'=>0,'critical_threshold'=>0]);$this->db->executeStatement("UPDATE notification_events SET status='queued'");$this->login();$this->put([$this->item('manager','critical')]);
        $fake=new class implements \App\Notification\MailDelivery {public int $sent=0;public function send(\Symfony\Component\Mime\Email $email): void {$this->sent++;}};$handler=new \App\Notification\NotificationDeliveryHandler($this->db,$fake,static::getContainer()->get(\App\Notification\NotificationRenderer::class),new \Psr\Log\NullLogger(),true,'test');
        $events=$this->db->fetchAllAssociative('SELECT id,recipient_id FROM notification_events');foreach($events as $event) {try {$handler(new \App\Message\DeliverNotification($event['id']));self::assertSame($this->users['manager'],$event['recipient_id']);}catch(\RuntimeException $e) {self::assertSame('recipient_unavailable',$e->getMessage());self::assertSame($this->users['admin'],$event['recipient_id']);}}self::assertSame(1,$fake->sent);
    }
    public function testRightsInactiveStoresAndUnknownIdsCannotLeak(): void {
        $this->login('user');$this->client->request('GET','/api/v1/alerts');self::assertResponseStatusCodeSame(403);$this->put();self::assertResponseStatusCodeSame(403);$this->client->request('GET','/alertes');self::assertResponseStatusCodeSame(403);
        $this->login();$this->put(store:'B');self::assertResponseStatusCodeSame(404);$this->client->request('GET',$this->url('B'));self::assertResponseStatusCodeSame(404);$this->client->request('GET',$this->url('D'));self::assertResponseStatusCodeSame(404);
        $this->login('admin');$this->put(store:'D');self::assertResponseStatusCodeSame(404);$this->client->request('GET',$this->url('D'));self::assertResponseIsSuccessful();self::assertSame(0,$this->response()['meta']['total']);
        $this->client->request('GET','/api/v1/stores/not-a-uuid/alert-recipients');self::assertResponseStatusCodeSame(404);
    }
    public function testForgeryDuplicatesInvalidTypesAndCsrfAreRejectedWithoutChange(): void {
        $this->login();foreach([[$this->item('user','low')],[$this->item('unassigned','low')],[$this->item('admin','invalid')],[$this->item('admin','low'),$this->item('admin','low')]] as $rows) {$this->put($rows);self::assertResponseStatusCodeSame(422);}
        $this->put([$this->item('admin','low')],true);self::assertResponseStatusCodeSame(422);
        $this->client->request('PUT',$this->url(),server:['CONTENT_TYPE'=>'application/json'],content:'{"recipients":[]}');self::assertResponseStatusCodeSame(403);
        $this->client->request('GET','/api/v1/alerts?unknown=1');self::assertResponseStatusCodeSame(422);$this->client->request('GET','/api/v1/alerts?store_id[]=x');self::assertResponseStatusCodeSame(422);self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM alert_recipient_configurations'));
    }
    public function testFormSaveStaleProtectionAndRestore(): void {
        $this->login();$crawler=$this->client->request('GET',$this->formUrl());self::assertResponseIsSuccessful();$form=$crawler->selectButton('Enregistrer les destinataires')->form();$old=$form->getPhpValues();$values=$old;$values['recipients']=[$this->users['manager'].':critical'];$this->client->request('POST',$this->formUrl(),$values);self::assertResponseRedirects($this->formUrl(),303);
        $this->client->request('POST',$this->formUrl(),$old);self::assertResponseStatusCodeSame(409);self::assertSelectorTextContains('[role="alert"]','changé');self::assertSame(1,$this->db->fetchOne('SELECT count(*) FROM alert_recipients'));
        $crawler=$this->client->request('GET',$this->formUrl());$this->client->submit($crawler->selectButton('Rétablir la liste par défaut')->form());self::assertResponseRedirects($this->formUrl(),303);self::assertTrue($this->db->fetchOne('SELECT use_defaults FROM alert_recipient_configurations'));
    }
    public function testEtagAndLiveEligibilityPreventStaleOverwrite(): void {
        $this->login('admin');$this->client->request('GET',$this->url());$etag=$this->client->getResponse()->headers->get('ETag');$this->db->executeStatement('UPDATE users SET active=false WHERE id=?',[$this->users['manager']]);$this->put([],false,etag:$etag);self::assertResponseStatusCodeSame(409);$this->put([$this->item('manager','critical')]);self::assertResponseStatusCodeSame(422);self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM alert_recipient_configurations'));
    }
    public function testAuditFailureRollsBackSettingsAndExplicitRecipients(): void {
        $this->db->executeStatement("CREATE FUNCTION fail_recipient_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Injected recipient audit failure'; END $$");$this->db->executeStatement('CREATE TRIGGER recipient_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION fail_recipient_audit()');$actor=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('admin@example.test');
        try {static::getContainer()->get(AlertRecipients::class)->replace($actor,$this->stores['A'],(object)['recipients'=>[(object)$this->item('manager','critical')]]);self::fail('Panne attendue');}catch(\Doctrine\DBAL\Exception $e) {self::assertStringContainsString('Injected recipient audit failure',$e->getMessage());}self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM alert_recipient_configurations'));self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM alert_recipients'));
    }
    public function testMalformedFormsCsrfAndLostAssignmentCannotWrite(): void {
        $this->login();$crawler=$this->client->request('GET',$this->formUrl());$values=$crawler->selectButton('Enregistrer les destinataires')->form()->getPhpValues();
        foreach([['_complete'=>''],['unknown'=>'x'],['recipients'=>[['nested']]],['action'=>'invalid']] as $extra) {$this->client->request('POST',$this->formUrl(),array_replace($values,$extra));self::assertResponseStatusCodeSame(422);}
        $this->client->request('POST',$this->formUrl(),array_replace($values,['_csrf_token'=>'bad']));self::assertResponseStatusCodeSame(403);$this->db->executeStatement('DELETE FROM user_stores WHERE user_id=? AND store_id=?',[$this->users['manager'],$this->stores['A']]);$this->client->request('POST',$this->formUrl(),$values);self::assertResponseStatusCodeSame(404);self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM alert_recipient_configurations'));
    }
    public function testApiResponsesMatchPublishedSchemasAndAnonymousCannotRead(): void {
        $this->login();$contract=static::getContainer()->get(\App\Api\OpenApiContract::class);
        foreach([['/api/v1/alerts','AlertPage'],[$this->url(),'AlertRecipientPage'],['/api/v1/stores/'.$this->stores['A'].'/alert-recipient-options','AlertRecipientOptionPage']] as [$url,$schema]) {$this->client->request('GET',$url);self::assertResponseIsSuccessful();self::assertTrue($contract->valid(json_decode($this->client->getResponse()->getContent()),['$ref'=>'#/components/schemas/'.$schema]));}
        $this->put([$this->item('manager','critical')]);self::assertResponseIsSuccessful();self::assertTrue($contract->valid(json_decode($this->client->getResponse()->getContent()),['$ref'=>'#/components/schemas/RecipientsWrite']));
        $this->client->getCookieJar()->clear();$this->client->request('GET','/api/v1/alerts');self::assertResponseStatusCodeSame(401);
    }

}
