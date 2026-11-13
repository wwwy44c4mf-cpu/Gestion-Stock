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
        $this->db->insert('user_stores',['user_id'=>$this->actors['manager']->id,'store_id'=>$this->stores['A']]);$this->db->insert('user_stores',['user_id'=>$this->actors['user']->id,'store_id'=>$this->stores['A']]);
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
    public function testUnlinkedReturnCreditsStockForAnyTechnicianWithoutInventedConsumptionAndReplaysOnce(): void
    {
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
    public function testNavigationScannerAndRemovedExportFollowNewRequirements(): void
    {
        $items=(new \App\Ui\Navigation())->items(['ROLE_UTILISATEUR'],$this->stores['A']);self::assertSame(['Tableau de bord','Articles','Stocks','Consommations'],array_column($items,'label'));
        $this->client->request('GET','/stocks/ajustement');self::assertSelectorExists('[data-controller=scanner]');self::assertStringContainsString('camera=(self)',$this->client->getResponse()->headers->get('Permissions-Policy'));
        $this->client->request('GET','/magasins/'.$this->stores['A'].'/export.xlsx');self::assertResponseStatusCodeSame(404);
        $this->client->request('GET','/stocks?store_id='.$this->stores['A']);self::assertSelectorNotExists('a[href$="export.xlsx"]');self::assertResponseIsSuccessful();
    }
}
