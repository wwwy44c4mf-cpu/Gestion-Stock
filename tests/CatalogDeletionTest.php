<?php

declare(strict_types=1);

namespace App\Tests;

use App\Administration\CatalogAdministration;
use App\Api\ApiProblem;
use App\Api\OpenApiContract;
use App\Security\StockUserProvider;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DatabaseException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CatalogDeletionTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private string $csrf;
    private array $users = [];
    private array $stores = [];

    protected function setUp(): void
    {
        $this->client = static::createClient(); $this->client->disableReboot();
        $this->db = static::getContainer()->get(Connection::class); $this->db->beginTransaction();
        $schema = 'cat02_'.bin2hex(random_bytes(6));
        $this->db->executeStatement('CREATE SCHEMA '.$schema); $this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        foreach (glob(dirname(__DIR__).'/migrations/Version*.php') as $file) {
            require_once $file; $class = 'DoctrineMigrations\\'.basename($file, '.php');
            $migration = new $class($this->db, new \Psr\Log\NullLogger()); $migration->up(new \Doctrine\DBAL\Schema\Schema());
            foreach ($migration->getSql() as $sql) { $this->db->executeStatement($sql->getStatement()); }
        }
        $hash = password_hash('Deletion-fixture-2026!', PASSWORD_ARGON2ID);
        foreach (['admin'=>1,'manager'=>2,'user'=>3] as $name=>$role) {
            $this->users[$name] = $this->db->fetchOne('INSERT INTO users (email,name,password_hash,role_id) VALUES (?,?,?,?) RETURNING id', [$name.'@example.test', $name, $hash, $role]);
        }
        foreach (['A','B'] as $code) {
            $this->stores[$code] = $this->db->fetchOne('INSERT INTO stores (code,name) VALUES (?,?) RETURNING id', [$code, 'Magasin '.$code]);
            foreach (['manager','user'] as $name) { $this->db->insert('user_stores', ['user_id'=>$this->users[$name], 'store_id'=>$this->stores[$code]]); }
        }
        $this->login();
    }
    protected function tearDown(): void { if ($this->db->isTransactionActive()) { $this->db->rollBack(); } parent::tearDown(); }
    private function login(string $name = 'admin'): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->loginUser(static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier($name.'@example.test'));
        $this->client->request('GET', '/api/v1/auth/csrf'); $this->csrf = json_decode($this->client->getResponse()->getContent(), true)['token'];
    }
    private function api(string $method, string $path, array $body): array
    {
        $this->client->request($method, $path, server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>bin2hex(random_bytes(16))], content:json_encode($body));
        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }
    private function article(string $sku = 'REMOVE-ME', ?string $category = null): array
    {
        $result = $this->api('POST', '/api/v1/articles', ['sku'=>$sku,'name'=>'Article '.$sku,'category_id'=>$category,'scan_codes'=>['CODE-'.$sku]]);
        self::assertResponseStatusCodeSame(201); return $result['data'];
    }
    private function stockedArticle(): array
    {
        $article = $this->article();
        $this->api('POST', '/api/v1/movements/entries', ['article_id'=>$article['id'],'store_id'=>$this->stores['A'],'quantity'=>4,'reason'=>'Stock initial fictif']);
        self::assertResponseStatusCodeSame(201);
        $consumption = $this->api('POST', '/api/v1/movements/consumptions', ['article_id'=>$article['id'],'store_id'=>$this->stores['A'],'quantity'=>1,'consumer_id'=>$this->users['user']]);
        self::assertResponseStatusCodeSame(201); return [$article, $consumption['data']];
    }
    public function testBothAuthorizedRolesRemoveWhilePreservingPositiveStocksAndHistory(): void
    {
        [$article] = $this->stockedArticle();
        $stocks = $this->db->fetchAllAssociative('SELECT * FROM stock_levels ORDER BY article_id,store_id');
        $history = $this->db->fetchAllAssociative('SELECT * FROM stock_movements ORDER BY id');
        foreach (['manager','admin'] as $role) {
            $this->login($role);
            $result = $this->api('DELETE', '/api/v1/articles/'.$article['id'], ['version'=>1]);
            self::assertResponseIsSuccessful(); self::assertFalse($result['data']['active']); self::assertNotNull($result['data']['deleted_at']);
            self::assertSame(2, $result['data']['version']);
            self::assertTrue(static::getContainer()->get(OpenApiContract::class)->valid(json_decode(json_encode($result)), ['$ref'=>'#/components/schemas/ArticleResource']));
        }
        self::assertSame($stocks, $this->db->fetchAllAssociative('SELECT * FROM stock_levels ORDER BY article_id,store_id'));
        self::assertSame($history, $this->db->fetchAllAssociative('SELECT * FROM stock_movements ORDER BY id'));
        self::assertSame(1, (int)$this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE action='article.deleted'"));
        self::assertSame($this->users['manager'], $this->db->fetchOne("SELECT actor_id FROM audit_logs WHERE action='article.deleted'"));
    }
    public function testAdminCanRemoveAndUnusedArticleAlsoStaysReserved(): void
    {
        $article = $this->article();
        $this->api('DELETE', '/api/v1/articles/'.$article['id'], ['version'=>1]); self::assertResponseIsSuccessful();
        self::assertSame(1, (int)$this->db->fetchOne('SELECT count(*) FROM articles'));
        $this->api('POST', '/api/v1/articles', ['sku'=>$article['sku'],'name'=>'Duplicate']); self::assertResponseStatusCodeSame(409);
        $this->api('POST', '/api/v1/articles', ['sku'=>'OTHER','name'=>'Other','scan_codes'=>$article['scan_codes']]); self::assertResponseStatusCodeSame(409);
    }
    public function testRemovedArticleDisappearsFromEveryCatalogFilterButRetainsReadableStockAndScan(): void
    {
        [$article] = $this->stockedArticle();
        $this->api('DELETE', '/api/v1/articles/'.$article['id'], ['version'=>1]); self::assertResponseIsSuccessful();
        foreach (['admin','manager','user'] as $role) {
            $this->login($role);
            foreach (['','?status=inactive','?status=active','?q=REMOVE-ME'] as $query) {
                $this->client->request('GET', '/api/v1/articles'.$query); self::assertResponseIsSuccessful();
                self::assertSame([], json_decode($this->client->getResponse()->getContent(), true)['data']);
            }
            $this->client->request('GET', '/articles'); self::assertResponseIsSuccessful(); self::assertSelectorTextNotContains('tbody', 'REMOVE-ME');
            $this->client->request('GET', '/articles/'.$article['id']); self::assertResponseIsSuccessful(); self::assertSelectorTextContains('main', 'supprimé du catalogue');
            self::assertSelectorNotExists('a[href="/administration/articles/'.$article['id'].'/modifier"]');
            self::assertSelectorNotExists('a[href="/articles/'.$article['id'].'/supprimer"]');
            $scan = $this->api('POST', '/api/v1/articles/resolve-code', ['code'=>'CODE-REMOVE-ME']); self::assertResponseIsSuccessful();
            self::assertSame($article['id'], $scan['data']['id']); self::assertFalse($scan['data']['active']);
        }
    }
    public function testOrdinaryUserCannotSeeOrInvokeRemovalAndManagerDoesNotGainEditing(): void
    {
        $article = $this->article(); $id = $article['id'];
        $this->login('user');
        $this->client->request('GET', '/articles/'.$id); self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/articles/'.$id.'/supprimer"]');
        $this->client->request('GET', '/articles/'.$id.'/supprimer'); self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/articles/'.$id.'/supprimer', ['version'=>'1','_csrf_token'=>'forged']); self::assertResponseStatusCodeSame(403);
        $this->api('DELETE', '/api/v1/articles/'.$id, ['version'=>1]); self::assertResponseStatusCodeSame(403);
        $this->login('manager');
        $this->client->request('GET', '/articles/'.$id); self::assertSelectorExists('a[href="/articles/'.$id.'/supprimer"]');
        $this->api('PATCH', '/api/v1/articles/'.$id, ['version'=>1,'name'=>'Forbidden']); self::assertResponseStatusCodeSame(403);
        self::assertNull($this->db->fetchOne('SELECT deleted_at FROM articles WHERE id=?', [$id]));
    }
    public function testConfirmationAndApiRequireCsrfAndAReadDoesNotDelete(): void
    {
        $article = $this->article(); $id = $article['id'];
        $this->login('manager');
        $crawler = $this->client->request('GET', '/articles/'.$id.'/supprimer'); self::assertResponseIsSuccessful();
        self::assertNull($this->db->fetchOne('SELECT deleted_at FROM articles WHERE id=?', [$id]));
        $form = $crawler->selectButton('Supprimer l’article')->form();
        $this->client->request('POST', '/articles/'.$id.'/supprimer', ['version'=>'1','_csrf_token'=>'forged']); self::assertResponseStatusCodeSame(403);
        $this->client->request('DELETE', '/api/v1/articles/'.$id, server:['CONTENT_TYPE'=>'application/json'], content:'{"version":1}'); self::assertResponseStatusCodeSame(403);
        $this->client->submit($form); self::assertResponseStatusCodeSame(303); $this->client->followRedirect(); self::assertResponseIsSuccessful();
        self::assertNotNull($this->db->fetchOne('SELECT deleted_at FROM articles WHERE id=?', [$id]));
    }
    public function testStaleConfirmationRequiresFreshVersionBeforeDelete(): void
    {
        $article = $this->article(); $id = $article['id'];
        $crawler = $this->client->request('GET', '/articles/'.$id.'/supprimer'); $form = $crawler->selectButton('Supprimer l’article')->form();
        $this->api('PATCH', '/api/v1/articles/'.$id, ['version'=>1,'name'=>'Changed concurrently']); self::assertResponseIsSuccessful();
        $this->client->submit($form); self::assertResponseStatusCodeSame(409); self::assertSelectorTextContains('[role="alert"]', 'modifié');
        self::assertNull($this->db->fetchOne('SELECT deleted_at FROM articles WHERE id=?', [$id]));
        $this->api('DELETE', '/api/v1/articles/'.$id, ['version'=>1]); self::assertResponseStatusCodeSame(409);
        $this->api('DELETE', '/api/v1/articles/'.$id, ['version'=>2]); self::assertResponseIsSuccessful();
    }
    public function testDeletedArticleCannotBeReactivatedByEditingCategoryOrProposal(): void
    {
        $category = $this->api('POST', '/api/v1/categories', ['name'=>'Category'])['data']['id'];
        $article = $this->article('REMOVE-ME', $category); $other = $this->article('KEEP-ME', $category);
        $this->api('DELETE', '/api/v1/articles/'.$article['id'], ['version'=>1]); self::assertResponseIsSuccessful();
        foreach ([false,true] as $active) { $this->api('PATCH', '/api/v1/categories/'.$category, ['active'=>$active]); self::assertResponseIsSuccessful(); }
        self::assertFalse($this->db->fetchOne('SELECT active FROM articles WHERE id=?', [$article['id']]));
        self::assertTrue($this->db->fetchOne('SELECT active FROM articles WHERE id=?', [$other['id']]));
        $this->api('PATCH', '/api/v1/articles/'.$article['id'], ['version'=>2,'active'=>true]); self::assertResponseStatusCodeSame(409);
        $this->client->request('GET', '/administration/articles/'.$article['id'].'/modifier'); self::assertResponseStatusCodeSame(409);
        try { $this->db->transactional(fn()=> $this->db->executeStatement('UPDATE articles SET active=true WHERE id=?', [$article['id']])); self::fail('Deleted article reactivated by SQL'); }
        catch (DatabaseException) { self::assertTrue(true); }
        $this->login('manager');
        $actor = static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('manager@example.test');
        try { static::getContainer()->get(CatalogAdministration::class)->checkArticleProposal($actor,'update',(object)['active'=>true],$article['id'],2); self::fail('Proposal accepted'); }
        catch (ApiProblem $problem) { self::assertSame('deleted_article', $problem->errorCode); }
    }
    public function testReturnsRemainPossibleAfterDeletionAndNewEntriesAndConsumptionsAreRefused(): void
    {
        [$article] = $this->stockedArticle(); $id = $article['id'];
        $this->api('DELETE', '/api/v1/articles/'.$id, ['version'=>1]); self::assertResponseIsSuccessful();
        $this->api('POST', '/api/v1/movements/entries', ['article_id'=>$id,'store_id'=>$this->stores['A'],'quantity'=>1,'reason'=>'New entry']); self::assertResponseStatusCodeSame(409);
        $this->login('user');
        $this->api('POST', '/api/v1/movements/consumptions', ['article_id'=>$id,'store_id'=>$this->stores['A'],'quantity'=>1]); self::assertResponseStatusCodeSame(409);
        $this->client->request('GET', '/consommations/retours/nouveau?article_id='.$id); self::assertResponseIsSuccessful();
        $this->api('POST', '/api/v1/movements/return-batches', ['article_id'=>$id,'store_id'=>$this->stores['B'],'quantity'=>1]); self::assertResponseStatusCodeSame(201);
        self::assertSame(3, (int)$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE article_id=? AND store_id=?', [$id,$this->stores['A']]));
        self::assertSame(1, (int)$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE article_id=? AND store_id=?', [$id,$this->stores['B']]));
        self::assertSame(0, (int)$this->db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference<>0'));
    }
    public function testInvalidVersionsFieldsMissingIdsAndVersionLimitDoNotDelete(): void
    {
        $article = $this->article(); $id = $article['id'];
        foreach ([['version'=>0],['version'=>'1'],['version'=>1,'name'=>'Extra']] as $body) { $this->api('DELETE','/api/v1/articles/'.$id,$body); self::assertResponseStatusCodeSame(422); }
        $this->api('DELETE','/api/v1/articles/not-a-uuid',['version'=>1]); self::assertResponseStatusCodeSame(404);
        $this->db->executeStatement('UPDATE articles SET version=9007199254740991 WHERE id=?',[$id]);
        $this->api('DELETE','/api/v1/articles/'.$id,['version'=>9007199254740991]); self::assertResponseStatusCodeSame(409);
        self::assertNull($this->db->fetchOne('SELECT deleted_at FROM articles WHERE id=?',[$id]));
    }
    public function testLiveRoleRevocationIsRecheckedInsideService(): void
    {
        $article = $this->article();
        $actor = static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('manager@example.test');
        $this->db->executeStatement('UPDATE users SET role_id=3 WHERE id=?',[$this->users['manager']]);
        try { static::getContainer()->get(CatalogAdministration::class)->removeArticle($actor,$article['id'],1); self::fail('Stale role accepted'); }
        catch (ApiProblem $problem) { self::assertSame(401,$problem->status); }
        self::assertNull($this->db->fetchOne('SELECT deleted_at FROM articles WHERE id=?',[$article['id']]));
    }
}
