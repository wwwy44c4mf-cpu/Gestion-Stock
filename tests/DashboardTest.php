<?php

declare(strict_types=1);
namespace App\Tests;

use App\Api\OpenApiContract;
use App\Statistics\Dashboard;
use App\Security\StockUserProvider;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DashboardTest extends WebTestCase
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
        \App\Tests\CatalogDeletionSchema::apply($this->db);
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
    private function data(string $query=''): array {
        $this->client->request('GET','/api/v1/dashboard'.$query);
        return json_decode($this->client->getResponse()->getContent(),true)['data']??[];
    }
    private function total(string $path): int {
        $this->client->request('GET',$path);self::assertResponseIsSuccessful();
        return json_decode($this->client->getResponse()->getContent(),true)['meta']['total'];
    }
    private function stock(string $store,string $article,int $quantity,?int $threshold=null): void {
        $this->db->insert('stock_levels',['store_id'=>$this->stores[$store],'article_id'=>$article,'quantity'=>$quantity,'low_threshold'=>$threshold]);
    }
    public function testKnownIndicatorsAndPublishedSchema(): void {
        $this->stock('A',$this->article,0,2);$this->stock('A',$this->otherArticle,4,5);
        $this->login('manager');$d=$this->data();self::assertResponseIsSuccessful();
        self::assertSame(1,$d['active_articles']);self::assertSame(1,$d['out_of_stock_references']);self::assertSame(2,$d['below_threshold_references']);self::assertSame(4,$d['movements_on_period']);self::assertSame('15',$d['consumption']['consumed_quantity']);self::assertSame('2',$d['consumption']['returned_quantity']);
        self::assertTrue(static::getContainer()->get(OpenApiContract::class)->valid(json_decode(json_encode(['data'=>$d])),['$ref'=>'#/components/schemas/DashboardResource']));
        self::assertSame(0,$d['critical_alerts']);self::assertSame(0,$d['low_alerts']);
    }
    public function testEveryCardMatchesItsDestinationAndFilters(): void {
        $this->stock('A',$this->article,0,2);$this->stock('A',$this->otherArticle,4,5);$this->login('manager');
        $q='store_id='.$this->stores['A'].'&category_id='.$this->category;$d=$this->data('?'.$q.'&date_from=2026-04-01&date_to=2026-04-01');
        self::assertSame($d['out_of_stock_references'],$this->total('/api/v1/stocks?'.$q.'&status=out_of_stock'));
        self::assertSame($d['below_threshold_references'],$this->total('/api/v1/stocks?'.$q.'&status=below_threshold'));
        self::assertSame($d['active_articles'],$this->total('/api/v1/articles?status=active&category_id='.$this->category));
        self::assertSame($d['movements_on_period'],$this->total('/api/v1/movements?'.$q.'&date_from=2026-04-01&date_to=2026-04-01'));
        $this->client->request('GET','/api/v1/statistics/consumptions?'.$q.'&date_from=2026-04-01&date_to=2026-04-01');self::assertResponseIsSuccessful();
        self::assertEquals(json_decode($this->client->getResponse()->getContent(),true)['data']['totals'],$d['consumption']);self::assertSame('0',$d['consumption']['consumed_quantity']);self::assertSame('2',$d['consumption']['returned_quantity']);
        $this->client->request('GET','/?'.$q.'&date_from=2026-04-01&date_to=2026-04-01');self::assertResponseIsSuccessful();
        foreach(['Voir les articles actifs','Voir les ruptures','Voir les stocks sous seuil','Voir les mouvements de la période','Voir les consommations et statistiques','Voir les alertes critiques','Voir les alertes de stock bas'] as $label) {
            $crawler=$this->client->getCrawler();$link=$crawler->selectLink($label)->link();$this->client->click($link);self::assertResponseIsSuccessful();$this->client->request('GET','/?'.$q.'&date_from=2026-04-01&date_to=2026-04-01');
        }
    }
    public function testScopesMissingBalancesAndLiveRevocation(): void {
        $this->login('manager');$d=$this->data();self::assertSame(2,$d['out_of_stock_references']);self::assertSame(0,$d['below_threshold_references']);
        $this->login('admin');$d=$this->data();self::assertSame(8,$d['out_of_stock_references']);self::assertSame(7,$d['movements_on_period']);self::assertSame('36',$d['consumption']['consumed_quantity']);
        $this->login('unassigned');$d=$this->data();self::assertSame(0,$d['out_of_stock_references']);self::assertSame(0,$d['movements_on_period']);self::assertSame('0',$d['consumption']['consumed_quantity']);self::assertSame(1,$d['active_articles']);
        $this->login('manager');$this->data('?store_id='.$this->stores['SECRET']);self::assertResponseStatusCodeSame(404);$this->data('?store_id='.$this->stores['D']);self::assertResponseStatusCodeSame(404);
        $this->db->executeStatement('DELETE FROM user_stores WHERE user_id=?',[$this->users['manager']]);$d=$this->data();self::assertSame(0,$d['movements_on_period']);self::assertSame(0,$d['out_of_stock_references']);
    }
    public function testPartialTransfersAndCorrectionsMatchJournalWithoutLeaking(): void {
        $this->db->executeStatement('UPDATE articles SET active=true WHERE id=?',[$this->article]);
        $this->movement('transfer','A','SECRET',2,'2026-03-29 12:00:00+00','outside');
        $this->movement('entry',null,'A',10,'2026-03-29 12:00:00+00','admin');
        $undo=$this->movement('reversal',null,'A',5,'2026-04-02 09:00:00+00','admin',null,$this->consumption);
        $replacement=$this->movement('consumption','A',null,3,'2026-04-02 09:00:00+00','admin',$this->users['user']);$this->db->insert('movement_corrections',['original_id'=>$this->consumption,'reversal_id'=>$undo,'replacement_id'=>$replacement]);
        $this->login('manager');$d=$this->data();self::assertSame(8,$d['movements_on_period']);self::assertSame('13',$d['consumption']['consumed_quantity']);self::assertSame('0',$d['consumption']['returned_quantity']);self::assertStringNotContainsString('SECRET',json_encode($d));
        self::assertSame($d['movements_on_period'],$this->total('/api/v1/movements'));$this->login('admin');self::assertSame(11,$this->data()['movements_on_period']);
    }
    public function testAlertsConcordanceCategoryAndDatesDoNotAffectCurrentStock(): void {
        $this->stock('A',$this->article,0,2);$this->stock('A',$this->otherArticle,1,5);$this->stock('SECRET',$this->article,0,2);
        foreach([['A',$this->article,'critical'],['A',$this->otherArticle,'low'],['SECRET',$this->article,'critical']] as [$store,$article,$level]) {$this->db->insert('stock_alerts',['store_id'=>$this->stores[$store],'article_id'=>$article,'level'=>$level,'threshold'=>2]);}
        $this->login('manager');$d=$this->data('?date_from=1900-01-01&date_to=1900-01-01');self::assertSame(1,$d['critical_alerts']);self::assertSame(1,$d['low_alerts']);self::assertSame(0,$d['movements_on_period']);self::assertSame(2,$d['below_threshold_references']);
        $q='category_id='.$this->category;$d=$this->data('?'.$q);self::assertSame(1,$d['critical_alerts']);self::assertSame(0,$d['low_alerts']);self::assertSame($d['critical_alerts'],$this->total('/api/v1/alerts?status=active&level=critical&'.$q));self::assertSame(0,$this->total('/api/v1/alerts?status=active&level=low&'.$q));
    }
    public function testPersonalHomeIncludesUnassignedOriginsWithoutStockOrOthers(): void {
        $this->login();$this->data();self::assertResponseStatusCodeSame(403);
        $this->client->request('GET','/?date_from=2026-03-29&date_to=2026-04-01&store_id=');self::assertResponseIsSuccessful();self::assertSelectorTextContains('h1','Mon tableau de bord');self::assertSelectorTextContains('[data-indicator="consumed_quantity"]','12');self::assertSelectorTextContains('[data-indicator="returned_quantity"]','2');self::assertSelectorNotExists('[data-indicator="out_of_stock_references"]');self::assertSelectorNotExists('main a[href^="/stocks"]');self::assertSelectorNotExists('main a[href^="/alertes"]');self::assertSelectorNotExists('main a[href^="/mouvements"]');self::assertSelectorExists('option[value="'.$this->stores['B'].'"]');self::assertSelectorNotExists('option[value="'.$this->stores['SECRET'].'"]');
        $this->client->request('GET','/?date_from=&date_to=&store_id='.$this->stores['B']);self::assertResponseIsSuccessful();self::assertSelectorTextContains('[data-indicator="consumed_quantity"]','4');
        $this->client->request('GET','/stocks/'.$this->stores['B'].'/'.$this->article);self::assertResponseStatusCodeSame(404);
        $this->db->executeStatement('DELETE FROM user_stores WHERE user_id=?',[$this->users['user']]);$this->client->request('GET','/?date_from=&date_to=&store_id=');self::assertResponseIsSuccessful();self::assertSelectorTextContains('[data-indicator="consumed_quantity"]','12');
        $this->db->executeStatement('UPDATE stores SET active=false WHERE id=?',[$this->stores['A']]);$this->client->request('GET','/?date_from=&date_to=&store_id=');self::assertSelectorTextContains('[data-indicator="consumed_quantity"]','4');
    }
    public function testExactSumsTimezoneAndBoundaries(): void {
        $this->login('manager');$d=$this->data('?date_from=2026-03-29&date_to=2026-03-29');self::assertSame('12',$d['consumption']['consumed_quantity']);self::assertSame(2,$d['movements_on_period']);
        $this->db->executeStatement("UPDATE users SET timezone='Pacific/Honolulu' WHERE id=?",[$this->users['manager']]);$this->login('manager');$d=$this->data('?date_from=2026-03-28&date_to=2026-03-28');self::assertSame('5',$d['consumption']['consumed_quantity']);
        $this->article=$this->db->fetchOne("INSERT INTO articles(sku,name) VALUES ('BIG','Quantité exacte') RETURNING id");foreach([1,2] as $_) {$this->movement('consumption','A',null,9007199254740991,'2026-05-01 12:00:00+00','admin',$this->users['user']);}
        $d=$this->data('?date_from=2026-05-01&date_to=2026-05-01');self::assertSame('18014398509481982',$d['consumption']['consumed_quantity']);self::assertSame(2,$d['consumption']['movement_count']);
        $this->data('?date_from=0001-01-01&date_to=9999-12-31');self::assertResponseIsSuccessful();
    }
    public function testInvalidAnonymousUiDefaultsAndNoWrites(): void {
        $this->login('manager');foreach(['?wat=x','?store_id[]=x','?category_id=bad','?date_from=2026-02-30','?date_from=2026-05-02&date_to=2026-05-01'] as $q) {$this->data($q);self::assertResponseStatusCodeSame(422);$this->client->request('GET','/'.$q);self::assertResponseStatusCodeSame(422);}
        $this->client->request('GET','/');self::assertResponseIsSuccessful();$today=new \DateTimeImmutable('now',new \DateTimeZone('Europe/Paris'));self::assertSelectorExists('input[name="date_from"][value="'.$today->format('Y-m-01').'"]');self::assertSelectorExists('nav a[href="/"][aria-current="page"]');
        self::assertSame(7,$this->db->fetchOne('SELECT count(*) FROM stock_movements'));self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM stock_levels'));self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM audit_logs'));
        $this->client->getCookieJar()->clear();$this->data();self::assertResponseStatusCodeSame(401);$this->client->request('GET','/');self::assertResponseRedirects('/connexion');
    }
    public function testStoreContextResetAndEscapedFilterLabels(): void {
        $this->login('admin');$this->client->request('GET','/');
        $session=$this->client->getRequest()->getSession();$session->set('store_context',$this->stores['A']);$session->save();
        $this->client->request('GET','/?date_from=&date_to=');self::assertResponseIsSuccessful();self::assertSelectorTextContains('[data-indicator="out_of_stock_references"]','2');
        $this->client->request('GET','/?date_from=&date_to=&store_id=');self::assertResponseIsSuccessful();self::assertSelectorTextContains('[data-indicator="out_of_stock_references"]','8');
        self::assertSelectorExists('a[href="/stocks?store_id=&status=out_of_stock"]');
        $this->db->executeStatement('UPDATE categories SET name=? WHERE id=?',['<script>unsafe</script>',$this->category]);
        $this->client->request('GET','/?store_id=');self::assertResponseIsSuccessful();self::assertSelectorNotExists('main script');self::assertStringContainsString('&lt;script&gt;unsafe&lt;/script&gt;',$this->client->getResponse()->getContent());
    }

}
