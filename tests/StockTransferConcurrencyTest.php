<?php

declare(strict_types=1);

namespace App\Tests;

use App\Security\StockUserProvider;
use App\Stock\StockMovementEngine;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Two PHP processes and PostgreSQL connections; no process-local mutexes or mocked stock. */
final class StockTransferConcurrencyTest extends KernelTestCase
{
    private array $running = [];
    public function testLastTransferUnitsConcurrentReplaysAndOppositeDirections(): void
    {
        self::bootKernel(); $db=static::getContainer()->get(Connection::class);$schema='stk06_race_'.bin2hex(random_bytes(6));$barrier=random_int(1000000,1000000000);$db->executeStatement('CREATE SCHEMA '.$schema);
        try {
            $db->executeStatement('SET search_path TO '.$schema);
            foreach(explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) { if(trim($sql)!=='') { $db->executeStatement($sql); } }
            $db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
            foreach(['20261018140000','20261018200000'] as $version) { require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';$class='DoctrineMigrations\\Version'.$version;$migration=new $class($db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) { $db->executeStatement($sql->getStatement()); } }
            $hash=password_hash('Stock-race-2026!',PASSWORD_ARGON2ID);$users=[];
            foreach(['admin'=>1,'user1'=>3,'user2'=>3] as $name=>$role) { $users[$name]=$db->fetchOne('INSERT INTO users (email,name,password_hash,role_id) VALUES (?,?,?,?) RETURNING id',[$name.'@example.test',$name,$hash,$role]); }
            $a=$db->fetchOne("INSERT INTO stores (code,name) VALUES ('A','A') RETURNING id");$b=$db->fetchOne("INSERT INTO stores (code,name) VALUES ('B','B') RETURNING id");
            foreach(['user1','user2'] as $name) { $db->insert('user_stores',['user_id'=>$users[$name],'store_id'=>$a]); }
            $article=$db->fetchOne("INSERT INTO articles (sku,name) VALUES ('LAST','Dernière unité') RETURNING id");$fresh=$db->fetchOne("INSERT INTO articles (sku,name) VALUES ('FRESH','Premier solde') RETURNING id");
            $engine=static::getContainer()->get(StockMovementEngine::class);$admin=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('admin@example.test');
            $engine->record($admin,'entry',(object)['article_id'=>$article,'store_id'=>$a,'quantity'=>3,'reason'=>'Initialisation'],'transfer-race-seed');
            $left=['actor'=>'admin','operation'=>'transfer','key'=>'last-transfer-left','body'=>['article_id'=>$article,'source_store_id'=>$a,'destination_store_id'=>$b,'quantity'=>3]];$right=$left;$right['key']='last-transfer-right';
            $responses=$this->pair($db,$schema,$barrier,[$left,$right]);$statuses=array_column($responses,'status');sort($statuses);self::assertSame([201,409],$statuses);self::assertSame(['insufficient_stock'],array_values(array_filter(array_column($responses,'code'))));self::assertSame(0,(int)$db->fetchOne('SELECT quantity FROM stock_levels WHERE article_id = ? AND store_id = ?',[$article,$a]));self::assertSame(3,(int)$db->fetchOne('SELECT quantity FROM stock_levels WHERE article_id = ? AND store_id = ?',[$article,$b]));
            $engine->record($admin,'entry',(object)['article_id'=>$article,'store_id'=>$a,'quantity'=>4,'reason'=>'Réapprovisionnement'],'replay-transfer-seed');$job=$left;$job['key']='transfer-replay-race';$job['body']['quantity']=2;
            $responses=$this->pair($db,$schema,$barrier,[$job,$job]);self::assertSame([201,201],array_column($responses,'status'));self::assertSame($responses[0]['result'],$responses[1]['result']);self::assertSame(2,(int)$db->fetchOne('SELECT quantity FROM stock_levels WHERE article_id = ? AND store_id = ?',[$article,$a]));self::assertSame(5,(int)$db->fetchOne('SELECT quantity FROM stock_levels WHERE article_id = ? AND store_id = ?',[$article,$b]));
            $engine->record($admin,'entry',(object)['article_id'=>$fresh,'store_id'=>$a,'quantity'=>5,'reason'=>'Autre article'],'conflict-transfer-seed');$left['key']='transfer-conflict-race';$left['body']['article_id']=$fresh;$left['body']['quantity']=1;$right=$left;$right['body']['quantity']=2;
            $responses=$this->pair($db,$schema,$barrier,[$left,$right]);$statuses=array_column($responses,'status');sort($statuses);self::assertSame([201,409],$statuses);self::assertSame(['idempotency_conflict'],array_values(array_filter(array_column($responses,'code'))));self::assertSame('5',$db->fetchOne('SELECT sum(quantity)::text FROM stock_levels WHERE article_id = ?',[$fresh]));
            $before=$db->fetchFirstColumn('SELECT quantity::text FROM stock_levels WHERE article_id = ? ORDER BY store_id',[$article]);$left=['actor'=>'admin','operation'=>'transfer','key'=>'opposite-transfer-left','body'=>['article_id'=>$article,'source_store_id'=>$a,'destination_store_id'=>$b,'quantity'=>1]];$right=$left;$right['key']='opposite-transfer-right';$right['body']['source_store_id']=$b;$right['body']['destination_store_id']=$a;
            $responses=$this->pair($db,$schema,$barrier,[$left,$right]);self::assertSame([201,201],array_column($responses,'status'));self::assertSame($before,$db->fetchFirstColumn('SELECT quantity::text FROM stock_levels WHERE article_id = ? ORDER BY store_id',[$article]));
            self::assertSame(8,(int)$db->fetchOne('SELECT count(*) FROM stock_movements'));self::assertSame(8,(int)$db->fetchOne('SELECT count(*) FROM stock_change_events'));self::assertSame(8,(int)$db->fetchOne('SELECT count(*) FROM audit_logs'));self::assertSame(0,(int)$db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference <> 0'));
        } finally {
            $db->executeQuery('SELECT pg_advisory_unlock(?)',[$barrier]);foreach($this->running as $job) { if(is_resource($job['process'])) { proc_terminate($job['process']); }foreach($job['pipes'] as $pipe) { if(is_resource($pipe)) { fclose($pipe); } }if(is_resource($job['process'])) { proc_close($job['process']); } }$this->running=[];$db->executeStatement('SET search_path TO public');$db->executeStatement('DROP SCHEMA '.$schema.' CASCADE');
        }
    }
    private function pair(Connection $db,string $schema,int $barrier,array $jobs): array
    {
        $db->executeQuery('SELECT pg_advisory_lock(?)',[$barrier]);foreach($jobs as $job) { $this->running[]=$this->start($schema,$barrier,$job); }foreach($this->running as $job) { self::assertSame("READY\n",fgets($job['pipes'][1])); }$db->executeQuery('SELECT pg_advisory_unlock(?)',[$barrier]);$responses=[];
        foreach($this->running as $job) { $output=stream_get_contents($job['pipes'][1]);$error=stream_get_contents($job['pipes'][2]);fclose($job['pipes'][1]);fclose($job['pipes'][2]);self::assertSame(0,proc_close($job['process']),$error);$responses[]=json_decode($output,true,flags:JSON_THROW_ON_ERROR); }$this->running=[];return $responses;
    }
    private function start(string $schema,int $barrier,array $job): array
    {
        $script= <<<'WORKER'
require 'vendor/autoload.php';$kernel=new App\Kernel('test',false);$kernel->boot();$container=$kernel->getContainer()->get('test.service_container');$db=$container->get(Doctrine\DBAL\Connection::class);$db->executeStatement('SET search_path TO '.$argv[1]);$db->executeStatement("SET statement_timeout TO '15s'");$job=json_decode($argv[3],false,flags:JSON_THROW_ON_ERROR);fwrite(STDOUT,"READY\n");fflush(STDOUT);$db->executeQuery('SELECT pg_advisory_lock_shared(?)',[(int)$argv[2]]);
try { $actor=$container->get(App\Security\StockUserProvider::class)->loadUserByIdentifier($job->actor.'@example.test');$result=$container->get(App\Stock\StockMovementEngine::class)->record($actor,$job->operation,$job->body,$job->key);echo json_encode(['status'=>201,'result'=>$result],JSON_THROW_ON_ERROR); }
catch(App\Api\ApiProblem $error) { echo json_encode(['status'=>$error->status,'code'=>$error->errorCode],JSON_THROW_ON_ERROR); }$kernel->shutdown();
WORKER;
        $process=proc_open([PHP_BINARY,'-r',$script,$schema,(string)$barrier,json_encode($job,JSON_THROW_ON_ERROR)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));if(!is_resource($process)) { throw new \RuntimeException('Could not start test process.'); }fclose($pipes[0]);unset($pipes[0]);stream_set_timeout($pipes[1],25);stream_set_timeout($pipes[2],25);return ['process'=>$process,'pipes'=>$pipes];
    }
}
