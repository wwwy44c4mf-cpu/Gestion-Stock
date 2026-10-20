<?php

declare(strict_types=1);
namespace App\Tests;
use App\Inventory\InventoryWorkflow;
use App\Security\StockUserProvider;
use App\Stock\StockMovementEngine;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Real PostgreSQL connections prove both orders of the start/movement boundary. */
final class InventoryCountConcurrencyTest extends KernelTestCase
{
    private array $running=[];
    public function testConcurrentCountsAndSubmissionNeverLoseAnUpdate(): void
    {
        self::bootKernel();$db=static::getContainer()->get(Connection::class);$schema='inv01_race_'.bin2hex(random_bytes(6));$db->executeStatement('CREATE SCHEMA '.$schema);$barrier=random_int(1000000,1000000000);
        try {
            $db->executeStatement('SET search_path TO '.$schema);
            foreach(explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) {if(trim($sql)!=='') {$db->executeStatement($sql);}}
            $db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
            foreach(['20261018140000','20261018200000','20261018223000','20261020220000','20261020230000'] as $version) {require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';$class='DoctrineMigrations\\Version'.$version;$migration=new $class($db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) {$db->executeStatement($sql->getStatement());}}
            $db->executeStatement('INSERT INTO users (email,name,password_hash,role_id) VALUES (?,?,?,1)',['admin@example.test','Admin',password_hash('Inventory-race-2026!',PASSWORD_ARGON2ID)]);
            $store=$db->fetchOne("INSERT INTO stores (code,name) VALUES ('A','A') RETURNING id");$other=$db->fetchOne("INSERT INTO stores (code,name) VALUES ('B','B') RETURNING id");$article=$db->fetchOne("INSERT INTO articles (sku,name) VALUES ('RACE','Stock compté') RETURNING id");
            $actor=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('admin@example.test');$workflow=static::getContainer()->get(InventoryWorkflow::class);$engine=static::getContainer()->get(StockMovementEngine::class);
            $body=(object)['article_id'=>$article,'store_id'=>$store,'quantity'=>5,'reason'=>'Initialisation'];$engine->record($actor,'entry',$body,'inventory-race-seed');
            $id=$workflow->create($actor,(object)['store_id'=>$store],'count-race-create')['data']['id'];$workflow->transition($actor,$id,'start','count-race-start');
            $left=['kind'=>'counts','id'=>$id,'key'=>'count-race-left','body'=>['lines'=>[['article_id'=>$article,'counted_qty'=>3,'expected_version'=>0]]]];$right=$left;$right['key']='count-race-right';$right['body']['lines'][0]['counted_qty']=4;
            $results=$this->pair($db,$schema,$barrier,[$left,$right]);$codes=array_column($results,'status');sort($codes);self::assertSame([200,409],$codes);self::assertSame(['stale_inventory_line'],array_values(array_filter(array_column($results,'code'))));self::assertSame(1,(int)$db->fetchOne('SELECT version FROM inventory_lines WHERE inventory_id=?',[$id]));
            $job=$left;$job['key']='count-race-replay';$job['body']['lines'][0]['expected_version']=1;$job['body']['lines'][0]['counted_qty']=2;$results=$this->pair($db,$schema,$barrier,[$job,$job]);self::assertSame([200,200],array_column($results,'status'));self::assertSame($results[0]['result'],$results[1]['result']);self::assertSame(2,(int)$db->fetchOne('SELECT version FROM inventory_lines WHERE inventory_id=?',[$id]));
            // A submission waits for an uncommitted count, then observes its zero as a completed line.
            $db->beginTransaction();$workflow->write($actor,$id,(object)['lines'=>[(object)['article_id'=>$article,'counted_qty'=>0,'expected_version'=>2]]],'count-before-submit');
            $job=$this->launch($schema,0,['kind'=>'submit','id'=>$id,'key'=>'submit-after-count']);$pid=$this->ready($job);$this->waitingForLock($db,$pid);$db->commit();$result=$this->finish($job);self::assertSame(200,$result['status']);self::assertSame('pending_validation',$result['result']['data']['status']);self::assertSame(0,(int)$db->fetchOne('SELECT counted_qty FROM inventory_lines WHERE inventory_id=?',[$id]));
            $workflow->transition($actor,$id,'cancel','count-race-cancel');
            $id=$workflow->create($actor,(object)['store_id'=>$store],'count-race-create-second')['data']['id'];$workflow->transition($actor,$id,'start','count-race-start-second');$workflow->write($actor,$id,(object)['lines'=>[(object)['article_id'=>$article,'counted_qty'=>5,'expected_version'=>0]]],'count-race-fill-second');
            // A late count waits for submission, then fails the live state check without updating its version.
            $db->beginTransaction();$workflow->transition($actor,$id,'submit','submit-before-count');$job=$this->launch($schema,0,['kind'=>'counts','id'=>$id,'key'=>'count-after-submit','body'=>['lines'=>[['article_id'=>$article,'counted_qty'=>8,'expected_version'=>1]]]]);$pid=$this->ready($job);$this->waitingForLock($db,$pid);$db->commit();$result=$this->finish($job);self::assertSame(409,$result['status']);self::assertSame('invalid_inventory_state',$result['code']);self::assertSame(5,(int)$db->fetchOne('SELECT counted_qty FROM inventory_lines WHERE inventory_id=?',[$id]));self::assertSame(1,(int)$db->fetchOne('SELECT version FROM inventory_lines WHERE inventory_id=?',[$id]));
            self::assertSame(5,(int)$db->fetchOne('SELECT quantity FROM stock_levels WHERE store_id=?',[$store]));self::assertSame(1,(int)$db->fetchOne('SELECT count(*) FROM stock_movements'));self::assertSame(0,(int)$db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference<>0'));
        } finally {
            if($db->isTransactionActive()) {$db->rollBack();}$db->executeQuery('SELECT pg_advisory_unlock(?)',[$barrier]);
            foreach($this->running as $job) {if(is_resource($job['process'])) {proc_terminate($job['process']);}foreach($job['pipes'] as $pipe) {if(is_resource($pipe)) {fclose($pipe);}}if(is_resource($job['process'])) {proc_close($job['process']);}}
            $this->running=[];$db->executeStatement('SET search_path TO public');$db->executeStatement('DROP SCHEMA '.$schema.' CASCADE');
        }
    }
    private function pair(Connection $db,string $schema,int $barrier,array $jobs): array
    {
        $db->executeQuery('SELECT pg_advisory_lock(?)',[$barrier]);$processes=[];foreach($jobs as $job) {$processes[]=$this->launch($schema,$barrier,$job);}foreach($processes as $job) {$this->ready($job);}$db->executeQuery('SELECT pg_advisory_unlock(?)',[$barrier]);return array_map($this->finish(...),$processes);
    }
    private function waitingForLock(Connection $db,int $pid): void
    {
        $deadline=microtime(true)+5;do {$db->executeQuery('SELECT pg_stat_clear_snapshot()');if($db->fetchOne("SELECT 1 FROM pg_stat_activity WHERE pid=? AND wait_event_type='Lock'",[$pid])) {self::assertTrue(true);return;}usleep(10000);}while(microtime(true)<$deadline);self::fail('La seconde connexion n’attend pas le verrou transactionnel.');
    }
    private function ready(array $job): int
    {
        $line=fgets($job['pipes'][1]);self::assertIsString($line);self::assertMatchesRegularExpression('/^READY:[0-9]+\n$/D',$line);return (int)substr($line,6);
    }
    private function finish(array $job): array
    {
        $output=stream_get_contents($job['pipes'][1]);$error=stream_get_contents($job['pipes'][2]);fclose($job['pipes'][1]);fclose($job['pipes'][2]);self::assertSame(0,proc_close($job['process']),$error);return json_decode($output,true,flags:JSON_THROW_ON_ERROR);
    }
    private function launch(string $schema,int $barrier,array $job): array
    {
        $script= <<<'WORKER'
require 'vendor/autoload.php';$kernel=new App\Kernel('test',false);$kernel->boot();$container=$kernel->getContainer()->get('test.service_container');$db=$container->get(Doctrine\DBAL\Connection::class);$db->executeStatement('SET search_path TO '.$argv[1]);$db->executeStatement("SET statement_timeout TO '15s'");$job=json_decode($argv[3],false,flags:JSON_THROW_ON_ERROR);echo 'READY:'.$db->fetchOne('SELECT pg_backend_pid()')."\n";fflush(STDOUT);if((int)$argv[2]!==0) {$db->executeQuery('SELECT pg_advisory_lock_shared(?)',[(int)$argv[2]]);}
try {$actor=$container->get(App\Security\StockUserProvider::class)->loadUserByIdentifier('admin@example.test');$result=$job->kind==='counts'?$container->get(App\Inventory\InventoryWorkflow::class)->write($actor,$job->id,$job->body,$job->key):$container->get(App\Inventory\InventoryWorkflow::class)->transition($actor,$job->id,$job->kind,$job->key);echo json_encode(['status'=>$job->kind==='movement'?201:200,'result'=>$result],JSON_THROW_ON_ERROR);}
catch(App\Api\ApiProblem $e) {echo json_encode(['status'=>$e->status,'code'=>$e->errorCode],JSON_THROW_ON_ERROR);}$kernel->shutdown();
WORKER;
        $process=proc_open([PHP_BINARY,'-r',$script,$schema,(string)$barrier,json_encode($job,JSON_THROW_ON_ERROR)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));if(!is_resource($process)) {throw new \RuntimeException('Impossible de lancer la recette concurrente.');}fclose($pipes[0]);unset($pipes[0]);stream_set_timeout($pipes[1],25);stream_set_timeout($pipes[2],25);$result=['process'=>$process,'pipes'=>$pipes];$this->running[]=$result;return $result;
    }
}
