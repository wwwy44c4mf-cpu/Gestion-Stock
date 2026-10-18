<?php

declare(strict_types=1);

namespace App\Tests;

use App\Api\OpenApiContract;
use App\Security\StockUserProvider;
use App\Stock\StockMovementEngine;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StockCorrectionTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private array $users=[], $stores=[];
    private string $article, $inactive, $csrf;
    protected function setUp(): void
    {
        $this->client=static::createClient();$this->client->disableReboot();$this->db=static::getContainer()->get(Connection::class);$this->db->beginTransaction();
        $schema='stk05_'.bin2hex(random_bytes(6));$this->db->executeStatement('CREATE SCHEMA '.$schema);$this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        foreach(explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) { if(trim($sql)!=='') { $this->db->executeStatement($sql); } }
        $this->db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
        foreach(['20261018140000','20261018200000','20261018223000'] as $version) { require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';$class='DoctrineMigrations\\Version'.$version;$migration=new $class($this->db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) { $this->db->executeStatement($sql->getStatement()); } }
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
    private function move(string $operation,array $body,string $key='' ): array
    {
        $actor=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('admin@example.test');
        return static::getContainer()->get(StockMovementEngine::class)->record($actor,$operation,(object)$body,$key !== '' ? $key : bin2hex(random_bytes(12)))['data'];
    }
    private function entry(int $quantity=10): array { return $this->move('entry',['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>$quantity,'reason'=>'Réception initiale','reference'=>'BL-EXEMPLE']); }
    private function correct(string $id,array $body=['quantity'=>8,'reason'=>'Erreur de saisie'],string $key='correction-request',bool $cancel=false): array
    {
        $this->client->request('POST','/api/v1/movements/'.$id.($cancel?'/reverse':'/correct'),server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>$key],content:json_encode($body,JSON_PRESERVE_ZERO_FRACTION));
        return json_decode($this->client->getResponse()->getContent(),true)??[];
    }
    private function balance(string $store='A'): int { return (int)$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE article_id = ? AND store_id = ?',[$this->article,$this->stores[$store]]); }
    private function unchanged(array $before): void { self::assertSame($before,$this->totals()); }
    private function reconciled(): void { self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference <> 0')); }

    public function testEntryTenCorrectedToEightAfterConsumptionUsesOnlyTheNetDelta(): void
    {
        $entry=$this->entry();$snapshot=$this->db->fetchAssociative('SELECT * FROM stock_movements WHERE id = ?',[$entry['id']]);
        $this->move('consumption',['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>5]);$this->login('manager');
        $result=$this->correct($entry['id']);self::assertResponseStatusCodeSame(201);self::assertSame(3,$this->balance());
        self::assertTrue(static::getContainer()->get(OpenApiContract::class)->valid(json_decode(json_encode($result)),['$ref'=>'#/components/schemas/MovementCorrectionResource']));
        $data=$result['data'];self::assertSame($entry['id'],$data['original_movement_id']);self::assertSame('reversal',$data['reversal']['type']);self::assertSame(10,$data['reversal']['quantity']);self::assertSame($entry['id'],$data['reversal']['related_movement_id']);self::assertSame(8,$data['replacement']['quantity']);self::assertSame('entry',$data['replacement']['type']);self::assertSame('BL-EXEMPLE',$data['replacement']['reference']);self::assertSame($this->users['manager'],$data['replacement']['author_id']);
        self::assertSame($snapshot,$this->db->fetchAssociative('SELECT * FROM stock_movements WHERE id = ?',[$entry['id']]));self::assertSame(4,(int)$this->db->fetchOne('SELECT count(*) FROM stock_change_events'));self::assertSame(4,(int)$this->db->fetchOne('SELECT count(*) FROM audit_logs'));self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM movement_corrections'));$this->reconciled();
    }
    public function testReplayConflictsAndSecondCancellationAreSafe(): void
    {
        $entry=$this->entry();$this->login('admin');$first=$this->correct($entry['id']);$before=$this->totals();self::assertSame($first,$this->correct($entry['id']));self::assertResponseStatusCodeSame(201);$this->unchanged($before);
        $this->correct($entry['id'],['quantity'=>7,'reason'=>'Autre contenu']);self::assertResponseStatusCodeSame(409);$this->unchanged($before);
        $this->correct($entry['id'],key:'second-correction');self::assertResponseStatusCodeSame(409);$this->correct($entry['id'],['reason'=>'Annuler'],key:'cancel-already-corrected',cancel:true);self::assertResponseStatusCodeSame(409);$this->unchanged($before);
        $this->correct($first['data']['reversal']['id'],['reason'=>'Annuler annulation'],key:'cancel-reversal',cancel:true);self::assertResponseStatusCodeSame(409);$this->unchanged($before);
        $this->correct($first['data']['replacement']['id'],['quantity'=>9,'reason'=>'Nouvelle correction'],key:'correct-replacement');self::assertResponseStatusCodeSame(201);self::assertSame(9,$this->balance());$this->reconciled();
    }
    public function testCancellationPreservesOriginalAndCanReplay(): void
    {
        $entry=$this->entry();$this->login('manager');$result=$this->correct($entry['id'],['reason'=>'Doublon'],cancel:true);self::assertResponseStatusCodeSame(201);self::assertSame(0,$this->balance());self::assertSame(10,$result['data']['quantity']);self::assertSame($entry['id'],$result['data']['related_movement_id']);$before=$this->totals();self::assertSame($result,$this->correct($entry['id'],['reason'=>'Doublon'],cancel:true));self::assertResponseStatusCodeSame(201);$this->correct($entry['id'],['reason'=>'Autre annulation'],key:'another-cancellation',cancel:true);self::assertResponseStatusCodeSame(409);$this->unchanged($before);$this->reconciled();
    }
    public function testInsufficientStockAndInventoryRollbackTheWholeCorrection(): void
    {
        $entry=$this->entry();$this->move('consumption',['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>9]);$this->login('admin');$before=$this->totals();
        $this->correct($entry['id']);self::assertResponseStatusCodeSame(409);$this->unchanged($before);$this->correct($entry['id'],['reason'=>'Annuler'],cancel:true);self::assertResponseStatusCodeSame(409);$this->unchanged($before);
        foreach(['in_progress','pending_validation'] as $state) {
            $inventory=$this->db->fetchOne('INSERT INTO inventories(store_id,created_by,status,snapshot_at) VALUES (?,?,?,clock_timestamp()) RETURNING id',[$this->stores['A'],$this->users['admin'],$state]);
            $this->correct($entry['id'],['quantity'=>11,'reason'=>'Plus un']);self::assertResponseStatusCodeSame(409);$this->unchanged($before);$this->db->executeStatement("UPDATE inventories SET status = 'cancelled',cancelled_at=clock_timestamp() WHERE id = ?",[$inventory]);
        }
        $this->correct($entry['id'],['quantity'=>11,'reason'=>'Plus un']);self::assertResponseStatusCodeSame(201);self::assertSame(2,$this->balance());$this->reconciled();
    }
    public function testUsersOtherStoresInactiveStoresAndRevokedAssignmentsAreRejected(): void
    {
        $entry=$this->entry();$before=$this->totals();$this->login('user');$this->correct($entry['id']);self::assertResponseStatusCodeSame(403);$this->client->request('GET','/stocks/mouvements/'.$entry['id'].'/corriger');self::assertResponseStatusCodeSame(403);
        $this->login('unassigned');$this->correct($entry['id']);self::assertResponseStatusCodeSame(404);
        $this->login('manager');$this->client->request('GET','/stocks/mouvements/'.$entry['id'].'/corriger');self::assertResponseIsSuccessful();$this->db->executeStatement('DELETE FROM user_stores WHERE user_id = ?',[$this->users['manager']]);$this->correct($entry['id']);self::assertResponseStatusCodeSame(404);
        $this->login('admin');$this->db->executeStatement('UPDATE stores SET active = false WHERE id = ?',[$this->stores['A']]);$this->correct($entry['id']);self::assertResponseStatusCodeSame(404);$this->unchanged($before);
    }
    public function testConsumptionCorrectionPreservesBeneficiaryAndComment(): void
    {
        $this->entry(15);$m=$this->move('consumption',['article_id'=>$this->article,'store_id'=>$this->stores['A'],'consumer_id'=>$this->users['tech'],'quantity'=>4,'reference'=>'Chantier','comment'=>'Commentaire fictif']);$this->login('manager');
        $r=$this->correct($m['id'],['quantity'=>6,'reason'=>'Deux unités oubliées']);self::assertResponseStatusCodeSame(201);self::assertSame(9,$this->balance());self::assertSame($this->users['tech'],$r['data']['replacement']['consumer_id']);self::assertSame('Commentaire fictif',$r['data']['replacement']['comment']);
        $this->login('tech');$this->client->request('GET','/consommations/'.$m['id']);self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Cette consommation a été corrigée');self::assertSelectorExists('a[href="/consommations/'.$r['data']['replacement']['id'].'"]');$this->login('manager');
        $this->correct($r['data']['replacement']['id'],['reason'=>'Annulation'],key:'cancel-consumption',cancel:true);self::assertResponseStatusCodeSame(201);self::assertSame(15,$this->balance());$this->reconciled();
    }
    public function testTransfersRequireBothStoresAndReconcileBothBalances(): void
    {
        $this->entry();$m=$this->move('transfer',['article_id'=>$this->article,'source_store_id'=>$this->stores['A'],'destination_store_id'=>$this->stores['B'],'quantity'=>4]);$this->login('manager');$before=$this->totals();$this->correct($m['id'],['quantity'=>2,'reason'=>'Erreur transfert']);self::assertResponseStatusCodeSame(404);$this->unchanged($before);
        $this->client->request('GET','/stocks/'.$this->stores['A'].'/'.$this->article);self::assertResponseIsSuccessful();self::assertSelectorNotExists('a[href="/stocks/mouvements/'.$m['id'].'/corriger"]');
        $this->login('admin');$inv=$this->db->fetchOne("INSERT INTO inventories(store_id,created_by,status,snapshot_at) VALUES (?,?,'in_progress',clock_timestamp()) RETURNING id",[$this->stores['B'],$this->users['admin']]);$this->correct($m['id'],['quantity'=>2,'reason'=>'Erreur transfert']);self::assertResponseStatusCodeSame(409);$this->unchanged($before);$this->db->executeStatement("UPDATE inventories SET status = 'cancelled',cancelled_at=clock_timestamp() WHERE id = ?",[$inv]);
        $this->correct($m['id'],['quantity'=>2,'reason'=>'Erreur transfert']);self::assertResponseStatusCodeSame(201);self::assertSame(8,$this->balance());self::assertSame(2,$this->balance('B'));$this->reconciled();
    }
    public function testInvalidQuantitiesReasonIdsCsrfAndExtraFieldsCannotWrite(): void
    {
        $entry=$this->entry();$this->login('admin');$before=$this->totals();
        foreach([0,-1,1.0,1.5,'8',true,null,9007199254740992] as $quantity) { $this->correct($entry['id'],['quantity'=>$quantity,'reason'=>'Erreur']);self::assertResponseStatusCodeSame(422); }
        foreach(['', '  ',str_repeat('a',2001),"nul\0texte"] as $reason) { $this->correct($entry['id'],['quantity'=>8,'reason'=>$reason]);self::assertResponseStatusCodeSame(422); }
        $this->correct($entry['id'],['quantity'=>10,'reason'=>'Identique']);self::assertResponseStatusCodeSame(422);$this->correct($entry['id'],['quantity'=>8,'reason'=>'Erreur','author_id'=>$this->users['admin']]);self::assertResponseStatusCodeSame(422);
        foreach(['not-a-uuid','11111111-1111-4111-8111-111111111111'] as $id) { $this->correct($id);self::assertResponseStatusCodeSame(404); }
        $this->correct($entry['id'],key:'bad');self::assertResponseStatusCodeSame(422);$this->csrf='invalid';$this->correct($entry['id']);self::assertResponseStatusCodeSame(403);$this->unchanged($before);
    }
    public function testCorrectionFormAndDoubleSubmission(): void
    {
        $entry=$this->entry();$this->login('manager');$c=$this->client->request('GET','/stocks/mouvements/'.$entry['id'].'/corriger');self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','10 unités');
        $form=$c->selectButton('Enregistrer la correction')->form(['quantity'=>'8','reason'=>'Erreur <script>']);$this->client->submit($form);self::assertResponseStatusCodeSame(303);$this->client->followRedirect();self::assertResponseIsSuccessful();self::assertSelectorTextContains('h2','Quantité actuelle : 8');self::assertSelectorTextContains('main','Corrigé');$this->client->submit($form);self::assertResponseStatusCodeSame(303);self::assertSame(8,$this->balance());
        $this->client->request('GET','/stocks/mouvements/'.$entry['id'].'/corriger');self::assertSelectorNotExists('form[action="/stocks/mouvements/'.$entry['id'].'/corriger"]');self::assertSelectorTextContains('main','déjà été annulé ou corrigé');self::assertSelectorTextContains('main','Motif enregistré : Erreur <script>');$this->reconciled();
    }
    public function testFormErrorsKeepValuesAndCancellationNeedsExplicitSubmission(): void
    {
        $entry=$this->entry();$this->login('admin');$url='/stocks/mouvements/'.$entry['id'];$c=$this->client->request('GET',$url.'/corriger');$f=$c->selectButton('Enregistrer la correction')->form(['quantity'=>'1e2','reason'=>'Texte <script>']);$key=$f->getPhpValues()['_movement_key'];$this->client->submit($f);self::assertResponseStatusCodeSame(422);self::assertInputValueSame('_movement_key',$key);self::assertInputValueSame('quantity','1e2');self::assertSelectorTextContains('textarea','Texte <script>');
        $values=$f->getPhpValues();$values['quantity']=['8'];$this->client->request('POST',$url.'/corriger',$values);self::assertResponseStatusCodeSame(422);$values=$f->getPhpValues();$values['_csrf_token']='bad';$this->client->request('POST',$url.'/corriger',$values);self::assertResponseStatusCodeSame(403);self::assertSame(10,$this->balance());
        $c=$this->client->request('GET',$url.'/annuler');self::assertResponseIsSuccessful();self::assertSame(10,$this->balance());$form=$c->selectButton('Confirmer l’annulation')->form(['reason'=>'Doublon']);$this->client->submit($form);self::assertResponseStatusCodeSame(303);self::assertSame(0,$this->balance());$this->reconciled();
    }
    public function testSignedAdjustmentsAndArchivedArticles(): void
    {
        $this->entry();$this->login('manager');$this->client->request('POST','/api/v1/movements/adjustments',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>'adjust-minus-two'],content:json_encode(['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>-2,'reason'=>'Écart constaté']));self::assertResponseStatusCodeSame(201);$m=json_decode($this->client->getResponse()->getContent(),true)['data'];self::assertSame(8,$this->balance());
        $this->correct($m['id'],['quantity'=>3,'reason'=>'Retrait de trois en réalité']);self::assertResponseStatusCodeSame(201);self::assertSame(7,$this->balance());
        $this->db->executeStatement('UPDATE articles SET active = false WHERE id = ?',[$this->article]);$c=$this->client->request('GET','/stocks/ajustement?store_id='.$this->stores['A'].'&article_id='.$this->article);self::assertResponseIsSuccessful();self::assertSelectorTextContains('select[name=article_id]','désactivé');$f=$c->selectButton('Enregistrer l’ajustement')->form(['quantity'=>'2','reason'=>'Écart positif']);$this->client->submit($f);self::assertResponseStatusCodeSame(303);self::assertSame(9,$this->balance());$this->reconciled();
        $this->login('user');$this->client->request('GET','/stocks/ajustement');self::assertResponseStatusCodeSame(403);
    }
    public function testDatabaseFailureRollsBackBalancePairEventsAndAudit(): void
    {
        $entry=$this->entry();$this->login('admin');$before=$this->totals();
        $this->db->executeStatement("CREATE FUNCTION fail_correction_test() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Panne simulée'; END $$");$this->db->executeStatement('CREATE TRIGGER fail_correction_test BEFORE INSERT ON movement_corrections FOR EACH ROW EXECUTE FUNCTION fail_correction_test()');
        $this->correct($entry['id']);self::assertResponseStatusCodeSame(500);$this->unchanged($before);self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM movement_corrections'));$this->reconciled();
    }
    public function testReturnsBlockConsumptionCorrectionAndRemainBounded(): void
    {
        $this->entry();$m=$this->move('consumption',['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>4]);
        $return=$this->db->fetchOne("INSERT INTO stock_movements(type,article_id,destination_store_id,quantity,author_id,related_movement_id,idempotency_key) VALUES ('return',?,?,2,?,?,?) RETURNING id",[$this->article,$this->stores['A'],$this->users['admin'],$m['id'],'test-return-key']);$this->db->executeStatement('UPDATE stock_levels SET quantity = quantity + 2 WHERE article_id = ?',[$this->article]);$this->login('admin');$before=$this->totals();$this->correct($m['id'],['quantity'=>3,'reason'=>'Erreur']);self::assertResponseStatusCodeSame(409);$this->correct($m['id'],['reason'=>'Annuler'],cancel:true);self::assertResponseStatusCodeSame(409);$this->unchanged($before);
        $this->correct($return,['quantity'=>5,'reason'=>'Trop retourné']);self::assertResponseStatusCodeSame(409);$this->unchanged($before);$this->correct($return,['quantity'=>1,'reason'=>'Un seul retourné']);self::assertResponseStatusCodeSame(201);self::assertSame(7,$this->balance());$this->reconciled();
    }
    public function testConsumptionInactiveBeneficiaryArticleAndInventoryAdjustmentsAreProtected(): void
    {
        $this->entry();$m=$this->move('consumption',['article_id'=>$this->article,'store_id'=>$this->stores['A'],'consumer_id'=>$this->users['tech'],'quantity'=>4]);$this->login('admin');$before=$this->totals();
        $this->db->executeStatement('UPDATE users SET active = false WHERE id = ?',[$this->users['tech']]);$this->correct($m['id'],['quantity'=>3,'reason'=>'Erreur']);self::assertResponseStatusCodeSame(422);$this->unchanged($before);$this->db->executeStatement('UPDATE users SET active = true WHERE id = ?',[$this->users['tech']]);
        $this->db->executeStatement('UPDATE articles SET active = false WHERE id = ?',[$this->article]);$this->correct($m['id'],['quantity'=>3,'reason'=>'Erreur']);self::assertResponseStatusCodeSame(409);$this->unchanged($before);
        $this->correct($m['id'],['reason'=>'Annuler consommation inactive'],cancel:true);self::assertResponseStatusCodeSame(201);self::assertSame(10,$this->balance());$this->reconciled();
        $inventory=$this->db->fetchOne("INSERT INTO inventories(store_id,created_by,status,snapshot_at,validated_by,closed_at) VALUES (?,?,'closed',clock_timestamp(),?,clock_timestamp()) RETURNING id",[$this->stores['A'],$this->users['admin'],$this->users['admin']]);
        $adjustment=$this->db->fetchOne("INSERT INTO stock_movements(type,article_id,destination_store_id,quantity,author_id,inventory_id,reason,idempotency_key) VALUES ('adjustment',?,?,1,?,?,'Inventaire','inventory-locked') RETURNING id",[$this->article,$this->stores['A'],$this->users['admin'],$inventory]);$before=$this->totals();$this->correct($adjustment,['quantity'=>2,'reason'=>'Erreur inventaire'],key:'correct-inventory');self::assertResponseStatusCodeSame(409);$this->unchanged($before);
    }
    public function testAdjustmentValidationAndLimitFailuresNeverWrite(): void
    {
        $entry=$this->entry();$this->login('manager');$before=$this->totals();
        foreach([0,1.0,'-2',true,null,-9007199254740992] as $quantity) { $this->client->request('POST','/api/v1/movements/adjustments',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>'invalid-adjustment'],content:json_encode(['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>$quantity,'reason'=>'Écart'],JSON_PRESERVE_ZERO_FRACTION));self::assertResponseStatusCodeSame(422); }
        $this->client->request('POST','/api/v1/movements/adjustments',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>'insufficient-adjustment'],content:json_encode(['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>-11,'reason'=>'Écart']));self::assertResponseStatusCodeSame(409);$this->unchanged($before);
        $this->move('adjustment',['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>9007199254740981,'reason'=>'Borne maximale']);$before=$this->totals();$this->correct($entry['id'],['quantity'=>11,'reason'=>'Débordement']);self::assertResponseStatusCodeSame(409);$this->unchanged($before);$this->reconciled();
    }

    public function testNegativeAdjustmentFormRedirectsToItsSourceAndReplaysOnce(): void
    {
        $this->entry();$this->login('manager');$crawler=$this->client->request('GET','/stocks/ajustement');$form=$crawler->selectButton('Enregistrer l’ajustement')->form(['store_id'=>$this->stores['A'],'article_id'=>$this->article,'quantity'=>'-2','reason'=>'Écart négatif']);
        $this->client->submit($form);self::assertResponseStatusCodeSame(303);self::assertSame('/stocks/'.$this->stores['A'].'/'.$this->article,$this->client->getResponse()->headers->get('Location'));$this->client->followRedirect();self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','L’ajustement de −2 unités');self::assertSelectorTextContains('h2','Quantité actuelle : 8');
        $before=$this->totals();$this->client->submit($form);self::assertResponseStatusCodeSame(303);$this->unchanged($before);$this->reconciled();
        $this->login('user');$this->client->request('POST','/api/v1/movements/adjustments',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>'user-adjustment-forbidden'],content:json_encode(['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>1,'reason'=>'Interdit']));self::assertResponseStatusCodeSame(403);$this->unchanged($before);
    }

}
