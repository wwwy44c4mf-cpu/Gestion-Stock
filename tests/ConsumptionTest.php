<?php

declare(strict_types=1);

namespace App\Tests;

use App\Api\OpenApiContract;
use App\Security\StockUserProvider;
use App\Stock\StockMovementEngine;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ConsumptionTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private array $users=[], $stores=[];
    private string $article, $inactive, $csrf;
    protected function setUp(): void
    {
        $this->client=static::createClient();$this->client->disableReboot();$this->db=static::getContainer()->get(Connection::class);$this->db->beginTransaction();
        $schema='stk04_'.bin2hex(random_bytes(6));$this->db->executeStatement('CREATE SCHEMA '.$schema);$this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        foreach(explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) { if(trim($sql)!=='') { $this->db->executeStatement($sql); } }
        $this->db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
        foreach(['20261018140000','20261018200000'] as $version) { require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';$class='DoctrineMigrations\\Version'.$version;$migration=new $class($this->db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) { $this->db->executeStatement($sql->getStatement()); } }
        $hash=password_hash('Consumption-test-2026!',PASSWORD_ARGON2ID);
        foreach(['admin'=>1,'manager'=>2,'user'=>3,'tech'=>3,'inactive'=>3,'dev'=>3,'unassigned'=>2] as $name=>$role) { $this->users[$name]=$this->db->fetchOne('INSERT INTO users (email,name,password_hash,role_id) VALUES (?,?,?,?) RETURNING id',[$name.'@example.test','Nom '.$name,$hash,$role]); }
        $this->db->executeStatement('UPDATE users SET active = false WHERE id = ?',[$this->users['inactive']]);$this->db->executeStatement('UPDATE users SET development_only = true WHERE id = ?',[$this->users['dev']]);
        foreach(['A','B','D'] as $code) { $this->stores[$code]=$this->db->fetchOne('INSERT INTO stores (code,name) VALUES (?,?) RETURNING id',[$code,'Magasin '.$code]); }
        $this->db->executeStatement('UPDATE stores SET active = false WHERE id = ?',[$this->stores['D']]);
        foreach(['manager','user'] as $name) { foreach(['A','D'] as $code) { $this->db->insert('user_stores',['user_id'=>$this->users[$name],'store_id'=>$this->stores[$code]]); } }
        $this->article=$this->db->fetchOne("INSERT INTO articles (sku,name) VALUES ('CONSUME','Article fictif') RETURNING id");$this->inactive=$this->db->fetchOne("INSERT INTO articles (sku,name,active) VALUES ('INACTIVE','Archivé',false) RETURNING id");
    }
    protected function tearDown(): void { if($this->db->isTransactionActive()) { $this->db->rollBack(); }parent::tearDown(); }
    private function login(string $name='user'): void
    {
        $this->client->getCookieJar()->clear();$this->client->loginUser(static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier($name.'@example.test'));
        $this->client->request('GET','/api/v1/auth/csrf');$this->csrf=json_decode($this->client->getResponse()->getContent(),true)['token'];
    }
    private function body(array $extra=[]): array { return array_replace(['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>4],$extra); }
    private function api(array $extra=[],string $key='consumption-api-key'): array
    {
        $this->client->request('POST','/api/v1/movements/consumptions',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>$key],content:json_encode($this->body($extra),JSON_PRESERVE_ZERO_FRACTION));return json_decode($this->client->getResponse()->getContent(),true)??[];
    }
    private function seed(string $code='A'): void
    {
        $actor=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('admin@example.test');static::getContainer()->get(StockMovementEngine::class)->record($actor,'entry',(object)['article_id'=>$this->article,'store_id'=>$this->stores[$code],'quantity'=>15,'reason'=>'Initialisation fictive'],'seed-consumption-'.$code);
    }
    private function totals(): array
    {
        return $this->db->fetchAssociative('SELECT (SELECT coalesce(sum(quantity),0)::text FROM stock_levels) quantity,(SELECT count(*) FROM stock_movements) movements,(SELECT count(*) FROM stock_change_events) events,(SELECT count(*) FROM audit_logs) audits');
    }
    public function testUserApiConsumesFourOfFifteenForSelfWithServerAttributionAndReceipt(): void
    {
        $this->seed();$this->login();$before=time();$result=$this->api(['reference'=>'Intervention fictive','comment'=>'Utilisation fictive']);self::assertResponseStatusCodeSame(201);$m=$result['data'];self::assertSame('consumption',$m['type']);self::assertSame($this->users['user'],$m['author_id']);self::assertSame($this->users['user'],$m['consumer_id']);self::assertSame($this->stores['A'],$m['source_store_id']);self::assertNull($m['destination_store_id']);self::assertSame(4,$m['quantity']);self::assertGreaterThanOrEqual($before,(new \DateTimeImmutable($m['created_at']))->getTimestamp());self::assertTrue(static::getContainer()->get(OpenApiContract::class)->valid(json_decode(json_encode($result)),['$ref'=>'#/components/schemas/MovementResource']));
        self::assertSame(['quantity'=>'11','movements'=>2,'events'=>2,'audits'=>2],$this->totals());$location=$this->client->getResponse()->headers->get('Location');self::assertSame('/consommations/'.$m['id'],$location);$this->client->request('GET',$location);self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','4 unités retirées');self::assertSelectorTextContains('main','Nom user');self::assertSelectorExists('a[href="/stocks/'.$this->stores['A'].'/'.$this->article.'"]');self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference <> 0'));
    }
    public function testManagersAndAdminsMayChooseAnActiveBeneficiaryOutsideTheStore(): void
    {
        $this->seed();foreach(['manager','admin'] as $name) { $this->login($name);$m=$this->api(['consumer_id'=>$this->users['tech']], 'third-party-'.$name)['data'];self::assertResponseStatusCodeSame(201);self::assertSame($this->users[$name],$m['author_id']);self::assertSame($this->users['tech'],$m['consumer_id']); }
        self::assertSame('7',$this->totals()['quantity']);$this->login();$before=$this->totals();$this->api(['consumer_id'=>$this->users['tech']]);self::assertResponseStatusCodeSame(403);self::assertSame($before,$this->totals());
    }
    public function testUserFormHasNoThirdPartySelectorAndDoubleSubmitDoesNotDebitTwice(): void
    {
        $this->seed();$this->login();$crawler=$this->client->request('GET','/consommations/nouvelle?store_id='.$this->stores['A'].'&article_id='.$this->article);self::assertResponseIsSuccessful();self::assertSelectorNotExists('select[name="consumer_id"]');self::assertSelectorExists('input[name="consumer_id"][value="'.$this->users['user'].'"]');self::assertSelectorTextNotContains('main','Nom tech');self::assertSelectorExists('nav a[aria-current="page"][href="/consommations/nouvelle"]');self::assertSelectorNotExists('#consumption-store option[value="'.$this->stores['B'].'"]');self::assertSelectorNotExists('#consumption-store option[value="'.$this->stores['D'].'"]');self::assertSelectorNotExists('#consumption-article option[value="'.$this->inactive.'"]');
        $form=$crawler->selectButton('Enregistrer la consommation')->form(['quantity'=>'4','reference'=>'0','comment'=>'0']);$this->client->submit($form);self::assertResponseStatusCodeSame(303);$this->client->followRedirect();self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','La consommation de 4 unités');self::assertSelectorTextContains('main','Référence d’intervention, chantier ou client : 0');self::assertSelectorTextContains('main','Commentaire : 0');$this->client->submit($form);self::assertResponseStatusCodeSame(303);self::assertSame(['quantity'=>'11','movements'=>2,'events'=>2,'audits'=>2],$this->totals());
        $values=$form->getPhpValues();$values['consumer_id']=$this->users['tech'];$this->client->request('POST','/consommations/nouvelle',$values);self::assertResponseStatusCodeSame(403);self::assertSame('11',$this->totals()['quantity']);
    }
    public function testManagerFormShowsMinimalCompleteBeneficiariesAndEscapedConfirmation(): void
    {
        $this->seed();$this->login('manager');$crawler=$this->client->request('GET','/consommations/nouvelle');self::assertResponseIsSuccessful();self::assertSelectorExists('#consumption-beneficiary option[value="'.$this->users['tech'].'"]');self::assertSelectorNotExists('#consumption-beneficiary option[value="'.$this->users['inactive'].'"]');self::assertSelectorNotExists('#consumption-beneficiary option[value="'.$this->users['dev'].'"]');self::assertStringNotContainsString('tech@example.test',$this->client->getResponse()->getContent());
        $form=$crawler->selectButton('Enregistrer la consommation')->form(['store_id'=>$this->stores['A'],'article_id'=>$this->article,'consumer_id'=>$this->users['tech'],'quantity'=>'4','comment'=>'<script>fictif</script>','reference'=>'0']);$this->client->submit($form);self::assertResponseStatusCodeSame(303);$this->client->followRedirect();self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Technicien bénéficiaire : Nom tech');self::assertSelectorTextContains('main','Opérateur : Nom manager');self::assertSelectorTextContains('main','<script>fictif</script>');self::assertSelectorNotExists('main script');
    }
    public function testReceiptOwnershipSurvivesUnassignmentWithoutExposingCurrentStock(): void
    {
        $this->seed();$this->login('manager');$id=$this->api(['consumer_id'=>$this->users['tech']])['data']['id'];self::assertResponseStatusCodeSame(201);
        $this->login('tech');$this->client->request('GET','/consommations/'.$id);self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Nom manager');self::assertSelectorNotExists('a[href="/stocks/'.$this->stores['A'].'/'.$this->article.'"]');self::assertSelectorTextNotContains('main','Consulter le stock actualisé');$this->client->request('GET','/api/v1/stores/'.$this->stores['A'].'/stocks');self::assertResponseStatusCodeSame(404);
        $this->login();$this->client->request('GET','/consommations/'.$id);self::assertResponseStatusCodeSame(404);
        $this->login('manager');$this->db->executeStatement('DELETE FROM user_stores WHERE user_id = ?',[$this->users['manager']]);$this->client->request('GET','/consommations/'.$id);self::assertResponseStatusCodeSame(404);
        $this->db->executeStatement('UPDATE stores SET active = false WHERE id = ?',[$this->stores['A']]);$this->login('tech');$this->client->request('GET','/consommations/'.$id);self::assertResponseStatusCodeSame(404);$this->login('admin');$this->client->request('GET','/consommations/'.$id);self::assertResponseIsSuccessful();
    }
    public function testReceiptCannotExposeAnotherStoreOrNonConsumptionMovement(): void
    {
        $this->seed('B');$this->login('admin');$id=$this->api(['store_id'=>$this->stores['B'],'consumer_id'=>$this->users['tech']])['data']['id'];self::assertResponseStatusCodeSame(201);$entry=$this->db->fetchOne("SELECT id FROM stock_movements WHERE type='entry'");
        $this->login('manager');foreach([$id,$entry,'bad-id','11111111-1111-4111-8111-111111111111'] as $value) { $this->client->request('GET','/consommations/'.$value);self::assertResponseStatusCodeSame(404); }
    }
    public function testInsufficientStockInventoryAndRevokedAssignmentsLeaveStockUnchanged(): void
    {
        $this->seed();$this->login();$before=$this->totals();$this->api(['quantity'=>20]);self::assertResponseStatusCodeSame(409);self::assertSame($before,$this->totals());
        foreach(['in_progress','pending_validation'] as $status) { $id=$this->db->fetchOne('INSERT INTO inventories (store_id,status,created_by,snapshot_at) VALUES (?,?,?,clock_timestamp()) RETURNING id',[$this->stores['A'],$status,$this->users['admin']]);$this->api();self::assertResponseStatusCodeSame(409);self::assertSame($before,$this->totals());$this->db->executeStatement("UPDATE inventories SET status='cancelled',cancelled_at=clock_timestamp() WHERE id=?",[$id]); }
        $crawler=$this->client->request('GET','/consommations/nouvelle');$form=$crawler->selectButton('Enregistrer la consommation')->form(['store_id'=>$this->stores['A'],'article_id'=>$this->article,'quantity'=>'4']);$this->db->executeStatement('DELETE FROM user_stores WHERE user_id=?',[$this->users['user']]);$this->client->submit($form);self::assertResponseStatusCodeSame(404);self::assertSame($before,$this->totals());
    }
    public function testInactiveArticlesStoresAndBeneficiariesAreRejected(): void
    {
        $this->seed();$this->login('manager');$before=$this->totals();foreach(['inactive','dev'] as $name) { $this->api(['consumer_id'=>$this->users[$name]]);self::assertResponseStatusCodeSame(422); }
        $this->api(['article_id'=>$this->inactive]);self::assertResponseStatusCodeSame(409);foreach(['B','D'] as $code) { $this->api(['store_id'=>$this->stores[$code]]);self::assertResponseStatusCodeSame(404);$this->client->request('GET','/consommations/nouvelle?store_id='.$this->stores[$code]);self::assertResponseStatusCodeSame(404); }self::assertSame($before,$this->totals());
    }
    public function testInvalidApiNumbersMetadataCsrfAndKeysCannotWrite(): void
    {
        $this->login();foreach([0,-1,1.0,1.5,'4',true,null,9007199254740992] as $quantity) { $this->api(['quantity'=>$quantity]);self::assertResponseStatusCodeSame(422); }
        foreach([['consumer_id'=>'bad'],['comment'=>str_repeat('x',2001)],['reference'=>str_repeat('x',256)],['comment'=>"bad\0text"],['created_at'=>'2000-01-01T00:00:00Z'],['author_id'=>$this->users['admin']]] as $extra) { $this->api($extra);self::assertResponseStatusCodeSame(422); }
        $this->api(key:'short');self::assertResponseStatusCodeSame(422);$this->client->request('POST','/api/v1/movements/consumptions',server:['CONTENT_TYPE'=>'application/json','HTTP_IDEMPOTENCY_KEY'=>'missing-csrf-key'],content:json_encode($this->body()));self::assertResponseStatusCodeSame(403);self::assertSame(['quantity'=>'0','movements'=>0,'events'=>0,'audits'=>0],$this->totals());
    }
    public function testFormValidationPreservesTheKeyAndCannotForgeBeneficiaryOrDates(): void
    {
        $this->seed();$this->login();$crawler=$this->client->request('GET','/consommations/nouvelle');$form=$crawler->selectButton('Enregistrer la consommation')->form(['store_id'=>$this->stores['A'],'article_id'=>$this->article,'quantity'=>'1e2','comment'=>'<script>texte</script>']);$key=$form['_movement_key']->getValue();$this->client->submit($form);self::assertResponseStatusCodeSame(422);self::assertSelectorExists('input[name="_movement_key"][value="'.$key.'"]');self::assertSelectorExists('#consumption-quantity[value="1e2"]');self::assertSelectorTextContains('#consumption-comment','<script>texte</script>');self::assertSelectorNotExists('main script');
        $values=$form->getPhpValues();$values['quantity']='4';foreach([['consumer_id'=>$this->users['tech']],['quantity'=>['4']],['created_at'=>'2000-01-01'],['comment'=>"\xff"],['_csrf_token'=>'bad']] as $extra) { $this->client->request('POST','/consommations/nouvelle',array_replace($values,$extra));self::assertResponseStatusCodeSame(isset($extra['consumer_id'])||isset($extra['_csrf_token'])?403:422); }self::assertSame('15',$this->totals()['quantity']);
    }
    public function testReplayKeepsTheOriginalAttributionAndDoesNotDebitAgain(): void
    {
        $this->seed();$this->login('manager');$body=['consumer_id'=>$this->users['tech']];$first=$this->api($body);self::assertResponseStatusCodeSame(201);$before=$this->totals();self::assertSame($first,$this->api($body));self::assertResponseStatusCodeSame(201);$this->api(['consumer_id'=>$this->users['user']]);self::assertResponseStatusCodeSame(409);$this->db->executeStatement('UPDATE users SET active=false WHERE id=?',[$this->users['tech']]);self::assertSame($first,$this->api($body));self::assertResponseStatusCodeSame(201);self::assertSame($before,$this->totals());
    }
    public function testEmptyOptionsContextNavigationAndAnonymousRequests(): void
    {
        $this->login();$crawler=$this->client->request('GET','/stocks');$this->client->submit($crawler->selectButton('Choisir')->form(['store_id'=>$this->stores['A']]));self::assertResponseStatusCodeSame(303);$this->client->request('GET','/espace/consommations');self::assertResponseRedirects('/consommations/nouvelle');$this->client->followRedirect();self::assertSelectorExists('#consumption-store option[selected][value="'.$this->stores['A'].'"]');
        $this->login('unassigned');$this->client->request('GET','/consommations/nouvelle');self::assertResponseIsSuccessful();self::assertSelectorExists('button[type="submit"][disabled]');self::assertSelectorNotExists('#consumption-beneficiary option[value="'.$this->users['tech'].'"]');
        $this->client->getCookieJar()->clear();$this->client->request('GET','/consommations/nouvelle');self::assertResponseRedirects('/connexion');$this->client->request('GET','/api/v1/auth/csrf');$token=json_decode($this->client->getResponse()->getContent(),true)['token'];$this->client->request('POST','/api/v1/movements/consumptions',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$token],content:json_encode($this->body()));self::assertResponseStatusCodeSame(401);
    }
}
