<?php

declare(strict_types=1);

namespace App\Tests;

use App\Api\OpenApiContract;
use App\Security\StockUserProvider;
use App\Stock\StockMovementEngine;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StockTransferTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private array $users = [], $stores = [];
    private string $article, $inactive, $csrf;
    protected function setUp(): void
    {
        $this->client=static::createClient();$this->client->disableReboot();$this->db=static::getContainer()->get(Connection::class);$this->db->beginTransaction();
        $schema='stk06_'.bin2hex(random_bytes(6));$this->db->executeStatement('CREATE SCHEMA '.$schema);$this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        foreach(explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) { if(trim($sql)!=='') { $this->db->executeStatement($sql); } }
        $this->db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
        foreach(['20261018140000','20261018200000','20261018223000'] as $version) { require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';$class='DoctrineMigrations\\Version'.$version;$migration=new $class($this->db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) { $this->db->executeStatement($sql->getStatement()); } }
        $hash=password_hash('Entry-test-2026!',PASSWORD_ARGON2ID);
        foreach(['admin'=>1,'manager'=>2,'user'=>3,'unassigned'=>2,'single'=>2] as $name=>$role) { $this->users[$name]=$this->db->fetchOne('INSERT INTO users (email,name,password_hash,role_id) VALUES (?,?,?,?) RETURNING id',[$name.'@example.test',$name,$hash,$role]); }
        foreach(['A','B','C','D'] as $code) { $this->stores[$code]=$this->db->fetchOne('INSERT INTO stores (code,name) VALUES (?,?) RETURNING id',[$code,'Magasin '.$code]); }
        $this->db->executeStatement('UPDATE stores SET active = false WHERE id = ?',[$this->stores['D']]);
        foreach(['manager','user'] as $name) { foreach(['A','D'] as $code) { $this->db->insert('user_stores',['user_id'=>$this->users[$name],'store_id'=>$this->stores[$code]]); } }
        $this->db->insert('user_stores',['user_id'=>$this->users['manager'],'store_id'=>$this->stores['B']]);
        $this->db->insert('user_stores',['user_id'=>$this->users['single'],'store_id'=>$this->stores['A']]);
        $this->article=$this->db->fetchOne("INSERT INTO articles (sku,name) VALUES ('ENTRY','Article <script>fictif</script>') RETURNING id");$this->inactive=$this->db->fetchOne("INSERT INTO articles (sku,name,active) VALUES ('ARCHIVE','Archivé',false) RETURNING id");
    }
    protected function tearDown(): void { if($this->db->isTransactionActive()) { $this->db->rollBack(); }parent::tearDown(); }
    private function login(string $name='manager'): void
    {
        $this->client->getCookieJar()->clear();$this->client->loginUser(static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier($name.'@example.test'));
        $this->client->request('GET','/api/v1/auth/csrf');$this->csrf=json_decode($this->client->getResponse()->getContent(),true)['token'];
    }
    private function body(array $override=[]): array { return array_replace(['article_id'=>$this->article,'source_store_id'=>$this->stores['A'],'destination_store_id'=>$this->stores['B'],'quantity'=>3],$override); }
    private function api(array $override=[],string $key='transfer-api-key'): array
    {
        $this->client->request('POST','/api/v1/movements/transfers',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>$key],content:json_encode($this->body($override),JSON_PRESERVE_ZERO_FRACTION));
        return json_decode($this->client->getResponse()->getContent(),true)??[];
    }
    private function seed(string $store='A',int $quantity=5): void
    {
        $actor=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('admin@example.test');static::getContainer()->get(StockMovementEngine::class)->record($actor,'entry',(object)['article_id'=>$this->article,'store_id'=>$this->stores[$store],'quantity'=>$quantity,'reason'=>'Initialisation fictive'],'transfer-seed-'.$store);
    }
    private function balance(string $store): int { return (int)$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE article_id = ? AND store_id = ?',[$this->article,$this->stores[$store]]); }
    private function snapshot(): array
    {
        return ['balances'=>$this->db->fetchAllAssociative('SELECT * FROM stock_levels ORDER BY article_id,store_id'),'movements'=>(int)$this->db->fetchOne('SELECT count(*) FROM stock_movements'),'events'=>(int)$this->db->fetchOne('SELECT count(*) FROM stock_change_events'),'audits'=>(int)$this->db->fetchOne('SELECT count(*) FROM audit_logs')];
    }
    private function reconciled(): void { self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference <> 0')); }
    private function inventory(string $store,string $status): string { return $this->db->fetchOne('INSERT INTO inventories (store_id,created_by,status,snapshot_at) VALUES (?,?,?,clock_timestamp()) RETURNING id',[$this->stores[$store],$this->users['admin'],$status]); }
    private function endInventory(string $id): void { $this->db->executeStatement("UPDATE inventories SET status = 'cancelled',cancelled_at=clock_timestamp() WHERE id = ?",[$id]); }

    public function testApiDebitsAndCreditsThreeWithOneCommonIdAndDurableEvent(): void
    {
        $this->seed();$this->login();$before=time();$result=$this->api(['reason'=>'Rééquilibrage']);self::assertResponseStatusCodeSame(201);$m=$result['data'];self::assertSame('transfer',$m['type']);self::assertSame(3,$m['quantity']);self::assertSame($this->stores['A'],$m['source_store_id']);self::assertSame($this->stores['B'],$m['destination_store_id']);self::assertSame($this->users['manager'],$m['author_id']);self::assertNull($m['consumer_id']);self::assertGreaterThanOrEqual($before,(new \DateTimeImmutable($m['created_at']))->getTimestamp());
        self::assertTrue(static::getContainer()->get(OpenApiContract::class)->valid(json_decode(json_encode($result)),['$ref'=>'#/components/schemas/MovementResource']));self::assertSame(2,$this->balance('A'));self::assertSame(3,$this->balance('B'));self::assertSame('5',$this->db->fetchOne('SELECT sum(quantity)::text FROM stock_levels'));self::assertSame(2,$this->snapshot()['movements']);self::assertSame(2,$this->snapshot()['events']);self::assertSame(2,$this->snapshot()['audits']);
        foreach(['A'=>-3,'B'=>3] as $store=>$quantity) { $this->client->request('GET','/api/v1/stores/'.$this->stores[$store].'/stocks/'.$this->article.'/movements');self::assertResponseIsSuccessful();$rows=json_decode($this->client->getResponse()->getContent(),true)['data'];$rows=array_values(array_filter($rows,fn($r)=>$r['id']===$m['id']));self::assertCount(1,$rows);self::assertSame($quantity,$rows[0]['quantity']);self::assertArrayNotHasKey('destination_store_id',$rows[0]); }
        $event=$this->db->fetchAssociative('SELECT cardinality(store_ids) stores,?::uuid = ANY(store_ids) has_source,?::uuid = ANY(store_ids) has_destination FROM stock_change_events WHERE movement_id = ?',[$this->stores['A'],$this->stores['B'],$m['id']]);self::assertSame(['stores'=>2,'has_source'=>true,'has_destination'=>true],$event);
        $this->client->request('GET','/stocks/transferts/'.$m['id']);self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','3 unités transférées');self::assertSelectorTextContains('main',$m['id']);self::assertSelectorTextContains('main','Magasin A');self::assertSelectorTextContains('main','Magasin B');self::assertSelectorTextContains('main','Opérateur : manager');$this->reconciled();
    }
    public function testFormWithoutJavaScriptAndDoubleSubmission(): void
    {
        $this->seed();$this->login();$crawler=$this->client->request('GET','/stocks/transfert?source_store_id='.$this->stores['A'].'&article_id='.$this->article);self::assertResponseIsSuccessful();self::assertSelectorExists('#transfer-source option[selected][value="'.$this->stores['A'].'"]');self::assertSelectorExists('#transfer-article option[selected][value="'.$this->article.'"]');
        foreach(['source','destination'] as $field) { foreach(['C','D'] as $store) { self::assertSelectorNotExists('#transfer-'.$field.' option[value="'.$this->stores[$store].'"]'); } }self::assertSelectorNotExists('#transfer-article option[value="'.$this->inactive.'"]');self::assertSelectorNotExists('main script');
        $form=$crawler->selectButton('Enregistrer le transfert')->form(['destination_store_id'=>$this->stores['B'],'quantity'=>'3','reason'=>'0']);self::assertSame('/stocks/transfert',parse_url($form->getUri(),PHP_URL_PATH));self::assertNull(parse_url($form->getUri(),PHP_URL_QUERY));$this->client->submit($form);self::assertResponseStatusCodeSame(303);$location=$this->client->getResponse()->headers->get('Location');self::assertStringStartsWith('/stocks/transferts/',$location);$this->client->followRedirect();self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Le transfert de 3 unités');self::assertSelectorTextContains('main','Motif : 0');self::assertSelectorTextContains('main','−3 unités');self::assertSelectorTextContains('main','+3 unités');self::assertSelectorNotExists('main script');
        foreach(['A','B'] as $store) { self::assertSelectorExists('a[href="/stocks/'.$this->stores[$store].'/'.$this->article.'"]'); }$before=$this->snapshot();$this->client->submit($form);self::assertResponseStatusCodeSame(303);self::assertSame($location,$this->client->getResponse()->headers->get('Location'));self::assertSame($before,$this->snapshot());$this->reconciled();
    }
    public function testSameStoresPermissionsAndInactiveResourcesCannotBeBypassed(): void
    {
        $this->seed();$this->login();$before=$this->snapshot();$this->api(['destination_store_id'=>$this->stores['A']]);self::assertResponseStatusCodeSame(422);
        foreach(['source_store_id','destination_store_id'] as $field) { foreach(['C','D'] as $store) { $this->api([$field=>$this->stores[$store]]);self::assertResponseStatusCodeSame(404);$this->client->request('GET','/stocks/transfert?'.$field.'='.$this->stores[$store]);self::assertResponseStatusCodeSame(404); } }
        $this->api(['article_id'=>$this->inactive]);self::assertResponseStatusCodeSame(409);$this->client->request('GET','/stocks/transfert?article_id='.$this->inactive);self::assertResponseStatusCodeSame(409);
        $this->login('user');$this->api();self::assertResponseStatusCodeSame(403);$this->client->request('GET','/stocks/transfert');self::assertResponseStatusCodeSame(403);$this->client->request('GET','/stocks');self::assertSelectorNotExists('a[href="/stocks/transfert"]');
        $this->login('single');$this->api();self::assertResponseStatusCodeSame(404);$this->login('admin');$this->api(['destination_store_id'=>$this->stores['D']]);self::assertResponseStatusCodeSame(404);self::assertSame($before,$this->snapshot());
        $this->api(['destination_store_id'=>$this->stores['C']]);self::assertResponseStatusCodeSame(201);self::assertSame(2,$this->balance('A'));self::assertSame(3,$this->balance('C'));$this->reconciled();
    }
    public function testInvalidBodiesNumbersMetadataAndCsrfCannotWrite(): void
    {
        $this->seed();$this->login();$before=$this->snapshot();foreach([0,-1,1.0,1.5,'3',true,null,9007199254740992] as $quantity) { $this->api(['quantity'=>$quantity]);self::assertResponseStatusCodeSame(422); }
        foreach([['reason'=>''],['reason'=>'   '],['reason'=>"bad\0text"],['reason'=>str_repeat('a',2001)],['reference'=>'BL'],['comment'=>'Texte'],['source_store_id'=>'bad'],['created_at'=>'2000-01-01T00:00:00Z'],['author_id'=>$this->users['admin']],['consumer_id'=>$this->users['user']]] as $body) { $this->api($body);self::assertResponseStatusCodeSame(422); }
        $this->api(key:'bad');self::assertResponseStatusCodeSame(422);$this->client->request('POST','/api/v1/movements/transfers?quantity=3',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>'invalid-query'],content:json_encode($this->body()));self::assertResponseStatusCodeSame(422);
        $this->client->request('POST','/api/v1/movements/transfers',server:['CONTENT_TYPE'=>'text/plain','HTTP_X_CSRF_TOKEN'=>$this->csrf],content:'{}');self::assertResponseStatusCodeSame(415);$this->csrf='invalid';$this->api();self::assertResponseStatusCodeSame(403);self::assertSame($before,$this->snapshot());
    }
    public function testInsufficientSourceAndFullDestinationRollbackBothStores(): void
    {
        $this->seed();$this->login();$before=$this->snapshot();$this->api(['quantity'=>6]);self::assertResponseStatusCodeSame(409);self::assertSame($before,$this->snapshot());self::assertFalse($this->db->fetchOne('SELECT 1 FROM stock_levels WHERE store_id = ?',[$this->stores['B']]));
        $this->seed('B',9007199254740991);$before=$this->snapshot();$this->api();self::assertResponseStatusCodeSame(409);self::assertSame($before,$this->snapshot());$this->reconciled();
    }
    public function testInventoriesOnEitherStoreBlockBothSidesAndPreserveFormValues(): void
    {
        $this->seed();$this->login();$before=$this->snapshot();foreach(['A','B'] as $store) { foreach(['in_progress','pending_validation'] as $status) { $id=$this->inventory($store,$status);$this->api();self::assertResponseStatusCodeSame(409);self::assertSame($before,$this->snapshot());$this->endInventory($id); } }
        $crawler=$this->client->request('GET','/stocks/transfert');$form=$crawler->selectButton('Enregistrer le transfert')->form(['source_store_id'=>$this->stores['A'],'destination_store_id'=>$this->stores['B'],'article_id'=>$this->article,'quantity'=>'3','reason'=>'Motif <script>']);$key=$form->getPhpValues()['_movement_key'];$id=$this->inventory('B','in_progress');$this->client->submit($form);self::assertResponseStatusCodeSame(409);self::assertInputValueSame('_movement_key',$key);self::assertInputValueSame('quantity','3');self::assertSelectorTextContains('[role=alert]','inventaire');self::assertSelectorTextContains('textarea','Motif <script>');self::assertSelectorNotExists('main script');self::assertSame($before,$this->snapshot());$this->endInventory($id);$this->client->submit($form);self::assertResponseStatusCodeSame(303);$this->reconciled();
    }
    public function testLostAssignmentAndDestinationDeactivationAfterOpeningCannotWrite(): void
    {
        $this->seed();$this->login();$c=$this->client->request('GET','/stocks/transfert');$f=$c->selectButton('Enregistrer le transfert')->form(['source_store_id'=>$this->stores['A'],'destination_store_id'=>$this->stores['B'],'article_id'=>$this->article,'quantity'=>'3']);$before=$this->snapshot();
        $this->db->executeStatement('DELETE FROM user_stores WHERE user_id = ? AND store_id = ?',[$this->users['manager'],$this->stores['B']]);$this->client->submit($f);self::assertResponseStatusCodeSame(404);self::assertSame($before,$this->snapshot());
        $this->login('admin');$this->db->executeStatement('UPDATE stores SET active = false WHERE id = ?',[$this->stores['B']]);$this->api();self::assertResponseStatusCodeSame(404);self::assertSame($before,$this->snapshot());
    }
    public function testReplayAfterArchiveOrInventoryKeepsTheSameMovementButNeedsCurrentRights(): void
    {
        $this->seed();$this->login();$first=$this->api();self::assertResponseStatusCodeSame(201);$location=$this->client->getResponse()->headers->get('Location');self::assertSame('/stocks/transferts/'.$first['data']['id'],$location);$before=$this->snapshot();$this->api(['quantity'=>2]);self::assertResponseStatusCodeSame(409);$this->api(['reason'=>'Autre contenu']);self::assertResponseStatusCodeSame(409);self::assertSame($before,$this->snapshot());
        $this->db->executeStatement('UPDATE articles SET active = false WHERE id = ?',[$this->article]);$this->inventory('B','pending_validation');self::assertSame($first,$this->api());self::assertResponseStatusCodeSame(201);self::assertSame($before,$this->snapshot());
        $this->db->executeStatement('DELETE FROM user_stores WHERE user_id = ? AND store_id = ?',[$this->users['manager'],$this->stores['B']]);$this->api();self::assertResponseStatusCodeSame(404);self::assertSame($before,$this->snapshot());
    }
    public function testReceiptRequiresBothStoresWithoutLeakingRemoteDataFromLocalHistory(): void
    {
        $this->seed();$this->login('admin');$m=$this->api(['destination_store_id'=>$this->stores['C']])['data'];$url='/stocks/transferts/'.$m['id'];$this->login('manager');$this->client->request('GET',$url);self::assertResponseStatusCodeSame(404);self::assertSelectorTextNotContains('main','Magasin C');
        $this->client->request('GET','/stocks/'.$this->stores['A'].'/'.$this->article);self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Transfert');self::assertSelectorNotExists('a[href="'.$url.'"]');self::assertStringNotContainsString($this->stores['C'],$this->client->getResponse()->getContent());
        $this->login('user');$this->client->request('GET',$url);self::assertResponseStatusCodeSame(403);$this->login('admin');$this->db->executeStatement('UPDATE stores SET active = false WHERE id = ?',[$this->stores['C']]);$this->client->request('GET',$url);self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Magasin C');self::assertSelectorNotExists('a[href="/stocks/mouvements/'.$m['id'].'/corriger"]');
        foreach(['bad-id','11111111-1111-4111-8111-111111111111',$this->db->fetchOne("SELECT id FROM stock_movements WHERE type = 'entry'")] as $id) { $this->client->request('GET','/stocks/transferts/'.$id);self::assertResponseStatusCodeSame(404); }
    }
    public function testCorrectionAndCancellationRemainVisibleOnTransferReceipts(): void
    {
        $this->seed();$this->login();$m=$this->api()['data'];$actor=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('manager@example.test');$correction=static::getContainer()->get(\App\Stock\StockCorrectionEngine::class)->correct($actor,$m['id'],(object)['quantity'=>2,'reason'=>'Erreur transfert'],'transfer-correction-key')['data'];
        $this->client->request('GET','/stocks/transferts/'.$m['id']);self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Ce transfert a été corrigé');self::assertSelectorExists('a[href="/stocks/transferts/'.$correction['replacement']['id'].'"]');$this->client->request('GET','/stocks/transferts/'.$correction['replacement']['id']);self::assertSelectorTextContains('main','2 unités transférées');
        static::getContainer()->get(\App\Stock\StockCorrectionEngine::class)->correct($actor,$correction['replacement']['id'],(object)['reason'=>'Annulation'],'transfer-cancel-key',true);$this->client->request('GET','/stocks/transferts/'.$correction['replacement']['id']);self::assertSelectorTextContains('main','Ce transfert a été annulé');self::assertSelectorNotExists('a[href="/stocks/mouvements/'.$correction['replacement']['id'].'/corriger"]');self::assertSame(5,$this->balance('A'));self::assertSame(0,$this->balance('B'));$this->reconciled();
    }
    public function testEmptyOptionsSourceContextAndStrictPresets(): void
    {
        $this->login();$c=$this->client->request('GET','/stocks');$this->client->submit($c->selectButton('Choisir')->form(['store_id'=>$this->stores['A']]));self::assertResponseStatusCodeSame(303);$this->client->request('GET','/stocks/transfert');self::assertSelectorExists('#transfer-source option[selected][value="'.$this->stores['A'].'"]');self::assertSelectorExists('nav a[aria-current="page"][href="/stocks"]');
        foreach(['source_store_id[]=x','destination_store_id=bad','created_at=2000','quantity=3'] as $query) { $this->client->request('GET','/stocks/transfert?'.$query);self::assertResponseStatusCodeSame(422); }
        foreach(['single','unassigned'] as $user) { $this->login($user);$this->client->request('GET','/stocks/transfert');self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Deux magasins actifs autorisés');self::assertSelectorExists('button[type=submit][disabled]'); }
        $this->login();$this->db->executeStatement('UPDATE articles SET active = false');$this->client->request('GET','/stocks/transfert');self::assertSelectorTextContains('main','aucun article actif');self::assertSelectorExists('button[type=submit][disabled]');
    }
    public function testFormRejectsInvalidInputsAndPreservesItsKey(): void
    {
        $this->seed();$this->login();$c=$this->client->request('GET','/stocks/transfert');$f=$c->selectButton('Enregistrer le transfert')->form(['source_store_id'=>$this->stores['A'],'destination_store_id'=>$this->stores['B'],'article_id'=>$this->article,'quantity'=>'1.5','reason'=>'Motif <script>']);$key=$f->getPhpValues()['_movement_key'];$before=$this->snapshot();$this->client->submit($f);self::assertResponseStatusCodeSame(422);self::assertInputValueSame('_movement_key',$key);self::assertInputValueSame('quantity','1.5');self::assertSelectorTextContains('textarea','Motif <script>');
        $values=$f->getPhpValues();foreach([['quantity'=>['3']],['quantity'=>'1e2'],['quantity'=>'01'],['quantity'=>'-3'],['reason'=>"\xff"],['author_id'=>$this->users['admin']],['source_store_id'=>['invalid']]] as $extra) { $this->client->request('POST','/stocks/transfert',array_replace($values,$extra));self::assertResponseStatusCodeSame(422); }
        $this->client->request('POST','/stocks/transfert',array_replace($values,['_csrf_token'=>'bad']));self::assertResponseStatusCodeSame(403);self::assertSame($before,$this->snapshot());
    }
    public function testDatabaseFailureAndAnonymousRequestsCannotLeavePartialTransfers(): void
    {
        $this->seed();$this->login();$before=$this->snapshot();$this->db->executeStatement("CREATE FUNCTION fail_transfer_event() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Panne de test après les soldes'; END $$");$this->db->executeStatement('CREATE TRIGGER fail_transfer_event BEFORE INSERT ON stock_change_events FOR EACH ROW EXECUTE FUNCTION fail_transfer_event()');$this->api();self::assertResponseStatusCodeSame(500);self::assertSame($before,$this->snapshot());$this->reconciled();
        $this->client->getCookieJar()->clear();$this->client->request('POST','/api/v1/movements/transfers',server:['CONTENT_TYPE'=>'application/json'],content:json_encode($this->body()));self::assertResponseStatusCodeSame(403);$this->client->request('GET','/api/v1/auth/csrf');$this->csrf=json_decode($this->client->getResponse()->getContent(),true)['token'];$this->api();self::assertResponseStatusCodeSame(401);$this->client->request('GET','/stocks/transfert');self::assertResponseRedirects('/connexion');$this->client->request('GET','/stocks/transferts/11111111-1111-4111-8111-111111111111');self::assertResponseRedirects('/connexion');self::assertSame($before,$this->snapshot());
    }
}
