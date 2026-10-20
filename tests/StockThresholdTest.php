<?php

declare(strict_types=1);
namespace App\Tests;
use App\Security\StockUserProvider;
use App\Stock\StockThresholds;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
final class StockThresholdTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private array $users = [], $stores = [];
    private string $article, $inactive, $csrf;
    protected function setUp(): void
    {
        $this->client=static::createClient();$this->client->disableReboot();$this->db=static::getContainer()->get(Connection::class);$this->db->beginTransaction();
        $schema='stk03_'.bin2hex(random_bytes(6));$this->db->executeStatement('CREATE SCHEMA '.$schema);$this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        foreach(explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) { if(trim($sql)!=='') { $this->db->executeStatement($sql); } }
        $this->db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
        foreach(['20261018140000','20261018200000','20261018223000'] as $version) { require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';$class='DoctrineMigrations\\Version'.$version;$migration=new $class($this->db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) { $this->db->executeStatement($sql->getStatement()); } }
        $hash=password_hash('Entry-test-2026!',PASSWORD_ARGON2ID);
        foreach(['admin'=>1,'manager'=>2,'user'=>3,'unassigned'=>2] as $name=>$role) { $this->users[$name]=$this->db->fetchOne('INSERT INTO users (email,name,password_hash,role_id) VALUES (?,?,?,?) RETURNING id',[$name.'@example.test',$name,$hash,$role]); }
        foreach(['A','B','D'] as $code) { $this->stores[$code]=$this->db->fetchOne('INSERT INTO stores (code,name) VALUES (?,?) RETURNING id',[$code,'Magasin '.$code]); }
        $this->db->executeStatement('UPDATE stores SET active = false WHERE id = ?',[$this->stores['D']]);
        foreach(['manager','user'] as $name) { foreach(['A','D'] as $code) { $this->db->insert('user_stores',['user_id'=>$this->users[$name],'store_id'=>$this->stores[$code]]); } }
        $this->article=$this->db->fetchOne("INSERT INTO articles (sku,name) VALUES ('ENTRY','Article <script>fictif</script>') RETURNING id");$this->inactive=$this->db->fetchOne("INSERT INTO articles (sku,name,active) VALUES ('ARCHIVE','Archivé',false) RETURNING id");
    }
    protected function tearDown(): void { if($this->db->isTransactionActive()) { $this->db->rollBack(); }parent::tearDown(); }
    private function login(string $name='manager'): void
    {
        $this->client->getCookieJar()->clear();$this->client->loginUser(static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier($name.'@example.test'));
        $this->client->request('GET','/api/v1/auth/csrf');$this->csrf=json_decode($this->client->getResponse()->getContent(),true)['token'];
    }

    private function url(string $store='A',?string $article=null): string {return '/api/v1/stores/'.$this->stores[$store].'/stocks/'.($article??$this->article).'/thresholds';}
    private function patch(array $body,string $store='A',?string $article=null): array {
        $this->client->request('PATCH',$this->url($store,$article),server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf],content:json_encode((object)$body,JSON_PRESERVE_ZERO_FRACTION));
        return json_decode($this->client->getResponse()->getContent(),true)??[];
    }
    public function testIndependentThresholdsZeroNullAndNoDefaults(): void {
        $this->login('admin');$this->patch(['low_threshold'=>null]);self::assertResponseIsSuccessful();self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM stock_levels'));
        $a=$this->patch(['low_threshold'=>5,'critical_threshold'=>0]);self::assertResponseIsSuccessful();self::assertSame(0,$a['data']['quantity']);self::assertSame(0,$a['data']['critical_threshold']);
        $b=$this->patch(['low_threshold'=>12,'critical_threshold'=>4],'B');self::assertResponseIsSuccessful();self::assertSame(12,$b['data']['low_threshold']);
        $this->patch(['low_threshold'=>0]);self::assertResponseIsSuccessful();self::assertSame(2,$this->db->fetchOne('SELECT count(*) FROM stock_levels'));
        $this->patch(['low_threshold'=>null,'critical_threshold'=>null]);self::assertResponseIsSuccessful();self::assertSame(2,$this->db->fetchOne('SELECT count(*) FROM stock_levels'));
        self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM stock_movements'));self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM stock_change_events'));
    }
    public function testPreservesBalanceVersionAndAuditOnlyRealChanges(): void {
        $this->login();$this->db->insert('stock_levels',['store_id'=>$this->stores['A'],'article_id'=>$this->article,'quantity'=>13,'version'=>7]);
        $this->patch(['low_threshold'=>5,'critical_threshold'=>2]);self::assertResponseIsSuccessful();$this->patch(['low_threshold'=>5,'critical_threshold'=>2]);self::assertResponseIsSuccessful();
        self::assertSame(1,$this->db->fetchOne('SELECT count(*) FROM audit_logs'));$r=$this->patch(['low_threshold'=>6]);self::assertSame(2,$r['data']['critical_threshold']);
        self::assertSame(['quantity'=>'13','version'=>7],$this->db->fetchAssociative('SELECT quantity::text,version FROM stock_levels'));
        $this->patch(['low_threshold'=>null]);self::assertResponseStatusCodeSame(422);self::assertSame(2,$this->db->fetchOne('SELECT count(*) FROM audit_logs'));
        $context=json_decode($this->db->fetchOne('SELECT context FROM audit_logs ORDER BY created_at LIMIT 1'),true);self::assertArrayHasKey('before',$context);self::assertArrayHasKey('after',$context);
    }
    public function testInvalidThresholdsCannotWrite(): void {
        $this->login();foreach([-1,1.0,1.5,'2',true,9007199254740992] as $v) {$this->patch(['low_threshold'=>$v]);self::assertResponseStatusCodeSame(422);}
        foreach([[],['low_threshold'=>null,'critical_threshold'=>0],['low_threshold'=>1,'critical_threshold'=>2],['low_threshold'=>1,'quantity'=>4]] as $b) {$this->patch($b);self::assertResponseStatusCodeSame(422);}
        self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM stock_levels'));$this->patch(['low_threshold'=>9007199254740991]);self::assertResponseIsSuccessful();
    }
    public function testLiveAuthorizationAndInactiveArticles(): void {
        $this->login('user');$this->patch(['low_threshold'=>1]);self::assertResponseStatusCodeSame(403);
        $this->login();foreach(['B','D'] as $s) {$this->patch(['low_threshold'=>1],$s);self::assertResponseStatusCodeSame(404);}
        $this->patch(['low_threshold'=>1],article:$this->inactive);self::assertResponseIsSuccessful();
        $this->login('unassigned');$this->patch(['low_threshold'=>1]);self::assertResponseStatusCodeSame(404);
        $this->login('admin');$this->patch(['low_threshold'=>1],'D');self::assertResponseStatusCodeSame(404);
    }
    public function testFormZeroAndStaleSubmission(): void {
        $this->login();$path='/stocks/'.$this->stores['A'].'/'.$this->article.'/seuils';$crawler=$this->client->request('GET',$path);self::assertResponseIsSuccessful();
        $form=$crawler->selectButton('Enregistrer les seuils')->form(['low_threshold'=>'0','critical_threshold'=>'0']);$this->client->submit($form);self::assertResponseStatusCodeSame(303);
        $this->client->submit($form);self::assertResponseStatusCodeSame(409);self::assertSelectorTextContains('[role="alert"]','changé');
        $crawler=$this->client->request('GET',$path);self::assertSelectorExists('input[name="low_threshold"][value="0"]');$form=$crawler->selectButton('Enregistrer les seuils')->form(['low_threshold'=>'','critical_threshold'=>'']);$this->client->submit($form);self::assertResponseStatusCodeSame(303);
        $this->client->request('POST',$path,['_csrf_token'=>'bad','low_threshold'=>'1']);self::assertResponseStatusCodeSame(403);
    }
    public function testMovementDoesNotInvalidateFormAndInventoryAllowsMetadata(): void {
        $this->login();$path='/stocks/'.$this->stores['A'].'/'.$this->article.'/seuils';$c=$this->client->request('GET',$path);$form=$c->selectButton('Enregistrer les seuils')->form(['low_threshold'=>'5']);
        $this->db->insert('stock_levels',['store_id'=>$this->stores['A'],'article_id'=>$this->article,'quantity'=>10,'version'=>1]);
        $this->db->executeStatement("INSERT INTO inventories (store_id,status,created_by,snapshot_at) VALUES (?,'in_progress',?,clock_timestamp())",[$this->stores['A'],$this->users['admin']]);
        $this->client->submit($form);self::assertResponseStatusCodeSame(303);self::assertSame('10',$this->db->fetchOne('SELECT quantity::text FROM stock_levels'));
    }
    public function testAuditFailureRollsBackFirstConfiguration(): void {
        $this->db->executeStatement("CREATE FUNCTION fail_threshold_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Injected threshold audit failure'; END $$");$this->db->executeStatement('CREATE TRIGGER threshold_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION fail_threshold_audit()');
        $actor=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('admin@example.test');
        try {static::getContainer()->get(StockThresholds::class)->update($actor,$this->stores['A'],$this->article,(object)['low_threshold'=>5]);self::fail('Panne masquée');}catch(\Doctrine\DBAL\Exception $e) {self::assertStringContainsString('Injected threshold audit failure',$e->getMessage());}
        self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM stock_levels'));self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM audit_logs'));
    }
    public function testTransportGuardsAndRoleGatedLink(): void {
        $this->login();$this->client->request('PATCH',$this->url(),server:['CONTENT_TYPE'=>'application/json'],content:'{"low_threshold":1}');self::assertResponseStatusCodeSame(403);
        $this->client->request('PATCH',$this->url().'?x=1',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf],content:'{"low_threshold":1}');self::assertResponseStatusCodeSame(422);
        $this->client->request('GET',$this->url());self::assertResponseStatusCodeSame(405);
        $path='/stocks/'.$this->stores['A'].'/'.$this->article;$this->client->request('GET',$path);self::assertSelectorExists('a[href="'.$path.'/seuils"]');
        $this->login('user');$this->client->request('GET',$path);self::assertSelectorNotExists('a[href="'.$path.'/seuils"]');$this->client->request('GET',$path.'/seuils');self::assertResponseStatusCodeSame(403);
    }

}
