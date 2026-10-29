<?php

declare(strict_types=1);
namespace App\Tests;

use App\Api\OpenApiContract;
use App\Consumption\ConsumptionHistory;
use App\Security\StockUserProvider;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ConsumptionHistoryTest extends WebTestCase
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
    private function api(string $query=''): array { $this->client->request('GET','/api/v1/consumptions'.$query);return json_decode($this->client->getResponse()->getContent(),true)??[]; }
    public function testPersonalHistoryIncludesThirdPartyEntriesAndNeverOthersOrStocks(): void {
        $this->login();$r=$this->api();self::assertResponseIsSuccessful();self::assertSame(3,$r['meta']['total']);self::assertEqualsCanonicalizing([$this->consumption,$this->personalB,$this->child],array_column($r['data'],'id'));self::assertSame(['unit'=>'unit','consumed_quantity'=>'12','returned_quantity'=>'2'],$r['totals']);
        self::assertSame([$this->users['user']],array_values(array_unique(array_column($r['data'],'consumer_id'))));$this->api('?consumer_id='.$this->users['tech']);self::assertResponseStatusCodeSame(403);
        $r=$this->api('?store_id='.$this->stores['B']);self::assertResponseIsSuccessful();self::assertSame([$this->personalB],array_column($r['data'],'id'));
        $this->client->request('GET','/consommations/'.$this->personalB);self::assertResponseIsSuccessful();self::assertSelectorNotExists('a[href="/stocks/'.$this->stores['B'].'/'.$this->article.'"]');$this->client->request('GET','/stocks/'.$this->stores['B'].'/'.$this->article);self::assertResponseStatusCodeSame(404);
        foreach(['SECRET','D'] as $store) {$this->api('?store_id='.$this->stores[$store]);self::assertResponseStatusCodeSame(404);}
    }
    public function testManagerAndAdminScopesAndEmptyUnassignedManager(): void {
        $this->login('manager');$r=$this->api();self::assertResponseIsSuccessful();self::assertSame(3,$r['meta']['total']);self::assertSame('15',$r['totals']['consumed_quantity']);self::assertSame([$this->stores['A']],array_values(array_unique(array_column($r['data'],'source_store_id'))));$this->api('?store_id='.$this->stores['B']);self::assertResponseStatusCodeSame(404);
        $this->login('admin');$r=$this->api();self::assertSame(6,$r['meta']['total']);$r=$this->api('?store_id='.$this->stores['D']);self::assertSame(1,$r['meta']['total']);
        $this->login('unassigned');$r=$this->api();self::assertSame(0,$r['meta']['total']);self::assertSame('0',$r['totals']['consumed_quantity']);$this->client->request('GET','/consommations');self::assertResponseIsSuccessful();
    }
    public function testCombinedInclusiveDatesCategoryArticleConsumerAndStablePages(): void {
        $this->login();$query='?date_from=2026-03-29&date_to=2026-03-29&article_id='.$this->article.'&category_id='.$this->category.'&consumer_id='.$this->users['user'].'&limit=1&order=asc';$r=$this->api($query);self::assertSame(2,$r['meta']['total']);self::assertSame($this->consumption,$r['data'][0]['id']);self::assertSame('9',$r['totals']['consumed_quantity']);self::assertSame('2',$r['totals']['returned_quantity']);
        $next=$this->api($query.'&page=2');self::assertSame($this->personalB,$next['data'][0]['id']);self::assertSame($r['totals'],$next['totals']);self::assertSame(2,$next['meta']['total_pages']);
        $all=$this->api($query.'&store_id='.$this->stores['A']);self::assertSame(1,$all['meta']['total']);self::assertSame('5',$all['totals']['consumed_quantity']);
        $r=$this->api('?category_id='.$this->category);self::assertSame(2,$r['meta']['total']);$r=$this->api('?category_id='.$this->childCategory);self::assertSame([$this->child],array_column($r['data'],'id'));
        self::assertSame([$this->child],array_column($this->api('?date_from=2026-03-30')['data'],'id'));self::assertSame(2,$this->api('?date_to=2026-03-29')['meta']['total']);
    }
    public function testCancelledCorrectedRowsRemainAndReversedReturnsDoNotCount(): void {
        $this->db->executeStatement('UPDATE articles SET active=true WHERE id=?',[$this->article]);
        $this->movement('reversal','A',null,2,'2026-04-02 08:00:00+00','admin',null,$this->return);
        $undo=$this->movement('reversal',null,'A',5,'2026-04-02 09:00:00+00','admin',null,$this->consumption);$replacement=$this->movement('consumption','A',null,3,'2026-04-02 09:00:00+00','admin',$this->users['user']);$this->db->insert('movement_corrections',['original_id'=>$this->consumption,'reversal_id'=>$undo,'replacement_id'=>$replacement]);
        $this->movement('reversal',null,'B',4,'2026-04-02 10:00:00+00','admin',null,$this->personalB);$this->login();$r=$this->api();self::assertSame(4,$r['meta']['total']);self::assertSame('6',$r['totals']['consumed_quantity']);self::assertSame('0',$r['totals']['returned_quantity']);$rows=array_column($r['data'],null,'id');self::assertSame('corrected',$rows[$this->consumption]['status']);self::assertSame($replacement,$rows[$this->consumption]['replacement_id']);self::assertSame('cancelled',$rows[$this->personalB]['status']);self::assertSame(0,$rows[$this->consumption]['returned_quantity']);
        $this->client->request('GET','/consommations/'.$this->consumption);self::assertSelectorTextContains('main','corrigée');self::assertSelectorTextContains('main','Retours liés');self::assertSelectorTextContains('main','Annulé');self::assertSelectorExists('a[href="/consommations/'.$replacement.'"]');
    }
    public function testCorrectionsCannotChangeBeneficiaryOrOriginalStore(): void {
        $this->db->executeStatement('UPDATE articles SET active=true WHERE id=?',[$this->article]);
        try {$this->db->transactional(function(): void {
            $undo=$this->movement('reversal',null,'A',5,'2026-04-02 09:00:00+00','admin',null,$this->consumption);
            $replacement=$this->movement('consumption','SECRET',null,3,'2026-04-02 09:00:00+00','admin',$this->users['tech']);
            $this->db->insert('movement_corrections',['original_id'=>$this->consumption,'reversal_id'=>$undo,'replacement_id'=>$replacement]);
        });self::fail();}catch(\Doctrine\DBAL\Exception $e) {self::assertStringContainsString('Paire de correction invalide',$e->getMessage());}
        $this->login();$r=$this->api();self::assertSame(3,$r['meta']['total']);self::assertSame('valid',array_column($r['data'],null,'id')[$this->consumption]['status']);
    }
    public function testReturnCorrectionsCannotChangeTheOriginalConsumption(): void {
        $other=$this->db->fetchOne("SELECT id FROM stock_movements WHERE consumer_id=? AND source_store_id=?",[$this->users['tech'],$this->stores['A']]);
        try {$this->db->transactional(function() use($other): void {
            $undo=$this->movement('reversal','A',null,2,'2026-04-02 08:00:00+00','admin',null,$this->return);
            $replacement=$this->movement('return',null,'A',1,'2026-04-02 09:00:00+00','admin',null,$other);
            $this->db->insert('movement_corrections',['original_id'=>$this->return,'reversal_id'=>$undo,'replacement_id'=>$replacement]);
        });self::fail();}catch(\Doctrine\DBAL\Exception $e) {self::assertStringContainsString('Paire de correction invalide',$e->getMessage());}
        $this->login();self::assertSame('2',$this->api()['totals']['returned_quantity']);$this->client->request('GET','/consommations/'.$this->consumption);self::assertSelectorExists('a[href="/consommations/retours/'.$this->return.'"]');
    }
    public function testUiUsesScopedOptionsRetainsFiltersAndEscapesText(): void {
        $this->login();$this->client->request('GET','/consommations?store_id=&limit=1&date_from=2026-03-29&order=asc');self::assertResponseIsSuccessful();self::assertSelectorTextContains('h1','Mes consommations');self::assertSelectorExists('nav a[aria-current="page"][href="/consommations"]');self::assertSelectorExists('a[href*="page=2"]');self::assertSelectorExists('input[name="date_from"][value="2026-03-29"]');self::assertSelectorNotExists('select[name="consumer_id"]');self::assertSelectorNotExists('option[value="'.$this->stores['SECRET'].'"]');self::assertSelectorNotExists('option[value="'.$this->stores['D'].'"]');self::assertSelectorExists('option[value="'.$this->stores['B'].'"]');self::assertSelectorNotExists('main script');self::assertSelectorTextContains('tbody','Article <script>test</script>');
        $this->login('manager');$this->client->request('GET','/consommations');self::assertSelectorExists('select[name="consumer_id"] option[value="'.$this->users['tech'].'"]');self::assertSelectorNotExists('option[value="'.$this->users['outside'].'"]');self::assertStringNotContainsString('@example.test',$this->client->getResponse()->getContent());
    }
    public function testRightsImmediatelyReflectRemovalInactiveStoreAndRevokedSession(): void {
        $this->login();$this->db->executeStatement('DELETE FROM user_stores WHERE user_id=?',[$this->users['user']]);self::assertSame(3,$this->api()['meta']['total']);$this->client->request('GET','/consommations/'.$this->consumption);self::assertResponseIsSuccessful();self::assertSelectorNotExists('a[href="/stocks/'.$this->stores['A'].'/'.$this->article.'"]');
        $this->db->executeStatement('UPDATE stores SET active=false WHERE id=?',[$this->stores['A']]);self::assertSame([$this->personalB],array_column($this->api()['data'],'id'));
        $this->login('manager');self::assertSame(0,$this->api()['meta']['total']);$this->db->executeStatement('UPDATE users SET active=false WHERE id=?',[$this->users['manager']]);$this->api();self::assertResponseStatusCodeSame(401);
    }
    public function testQueriesInvalidDatesUnknownAndNestedValuesRejectedWithoutWrites(): void {
        $this->login();foreach(['?unknown=x','?page=0','?limit=101','?sort=comment','?order=no','?article_id=no','?category_id[]=x','?consumer_id[]=x','?date_from=2026-02-30','?date_from=2026-04-01&date_to=2026-03-01','?date_to=0000-01-01'] as $q) {$this->api($q);self::assertResponseStatusCodeSame(422);}
        self::assertSame(7,$this->db->fetchOne('SELECT count(*) FROM stock_movements'));self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM stock_levels'));self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM audit_logs'));
        $this->client->getCookieJar()->clear();$this->api();self::assertResponseStatusCodeSame(401);$this->client->request('GET','/consommations');self::assertResponseRedirects('/connexion');
    }
    public function testPublishedSchemaAndExactTotalsAboveJavascriptIntegerRange(): void {
        $this->login();$r=$this->api();self::assertTrue(static::getContainer()->get(OpenApiContract::class)->valid(json_decode(json_encode($r)),['$ref'=>'#/components/schemas/ConsumptionPage']));
        $old=$this->article;$this->article=$this->db->fetchOne("INSERT INTO articles(sku,name) VALUES ('BIG','Grande quantité') RETURNING id");foreach([1,2] as $_) {$this->movement('consumption','A',null,9007199254740991,'2026-03-29 12:00:00+00','admin',$this->users['user']);}$r=$this->api('?article_id='.$this->article.'&limit=1');self::assertSame('18014398509481982',$r['totals']['consumed_quantity']);self::assertSame(9007199254740991,$r['data'][0]['quantity']);self::assertSame(2,$r['meta']['total']);$this->article=$old;
    }
    public function testAnonymizedIdentitiesAndSortingRemainUsable(): void {
        $this->db->executeStatement('UPDATE users SET active=false,email=null,name=null,password_hash=null,anonymized_at=clock_timestamp() WHERE id=?',[$this->users['tech']]);$this->login('manager');$r=$this->api('?consumer_id='.$this->users['tech']);self::assertSame('Compte anonymisé',$r['data'][0]['consumer_name']);
        $this->login();$r=$this->api('?sort=quantity&order=desc');self::assertSame([5,4,3],array_column($r['data'],'quantity'));$this->api('?sort=quantity&order=asc&limit=1&page=10000');self::assertResponseIsSuccessful();self::assertCount(0,json_decode($this->client->getResponse()->getContent(),true)['data']);
    }
    public function testAccountTimezoneAndStableTiesAcrossPages(): void {
        $this->db->executeStatement("UPDATE users SET timezone='Pacific/Honolulu' WHERE id=?",[$this->users['user']]);$this->login();$r=$this->api('?date_from=2026-03-28&date_to=2026-03-28');self::assertSame([$this->consumption],array_column($r['data'],'id'));
        $this->db->executeStatement('UPDATE articles SET active=true WHERE id=?',[$this->article]);$one=$this->movement('consumption','A',null,2,'2026-04-04 20:00:00+00','admin',$this->users['user']);$two=$this->movement('consumption','A',null,2,'2026-04-04 20:00:00+00','admin',$this->users['user']);$expected=[$one,$two];sort($expected);$actual=[];
        foreach([1,2] as $page) {$r=$this->api('?date_from=2026-04-04&date_to=2026-04-04&sort=quantity&order=asc&limit=1&page='.$page);self::assertSame(2,$r['meta']['total']);self::assertSame('4',$r['totals']['consumed_quantity']);$actual[]=$r['data'][0]['id'];}self::assertSame($expected,$actual);
    }

}
