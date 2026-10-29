<?php

declare(strict_types=1);
namespace App\Tests;
use App\Api\OpenApiContract;
use App\Audit\{AuditContext,AuditDirectory};
use App\Security\StockUserProvider;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuditTest extends WebTestCase
{
    private $client;private Connection $db;private array $users=[],$stores=[],$ids=[];private string $article;
    protected function setUp(): void
    {
        $this->client=static::createClient();$this->client->disableReboot();$this->db=static::getContainer()->get(Connection::class);$this->db->beginTransaction();
        $schema='audit_'.bin2hex(random_bytes(6));$this->db->executeStatement('CREATE SCHEMA '.$schema);$this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        foreach(explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) {if(trim($sql)!=='') {$this->db->executeStatement($sql);}}
        \App\Tests\CatalogDeletionSchema::apply($this->db);
        $this->db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
        foreach(['20261018120000','20261018130000','20261018140000','20261018150000','20261018200000','20261018223000','20261020220000','20261020230000'] as $version) {require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';$class='DoctrineMigrations\\Version'.$version;$migration=new $class($this->db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) {$this->db->executeStatement($sql->getStatement());}}
        $hash=password_hash('Audit-test-2026!',PASSWORD_ARGON2ID);
        foreach(['admin'=>1,'manager'=>2,'user'=>3,'outside'=>2,'unassigned'=>2] as $name=>$role) {$this->users[$name]=$this->db->fetchOne('INSERT INTO users(email,name,password_hash,role_id) VALUES (?,?,?,?) RETURNING id',[$name.'@example.test',$name,$hash,$role]);}
        foreach(['A','SECRET','D'] as $code) {$this->stores[$code]=$this->db->fetchOne('INSERT INTO stores(code,name) VALUES (?,?) RETURNING id',[$code,'Magasin '.$code]);}
        $this->db->executeStatement('UPDATE stores SET active=false WHERE id=?',[$this->stores['D']]);$this->db->insert('user_stores',['user_id'=>$this->users['manager'],'store_id'=>$this->stores['A']]);
        $this->article=$this->db->fetchOne("INSERT INTO articles(sku,name) VALUES ('AUDIT','Article audit') RETURNING id");
        $this->ids['global']=$this->event('user.updated',null,'admin',['changed_fields'=>['role','store_ids'],'password'=>'SECRET-PASSWORD'],'user',$this->users['user']);
        $this->ids['A']=$this->event('stock.thresholds_updated','A','manager',['before'=>['low_threshold'=>null,'critical_threshold'=>null],'after'=>['low_threshold'=>5,'critical_threshold'=>0],'token'=>'SECRET-TOKEN'],'article',$this->article);
        $this->ids['SECRET']=$this->event('inventory.start','SECRET','outside',['status'=>'in_progress']);
        $this->ids['D']=$this->event('stock.entry','D','admin',['type'=>'entry']);
        $this->ids['unknown']=$this->event('future.action','A','user',['payload'=>['smtp_password'=>'SECRET-SMTP']]);
    }
    private function event(string $action,?string $store,?string $actor,array $context=[],string $type='inventory',?string $target=null,string $date='2026-03-29 12:00:00+00'): string
    {return $this->db->fetchOne('INSERT INTO audit_logs(actor_id,store_id,action,target_type,target_id,context,created_at) VALUES (?,?,?,?,?,?::jsonb,?) RETURNING id',[$actor===null?null:$this->users[$actor],$store===null?null:$this->stores[$store],$action,$type,$target,json_encode((object)$context),$date]);}
    private function login(string $name='manager'): void {$this->client->getCookieJar()->clear();$this->client->loginUser(static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier($name.'@example.test'));}
    private function page(string $query=''): array {$this->client->request('GET','/api/v1/audit-logs'.$query);return json_decode($this->client->getResponse()->getContent(),true)??[];}
    protected function tearDown(): void {if($this->db->isTransactionActive()) {$this->db->rollBack();}parent::tearDown();}

    public function testScopesAreAppliedBeforeCountsAndOptions(): void
    {
        $this->login();$p=$this->page('?limit=1');self::assertResponseIsSuccessful();self::assertSame(2,$p['meta']['total']);self::assertCount(1,$p['data']);self::assertStringNotContainsString('SECRET',json_encode($p));
        $this->client->request('GET','/audit');self::assertResponseIsSuccessful();self::assertSelectorExists('option[value="'.$this->users['manager'].'"]');self::assertSelectorNotExists('option[value="'.$this->users['outside'].'"]');self::assertSelectorNotExists('option[value="'.$this->stores['SECRET'].'"]');self::assertSelectorNotExists('option[value="user.updated"]');
        $this->page('?store_id='.$this->stores['SECRET']);self::assertResponseStatusCodeSame(404);$this->page('?store_id='.$this->stores['D']);self::assertResponseStatusCodeSame(404);
        $this->login('admin');self::assertSame(5,$this->page()['meta']['total']);self::assertSame(1,$this->page('?store_id='.$this->stores['D'])['meta']['total']);
        $this->login('unassigned');self::assertSame(0,$this->page()['meta']['total']);
        $this->login();$this->db->executeStatement('DELETE FROM user_stores WHERE user_id=?',[$this->users['manager']]);self::assertSame(0,$this->page()['meta']['total']);
    }
    public function testTypedContextNeverExposesSecretsAndPreservesKnownValues(): void
    {
        $this->login('admin');$p=$this->page();$raw=$this->client->getResponse()->getContent();self::assertStringNotContainsString('SECRET-', $raw);self::assertStringNotContainsString('@example.test',$raw);
        self::assertTrue(static::getContainer()->get(OpenApiContract::class)->valid(json_decode($raw),['$ref'=>'#/components/schemas/AuditLogPage']));
        $rows=array_column($p['data'],null,'id');self::assertSame(['changed_fields'=>['role','store_ids']],$rows[$this->ids['global']]['context']);self::assertSame(0,$rows[$this->ids['A']]['context']['after']['critical_threshold']);self::assertNull($rows[$this->ids['A']]['context']['before']['low_threshold']);self::assertSame([],$rows[$this->ids['unknown']]['context']);
        $this->event('auth.login_failed',null,null,['ip_key'=>'SECRET-HASH','account_key'=>'SECRET-ACCOUNT','token'=>'SECRET-TOKEN'],'user');self::assertSame([],$this->page('?action=auth.login_failed')['data'][0]['context']);
        $context=static::getContainer()->get(AuditContext::class)->project('stock.thresholds_updated',['before'=>['low_threshold'=>'SECRET-VALUE'],'after'=>['low_threshold'=>3,'critical_threshold'=>null,'password'=>'SECRET-VALUE']]);self::assertSame('{"before":{},"after":{"low_threshold":3,"critical_threshold":null}}',json_encode($context));
    }
    public function testPartialMovementMasksBeforeActorFilteringAndSelectors(): void
    {
        $id=$this->db->fetchOne("INSERT INTO stock_movements(type,article_id,source_store_id,destination_store_id,quantity,author_id,idempotency_key) VALUES ('transfer',?,?,?,2,?,'audit-transfer') RETURNING id",[$this->article,$this->stores['A'],$this->stores['SECRET'],$this->users['outside']]);
        $event=$this->event('stock.transfer','A','outside',['type'=>'transfer','original_id'=>$id],'stock_movement',$id);$this->login();$p=$this->page('?action=stock.transfer');$item=$p['data'][0];self::assertSame($event,$item['id']);self::assertTrue($item['restricted']);self::assertNull($item['actor_id']);self::assertNull($item['actor_name']);self::assertNull($item['target_id']);self::assertSame([],$item['context']);self::assertSame(0,$this->page('?actor_id='.$this->users['outside'])['meta']['total']);
        $this->client->request('GET','/audit');self::assertSelectorNotExists('option[value="'.$this->users['outside'].'"]');self::assertStringNotContainsString($id,$this->client->getResponse()->getContent());
        $this->login('admin');$item=$this->page('?action=stock.transfer')['data'][0];self::assertFalse($item['restricted']);self::assertSame($this->users['outside'],$item['actor_id']);self::assertSame($id,$item['target_id']);
        $this->db->insert('user_stores',['user_id'=>$this->users['manager'],'store_id'=>$this->stores['SECRET']]);$this->login();self::assertFalse($this->page('?action=stock.transfer')['data'][0]['restricted']);
    }
    public function testDatesStablePaginationTimezoneAndCombinedFilters(): void
    {
        $early=$this->event('inventory.create','A','manager',[],date:'2026-03-28 23:00:00+00');$late=$this->event('inventory.create','A','manager',[],date:'2026-03-29 22:00:00+00');$this->login();self::assertSame(3,$this->page('?date_from=2026-03-29&date_to=2026-03-29')['meta']['total']);
        $p=$this->page('?store_id='.$this->stores['A'].'&actor_id='.$this->users['manager'].'&action=inventory.create&date_to=2026-03-29');self::assertSame([$early],array_column($p['data'],'id'));
        $ids=[];foreach([1,2,3,4] as $page) {$p=$this->page('?limit=1&order=asc&page='.$page);$ids[]=$p['data'][0]['id'];}self::assertCount(4,array_unique($ids));self::assertSame($early,$ids[0]);self::assertSame($late,$ids[3]);
        $this->db->executeStatement("UPDATE users SET timezone='Pacific/Honolulu' WHERE id=?",[$this->users['manager']]);$this->login();self::assertSame([$early],array_column($this->page('?date_from=2026-03-28&date_to=2026-03-28')['data'],'id'));
    }
    public function testAnonymizedAndSystemActorsAndSafeHtml(): void
    {
        $this->db->executeStatement('UPDATE users SET active=false,email=null,name=null,password_hash=null,anonymized_at=clock_timestamp() WHERE id=?',[$this->users['user']]);$this->event('notifications.daily_summary',null,null,['recipient_count'=>0,'item_count'=>0]);$this->login('admin');$p=$this->page();$rows=array_column($p['data'],null,'id');self::assertSame('Compte anonymisé',$rows[$this->ids['unknown']]['actor_name']);
        $this->db->executeStatement('UPDATE stores SET name=? WHERE id=?',['<script>unsafe</script>',$this->stores['A']]);$this->client->request('GET','/audit');self::assertResponseIsSuccessful();self::assertSelectorNotExists('main script');self::assertStringContainsString('&lt;script&gt;unsafe&lt;/script&gt;',$this->client->getResponse()->getContent());self::assertSelectorTextContains('main','Système ou acteur inconnu');
    }
    public function testNoWriteRoutesAndDatabaseImmutability(): void
    {
        $this->login('admin');foreach(['POST','PATCH','PUT','DELETE'] as $method) {$this->client->request($method,'/api/v1/audit-logs');self::assertResponseStatusCodeSame(405);$this->client->request($method,'/audit');self::assertResponseStatusCodeSame(405);}
        foreach(['UPDATE audit_logs SET context=\'{}\' WHERE id=?','DELETE FROM audit_logs WHERE id=?'] as $sql) {$this->db->createSavepoint('immutable_check');try {$this->db->executeStatement($sql,[$this->ids['A']]);self::fail('Le journal doit refuser les mutations.');}catch(\Doctrine\DBAL\Exception $error) {self::assertStringContainsString('immuable',$error->getMessage());}finally {$this->db->rollbackSavepoint('immutable_check');}}
        self::assertSame(5,$this->page()['meta']['total']);
    }
    public function testUsersAnonymousAndInvalidFiltersAreRejected(): void
    {
        $this->login('user');$this->page();self::assertResponseStatusCodeSame(403);$this->client->request('GET','/audit');self::assertResponseStatusCodeSame(403);$this->client->request('GET','/espace/audit');self::assertResponseStatusCodeSame(403);
        $this->login();foreach(['?wat=1','?store_id[]=x','?actor_id=bad','?action=','?action=%20','?page=0','?limit=101','?sort=action','?order=bad','?date_from=2026-02-30','?date_from=2026-05-02&date_to=2026-05-01'] as $q) {$this->page($q);self::assertResponseStatusCodeSame(422);}
        $this->client->request('GET','/audit?date_from=2026-02-30');self::assertResponseStatusCodeSame(422);self::assertSelectorExists('a[href="/audit"]');
        self::assertSame(5,$this->db->fetchOne('SELECT count(*) FROM audit_logs'));$this->client->getCookieJar()->clear();$this->page();self::assertResponseStatusCodeSame(401);$this->client->request('GET','/audit');self::assertResponseRedirects('/connexion');
    }
    public function testHtmlLinksPaginationAndContextReset(): void
    {
        $this->login('admin');$this->client->request('GET','/audit?store_id=&limit=1');self::assertResponseIsSuccessful();self::assertSelectorExists('nav a[href="/audit"][aria-current="page"]');self::assertSelectorExists('a[rel="next"]');self::assertSelectorNotExists('form[method="post"] button:not(.button)');
        $next=$this->client->getCrawler()->filter('a[rel="next"]')->link();self::assertStringContainsString('store_id=', $next->getUri());$this->client->click($next);self::assertResponseIsSuccessful();
        $session=$this->client->getRequest()->getSession();$session->set('store_context',$this->stores['A']);$session->save();$this->client->request('GET','/audit');self::assertSelectorTextContains('main','2 événements');$this->client->request('GET','/audit?store_id=');self::assertSelectorTextContains('main','5 événements');
    }
    public function testSensitiveProducersRemainAttributableAndAtomic(): void
    {
        $actor=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('admin@example.test');
        static::getContainer()->get(\App\Administration\UserAdministration::class)->update($actor,$this->users['user'],(object)['role'=>'MANAGER']);
        static::getContainer()->get(\App\Administration\StoreAdministration::class)->update($actor,$this->stores['A'],(object)['name'=>'Nouveau nom']);
        static::getContainer()->get(\App\Administration\CatalogAdministration::class)->update($actor,'article',$this->article,(object)['version'=>1,'name'=>'Article modifié']);
        $entry=static::getContainer()->get(\App\Stock\StockMovementEngine::class)->record($actor,'entry',(object)['article_id'=>$this->article,'store_id'=>$this->stores['A'],'quantity'=>10,'reason'=>'Secret commentaire non journalisé'],'audit-entry-request')['data'];
        static::getContainer()->get(\App\Stock\StockCorrectionEngine::class)->correct($actor,$entry['id'],(object)['quantity'=>8,'reason'=>'Secret correction'],'audit-correction-request');
        static::getContainer()->get(\App\Stock\StockThresholds::class)->update($actor,$this->stores['A'],$this->article,(object)['low_threshold'=>5,'critical_threshold'=>0]);
        $workflow=static::getContainer()->get(\App\Inventory\InventoryWorkflow::class);$inv=$workflow->create($actor,(object)['store_id'=>$this->stores['A']],'audit-inventory-create')['data'];$workflow->transition($actor,$inv['id'],'start','audit-inventory-start');$workflow->transition($actor,$inv['id'],'cancel','audit-inventory-cancel');
        $this->login('admin');$p=$this->page('?actor_id='.$this->users['admin'].'&limit=100');$actions=array_column($p['data'],'action');foreach(['user.updated','store.updated','article.updated','stock.entry','stock.reversal','stock.correction_replacement','stock.thresholds_updated','inventory.create','inventory.start','inventory.cancel'] as $action) {self::assertContains($action,$actions);}
        foreach($p['data'] as $item) {self::assertSame($this->users['admin'],$item['actor_id']);self::assertNotEmpty($item['created_at']);}self::assertStringNotContainsString('Secret commentaire',json_encode($p));self::assertStringNotContainsString('Secret correction',json_encode($p));
    }
}
