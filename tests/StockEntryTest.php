<?php

declare(strict_types=1);

namespace App\Tests;

use App\Api\OpenApiContract;
use App\Security\StockUserProvider;
use App\Stock\StockMovementEngine;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StockEntryTest extends WebTestCase
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
        foreach(['20261018140000','20261018200000'] as $version) { require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';$class='DoctrineMigrations\\Version'.$version;$migration=new $class($this->db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) { $this->db->executeStatement($sql->getStatement()); } }
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
    private function body(array $override=[]): array { return array_replace(['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>10,'reason'=>'Livraison fictive'],$override); }
    private function api(array $override=[],string $key='entry-api-key-123'): array
    {
        $this->client->request('POST','/api/v1/movements/entries',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>$key],content:json_encode($this->body($override)));
        return json_decode($this->client->getResponse()->getContent(),true)??[];
    }
    private function seed(): void
    {
        $actor=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('admin@example.test');static::getContainer()->get(StockMovementEngine::class)->record($actor,'entry',(object)$this->body(['quantity'=>5]),'seed-entry-key-123');
    }
    private function totals(): array
    {
        return $this->db->fetchAssociative('SELECT (SELECT coalesce(sum(quantity),0)::text FROM stock_levels) quantity,(SELECT count(*) FROM stock_movements) movements,(SELECT count(*) FROM stock_change_events) events,(SELECT count(*) FROM audit_logs) audits');
    }
    public function testApiCreditsFivePlusTenWithAuthorServerDateAndOneDurableEvent(): void
    {
        $this->seed();$this->login();$before=time();$result=$this->api(['reference'=>'BL-TEST']);self::assertResponseStatusCodeSame(201);self::assertSame($this->users['manager'],$result['data']['author_id']);self::assertSame('entry',$result['data']['type']);self::assertSame(10,$result['data']['quantity']);self::assertNull($result['data']['source_store_id']);self::assertSame($this->stores['A'],$result['data']['destination_store_id']);self::assertSame('BL-TEST',$result['data']['reference']);
        self::assertGreaterThanOrEqual($before,(new \DateTimeImmutable($result['data']['created_at']))->getTimestamp());self::assertTrue(static::getContainer()->get(OpenApiContract::class)->valid(json_decode(json_encode($result)),['$ref'=>'#/components/schemas/MovementResource']));self::assertSame(['quantity'=>'15','movements'=>2,'events'=>2,'audits'=>2],$this->totals());
        $this->client->request('GET','/api/v1/stores/'.$this->stores['A'].'/stocks?article_id='.$this->article);self::assertResponseIsSuccessful();self::assertSame(15,json_decode($this->client->getResponse()->getContent(),true)['data'][0]['quantity']);
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference <> 0'));
    }
    public function testApiReplaysAndChangedPayloadNeverDuplicateTheEntry(): void
    {
        $this->login();$first=$this->api();self::assertResponseStatusCodeSame(201);self::assertSame($first,$this->api());self::assertResponseStatusCodeSame(201);self::assertSame(1,$this->totals()['movements']);
        $this->api(['quantity'=>11]);self::assertResponseStatusCodeSame(409);self::assertSame('10',$this->totals()['quantity']);
        $this->db->executeStatement('UPDATE articles SET active = false WHERE id = ?',[$this->article]);self::assertSame($first,$this->api());self::assertResponseStatusCodeSame(201);self::assertSame(1,$this->totals()['events']);
    }
    public function testRolesAssignmentsInactiveStoresAndArticlesCannotBeBypassed(): void
    {
        $this->login('user');$this->api();self::assertResponseStatusCodeSame(403);$this->client->request('GET','/stocks/entree');self::assertResponseStatusCodeSame(403);$this->client->request('GET','/stocks');self::assertResponseIsSuccessful();self::assertSelectorNotExists('a[href="/stocks/entree"]');
        $this->login();foreach(['B','D'] as $code) { $this->api(['store_id'=>$this->stores[$code]]);self::assertResponseStatusCodeSame(404);$this->client->request('GET','/stocks/entree?store_id='.$this->stores[$code]);self::assertResponseStatusCodeSame(404); }
        $this->api(['article_id'=>$this->inactive]);self::assertResponseStatusCodeSame(409);$this->client->request('GET','/stocks/entree?article_id='.$this->inactive);self::assertResponseStatusCodeSame(409);
        $this->login('admin');$this->api(['store_id'=>$this->stores['D']]);self::assertResponseStatusCodeSame(404);$this->api(['store_id'=>$this->stores['B']]);self::assertResponseStatusCodeSame(201);self::assertSame('10',$this->totals()['quantity']);
    }
    public function testApiRejectsInvalidBodiesDatesKeysAndCsrfWithoutWriting(): void
    {
        $this->login();
        foreach([0,-1,1.0,1.5,'2',true,null,9007199254740992] as $quantity) {
            // Preserve a decimal in JSON for the explicit 1.0 case.
            $this->client->request('POST','/api/v1/movements/entries',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>'invalid-quantity-key'],content:json_encode($this->body(['quantity'=>$quantity]),JSON_PRESERVE_ZERO_FRACTION));self::assertResponseStatusCodeSame(422);
        }
        foreach([['reason'=>''],['reason'=>'   '],['reason'=>"bad\0text"],['reference'=>str_repeat('x',256)],['article_id'=>'bad'],['created_at'=>'2000-01-01T00:00:00Z'],['author_id'=>$this->users['admin']]] as $body) { $this->api($body);self::assertResponseStatusCodeSame(422); }
        $this->api(key:'short');self::assertResponseStatusCodeSame(422);
        $this->client->request('POST','/api/v1/movements/entries',server:['CONTENT_TYPE'=>'application/json','HTTP_IDEMPOTENCY_KEY'=>'missing-csrf-key'],content:json_encode($this->body()));self::assertResponseStatusCodeSame(403);
        $this->client->request('POST','/api/v1/movements/entries?store_id='.$this->stores['A'],server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>'query-invalid-key'],content:json_encode($this->body()));self::assertResponseStatusCodeSame(422);
        $this->client->request('POST','/api/v1/movements/entries',server:['CONTENT_TYPE'=>'text/plain','HTTP_X_CSRF_TOKEN'=>$this->csrf],content:'{}');self::assertResponseStatusCodeSame(415);
        self::assertSame(['quantity'=>'0','movements'=>0,'events'=>0,'audits'=>0],$this->totals());
    }
    public function testFormWithoutJavaScriptCreditsStockAndResubmissionIsIdempotent(): void
    {
        $this->seed();$this->login();$crawler=$this->client->request('GET','/stocks/entree?store_id='.$this->stores['A'].'&article_id='.$this->article);self::assertResponseIsSuccessful();self::assertSelectorExists('#entry-store option[selected][value="'.$this->stores['A'].'"]');self::assertSelectorExists('#entry-article option[selected][value="'.$this->article.'"]');self::assertSelectorNotExists('#entry-article option[value="'.$this->inactive.'"]');self::assertSelectorNotExists('#entry-store option[value="'.$this->stores['B'].'"]');self::assertSelectorNotExists('#entry-store option[value="'.$this->stores['D'].'"]');self::assertSelectorNotExists('main script');
        $form=$crawler->selectButton('Enregistrer l’entrée')->form(['quantity'=>'10','reason'=>'0','reference'=>'0']);self::assertSame('/stocks/entree',parse_url($form->getUri(),PHP_URL_PATH));self::assertNull(parse_url($form->getUri(),PHP_URL_QUERY));$this->client->submit($form);self::assertResponseRedirects('/stocks/'.$this->stores['A'].'/'.$this->article,303);$this->client->followRedirect();self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Quantité actuelle : 15');self::assertSelectorTextContains('main','L’entrée de 10 unités');
        $this->client->submit($form);self::assertResponseStatusCodeSame(303);self::assertSame(['quantity'=>'15','movements'=>2,'events'=>2,'audits'=>2],$this->totals());$row=$this->db->fetchAssociative("SELECT reason,reference FROM stock_movements WHERE idempotency_key <> 'seed-entry-key-123'");self::assertSame(['reason'=>'0','reference'=>'0'],$row);
    }
    public function testInvalidFormPreservesValuesAndKeyAndExplainsInventoryConflict(): void
    {
        $this->login();$crawler=$this->client->request('GET','/stocks/entree');$key=$crawler->filter('input[name="_movement_key"]')->attr('value');$values=['store_id'=>$this->stores['A'],'article_id'=>$this->article,'quantity'=>'1.5','reason'=>'<script>motif</script>','reference'=>'BL-X'];$form=$crawler->selectButton('Enregistrer l’entrée')->form($values);$this->client->submit($form);self::assertResponseStatusCodeSame(422);self::assertSelectorExists('input[name="_movement_key"][value="'.$key.'"]');self::assertSelectorExists('#entry-quantity[value="1.5"]');self::assertSelectorTextContains('#entry-reason','<script>motif</script>');self::assertSelectorNotExists('main script');self::assertSelectorTextContains('[role="alert"]','quantité entière');
        $form=$this->client->getCrawler()->selectButton('Enregistrer l’entrée')->form(['quantity'=>'10']);$this->db->executeStatement("INSERT INTO inventories (store_id,status,created_by,snapshot_at) VALUES (?,'in_progress',?,clock_timestamp())",[$this->stores['A'],$this->users['admin']]);$this->client->submit($form);self::assertResponseStatusCodeSame(409);self::assertSelectorTextContains('[role="alert"]','inventaire');self::assertSelectorExists('input[name="_movement_key"][value="'.$key.'"]');self::assertSame('0',$this->totals()['quantity']);
    }
    public function testFormRejectsForgedFieldsArraysInvalidCsrfAndLostAssignment(): void
    {
        $this->login();$crawler=$this->client->request('GET','/stocks/entree');$form=$crawler->selectButton('Enregistrer l’entrée')->form(['store_id'=>$this->stores['A'],'article_id'=>$this->article,'quantity'=>'2','reason'=>'Livraison']);$values=$form->getPhpValues();
        foreach([['quantity'=>['1']],['reason'=>"\xff"],['created_at'=>'2000-01-01'],['quantity'=>'1e2'],['quantity'=>'01'],['quantity'=>'-1'],['quantity'=>'9007199254740992']] as $extra) { $this->client->request('POST','/stocks/entree',array_replace($values,$extra));self::assertResponseStatusCodeSame(422); }
        $this->client->request('POST','/stocks/entree',array_replace($values,['_csrf_token'=>'bad']));self::assertResponseStatusCodeSame(403);
        $this->db->executeStatement('DELETE FROM user_stores WHERE user_id = ? AND store_id = ?',[$this->users['manager'],$this->stores['A']]);$this->client->submit($form);self::assertResponseStatusCodeSame(404);self::assertSame('0',$this->totals()['quantity']);
    }
    public function testSelectedStoreDefaultsAndEmptyOptionsHaveAccessibleStates(): void
    {
        $this->login();$crawler=$this->client->request('GET','/stocks');$this->client->submit($crawler->selectButton('Choisir')->form(['store_id'=>$this->stores['A']]));self::assertResponseStatusCodeSame(303);$this->client->request('GET','/stocks/entree');self::assertResponseIsSuccessful();self::assertSelectorExists('#entry-store option[selected][value="'.$this->stores['A'].'"]');self::assertSelectorExists('nav a[aria-current="page"][href="/stocks"]');
        $this->login('unassigned');$this->client->request('GET','/stocks/entree');self::assertResponseIsSuccessful();self::assertSelectorExists('button[type="submit"][disabled]');self::assertSelectorTextContains('main','Aucun magasin actif');
        $this->login();$this->db->executeStatement('UPDATE articles SET active = false');$this->client->request('GET','/stocks/entree');self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','aucun article actif');self::assertSelectorExists('button[type="submit"][disabled]');
    }
    public function testAnonymousApiAndUnsupportedMethodsCannotWrite(): void
    {
        $this->client->request('POST','/api/v1/movements/entries',server:['CONTENT_TYPE'=>'application/json'],content:json_encode($this->body()));self::assertResponseStatusCodeSame(403);
        $this->client->request('GET','/api/v1/auth/csrf');$token=json_decode($this->client->getResponse()->getContent(),true)['token'];$this->client->request('POST','/api/v1/movements/entries',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$token],content:json_encode($this->body()));self::assertResponseStatusCodeSame(401);$this->client->request('GET','/stocks/entree');self::assertResponseRedirects('/connexion');
        $this->login();$this->client->request('GET','/api/v1/movements/entries');self::assertResponseStatusCodeSame(405);self::assertSame('0',$this->totals()['quantity']);
    }
}
