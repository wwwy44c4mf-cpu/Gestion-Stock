<?php

declare(strict_types=1);
namespace App\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DatabaseException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ArticleScanTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private string $csrf;
    protected function setUp(): void
    {
        $this->client=static::createClient();$this->client->disableReboot();
        $this->db=static::getContainer()->get(Connection::class);$this->db->beginTransaction();
        $schema='scan_'.bin2hex(random_bytes(6));$this->db->executeStatement('CREATE SCHEMA '.$schema);$this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        foreach (glob(dirname(__DIR__).'/migrations/Version*.php') as $file) {
            require_once $file; $class='DoctrineMigrations\\'.basename($file,'.php');
            $migration=new $class($this->db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());
            foreach ($migration->getSql() as $sql) { $this->db->executeStatement($sql->getStatement()); }
        }
        foreach (['admin'=>1,'manager'=>2,'user'=>3,'other'=>3] as $name=>$role) {
            $this->db->insert('users',['email'=>$name.'@example.test','name'=>$name,'password_hash'=>password_hash('Scan-fixture-2026!',PASSWORD_ARGON2ID),'role_id'=>$role]);
        }
        $this->login('admin');
    }
    protected function tearDown(): void { if ($this->db->isTransactionActive()) { $this->db->rollBack(); }parent::tearDown(); }
    private function login(string $name): void
    {
        $this->client->getCookieJar()->clear();$this->client->loginUser(static::getContainer()->get(\App\Security\StockUserProvider::class)->loadUserByIdentifier($name.'@example.test'));
        $this->client->request('GET','/api/v1/auth/csrf');$this->csrf=json_decode($this->client->getResponse()->getContent(),true)['token'];
    }
    private function api(string $method,string $path,array $body): array
    {
        $this->client->request($method,$path,server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>bin2hex(random_bytes(16))],content:json_encode($body));
        return json_decode($this->client->getResponse()->getContent(),true)??[];
    }
    private function article(string $sku,array $codes=[]): array
    {
        $r=$this->api('POST','/api/v1/articles',['sku'=>$sku,'name'=>'Article '.$sku,'scan_codes'=>$codes]);self::assertResponseStatusCodeSame(201);return $r['data'];
    }
    private function resolve(string $code): array { return $this->api('POST','/api/v1/articles/resolve-code',['code'=>$code]); }
    public function testMultipleAliasesResolveExactlyWithoutWritingAnyMovement(): void
    {
        $a=$this->article('FIRST',['0012345678905','https://codes.example.test/P-1','AbC']);
        foreach ($a['scan_codes'] as $code) { self::assertSame($a['id'],$this->resolve($code)['data']['id']);self::assertResponseIsSuccessful(); }
        $this->resolve('abc');self::assertResponseStatusCodeSame(404);
        $this->resolve('12345678905');self::assertResponseStatusCodeSame(404);
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_movements'));
        self::assertArrayNotHasKey('quantity',$this->resolve('AbC')['data']);
    }
    public function testUniquenessIncludesArchivedArticlesAndChangesUseVersions(): void
    {
        $a=$this->article('FIRST',['SAME']);$b=$this->article('SECOND');
        $this->api('PATCH','/api/v1/articles/'.$a['id'],['version'=>1,'active'=>false]);self::assertResponseIsSuccessful();
        $this->api('PATCH','/api/v1/articles/'.$b['id'],['version'=>1,'scan_codes'=>['SAME']]);self::assertResponseStatusCodeSame(409);
        $this->api('PATCH','/api/v1/articles/'.$a['id'],['version'=>2,'scan_codes'=>[]]);self::assertResponseIsSuccessful();
        $next=$this->api('PATCH','/api/v1/articles/'.$b['id'],['version'=>1,'scan_codes'=>['SAME']]);self::assertSame(2,$next['data']['version']);
        $this->api('PATCH','/api/v1/articles/'.$b['id'],['version'=>1,'scan_codes'=>['NEW']]);self::assertResponseStatusCodeSame(409);
        self::assertSame($b['id'],$this->resolve('SAME')['data']['id']);
    }
    public function testInvalidCodesDuplicatesAndMissingCsrfAreRejected(): void
    {
        foreach ([[' '],['SAME',' SAME '],[123],['bad'."\n".'code'],[str_repeat('x',513)],array_map(fn($i)=>'C'.$i,range(0,50))] as $codes) {
            $this->api('POST','/api/v1/articles',['sku'=>'BAD','name'=>'Bad','scan_codes'=>$codes]);self::assertResponseStatusCodeSame(422);
        }
        $this->client->request('POST','/api/v1/articles/resolve-code',server:['CONTENT_TYPE'=>'application/json'],content:'{"code":"unknown"}');self::assertResponseStatusCodeSame(403);
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM articles'));
    }
    public function testManagerProposesCodesAndOnlyAdministratorPublishes(): void
    {
        $a=$this->article('FIRST');$this->login('manager');
        $this->api('PATCH','/api/v1/articles/'.$a['id'],['version'=>1,'scan_codes'=>['NEW']]);self::assertResponseStatusCodeSame(403);
        $p=$this->api('POST','/api/v1/article-proposals',['operation'=>'update','article_id'=>$a['id'],'base_version'=>1,'payload'=>['scan_codes'=>['NEW']]])['data'];self::assertResponseStatusCodeSame(201);
        $this->resolve('NEW');self::assertResponseStatusCodeSame(404);
        $this->login('admin');$this->api('POST','/api/v1/article-proposals/'.$p['id'].'/review',['decision'=>'approved']);self::assertResponseIsSuccessful();
        $this->login('user');self::assertSame($a['id'],$this->resolve('NEW')['data']['id']);
    }
    public function testDatabaseRegistryRollsBackDuplicateAndUnversionedChanges(): void
    {
        $a=$this->article('FIRST',['ONE']);$b=$this->article('SECOND',['TWO']);
        foreach ([['["ONE"]', $b['id'],true],['["THREE"]',$a['id'],false],['["X","X"]',$b['id'],true]] as [$codes,$id,$version]) {
            try { $this->db->transactional(fn()=> $this->db->executeStatement('UPDATE articles SET scan_codes=?::jsonb'.($version?',version=version+1':'').' WHERE id=?',[$codes,$id]));self::fail('SQL mutation must be refused'); }
            catch (DatabaseException) { self::assertTrue(true); }
        }
        self::assertSame($a['id'],$this->resolve('ONE')['data']['id']);self::assertSame($b['id'],$this->resolve('TWO')['data']['id']);
    }
    public function testFormsExposeScannerAndOnlyScannerPagesAllowCamera(): void
    {
        $crawler=$this->client->request('GET','/administration/articles/nouveau');self::assertSelectorExists('[data-scanner-mode-value="register"]');
        self::assertStringContainsString('camera=(self)',$this->client->getResponse()->headers->get('Permissions-Policy'));
        $form=$crawler->selectButton('Créer l’article')->form(['sku'=>'UI','name'=>'Article UI','scan_codes'=>"CODE-ONE\nCODE-TWO"]);$this->client->submit($form);self::assertResponseStatusCodeSame(303);
        self::assertCount(2,$this->resolve('CODE-ONE')['data']['scan_codes']);
        foreach (['/stocks/entree','/consommations/nouvelle','/consommations/retours'] as $url) {
            $this->client->request('GET',$url);self::assertResponseIsSuccessful();self::assertSelectorExists('[data-controller="scanner"]');
            self::assertStringContainsString('camera=(self)',$this->client->getResponse()->headers->get('Permissions-Policy'));
        }
        $this->client->request('GET','/articles');self::assertStringContainsString('camera=()',$this->client->getResponse()->headers->get('Permissions-Policy'));
    }
    public function testInvalidFormPreservesScannedCodeText(): void
    {
        $crawler=$this->client->request('GET','/administration/articles/nouveau');
        $this->client->submit($crawler->selectButton('Créer l’article')->form(['sku'=>'BAD-UI','name'=>'Invalid codes','scan_codes'=>"DUP\nDUP"]));
        self::assertResponseStatusCodeSame(422);self::assertSelectorTextContains('#article-scan-codes',"DUP\nDUP");
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM articles'));
    }
    public function testScannedReturnFilterKeepsOriginalBeneficiaryScopeAndArchivedArticles(): void
    {
        $a=$this->article('FIRST',['RET-1']);$b=$this->article('SECOND',['RET-2']);
        $store=$this->db->fetchOne("INSERT INTO stores(code,name) VALUES('SCAN','Stock scan') RETURNING id");
        $admin=static::getContainer()->get(\App\Security\StockUserProvider::class)->loadUserByIdentifier('admin@example.test');$engine=static::getContainer()->get(\App\Stock\StockMovementEngine::class);
        foreach ([$a,$b] as $article) {
            $engine->record($admin,'entry',(object)['article_id'=>$article['id'],'store_id'=>$store,'quantity'=>10,'reason'=>'Fixture'],bin2hex(random_bytes(16)));
            foreach (['user','other'] as $name) {
                $id=$this->db->fetchOne('SELECT id FROM users WHERE email=?',[$name.'@example.test']);
                $engine->record($admin,'consumption',(object)['article_id'=>$article['id'],'store_id'=>$store,'quantity'=>2,'consumer_id'=>$id],bin2hex(random_bytes(16)));
            }
        }
        $this->api('PATCH','/api/v1/articles/'.$a['id'],['version'=>1,'active'=>false]);self::assertResponseIsSuccessful();
        $this->login('user');self::assertFalse($this->resolve('RET-1')['data']['active']);
        $this->client->request('GET','/consommations/retours?article_id='.$a['id']);self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody','FIRST');self::assertSelectorTextNotContains('tbody','SECOND');self::assertSelectorTextNotContains('tbody','other');
        self::assertCount(1,$this->client->getCrawler()->filter('tbody tr'));
    }
}
