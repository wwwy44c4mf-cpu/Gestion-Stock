<?php

declare(strict_types=1);
namespace App\Tests;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class LocationExportTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private string $schema;
    private string $csrf;
    private string $article;
    private array $stores=[];
    protected function setUp(): void
    {
        $this->client=static::createClient();$this->client->disableReboot();
        $this->db=static::getContainer()->get(Connection::class);
        $this->schema='location_'.bin2hex(random_bytes(6));
        $this->db->executeStatement('CREATE SCHEMA '.$this->schema);
        $this->db->executeStatement('SET search_path TO '.$this->schema);
        foreach(glob(dirname(__DIR__).'/migrations/Version*.php') as $file) {
            require_once $file;$class='DoctrineMigrations\\'.basename($file,'.php');
            $m=new $class($this->db,new \Psr\Log\NullLogger());$m->up(new \Doctrine\DBAL\Schema\Schema());
            foreach($m->getSql() as $sql) {$this->db->executeStatement($sql->getStatement());}
        }
        foreach(['admin'=>1,'manager'=>2,'user'=>3] as $name=>$role) {$this->db->insert('users',['name'=>$name,'email'=>$name.'@example.test','role_id'=>$role,'password_hash'=>password_hash('Fixture-location-only!',PASSWORD_ARGON2ID)]);}
        foreach(['A','B'] as $code) {$this->stores[$code]=$this->db->fetchOne('INSERT INTO stores(code,name) VALUES(?,?) RETURNING id',[$code,'Magasin '.$code]);}
        $manager=$this->db->fetchOne("SELECT id FROM users WHERE email='manager@example.test'");
        $this->db->insert('user_stores',['user_id'=>$manager,'store_id'=>$this->stores['A']]);
        $this->article=$this->db->fetchOne("INSERT INTO articles(sku,name,description,scan_codes) VALUES('00042','Article test','=texte inoffensif','[\"00123456789\"]') RETURNING id");
        $this->login('admin');
    }
    protected function tearDown(): void
    {
        if($this->db->isTransactionActive()) {$this->db->rollBack();}
        $this->db->executeStatement('SET search_path TO public');
        $this->db->executeStatement('DROP SCHEMA '.$this->schema.' CASCADE');parent::tearDown();
    }
    private function login(string $name): void
    {
        $this->client->getCookieJar()->clear();$this->client->loginUser(static::getContainer()->get(\App\Security\StockUserProvider::class)->loadUserByIdentifier($name.'@example.test'));
        $this->client->request('GET','/api/v1/auth/csrf');$this->csrf=json_decode($this->client->getResponse()->getContent(),true)['token'];
    }
    private function location(string $store,?string $label=null,?int $version=null): array
    {
        $this->client->request($version===null?'GET':'PATCH','/api/v1/stores/'.$this->stores[$store].'/articles/'.$this->article.'/location',server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf],content:$version===null?null:json_encode(['location'=>$label,'version'=>$version]));
        return json_decode($this->client->getResponse()->getContent(),true)??[];
    }
    public function testPerStoreOptionalLocationNeverChangesStockAndDetectsLostUpdates(): void
    {
        self::assertNull($this->location('A')['data']['location']);
        self::assertSame(1,$this->location('A','Étagère A — hauteur 1',0)['data']['version']);self::assertResponseIsSuccessful();
        self::assertSame(1,$this->location('A','Étagère A — hauteur 1',0)['data']['version']);
        $this->location('A','Autre emplacement',0);self::assertResponseStatusCodeSame(409);
        self::assertSame('Étagère A — hauteur 1',$this->location('A')['data']['location']);
        self::assertNull($this->location('B')['data']['location']);
        $this->location('B','Tiroir B',0);self::assertResponseIsSuccessful();
        self::assertNull($this->location('A',' ',1)['data']['location']);
        foreach(['stock_levels','stock_movements','stock_alerts'] as $table) {self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM '.$table));}
        self::assertSame(3,(int)$this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE action='stock.location_updated'"));
        foreach([str_repeat('x',161),"A\nB"] as $bad) {$this->location('A',$bad,2);self::assertResponseStatusCodeSame(422);}
    }
    public function testTechnicianSeesLocationInUnassignedActiveStoreWithoutWriteOrStockAccess(): void
    {
        $this->location('B','Case B privée au stock',0);$this->login('user');
        $data=$this->location('B')['data'];self::assertResponseIsSuccessful();self::assertFalse($data['can_edit']);self::assertArrayNotHasKey('quantity',$data);
        $this->location('B','Interdit',1);self::assertResponseStatusCodeSame(403);
        $this->client->request('GET','/api/v1/stores/'.$this->stores['B'].'/stocks');self::assertResponseStatusCodeSame(404);
        $this->db->executeStatement('UPDATE stores SET active=false WHERE id=?',[$this->stores['B']]);
        $this->location('B');self::assertResponseStatusCodeSame(404);
    }
    public function testManagerScopeAndCsrfAreEnforced(): void
    {
        $this->login('manager');$this->location('A','Armoire 2',0);self::assertResponseIsSuccessful();
        $this->location('B','Interdit',0);self::assertResponseStatusCodeSame(404);
        $this->client->request('PATCH','/api/v1/stores/'.$this->stores['A'].'/articles/'.$this->article.'/location',server:['CONTENT_TYPE'=>'application/json'],content:'{"location":"A","version":1}');self::assertResponseStatusCodeSame(403);
    }
    public function testInitialLocationIsOptionalAndInvalidStoreRollsBackCreation(): void
    {
        $crawler=$this->client->request('GET','/administration/articles/nouveau');
        $this->client->submit($crawler->selectButton('Créer l’article')->form(['sku'=>'WITH-CASE','name'=>'Création avec casage','location_store_id'=>$this->stores['A'],'location'=>'Étagère A 1']));
        self::assertResponseStatusCodeSame(303);
        self::assertSame('Étagère A 1',$this->db->fetchOne("SELECT location FROM article_locations l JOIN articles a ON a.id=l.article_id WHERE a.sku='WITH-CASE'"));
        $crawler=$this->client->request('GET','/administration/articles/nouveau');
        $form=$crawler->selectButton('Créer l’article')->form(['sku'=>'BAD-CASE','name'=>'Création refusée','location'=>'Case invalide']);
        $values=$form->getPhpValues();$values['location_store_id']='11111111-1111-4111-8111-111111111111';
        $this->client->request('POST','/administration/articles/nouveau',$values);self::assertResponseStatusCodeSame(404);
        self::assertSame(0,(int)$this->db->fetchOne("SELECT count(*) FROM articles WHERE sku='BAD-CASE'"));
        $crawler=$this->client->request('GET','/administration/articles/nouveau');
        $this->client->submit($crawler->selectButton('Créer l’article')->form(['sku'=>'NO-CASE','name'=>'Sans casage']));self::assertResponseStatusCodeSame(303);
        self::assertSame(0,(int)$this->db->fetchOne("SELECT count(*) FROM article_locations l JOIN articles a ON a.id=l.article_id WHERE a.sku='NO-CASE'"));
    }
    public function testTechnicianFormsAreReadOnlyAndManagerCanReachScannedLocationEditor(): void
    {
        $this->login('user');
        foreach(['/consommations/nouvelle','/consommations/retours/nouveau'] as $path) {
            $this->client->request('GET',$path);self::assertResponseIsSuccessful();self::assertSelectorExists('stock-location');self::assertSelectorNotExists('[data-location-save]');
        }
        $this->client->request('GET','/casages');self::assertResponseStatusCodeSame(403);
        $this->login('manager');$this->client->request('GET','/casages');self::assertResponseIsSuccessful();self::assertSelectorExists('[data-location-save]');self::assertSelectorExists('[data-controller="scanner"]');
    }
}
