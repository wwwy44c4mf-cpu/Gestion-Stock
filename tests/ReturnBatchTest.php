<?php

declare(strict_types=1);
namespace App\Tests;
use App\Security\StockUserProvider;
use App\Stock\{StockMovementEngine,StockCorrectionEngine};
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\{KernelBrowser,Test\WebTestCase};

final class ReturnBatchTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private array $users=[], $stores=[];
    private string $article,$csrf;
    protected function setUp(): void
    {
        $this->client=static::createClient();$this->client->disableReboot();$this->db=static::getContainer()->get(Connection::class);$this->db->beginTransaction();
        $schema='deposit_'.bin2hex(random_bytes(5));$this->db->executeStatement('CREATE SCHEMA '.$schema);$this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        foreach(glob(dirname(__DIR__).'/migrations/Version*.php') as $file) {
            require_once $file;$class='DoctrineMigrations\\'.basename($file,'.php');$migration=new $class($this->db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) { $this->db->executeStatement($sql->getStatement()); }
        }
        foreach(['admin'=>1,'manager'=>2,'user'=>3,'other'=>3] as $name=>$role) { $this->users[$name]=$this->db->fetchOne('INSERT INTO users(email,name,password_hash,role_id) VALUES(?,?,?,?) RETURNING id',[$name.'@example.test',$name,password_hash('Deposit-fixture-2026!',PASSWORD_ARGON2ID),$role]); }
        foreach(['A','B','C','D'] as $code) { $this->stores[$code]=$this->db->fetchOne('INSERT INTO stores(code,name) VALUES(?,?) RETURNING id',[$code,'Magasin '.$code]); }
        $this->db->executeStatement('UPDATE stores SET active=false WHERE id=?',[$this->stores['D']]);
        $this->db->insert('user_stores',['user_id'=>$this->users['manager'],'store_id'=>$this->stores['C']]);
        $this->article=$this->db->fetchOne("INSERT INTO articles(sku,name) VALUES('DEPOT','Matériel') RETURNING id");
        foreach(['A','B'] as $store) { $this->move('admin','entry',$store,20); }
        $this->login('user');
    }
    protected function tearDown(): void { if($this->db->isTransactionActive()) { $this->db->rollBack(); }parent::tearDown(); }
    private function actor(string $name): \App\Security\StockUser { return static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier($name.'@example.test'); }
    private function login(string $name): void { $this->client->getCookieJar()->clear();$this->client->loginUser($this->actor($name));$this->client->request('GET','/api/v1/auth/csrf');$this->csrf=json_decode($this->client->getResponse()->getContent(),true)['token']; }
    private function move(string $actor,string $type,string $store,int $quantity): array
    {
        $body=(object)['article_id'=>$this->article,'store_id'=>$this->stores[$store],'quantity'=>$quantity];if($type==='entry') {$body->reason='Fixture';}else{$body->consumer_id=$this->users[$actor];}
        return static::getContainer()->get(StockMovementEngine::class)->record($this->actor($actor),$type,$body,bin2hex(random_bytes(16)))['data'];
    }
    private function api(array $extra=[],?string $key=null): array
    {
        $this->client->request('POST','/api/v1/movements/return-batches',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>$key??bin2hex(random_bytes(16))],content:json_encode(array_replace(['article_id'=>$this->article,'store_id'=>$this->stores['C'],'quantity'=>5],$extra)));
        return json_decode($this->client->getResponse()->getContent(),true)??[];
    }
    public function testFavoriteIsGlobalAndManualContextOverridesOperationDefaults(): void
    {
        $this->db->executeStatement('UPDATE users SET favorite_store_id=? WHERE id=?',[$this->stores['A'],$this->users['admin']]);
        $this->login('admin');
        foreach(['/stocks','/articles','/profil'] as $url) {
            $this->client->request('GET',$url);self::assertResponseIsSuccessful();
            self::assertSelectorExists('#magasin option[value="'.$this->stores['A'].'"][selected]');
        }
        $context=static::getContainer()->get(\App\Ui\StoreContext::class);
        $request=$this->client->getRequest();
        $context->select($request,$this->actor('admin'),$this->stores['B']);$request->getSession()->save();
        foreach(['/stocks/entree','/consommations/nouvelle','/consommations/retours/nouveau'] as $url) {
            $this->client->request('GET',$url);self::assertResponseIsSuccessful();
            self::assertSelectorExists('#magasin option[value="'.$this->stores['B'].'"][selected]');
            self::assertSelectorExists('main select[name="store_id"] option[value="'.$this->stores['B'].'"][selected]');
        }
        self::assertSame($this->stores['A'],$this->actor('admin')->favoriteStoreId);
        $request=$this->client->getRequest();$context->select($request,$this->actor('admin'),'');$request->getSession()->save();
        $this->client->request('GET','/articles');self::assertSelectorNotExists('#magasin option[selected]');
        $this->db->executeStatement('UPDATE users SET favorite_store_id=? WHERE id=?',[$this->stores['C'],$this->users['admin']]);
        $this->client->request('GET','/profil');self::assertSelectorExists('#magasin option[value="'.$this->stores['C'].'"][selected]');
        $this->login('admin');$this->client->request('GET','/stocks');self::assertSelectorExists('#magasin option[value="'.$this->stores['C'].'"][selected]');
    }
    private function balances(): array { return $this->db->fetchAllAssociative('SELECT store_id,quantity,version FROM stock_levels ORDER BY store_id'); }
    private function seedConsumptions(): array { return [$this->move('user','consumption','A',3),$this->move('user','consumption','B',4)]; }
    public function testCrossStoreFifoDepositAndReplayAreAtomic(): void
    {
        [$a,$b]=$this->seedConsumptions();$key='one-confirmed-deposit';$result=$this->api(key:$key)['data'];self::assertResponseStatusCodeSame(201);self::assertSame(5,$result['quantity']);self::assertTrue(static::getContainer()->get(\App\Api\OpenApiContract::class)->valid((object)['data'=>(object)$result],['$ref'=>'#/components/schemas/ReturnBatchResource']));
        self::assertSame([[$a['id'],3],[$b['id'],2]],array_map(fn($r)=>[$r['related_movement_id'],(int)$r['quantity']],$this->db->fetchAllAssociative('SELECT related_movement_id,quantity FROM stock_movements WHERE return_group_id=? ORDER BY created_at,id',[$result['id']])));
        self::assertSame(5,(int)$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE store_id=?',[$this->stores['C']]));
        self::assertSame(17,(int)$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE store_id=?',[$this->stores['A']]));
        self::assertSame(16,(int)$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE store_id=?',[$this->stores['B']]));
        $before=$this->balances();self::assertSame($result,$this->api(key:$key)['data']);self::assertResponseStatusCodeSame(201);self::assertSame($before,$this->balances());
        $this->api(['quantity'=>1],$key);self::assertResponseStatusCodeSame(409);self::assertSame($before,$this->balances());
        $contexts=$this->db->fetchFirstColumn("SELECT context FROM audit_logs WHERE action='stock.return'");foreach($contexts as $context) { self::assertSame(['return_group_id'=>$result['id']],json_decode($context,true)); }
        $this->db->executeStatement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->client->request('GET','/consommations/retours/depot/'.$result['id']);self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Magasin de dépôt : C');
    }
    public function testForbiddenBeneficiaryExcessAndInactiveDepositLeaveNoPartialWrite(): void
    {
        $this->seedConsumptions();$before=$this->balances();
        foreach([['consumer_id'=>$this->users['other']],['quantity'=>8],['store_id'=>$this->stores['D']]] as $case) { $this->api($case);self::assertContains($this->client->getResponse()->getStatusCode(),[403,404,409]);self::assertSame($before,$this->balances());self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_return_groups')); }
    }
    public function testManagerCanRecordDepositAtHisStoreFromOtherOriginsWithoutReadingTheirStocks(): void
    {
        $this->seedConsumptions();$this->login('manager');$result=$this->api(['consumer_id'=>$this->users['user']])['data'];self::assertResponseStatusCodeSame(201);
        $this->client->request('GET','/consommations/retours/depot/'.$result['id']);self::assertResponseIsSuccessful();self::assertSelectorNotExists('a[href^="/consommations/retours/"]:not([href="/consommations/retours/nouveau"])');
        $this->client->request('GET','/api/v1/stores/'.$this->stores['A'].'/stocks');self::assertResponseStatusCodeSame(404);
        $this->api(['consumer_id'=>$this->users['user'],'store_id'=>$this->stores['A'],'quantity'=>1]);self::assertResponseStatusCodeSame(404);
    }
    public function testOldSourceAndArchivedArticleDoNotBlockDepositInAnActiveStore(): void
    {
        $this->seedConsumptions();$this->db->executeStatement('UPDATE stores SET active=false WHERE id=?',[$this->stores['A']]);$this->db->executeStatement('UPDATE articles SET active=false WHERE id=?',[$this->article]);
        $this->api();self::assertResponseStatusCodeSame(201);
        $this->client->request('GET','/api/v1/stores/'.$this->stores['C'].'/stocks');self::assertResponseStatusCodeSame(404);
    }
    public function testInventoryAndStockCapacityCannotPartiallyCreditTheDeposit(): void
    {
        $this->seedConsumptions();$before=$this->balances();
        $inventory=$this->db->fetchOne("INSERT INTO inventories(store_id,created_by,status,snapshot_at) VALUES(?,?,'in_progress',clock_timestamp()) RETURNING id",[$this->stores['C'],$this->users['admin']]);
        $this->api();self::assertResponseStatusCodeSame(409);self::assertSame($before,$this->balances());self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_return_groups'));
        $this->db->executeStatement("UPDATE inventories SET status='cancelled',cancelled_at=clock_timestamp() WHERE id=?",[$inventory]);
        $this->db->executeStatement('INSERT INTO stock_levels(article_id,store_id,quantity) VALUES(?,?,9007199254740991)',[$this->article,$this->stores['C']]);
        $before=$this->balances();$this->api();self::assertResponseStatusCodeSame(409);self::assertSame($before,$this->balances());self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_return_groups'));
    }
    public function testFavoriteIsPersonalPrefillsFormsAndDoesNotChangeWithOneOperation(): void
    {
        $this->client->request('PATCH','/api/v1/auth/me',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf],content:json_encode(['timezone'=>'Europe/Paris','favorite_store_id'=>$this->stores['C']]));self::assertResponseIsSuccessful();
        foreach(['/consommations/nouvelle'=>'consumption-store','/consommations/retours/nouveau'=>'deposit-store'] as $url=>$id) { $this->client->request('GET',$url);self::assertResponseIsSuccessful();self::assertSelectorExists('#'.$id.' option[selected][value="'.$this->stores['C'].'"]'); }
        $this->move('user','consumption','A',1);self::assertSame($this->stores['C'],$this->actor('user')->favoriteStoreId);self::assertNull($this->actor('other')->favoriteStoreId);
        $this->db->executeStatement('UPDATE stores SET active=false WHERE id=?',[$this->stores['C']]);$this->client->request('GET','/consommations/retours/nouveau');self::assertSelectorNotExists('#deposit-store option[selected]');
    }
    public function testInvalidFavoriteCannotChangeTimezoneAndNullClearsOnlyOwnPreference(): void
    {
        $this->db->executeStatement('UPDATE users SET favorite_store_id=? WHERE id=?',[$this->stores['C'],$this->users['user']]);
        $this->client->request('PATCH','/api/v1/auth/me',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf],content:json_encode(['timezone'=>'UTC','favorite_store_id'=>$this->stores['D']]));self::assertResponseStatusCodeSame(422);self::assertSame('Europe/Paris',$this->actor('user')->timezone);self::assertSame($this->stores['C'],$this->actor('user')->favoriteStoreId);
        $this->client->request('PATCH','/api/v1/auth/me',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf],content:json_encode(['timezone'=>'Europe/Paris','favorite_store_id'=>null]));self::assertResponseIsSuccessful();self::assertNull($this->actor('user')->favoriteStoreId);self::assertNull($this->actor('other')->favoriteStoreId);
    }
    public function testExistingManagerReplaySurvivesBeneficiaryDeactivation(): void
    {
        $this->seedConsumptions();$this->login('manager');$key='deposit-before-deactivation';$body=['consumer_id'=>$this->users['user']];$result=$this->api($body,$key);self::assertResponseStatusCodeSame(201);
        $before=$this->balances();$this->db->executeStatement('UPDATE users SET active=false WHERE id=?',[$this->users['user']]);self::assertSame($result,$this->api($body,$key));self::assertResponseStatusCodeSame(201);self::assertSame($before,$this->balances());$this->api($body);self::assertResponseStatusCodeSame(422);
    }
    public function testFormKeepsScannedArticleQuantityAndStoreOnFailure(): void
    {
        $this->seedConsumptions();$crawler=$this->client->request('GET','/consommations/retours/nouveau?article_id='.$this->article);self::assertResponseIsSuccessful();self::assertSelectorExists('[data-scanner-mode-value="deposit"]');
        $form=$crawler->selectButton('Enregistrer le retour')->form(['store_id'=>$this->stores['C'],'quantity'=>'8']);$this->client->submit($form);self::assertResponseStatusCodeSame(409);self::assertInputValueSame('quantity','8');self::assertSelectorExists('#deposit-store option[selected][value="'.$this->stores['C'].'"]');self::assertSelectorExists('#deposit-article option[selected][value="'.$this->article.'"]');
        $this->client->request('POST','/consommations/retours/nouveau',array_replace($form->getPhpValues(),['_csrf_token'=>'bad','quantity'=>'1']));self::assertResponseStatusCodeSame(403);
    }
    public function testReturnCorrectionChangesTheDepositAndReopensOriginalRemainder(): void
    {
        [$a]=$this->seedConsumptions();$result=$this->api()['data'];self::assertResponseStatusCodeSame(201);
        $return=$this->db->fetchOne('SELECT id FROM stock_movements WHERE return_group_id=? AND related_movement_id=?',[$result['id'],$a['id']]);
        static::getContainer()->get(StockCorrectionEngine::class)->correct($this->actor('manager'),$return,(object)['quantity'=>1,'reason'=>'Deux articles non déposés'],bin2hex(random_bytes(16)),false);
        self::assertSame(3,(int)$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE store_id=?',[$this->stores['C']]));
        $this->client->request('GET','/consommations/retours/depot/'.$result['id']);self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Quantité actuelle après corrections : 3');
        $this->api(['quantity'=>2]);self::assertResponseStatusCodeSame(201);
    }
    public function testStatisticsUseTheDepositStoreInsteadOfTheOrigin(): void
    {
        $this->seedConsumptions();$this->api();self::assertResponseStatusCodeSame(201);
        $stats=static::getContainer()->get(\App\Statistics\ConsumptionStatistics::class)->read($this->actor('user'),['store_id'=>$this->stores['C']]);self::assertSame('0',$stats['data']['totals']['consumed_quantity']);self::assertSame('5',$stats['data']['totals']['returned_quantity']);
        $stats=static::getContainer()->get(\App\Statistics\ConsumptionStatistics::class)->read($this->actor('manager'),['store_id'=>$this->stores['C']]);self::assertSame('5',$stats['data']['totals']['returned_quantity']);
    }
}
