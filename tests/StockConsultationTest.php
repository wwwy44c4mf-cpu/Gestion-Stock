<?php

declare(strict_types=1);

namespace App\Tests;

use App\Api\OpenApiContract;
use App\Security\StockUserProvider;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StockConsultationTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private array $users = [], $stores = [], $articles = [], $categories = [];
    protected function setUp(): void
    {
        $this->client = static::createClient(); $this->client->disableReboot();
        $this->db = static::getContainer()->get(Connection::class); $this->db->beginTransaction();
        $schema = 'stk01_'.bin2hex(random_bytes(6)); $this->db->executeStatement('CREATE SCHEMA '.$schema); $this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        foreach (explode('-- migrate:statement', file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) { if (trim($sql) !== '') { $this->db->executeStatement($sql); } }
        $this->db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
        require_once dirname(__DIR__).'/migrations/Version20261018140000.php';
        $migration = new \DoctrineMigrations\Version20261018140000($this->db, new \Psr\Log\NullLogger()); $migration->up(new \Doctrine\DBAL\Schema\Schema()); foreach ($migration->getSql() as $sql) { $this->db->executeStatement($sql->getStatement()); }
        $hash = password_hash('Stock-test-2026!', PASSWORD_ARGON2ID);
        foreach (['admin'=>1,'manager'=>2,'user'=>3,'outsider'=>3] as $name=>$role) { $this->users[$name] = $this->db->fetchOne('INSERT INTO users (email,name,password_hash,role_id) VALUES (?,?,?,?) RETURNING id', [$name.'@example.test', $name, $hash, $role]); }
        foreach (['A','B','C','D'] as $code) { $this->stores[$code] = $this->db->fetchOne('INSERT INTO stores (code,name) VALUES (?,?) RETURNING id', [$code, 'Magasin '.$code]); }
        $this->db->executeStatement('UPDATE stores SET active = false WHERE id = ?', [$this->stores['D']]);
        foreach (['manager'=>['A','B','D'],'user'=>['A','D']] as $name=>$codes) { foreach ($codes as $code) { $this->db->insert('user_stores',['user_id'=>$this->users[$name],'store_id'=>$this->stores[$code]]); } }
        foreach (['Alpha','Beta'] as $name) { $this->categories[$name] = $this->db->fetchOne('INSERT INTO categories (name) VALUES (?) RETURNING id', [$name]); }
        foreach (['REF-1'=>['Vis Alpha', 'Alpha'], 'REF-2'=>['Vis Beta','Beta'], 'ZERO'=>['Sans stock','Alpha'], '100%'=>['Littéral <script>','Alpha']] as $sku=>[$name,$category]) {
            $this->articles[$sku] = $this->db->fetchOne('INSERT INTO articles (sku,name,category_id) VALUES (?,?,?) RETURNING id',[$sku,$name,$this->categories[$category]]);
        }
        foreach (['A'=>5,'B'=>7,'C'=>100,'D'=>1000] as $store=>$quantity) { $this->db->insert('stock_levels',['article_id'=>$this->articles['REF-1'],'store_id'=>$this->stores[$store],'quantity'=>$quantity,'low_threshold'=>5,'critical_threshold'=>0]); }
        $this->db->insert('stock_levels',['article_id'=>$this->articles['REF-2'],'store_id'=>$this->stores['A'],'quantity'=>0,'low_threshold'=>0,'critical_threshold'=>0]);
    }
    protected function tearDown(): void { if ($this->db->isTransactionActive()) { $this->db->rollBack(); } parent::tearDown(); }
    private function login(string $name): void { $this->client->getCookieJar()->clear(); $this->client->loginUser(static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier($name.'@example.test')); }
    private function get(string $path, int $status = 200): array { $this->client->request('GET', $path); self::assertResponseStatusCodeSame($status); return json_decode($this->client->getResponse()->getContent(), true) ?? []; }
    private function valid(array $value, string $schema): void { self::assertTrue(static::getContainer()->get(OpenApiContract::class)->valid(json_decode(json_encode($value)), ['$ref'=>'#/components/schemas/'.$schema])); }
    public function testAuthorizedTotalsAreCalculatedBeforePaginationAndUseExactStrings(): void
    {
        foreach (['admin'=>['1112',4],'manager'=>['12',2],'user'=>['5',1],'outsider'=>['0',0]] as $name=>[$sum,$count]) {
            $this->login($name); $result = $this->get('/api/v1/articles/'.$this->articles['REF-1'].'/stocks?limit=1&page=1');
            self::assertSame($sum, $result['data']['total_quantity']); self::assertSame($count,$result['data']['stores']['meta']['total']); self::assertLessThanOrEqual(1,count($result['data']['stores']['data'])); $this->valid($result,'ArticleStocksResource');
            $next = $this->get('/api/v1/articles/'.$this->articles['REF-1'].'/stocks?limit=1&page=100'); self::assertSame($sum,$next['data']['total_quantity']); self::assertSame([],$next['data']['stores']['data']);
        }
        $this->login('admin'); $this->db->executeStatement('UPDATE stock_levels SET quantity = 9007199254740991 WHERE article_id = ?',[$this->articles['REF-1']]);
        $large = $this->get('/api/v1/articles/'.$this->articles['REF-1'].'/stocks'); self::assertSame('36028797018963964',$large['data']['total_quantity']); $this->valid($large,'ArticleStocksResource');
    }
    public function testFiltersNeverBroadenScopeAndNoStockRowsAreWrittenByReads(): void
    {
        $this->login('manager'); $before = $this->db->fetchAllAssociative('SELECT * FROM stock_levels ORDER BY article_id,store_id');
        $result = $this->get('/api/v1/stocks'); self::assertSame(8,$result['meta']['total']); $this->valid($result,'StockLevelPage');
        $filtered = $this->get('/api/v1/stocks?q=vis&category_id='.$this->categories['Alpha'].'&store_id='.$this->stores['A'].'&status=below_threshold&sort=quantity&order=desc'); self::assertCount(1,$filtered['data']);self::assertSame(5,$filtered['data'][0]['quantity']);
        $zero = $this->get('/api/v1/stores/'.$this->stores['A'].'/stocks?status=out_of_stock'); self::assertSame(3,$zero['meta']['total']);
        $threshold = $this->get('/api/v1/stores/'.$this->stores['A'].'/stocks?status=below_threshold'); self::assertSame(2,$threshold['meta']['total']);
        $literal = $this->get('/api/v1/stocks?q=%25'); self::assertSame(2,$literal['meta']['total']);
        self::assertSame($before,$this->db->fetchAllAssociative('SELECT * FROM stock_levels ORDER BY article_id,store_id'));
        foreach (['C','D'] as $code) { $this->get('/api/v1/stocks?store_id='.$this->stores[$code],404); $this->get('/api/v1/stores/'.$this->stores[$code].'/stocks',404); $this->get('/api/v1/articles/'.$this->articles['REF-1'].'/stocks?store_id='.$this->stores[$code],404); }
    }
    public function testArticleTotalsHonorStoreFiltersAndRightsChangesImmediately(): void
    {
        $this->login('manager'); $path = '/api/v1/articles/'.$this->articles['REF-1'].'/stocks';
        self::assertSame('7',$this->get($path.'?store_id='.$this->stores['B'])['data']['total_quantity']);
        $this->db->executeStatement('DELETE FROM user_stores WHERE user_id = ? AND store_id = ?',[$this->users['manager'],$this->stores['B']]);
        self::assertSame('5',$this->get($path)['data']['total_quantity']); $this->get($path.'?store_id='.$this->stores['B'],404);
        $this->db->executeStatement('UPDATE stores SET active = false WHERE id = ?',[$this->stores['A']]); self::assertSame('0',$this->get($path)['data']['total_quantity']);
        $this->login('admin'); self::assertSame('1112',$this->get($path)['data']['total_quantity']);
    }
    public function testInvalidQueriesAndUnknownIdentifiersHaveExplicitErrors(): void
    {
        $this->login('user');
        foreach (['q=%00','q=%20','sort=name','order=DROP','page=0','limit=101','store_id=invalid','article_id=invalid','category_id=invalid','status=active','q[]=a','unknown=1'] as $query) { $this->get('/api/v1/stocks?'.$query,422); }
        $this->get('/api/v1/stores/invalid/stocks',404); $this->get('/api/v1/articles/invalid/stocks',404); $this->get('/api/v1/articles/11111111-1111-4111-8111-111111111111/stocks',404);
        $this->login('outsider'); self::assertSame(0,$this->get('/api/v1/stocks')['meta']['total']);
    }
    public function testStockDetailAndLocalHistoryRespectPermissionsAndHideRemoteIdentities(): void
    {
        $a = $this->articles['REF-1']; $store = $this->stores['A'];
        $this->db->executeStatement("INSERT INTO stock_movements (type,article_id,destination_store_id,quantity,author_id,reason,idempotency_key) VALUES ('entry',?,?,8,?,'Réapprovisionnement fictif','entry-history')",[$a,$store,$this->users['admin']]);
        $this->db->executeStatement("INSERT INTO stock_movements (type,article_id,source_store_id,destination_store_id,quantity,author_id,idempotency_key) VALUES ('transfer',?,?,?,3,?,'transfer-history')",[$a,$store,$this->stores['C'],$this->users['admin']]);
        $this->login('manager'); $path='/api/v1/stores/'.$store.'/stocks/'.$a.'/movements'; $result=$this->get($path.'?limit=1'); self::assertSame(2,$result['meta']['total']); self::assertCount(1,$result['data']); $this->valid($result,'StockMovementPage');
        $all=$this->get($path);self::assertEqualsCanonicalizing([8,-3],array_column($all['data'],'quantity'));$text=json_encode($all);self::assertStringNotContainsString($this->stores['C'],$text);self::assertStringNotContainsString($this->users['admin'],$text);
        $this->client->request('GET','/stocks/'.$store.'/'.$a);self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Historique des variations');self::assertSelectorTextContains('main','-3');
        $this->login('user');$this->get($path,403);$this->client->request('GET','/stocks/'.$store.'/'.$a);self::assertResponseIsSuccessful();self::assertSelectorTextNotContains('main','Historique des variations');
        $this->client->request('GET','/stocks/'.$this->stores['C'].'/'.$a);self::assertResponseStatusCodeSame(404);
        $this->client->request('GET','/stocks/'.$store.'/'.$this->articles['ZERO']);self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Quantité actuelle : 0');
    }
    public function testCatalogueCombinedSearchSortAndPaginationRemainGlobal(): void
    {
        $this->login('outsider');$this->db->executeStatement('UPDATE articles SET active = false WHERE sku = ?', ['REF-2']);
        $path='/api/v1/articles?q=vis&category_id='.$this->categories['Beta'].'&status=inactive'; $result=$this->get($path);self::assertSame([$this->articles['REF-2']],array_column($result['data'],'id'));
        $this->get('/api/v1/articles?q=vis&category_id='.$this->categories['Alpha'].'&status=inactive');self::assertSame(0,json_decode($this->client->getResponse()->getContent(),true)['meta']['total']);
        foreach (['sku','name','category','status','created_at'] as $sort) { $all=$this->get('/api/v1/articles?sort='.$sort.'&order=desc');$ids=[];for($page=1;$page<=4;$page++){ $slice=$this->get('/api/v1/articles?sort='.$sort.'&order=desc&limit=1&page='.$page);$ids[]=$slice['data'][0]['id']; }self::assertSame(array_column($all['data'],'id'),$ids); }
        $this->client->request('GET','/articles?q=vis&status=inactive');self::assertResponseIsSuccessful();self::assertSelectorTextContains('tbody','Vis Beta');self::assertSelectorTextNotContains('tbody','Vis Alpha');
        $this->client->request('GET','/articles/'.$this->articles['REF-1']);self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Stock total autorisé : 0');self::assertSelectorTextNotContains('main','Magasin A');
    }
    public function testHtmlFiltersLinksEscapingAndUnavailableStoreViews(): void
    {
        $this->login('manager');$this->client->request('GET','/stocks?store_id='.$this->stores['A'].'&q=%25');self::assertResponseIsSuccessful();self::assertSelectorTextContains('tbody','Littéral <script>');self::assertSelectorNotExists('main script');self::assertSelectorExists('nav a[aria-current="page"][href="/stocks"]');self::assertSelectorNotExists('option[value="'.$this->stores['C'].'"]');self::assertSelectorNotExists('option[value="'.$this->stores['D'].'"]');
        $this->client->request('GET','/articles/'.$this->articles['REF-1'].'?limit=1');self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Stock total autorisé : 12');self::assertSelectorTextNotContains('main','Magasin C');
        $this->client->request('GET','/stocks?store_id='.$this->stores['D']);self::assertResponseStatusCodeSame(404);self::assertSelectorExists('a[href="/stocks"]');
        $this->login('admin');$this->client->request('GET','/stocks/'.$this->stores['D'].'/'.$this->articles['REF-1']);self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Ce magasin est désactivé');
    }
}
