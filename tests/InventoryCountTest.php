<?php

declare(strict_types=1);
namespace App\Tests;
use App\Api\ApiProblem;
use App\Api\OpenApiContract;
use App\Inventory\InventoryWorkflow;
use App\Security\StockUserProvider;
use App\Stock\StockMovementEngine;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InventoryCountTest extends WebTestCase
{
    private $client;private Connection $db;private array $users=[], $stores=[];private string $csrf,$article;
    protected function setUp(): void
    {
        $this->client=static::createClient();$this->client->disableReboot();$this->db=static::getContainer()->get(Connection::class);$this->db->beginTransaction();
        $schema='inv01_'.bin2hex(random_bytes(6));$this->db->executeStatement('CREATE SCHEMA '.$schema);$this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        foreach(explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) {if(trim($sql)!=='') {$this->db->executeStatement($sql);}}
        \App\Tests\CatalogDeletionSchema::apply($this->db);
        $this->db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
        foreach(['20261018140000','20261018200000','20261018223000','20261020220000','20261020230000'] as $version) {require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';$class='DoctrineMigrations\\Version'.$version;$migration=new $class($this->db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) {$this->db->executeStatement($sql->getStatement());}}
        $hash=password_hash('Inventory-test-2026!',PASSWORD_ARGON2ID);
        foreach(['admin'=>1,'manager'=>2,'user'=>3,'unassigned'=>2] as $name=>$role) {$this->users[$name]=$this->db->fetchOne('INSERT INTO users (email,name,password_hash,role_id) VALUES (?,?,?,?) RETURNING id',[$name.'@example.test',$name,$hash,$role]);}
        foreach(['A','B','D'] as $code) {$this->stores[$code]=$this->db->fetchOne('INSERT INTO stores (code,name) VALUES (?,?) RETURNING id',[$code,'Magasin '.$code]);}
        $this->db->executeStatement('UPDATE stores SET active=false WHERE id=?',[$this->stores['D']]);
        $this->db->insert('user_stores',['user_id'=>$this->users['manager'],'store_id'=>$this->stores['A']]);
        $this->article=$this->db->fetchOne("INSERT INTO articles (sku,name) VALUES ('INV','Article <script>test</script>') RETURNING id");
    }
    protected function tearDown(): void {if($this->db->isTransactionActive()) {$this->db->rollBack();}parent::tearDown();}
    private function actor(string $name='manager') {return static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier($name.'@example.test');}
    private function login(string $name='manager'): void
    {
        $this->client->getCookieJar()->clear();$this->client->loginUser($this->actor($name));$this->client->request('GET','/api/v1/auth/csrf');$this->csrf=json_decode($this->client->getResponse()->getContent(),true)['token'];
    }
    private function create(string $store='A',string $key='inventory-create-key'): array
    {
        $this->client->request('POST','/api/v1/inventories',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>$key],content:json_encode(['store_id'=>$this->stores[$store]]));return json_decode($this->client->getResponse()->getContent(),true)??[];
    }
    private function change(string $id,string $action,string $key): array
    {
        $this->client->request('POST','/api/v1/inventories/'.$id.'/'.$action,server:['HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>$key]);return json_decode($this->client->getResponse()->getContent(),true)??[];
    }
    private function started(): string
    {
        $this->login();$id=$this->create()['data']['id'];$this->change($id,'start','count-start-key');self::assertResponseIsSuccessful();return $id;
    }
    private function write(string $id,array $lines,string $key='inventory-count-key'): array
    {
        $this->client->request('POST','/api/v1/inventories/'.$id.'/lines',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>$key],content:json_encode(['lines'=>$lines],JSON_PRESERVE_ZERO_FRACTION));return json_decode($this->client->getResponse()->getContent(),true)??[];
    }
    private function input(int $quantity=0,int $version=0,?string $article=null): array {return ['article_id'=>$article??$this->article,'counted_qty'=>$quantity,'expected_version'=>$version];}
    private function row(string $id): array {return $this->db->fetchAssociative('SELECT counted_qty,counted_by,counted_at,version,delta FROM inventory_lines WHERE inventory_id=? AND article_id=?',[$id,$this->article]);}
    public function testZeroIsCountedAndReplaysNeverOverwriteNewerCounts(): void
    {
        $id=$this->started();$result=$this->write($id,[$this->input()]);self::assertResponseIsSuccessful();$row=$this->row($id);self::assertSame('0',$row['counted_qty']);self::assertSame(1,$row['version']);self::assertSame($this->users['manager'],$row['counted_by']);self::assertNotNull($row['counted_at']);self::assertSame('0',$row['delta']);
        self::assertSame($result,$this->write($id,[$this->input()]));self::assertSame($row,$this->row($id));
        $this->write($id,[$this->input(2,1)],'inventory-count-later');self::assertResponseIsSuccessful();$later=$this->row($id);self::assertSame('2',$later['counted_qty']);
        self::assertSame($result,$this->write($id,[$this->input()]));self::assertSame($later,$this->row($id));
        $this->write($id,[$this->input(5,0)],'inventory-stale-key');self::assertResponseStatusCodeSame(409);self::assertSame('stale_inventory_line',json_decode($this->client->getResponse()->getContent(),true)['code']);self::assertSame($later,$this->row($id));
        $this->write($id,[$this->input(4,2)]);self::assertResponseStatusCodeSame(409);self::assertSame(2,(int)$this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE action='inventory.counts'"));
        $context=json_decode($this->db->fetchOne("SELECT context FROM audit_logs WHERE action='inventory.counts' ORDER BY created_at,id LIMIT 1"),true);self::assertNull($context['lines'][0]['previous_qty']);self::assertSame(0,$context['lines'][0]['counted_qty']);
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_movements'));self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_levels'));
    }
    public function testRequestValidationAndDraftCancelledStates(): void
    {
        $this->login();$id=$this->create()['data']['id'];$this->write($id,[$this->input()]);self::assertResponseStatusCodeSame(409);$this->change($id,'start','count-state-start');self::assertResponseIsSuccessful();
        $this->client->request('POST','/api/v1/inventories/'.$id.'/lines',server:['CONTENT_TYPE'=>'application/json','HTTP_IDEMPOTENCY_KEY'=>'count-no-csrf'],content:json_encode(['lines'=>[$this->input()]]));self::assertResponseStatusCodeSame(403);
        $this->client->request('POST','/api/v1/inventories/'.$id.'/lines',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf],content:json_encode(['lines'=>[$this->input()]]));self::assertResponseStatusCodeSame(422);
        $this->write($id,array_fill(0,101,$this->input()));self::assertResponseStatusCodeSame(422);
        $this->change($id,'cancel','count-state-cancel');self::assertResponseIsSuccessful();$this->write($id,[$this->input()],'count-state-late');self::assertResponseStatusCodeSame(409);self::assertNull($this->row($id)['counted_qty']);
    }
    public function testBatchValidationIsAtomicAndOmittedLinesStayUncounted(): void
    {
        $second=$this->db->fetchOne("INSERT INTO articles (sku,name) VALUES ('SECOND','Deuxième') RETURNING id");$id=$this->started();
        $this->write($id,[$this->input(),$this->input(1,0,'11111111-1111-4111-8111-111111111111')]);self::assertResponseStatusCodeSame(422);self::assertNull($this->row($id)['counted_qty']);self::assertSame(0,$this->row($id)['version']);
        $this->write($id,[$this->input(),$this->input(1,0,strtoupper($this->article))]);self::assertResponseStatusCodeSame(422);
        $this->write($id,[$this->input()]);self::assertResponseIsSuccessful();self::assertNull($this->db->fetchOne('SELECT counted_qty FROM inventory_lines WHERE inventory_id=? AND article_id=?',[$id,$second]));
        $this->change($id,'submit','count-incomplete-submit');self::assertResponseStatusCodeSame(409);
        $this->write($id,[$this->input(3,0,$second),$this->input(4,0)],'count-stale-batch');self::assertResponseStatusCodeSame(409);self::assertNull($this->db->fetchOne('SELECT counted_qty FROM inventory_lines WHERE inventory_id=? AND article_id=?',[$id,$second]));
    }
    public function testExactDifferencesPercentagesAndMaximumInteger(): void
    {
        $this->db->insert('stock_levels',['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>3]);$id=$this->started();$this->write($id,[$this->input(2)]);self::assertResponseIsSuccessful();
        $this->client->request('GET','/api/v1/inventories/'.$id.'/lines');$page=json_decode($this->client->getResponse()->getContent(),true);self::assertSame(-1,$page['data'][0]['delta']);self::assertSame('-33.33',$page['data'][0]['delta_percent']);self::assertSame(1,$page['data'][0]['version']);self::assertTrue(static::getContainer()->get(OpenApiContract::class)->valid(json_decode(json_encode($page)),['$ref'=>'#/components/schemas/InventoryLinePage']));
        $this->write($id,[$this->input(9007199254740991,1)],'count-max-integer');self::assertResponseIsSuccessful();self::assertSame('9007199254740988',$this->row($id)['delta']);
        $this->client->request('GET','/api/v1/inventories/'.$id.'/lines');$line=json_decode($this->client->getResponse()->getContent(),true)['data'][0];self::assertSame('300239975158032933.33',$line['delta_percent']);
        self::assertSame('3',$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE article_id=?',[$this->article]));
    }
    public function testPermissionsStatesRevocationAndInvalidNumbers(): void
    {
        $id=$this->started();foreach([-1,1.0,1.5,'1',true,null,9007199254740992] as $number) {$line=$this->input();$line['counted_qty']=$number;$this->write($id,[$line]);self::assertResponseStatusCodeSame(422);}
        $this->write($id,[]);self::assertResponseStatusCodeSame(422);$line=$this->input();unset($line['expected_version']);$this->write($id,[$line]);self::assertResponseStatusCodeSame(422);
        $this->login('user');$this->write($id,[$this->input()]);self::assertResponseStatusCodeSame(403);$this->client->request('GET','/inventaires/'.$id.'/comptage/'.$this->article);self::assertResponseStatusCodeSame(403);
        $this->login('unassigned');$this->write($id,[$this->input()]);self::assertResponseStatusCodeSame(404);
        $this->login();$this->write($id,[$this->input()]);self::assertResponseIsSuccessful();$this->change($id,'submit','count-complete-submit');self::assertResponseIsSuccessful();$this->write($id,[$this->input(2,1)],'count-after-submit');self::assertResponseStatusCodeSame(409);
        $this->write($id,[$this->input()]);self::assertResponseIsSuccessful();self::assertSame('pending_validation',$this->db->fetchOne('SELECT status FROM inventories WHERE id=?',[$id]));
        $this->db->executeStatement('DELETE FROM user_stores WHERE user_id=?',[$this->users['manager']]);$this->write($id,[$this->input()]);self::assertResponseStatusCodeSame(404);
    }
    public function testFormsRetainZeroAndStaleInputAndOfferSubmissionOnlyWhenComplete(): void
    {
        $id=$this->started();$url='/inventaires/'.$id.'/comptage/'.$this->article.'?page=1&limit=1';$crawler=$this->client->request('GET',$url);self::assertResponseIsSuccessful();self::assertSelectorExists('input[name="counted_qty"][value=""]');$form=$crawler->selectButton('Enregistrer le comptage')->form(['counted_qty'=>'0']);
        $this->client->submit($form);self::assertResponseStatusCodeSame(303);$this->client->followRedirect();self::assertSelectorExists('button:contains("Soumettre à validation")');self::assertSelectorTextContains('main','Non défini');
        $crawler=$this->client->request('GET',$url);$stale=$crawler->selectButton('Enregistrer le comptage')->form(['counted_qty'=>'8']);$this->write($id,[$this->input(2,1)],'count-form-concurrent');self::assertResponseIsSuccessful();$this->client->submit($stale);self::assertResponseStatusCodeSame(409);self::assertSelectorExists('input[name="counted_qty"][value="8"]');self::assertSelectorTextContains('[role="alert"]','comptage a changé');self::assertSame('2',$this->row($id)['counted_qty']);
        $crawler=$this->client->request('GET',$url);$this->client->submit($crawler->selectButton('Enregistrer le comptage')->form(['counted_qty'=>'']));self::assertResponseStatusCodeSame(422);self::assertSame('2',$this->row($id)['counted_qty']);
    }
    public function testAuditFailureRollsBackCountsVersionAndRequest(): void
    {
        $id=$this->started();$this->db->executeStatement("CREATE FUNCTION fail_count_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.action='inventory.counts' THEN RAISE EXCEPTION 'Injected count audit failure'; END IF; RETURN NEW; END $$");$this->db->executeStatement('CREATE TRIGGER count_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION fail_count_audit()');
        try {static::getContainer()->get(InventoryWorkflow::class)->write($this->actor(),$id,(object)['lines'=>[(object)$this->input()]],'count-atomic-key');self::fail('Panne masquée');}catch(\Doctrine\DBAL\Exception $e) {self::assertStringContainsString('Injected count audit failure',$e->getMessage());}
        self::assertNull($this->row($id)['counted_qty']);self::assertSame(0,$this->row($id)['version']);self::assertSame(2,(int)$this->db->fetchOne('SELECT count(*) FROM inventory_requests'));
        $this->db->executeStatement('DROP TRIGGER count_audit_failure ON audit_logs');$this->write($id,[$this->input()],'count-atomic-key');self::assertResponseIsSuccessful();
    }
}
