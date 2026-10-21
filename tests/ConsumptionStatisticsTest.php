<?php

declare(strict_types=1);
namespace App\Tests;

use App\Api\OpenApiContract;
use App\Statistics\ConsumptionStatistics;
use App\Security\StockUserProvider;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ConsumptionStatisticsTest extends WebTestCase
{
    private $client;
    private Connection $db;
    private array $users=[], $stores=[];
    private string $article,$otherArticle,$consumption,$personalB,$return,$child,$category,$childCategory;
    protected function setUp(): void
    {
        $this->client=static::createClient();$this->client->disableReboot();$this->db=static::getContainer()->get(Connection::class);$this->db->beginTransaction();
        $schema='stk08_'.bin2hex(random_bytes(6));$this->db->executeStatement('CREATE SCHEMA '.$schema);$this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        foreach(explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) { if(trim($sql)!=='') { $this->db->executeStatement($sql); } }
        $this->db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
        foreach(['20261018140000','20261018200000','20261018223000'] as $version) { require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';$class='DoctrineMigrations\\Version'.$version;$migration=new $class($this->db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) { $this->db->executeStatement($sql->getStatement()); } }
        $hash=password_hash('History-test-2026!',PASSWORD_ARGON2ID);
        foreach(['admin'=>1,'manager'=>2,'user'=>3,'outside'=>2,'unassigned'=>2,'tech'=>3] as $name=>$role) { $this->users[$name]=$this->db->fetchOne('INSERT INTO users (email,name,password_hash,role_id) VALUES (?,?,?,?) RETURNING id',[$name.'@example.test',$name,$hash,$role]); }
        foreach(['A','SECRET','D'] as $code) { $this->stores[$code]=$this->db->fetchOne('INSERT INTO stores (code,name) VALUES (?,?) RETURNING id',[$code,'Magasin '.$code]); }
        $this->db->executeStatement('UPDATE stores SET active=false WHERE id=?',[$this->stores['D']]);
        $this->db->insert('user_stores',['user_id'=>$this->users['manager'],'store_id'=>$this->stores['A']]);
        $this->category=$this->db->fetchOne("INSERT INTO categories(name) VALUES ('Catégorie racine') RETURNING id");$this->childCategory=$this->db->fetchOne("INSERT INTO categories(name,parent_id) VALUES ('Sous-catégorie',?) RETURNING id",[$this->category]);
        $this->article=$this->db->fetchOne("INSERT INTO articles(sku,name,category_id) VALUES ('HISTORY','Article <script>test</script>',?) RETURNING id",[$this->category]);
        $this->otherArticle=$this->db->fetchOne("INSERT INTO articles(sku,name,category_id) VALUES ('CHILD','Article enfant',?) RETURNING id",[$this->childCategory]);
        $this->stores['B']=$this->db->fetchOne("INSERT INTO stores(code,name) VALUES ('B','Magasin personnel B') RETURNING id");
        $this->db->insert('user_stores',['user_id'=>$this->users['user'],'store_id'=>$this->stores['A']]);
        $this->consumption=$this->movement('consumption','A',null,5,'2026-03-28 23:00:00+00','manager',$this->users['user']);
        $this->personalB=$this->movement('consumption','B',null,4,'2026-03-29 21:59:59+00','outside',$this->users['user']);
        $this->return=$this->movement('return',null,'A',2,'2026-04-01 08:00:00+00','manager',null,$this->consumption);
        $this->movement('consumption','A',null,7,'2026-03-29 12:00:00+00','user',$this->users['tech']);
        $this->movement('consumption','D',null,8,'2026-03-29 12:00:00+00','admin',$this->users['user']);
        $this->movement('consumption','SECRET',null,9,'2026-03-29 12:00:00+00','outside',$this->users['tech']);
        $original=$this->article;$this->article=$this->otherArticle;$this->child=$this->movement('consumption','A',null,3,'2026-03-30 12:00:00+00','manager',$this->users['user']);$this->article=$original;
        $this->db->executeStatement('UPDATE articles SET active=false WHERE id=?',[$this->article]);
    }
    private function movement(string $type,?string $source,?string $destination,int $quantity,string $date,string $author,?string $consumer=null,?string $related=null): string
    {
        return $this->db->fetchOne('INSERT INTO stock_movements (type,article_id,source_store_id,destination_store_id,quantity,author_id,consumer_id,related_movement_id,reason,reference,comment,created_at,idempotency_key) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id',[$type,$this->article,$source===null?null:$this->stores[$source],$destination===null?null:$this->stores[$destination],$quantity,$this->users[$author],$consumer,$related,'Magasin SECRET','Secret reference','Secret comment',$date,bin2hex(random_bytes(12))]);
    }
    protected function tearDown(): void { if($this->db->isTransactionActive()) {$this->db->rollBack();}parent::tearDown(); }
    private function login(string $name='user'): void { $this->client->getCookieJar()->clear();$this->client->loginUser(static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier($name.'@example.test')); }
    private function api(string $query=''): array { $this->client->request('GET','/api/v1/statistics/consumptions'.$query);return json_decode($this->client->getResponse()->getContent(),true)??[]; }
    private function data(string $query=''): array {return $this->api($query)['data']??[];}
    private function addChild(int $quantity,string $date): string {$original=$this->article;$this->article=$this->otherArticle;try {return $this->movement('consumption','A',null,$quantity,$date,'admin',$this->users['user']);}finally {$this->article=$original;}}
    public function testKnownDatasetTotalsScopesAndSeparateReturnDates(): void {
        $this->login();$d=$this->data();self::assertResponseIsSuccessful();self::assertEquals(['consumed_quantity'=>'12','returned_quantity'=>'2','unit_code'=>'unit','movement_count'=>3,'return_count'=>1],$d['totals']);self::assertCount(1,$d['by_user']);self::assertSame($this->users['user'],$d['by_user'][0]['id']);self::assertCount(2,$d['by_store']);self::assertStringNotContainsString('SECRET',json_encode($d));
        $march=$this->data('?date_from=2026-03-29&date_to=2026-03-29');self::assertSame('9',$march['totals']['consumed_quantity']);self::assertSame('0',$march['totals']['returned_quantity']);
        $april=$this->data('?date_from=2026-04-01&date_to=2026-04-01');self::assertSame('0',$april['totals']['consumed_quantity']);self::assertSame('2',$april['totals']['returned_quantity']);self::assertSame(0,$april['totals']['movement_count']);self::assertSame(1,$april['totals']['return_count']);self::assertCount(0,$april['top_articles']);self::assertSame('2',$april['by_user'][0]['returned_quantity']);self::assertSame('2',$april['buckets'][0]['returned_quantity']);
        $this->login('manager');$d=$this->data();self::assertSame('15',$d['totals']['consumed_quantity']);self::assertSame(3,$d['totals']['movement_count']);self::assertSame([$this->stores['A']],array_column($d['by_store'],'id'));
        $this->login('admin');self::assertSame('36',$this->data()['totals']['consumed_quantity']);self::assertSame('8',$this->data('?store_id='.$this->stores['D'])['totals']['consumed_quantity']);
    }
    public function testBothRankingsAreIndependentLimitedAndDeterministic(): void {
        $this->addChild(1,'2026-03-30 12:00:00+00');$this->addChild(1,'2026-03-30 12:00:00+00');$this->login();$d=$this->data('?top_limit=1');self::assertCount(1,$d['top_articles']);self::assertCount(1,$d['top_articles_by_count']);self::assertSame($this->article,$d['top_articles'][0]['article_id']);self::assertSame('9',$d['top_articles'][0]['quantity']);self::assertSame($this->otherArticle,$d['top_articles_by_count'][0]['article_id']);self::assertSame(3,$d['top_articles_by_count'][0]['movement_count']);self::assertSame('14',$d['totals']['consumed_quantity']);
        $same=$this->data('?top_limit=1');self::assertSame($d,$same);
    }
    public function testDailyWeeklyMonthlyCalendarAndZeroGaps(): void {
        $this->login();$d=$this->data('?date_from=2026-03-28&date_to=2026-04-02');self::assertCount(6,$d['buckets']);self::assertSame(['2026-03-28','2026-03-29','2026-03-30','2026-03-31','2026-04-01','2026-04-02'],array_column($d['buckets'],'start_date'));self::assertSame(['0','9','3','0','0','0'],array_column($d['buckets'],'consumed_quantity'));self::assertSame(['0','0','0','0','2','0'],array_column($d['buckets'],'returned_quantity'));
        $w=$this->data('?date_from=2026-03-28&date_to=2026-04-02&granularity=week');self::assertSame(['2026-03-23','2026-03-30'],array_column($w['buckets'],'start_date'));self::assertSame(['9','3'],array_column($w['buckets'],'consumed_quantity'));self::assertSame(['0','2'],array_column($w['buckets'],'returned_quantity'));
        $m=$this->data('?date_from=2026-03-28&date_to=2026-04-02&granularity=month');self::assertSame(['2026-03-01','2026-04-01'],array_column($m['buckets'],'start_date'));self::assertSame(['12','0'],array_column($m['buckets'],'consumed_quantity'));
        $this->addChild(1,'2015-12-31 12:00:00+00');$this->addChild(1,'2016-01-01 12:00:00+00');self::assertSame(['2015-12-28'],array_column($this->data('?date_from=2016-01-01&date_to=2016-01-03&granularity=week')['buckets'],'start_date'));self::assertSame('1',$this->data('?date_from=2016-01-01&date_to=2016-01-03&granularity=week')['totals']['consumed_quantity']);
    }
    public function testStoreCategoryAndDateFiltersApplyToEveryAggregate(): void {
        $this->login();$d=$this->data('?store_id='.$this->stores['A'].'&category_id='.$this->category.'&date_from=2026-03-29&date_to=2026-03-30');self::assertSame('5',$d['totals']['consumed_quantity']);self::assertCount(1,$d['by_store']);self::assertCount(1,$d['by_category']);self::assertSame($this->category,$d['by_category'][0]['id']);self::assertSame($this->article,$d['top_articles'][0]['article_id']);self::assertSame(['5','0'],array_column($d['buckets'],'consumed_quantity'));
        self::assertSame('3',$this->data('?category_id='.$this->childCategory)['totals']['consumed_quantity']);self::assertSame('9',$this->data('?category_id='.$this->category)['totals']['consumed_quantity']);
        $this->api('?store_id='.$this->stores['SECRET']);self::assertResponseStatusCodeSame(404);$this->api('?store_id='.$this->stores['D']);self::assertResponseStatusCodeSame(404);
        self::assertSame('4',$this->data('?store_id='.$this->stores['B'])['totals']['consumed_quantity']);$this->client->request('GET','/stocks/'.$this->stores['B'].'/'.$this->article);self::assertResponseStatusCodeSame(404);
    }
    public function testReversalsCorrectionsAndOtherMovementTypesNeverInflateTotals(): void {
        $this->db->executeStatement('UPDATE articles SET active=true WHERE id=?',[$this->article]);$this->movement('entry',null,'A',99,'2026-03-29 12:00:00+00','admin');$this->movement('transfer','A','SECRET',40,'2026-03-29 12:00:00+00','admin');$this->movement('adjustment',null,'A',30,'2026-03-29 12:00:00+00','admin');
        $this->movement('reversal','A',null,2,'2026-04-02 08:00:00+00','admin',null,$this->return);$undo=$this->movement('reversal',null,'A',5,'2026-04-02 09:00:00+00','admin',null,$this->consumption);$replacement=$this->movement('consumption','A',null,3,'2026-04-02 09:00:00+00','admin',$this->users['user']);$this->db->insert('movement_corrections',['original_id'=>$this->consumption,'reversal_id'=>$undo,'replacement_id'=>$replacement]);
        $this->login();$d=$this->data();self::assertSame('10',$d['totals']['consumed_quantity']);self::assertSame('0',$d['totals']['returned_quantity']);self::assertSame(3,$d['totals']['movement_count']);self::assertSame(0,$d['totals']['return_count']);self::assertSame('4',$this->data('?date_from=2026-03-29&date_to=2026-03-29')['totals']['consumed_quantity']);self::assertSame('3',$this->data('?date_from=2026-04-02&date_to=2026-04-02')['totals']['consumed_quantity']);
    }
    public function testExactLargeSumsAndUnclassifiedCategory(): void {
        $this->article=$this->db->fetchOne("INSERT INTO articles(sku,name) VALUES ('BIG','Quantité exacte') RETURNING id");foreach([1,2] as $_) {$this->movement('consumption','A',null,9007199254740991,'2026-05-01 10:00:00+00','admin',$this->users['user']);}$this->login();$d=$this->data('?date_from=2026-05-01&date_to=2026-05-01');self::assertSame('18014398509481982',$d['totals']['consumed_quantity']);self::assertSame('18014398509481982',$d['top_articles'][0]['quantity']);self::assertSame('18014398509481982',$d['buckets'][0]['consumed_quantity']);self::assertSame('18014398509481982',$d['by_category'][0]['quantity']);self::assertNull($d['by_category'][0]['id']);self::assertSame('Sans catégorie',$d['by_category'][0]['label']);
        self::assertTrue(static::getContainer()->get(OpenApiContract::class)->valid(json_decode(json_encode(['data'=>$d])),['$ref'=>'#/components/schemas/ConsumptionStatisticsResource']));self::assertStringNotContainsString('_qs',json_encode($d));
    }
    public function testLocalTimezoneOpenBoundsAndInclusiveDstBoundary(): void {
        $this->addChild(1,'2026-03-29 22:00:00+00');$this->login();self::assertSame('9',$this->data('?date_from=2026-03-29&date_to=2026-03-29')['totals']['consumed_quantity']);self::assertSame('4',$this->data('?date_from=2026-03-30')['totals']['consumed_quantity']);self::assertSame('9',$this->data('?date_to=2026-03-29')['totals']['consumed_quantity']);
        $this->db->executeStatement("UPDATE users SET timezone='Pacific/Honolulu' WHERE id=?",[$this->users['user']]);$this->login();self::assertSame('5',$this->data('?date_from=2026-03-28&date_to=2026-03-28')['totals']['consumed_quantity']);self::assertSame('2',$this->data('?date_from=2026-03-31&date_to=2026-03-31')['totals']['returned_quantity']);
    }
    public function testEmptyDataAndRangeLimitsDoNotTruncate(): void {
        $this->login('unassigned');$d=$this->data();self::assertSame('0',$d['totals']['consumed_quantity']);self::assertSame([],$d['buckets']);self::assertSame([],$d['by_user']);
        $d=$this->data('?date_from=2026-05-01&date_to=2026-05-03');self::assertCount(3,$d['buckets']);self::assertSame(['0','0','0'],array_column($d['buckets'],'consumed_quantity'));
        $this->api('?date_from=0001-01-01&date_to=9999-12-31');self::assertResponseStatusCodeSame(422);$d=$this->data('?date_from=2026-05-01');self::assertCount(1,$d['buckets']);self::assertSame('2026-05-01',$d['buckets'][0]['start_date']);
    }
    public function testAnonymizedBeneficiariesAndCurrentAccessAreRespected(): void {
        $this->db->executeStatement('UPDATE users SET active=false,email=null,name=null,password_hash=null,anonymized_at=clock_timestamp() WHERE id=?',[$this->users['tech']]);$this->login('manager');$d=$this->data();self::assertContains('Compte anonymisé',array_column($d['by_user'],'label'));self::assertStringNotContainsString('@example.test',json_encode($d));$this->db->executeStatement('DELETE FROM user_stores WHERE user_id=?',[$this->users['manager']]);self::assertSame('0',$this->data()['totals']['consumed_quantity']);
        $this->login();$this->db->executeStatement('DELETE FROM user_stores WHERE user_id=?',[$this->users['user']]);self::assertSame('12',$this->data()['totals']['consumed_quantity']);$this->db->executeStatement('UPDATE stores SET active=false WHERE id=?',[$this->stores['A']]);self::assertSame('4',$this->data()['totals']['consumed_quantity']);
    }
    public function testInvalidRequestsAnonymousAndNoWrites(): void {
        $this->login();foreach(['?wat=1','?consumer_id='.$this->users['tech'],'?granularity=year','?top_limit=101','?top_limit=0','?top_limit=1e2','?store_id[]=x','?category_id=no','?date_from=2026-02-30','?date_from=2026-04-01&date_to=2026-03-01','?date_to=0000-01-01'] as $q) {$this->api($q);self::assertResponseStatusCodeSame(422);}
        self::assertSame(7,$this->db->fetchOne('SELECT count(*) FROM stock_movements'));self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM stock_levels'));self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM audit_logs'));$this->client->getCookieJar()->clear();$this->api();self::assertResponseStatusCodeSame(401);$this->client->request('GET','/statistiques');self::assertResponseRedirects('/connexion');
    }
    public function testUiHasChartsExactTablesFiltersAndPersonalNavigation(): void {
        $this->login();$this->client->request('GET','/statistiques?date_from=2026-03-29&date_to=2026-04-01&granularity=day&top_limit=1&store_id=');self::assertResponseIsSuccessful();self::assertSelectorTextContains('h1','Mes statistiques');self::assertSelectorExists('svg[role="img"]');self::assertSelectorExists('details summary');self::assertSelectorExists('nav a[href="/statistiques"][aria-current="page"]');self::assertSelectorExists('input[name="date_from"][value="2026-03-29"]');self::assertSelectorExists('option[value="'.$this->stores['B'].'"]');self::assertSelectorNotExists('option[value="'.$this->stores['SECRET'].'"]');self::assertSelectorNotExists('main script');self::assertSelectorTextContains('main','Article <script>test</script>');self::assertSelectorTextContains('main','leur propre date');self::assertStringNotContainsString('@example.test',$this->client->getResponse()->getContent());
        $this->client->request('GET','/espace/statistiques');self::assertResponseRedirects('/statistiques');$this->client->request('GET','/statistiques?granularity=bad');self::assertResponseStatusCodeSame(422);
    }
    public function testOverOneThousandGroupsIsRejectedRatherThanTruncated(): void {
        $hash=$this->db->fetchOne('SELECT password_hash FROM users WHERE id=?',[$this->users['admin']]);
        $this->db->executeStatement("INSERT INTO users(email,name,password_hash,role_id) SELECT 'stat-'||n||'@example.test','Technicien '||n,?,3 FROM generate_series(1,1001) n",[$hash]);
        $this->db->executeStatement("INSERT INTO stock_movements(type,article_id,source_store_id,quantity,author_id,consumer_id,created_at,idempotency_key) SELECT 'consumption',?::uuid,?::uuid,1,?::uuid,id,'2026-05-01 12:00:00+00','bulkstat-'||id::text FROM users WHERE email LIKE 'stat-%'",[$this->otherArticle,$this->stores['A'],$this->users['admin']]);
        $this->login('admin');$this->api('?date_from=2026-05-01&date_to=2026-05-01');self::assertResponseStatusCodeSame(422);self::assertSame('statistics_distribution_too_large',json_decode($this->client->getResponse()->getContent(),true)['code']);
    }

}
