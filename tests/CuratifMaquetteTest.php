<?php

declare(strict_types=1);
namespace App\Tests;
use App\Api\ApiProblem;
use App\Curatif\{StockExtensions,ToolLending,CableReels};
use App\Security\{StockUser,StockUserProvider};
use App\Stock\StockMovementEngine;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
final class CuratifMaquetteTest extends WebTestCase
{
    private KernelBrowser $client;private Connection $db;private array $actors=[];private array $stores=[];private string $article;private string $cable;
    protected function setUp(): void
    {
        $this->client=static::createClient();$this->client->disableReboot();$this->db=static::getContainer()->get(Connection::class);$this->db->beginTransaction();
        $schema='curatif_'.bin2hex(random_bytes(5));$this->db->executeStatement('CREATE SCHEMA '.$schema);$this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        foreach(glob(dirname(__DIR__).'/migrations/Version*.php') as $file) {require_once $file;$class='DoctrineMigrations\\'.basename($file,'.php');$m=new $class($this->db,new \Psr\Log\NullLogger());$m->up(new \Doctrine\DBAL\Schema\Schema());foreach($m->getSql() as $sql) {$this->db->executeStatement($sql->getStatement());}}
        $this->db->executeStatement("CREATE TABLE messenger_messages(id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,body text NOT NULL,headers text NOT NULL DEFAULT '{}',queue_name text NOT NULL DEFAULT 'notifications',created_at timestamp NOT NULL DEFAULT clock_timestamp(),available_at timestamp NOT NULL DEFAULT clock_timestamp(),delivered_at timestamp)");
        foreach(['admin'=>1,'manager'=>2,'user'=>3,'other'=>3] as $name=>$role) {$this->db->insert('users',['name'=>$name,'email'=>$name.'@example.test','role_id'=>$role,'password_hash'=>password_hash('Fictitious-CA-2026!',PASSWORD_ARGON2ID)]);$this->actors[$name]=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier($name.'@example.test');}
        foreach(['A','B'] as $code) {$this->stores[$code]=$this->db->fetchOne('INSERT INTO stores(code,name) VALUES(?,?) RETURNING id',[$code,'Magasin '.$code]);}
        $this->db->insert('user_stores',['user_id'=>$this->actors['manager']->id,'store_id'=>$this->stores['A']]);$this->db->insert('user_stores',['user_id'=>$this->actors['user']->id,'store_id'=>$this->stores['A']]);$this->db->insert('user_stores',['user_id'=>$this->actors['other']->id,'store_id'=>$this->stores['A']]);
        $this->article=$this->db->fetchOne("INSERT INTO articles(sku,name) VALUES('NORMAL','Article courant') RETURNING id");
        $cat=$this->db->fetchOne("INSERT INTO categories(name,tracks_reels) VALUES('Touret',true) RETURNING id");$this->cable=$this->db->fetchOne("INSERT INTO articles(sku,name,category_id) VALUES('CABLE','Câble',?) RETURNING id",[$cat]);
        $this->move('entry',5);$this->client->loginUser($this->actors['admin']);
    }
    protected function tearDown(): void {if($this->db->isTransactionActive()) {$this->db->rollBack();}parent::tearDown();}
    private function move(string $type,int $quantity): array {return static::getContainer()->get(StockMovementEngine::class)->record($this->actors['admin'],$type,(object)['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>$quantity,'reason'=>'Fictif'],bin2hex(random_bytes(16)));}
    private function denied(callable $f,int $status): void {try {$f();self::fail('Refus attendu');}catch(ApiProblem $e) {self::assertSame($status,$e->status,$e->getMessage());}}
    public function testManagerCreatesArticleDirectlyButCannotEditOrCreateCategory(): void
    {
        $this->client->loginUser($this->actors['manager']);$crawler=$this->client->request('GET','/administration/articles/nouveau');self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Créer l’article')->form(['sku'=>'MANAGER-CREATE','name'=>'Création directe']));self::assertResponseStatusCodeSame(303);self::assertStringContainsString('/articles/',$this->client->getResponse()->headers->get('Location'));
        $id=$this->db->fetchOne("SELECT id FROM articles WHERE sku='MANAGER-CREATE'");self::assertNotFalse($id);self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM article_proposals'));
        $this->client->request('GET','/administration/articles/'.$id.'/modifier');self::assertResponseStatusCodeSame(403);$this->client->request('GET','/administration/categories/nouvelle');self::assertResponseStatusCodeSame(403);
    }
    public function testUnlinkedReturnCreditsAssignedStoreWithoutInventedConsumptionAndReplaysOnce(): void
    {
        $this->db->insert('user_stores',['user_id'=>$this->actors['user']->id,'store_id'=>$this->stores['B']]);
        $s=static::getContainer()->get(StockExtensions::class);$key=bin2hex(random_bytes(16));$id=$s->deposit($this->actors['user'],$this->stores['B'],$this->article,3,$key);
        self::assertSame($id,$s->deposit($this->actors['user'],$this->stores['B'],$this->article,3,$key));self::assertSame(3,(int)$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE store_id=? AND article_id=?',[$this->stores['B'],$this->article]));
        self::assertSame(0,(int)$this->db->fetchOne("SELECT count(*) FROM stock_movements WHERE type='consumption'"));self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM unlinked_returns'));
        $this->denied(fn()=> $s->deposit($this->actors['user'],$this->stores['B'],$this->article,4,$key),409);$this->denied(fn()=> $s->deposit($this->actors['manager'],$this->stores['B'],$this->article,1,bin2hex(random_bytes(16))),404);
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference<>0'));
    }
    public function testOrderSignalIsReadOnlyForUserAndClearsOnlyWithActualPositiveRestocking(): void
    {
        $s=static::getContainer()->get(StockExtensions::class);$s->order($this->actors['manager'],$this->stores['A'],$this->article,true);
        $this->denied(fn()=> $s->order($this->actors['user'],$this->stores['A'],$this->article,false),403);$this->denied(fn()=> $s->order($this->actors['manager'],$this->stores['B'],$this->article,true),404);
        $this->move('adjustment',-1);self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM stock_orders'));$this->move('entry',1);self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_orders'));
        $s->order($this->actors['manager'],$this->stores['A'],$this->article,true);$this->move('adjustment',1);self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_orders'));
    }
    public function testToolCopiesCannotBeDoubleBorrowedAndReturnRequiresOriginalStoreAndOwnLoan(): void
    {
        $s=static::getContainer()->get(ToolLending::class);$s->add($this->actors['manager'],$this->stores['A'],'Scie à métaux','SCIE',2);$items=$s->items($this->actors['user'],$this->stores['A']);self::assertCount(2,$items);
        $key=bin2hex(random_bytes(16));$s->borrow($this->actors['user'],$this->stores['A'],[$items[0]['id']],$key);$s->borrow($this->actors['user'],$this->stores['A'],[$items[0]['id']],$key);self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM tool_loans'));
        $this->denied(fn()=> $s->borrow($this->actors['other'],$this->stores['A'],[$items[0]['id'],$items[1]['id']],bin2hex(random_bytes(16))),409);self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM tool_loans'));
        $loan=$this->db->fetchOne('SELECT id FROM tool_loans');$this->denied(fn()=> $s->giveBack($this->actors['other'],$this->stores['A'],$items[0]['id'],$loan),403);$this->denied(fn()=> $s->giveBack($this->actors['user'],$this->stores['B'],$items[0]['id'],$loan),404);$this->denied(fn()=> $s->remove($this->actors['manager'],$this->stores['A'],$items[0]['id']),409);
        $s->giveBack($this->actors['user'],$this->stores['A'],$items[0]['id'],$loan);$s->borrow($this->actors['user'],$this->stores['A'],[$items[0]['id']],bin2hex(random_bytes(16)));$this->denied(fn()=> $s->giveBack($this->actors['user'],$this->stores['A'],$items[0]['id'],$loan),409);
    }
    public function testOldInactiveBorrowerIsKeptUntilTheirToolsAreReturned(): void
    {
        $s=static::getContainer()->get(ToolLending::class);$s->add($this->actors['manager'],$this->stores['A'],'Marteau','MARTEAU',1);$item=$s->items($this->actors['user'],$this->stores['A'])[0];
        $s->borrow($this->actors['user'],$this->stores['A'],[$item['id']],bin2hex(random_bytes(16)));$loan=$this->db->fetchOne('SELECT id FROM tool_loans');
        $this->db->executeStatement('ALTER TABLE users DISABLE TRIGGER users_deactivation_clock');
        $this->db->executeStatement("UPDATE users SET active=false,deactivated_at=transaction_timestamp()-interval '2 years' WHERE id=?",[$this->actors['user']->id]);
        $this->db->executeStatement('ALTER TABLE users ENABLE TRIGGER users_deactivation_clock');
        $this->db->executeStatement("UPDATE tool_loans SET borrowed_at=transaction_timestamp()-interval '2 years' WHERE id=?",[$loan]);
        self::assertFalse((bool)$this->db->fetchOne('SELECT privacy_account_due(?)',[$this->actors['user']->id]));
        $s->giveBack($this->actors['manager'],$this->stores['A'],$item['id'],$loan);
        self::assertNull($this->db->fetchOne('SELECT email FROM users WHERE id=?',[$this->actors['user']->id]));
        self::assertSame('00000000-0000-4000-8000-000000000001',$this->db->fetchOne('SELECT borrower_id FROM tool_loans WHERE id=?',[$loan]));
    }
    public function testNewConsumptionScreensResolveBeforeDynamicMovementRoute(): void
    {
        $this->client->loginUser($this->actors['user']);
        foreach(['/consommations/outillage-ca','/consommations/tourets'] as $path) {$this->client->request('GET',$path,['store_id'=>$this->stores['A']]);self::assertResponseIsSuccessful();}
    }
    public function testTechnicianSubmitsUnlinkedReturnFormAndRepeatDoesNotDuplicateIt(): void
    {
        $this->db->insert('user_stores',['user_id'=>$this->actors['user']->id,'store_id'=>$this->stores['B']]);
        $this->client->loginUser($this->actors['user']);$crawler=$this->client->request('GET','/consommations/retours/nouveau');
        $form=$crawler->selectButton('Enregistrer le retour')->form(['article_id'=>$this->article,'store_id'=>$this->stores['B'],'quantity'=>'2']);
        $this->client->submit($form);self::assertResponseStatusCodeSame(303);$this->client->submit($form);self::assertResponseStatusCodeSame(303);
        self::assertSame(2,(int)$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE article_id=? AND store_id=?',[$this->article,$this->stores['B']]));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM unlinked_returns'));
    }
    public function testStoreCannotCloseWithAnOutstandingToolLoan(): void
    {
        $s=static::getContainer()->get(ToolLending::class);$s->add($this->actors['manager'],$this->stores['A'],'Clé','CLE',1);$item=$s->items($this->actors['user'],$this->stores['A'])[0];
        $s->borrow($this->actors['user'],$this->stores['A'],[$item['id']],bin2hex(random_bytes(16)));
        $admin=static::getContainer()->get(\App\Administration\StoreAdministration::class);
        $this->denied(fn()=> $admin->update($this->actors['admin'],$this->stores['A'],(object)['active'=>false]),409);
        self::assertTrue((bool)$this->db->fetchOne('SELECT active FROM stores WHERE id=?',[$this->stores['A']]));
    }
    public function testDefinitiveDeletionIncludesNewReelAndUnlinkedReturnHistory(): void
    {
        $reels=static::getContainer()->get(CableReels::class);$id=$reels->receive($this->actors['manager'],$this->stores['A'],$this->cable,'PURGE-REEL',500,bin2hex(random_bytes(16)));$reels->useLength($this->actors['user'],$this->stores['A'],$id,100,bin2hex(random_bytes(16)));
        $extension=static::getContainer()->get(StockExtensions::class);$extension->deposit($this->actors['user'],$this->stores['A'],$this->article,2,bin2hex(random_bytes(16)));$extension->order($this->actors['manager'],$this->stores['A'],$this->article,true);
        $purge=static::getContainer()->get(\App\Administration\ArticlePurge::class);
        foreach([[$this->cable,'CABLE'],[$this->article,'NORMAL']] as [$article,$sku]) {$preview=$purge->preview($this->actors['manager'],$article);$purge->purge($this->actors['manager'],$article,$preview['token'],$preview['at'],$sku,true);}
        foreach(['cable_reels','cable_reel_movements','ca_requests','unlinked_returns','stock_orders','stock_movements','articles'] as $table) {self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM '.$table),$table);}
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference<>0'));
    }
    public function testExistingStockCannotBeReclassifiedAsReels(): void
    {
        $cat=$this->db->fetchOne('SELECT category_id FROM articles WHERE id=?',[$this->cable]);$admin=static::getContainer()->get(\App\Administration\CatalogAdministration::class);
        $this->denied(fn()=> $admin->update($this->actors['admin'],'article',$this->article,(object)['version'=>1,'category_id'=>$cat]),409);
        self::assertNull($this->db->fetchOne('SELECT category_id FROM articles WHERE id=?',[$this->article]));
    }
    public function testGroupedToolReturnFormReturnsAllOwnToolsOnlyAndRejectsStaleLists(): void
    {
        $this->db->insert('user_stores',['user_id'=>$this->actors['user']->id,'store_id'=>$this->stores['B']]);
        $s=static::getContainer()->get(ToolLending::class);$s->add($this->actors['manager'],$this->stores['A'],'Scie','SCIE-GROUPE',2);$items=$s->items($this->actors['user'],$this->stores['A']);
        $s->borrow($this->actors['user'],$this->stores['A'],array_column($items,'id'),bin2hex(random_bytes(16)));
        $loans=$this->db->fetchFirstColumn('SELECT id FROM tool_loans WHERE returned_at IS NULL');
        $this->denied(fn()=> $s->giveBackAll($this->actors['other'],$this->stores['A'],$this->actors['user']->id,$loans),403);
        $this->denied(fn()=> $s->giveBackAll($this->actors['user'],$this->stores['A'],$this->actors['user']->id,[$loans[0]]),409);
        self::assertSame(2,(int)$this->db->fetchOne('SELECT count(*) FROM tool_loans WHERE returned_at IS NULL'));
        $s->add($this->actors['admin'],$this->stores['B'],'Pince','PINCE-B',1);$other=$s->items($this->actors['user'],$this->stores['B'])[0];$s->borrow($this->actors['user'],$this->stores['B'],[$other['id']],bin2hex(random_bytes(16)));
        $this->client->loginUser($this->actors['user']);$crawler=$this->client->request('GET','/consommations/outillage-ca',['store_id'=>$this->stores['A']]);
        self::assertCount(1,$crawler->selectButton('Rendre les outils'));self::assertSelectorNotExists('button[value="return"]');
        $form=$crawler->selectButton('Rendre les outils')->form();$this->client->submit($form);self::assertResponseStatusCodeSame(303);$this->client->submit($form);self::assertResponseStatusCodeSame(303);
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM tool_loans WHERE store_id=? AND returned_at IS NULL',[$this->stores['A']]));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM tool_loans WHERE store_id=? AND returned_at IS NULL',[$this->stores['B']]));
        $s->borrow($this->actors['user'],$this->stores['A'],[$items[0]['id']],bin2hex(random_bytes(16)));
        $this->denied(fn()=> $s->giveBackAll($this->actors['user'],$this->stores['A'],$this->actors['user']->id,$loans),409);
        self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM tool_loans WHERE store_id=? AND returned_at IS NULL',[$this->stores['A']]));
    }
    public function testUnifiedReturnSplitsKnownAndUnknownQuantitiesAndReplaysOnce(): void
    {
        $this->db->insert('user_stores',['user_id'=>$this->actors['user']->id,'store_id'=>$this->stores['B']]);
        $stock=static::getContainer()->get(StockMovementEngine::class);$stock->record($this->actors['user'],'consumption',(object)['store_id'=>$this->stores['A'],'article_id'=>$this->article,'quantity'=>2],bin2hex(random_bytes(16)));
        $service=static::getContainer()->get(\App\Curatif\UnifiedReturns::class);$key=bin2hex(random_bytes(16));$body=(object)['store_id'=>$this->stores['B'],'article_id'=>$this->article,'quantity'=>3,'comment'=>'Retour mélangé'];
        $result=$service->record($this->actors['user'],$body,$key);self::assertSame(2,$result['linked_quantity']);self::assertSame(1,$result['unlinked_quantity']);self::assertSame($result,$service->record($this->actors['user'],$body,$key));
        self::assertSame(3,(int)$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE store_id=? AND article_id=?',[$this->stores['B'],$this->article]));
        self::assertSame('Retour mélangé',$this->db->fetchOne('SELECT comment FROM stock_movements WHERE id=?',[$result['movement_id']]));
        $this->db->executeStatement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->client->loginUser($this->actors['user']);$this->client->request('GET','/consommations/retours/confirmation/'.$key);self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','3 unité(s)');
        $this->client->loginUser($this->actors['other']);$this->client->request('GET','/consommations/retours/confirmation/'.$key);self::assertResponseStatusCodeSame(404);
        $this->denied(fn()=> $service->record($this->actors['user'],(object)['store_id'=>$this->stores['B'],'article_id'=>$this->article,'quantity'=>4],$key),409);
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference<>0'));
    }
    public function testUnifiedReturnRollsBackLinkedPartIfUnlinkedRemainderCannotFit(): void
    {
        $this->db->insert('user_stores',['user_id'=>$this->actors['user']->id,'store_id'=>$this->stores['B']]);
        $stock=static::getContainer()->get(StockMovementEngine::class);$stock->record($this->actors['user'],'consumption',(object)['store_id'=>$this->stores['A'],'article_id'=>$this->article,'quantity'=>1],bin2hex(random_bytes(16)));
        $stock->record($this->actors['admin'],'entry',(object)['store_id'=>$this->stores['B'],'article_id'=>$this->article,'quantity'=>9007199254740990,'reason'=>'Capacité fictive'],bin2hex(random_bytes(16)));
        $service=static::getContainer()->get(\App\Curatif\UnifiedReturns::class);
        $this->denied(fn()=> $service->record($this->actors['user'],(object)['store_id'=>$this->stores['B'],'article_id'=>$this->article,'quantity'=>3],bin2hex(random_bytes(16))),422);
        self::assertSame(9007199254740990,(int)$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE store_id=? AND article_id=?',[$this->stores['B'],$this->article]));
        foreach(['stock_return_groups','unlinked_returns','ca_requests'] as $t) {self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM '.$t));}
        self::assertSame(0,(int)$this->db->fetchOne("SELECT count(*) FROM stock_movements WHERE type='return'"));
    }
    public function testEachReelHasIndependentIntegerLengthAndExhaustionReconcilesStock(): void
    {
        $s=static::getContainer()->get(CableReels::class);$a=$s->receive($this->actors['manager'],$this->stores['A'],$this->cable,'T-001',500,bin2hex(random_bytes(16)));$b=$s->receive($this->actors['manager'],$this->stores['A'],$this->cable,'T-002',300,bin2hex(random_bytes(16)));
        $key=bin2hex(random_bytes(16));$s->useLength($this->actors['user'],$this->stores['A'],$a,100,$key);$s->useLength($this->actors['user'],$this->stores['A'],$a,100,$key);
        self::assertSame(400,(int)$this->db->fetchOne('SELECT remaining_length FROM cable_reels WHERE id=?',[$a]));self::assertSame(300,(int)$this->db->fetchOne('SELECT remaining_length FROM cable_reels WHERE id=?',[$b]));
        $this->denied(fn()=> $s->useLength($this->actors['user'],$this->stores['A'],$a,401,bin2hex(random_bytes(16))),409);
        $s->useLength($this->actors['user'],$this->stores['A'],$a,400,bin2hex(random_bytes(16)));self::assertSame(1,(int)$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE store_id=? AND article_id=?',[$this->stores['A'],$this->cable]));self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference<>0'));
    }
    public function testSelectedStoreFiltersCatalogueAndStocksButKeepsEstablishedZeroRows(): void
    {
        $onlyB=$this->db->fetchOne("INSERT INTO articles(sku,name) VALUES('ONLY-B','Seulement magasin B') RETURNING id");
        static::getContainer()->get(StockMovementEngine::class)->record($this->actors['admin'],'entry',(object)['article_id'=>$onlyB,'store_id'=>$this->stores['B'],'quantity'=>2,'reason'=>'Fictif'],bin2hex(random_bytes(16)));
        $location=$this->db->fetchOne("INSERT INTO articles(sku,name) VALUES('CASE-A','Casage seul') RETURNING id");
        static::getContainer()->get(\App\Location\ArticleLocations::class)->save($this->actors['admin'],$this->stores['A'],$location,'Étagère A',0);
        static::getContainer()->get(StockMovementEngine::class)->record($this->actors['user'],'consumption',(object)['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>5],bin2hex(random_bytes(16)));
        $this->db->executeStatement('UPDATE users SET favorite_store_id=? WHERE id=?',[$this->stores['A'],$this->actors['user']->id]);
        $this->client->loginUser(static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('user@example.test'));
        $this->client->request('GET','/api/v1/articles?store_id='.$this->stores['A'].'&limit=1');self::assertResponseIsSuccessful();$data=json_decode($this->client->getResponse()->getContent(),true);self::assertSame(2,$data['meta']['total']);self::assertCount(1,$data['data']);
        $this->client->request('GET','/api/v1/stores/'.$this->stores['A'].'/stocks');self::assertResponseIsSuccessful();$data=json_decode($this->client->getResponse()->getContent(),true);self::assertCount(2,$data['data']);foreach($data['data'] as $row) {self::assertSame(0,$row['quantity']);}
        $this->client->request('GET','/articles');self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','NORMAL');self::assertSelectorTextContains('main','CASE-A');self::assertStringNotContainsString('ONLY-B',$this->client->getResponse()->getContent());
        $this->client->request('GET','/api/v1/articles?store_id='.$this->stores['B']);self::assertResponseStatusCodeSame(404);
    }
    public function testNewArticleCanBelongToChosenStoreWithoutMandatoryLocationOrFakeEntry(): void
    {
        $this->client->loginUser($this->actors['manager']);$before=(int)$this->db->fetchOne('SELECT count(*) FROM stock_movements');
        $crawler=$this->client->request('GET','/administration/articles/nouveau');
        $form=$crawler->selectButton('Créer l’article')->form(['sku'=>'NEW-A','name'=>'Article du magasin A','location_store_id'=>$this->stores['A'],'location'=>'']);
        $this->client->submit($form);self::assertResponseStatusCodeSame(303);$id=$this->db->fetchOne("SELECT id FROM articles WHERE sku='NEW-A'");self::assertNotFalse($id);
        self::assertSame(0,(int)$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE article_id=? AND store_id=?',[$id,$this->stores['A']]));self::assertSame($before,(int)$this->db->fetchOne('SELECT count(*) FROM stock_movements'));
        $crawler=$this->client->request('GET','/administration/articles/nouveau');$values=$crawler->selectButton('Créer l’article')->form(['sku'=>'NO-B','name'=>'Magasin interdit'])->getPhpValues();$values['location_store_id']=$this->stores['B'];
        $this->client->request('POST','/administration/articles/nouveau',$values);self::assertResponseStatusCodeSame(404);self::assertFalse($this->db->fetchOne("SELECT 1 FROM articles WHERE sku='NO-B'"));
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference<>0'));
    }
    public function testTechnicianSelectorsAndDirectWritesAreRestrictedToAssignedStores(): void
    {
        $directory=static::getContainer()->get(\App\Access\AccessDirectory::class);$policy=static::getContainer()->get(\App\Security\AccessPolicy::class);
        foreach([\App\Security\Permission::CONSUMPTION_CREATE,\App\Security\Permission::RETURN_CREATE,\App\Security\Permission::LOCATION_READ] as $permission) {
            self::assertSame([$this->stores['A']],array_column($directory->storeOptions($this->actors['user'],$permission),'id'));
            $this->denied(fn()=> $policy->assert($this->actors['user'],$permission,\App\Security\AccessTarget::store($this->stores['B'])),404);
            self::assertCount(2,$directory->storeOptions($this->actors['admin'],$permission));
        }
        $this->db->executeStatement('DELETE FROM user_stores WHERE user_id=?',[$this->actors['user']->id]);self::assertSame([],$directory->storeOptions($this->actors['user'],\App\Security\Permission::CONSUMPTION_CREATE));
        $this->client->loginUser($this->actors['user']);$this->client->request('GET','/consommations/nouvelle');self::assertResponseIsSuccessful();self::assertSelectorNotExists('#consumption-store option[value="'.$this->stores['A'].'"]');self::assertSelectorNotExists('#magasin option[value="'.$this->stores['B'].'"]');self::assertSelectorNotExists('[data-ca-operation]:not([hidden])');
    }
    public function testCuratifTabsFollowStoreConfigurationAndCanBeDisabledForConso(): void
    {
        $stores=static::getContainer()->get(\App\Administration\StoreAdministration::class);
        $stores->update($this->actors['admin'],$this->stores['A'],(object)['ca_enabled'=>true]);$stores->update($this->actors['admin'],$this->stores['B'],(object)['ca_enabled'=>false]);
        $context=static::getContainer()->get(\App\Ui\StoreContext::class);$this->client->loginUser($this->actors['admin']);
        foreach(['A'=>true,'B'=>false] as $code=>$visible) {
            $this->client->request('GET','/articles');$request=$this->client->getRequest();$context->select($request,$this->actors['admin'],$this->stores[$code]);$request->getSession()->save();
            foreach(['/consommations','/consommations/nouvelle','/consommations/retours/nouveau'] as $path) {
                $this->client->request('GET',$path);self::assertResponseIsSuccessful();$crawler=$this->client->getCrawler();self::assertSame($visible?1:0,$crawler->filter('a[href="/consommations/outillage-ca"]:not([hidden])')->count(),$path);self::assertSame($visible?1:0,$crawler->filter('a[href="/consommations/tourets"]:not([hidden])')->count(),$path);
            }
        }
    }
    public function testMovementFormsUseStoreArticlesButEntryAndReturnKeepWholeCatalogue(): void
    {
        $foreign=$this->db->fetchOne("INSERT INTO articles(sku,name) VALUES('FOREIGN','Autre magasin') RETURNING id");
        $this->db->insert('stock_levels',['article_id'=>$foreign,'store_id'=>$this->stores['B'],'quantity'=>0]);
        $zero=$this->db->fetchOne("INSERT INTO articles(sku,name,active) VALUES('ZERO-INACTIVE','Épuisé désactivé',false) RETURNING id");
        $this->db->insert('stock_levels',['article_id'=>$zero,'store_id'=>$this->stores['A'],'quantity'=>0]);
        foreach(['/consommations/nouvelle?store_id=','/stocks/ajustement?store_id=','/stocks/transfert?source_store_id='] as $url) {
            $this->client->request('GET',$url.$this->stores['A']);self::assertResponseIsSuccessful();
            self::assertSelectorExists('select[name=article_id] option[value="'.$this->article.'"]');
            self::assertSelectorNotExists('select[name=article_id] option[value="'.$foreign.'"]');
        }
        $crawler=$this->client->request('GET','/stocks/ajustement?store_id='.$this->stores['A']);
        self::assertSelectorExists('select[name=article_id] option[value="'.$zero.'"]');
        $values=$crawler->selectButton('Enregistrer l’ajustement')->form(['article_id'=>$this->article,'quantity'=>'1','reason'=>'Essai'])->getPhpValues();
        $before=(int)$this->db->fetchOne('SELECT count(*) FROM stock_movements');$values['article_id']=$foreign;
        $this->client->request('POST','/stocks/ajustement',$values);self::assertResponseStatusCodeSame(422);
        self::assertSame($before,(int)$this->db->fetchOne('SELECT count(*) FROM stock_movements'));
        self::assertFalse($this->db->fetchOne('SELECT 1 FROM stock_levels WHERE article_id=? AND store_id=?',[$foreign,$this->stores['A']]));
        foreach(['/stocks/entree','/consommations/retours/nouveau'] as $url) {
            $this->client->request('GET',$url);self::assertResponseIsSuccessful();
            self::assertSelectorExists('select[name=article_id] option[value="'.$foreign.'"]');
        }
    }
    public function testLocalWithdrawalKeepsOtherStoreCatalogueHistoryAndRestoresOnReceipt(): void
    {
        $engine=static::getContainer()->get(StockMovementEngine::class);
        $engine->record($this->actors['admin'],'entry',(object)['article_id'=>$this->article,'store_id'=>$this->stores['B'],'quantity'=>2,'reason'=>'Autre magasin'],bin2hex(random_bytes(16)));
        $this->db->executeStatement('UPDATE stock_levels SET low_threshold=2,critical_threshold=0 WHERE article_id=? AND store_id=?',[$this->article,$this->stores['A']]);
        $this->move('adjustment',-5);$before=(int)$this->db->fetchOne('SELECT count(*) FROM stock_movements');
        self::assertSame(1,(int)$this->db->fetchOne("SELECT count(*) FROM stock_alerts WHERE store_id=? AND status='active'",[$this->stores['A']]));
        $this->client->loginUser($this->actors['manager']);$url='/stocks/'.$this->stores['A'].'/'.$this->article.'/retirer';
        $crawler=$this->client->request('GET',$url);self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','les autres magasins');
        $form=$crawler->selectButton('Confirmer le retrait du magasin')->form(['confirm'=>'1']);$this->client->submit($form);self::assertResponseStatusCodeSame(303);
        $this->client->submit($form);self::assertResponseStatusCodeSame(303);
        self::assertSame(1,(int)$this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE action='stock.article_withdrawn'"));
        self::assertSame($before,(int)$this->db->fetchOne('SELECT count(*) FROM stock_movements'));
        self::assertSame(2,(int)$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE article_id=? AND store_id=?',[$this->article,$this->stores['B']]));
        self::assertSame(0,(int)$this->db->fetchOne("SELECT count(*) FROM stock_alerts WHERE store_id=? AND status='active'",[$this->stores['A']]));
        $event=$this->db->fetchOne("SELECT id FROM notification_events WHERE store_id=? AND type='stock_critical' LIMIT 1",[$this->stores['A']]);self::assertNotFalse($event);
        $this->db->executeStatement("UPDATE notification_events SET status='queued' WHERE id=?",[$event]);
        $mail=new class implements \App\Notification\MailDelivery {public int $sent=0;public function send(\Symfony\Component\Mime\Email $email): void {$this->sent++;}};
        $handler=new \App\Notification\NotificationDeliveryHandler($this->db,$mail,static::getContainer()->get(\App\Notification\NotificationRenderer::class),new \Psr\Log\NullLogger(),true,'test');
        try {$handler(new \App\Message\DeliverNotification($event));self::fail('Alerte retirée non envoyée');}catch(\Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException) {}
        self::assertSame(0,$mail->sent);
        $catalog=static::getContainer()->get(\App\Access\CatalogDirectory::class);$stocks=static::getContainer()->get(\App\Access\StockDirectory::class);
        self::assertSame(0,$catalog->listing($this->actors['admin'],'article',['store_id'=>$this->stores['A']])['meta']['total']);
        self::assertSame(0,$stocks->listing($this->actors['admin'],['store_id'=>$this->stores['A']])['meta']['total']);
        self::assertSame($this->article,$catalog->get($this->actors['admin'],'article',$this->article)['data']['id']);
        self::assertSame(2,$stocks->history($this->actors['admin'],$this->stores['A'],$this->article)['meta']['total']);
        $workflow=static::getContainer()->get(\App\Inventory\InventoryWorkflow::class);
        $inventory=$workflow->create($this->actors['admin'],(object)['store_id'=>$this->stores['A']],bin2hex(random_bytes(16)))['data']['id'];
        $workflow->transition($this->actors['admin'],$inventory,'start',bin2hex(random_bytes(16)));
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM inventory_lines WHERE inventory_id=? AND article_id=?',[$inventory,$this->article]));
        $workflow->transition($this->actors['admin'],$inventory,'cancel',bin2hex(random_bytes(16)));
        static::getContainer()->get(StockExtensions::class)->deposit($this->actors['user'],$this->stores['A'],$this->article,1,bin2hex(random_bytes(16)));
        self::assertSame(1,$stocks->listing($this->actors['admin'],['store_id'=>$this->stores['A']])['meta']['total']);
        self::assertFalse((bool)$this->db->fetchOne('SELECT withdrawn FROM stock_levels WHERE article_id=? AND store_id=?',[$this->article,$this->stores['A']]));
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference<>0'));
    }
    public function testLocalWithdrawalRechecksQuantityAssignmentInventoryAndConfirmation(): void
    {
        $service=static::getContainer()->get(\App\Stock\StoreArticleWithdrawal::class);
        $this->denied(fn()=> $service->withdraw($this->actors['user'],$this->stores['A'],$this->article),403);
        $this->denied(fn()=> $service->withdraw($this->actors['manager'],$this->stores['B'],$this->article),404);
        $this->denied(fn()=> $service->withdraw($this->actors['admin'],$this->stores['A'],$this->article),409);
        $this->move('adjustment',-5);$url='/stocks/'.$this->stores['A'].'/'.$this->article.'/retirer';
        $this->client->loginUser($this->actors['user']);$this->client->request('GET','/stocks?store_id='.$this->stores['A']);
        self::assertSelectorNotExists('a[href="'.$url.'"]');$this->client->request('GET',$url);self::assertResponseStatusCodeSame(403);
        $this->client->loginUser($this->actors['admin']);$this->client->request('GET','/stocks?store_id='.$this->stores['A']);self::assertSelectorExists('a[href="'.$url.'"]');
        $crawler=$this->client->request('GET',$url);$values=$crawler->selectButton('Confirmer le retrait du magasin')->form()->getPhpValues();
        $this->client->request('POST',$url,$values);self::assertResponseStatusCodeSame(422);
        $this->client->request('POST',$url,array_replace($values,['confirm'=>'1','_csrf_token'=>'bad']));self::assertResponseStatusCodeSame(403);
        $inventory=$this->db->fetchOne("INSERT INTO inventories(store_id,created_by,status,snapshot_at) VALUES(?,?,'in_progress',clock_timestamp()) RETURNING id",[$this->stores['A'],$this->actors['admin']->id]);
        $this->denied(fn()=> $service->withdraw($this->actors['admin'],$this->stores['A'],$this->article),409);
        $this->db->executeStatement("UPDATE inventories SET status='cancelled',cancelled_at=clock_timestamp() WHERE id=?",[$inventory]);
        $this->move('entry',1);$this->client->request('POST',$url,array_replace($values,['confirm'=>'1']));self::assertResponseStatusCodeSame(409);
        self::assertFalse((bool)$this->db->fetchOne('SELECT withdrawn FROM stock_levels WHERE article_id=? AND store_id=?',[$this->article,$this->stores['A']]));
        self::assertSame(0,(int)$this->db->fetchOne("SELECT count(*) FROM audit_logs WHERE action='stock.article_withdrawn'"));
    }
    public function testNavigationScannerAndRemovedExportFollowNewRequirements(): void
    {
        $items=(new \App\Ui\Navigation())->items(['ROLE_UTILISATEUR'],$this->stores['A']);self::assertSame(['Tableau de bord','Articles','Stocks','Consommations'],array_column($items,'label'));
        $this->client->request('GET','/stocks/ajustement');self::assertSelectorExists('[data-controller=scanner]');self::assertStringContainsString('camera=(self)',$this->client->getResponse()->headers->get('Permissions-Policy'));
        $this->client->request('GET','/magasins/'.$this->stores['A'].'/export.xlsx');self::assertResponseStatusCodeSame(404);
        $this->client->request('GET','/stocks?store_id='.$this->stores['A']);self::assertSelectorNotExists('a[href$="export.xlsx"]');self::assertResponseIsSuccessful();
    }
}
