<?php

declare(strict_types=1);

namespace App\Tests;

use App\Api\OpenApiContract;
use App\Security\StockUserProvider;
use App\Stock\StockMovementEngine;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StockReturnTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private array $users=[], $stores=[];
    private string $article, $inactive, $csrf;
    protected function setUp(): void
    {
        $this->client=static::createClient();$this->client->disableReboot();$this->db=static::getContainer()->get(Connection::class);$this->db->beginTransaction();
        $schema='stk07_'.bin2hex(random_bytes(6));$this->db->executeStatement('CREATE SCHEMA '.$schema);$this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        foreach(explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) { if(trim($sql)!=='') { $this->db->executeStatement($sql); } }
        $this->db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
        foreach(['20261018140000','20261018200000','20261018223000','20261023220000'] as $version) { require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';$class='DoctrineMigrations\\Version'.$version;$migration=new $class($this->db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) { $this->db->executeStatement($sql->getStatement()); } }
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
    private function move(string $type,array $body): array
    {
        $actor=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('admin@example.test');return static::getContainer()->get(StockMovementEngine::class)->record($actor,$type,(object)$body,bin2hex(random_bytes(16)))['data'];
    }
    private function consume(string $consumer='user',string $store='A',int $quantity=4,bool $seed=true): array
    {
        if($seed) { $this->move('entry',['article_id'=>$this->article,'store_id'=>$this->stores[$store],'quantity'=>10,'reason'=>'Initialisation fictive']); }
        return $this->move('consumption',['article_id'=>$this->article,'store_id'=>$this->stores[$store],'quantity'=>$quantity,'consumer_id'=>$this->users[$consumer],'reference'=>'Chantier fictif']);
    }
    private function api(string $id,array $extra=[],string $key='return-request-key'): array
    {
        $this->client->request('POST','/api/v1/movements/returns',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>$key],content:json_encode(array_replace(['consumption_id'=>$id,'quantity'=>2],$extra),JSON_PRESERVE_ZERO_FRACTION));return json_decode($this->client->getResponse()->getContent(),true)??[];
    }
    private function balance(string $store='A'): int { return (int)$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE store_id = ? AND article_id = ?',[$this->stores[$store],$this->article]); }
    private function snapshot(): array { return ['balances'=>$this->db->fetchAllAssociative('SELECT * FROM stock_levels ORDER BY article_id,store_id'),'movements'=>(int)$this->db->fetchOne('SELECT count(*) FROM stock_movements'),'events'=>(int)$this->db->fetchOne('SELECT count(*) FROM stock_change_events'),'audits'=>(int)$this->db->fetchOne('SELECT count(*) FROM audit_logs')]; }
    private function reconciled(): void { self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference <> 0')); }
    private function correction(string $id,array $body,bool $cancel=false): array
    {
        $actor=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('admin@example.test');return static::getContainer()->get(\App\Stock\StockCorrectionEngine::class)->correct($actor,$id,(object)$body,bin2hex(random_bytes(16)),$cancel)['data'];
    }
    public function testPartialThenTotalReturnCreditsOnlyTheOriginAndKeepsDistinctTrace(): void
    {
        $c=$this->consume();$original=$this->db->fetchAssociative('SELECT * FROM stock_movements WHERE id = ?',[$c['id']]);$this->login();$before=time();$r=$this->api($c['id'],['comment'=>'Non utilisés'])['data'];self::assertResponseStatusCodeSame(201);self::assertSame(8,$this->balance());self::assertSame('return',$r['type']);self::assertSame($c['id'],$r['related_movement_id']);self::assertSame($this->stores['A'],$r['destination_store_id']);self::assertNull($r['source_store_id']);self::assertNull($r['consumer_id']);self::assertSame($this->users['user'],$r['author_id']);self::assertSame($this->article,$r['article_id']);self::assertSame('Chantier fictif',$r['reference']);self::assertGreaterThanOrEqual($before,(new \DateTimeImmutable($r['created_at']))->getTimestamp());self::assertTrue(static::getContainer()->get(OpenApiContract::class)->valid(json_decode(json_encode(['data'=>$r])),['$ref'=>'#/components/schemas/MovementResource']));
        self::assertSame('/consommations/retours/'.$r['id'],$this->client->getResponse()->headers->get('Location'));$this->client->request('GET','/consommations/'.$c['id']);self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Unités rendues : 2');self::assertSelectorTextContains('main','Encore à rendre : 2');
        $this->api($c['id'],key:'second-partial-return');self::assertResponseStatusCodeSame(201);self::assertSame(10,$this->balance());$this->client->request('GET','/consommations/'.$c['id']);self::assertSelectorTextContains('main','Encore à rendre : 0');self::assertSelectorNotExists('a[href="/consommations/'.$c['id'].'/retour"]');$before=$this->snapshot();$this->api($c['id'],['quantity'=>1],key:'excessive-return-key');self::assertResponseStatusCodeSame(409);self::assertSame($before,$this->snapshot());self::assertSame($original,$this->db->fetchAssociative('SELECT * FROM stock_movements WHERE id = ?',[$c['id']]));self::assertSame(4,$before['movements']);self::assertSame(4,$before['events']);self::assertSame(4,$before['audits']);$this->reconciled();
    }
    public function testOwnReturnSurvivesUnassignmentWithoutGivingStockAccess(): void
    {
        $c=$this->consume();$this->db->executeStatement('DELETE FROM user_stores WHERE user_id = ?',[$this->users['user']]);$this->login();$this->client->request('GET','/consommations/retours');self::assertResponseIsSuccessful();self::assertSelectorExists('a[href="/consommations/'.$c['id'].'/retour"]');$r=$this->api($c['id'])['data'];self::assertResponseStatusCodeSame(201);self::assertSame(8,$this->balance());$this->client->request('GET','/consommations/retours/'.$r['id']);self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','2 unités rendues');self::assertSelectorNotExists('a[href="/stocks/'.$this->stores['A'].'/'.$this->article.'"]');self::assertSelectorNotExists('a[href="/stocks/mouvements/'.$r['id'].'/corriger"]');$this->client->request('GET','/api/v1/stores/'.$this->stores['A'].'/stocks');self::assertResponseStatusCodeSame(404);
    }
    public function testForeignConsumptionAndInactiveOrUnassignedStoresAreDenied(): void
    {
        $foreign=$this->consume('tech');$own=$this->consume();$outside=$this->consume('tech','B');$before=$this->snapshot();$this->login();foreach([$foreign['id'],$outside['id']] as $id) { $this->api($id);self::assertResponseStatusCodeSame(404);$this->client->request('GET','/consommations/'.$id.'/retour');self::assertResponseStatusCodeSame(404); }
        $this->login('manager');$this->api($outside['id']);self::assertResponseStatusCodeSame(404);$this->login('unassigned');$this->api($own['id']);self::assertResponseStatusCodeSame(404);
        $this->db->executeStatement('UPDATE stores SET active = false WHERE id = ?',[$this->stores['A']]);foreach(['user','manager','admin'] as $actor) { $this->login($actor);$this->api($own['id']);self::assertResponseStatusCodeSame(404); }self::assertSame($before,$this->snapshot());
        $this->login('admin');$this->api($outside['id']);self::assertResponseStatusCodeSame(201);self::assertSame(8,$this->balance('B'));$this->reconciled();
    }
    public function testManagerCanReturnForAnotherBeneficiaryAndArchivedArticlesRemainReturnable(): void
    {
        $c=$this->consume('tech');$this->db->executeStatement('UPDATE articles SET active = false WHERE id = ?',[$this->article]);$this->db->executeStatement('UPDATE users SET active = false WHERE id = ?',[$this->users['tech']]);$this->login('manager');$this->client->request('GET','/consommations/retours');self::assertSelectorExists('a[href="/consommations/'.$c['id'].'/retour"]');$r=$this->api($c['id'])['data'];self::assertResponseStatusCodeSame(201);self::assertSame($this->users['manager'],$r['author_id']);self::assertSame(8,$this->balance());$this->client->request('GET','/consommations/retours/'.$r['id']);self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Nom tech');self::assertSelectorTextContains('main','Retour enregistré par : Nom manager');$this->reconciled();
    }
    public function testReplayAndConflictingPayloadNeverCreditTwice(): void
    {
        $c=$this->consume();$this->login();$first=$this->api($c['id']);self::assertResponseStatusCodeSame(201);$before=$this->snapshot();self::assertSame($first,$this->api($c['id']));self::assertResponseStatusCodeSame(201);$this->api($c['id'],['quantity'=>1]);self::assertResponseStatusCodeSame(409);$this->api($c['id'],['comment'=>'Autre']);self::assertResponseStatusCodeSame(409);self::assertSame($before,$this->snapshot());
        $this->db->executeStatement("INSERT INTO inventories(store_id,created_by,status,snapshot_at) VALUES (?,?,'in_progress',clock_timestamp())",[$this->stores['A'],$this->users['admin']]);$this->db->executeStatement('UPDATE articles SET active = false WHERE id = ?',[$this->article]);self::assertSame($first,$this->api($c['id']));self::assertResponseStatusCodeSame(201);self::assertSame($before,$this->snapshot());
    }
    public function testInventoriesAndCapacityBlockWithoutAnyPartialCredit(): void
    {
        $c=$this->consume();$this->login();$before=$this->snapshot();foreach(['in_progress','pending_validation'] as $state) { $id=$this->db->fetchOne('INSERT INTO inventories(store_id,created_by,status,snapshot_at) VALUES (?,?,?,clock_timestamp()) RETURNING id',[$this->stores['A'],$this->users['admin'],$state]);$this->api($c['id']);self::assertResponseStatusCodeSame(409);self::assertSame($before,$this->snapshot());$this->db->executeStatement("UPDATE inventories SET status = 'cancelled',cancelled_at = clock_timestamp() WHERE id = ?",[$id]); }
        $this->move('adjustment',['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>9007199254740985,'reason'=>'Borne maximale']);$before=$this->snapshot();$this->api($c['id']);self::assertResponseStatusCodeSame(409);self::assertSame($before,$this->snapshot());$this->reconciled();
    }
    public function testInvalidBodiesCannotChooseAnotherArticleStoreBeneficiaryOrDate(): void
    {
        $c=$this->consume();$this->login();$before=$this->snapshot();foreach([0,-1,1.0,1.5,'2',true,null,9007199254740992] as $quantity) { $this->api($c['id'],['quantity'=>$quantity]);self::assertResponseStatusCodeSame(422); }
        foreach([['comment'=>str_repeat('a',2001)],['comment'=>"bad\0text"],['article_id'=>$this->inactive],['consumer_id'=>$this->users['tech']],['created_at'=>'2000-01-01T00:00:00Z'],['author_id'=>$this->users['admin']]] as $extra) { $this->api($c['id'],$extra);self::assertResponseStatusCodeSame(422); }
        $this->api('bad-id');self::assertResponseStatusCodeSame(422);$this->api('11111111-1111-4111-8111-111111111111');self::assertResponseStatusCodeSame(404);$entry=$this->db->fetchOne("SELECT id FROM stock_movements WHERE type = 'entry'");$this->api($entry);self::assertResponseStatusCodeSame(404);$this->api($c['id'],key:'bad');self::assertResponseStatusCodeSame(422);$this->csrf='bad';$this->api($c['id']);self::assertResponseStatusCodeSame(403);self::assertSame($before,$this->snapshot());
    }
    public function testFormFromEligibleConsumptionAndDoubleSubmission(): void
    {
        $c=$this->consume();$this->login();$this->client->request('GET','/consommations/nouvelle');self::assertSelectorExists('a[href="/consommations/retours/nouveau"]');$this->client->request('GET','/consommations/retours');self::assertResponseIsSuccessful();self::assertSelectorTextContains('h1','Rendre des articles');self::assertSelectorExists('a[href="/consommations/'.$c['id'].'/retour"]');$crawler=$this->client->request('GET','/consommations/'.$c['id'].'/retour');self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Encore à rendre : 4');self::assertSelectorExists('select#return-store');
        $form=$crawler->selectButton('Enregistrer le retour')->form(['quantity'=>'2','comment'=>'0']);$this->client->submit($form);self::assertResponseStatusCodeSame(303);$location=$this->client->getResponse()->headers->get('Location');$this->client->followRedirect();self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Le retour de 2 unités');self::assertSelectorTextContains('main','Commentaire : 0');self::assertSelectorTextContains('main','Magasin de dépôt : A');$before=$this->snapshot();$this->client->submit($form);self::assertResponseStatusCodeSame(303);self::assertSame($location,$this->client->getResponse()->headers->get('Location'));self::assertSame($before,$this->snapshot());$this->reconciled();
    }
    public function testExplicitDepositCanDifferFromOriginAndFavoritePrefillsTheDetailedForm(): void
    {
        $c=$this->consume();$this->db->executeStatement('UPDATE users SET favorite_store_id=? WHERE id=?',[$this->stores['B'],$this->users['user']]);$this->login();
        $this->client->request('GET','/consommations/'.$c['id'].'/retour');self::assertSelectorExists('#return-store option[selected][value="'.$this->stores['B'].'"]');
        $return=$this->api($c['id'],['store_id'=>$this->stores['B']])['data'];self::assertResponseStatusCodeSame(201);self::assertSame($this->stores['B'],$return['destination_store_id']);self::assertSame(6,$this->balance());self::assertSame(2,(int)$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE store_id=? AND article_id=?',[$this->stores['B'],$this->article]));$this->reconciled();
    }
    public function testAdminCanDepositAnAnonymizedConsumptionFromAnInactiveOriginElsewhere(): void
    {
        $c=$this->consume();$anonymous=\App\Privacy\AnonymousIdentity::ID;
        $this->db->executeStatement('INSERT INTO users(id,active,anonymized_at,role_id) VALUES(?,false,clock_timestamp(),3)',[$anonymous]);
        $this->db->executeStatement('ALTER TABLE stock_movements DISABLE TRIGGER movements_immutable');$this->db->executeStatement('UPDATE stock_movements SET consumer_id=?,author_id=? WHERE id=?',[$anonymous,$anonymous,$c['id']]);$this->db->executeStatement('ALTER TABLE stock_movements ENABLE TRIGGER movements_immutable');$this->db->executeStatement('UPDATE stores SET active=false WHERE id=?',[$this->stores['A']]);$this->login('admin');
        $return=$this->api($c['id'],['store_id'=>$this->stores['B']])['data'];self::assertResponseStatusCodeSame(201);self::assertSame($this->stores['B'],$return['destination_store_id']);$this->reconciled();
    }
    public function testFormValidationKeepsKeyAndHandlesConcurrentReturn(): void
    {
        $c=$this->consume();$this->login();$url='/consommations/'.$c['id'].'/retour';$crawler=$this->client->request('GET',$url);$form=$crawler->selectButton('Enregistrer le retour')->form(['quantity'=>'1e2','comment'=>'Texte <script>']);$key=$form->getPhpValues()['_movement_key'];$this->client->submit($form);self::assertResponseStatusCodeSame(422);self::assertInputValueSame('_movement_key',$key);self::assertInputValueSame('quantity','1e2');self::assertSelectorTextContains('textarea','Texte <script>');self::assertSelectorNotExists('main script');$before=$this->snapshot();
        foreach([['quantity'=>['2']],['quantity'=>'01'],['quantity'=>'-1'],['comment'=>"\xff"],['consumption_id'=>$c['id']],['destination_store_id'=>$this->stores['B']]] as $extra) { $this->client->request('POST',$url,array_replace($form->getPhpValues(),$extra));self::assertResponseStatusCodeSame(422); }$this->client->request('POST',$url,array_replace($form->getPhpValues(),['_csrf_token'=>'bad']));self::assertResponseStatusCodeSame(403);self::assertSame($before,$this->snapshot());
        $this->api($c['id'],['quantity'=>3]);self::assertResponseStatusCodeSame(201);$form=$this->client->request('GET',$url)->selectButton('Enregistrer le retour')->form(['quantity'=>'2']);$this->client->submit($form);self::assertResponseStatusCodeSame(409);self::assertInputValueSame('quantity','2');self::assertSame(9,$this->balance());
    }
    public function testCorrectedAndCancelledConsumptionRejectNewReturns(): void
    {
        $c=$this->consume();$corrected=$this->correction($c['id'],['quantity'=>3,'reason'=>'Erreur']);$this->login();$before=$this->snapshot();$this->api($c['id']);self::assertResponseStatusCodeSame(409);self::assertSame($before,$this->snapshot());$this->client->request('GET','/consommations/'.$c['id'].'/retour');self::assertResponseIsSuccessful();self::assertSelectorExists('button[type=submit][disabled]');self::assertSelectorExists('a[href="/consommations/'.$corrected['replacement']['id'].'/retour"]');
        $this->correction($corrected['replacement']['id'],['reason'=>'Annulation'],true);$before=$this->snapshot();$this->api($corrected['replacement']['id']);self::assertResponseStatusCodeSame(409);self::assertSame($before,$this->snapshot());$this->client->request('GET','/consommations/retours');self::assertSelectorTextContains('main','Aucune consommation autorisée');$this->reconciled();
    }
    public function testReturnCorrectionsAndCancellationReopenOnlyTheEffectiveRemainder(): void
    {
        $c=$this->consume();$this->login();$r=$this->api($c['id'],['quantity'=>3])['data'];$corrected=$this->correction($r['id'],['quantity'=>1,'reason'=>'Un article rendu']);self::assertSame(7,$this->balance());$this->api($c['id'],['quantity'=>3],key:'complete-after-correction');self::assertResponseStatusCodeSame(201);self::assertSame(10,$this->balance());
        $this->correction($corrected['replacement']['id'],['reason'=>'Retour annulé'],true);self::assertSame(9,$this->balance());$this->api($c['id'],['quantity'=>1],key:'one-unit-reopened');self::assertResponseStatusCodeSame(201);self::assertSame(10,$this->balance());$this->client->request('GET','/consommations/retours/'.$r['id']);self::assertSelectorTextContains('main','Ce retour a été corrigé');self::assertSelectorExists('a[href="/consommations/retours/'.$corrected['replacement']['id'].'"]');$this->client->request('GET','/consommations/retours/'.$corrected['replacement']['id']);self::assertSelectorTextContains('main','Ce retour a été annulé');$this->reconciled();
    }
    public function testEligibleListIsScopedBeforePaginationAndExcludesCompletedOrigins(): void
    {
        $this->move('entry',['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>100,'reason'=>'Initialisation']);$ids=[];for($i=0;$i<27;$i++) { $ids[]=$this->consume('user','A',1,false)['id']; }$foreign=$this->consume('tech','A',1,false);$this->login();$this->api($ids[0],['quantity'=>1]);self::assertResponseStatusCodeSame(201);$this->client->request('GET','/consommations/retours');self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','26 consommations');self::assertSelectorCount(25,'a[href$="/retour"]');self::assertSelectorNotExists('a[href="/consommations/'.$foreign['id'].'/retour"]');self::assertSelectorNotExists('a[href="/consommations/'.$ids[0].'/retour"]');$this->client->request('GET','/consommations/retours?page=2');self::assertResponseIsSuccessful();self::assertSelectorCount(1,'a[href$="/retour"]');
        foreach(['page=0','page=10001','page[]=2','page=1e2','store_id='.$this->stores['B']] as $query) { $this->client->request('GET','/consommations/retours?'.$query);self::assertResponseStatusCodeSame(422); }
        $this->login('unassigned');$this->client->request('GET','/consommations/retours');self::assertSelectorTextContains('main','Aucune consommation autorisée');
    }
    public function testReceiptPrivacyInactiveStoreAndUnknownIds(): void
    {
        $c=$this->consume();$this->login();$r=$this->api($c['id'])['data'];$url='/consommations/retours/'.$r['id'];$this->login('tech');$this->client->request('GET',$url);self::assertResponseStatusCodeSame(404);$this->login('unassigned');$this->client->request('GET',$url);self::assertResponseStatusCodeSame(404);$this->login('manager');$this->client->request('GET',$url);self::assertResponseIsSuccessful();self::assertSelectorExists('a[href="/stocks/mouvements/'.$r['id'].'/corriger"]');
        $this->db->executeStatement('UPDATE stores SET active = false WHERE id = ?',[$this->stores['A']]);$this->login();$this->client->request('GET',$url);self::assertResponseStatusCodeSame(404);$this->login('admin');$this->client->request('GET',$url);self::assertResponseIsSuccessful();self::assertSelectorNotExists('a[href="/stocks/mouvements/'.$r['id'].'/corriger"]');foreach(['bad-id','11111111-1111-4111-8111-111111111111',$c['id']] as $id) { $this->client->request('GET','/consommations/retours/'.$id);self::assertResponseStatusCodeSame(404); }
    }
    public function testFailureRollsBackEverythingAndAnonymousRequestsCannotCredit(): void
    {
        $c=$this->consume();$this->login();$before=$this->snapshot();$this->db->executeStatement("CREATE FUNCTION fail_return_event() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Panne de test'; END $$");$this->db->executeStatement('CREATE TRIGGER fail_return_event BEFORE INSERT ON stock_change_events FOR EACH ROW EXECUTE FUNCTION fail_return_event()');$this->api($c['id']);self::assertResponseStatusCodeSame(500);self::assertSame($before,$this->snapshot());$this->reconciled();
        $this->client->getCookieJar()->clear();$this->client->request('POST','/api/v1/movements/returns',server:['CONTENT_TYPE'=>'application/json'],content:json_encode(['consumption_id'=>$c['id'],'quantity'=>1]));self::assertResponseStatusCodeSame(403);$this->client->request('GET','/api/v1/auth/csrf');$this->csrf=json_decode($this->client->getResponse()->getContent(),true)['token'];$this->api($c['id']);self::assertResponseStatusCodeSame(401);$this->client->request('GET','/consommations/retours');self::assertResponseRedirects('/connexion');self::assertSame($before,$this->snapshot());
    }
}
