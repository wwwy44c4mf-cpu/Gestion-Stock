<?php

declare(strict_types=1);

namespace App\Tests;

use App\Api\OpenApiContract;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProposalTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private array $ids=[];
    private string $csrf;
    protected function setUp(): void
    {
        $this->client=static::createClient();$this->client->disableReboot();$this->db=static::getContainer()->get(Connection::class);$this->db->beginTransaction();
        $schema='art02_'.bin2hex(random_bytes(6));$this->db->executeStatement('CREATE SCHEMA '.$schema);$this->db->executeStatement('SET LOCAL search_path TO '.$schema);
        foreach(explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) { if(trim($sql)!=='') { $this->db->executeStatement($sql); } }
        \App\Tests\CatalogDeletionSchema::apply($this->db);
        $this->db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
        foreach(['20261018140000','20261018150000'] as $version) { require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';$class='DoctrineMigrations\\Version'.$version;$migration=new $class($this->db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) { $this->db->executeStatement($sql->getStatement()); } }
        $hash=password_hash('Proposal-test-2026!',PASSWORD_ARGON2ID);foreach(['admin'=>1,'admin2'=>1,'manager'=>2,'manager2'=>2,'user'=>3] as $name=>$role) { $this->ids[$name]=$this->db->fetchOne('INSERT INTO users (email,name,password_hash,role_id) VALUES (?,?,?,?) RETURNING id',[$name.'@example.test',ucfirst($name),$hash,$role]); }
    }
    protected function tearDown(): void { if($this->db->isTransactionActive()) { $this->db->rollBack(); }parent::tearDown(); }
    private function login(string $name='manager'): void
    {
        $this->client->getCookieJar()->clear();$this->client->loginUser(static::getContainer()->get(\App\Security\StockUserProvider::class)->loadUserByIdentifier($name.'@example.test'));$this->client->request('GET','/api/v1/auth/csrf');$this->csrf=json_decode($this->client->getResponse()->getContent(),true)['token'];
    }
    private function send(string $method,string $path,mixed $body=null,string $key='proposal-request-key'): array
    {
        $this->client->request($method,$path,server:['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$this->csrf,'HTTP_IDEMPOTENCY_KEY'=>$key],content:$body===null?null:json_encode($body));return json_decode($this->client->getResponse()->getContent(),true)??[];
    }
    private function body(array $extra=[]): array { return ['operation'=>'create','payload'=>array_replace(['sku'=>'PROPOSED-001','name'=>'Article proposé'],$extra)]; }
    private function submit(array $body=[],string $key='proposal-create-key'): array { return $this->send('POST','/api/v1/article-proposals',$body?:$this->body(),$key); }
    private function published(array $extra=[]): array
    {
        $this->login('admin');$result=$this->send('POST','/api/v1/articles',array_replace(['sku'=>'PUBLISHED','name'=>'Publié'],$extra),'published-article-key');self::assertResponseStatusCodeSame(201);return $result['data'];
    }
    public function testSubmissionLeavesCatalogueUnchangedAndOnlyAdministratorPublishes(): void
    {
        $this->login();$first=$this->submit();self::assertResponseStatusCodeSame(201);$id=$first['data']['id'];self::assertSame($this->ids['manager'],$first['data']['proposed_by']);self::assertSame('pending',$first['data']['status']);self::assertNull($first['data']['review_comment']);self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM articles'));
        self::assertTrue(static::getContainer()->get(OpenApiContract::class)->valid(json_decode(json_encode($first)),['$ref'=>'#/components/schemas/ProposalResource']));self::assertSame('/api/v1/article-proposals/'.$id,$this->client->getResponse()->headers->get('Location'));
        $this->send('POST','/api/v1/article-proposals/'.$id.'/review',['decision'=>'approved'],'approval-key-123');self::assertResponseStatusCodeSame(403);
        $this->login('admin');$approved=$this->send('POST','/api/v1/article-proposals/'.$id.'/review',['decision'=>'approved'],'approval-key-123');self::assertResponseIsSuccessful();self::assertSame('approved',$approved['data']['status']);self::assertSame($this->ids['admin'],$approved['data']['reviewed_by']);self::assertNotNull($approved['data']['reviewed_at']);self::assertNotNull($approved['data']['published_article_id']);self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM articles'));self::assertSame('PROPOSED-001',$this->db->fetchOne('SELECT sku FROM articles'));
        $again=$this->send('POST','/api/v1/article-proposals/'.$id.'/review',['decision'=>'approved'],'approval-key-123');self::assertResponseIsSuccessful();self::assertEquals($approved,$again);self::assertSame(3,(int)$this->db->fetchOne('SELECT count(*) FROM audit_logs'));
        $this->send('POST','/api/v1/article-proposals/'.$id.'/review',['decision'=>'rejected','comment'=>'Autre décision'],'approval-key-123');self::assertResponseStatusCodeSame(409);
        $this->login('admin2');$this->send('POST','/api/v1/article-proposals/'.$id.'/review',['decision'=>'rejected','comment'=>'Autre décision'],'another-review-key');self::assertResponseStatusCodeSame(409);
        $this->login();self::assertEquals($first,$this->submit());self::assertResponseStatusCodeSame(201);$this->client->request('GET','/api/v1/article-proposals/'.$id);self::assertSame('approved',json_decode($this->client->getResponse()->getContent(),true)['data']['status']);
    }
    public function testRejectionRequiresReasonAndNeverChangesCatalogue(): void
    {
        $this->login();$id=$this->submit()['data']['id'];$this->login('admin');
        foreach([['decision'=>'rejected'],['decision'=>'rejected','comment'=>'  '],['decision'=>'rejected','comment'=>"bad\0text"],['decision'=>'rejected','comment'=>str_repeat('x',2001)],['decision'=>'approved','reviewed_by'=>$this->ids['manager']]] as $body) { $this->send('POST','/api/v1/article-proposals/'.$id.'/review',$body,'reject-key-123');self::assertResponseStatusCodeSame(422); }
        $result=$this->send('POST','/api/v1/article-proposals/'.$id.'/review',['decision'=>'rejected','comment'=>' Raison <script>fictive</script> '],'reject-key-123');self::assertResponseIsSuccessful();self::assertSame('rejected',$result['data']['status']);self::assertSame('Raison <script>fictive</script>',$result['data']['review_comment']);self::assertNull($result['data']['published_article_id']);self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM articles'));
        $this->login();$this->client->request('GET','/propositions/'.$id);self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Raison <script>fictive</script>');self::assertSelectorNotExists('main script');self::assertSelectorNotExists('button[value="approved"]');
    }
    public function testManagersOnlyReadOwnProposalsAndTotalsAreScoped(): void
    {
        $this->login();$own=$this->submit()['data']['id'];$this->login('manager2');$other=$this->submit($this->body(['sku'=>'OTHER']),'manager2-submit-key')['data']['id'];
        foreach(['manager'=>1,'manager2'=>1,'admin'=>2] as $name=>$total) { $this->login($name);$this->client->request('GET','/api/v1/article-proposals?limit=1&page=2');self::assertResponseIsSuccessful();$body=json_decode($this->client->getResponse()->getContent(),true);self::assertSame($total,$body['meta']['total']);self::assertCount($name==='admin'?1:0,$body['data']); }
        $this->login();foreach(['/api/v1/article-proposals/'.$other,'/propositions/'.$other] as $path) { $this->client->request('GET',$path);self::assertResponseStatusCodeSame(404); }
        $this->client->request('GET','/api/v1/article-proposals/'.$own);self::assertResponseIsSuccessful();
        $this->login('user');foreach(['/api/v1/article-proposals','/api/v1/article-proposals/'.$own,'/propositions','/propositions/nouvelle','/propositions/'.$own] as $path) { $this->client->request('GET',$path);self::assertResponseStatusCodeSame(403); }
        $this->submit();self::assertResponseStatusCodeSame(403);$this->client->request('GET','/articles');self::assertResponseIsSuccessful();self::assertSelectorNotExists('a[href="/propositions"]');
    }
    public function testApprovedModificationRetainsIdentityAndOnlyChangesAfterApproval(): void
    {
        $article=$this->published();$before=$this->db->fetchAssociative('SELECT * FROM articles');$this->login();$body=['operation'=>'update','article_id'=>$article['id'],'base_version'=>1,'payload'=>['name'=>'Proposé','description'=>'Nouvelle description','active'=>false]];$id=$this->submit($body)['data']['id'];self::assertResponseStatusCodeSame(201);self::assertSame($before,$this->db->fetchAssociative('SELECT * FROM articles'));
        $this->login('admin');$result=$this->send('POST','/api/v1/article-proposals/'.$id.'/review',['decision'=>'approved'],'modify-approval-key');self::assertResponseIsSuccessful();self::assertSame($article['id'],$result['data']['published_article_id']);$row=$this->db->fetchAssociative('SELECT * FROM articles');self::assertSame('PUBLISHED',$row['sku']);self::assertSame('unit',$row['unit_code']);self::assertSame('Proposé',$row['name']);self::assertFalse($row['active']);self::assertSame(2,(int)$row['version']);self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM articles'));
    }
    public function testStaleProposalCannotOverwriteNewerArticleButCanBeRejected(): void
    {
        $article=$this->published();$this->login();$id=$this->submit(['operation'=>'update','article_id'=>$article['id'],'base_version'=>1,'payload'=>['name'=>'Ancienne proposition']])['data']['id'];
        $this->login('admin');$this->send('PATCH','/api/v1/articles/'.$article['id'],['version'=>1,'name'=>'Version récente']);self::assertResponseIsSuccessful();
        $error=$this->send('POST','/api/v1/article-proposals/'.$id.'/review',['decision'=>'approved'],'stale-review-key');self::assertResponseStatusCodeSame(409);self::assertSame('stale_article',$error['code']);self::assertSame('Version récente',$this->db->fetchOne('SELECT name FROM articles'));self::assertSame('pending',$this->db->fetchOne('SELECT status FROM article_proposals'));
        $this->client->request('GET','/propositions/'.$id);self::assertResponseIsSuccessful();self::assertSelectorExists('button[value="approved"][disabled]');self::assertSelectorExists('button[value="rejected"]');self::assertSelectorTextContains('main','PUBLISHED');
        $this->send('POST','/api/v1/article-proposals/'.$id.'/review',['decision'=>'rejected','comment'=>'À reprendre sur la nouvelle fiche'],'stale-reject-key');self::assertResponseIsSuccessful();
    }
    public function testDuplicatePendingReferencesAndCategoryChangesAreRecheckedAtPublication(): void
    {
        $this->login();$one=$this->submit()['data']['id'];$this->login('manager2');$two=$this->submit()['data']['id'];self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM articles'));
        $this->login('admin');$this->send('POST','/api/v1/article-proposals/'.$one.'/review',['decision'=>'approved'],'first-publish-key');self::assertResponseIsSuccessful();$error=$this->send('POST','/api/v1/article-proposals/'.$two.'/review',['decision'=>'approved'],'second-publish-key');self::assertResponseStatusCodeSame(409);self::assertSame('sku_already_used',$error['code']);self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM articles'));
        $this->login();$this->submit($this->body(['sku'=>' proposed-001 ']),'duplicate-submit-key');self::assertResponseStatusCodeSame(409);
        $this->login('admin');$category=$this->send('POST','/api/v1/categories',['name'=>'Temporaire'],'create-category-key')['data']['id'];$this->login();$id=$this->submit($this->body(['sku'=>'WITH-CATEGORY','category_id'=>$category]),'with-category-key')['data']['id'];
        $this->login('admin');$this->send('PATCH','/api/v1/categories/'.$category,['active'=>false]);self::assertResponseIsSuccessful();$error=$this->send('POST','/api/v1/article-proposals/'.$id.'/review',['decision'=>'approved'],'category-review-key');self::assertResponseStatusCodeSame(409);self::assertSame('proposal_not_applicable',$error['code']);self::assertSame('pending',$this->db->fetchOne('SELECT status FROM article_proposals WHERE id = ?',[$id]));
    }
    public function testInvalidPayloadsReferencesAndVersionsCannotBeProposed(): void
    {
        $article=$this->published();$this->login();
        foreach([$this->body(['sku'=>'  ']),$this->body(['name'=>'  ']),$this->body(['unit_code'=>'litre']),$this->body(['name'=>"nul\0texte"]),['operation'=>'update','article_id'=>$article['id'],'base_version'=>1,'payload'=>['sku'=>'NEW']],['operation'=>'update','article_id'=>$article['id'],'base_version'=>1,'payload'=>['name'=>'Publié']],['operation'=>'update','article_id'=>$article['id'],'payload'=>['name'=>'X']],['operation'=>'create','payload'=>['sku'=>'X','name'=>'X'],'proposed_by'=>$this->ids['admin']]] as $body) { $this->submit($body);self::assertResponseStatusCodeSame(422); }
        $this->submit(['operation'=>'update','article_id'=>$article['id'],'base_version'=>2,'payload'=>['name'=>'X']]);self::assertResponseStatusCodeSame(409);
        $actor=static::getContainer()->get(\App\Security\StockUserProvider::class)->loadUserByIdentifier('manager@example.test');
        try { static::getContainer()->get(\App\Administration\ProposalAdministration::class)->submit($actor,(object)['operation'=>'create','payload'=>(object)['sku'=>'BAD-UTF8','name'=>"\xC3\x28"]],'invalid-utf8-key');self::fail('Invalid UTF-8 should be refused.'); }
        catch(\App\Api\ApiProblem $error) { self::assertSame(422,$error->status); }
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM article_proposals'));
    }
    public function testCsrfAndIdempotencyKeysProtectBothActions(): void
    {
        $this->login();$this->client->request('POST','/api/v1/article-proposals',server:['CONTENT_TYPE'=>'application/json','HTTP_IDEMPOTENCY_KEY'=>'no-csrf-key'],content:json_encode($this->body()));self::assertResponseStatusCodeSame(403);$this->submit([],'bad');self::assertResponseStatusCodeSame(422);
        $id=$this->submit()['data']['id'];$this->submit($this->body(['name'=>'Autre demande']));self::assertResponseStatusCodeSame(409);$this->login('admin');$this->client->request('POST','/api/v1/article-proposals/'.$id.'/review',server:['CONTENT_TYPE'=>'application/json','HTTP_IDEMPOTENCY_KEY'=>'no-csrf-key'],content:'{"decision":"approved"}');self::assertResponseStatusCodeSame(403);
        self::assertSame('pending',$this->db->fetchOne('SELECT status FROM article_proposals'));self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM articles'));
    }
    public function testDatabaseFailureAfterPublicationRollsBackArticleDecisionReplayAndAudit(): void
    {
        $this->login();$id=$this->submit()['data']['id'];$this->login('admin');$this->db->executeStatement("CREATE FUNCTION fail_proposal_review() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Failure injected by isolated test'; END; $$");$this->db->executeStatement('CREATE TRIGGER fail_review BEFORE UPDATE ON article_proposals FOR EACH ROW EXECUTE FUNCTION fail_proposal_review()');
        $this->send('POST','/api/v1/article-proposals/'.$id.'/review',['decision'=>'approved'],'failing-review-key');self::assertResponseStatusCodeSame(500);self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM articles'));self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM catalog_creation_requests'));self::assertSame('pending',$this->db->fetchOne('SELECT status FROM article_proposals'));self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM proposal_requests'));self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM audit_logs'));
    }
    public function testFormsWithoutJavascriptAndRejectionReasonReachTheirOwner(): void
    {
        $this->login();$crawler=$this->client->request('GET','/propositions/nouvelle');self::assertResponseIsSuccessful();$this->client->submit($crawler->selectButton('Envoyer la proposition')->form(['sku'=>'UI-PROPOSAL','name'=>'Proposition UI','description'=>'Description UI']));self::assertResponseStatusCodeSame(303);$this->client->followRedirect();self::assertResponseIsSuccessful();$id=$this->db->fetchOne('SELECT id FROM article_proposals');self::assertSelectorNotExists('button[value="approved"]');self::assertSelectorTextContains('main','En attente');
        $this->login('admin');$crawler=$this->client->request('GET','/propositions/'.$id);self::assertResponseIsSuccessful();$form=$crawler->selectButton('Refuser la proposition')->form(['comment'=>'Motif destiné au gestionnaire']);$this->client->submit($form);self::assertResponseStatusCodeSame(303);$this->client->followRedirect();self::assertSelectorTextContains('main','Refusée');self::assertSelectorNotExists('button[value="rejected"]');
        $this->login();$this->client->request('GET','/propositions/'.$id);self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Motif destiné au gestionnaire');self::assertSelectorExists('a[href="/propositions/nouvelle"]');self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM articles'));
        $this->client->request('POST','/propositions/nouvelle',['operation'=>'create','sku'=>'BAD','name'=>'No token']);self::assertResponseStatusCodeSame(403);
    }
    public function testApprovalFormPublishesWithoutJavascript(): void
    {
        $this->login();$id=$this->submit($this->body(['sku'=>'0','name'=>'0','description'=>'0']))['data']['id'];$this->login('admin');$crawler=$this->client->request('GET','/propositions/'.$id);self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table tbody tr:first-child td:last-child','0');self::assertSelectorTextContains('main','unité');self::assertSelectorTextContains('main','Actif');$form=$crawler->selectButton('Approuver et publier')->form(['comment'=>'0']);$this->client->submit($form);self::assertResponseStatusCodeSame(303);$this->client->followRedirect();self::assertResponseIsSuccessful();self::assertSelectorTextContains('main','Approuvée');self::assertSelectorTextContains('h2','Commentaire de décision');self::assertSelectorExists('a[href^="/articles/"]');self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM articles'));
    }
    public function testModificationFormRetainsItsBaseVersionAfterAConflict(): void
    {
        $article=$this->published();$this->login();$crawler=$this->client->request('GET','/propositions/nouvelle?article_id='.$article['id']);$form=$crawler->selectButton('Envoyer la proposition')->form(['name'=>'Nouveau libellé proposé']);
        $this->db->executeStatement("UPDATE articles SET name='Autre modification',version=2 WHERE id = ?",[$article['id']]);$this->client->submit($form);self::assertResponseStatusCodeSame(409);self::assertSelectorExists('input[name="base_version"][value="1"]');self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM article_proposals'));
    }
    public function testInvalidIdsAndAuditContainNoPayloadOrRejectionValues(): void
    {
        $this->login();foreach(['not-an-id','11111111-1111-4111-8111-111111111111'] as $id) { $this->client->request('GET','/api/v1/article-proposals/'.$id);self::assertResponseStatusCodeSame(404); }
        $id=$this->submit($this->body(['description'=>'Description fictive privée']))['data']['id'];$this->login('admin');$this->send('POST','/api/v1/article-proposals/'.$id.'/review',['decision'=>'rejected','comment'=>'Motif fictif privé'],'private-comment-key');self::assertResponseIsSuccessful();$audit=implode(' ',$this->db->fetchFirstColumn('SELECT context::text FROM audit_logs'));self::assertStringNotContainsString('Description fictive',$audit);self::assertStringNotContainsString('Motif fictif',$audit);
        $this->client->request('GET','/api/v1/article-proposals?status=approved');self::assertResponseIsSuccessful();self::assertSame(0,json_decode($this->client->getResponse()->getContent(),true)['meta']['total']);
    }
}
