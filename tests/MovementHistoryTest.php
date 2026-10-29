<?php

declare(strict_types=1);
namespace App\Tests;

use App\Api\OpenApiContract;
use App\History\MovementDirectory;
use App\Security\StockUserProvider;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MovementHistoryTest extends WebTestCase
{
    private $client;
    private Connection $db;
    private array $users=[], $stores=[];
    private string $article, $entry, $transfer, $consumption, $return;
    protected function setUp(): void
    {
        $this->client=static::createClient();$this->client->disableReboot();$this->db=static::getContainer()->get(Connection::class);$this->db->beginTransaction();
        $schema='stk08_'.bin2hex(random_bytes(6));$this->db->executeStatement('CREATE SCHEMA '.$schema);$this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        foreach(explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) { if(trim($sql)!=='') { $this->db->executeStatement($sql); } }
        \App\Tests\CatalogDeletionSchema::apply($this->db);
        $this->db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
        foreach(['20261018140000','20261018200000','20261018223000'] as $version) { require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';$class='DoctrineMigrations\\Version'.$version;$migration=new $class($this->db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) { $this->db->executeStatement($sql->getStatement()); } }
        $hash=password_hash('History-test-2026!',PASSWORD_ARGON2ID);
        foreach(['admin'=>1,'manager'=>2,'user'=>3,'outside'=>2,'unassigned'=>2] as $name=>$role) { $this->users[$name]=$this->db->fetchOne('INSERT INTO users (email,name,password_hash,role_id) VALUES (?,?,?,?) RETURNING id',[$name.'@example.test',$name,$hash,$role]); }
        foreach(['A','SECRET','D'] as $code) { $this->stores[$code]=$this->db->fetchOne('INSERT INTO stores (code,name) VALUES (?,?) RETURNING id',[$code,'Magasin '.$code]); }
        $this->db->executeStatement('UPDATE stores SET active=false WHERE id=?',[$this->stores['D']]);
        $this->db->insert('user_stores',['user_id'=>$this->users['manager'],'store_id'=>$this->stores['A']]);
        $this->article=$this->db->fetchOne("INSERT INTO articles (sku,name,active) VALUES ('HISTORY','Article <script>test</script>',true) RETURNING id");
        $this->entry=$this->movement('entry',null,'A',10,'2026-03-28 23:00:00+00','manager');
        $this->consumption=$this->movement('consumption','A',null,2,'2026-03-29 20:00:00+00','manager',$this->users['user']);
        $this->return=$this->movement('return',null,'A',1,'2026-03-29 21:59:59+00','manager',null,$this->consumption);
        $this->transfer=$this->movement('transfer','A','SECRET',3,'2026-03-29 22:00:00+00','outside');
        $this->movement('entry',null,'SECRET',20,'2026-03-29 12:00:00+00','outside');
        $this->movement('entry',null,'D',30,'2026-03-29 12:00:00+00','admin');
        $this->db->executeStatement('UPDATE articles SET active=false WHERE id=?',[$this->article]);
    }
    private function movement(string $type,?string $source,?string $destination,int $quantity,string $date,string $author,?string $consumer=null,?string $related=null): string
    {
        return $this->db->fetchOne('INSERT INTO stock_movements (type,article_id,source_store_id,destination_store_id,quantity,author_id,consumer_id,related_movement_id,reason,reference,comment,created_at,idempotency_key) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id',[$type,$this->article,$source===null?null:$this->stores[$source],$destination===null?null:$this->stores[$destination],$quantity,$this->users[$author],$consumer,$related,'Magasin SECRET','Secret reference','Secret comment',$date,bin2hex(random_bytes(12))]);
    }
    protected function tearDown(): void { if($this->db->isTransactionActive()) {$this->db->rollBack();}parent::tearDown(); }
    private function login(string $name='manager'): void { $this->client->getCookieJar()->clear();$this->client->loginUser(static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier($name.'@example.test')); }
    private function api(string $query=''): array { $this->client->request('GET','/api/v1/movements'.$query);return json_decode($this->client->getResponse()->getContent(),true)??[]; }
    public function testPartialTransferMasksEverythingOutsidePerimeterBeforePersonFilters(): void
    {
        $this->login();$result=$this->api('?type=transfer');self::assertResponseIsSuccessful();self::assertSame(1,$result['meta']['total']);$row=$result['data'][0];
        self::assertTrue($row['restricted']);self::assertSame($this->stores['A'],$row['source_store_id']);self::assertSame(3,$row['quantity']);
        foreach(['destination_store_id','destination_store_name','author_id','author_name','consumer_id','consumer_name','reason','reference','comment','related_movement_id','inventory_id'] as $key) {self::assertNull($row[$key],$key);}
        self::assertStringNotContainsString('SECRET',json_encode($result));self::assertStringNotContainsString($this->users['outside'],json_encode($result));
        $result=$this->api('?author_id='.$this->users['outside']);self::assertSame(0,$result['meta']['total']);
        $this->api('?store_id='.$this->stores['SECRET']);self::assertResponseStatusCodeSame(404);
        $this->client->request('GET','/api/v1/movements/'.$this->transfer);self::assertResponseIsSuccessful();self::assertTrue(json_decode($this->client->getResponse()->getContent(),true)['data']['restricted']);
        $this->client->request('GET','/mouvements/'.$this->transfer);self::assertResponseIsSuccessful();self::assertStringNotContainsString('SECRET',$this->client->getResponse()->getContent());self::assertSelectorNotExists('a[href="/stocks/'.$this->stores['SECRET'].'/'.$this->article.'"]');
        $actor=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('manager@example.test');$people=static::getContainer()->get(MovementDirectory::class)->people($actor);self::assertNotContains($this->users['outside'],array_column($people,'id'));
    }
    public function testCombinedFiltersPaginationAndLocalDstDay(): void
    {
        $this->login();$filters='?date_from=2026-03-29&date_to=2026-03-29&article_id='.$this->article.'&store_id='.$this->stores['A'].'&author_id='.$this->users['manager'].'&limit=1&order=asc';
        $result=$this->api($filters);self::assertResponseIsSuccessful();self::assertSame(3,$result['meta']['total']);self::assertSame(3,$result['meta']['total_pages']);self::assertSame($this->entry,$result['data'][0]['id']);
        $result=$this->api($filters.'&page=3');self::assertSame($this->return,$result['data'][0]['id']);
        $result=$this->api($filters.'&type=return&consumer_id='.$this->users['user']);self::assertSame(1,$result['meta']['total']);self::assertSame($this->return,$result['data'][0]['id']);self::assertSame($this->users['user'],$result['data'][0]['consumer_id']);
        $result=$this->api('?sort=quantity&order=desc&limit=2');self::assertSame([$this->entry,$this->transfer],array_column($result['data'],'id'));
        $result=$this->api('?date_from=2026-03-30&date_to=2026-03-30');self::assertSame([$this->transfer],array_column($result['data'],'id'));
    }
    public function testAdminSeesAllAndContractMatchesReadProjectionWithoutChangingWriteContract(): void
    {
        $this->login('admin');$result=$this->api();self::assertResponseIsSuccessful();self::assertSame(6,$result['meta']['total']);
        $contract=static::getContainer()->get(OpenApiContract::class);self::assertTrue($contract->valid(json_decode(json_encode($result)),['$ref'=>'#/components/schemas/HistoryMovementPage']));
        $this->client->request('GET','/api/v1/movements/'.$this->transfer);$detail=json_decode($this->client->getResponse()->getContent(),true);self::assertFalse($detail['data']['restricted']);self::assertSame($this->stores['SECRET'],$detail['data']['destination_store_id']);self::assertTrue($contract->valid(json_decode(json_encode($detail)),['$ref'=>'#/components/schemas/HistoryMovementResource']));
        $this->client->request('GET','/mouvements/'.$this->entry);self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Magasin SECRET');self::assertStringNotContainsString('<script>test</script>',$this->client->getResponse()->getContent());
    }
    public function testRolesInvalidQueriesEmptyScopeAndUnknownDetails(): void
    {
        $this->api();self::assertResponseStatusCodeSame(401);$this->login('user');$this->api();self::assertResponseStatusCodeSame(403);$this->client->request('GET','/mouvements');self::assertResponseStatusCodeSame(403);
        $this->login('unassigned');self::assertSame(0,$this->api()['meta']['total']);$this->client->request('GET','/api/v1/movements/'.$this->entry);self::assertResponseStatusCodeSame(404);
        $this->login();foreach(['?wat=1','?page=0','?limit=101','?sort=reason','?order=wrong','?date_from=2026-02-30','?date_from=2026-04-01&date_to=2026-03-01','?author_id[]=x','?article_id=bad'] as $query) {$this->api($query);self::assertResponseStatusCodeSame(422);}
        foreach(['bad','11111111-1111-4111-8111-111111111111'] as $id) {$this->client->request('GET','/api/v1/movements/'.$id);self::assertResponseStatusCodeSame(404);}
        $this->client->request('GET','/api/v1/movements/'.$this->entry.'?page=1');self::assertResponseStatusCodeSame(422);
        self::assertSame(6,(int)$this->db->fetchOne('SELECT count(*) FROM stock_movements'));self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_levels'));
    }
    public function testUiFiltersRetainValuesLinksAndNoUnauthorizedPeople(): void
    {
        $this->login();$this->client->request('GET','/mouvements?store_id=&limit=1&date_from=2026-03-29');self::assertResponseIsSuccessful();self::assertSelectorExists('a[href*="page=2"]');self::assertSelectorExists('input[name="date_from"][value="2026-03-29"]');self::assertSelectorNotExists('option[value="'.$this->users['outside'].'"]');self::assertSelectorExists('a[href="/mouvements"][aria-current]');
        $this->client->request('GET','/espace/mouvements');self::assertResponseRedirects('/mouvements');
    }
    public function testCorrectionChainsAllTypesStableTiesAndAnonymizedBeneficiary(): void
    {
        $reversal=$this->movement('reversal','A',null,10,'2026-03-30 12:00:00+00','manager',null,$this->entry);
        $replacement=$this->movement('entry',null,'A',8,'2026-03-30 12:00:00+00','manager');
        $adjustment=$this->movement('adjustment','A',null,1,'2026-03-30 12:00:00+00','manager');
        $this->db->insert('movement_corrections',['original_id'=>$this->entry,'reversal_id'=>$reversal,'replacement_id'=>$replacement]);
        $this->db->executeStatement('UPDATE users SET active=false,email=null,name=null,password_hash=null,anonymized_at=clock_timestamp() WHERE id=?',[$this->users['user']]);
        $this->login();$this->client->request('GET','/api/v1/movements/'.$this->entry);$row=json_decode($this->client->getResponse()->getContent(),true)['data'];self::assertSame($reversal,$row['reversed_by']);self::assertSame($replacement,$row['replacement_id']);
        $this->client->request('GET','/mouvements/'.$this->entry);self::assertResponseIsSuccessful();self::assertSelectorExists('a[href="/mouvements/'.$replacement.'"]');
        $this->client->request('GET','/mouvements/'.$replacement);self::assertResponseIsSuccessful();self::assertSelectorExists('a[href="/mouvements/'.$this->entry.'"]');
        $result=$this->api('?consumer_id='.$this->users['user']);self::assertSame(2,$result['meta']['total']);self::assertSame(['Compte anonymisé'],array_values(array_unique(array_column($result['data'],'consumer_name'))));
        $expected=[$reversal,$replacement,$adjustment];sort($expected);$actual=[];
        foreach([1,2,3] as $page) {$result=$this->api('?date_from=2026-03-30&date_to=2026-03-30&author_id='.$this->users['manager'].'&limit=1&page='.$page);self::assertSame(3,$result['meta']['total']);$actual[]=$result['data'][0]['id'];}self::assertSame($expected,$actual);
        $this->db->executeStatement('UPDATE stores SET active=false WHERE id=?',[$this->stores['A']]);$this->login('admin');$this->client->request('GET','/mouvements/'.$this->entry);self::assertResponseIsSuccessful();self::assertSelectorExists('a[href="/mouvements/'.$reversal.'"]');self::assertSelectorNotExists('a[href="/stocks/mouvements/'.$this->entry.'/corriger"]');
        $this->login();self::assertSame(0,$this->api()['meta']['total']);
    }

}
