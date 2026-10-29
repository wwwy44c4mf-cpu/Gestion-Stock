<?php

declare(strict_types=1);

namespace App\Tests;

use App\Administration\CatalogAdministration;
use App\Security\StockUserProvider;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CatalogConcurrencyTest extends KernelTestCase
{
    public function testConcurrentReplaysVersionsAndCategoryCyclesAreSafe(): void
    {
        self::bootKernel();$db=static::getContainer()->get(Connection::class);
        $schema='art01_race_'.bin2hex(random_bytes(6));$jobs=[];$lock=random_int(1000000,1000000000);
        $db->executeStatement('CREATE SCHEMA '.$schema);
        try {
            $db->executeStatement('SET search_path TO '.$schema);
            foreach(explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) { if(trim($sql)!=='') { $db->executeStatement($sql); } }
            \App\Tests\CatalogDeletionSchema::apply($db);
            $db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
            require_once dirname(__DIR__).'/migrations/Version20261018140000.php';$migration=new \DoctrineMigrations\Version20261018140000($db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) { $db->executeStatement($sql->getStatement()); }
            $db->executeStatement("INSERT INTO users (email,name,password_hash,role_id) VALUES ('race@example.test','Race',?,1)",[password_hash('Catalog-race-2026!',PASSWORD_ARGON2ID)]);
            $actor=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('race@example.test');$service=static::getContainer()->get(CatalogAdministration::class);
            $versioned=$service->create($actor,'article',(object)['sku'=>'VERSIONED','name'=>'Avant'],'versioned-create-key')['data']['id'];
            $a=$service->create($actor,'category',(object)['name'=>'Catégorie A'],'category-a-create')['data']['id'];$b=$service->create($actor,'category',(object)['name'=>'Catégorie B'],'category-b-create')['data']['id'];
            foreach(['replay','duplicate','version','cycle','remove'] as $mode) {
                $db->executeQuery('SELECT pg_advisory_lock(?)',[$lock]);
                foreach([1,2] as $number) {
                    $key=$mode==='replay'?'catalog-replay-key':'catalog-'.$mode.'-'.$number;
                    $target=match($mode) { 'version','remove'=>$versioned,'cycle'=>$number===1?$a:$b,default=>'RACE-'.$mode };
                    $other=$mode==='cycle'?($number===1?$b:$a):(string)$number;
                    $jobs[]=$this->start($schema,$lock,$mode,$key,$target,$other);
                }
                foreach($jobs as $job) { self::assertSame("READY\n",fgets($job['pipes'][1])); }
                $db->executeQuery('SELECT pg_advisory_unlock(?)',[$lock]);$responses=[];
                foreach($jobs as $job) {
                    $output=stream_get_contents($job['pipes'][1]);$error=stream_get_contents($job['pipes'][2]);fclose($job['pipes'][1]);fclose($job['pipes'][2]);self::assertSame(0,proc_close($job['process']),$error);$responses[]=json_decode($output,true,flags:JSON_THROW_ON_ERROR);
                }
                if ($mode === 'remove') { self::assertSame(1,(int)$db->fetchOne("SELECT count(*) FROM audit_logs WHERE action='article.deleted'")); }
                $jobs=[];$statuses=array_column($responses,'status');sort($statuses);
                self::assertSame(match($mode) { 'replay'=>[201,201],'duplicate'=>[201,409],'version'=>[200,409],'cycle'=>[200,422],'remove'=>[200,200] },$statuses);
                if($mode==='replay') { self::assertEquals($responses[0]['data'],$responses[1]['data']); }
            }
            self::assertSame(3,(int)$db->fetchOne('SELECT count(*) FROM articles'));self::assertSame(2,(int)$db->fetchOne('SELECT count(*) FROM categories'));self::assertSame(5,(int)$db->fetchOne('SELECT count(*) FROM catalog_creation_requests'));self::assertSame(8,(int)$db->fetchOne('SELECT count(*) FROM audit_logs'));self::assertSame(3,(int)$db->fetchOne('SELECT version FROM articles WHERE id = ?',[$versioned]));
            self::assertSame(1,(int)$db->fetchOne('SELECT count(*) FROM categories WHERE parent_id IS NOT NULL'));
        } finally {
            $db->executeQuery('SELECT pg_advisory_unlock(?)',[$lock]);foreach($jobs as $job) { if(is_resource($job['process'])) { proc_terminate($job['process']); }foreach($job['pipes'] as $pipe) { if(is_resource($pipe)) { fclose($pipe); } }if(is_resource($job['process'])) { proc_close($job['process']); } }
            $db->executeStatement('SET search_path TO public');$db->executeStatement('DROP SCHEMA '.$schema.' CASCADE');
        }
    }
    public function testRemovalSerializesWithPositiveStockMovementWithoutLosingHistory(): void
    {
        self::bootKernel(); $db = static::getContainer()->get(Connection::class);
        $schema = 'cat02_race_'.bin2hex(random_bytes(6)); $jobs = [];
        $db->executeStatement('CREATE SCHEMA '.$schema);
        try {
            $db->executeStatement('SET search_path TO '.$schema);
            foreach (explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) { if (trim($sql)!=='') { $db->executeStatement($sql); } }
            CatalogDeletionSchema::apply($db);
            $db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
            foreach (['20261018140000','20261018200000'] as $version) {
                require_once dirname(__DIR__).'/migrations/Version'.$version.'.php'; $class='DoctrineMigrations\\Version'.$version;
                $migration=new $class($db,new \Psr\Log\NullLogger()); $migration->up(new \Doctrine\DBAL\Schema\Schema());
                foreach ($migration->getSql() as $sql) { $db->executeStatement($sql->getStatement()); }
            }
            $db->executeStatement("INSERT INTO users (email,name,password_hash,role_id) VALUES ('race@example.test','Race',?,1)",[password_hash('Catalog-race-2026!',PASSWORD_ARGON2ID)]);
            $store=$db->fetchOne("INSERT INTO stores (code,name) VALUES ('A','Fixture') RETURNING id");
            $actor=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('race@example.test');
            $catalog=static::getContainer()->get(CatalogAdministration::class);
            $engine=static::getContainer()->get(\App\Stock\StockMovementEngine::class);
            foreach (['delete-first','entry-first'] as $scenario) {
                $article=$catalog->create($actor,'article',(object)['sku'=>$scenario,'name'=>$scenario],'create-'.$scenario)['data']['id'];
                $body=(object)['article_id'=>$article,'store_id'=>$store,'quantity'=>1,'reason'=>'Fixture entry'];
                if ($scenario==='delete-first') { $engine->record($actor,'entry',$body,'initial-'.$scenario); }
                $db->beginTransaction();
                if ($scenario==='delete-first') { $catalog->removeArticle($actor,$article,1); }
                else { $engine->record($actor,'entry',$body,'first-'.$scenario); }
                $mode=$scenario==='delete-first'?'entry':'remove';
                $job=$this->start($schema,random_int(1000000,1000000000),$mode,'racing-'.$scenario,$article,$store); $jobs[]=$job;
                self::assertSame("READY\n",fgets($job['pipes'][1]));
                $waiting=false; $deadline=microtime(true)+10;
                while (microtime(true)<$deadline) {
                    $db->executeQuery('SELECT pg_stat_clear_snapshot()');
                    if ($db->fetchOne("SELECT 1 FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'",['cat02:'.$schema.':'.$mode])) { $waiting=true; break; }
                    usleep(20000);
                }
                self::assertTrue($waiting,'The competing write must wait for the catalogue/stock guard');
                $db->commit();
                $output=stream_get_contents($job['pipes'][1]);$error=stream_get_contents($job['pipes'][2]);
                fclose($job['pipes'][1]);fclose($job['pipes'][2]);self::assertSame(0,proc_close($job['process']),$error);$jobs=[];
                $result=json_decode($output,true,flags:JSON_THROW_ON_ERROR);
                self::assertSame($scenario==='delete-first'?409:200,$result['status']);
                self::assertNotNull($db->fetchOne('SELECT deleted_at FROM articles WHERE id=?',[$article]));
                self::assertSame(1,(int)$db->fetchOne('SELECT quantity FROM stock_levels WHERE article_id=? AND store_id=?',[$article,$store]));
                self::assertSame(1,(int)$db->fetchOne('SELECT count(*) FROM stock_movements WHERE article_id=?',[$article]));
            }
        } finally {
            if ($db->isTransactionActive()) { $db->rollBack(); }
            foreach ($jobs as $job) { foreach ($job['pipes'] as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }proc_terminate($job['process']);proc_close($job['process']); }
            $db->executeStatement('SET search_path TO public');$db->executeStatement('DROP SCHEMA '.$schema.' CASCADE');
        }
    }
    private function start(string $schema,int $lock,string $mode,string $key,string $target,string $other): array
    {
        $script= <<<'WORKER'
require 'vendor/autoload.php';
$kernel=new App\Kernel('test',false);$kernel->boot();$container=$kernel->getContainer()->get('test.service_container');$db=$container->get(Doctrine\DBAL\Connection::class);$db->executeStatement('SET search_path TO '.$argv[1]);$db->executeQuery("SELECT set_config('application_name',?,false)",['cat02:'.$argv[1].':'.$argv[3]]);
fwrite(STDOUT,"READY\n");fflush(STDOUT);$db->executeQuery('SELECT pg_advisory_lock_shared(?)',[(int)$argv[2]]);
try {
    $actor=$container->get(App\Security\StockUserProvider::class)->loadUserByIdentifier('race@example.test');$service=$container->get(App\Administration\CatalogAdministration::class);
    if($argv[3]==='version') { $result=$service->update($actor,'article',$argv[5],(object)['version'=>1,'name'=>'Concurrent '.$argv[6]]);$status=200; }
    elseif($argv[3]==='entry') { $result=$container->get(App\Stock\StockMovementEngine::class)->record($actor,'entry',(object)['article_id'=>$argv[5],'store_id'=>$argv[6],'quantity'=>1,'reason'=>'Concurrent fixture entry'],$argv[4]);$status=201; }
    elseif($argv[3]==='remove') { $version=(int)$db->fetchOne('SELECT version FROM articles WHERE id=?',[$argv[5]]);$result=$service->removeArticle($actor,$argv[5],$version);$status=200; }
    elseif($argv[3]==='cycle') { $result=$service->update($actor,'category',$argv[5],(object)['parent_id'=>$argv[6]]);$status=200; }
    else { $result=$service->create($actor,'article',(object)['sku'=>$argv[5],'name'=>'Article concurrent'],$argv[4]);$status=201; }
    echo json_encode(['status'=>$status,'data'=>$result],JSON_THROW_ON_ERROR);
} catch(App\Api\ApiProblem $error) { echo json_encode(['status'=>$error->status,'code'=>$error->errorCode],JSON_THROW_ON_ERROR); }
$kernel->shutdown();
WORKER;
        $process=proc_open([PHP_BINARY,'-r',$script,$schema,(string)$lock,$mode,$key,$target,$other],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));if(!is_resource($process)) { throw new \RuntimeException('Could not start test process.'); }fclose($pipes[0]);unset($pipes[0]);stream_set_timeout($pipes[1],20);stream_set_timeout($pipes[2],20);return ['process'=>$process,'pipes'=>$pipes];
    }
}
