<?php

declare(strict_types=1);
namespace App\Tests;
use App\Administration\{AccountRequests,UserAdministration,StoreAdministration,AdminInbox};
use App\Api\ApiProblem;
use App\Security\{StockUser,StockUserProvider};
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
final class AdministrationLifecycleTest extends WebTestCase
{
    private Connection $db;private string $schema;private KernelBrowser $client;private StockUser $admin;
    protected function setUp(): void
    {
        self::ensureKernelShutdown();$this->client=static::createClient();$this->client->disableReboot();
        $this->db=static::getContainer()->get(Connection::class);$this->schema='admin04_'.bin2hex(random_bytes(6));
        $this->db->executeStatement('CREATE SCHEMA '.$this->schema);$this->db->executeStatement('SET search_path TO '.$this->schema);
        $factory=DependencyFactory::fromConnection(new ConfigurationArray(['migrations_paths'=>['DoctrineMigrations'=>dirname(__DIR__).'/migrations'],'all_or_nothing'=>true]),new ExistingConnection($this->db));
        $command=new MigrateCommand($factory);$command->setName('doctrine:migrations:migrate');$input=new ArrayInput([]);$input->setInteractive(false);$output=new BufferedOutput();
        self::assertSame(0,$command->run($input,$output),$output->fetch());$this->db->beginTransaction();
        $this->db->executeStatement("INSERT INTO users(email,name,password_hash,role_id) VALUES ('admin@example.test','Admin','unused',1),('manager@example.test','Manager','unused',2)");
        $this->admin=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('admin@example.test');
        $this->client->setServerParameter('REMOTE_ADDR','10.'.random_int(1,254).'.'.random_int(1,254).'.'.random_int(1,254));
        // Initialize request globals before rendering a simulated mail in this shared test kernel.
        $this->client->request('GET','/api/v1/auth/csrf');
    }
    protected function tearDown(): void
    {
        if(isset($this->db)) {while($this->db->isTransactionActive()) {$this->db->rollBack();}$this->db->executeStatement('SET search_path TO public');$this->db->executeStatement('DROP SCHEMA '.$this->schema.' CASCADE');}parent::tearDown();
    }
    private function user(): string
    {
        return $this->db->fetchOne("INSERT INTO users(email,name,password_hash,role_id) VALUES ('erase@example.test','Private identity','hash',3) RETURNING id");
    }
    private function store(): string {return $this->db->fetchOne("INSERT INTO stores(code,name) VALUES ('REMOVE','Magasin de test') RETURNING id");}
    private function fails(callable $action,string $code): void
    {
        try {$action();self::fail('Expected refusal');}catch(ApiProblem $e) {self::assertSame($code,$e->errorCode);}
    }
    public function testUserDeletionErasesIdentityAndPreservesLedgerAndRevokesAccess(): void
    {
        $id=$this->user();$store=$this->store();$article=$this->db->fetchOne("INSERT INTO articles(sku,name) VALUES ('ERASE','Article') RETURNING id");
        $movement=$this->db->fetchOne("INSERT INTO stock_movements(type,article_id,destination_store_id,quantity,author_id,reason,idempotency_key) VALUES ('entry',?,?,2,?,'Test','erase-test') RETURNING id",[$article,$store,$id]);
        $this->db->executeStatement('INSERT INTO user_stores(user_id,store_id) VALUES (?,?)',[$id,$store]);
        $this->db->executeStatement("INSERT INTO account_requests(first_name,last_name,email,status,user_id,reviewed_at,activated_at) VALUES ('Private','identity','erase@example.test','approved',?,clock_timestamp(),clock_timestamp())",[$id]);
        $this->db->executeStatement("INSERT INTO user_creation_requests(actor_id,request_key,request_fingerprint,user_id,response) VALUES (?,'cached-erase',repeat('0',64),?,?::jsonb)",[$this->admin->id,$id,json_encode(['name'=>'Private identity'])]);
        $before=$this->db->fetchAllAssociative('SELECT * FROM stock_movements');
        $users=static::getContainer()->get(UserAdministration::class);$users->delete($this->admin,$id);$users->delete($this->admin,$id);
        $row=$this->db->fetchAssociative('SELECT * FROM users WHERE id=?',[$id]);
        foreach(['name','email','password_hash','favorite_store_id'] as $field) {self::assertNull($row[$field]);}
        self::assertFalse($row['active']);self::assertNotNull($row['deleted_at']);self::assertNotNull($row['anonymized_at']);
        self::assertSame($before,$this->db->fetchAllAssociative('SELECT * FROM stock_movements'));
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM user_creation_requests'));
        self::assertNull($this->db->fetchOne('SELECT email FROM account_requests'));
        self::assertNotContains($id,array_column($users->listing($this->admin,[])['data'],'id'));
        $this->fails(fn()=>$users->update($this->admin,$id,(object)['active'=>true]),'anonymized_account');
    }
    public function testSelfDeletionAndManagerDeletionAreForbiddenAndOpenCasesBlockDeletion(): void
    {
        $users=static::getContainer()->get(UserAdministration::class);$this->fails(fn()=>$users->delete($this->admin,$this->admin->id),'self_deletion');
        $id=$this->user();$manager=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('manager@example.test');
        $this->fails(fn()=>$users->delete($manager,$id),'permission_denied');
        $store=$this->store();$this->db->executeStatement("INSERT INTO inventories(store_id,created_by,status,snapshot_at) VALUES (?,?,'in_progress',clock_timestamp())",[$store,$id]);
        $this->fails(fn()=>$users->delete($this->admin,$id),'open_user_cases');self::assertSame('Private identity',$this->db->fetchOne('SELECT name FROM users WHERE id=?',[$id]));
    }
    public function testStoreDeletionRequiresZeroStockAndNoOpenInventory(): void
    {
        $id=$this->store();$article=$this->db->fetchOne("INSERT INTO articles(sku,name) VALUES ('STORE','Article') RETURNING id");
        $this->db->executeStatement('INSERT INTO stock_levels(article_id,store_id,quantity) VALUES (?,?,1)',[$article,$id]);
        $service=static::getContainer()->get(StoreAdministration::class);
        $this->fails(fn()=>$service->delete($this->admin,$id),'store_not_empty');
        $this->db->executeStatement('UPDATE stock_levels SET quantity=0 WHERE store_id=?',[$id]);
        $inventory=$this->db->fetchOne("INSERT INTO inventories(store_id,created_by,status,snapshot_at) VALUES (?,?,'in_progress',clock_timestamp()) RETURNING id",[$id,$this->admin->id]);
        $this->fails(fn()=>$service->delete($this->admin,$id),'inventory_in_progress');
        $this->db->executeStatement("UPDATE inventories SET status='cancelled',cancelled_at=clock_timestamp() WHERE id=?",[$inventory]);
        $this->db->executeStatement('UPDATE users SET favorite_store_id=? WHERE id=?',[$id,$this->admin->id]);
        $service->delete($this->admin,$id);$service->delete($this->admin,$id);
        self::assertFalse($this->db->fetchOne('SELECT active FROM stores WHERE id=?',[$id]));self::assertNotNull($this->db->fetchOne('SELECT deleted_at FROM stores WHERE id=?',[$id]));
        self::assertNull($this->db->fetchOne('SELECT favorite_store_id FROM users WHERE id=?',[$this->admin->id]));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM inventories WHERE id=?',[$inventory]));
        $this->fails(fn()=>$service->update($this->admin,$id,(object)['active'=>true]),'store_deleted');
        self::assertNotContains($id,array_column(static::getContainer()->get(\App\Access\AccessDirectory::class)->stores($this->admin)['data'],'id'));
        self::assertNotContains($id,array_column(static::getContainer()->get(\App\Access\AccessDirectory::class)->storeOptions($this->admin),'id'));
        $stocks=static::getContainer()->get(\App\Access\StockDirectory::class);self::assertSame(0,$stocks->listing($this->admin,['store_id'=>$id])['meta']['total']);
        $this->fails(fn()=>$stocks->detail($this->admin,$id,$article),'resource_not_found');
    }
    public function testReadNotificationsArePersonalAndRemainPendingUntilReviewed(): void
    {
        $requests=static::getContainer()->get(AccountRequests::class);$requests->request((object)['first_name'=>'Camille','last_name'=>'Test','email'=>'pending@example.test']);
        $request=$this->db->fetchOne('SELECT id FROM account_requests');
        $proposal=$this->db->fetchOne("INSERT INTO article_proposals(operation,payload,proposed_by) VALUES ('create',?::jsonb,?) RETURNING id",[json_encode(['name'=>'Article proposé']),$this->admin->id]);
        $other=$this->db->fetchOne("INSERT INTO users(email,name,password_hash,role_id) VALUES ('other-admin@example.test','Other','hash',1) RETURNING id");$second=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('other-admin@example.test');
        $inbox=static::getContainer()->get(AdminInbox::class);self::assertSame(2,$inbox->count($this->admin));
        $inbox->read($this->admin,'account_request',$request);$inbox->read($this->admin,'account_request',$request);
        self::assertSame(1,$inbox->count($this->admin));self::assertSame(2,$inbox->count($second));self::assertCount(2,$inbox->items($this->admin));
        $inbox->read($this->admin,'article_proposal',$proposal);self::assertSame(0,$inbox->count($this->admin));
        $requests->decide($this->admin,$request,'reject',new \stdClass());self::assertSame(1,$inbox->count($second));
    }
    public function testBadgeDisappearsAfterOpeningAndNotificationsAreAdminOnly(): void
    {
        static::getContainer()->get(AccountRequests::class)->request((object)['first_name'=>'Camille','last_name'=>'Test','email'=>'pending@example.test']);$id=$this->db->fetchOne('SELECT id FROM account_requests');
        $this->client->loginUser($this->admin);$this->client->request('GET','/administration/comptes');self::assertSelectorTextContains('.notification-count','1');
        $this->client->request('GET','/administration/notifications');self::assertSelectorTextContains('main','Camille Test');
        $this->client->request('GET','/administration/demandes-comptes/'.$id);self::assertResponseIsSuccessful();self::assertSelectorNotExists('.notification-count');
        $this->client->loginUser(static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('manager@example.test'));$this->client->request('GET','/administration/notifications');self::assertResponseStatusCodeSame(403);
    }
    public function testDeleteFormsRequireConfirmationAndCsrf(): void
    {
        $id=$this->user();$store=$this->store();$this->client->loginUser($this->admin);
        foreach(['/administration/comptes/'.$id.'/supprimer','/administration/magasins/'.$store.'/supprimer'] as $path) {
            $this->client->request('POST',$path,['confirm'=>'1']);self::assertResponseStatusCodeSame(403);
            $this->client->request('GET',$path);self::assertResponseIsSuccessful();
        }
        self::assertNull($this->db->fetchOne('SELECT deleted_at FROM users WHERE id=?',[$id]));self::assertNull($this->db->fetchOne('SELECT deleted_at FROM stores WHERE id=?',[$store]));
    }
}
