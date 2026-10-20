<?php

declare(strict_types=1);
namespace App\Tests;
use App\Inventory\InventoryWorkflow;
use App\Security\StockUserProvider;
use App\Stock\StockMovementEngine;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Real PostgreSQL connections prove both orders of the start/movement boundary. */
final class InventoryConcurrencyTest extends KernelTestCase
{
    private array $running=[];
    public function testConcurrentStartsReplaysAndBothOrdersOfTheFreezeBoundary(): void
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
            $a=$workflow->create($actor,(object)['store_id'=>$store],'inventory-race-draft-a')['data']['id'];$b=$workflow->create($actor,(object)['store_id'=>$store],'inventory-race-draft-b')['data']['id'];
            $jobs=[['kind'=>'start','id'=>$a,'key'=>'race-start-a'],['kind'=>'start','id'=>$b,'key'=>'race-start-b']];$results=$this->pair($db,$schema,$barrier,$jobs);$codes=array_column($results,'status');sort($codes);self::assertSame([200,409],$codes);self::assertSame(['inventory_in_progress'],array_values(array_filter(array_column($results,'code'))));
            $active=$db->fetchOne("SELECT id FROM inventories WHERE status='in_progress'");self::assertSame(1,(int)$db->fetchOne('SELECT count(*) FROM inventory_lines'));$workflow->transition($actor,$active,'cancel','inventory-race-cancel-active');
            $draft=$active===$a?$b:$a;$job=['kind'=>'start','id'=>$draft,'key'=>'race-start-replay'];$results=$this->pair($db,$schema,$barrier,[$job,$job]);self::assertSame([200,200],array_column($results,'status'));self::assertSame($results[0]['result'],$results[1]['result']);self::assertSame(1,(int)$db->fetchOne("SELECT count(*) FROM audit_logs WHERE target_id=? AND action='inventory.start'",[$draft]));$workflow->transition($actor,$draft,'cancel','inventory-race-cancel-replay');

            // An uncommitted movement holds the shared guard: start must wait and include its committed balance.
            $draft=$workflow->create($actor,(object)['store_id'=>$store],'inventory-race-draft-c')['data']['id'];$db->beginTransaction();$body->quantity=2;$engine->record($actor,'entry',$body,'inventory-before-start');
            $job=$this->launch($schema,0,['kind'=>'start','id'=>$draft,'key'=>'inventory-start-after-write']);$pid=$this->ready($job);$this->waitingForLock($db,$pid);self::assertNull($db->fetchOne('SELECT snapshot_at FROM inventories WHERE id=?',[$draft]));$db->commit();$result=$this->finish($job);self::assertSame(200,$result['status']);self::assertSame(7,(int)$db->fetchOne('SELECT theoretical_qty FROM inventory_lines WHERE inventory_id=?',[$draft]));$workflow->transition($actor,$draft,'cancel','inventory-race-cancel-c');

            // An uncommitted start holds the incompatible guard: a transfer must wait, then see the freeze.
            $draft=$workflow->create($actor,(object)['store_id'=>$store],'inventory-race-draft-d')['data']['id'];$db->beginTransaction();$workflow->transition($actor,$draft,'start','inventory-freeze-before-write');
            $job=$this->launch($schema,0,['kind'=>'movement','operation'=>'transfer','body'=>['article_id'=>$article,'source_store_id'=>$store,'destination_store_id'=>$other,'quantity'=>1],'key'=>'inventory-transfer-after-freeze']);$pid=$this->ready($job);$this->waitingForLock($db,$pid);$db->commit();$result=$this->finish($job);self::assertSame(409,$result['status']);self::assertSame('inventory_in_progress',$result['code']);self::assertSame(7,(int)$db->fetchOne('SELECT quantity FROM stock_levels WHERE store_id=?',[$store]));self::assertFalse($db->fetchOne('SELECT 1 FROM stock_levels WHERE store_id=?',[$other]));
            self::assertSame(2,(int)$db->fetchOne('SELECT count(*) FROM stock_movements'));self::assertSame(0,(int)$db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference<>0'));
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
try {$actor=$container->get(App\Security\StockUserProvider::class)->loadUserByIdentifier('admin@example.test');$result=$job->kind==='movement'?$container->get(App\Stock\StockMovementEngine::class)->record($actor,$job->operation,$job->body,$job->key):$container->get(App\Inventory\InventoryWorkflow::class)->transition($actor,$job->id,$job->kind,$job->key);echo json_encode(['status'=>$job->kind==='movement'?201:200,'result'=>$result],JSON_THROW_ON_ERROR);}
catch(App\Api\ApiProblem $e) {echo json_encode(['status'=>$e->status,'code'=>$e->errorCode],JSON_THROW_ON_ERROR);}$kernel->shutdown();
WORKER;
        $process=proc_open([PHP_BINARY,'-r',$script,$schema,(string)$barrier,json_encode($job,JSON_THROW_ON_ERROR)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));if(!is_resource($process)) {throw new \RuntimeException('Impossible de lancer la recette concurrente.');}fclose($pipes[0]);unset($pipes[0]);stream_set_timeout($pipes[1],25);stream_set_timeout($pipes[2],25);$result=['process'=>$process,'pipes'=>$pipes];$this->running[]=$result;return $result;
    }
}
