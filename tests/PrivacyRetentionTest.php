<?php

declare(strict_types=1);
namespace App\Tests;
use App\Privacy\AnonymousIdentity;
use App\Privacy\RestoreGate;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class PrivacyRetentionTest extends KernelTestCase
{
    private Connection $db;
    private Connection $admin;
    private string $schema;
    protected function setUp(): void
    {
        if (!is_readable('/run/secrets/privacy_test_admin')) {self::markTestSkipped('Privileged disposable-schema recipe requires explicit test-only admin secret mount.');}
        self::bootKernel();$this->db=static::getContainer()->get(Connection::class);
        $this->schema='privacy_execution_'.bin2hex(random_bytes(6));
        $this->db->executeStatement('CREATE SCHEMA '.$this->schema);$this->db->executeStatement('SET search_path TO '.$this->schema);
        $factory=DependencyFactory::fromConnection(new ConfigurationArray(['migrations_paths'=>['DoctrineMigrations'=>dirname(__DIR__).'/migrations'],'all_or_nothing'=>true]),new ExistingConnection($this->db));
        $command=new MigrateCommand($factory);$command->setName('doctrine:migrations:migrate');$out=new BufferedOutput();$input=new ArrayInput([]);$input->setInteractive(false);self::assertSame(0,$command->run($input,$out),$out->fetch());
        $params=$this->db->getParams();unset($params['url']);$params['user']='postgres';$params['password']=trim(file_get_contents('/run/secrets/privacy_test_admin'));
        $this->admin=DriverManager::getConnection($params);$this->admin->executeStatement('SET search_path TO '.$this->schema);
    }
    protected function tearDown(): void
    {
        if(isset($this->admin)) {$this->admin->executeStatement('ROLLBACK');$this->admin->close();}
        if(isset($this->db)) {$this->db->executeStatement('SET search_path TO public');$this->db->executeStatement('DROP SCHEMA '.$this->schema.' CASCADE');}
        parent::tearDown();
    }
    private function sql(): string {return preg_replace('/^\\\\.*$/m','',file_get_contents(dirname(__DIR__).'/docker/privacy/retention.sql'));}
    private function runRetention(): void {$this->admin->getNativeConnection()->exec($this->sql());}
    private function fixtures(): array
    {
        $u=$this->db->fetchOne("INSERT INTO users(email,name,password_hash,role_id) VALUES ('confidential@example.test','private-name','private-hash',1) RETURNING id");
        $s=$this->db->fetchOne("INSERT INTO stores(code,name) VALUES ('A','Store') RETURNING id");$a=$this->db->fetchOne("INSERT INTO articles(sku,name) VALUES ('A','Article') RETURNING id");
        $e=$this->db->fetchOne("INSERT INTO stock_movements(type,article_id,destination_store_id,quantity,author_id,reason,reference,comment,created_at,idempotency_key) VALUES ('entry',?,?,20,?,'private-reason','private-reference','private-comment',clock_timestamp()-interval '2 years','private-key') RETURNING id",[$a,$s,$u]);
        $c=$this->db->fetchOne("INSERT INTO stock_movements(type,article_id,source_store_id,quantity,author_id,consumer_id,reference,comment,created_at,idempotency_key) VALUES ('consumption',?,?,2,?,?,'private-reference','copied-personal-comment',clock_timestamp()-interval '2 years','private-consumption') RETURNING id",[$a,$s,$u,$u]);
        $r=$this->db->fetchOne("INSERT INTO stock_movements(type,article_id,destination_store_id,quantity,author_id,related_movement_id,reason,comment,idempotency_key) VALUES ('reversal',?,?,2,?,?,'Recent correction','copied-personal-comment','recent-reversal') RETURNING id",[$a,$s,$u,$c]);
        $n=$this->db->fetchOne("INSERT INTO stock_movements(type,article_id,source_store_id,quantity,author_id,consumer_id,reference,idempotency_key) VALUES ('consumption',?,?,3,?,?,'private-reference','recent-replacement') RETURNING id",[$a,$s,$u,$u]);
        $this->db->executeStatement('INSERT INTO movement_corrections VALUES (?,?,?)',[$c,$r,$n]);$this->db->executeStatement('INSERT INTO stock_levels(article_id,store_id,quantity) VALUES (?,?,17)',[$a,$s]);
        $this->db->executeStatement("INSERT INTO audit_logs(actor_id,action,target_type,target_id,context,created_at) VALUES (?,'stock.entry','stock_movement',?,'{\"name\":\"private-name\"}',clock_timestamp()-interval '2 years')",[$u,$e]);
        $this->db->executeStatement("INSERT INTO runtime_restore_gate VALUES (true,true) ON CONFLICT(singleton) DO UPDATE SET privacy_required=true");
        return compact('u','s','a','e','c','r','n');
    }
    public function testAccountApplicationsAreAnonymizedWithoutErasingActiveAccountIdentity(): void
    {
        $f=$this->fixtures();
        $old=$this->db->fetchOne("INSERT INTO account_requests(first_name,last_name,email,status,user_id,reviewed_by,created_at,reviewed_at,activated_at) VALUES ('Private','Applicant','private@example.test','approved',?,?,clock_timestamp()-interval '2 years',clock_timestamp()-interval '2 years',clock_timestamp()-interval '2 years') RETURNING id",[$f['u'],$f['u']]);
        $pending=$this->db->fetchOne("INSERT INTO account_requests(first_name,last_name,email,created_at) VALUES ('Old','Pending','pending@example.test',clock_timestamp()-interval '2 years') RETURNING id");
        $recent=$this->db->fetchOne("INSERT INTO account_requests(first_name,last_name,email) VALUES ('Recent','Applicant','recent@example.test') RETURNING id");
        $this->runRetention();
        foreach([$old,$pending] as $id) {
            $row=$this->db->fetchAssociative('SELECT * FROM account_requests WHERE id=?',[$id]);
            foreach(['first_name','last_name','email','user_id','reviewed_by'] as $field) {self::assertNull($row[$field]);}
            self::assertNotNull($row['anonymized_at']);
        }
        self::assertSame('expired',$this->db->fetchOne('SELECT status FROM account_requests WHERE id=?',[$pending]));
        self::assertSame('recent@example.test',$this->db->fetchOne('SELECT email FROM account_requests WHERE id=?',[$recent]));
        self::assertSame('private-name',$this->db->fetchOne('SELECT name FROM users WHERE id=?',[$f['u']]));
        $this->runRetention();self::assertSame(3,(int)$this->db->fetchOne('SELECT count(*) FROM account_requests'));
    }

    public function testGroupedReturnsAnonymizeTheEntireConnectedBeneficiaryChain(): void
    {
        $f=$this->fixtures();
        $second=$this->db->fetchOne("INSERT INTO stock_movements(type,article_id,source_store_id,quantity,author_id,consumer_id,reference,idempotency_key) VALUES('consumption',?,?,2,?,?,'Recent private-reference','group-recent-consumption') RETURNING id",[$f['a'],$f['s'],$f['u'],$f['u']]);
        $this->db->beginTransaction();
        $group=$this->db->fetchOne('INSERT INTO stock_return_groups(article_id,store_id,quantity) VALUES(?,?,2) RETURNING id',[$f['a'],$f['s']]);
        foreach([$f['n'],$second] as $index=>$origin) {
            $this->db->executeStatement("INSERT INTO stock_movements(type,article_id,destination_store_id,quantity,author_id,related_movement_id,reference,comment,idempotency_key,return_group_id) VALUES('return',?,?,1,?,?,'Private reference','Private name',?,?)",[$f['a'],$f['s'],$f['u'],$origin,'group-return-'.$index,$group]);
        }
        $this->db->commit();
        $this->db->executeStatement('UPDATE users SET favorite_store_id=? WHERE id=?',[$f['s'],$f['u']]);
        $before=$this->db->fetchAllAssociative('SELECT * FROM stock_ledger ORDER BY movement_id,store_id');
        $this->runRetention();
        self::assertSame(AnonymousIdentity::ID,$this->db->fetchOne('SELECT consumer_id FROM stock_movements WHERE id=?',[$second]));
        self::assertNull($this->db->fetchOne('SELECT reference FROM stock_movements WHERE id=?',[$second]));
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM stock_movements WHERE return_group_id=? AND (reference IS NOT NULL OR comment IS NOT NULL)',[$group]));
        self::assertSame($before,$this->db->fetchAllAssociative('SELECT * FROM stock_ledger ORDER BY movement_id,store_id'));
        self::assertSame($f['s'],$this->db->fetchOne('SELECT favorite_store_id FROM users WHERE id=?',[$f['u']]));
    }
    public function testAtomicErasureChainIntegrityActiveIdentityAndRepeatRun(): void
    {
        $f=$this->fixtures();$ledger=$this->db->fetchAllAssociative('SELECT * FROM stock_ledger ORDER BY movement_id,store_id');$levels=$this->db->fetchAllAssociative('SELECT * FROM stock_levels');
        self::assertTrue((new RestoreGate($this->db))->closed());$this->runRetention();
        self::assertFalse((new RestoreGate($this->db))->closed());self::assertSame($ledger,$this->db->fetchAllAssociative('SELECT * FROM stock_ledger ORDER BY movement_id,store_id'));self::assertSame($levels,$this->db->fetchAllAssociative('SELECT * FROM stock_levels'));
        $old=$this->db->fetchAssociative('SELECT * FROM stock_movements WHERE id=?',[$f['e']]);self::assertSame(AnonymousIdentity::ID,$old['author_id']);self::assertSame('[Effacé]',$old['reason']);self::assertNull($old['reference']);self::assertNull($old['comment']);self::assertNull($old['request_fingerprint']);
        self::assertSame(AnonymousIdentity::ID,$this->db->fetchOne('SELECT consumer_id FROM stock_movements WHERE id=?',[$f['n']]));self::assertSame($f['u'],$this->db->fetchOne('SELECT author_id FROM stock_movements WHERE id=?',[$f['n']]));self::assertNull($this->db->fetchOne('SELECT reference FROM stock_movements WHERE id=?',[$f['n']]));
        self::assertSame('private-name',$this->db->fetchOne('SELECT name FROM users WHERE id=?',[$f['u']]));self::assertSame(1,$this->db->fetchOne('SELECT count(*) FROM movement_corrections'));
        $before=$this->db->fetchAllAssociative('SELECT * FROM stock_movements ORDER BY id');$this->runRetention();self::assertSame($before,$this->db->fetchAllAssociative('SELECT * FROM stock_movements ORDER BY id'));
        self::assertSame(0,$this->db->fetchOne("SELECT count(*) FROM pg_trigger WHERE tgrelid IN (SELECT oid FROM pg_class WHERE relnamespace=?::regnamespace) AND NOT tgisinternal AND tgenabled='D'",[$this->schema]));
        $this->expectException(\Doctrine\DBAL\Exception::class);$this->db->executeStatement('UPDATE stock_movements SET quantity=1 WHERE id=?',[$f['e']]);
    }
    public function testOldConsumptionClearsPersonalCommentsCopiedIntoRecentReversal(): void
    {
        $f=$this->fixtures();self::assertSame('copied-personal-comment',$this->db->fetchOne('SELECT comment FROM stock_movements WHERE id=?',[$f['r']]));
        $this->runRetention();
        self::assertNull($this->db->fetchOne('SELECT comment FROM stock_movements WHERE id=?',[$f['r']]));
        self::assertNull($this->db->fetchOne('SELECT comment FROM stock_movements WHERE id=?',[$f['c']]));
        self::assertSame('Recent correction',$this->db->fetchOne('SELECT reason FROM stock_movements WHERE id=?',[$f['r']]));
    }
    public function testClosingRecentInventoryReleasesDueAccountImmediately(): void { $this->closeRecentCase('inventory'); }
    public function testClosingRecentProposalReleasesDueAccountImmediately(): void { $this->closeRecentCase('proposal'); }
    private function closeRecentCase(string $kind): void
    {
        $f=$this->fixtures();$this->db->executeStatement('UPDATE users SET favorite_store_id=? WHERE id=?',[$f['s'],$f['u']]);$reviewer=$this->db->fetchOne("INSERT INTO users(email,name,password_hash,role_id) VALUES ('reviewer@example.test','Reviewer','hash',1) RETURNING id");
        $this->db->executeStatement('UPDATE users SET active=false WHERE id=?',[$f['u']]);
        $this->admin->executeStatement('ALTER TABLE users DISABLE TRIGGER users_deactivation_clock');
        $this->admin->executeStatement("UPDATE users SET deactivated_at=clock_timestamp()-interval '2 years' WHERE id=?",[$f['u']]);
        $this->admin->executeStatement('ALTER TABLE users ENABLE TRIGGER users_deactivation_clock');
        if($kind==='inventory') {$case=$this->db->fetchOne('INSERT INTO inventories(store_id,created_by) VALUES (?,?) RETURNING id',[$f['s'],$f['u']]);}
        else {$case=$this->db->fetchOne("INSERT INTO article_proposals(operation,payload,proposed_by) VALUES ('create','{\"name\":\"Recent personal text\"}',?) RETURNING id",[$f['u']]);}
        $this->runRetention();self::assertSame('private-name',$this->db->fetchOne('SELECT name FROM users WHERE id=?',[$f['u']]));
        if($kind==='inventory') {$this->db->executeStatement("UPDATE inventories SET status='cancelled',cancelled_at=clock_timestamp() WHERE id=?",[$case]);}
        else {$this->db->executeStatement("UPDATE article_proposals SET status='rejected',reviewed_by=?,reviewed_at=clock_timestamp(),review_comment='Recent comment' WHERE id=?",[$reviewer,$case]);}
        $account=$this->db->fetchAssociative('SELECT name,email,password_hash,anonymized_at FROM users WHERE id=?',[$f['u']]);
        foreach(['name','email','password_hash'] as $field) {self::assertNull($account[$field]);}self::assertNotNull($account['anonymized_at']);self::assertNull($this->db->fetchOne('SELECT favorite_store_id FROM users WHERE id=?',[$f['u']]));
        if($kind==='inventory') {self::assertSame($f['u'],$this->db->fetchOne('SELECT created_by FROM inventories WHERE id=?',[$case]));}
        else {self::assertStringContainsString('Recent personal text',$this->db->fetchOne('SELECT payload::text FROM article_proposals WHERE id=?',[$case]));self::assertSame('Recent comment',$this->db->fetchOne('SELECT review_comment FROM article_proposals WHERE id=?',[$case]));}
    }

    public function testOpenCasesDeferIdentityAndLastClosureErasesImmediately(): void
    {
        $f=$this->fixtures();
        $reviewer=$this->db->fetchOne("INSERT INTO users(email,name,password_hash,role_id) VALUES ('reviewer@example.test','Reviewer','hash',1) RETURNING id");
        $this->db->executeStatement('UPDATE users SET active=false WHERE id=?',[$f['u']]);
        $this->admin->executeStatement('ALTER TABLE users DISABLE TRIGGER users_deactivation_clock');
        $this->admin->executeStatement("UPDATE users SET deactivated_at=clock_timestamp()-interval '2 years' WHERE id=?",[$f['u']]);
        $this->admin->executeStatement('ALTER TABLE users ENABLE TRIGGER users_deactivation_clock');
        $inventory=$this->db->fetchOne("INSERT INTO inventories(store_id,created_by,created_at) VALUES (?,?,clock_timestamp()-interval '2 years') RETURNING id",[$f['s'],$f['u']]);
        $proposal=$this->db->fetchOne("INSERT INTO article_proposals(operation,payload,proposed_by,created_at) VALUES ('create','{\"name\":\"private-name\"}',?,clock_timestamp()-interval '2 years') RETURNING id",[$f['u']]);
        $this->db->executeStatement("INSERT INTO user_creation_requests(actor_id,request_key,request_fingerprint,user_id,response) VALUES (?,'fixture-user-cache',repeat('0',64),?,'{\"name\":\"private-name\"}')",[$reviewer,$f['u']]);
        $this->runRetention();self::assertSame('private-name',$this->db->fetchOne('SELECT name FROM users WHERE id=?',[$f['u']]));
        $this->db->executeStatement("UPDATE article_proposals SET status='rejected',reviewed_by=?,reviewed_at=clock_timestamp(),review_comment='Current review' WHERE id=?",[$reviewer,$proposal]);
        self::assertSame(AnonymousIdentity::ID,$this->db->fetchOne('SELECT proposed_by FROM article_proposals WHERE id=?',[$proposal]));
        self::assertSame('{}',$this->db->fetchOne('SELECT payload::text FROM article_proposals WHERE id=?',[$proposal]));
        self::assertSame('Current review',$this->db->fetchOne('SELECT review_comment FROM article_proposals WHERE id=?',[$proposal]));
        self::assertSame('private-name',$this->db->fetchOne('SELECT name FROM users WHERE id=?',[$f['u']]));
        $this->db->executeStatement("UPDATE inventories SET status='cancelled',cancelled_at=clock_timestamp() WHERE id=?",[$inventory]);
        self::assertSame(AnonymousIdentity::ID,$this->db->fetchOne('SELECT created_by FROM inventories WHERE id=?',[$inventory]));
        self::assertNull($this->db->fetchOne('SELECT name FROM users WHERE id=?',[$f['u']]));
        self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM user_creation_requests'));
    }
    public function testClosingOldInventoryErasesCountedByWithoutChangingQuantitiesOrVersion(): void
    {
        $f=$this->fixtures();$inventory=$this->db->fetchOne("INSERT INTO inventories(store_id,created_by,created_at,status,snapshot_at) VALUES (?,?,clock_timestamp()-interval '2 years','in_progress',clock_timestamp()-interval '2 years') RETURNING id",[$f['s'],$f['u']]);
        $this->db->executeStatement("INSERT INTO inventory_lines(inventory_id,article_id,theoretical_qty,counted_qty,counted_by,counted_at) VALUES (?,?,17,17,?,clock_timestamp()-interval '2 years')",[$inventory,$f['a'],$f['u']]);
        $before=$this->db->fetchAssociative('SELECT theoretical_qty,counted_qty,delta,version FROM inventory_lines WHERE inventory_id=?',[$inventory]);
        $this->db->executeStatement("UPDATE inventories SET status='pending_validation' WHERE id=?",[$inventory]);
        self::assertSame($f['u'],$this->db->fetchOne('SELECT counted_by FROM inventory_lines WHERE inventory_id=?',[$inventory]));
        $this->db->executeStatement("UPDATE inventories SET status='closed',validated_by=?,closed_at=clock_timestamp() WHERE id=?",[$f['u'],$inventory]);
        self::assertSame(AnonymousIdentity::ID,$this->db->fetchOne('SELECT counted_by FROM inventory_lines WHERE inventory_id=?',[$inventory]));
        self::assertSame($before,$this->db->fetchAssociative('SELECT theoretical_qty,counted_qty,delta,version FROM inventory_lines WHERE inventory_id=?',[$inventory]));
        self::assertSame($f['u'],$this->db->fetchOne('SELECT validated_by FROM inventories WHERE id=?',[$inventory]));
    }
    public function testClosureResponseAndReplayDoNotReintroduceErasedCreator(): void
    {
        $f=$this->fixtures();$inventory=$this->db->fetchOne("INSERT INTO inventories(store_id,created_by,created_at) VALUES (?,?,clock_timestamp()-interval '2 years') RETURNING id",[$f['s'],$f['u']]);
        $actor=static::getContainer()->get(\App\Security\StockUserProvider::class)->loadUserByIdentifier('confidential@example.test');
        $workflow=static::getContainer()->get(\App\Inventory\InventoryWorkflow::class);
        $result=$workflow->transition($actor,$inventory,'cancel','privacy-closure-request');
        self::assertSame(AnonymousIdentity::ID,$result['data']['created_by']);
        self::assertStringNotContainsString($f['u'],json_encode($result));
        self::assertSame($result,$workflow->transition($actor,$inventory,'cancel','privacy-closure-request'));
        self::assertStringNotContainsString($f['u'],$this->db->fetchOne('SELECT response::text FROM inventory_requests WHERE inventory_id=?',[$inventory]));
    }
    public function testDeactivationClockSurvivesProfileEditAndResetsOnReactivation(): void
    {
        $f=$this->fixtures();$this->db->executeStatement('UPDATE users SET active=false WHERE id=?',[$f['u']]);
        $at=$this->db->fetchOne('SELECT deactivated_at FROM users WHERE id=?',[$f['u']]);self::assertNotNull($at);
        $this->db->executeStatement("UPDATE users SET name='Another name' WHERE id=?",[$f['u']]);self::assertSame($at,$this->db->fetchOne('SELECT deactivated_at FROM users WHERE id=?',[$f['u']]));
        $this->db->executeStatement('UPDATE users SET active=true WHERE id=?',[$f['u']]);self::assertNull($this->db->fetchOne('SELECT deactivated_at FROM users WHERE id=?',[$f['u']]));
    }
    public function testHttpAndWorkerGateStayClosedUntilSuccessfulTreatment(): void
    {
        $this->fixtures();$subscriber=new \App\EventSubscriber\PrivacyRestoreSubscriber(new RestoreGate($this->db));
        $event=new \Symfony\Component\HttpKernel\Event\RequestEvent($this->createStub(\Symfony\Component\HttpKernel\HttpKernelInterface::class),\Symfony\Component\HttpFoundation\Request::create('/'),\Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST);
        $subscriber->request($event);self::assertSame(503,$event->getResponse()->getStatusCode());self::assertTrue($event->getResponse()->headers->hasCacheControlDirective('no-store'));
        try {$subscriber->worker();self::fail('Worker must remain blocked');} catch (\RuntimeException $error) {self::assertSame('privacy_restore_required',$error->getMessage());}
        $this->runRetention();$subscriber->worker();
        $open=new \Symfony\Component\HttpKernel\Event\RequestEvent($this->createStub(\Symfony\Component\HttpKernel\HttpKernelInterface::class),\Symfony\Component\HttpFoundation\Request::create('/'),\Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST);$subscriber->request($open);self::assertFalse($open->hasResponse());
    }
    public function testNotificationCopiesAudiencesAndQueuedMessagesAreRemovedTogether(): void
    {
        $f=$this->fixtures();
        static::getContainer()->get('messenger.transport.notifications')->setup();
        $campaign=$this->db->fetchOne("INSERT INTO information_campaigns(author_id,request_key,request_fingerprint,subject,message,roles,recipient_count,created_at) VALUES (?,'private-campaign',repeat('0',64),'private-name','private-email',ARRAY['ADMIN'],1,clock_timestamp()-interval '2 years') RETURNING id",[$f['u']]);
        $event=$this->db->fetchOne("INSERT INTO notification_events(type,event_key,recipient_id,information_id,payload) VALUES ('information','private-event',?,?,'{\"message\":\"private-name\"}') RETURNING id",[$f['u'],$campaign]);
        $this->db->executeStatement("INSERT INTO information_campaign_recipients VALUES (?,?,repeat('a',64),?)",[$campaign,$f['u'],$event]);
        $this->db->executeStatement("INSERT INTO notification_delivery_attempts(event_id,attempt_number,result_code) VALUES (?,1,'smtp_delivery_failed')",[$event]);
        $daily=$this->db->fetchOne("INSERT INTO notification_events(type,event_key,recipient_id,payload,created_at) VALUES ('daily_summary','private-daily',?,'{}',clock_timestamp()-interval '2 years') RETURNING id",[$f['u']]);
        $this->db->executeStatement("INSERT INTO daily_notification_runs(recipient_id,local_date,event_id,email_fingerprint,created_at) VALUES (?,(CURRENT_DATE-interval '2 years')::date,?,repeat('b',64),clock_timestamp()-interval '2 years')",[$f['u'],$daily]);
        foreach([$event,'current-message'] as $body) {$this->db->executeStatement("INSERT INTO messenger_messages(body,headers,queue_name,created_at,available_at) VALUES (?,'{}','notifications',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)",[$body]);}
        $this->runRetention();
        foreach(['notification_events','notification_delivery_attempts','information_campaign_recipients','daily_notification_runs'] as $table) {self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM '.$table));}
        self::assertSame('current-message',$this->db->fetchOne('SELECT body FROM messenger_messages'));
        $row=$this->db->fetchAssociative('SELECT author_id,subject,message,request_key,request_fingerprint FROM information_campaigns');
        self::assertSame(AnonymousIdentity::ID,$row['author_id']);self::assertSame('[Effacé]',$row['subject']);self::assertSame('[Effacé]',$row['message']);
        self::assertStringNotContainsString('private-',json_encode($row));
    }
    public function testPersonalReadAndReturnAreRevokedButAuthorizedManagerCanReturn(): void
    {
        $f=$this->fixtures();
        $tech=$this->db->fetchOne("INSERT INTO users(email,name,password_hash,role_id) VALUES ('technician@example.test','Technician','hash',3) RETURNING id");
        $origin=$this->db->fetchOne("INSERT INTO stock_movements(type,article_id,source_store_id,quantity,author_id,consumer_id,created_at,idempotency_key) VALUES ('consumption',?,?,1,?,?,clock_timestamp()-interval '2 years','old-personal-consumption') RETURNING id",[$f['a'],$f['s'],$f['u'],$tech]);
        $this->db->executeStatement('UPDATE stock_levels SET quantity=16 WHERE article_id=? AND store_id=?',[$f['a'],$f['s']]);
        $provider=static::getContainer()->get(\App\Security\StockUserProvider::class);$person=$provider->loadUserByIdentifier('technician@example.test');$admin=$provider->loadUserByIdentifier('confidential@example.test');
        $history=static::getContainer()->get(\App\Consumption\ConsumptionHistory::class);self::assertCount(1,$history->listing($person)['data']);
        $this->runRetention();self::assertSame([],$history->listing($person)['data']);
        $returns=static::getContainer()->get(\App\Stock\StockReturnEngine::class);
        try {$returns->record($person,(object)['consumption_id'=>$origin,'quantity'=>1],'personal-return-after-erasure');self::fail('Personal ownership must no longer exist');} catch(\App\Api\ApiProblem $error) {self::assertSame(404,$error->status);}
        $result=$returns->record($admin,(object)['consumption_id'=>$origin,'quantity'=>1],'admin-return-after-erasure');self::assertSame(1,$result['data']['quantity']);
        self::assertSame(17,(int)$this->db->fetchOne('SELECT quantity FROM stock_levels WHERE article_id=? AND store_id=?',[$f['a'],$f['s']]));
        try {$returns->record($admin,(object)['consumption_id'=>$origin,'quantity'=>1],'admin-excessive-return');self::fail('Return cap must remain');} catch(\App\Api\ApiProblem $error) {self::assertSame('excessive_return',$error->errorCode);}
    }
    public function testAppDatabaseRoleCannotRunTheTreatment(): void
    {
        $this->fixtures();
        try {$this->db->getNativeConnection()->exec($this->sql());self::fail('Operator role required');}
        catch (\PDOException) {$this->db->getNativeConnection()->exec('ROLLBACK');self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM privacy_runs'));self::assertTrue((new RestoreGate($this->db))->closed());}
    }
    public function testFailureRollsBackDataAndTriggerChangesAndKeepsRestoreGate(): void
    {
        $this->fixtures();$before=$this->db->fetchAllAssociative('SELECT * FROM stock_movements ORDER BY id');
        $broken=str_replace('INSERT INTO privacy_runs(counts)',"DO $$ BEGIN RAISE EXCEPTION 'injected_failure'; END $$;\nINSERT INTO privacy_runs(counts)",$this->sql());
        try {$this->admin->getNativeConnection()->exec($broken);self::fail('Expected injected failure');}
        catch (\PDOException) {$this->admin->executeStatement('ROLLBACK');}
        self::assertSame($before,$this->db->fetchAllAssociative('SELECT * FROM stock_movements ORDER BY id'));self::assertTrue((new RestoreGate($this->db))->closed());self::assertSame(0,$this->db->fetchOne('SELECT count(*) FROM privacy_runs'));
        self::assertSame(0,$this->db->fetchOne("SELECT count(*) FROM pg_trigger WHERE tgrelid IN (SELECT oid FROM pg_class WHERE relnamespace=?::regnamespace) AND NOT tgisinternal AND tgenabled='D'",[$this->schema]));
    }
}
