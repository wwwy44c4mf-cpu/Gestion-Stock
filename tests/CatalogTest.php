<?php

declare(strict_types=1);

namespace App\Tests;

use App\Api\OpenApiContract;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DatabaseException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CatalogTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private array $ids=[];
    private string $csrf;
    protected function setUp(): void
    {
        $this->client=static::createClient();$this->client->disableReboot();
        $this->db=static::getContainer()->get(Connection::class);$this->db->beginTransaction();
        $schema='art01_'.bin2hex(random_bytes(6));$this->db->executeStatement('CREATE SCHEMA '.$schema);$this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        foreach (explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) { if (trim($sql)!=='') { $this->db->executeStatement($sql); } }
        \App\Tests\CatalogDeletionSchema::apply($this->db);
        $this->db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
        require_once dirname(__DIR__).'/migrations/Version20261018140000.php';
        $migration=new \DoctrineMigrations\Version20261018140000($this->db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) { $this->db->executeStatement($sql->getStatement()); }
        $hash=password_hash('Catalog-test-2026!',PASSWORD_ARGON2ID);
        foreach(['admin'=>1,'manager'=>2,'user'=>3] as $name=>$role) { $this->ids[$name]=$this->db->fetchOne('INSERT INTO users (email,name,password_hash,role_id) VALUES (?,?,?,?) RETURNING id',[$name.'@example.test',ucfirst($name),$hash,$role]); }
    }
    protected function tearDown(): void { if($this->db->isTransactionActive()) { $this->db->rollBack(); }parent::tearDown(); }
    private function login(string $name='admin'): void
    {
        $this->client->getCookieJar()->clear();$this->client->loginUser(static::getContainer()->get(\App\Security\StockUserProvider::class)->loadUserByIdentifier($name.'@example.test'));
        $this->client->request('GET','/api/v1/auth/csrf');$this->csrf=json_decode($this->client->getResponse()->getContent(),true)['token'];
    }
    private function send(string $method,string $path,mixed $body=null,string $key='catalog-key-123'): array
    {
        $this->client->request($method,$path,server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>$key],content:$body===null?null:json_encode($body));
        return json_decode($this->client->getResponse()->getContent(),true)??[];
    }
    private function article(array $extra=[],string $key='article-key-123'): array { return $this->send('POST','/api/v1/articles',array_replace(['sku'=>'REF-001','name'=>'Article fictif'],$extra),$key); }
    private function category(array $extra=[],string $key='category-key-123'): array { return $this->send('POST','/api/v1/categories',array_replace(['name'=>'Fournitures'],$extra),$key); }
    private function rejectedSql(string $sql,array $parameters=[]): void
    {
        try { $this->db->transactional(fn()=> $this->db->executeStatement($sql,$parameters)); }
        catch(DatabaseException) { self::assertTrue(true);return; }
        self::fail('The database should refuse this mutation.');
    }
    public function testArticleCreationImmutableReferenceUnitAndVersionedUpdates(): void
    {
        $this->login();$category=$this->category()['data'];$result=$this->article(['sku'=>' REF-001 ','name'=>' Article fictif ','category_id'=>$category['id']]);self::assertResponseStatusCodeSame(201);
        $a=$result['data'];self::assertSame('REF-001',$a['sku']);self::assertSame('Article fictif',$a['name']);self::assertSame('unit',$a['unit_code']);self::assertSame(1,$a['version']);self::assertTrue($a['active']);
        self::assertSame('/api/v1/articles/'.$a['id'],$this->client->getResponse()->headers->get('Location'));
        self::assertTrue(static::getContainer()->get(OpenApiContract::class)->valid(json_decode(json_encode($result)),['$ref'=>'#/components/schemas/ArticleResource']));
        $next=$this->send('PATCH','/api/v1/articles/'.$a['id'],['version'=>1,'name'=>'Nouveau libellé','description'=>'Description','category_id'=>null]);self::assertResponseIsSuccessful();self::assertSame(2,$next['data']['version']);self::assertSame($a['sku'],$next['data']['sku']);self::assertNull($next['data']['category_id']);
        $this->send('PATCH','/api/v1/articles/'.$a['id'],['version'=>1,'name'=>'Écriture périmée']);self::assertResponseStatusCodeSame(409);
        $this->send('PATCH','/api/v1/articles/'.$a['id'],['version'=>2,'sku'=>'NEW']);self::assertResponseStatusCodeSame(422);
        $this->send('PATCH','/api/v1/articles/'.$a['id'],['version'=>2,'unit_code'=>'litre']);self::assertResponseStatusCodeSame(422);
        $this->rejectedSql('UPDATE articles SET sku = ? WHERE id = ?',['NEW',$a['id']]);
        $this->rejectedSql('UPDATE articles SET unit_code = ? WHERE id = ?',['litre',$a['id']]);
    }
    public function testReplaysAreDurableAndArchivedReferencesStayReserved(): void
    {
        $this->login();$first=$this->article();self::assertResponseStatusCodeSame(201);$id=$first['data']['id'];
        $this->send('PATCH','/api/v1/articles/'.$id,['version'=>1,'active'=>false]);self::assertResponseIsSuccessful();
        self::assertEquals($first,$this->article());self::assertResponseStatusCodeSame(201);
        $this->article(['name'=>'Different']);self::assertResponseStatusCodeSame(409);
        $this->article(['sku'=>' ref-001 '],'another-key-123');self::assertResponseStatusCodeSame(409);
        $cat=$this->category();self::assertResponseStatusCodeSame(201);self::assertEquals($cat,$this->category());self::assertResponseStatusCodeSame(201);
        self::assertSame(2,(int)$this->db->fetchOne('SELECT count(*) FROM catalog_creation_requests'));self::assertSame(3,(int)$this->db->fetchOne('SELECT count(*) FROM audit_logs'));
    }
    public function testInvalidBodiesNamesCategoriesAndVersionsAreRejected(): void
    {
        $this->login();
        foreach([['sku'=>' '],['name'=>' '],['sku'=>str_repeat('a',81)],['name'=>str_repeat('é',161)],['description'=>str_repeat('a',5001)],['name'=>"bad\0text"],['unit_code'=>'piece'],['active'=>'false'],['unknown'=>'x'],['category_id'=>'11111111-1111-4111-8111-111111111111']] as $bad) { $this->article($bad);self::assertResponseStatusCodeSame(422); }
        $inactive=$this->db->fetchOne("INSERT INTO categories (name,active) VALUES ('Inactive',false) RETURNING id");
        $this->article(['category_id'=>$inactive]);self::assertResponseStatusCodeSame(422);$this->category(['parent_id'=>$inactive]);self::assertResponseStatusCodeSame(422);
        $id=$this->article()['data']['id'];
        foreach([['name'=>'No version'],['version'=>1],['version'=>0,'name'=>'Zero'],['version'=>1,'active'=>null],['version'=>'1','name'=>'String'],['version'=>1,'name'=>' ']] as $bad) { $this->send('PATCH','/api/v1/articles/'.$id,$bad);self::assertResponseStatusCodeSame(422); }
        foreach(['not-a-uuid','11111111-1111-4111-8111-111111111111'] as $bad) { $this->send('PATCH','/api/v1/articles/'.$bad,['version'=>1,'name'=>'New']);self::assertResponseStatusCodeSame(404);$this->client->request('GET','/api/v1/articles/'.$bad);self::assertResponseStatusCodeSame(404); }
        self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM articles'));
    }
    public function testCategoryHierarchyPreventsCyclesAndAllowsClearingParent(): void
    {
        $this->login();$root=$this->category()['data']['id'];$child=$this->category(['name'=>'Enfant','parent_id'=>$root],'category-child-123')['data']['id'];$leaf=$this->category(['name'=>'Feuille','parent_id'=>$child],'category-leaf-123')['data']['id'];
        foreach([$root,$child,$leaf] as $parent) { $this->send('PATCH','/api/v1/categories/'.$root,['parent_id'=>$parent]);self::assertResponseStatusCodeSame(422); }
        $this->send('PATCH','/api/v1/categories/'.$child,['parent_id'=>null,'name'=>'Renommée']);self::assertResponseIsSuccessful();self::assertNull($this->db->fetchOne('SELECT parent_id FROM categories WHERE id = ?',[$child]));
        $this->send('PATCH','/api/v1/categories/'.$root,(object)[]);self::assertResponseStatusCodeSame(422);
        $this->category(['name'=>' '],'category-empty-123');self::assertResponseStatusCodeSame(422);
    }
    public function testArchivedArticleKeepsAllHistoryAndCannotBeConsumedEvenByDirectSql(): void
    {
        $this->login();$id=$this->article()['data']['id'];$store=$this->db->fetchOne("INSERT INTO stores (code,name) VALUES ('A','A') RETURNING id");$this->db->insert('stock_levels',['article_id'=>$id,'store_id'=>$store,'quantity'=>10]);
        $movement=$this->db->fetchOne("INSERT INTO stock_movements (type,article_id,source_store_id,quantity,author_id,consumer_id,idempotency_key) VALUES ('consumption',?,?,1,?,?,'old-consumption') RETURNING id",[$id,$store,$this->ids['admin'],$this->ids['user']]);
        $before=$this->db->fetchAllAssociative('SELECT * FROM stock_movements');$stocks=$this->db->fetchAllAssociative('SELECT * FROM stock_levels');
        $this->send('PATCH','/api/v1/articles/'.$id,['version'=>1,'active'=>false]);self::assertResponseIsSuccessful();
        self::assertSame($before,$this->db->fetchAllAssociative('SELECT * FROM stock_movements'));self::assertSame($stocks,$this->db->fetchAllAssociative('SELECT * FROM stock_levels'));
        $sql="INSERT INTO stock_movements (type,article_id,source_store_id,quantity,author_id,consumer_id,idempotency_key) VALUES ('consumption',?,?,1,?,?,'new-consumption')";
        $this->rejectedSql($sql,[$id,$store,$this->ids['admin'],$this->ids['user']]);$this->rejectedSql('DELETE FROM articles WHERE id = ?',[$id]);
        $this->send('DELETE','/api/v1/articles/'.$id);self::assertResponseStatusCodeSame(400);
        // An unused item can still be returned to its original store after the article is archived.
        $this->db->executeStatement("INSERT INTO stock_movements (type,article_id,destination_store_id,quantity,author_id,related_movement_id,idempotency_key) VALUES ('return',?,?,1,?,?,'return-archived')",[$id,$store,$this->ids['admin'],$movement]);
        $this->send('PATCH','/api/v1/articles/'.$id,['version'=>2,'active'=>true]);self::assertResponseIsSuccessful();$this->db->executeStatement($sql,[$id,$store,$this->ids['admin'],$this->ids['user']]);self::assertSame(3,(int)$this->db->fetchOne('SELECT count(*) FROM stock_movements'));
    }
    public function testRolesReadOnlyGlobalCatalogueAndCsrf(): void
    {
        $this->login();$id=$this->article()['data']['id'];$cat=$this->category()['data']['id'];
        foreach(['manager','user'] as $name) {
            $this->login($name);
            foreach(['/api/v1/articles','/api/v1/articles/'.$id,'/api/v1/categories','/articles','/categories','/articles/'.$id] as $path) { $this->client->request('GET',$path);self::assertResponseIsSuccessful(); }
            self::assertSelectorNotExists('a[href="/administration/articles/'.$id.'/modifier"]');
            $this->article(['sku'=>'FORBIDDEN']);self::assertResponseStatusCodeSame(403);$this->category(['name'=>'Forbidden']);self::assertResponseStatusCodeSame(403);
            $this->send('PATCH','/api/v1/articles/'.$id,['version'=>1,'active'=>false]);self::assertResponseStatusCodeSame(403);$this->send('PATCH','/api/v1/categories/'.$cat,['name'=>'Forbidden']);self::assertResponseStatusCodeSame(403);
            foreach(['/administration/articles/nouveau','/administration/articles/'.$id.'/modifier','/administration/categories/nouvelle','/administration/categories/'.$cat.'/modifier'] as $path) { $this->client->request('GET',$path);self::assertResponseStatusCodeSame(403); }
        }
        $this->login();$this->client->request('POST','/api/v1/articles',server:['CONTENT_TYPE'=>'application/json','HTTP_IDEMPOTENCY_KEY'=>'csrf-key-123'],content:'{"sku":"NO-CSRF","name":"X"}');self::assertResponseStatusCodeSame(403);
        self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM articles'));
    }
    public function testQueriesPaginationAndEscapingDoNotExposeStocks(): void
    {
        $this->login();$cat=$this->category()['data']['id'];$this->article(['name'=>'Équerre <script>test</script>','category_id'=>$cat]);$this->article(['sku'=>'REF-002','name'=>'Autre article'],'article-other-123');
        $this->client->request('GET','/api/v1/articles?q=REF&limit=1&page=2&sort=name&order=desc');self::assertResponseIsSuccessful();$page=json_decode($this->client->getResponse()->getContent(),true);self::assertSame(2,$page['meta']['total']);self::assertCount(1,$page['data']);self::assertArrayNotHasKey('quantity',$page['data'][0]);
        $this->client->request('GET','/articles?category_id='.$cat);self::assertResponseIsSuccessful();self::assertSelectorTextContains('tbody','Équerre <script>test</script>');self::assertSelectorNotExists('main script');self::assertSelectorTextNotContains('tbody','Autre article');
        foreach(['q=%20%20','sort=quantity','store_id=anything','q=%00'] as $q) { $this->client->request('GET','/api/v1/articles?'.$q);self::assertResponseStatusCodeSame(422); }
        $this->client->request('GET','/api/v1/articles?q=%25');self::assertResponseIsSuccessful();self::assertSame(0,json_decode($this->client->getResponse()->getContent(),true)['meta']['total']);
    }
    public function testFormsAndStaleFormPreserveTheOriginalVersionUntilReload(): void
    {
        $this->login();$crawler=$this->client->request('GET','/administration/categories/nouvelle');$this->client->submit($crawler->selectButton('Créer la catégorie')->form(['name'=>'Catégorie UI']));self::assertResponseStatusCodeSame(303);
        $cat=$this->db->fetchOne('SELECT id FROM categories');
        $crawler=$this->client->request('GET','/administration/articles/nouveau');$this->client->submit($crawler->selectButton('Créer l’article')->form(['sku'=>'UI-REF','name'=>'Article UI','description'=>'Description UI','category_id'=>$cat]));self::assertResponseStatusCodeSame(303);$this->client->followRedirect();self::assertResponseIsSuccessful();self::assertSelectorExists('#article-sku[disabled]');self::assertSelectorExists('nav a[aria-current="page"][href="/articles"]');
        $form=$this->client->getCrawler()->selectButton('Enregistrer les modifications')->form(['name'=>'Modification du formulaire']);$id=$this->db->fetchOne('SELECT id FROM articles');
        $this->send('PATCH','/api/v1/articles/'.$id,['version'=>1,'name'=>'Modification concurrente']);self::assertResponseIsSuccessful();
        $this->client->submit($form);self::assertResponseStatusCodeSame(409);self::assertSelectorExists('input[name="version"][value="1"]');self::assertSelectorTextContains('[role="alert"]','modifié');
        $crawler=$this->client->request('GET','/administration/articles/'.$id.'/modifier');$form=$crawler->selectButton('Enregistrer les modifications')->form(['name'=>'Modification finale']);$form['active']->untick();$this->client->submit($form);self::assertResponseStatusCodeSame(303);
        $this->client->request('GET','/articles?status=inactive');self::assertResponseIsSuccessful();self::assertSelectorTextContains('tbody','Modification finale');
        $this->client->request('POST','/administration/articles/nouveau',['sku'=>'BAD','name'=>'No CSRF']);self::assertResponseStatusCodeSame(403);
    }
    public function testCategoryStatusCascadesToWholeBranchIncludingPreviouslyArchivedArticles(): void
    {
        $this->login();$root=$this->category()['data']['id'];$child=$this->category(['name'=>'Enfant','parent_id'=>$root],'child-category-123')['data']['id'];$leaf=$this->category(['name'=>'Feuille','parent_id'=>$child],'leaf-category-123')['data']['id'];$other=$this->category(['name'=>'Autre'],'other-category-123')['data']['id'];
        $a=$this->article(['category_id'=>$root])['data']['id'];$b=$this->article(['sku'=>'LEAF','category_id'=>$leaf],'leaf-article-123')['data']['id'];$c=$this->article(['sku'=>'OLD','category_id'=>$child],'old-article-123')['data']['id'];$d=$this->article(['sku'=>'OUTSIDE','category_id'=>$other],'outside-article-123')['data']['id'];
        $this->send('PATCH','/api/v1/articles/'.$c,['version'=>1,'active'=>false]);self::assertResponseIsSuccessful();
        $before=(int)$this->db->fetchOne('SELECT count(*) FROM audit_logs');
        $this->send('PATCH','/api/v1/categories/'.$root,['active'=>false]);self::assertResponseIsSuccessful();
        foreach([$root,$child,$leaf] as $id) { self::assertFalse($this->db->fetchOne('SELECT active FROM categories WHERE id = ?',[$id])); }
        foreach([$a,$b,$c] as $id) { self::assertFalse($this->db->fetchOne('SELECT active FROM articles WHERE id = ?',[$id]));self::assertSame(2,(int)$this->db->fetchOne('SELECT version FROM articles WHERE id = ?',[$id])); }
        self::assertTrue($this->db->fetchOne('SELECT active FROM articles WHERE id = ?',[$d]));self::assertSame($before+5,(int)$this->db->fetchOne('SELECT count(*) FROM audit_logs'));
        $this->send('PATCH','/api/v1/articles/'.$a,['version'=>2,'active'=>true]);self::assertResponseStatusCodeSame(422);
        $this->send('PATCH','/api/v1/categories/'.$leaf,['active'=>true]);self::assertResponseStatusCodeSame(422);
        $this->article(['sku'=>'INACTIVE-CAT','category_id'=>$leaf],'inactive-cat-key');self::assertResponseStatusCodeSame(422);
        $this->send('PATCH','/api/v1/categories/'.$root,['active'=>true]);self::assertResponseIsSuccessful();
        foreach([$root,$child,$leaf] as $id) { self::assertTrue($this->db->fetchOne('SELECT active FROM categories WHERE id = ?',[$id])); }
        foreach([$a,$b,$c] as $id) { self::assertTrue($this->db->fetchOne('SELECT active FROM articles WHERE id = ?',[$id]));self::assertSame(3,(int)$this->db->fetchOne('SELECT version FROM articles WHERE id = ?',[$id])); }
        self::assertSame(1,(int)$this->db->fetchOne('SELECT version FROM articles WHERE id = ?',[$d]));
        $this->send('PATCH','/api/v1/articles/'.$b,['version'=>1,'name'=>'Formulaire ancien']);self::assertResponseStatusCodeSame(409);
        self::assertSame(4,(int)$this->db->fetchOne('SELECT count(*) FROM articles'));
        $cascade=$this->db->fetchAllAssociative("SELECT context FROM audit_logs WHERE jsonb_exists(context, 'cascade_from')");self::assertCount(9,$cascade);foreach($cascade as $row) { self::assertSame($root,json_decode($row['context'],true)['cascade_from']); }
    }
    public function testCategoryCascadeRollsBackCompletelyOnVersionLimit(): void
    {
        $this->login();$root=$this->category()['data']['id'];$child=$this->category(['name'=>'Enfant','parent_id'=>$root],'child-category-123')['data']['id'];$article=$this->article(['category_id'=>$child])['data']['id'];
        $this->db->executeStatement('UPDATE articles SET version = 9007199254740991 WHERE id = ?',[$article]);
        $before=(int)$this->db->fetchOne('SELECT count(*) FROM audit_logs');
        $this->send('PATCH','/api/v1/categories/'.$root,['active'=>false,'name'=>'Ne doit pas changer']);self::assertResponseStatusCodeSame(409);
        self::assertSame('Fournitures',$this->db->fetchOne('SELECT name FROM categories WHERE id = ?',[$root]));self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM categories WHERE NOT active'));self::assertTrue($this->db->fetchOne('SELECT active FROM articles WHERE id = ?',[$article]));self::assertSame($before,(int)$this->db->fetchOne('SELECT count(*) FROM audit_logs'));
    }
    public function testInactiveCreationAndInactiveBranchPreventNewAssignments(): void
    {
        $this->login();$category=$this->category(['active'=>false])['data'];self::assertResponseStatusCodeSame(201);self::assertFalse($category['active']);
        $article=$this->article(['active'=>false])['data'];self::assertResponseStatusCodeSame(201);self::assertFalse($article['active']);
        $this->article(['sku'=>'CANNOT-ASSIGN','category_id'=>$category['id']],'inactive-assignment-key');self::assertResponseStatusCodeSame(422);
        $this->send('PATCH','/api/v1/articles/'.$article['id'],['version'=>1,'category_id'=>$category['id']]);self::assertResponseStatusCodeSame(422);
        $this->send('PATCH','/api/v1/categories/'.$category['id'],['active'=>true]);self::assertResponseIsSuccessful();
        $this->send('PATCH','/api/v1/articles/'.$article['id'],['version'=>1,'active'=>true,'category_id'=>$category['id']]);self::assertResponseIsSuccessful();
    }
    public function testNoOpDoesNotBumpVersionOrCopyValuesToAudit(): void
    {
        $this->login();$id=$this->article(['description'=>'Contenu fictif à ne pas copier dans l’audit'])['data']['id'];
        $this->send('PATCH','/api/v1/articles/'.$id,['version'=>1,'name'=>'Article fictif']);self::assertResponseIsSuccessful();self::assertSame(1,(int)$this->db->fetchOne('SELECT version FROM articles'));self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM audit_logs'));
        $this->send('PATCH','/api/v1/articles/'.$id,['version'=>1,'description'=>'Nouveau texte fictif']);self::assertResponseIsSuccessful();
        $audit=implode(' ',$this->db->fetchFirstColumn('SELECT context::text FROM audit_logs'));self::assertStringNotContainsString('Contenu fictif',$audit);self::assertStringNotContainsString('Nouveau texte',$audit);
    }
}
