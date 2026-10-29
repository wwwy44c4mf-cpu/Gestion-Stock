<?php

declare(strict_types=1);

namespace App\Tests;

use App\Security\StockUserProvider;
use App\Stock\StockMovementEngine;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Two PHP processes and PostgreSQL connections; no process-local mutexes or mocked stock. */
final class StockReturnConcurrencyTest extends KernelTestCase
{
    private array $running = [];
    public function testReturnLimitsReplayAndCorrectionsSerializeAcrossProcesses(): void
    {
        self::bootKernel(); $db=static::getContainer()->get(Connection::class);$schema='stk07_race_'.bin2hex(random_bytes(6));$barrier=random_int(1000000,1000000000);$db->executeStatement('CREATE SCHEMA '.$schema);
        try {
            $db->executeStatement('SET search_path TO '.$schema);
            foreach(explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) { if(trim($sql)!=='') { $db->executeStatement($sql); } }
            \App\Tests\CatalogDeletionSchema::apply($db);
            $db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
            foreach(['20261018140000','20261018200000','20261018223000'] as $version) { require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';$class='DoctrineMigrations\\Version'.$version;$migration=new $class($db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) { $db->executeStatement($sql->getStatement()); } }
            $hash=password_hash('Stock-race-2026!',PASSWORD_ARGON2ID);$users=[];
            foreach(['admin'=>1,'user1'=>3,'user2'=>3] as $name=>$role) { $users[$name]=$db->fetchOne('INSERT INTO users (email,name,password_hash,role_id) VALUES (?,?,?,?) RETURNING id',[$name.'@example.test',$name,$hash,$role]); }
            $a=$db->fetchOne("INSERT INTO stores (code,name) VALUES ('A','A') RETURNING id");$b=$db->fetchOne("INSERT INTO stores (code,name) VALUES ('B','B') RETURNING id");
            foreach(['user1','user2'] as $name) { $db->insert('user_stores',['user_id'=>$users[$name],'store_id'=>$a]); }
            $article=$db->fetchOne("INSERT INTO articles (sku,name) VALUES ('LAST','Dernière unité') RETURNING id");$fresh=$db->fetchOne("INSERT INTO articles (sku,name) VALUES ('FRESH','Premier solde') RETURNING id");
            $engine=static::getContainer()->get(StockMovementEngine::class);$admin=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('admin@example.test');
            $engine->record($admin,'entry',(object)['article_id'=>$article,'store_id'=>$a,'quantity'=>100,'reason'=>'Initialisation'],'return-race-seed');
            $consume=function(string $key) use ($engine,$admin,$article,$a,$users): array { return $engine->record($admin,'consumption',(object)['article_id'=>$article,'store_id'=>$a,'quantity'=>4,'consumer_id'=>$users['user1']],$key)['data']; };
            $c=$consume('return-limit-consumption');$left=['actor'=>'user1','kind'=>'return','key'=>'return-limit-left','body'=>['consumption_id'=>$c['id'],'quantity'=>3]];$right=$left;$right['actor']='admin';$right['key']='return-limit-right';
            $responses=$this->pair($db,$schema,$barrier,[$left,$right]);$statuses=array_column($responses,'status');sort($statuses);self::assertSame([201,409],$statuses);self::assertSame(['excessive_return'],array_values(array_filter(array_column($responses,'code'))));
            $c=$consume('return-replay-consumption');$job=['actor'=>'user1','kind'=>'return','key'=>'return-replay-race','body'=>['consumption_id'=>$c['id'],'quantity'=>2]];$responses=$this->pair($db,$schema,$barrier,[$job,$job]);self::assertSame([201,201],array_column($responses,'status'));self::assertSame($responses[0]['result'],$responses[1]['result']);
            $c=$consume('return-conflict-consumption');$left=['actor'=>'user1','kind'=>'return','key'=>'return-conflict-race','body'=>['consumption_id'=>$c['id'],'quantity'=>1]];$right=$left;$right['body']['quantity']=2;$responses=$this->pair($db,$schema,$barrier,[$left,$right]);$statuses=array_column($responses,'status');sort($statuses);self::assertSame([201,409],$statuses);self::assertSame(['idempotency_conflict'],array_values(array_filter(array_column($responses,'code'))));
            $c=$consume('consumption-correction-race');$left=['actor'=>'user1','kind'=>'return','key'=>'return-before-correction','body'=>['consumption_id'=>$c['id'],'quantity'=>2]];$right=['actor'=>'admin','kind'=>'correct','id'=>$c['id'],'key'=>'correct-with-return-race','body'=>['quantity'=>3,'reason'=>'Correction concurrente']];$responses=$this->pair($db,$schema,$barrier,[$left,$right]);$statuses=array_column($responses,'status');sort($statuses);self::assertSame([201,409],$statuses);$codes=array_values(array_filter(array_column($responses,'code')));self::assertCount(1,$codes);self::assertContains($codes[0],['consumption_has_returns','consumption_reversed']);
            $c=$consume('return-correction-parent');$user=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('user1@example.test');$r=static::getContainer()->get(\App\Stock\StockReturnEngine::class)->record($user,(object)['consumption_id'=>$c['id'],'quantity'=>2],'return-to-correct')['data'];
            $left=['actor'=>'user1','kind'=>'return','key'=>'new-return-with-correction','body'=>['consumption_id'=>$c['id'],'quantity'=>2]];$right=['actor'=>'admin','kind'=>'correct','id'=>$r['id'],'key'=>'correct-existing-return','body'=>['quantity'=>3,'reason'=>'Trois articles rendus']];$responses=$this->pair($db,$schema,$barrier,[$left,$right]);$statuses=array_column($responses,'status');sort($statuses);self::assertSame([201,409],$statuses);$codes=array_values(array_filter(array_column($responses,'code')));self::assertContains($codes[0],['excessive_return','invalid_return_correction']);
            $c=$consume('return-cancel-parent');$r=static::getContainer()->get(\App\Stock\StockReturnEngine::class)->record($user,(object)['consumption_id'=>$c['id'],'quantity'=>2],'return-to-cancel')['data'];
            $left=['actor'=>'user1','kind'=>'return','key'=>'new-return-with-cancel','body'=>['consumption_id'=>$c['id'],'quantity'=>4]];$right=['actor'=>'admin','kind'=>'correct','id'=>$r['id'],'cancel'=>true,'key'=>'cancel-existing-return','body'=>['reason'=>'Annulation concurrente']];$responses=$this->pair($db,$schema,$barrier,[$left,$right]);self::assertSame(201,$responses[1]['status']);self::assertContains($responses[0]['status'],[201,409]);
            self::assertSame(0,(int)$db->fetchOne("SELECT count(*) FROM stock_movements m WHERE m.type = 'consumption' AND (SELECT coalesce(sum(r.quantity),0) FROM stock_movements r WHERE r.type = 'return' AND r.related_movement_id = m.id AND NOT EXISTS (SELECT 1 FROM stock_movements undo WHERE undo.type = 'reversal' AND undo.related_movement_id = r.id)) > m.quantity"));self::assertSame(0,(int)$db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference <> 0'));self::assertSame((int)$db->fetchOne('SELECT count(*) FROM stock_movements'),(int)$db->fetchOne('SELECT count(*) FROM stock_change_events'));self::assertSame((int)$db->fetchOne('SELECT count(*) FROM stock_movements'),(int)$db->fetchOne('SELECT count(*) FROM audit_logs'));
        } finally {
            $db->executeQuery('SELECT pg_advisory_unlock(?)',[$barrier]);foreach($this->running as $job) { if(is_resource($job['process'])) { proc_terminate($job['process']); }foreach($job['pipes'] as $pipe) { if(is_resource($pipe)) { fclose($pipe); } }if(is_resource($job['process'])) { proc_close($job['process']); } }$this->running=[];$db->executeStatement('SET search_path TO public');$db->executeStatement('DROP SCHEMA '.$schema.' CASCADE');
        }
    }
    public function testGroupedDepositsSerializeWithEachOtherAndLegacyReturns(): void
    {
        self::bootKernel();$db=static::getContainer()->get(Connection::class);$schema='deposit_race_'.bin2hex(random_bytes(5));$barrier=random_int(1000000,1000000000);$db->executeStatement('CREATE SCHEMA '.$schema);
        try {
            $db->executeStatement('SET search_path TO '.$schema);
            foreach(glob(dirname(__DIR__).'/migrations/Version*.php') as $file) {
                require_once $file;$class='DoctrineMigrations\\'.basename($file,'.php');$migration=new $class($db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) { $db->executeStatement($sql->getStatement()); }
            }
            $hash=password_hash('Batch-race-2026!',PASSWORD_ARGON2ID);
            foreach(['admin'=>1,'user1'=>3] as $name=>$role) { $db->insert('users',['email'=>$name.'@example.test','name'=>$name,'password_hash'=>$hash,'role_id'=>$role]); }
            $user=$db->fetchOne("SELECT id FROM users WHERE email='user1@example.test'");$stores=[];
            foreach(['A','B','C'] as $code) { $stores[$code]=$db->fetchOne('INSERT INTO stores(code,name) VALUES(?,?) RETURNING id',[$code,$code]); }
            $admin=static::getContainer()->get(StockUserProvider::class)->loadUserByIdentifier('admin@example.test');$engine=static::getContainer()->get(StockMovementEngine::class);
            $seed=function(string $suffix) use($db,$stores,$admin,$engine,$user): array {
                $article=$db->fetchOne('INSERT INTO articles(sku,name) VALUES(?,?) RETURNING id',[$suffix,$suffix]);$origins=[];
                foreach(['A','B'] as $code) { $engine->record($admin,'entry',(object)['article_id'=>$article,'store_id'=>$stores[$code],'quantity'=>10,'reason'=>'Fixture'],'batch-entry-'.$suffix.$code);$origins[]=$engine->record($admin,'consumption',(object)['article_id'=>$article,'store_id'=>$stores[$code],'consumer_id'=>$user,'quantity'=>4],'batch-consume-'.$suffix.$code)['data']['id']; }
                return [$article,$origins];
            };
            [$article]=$seed('COMPETE');
            $left=['actor'=>'user1','kind'=>'batch','key'=>'batch-limit-left','body'=>['article_id'=>$article,'store_id'=>$stores['C'],'quantity'=>6]];$right=$left;$right['actor']='admin';$right['key']='batch-limit-right';$right['body']['consumer_id']=$user;
            $results=$this->pair($db,$schema,$barrier,[$left,$right]);$statuses=array_column($results,'status');sort($statuses);self::assertSame([201,409],$statuses);self::assertSame(['excessive_return'],array_values(array_filter(array_column($results,'code'))));
            [$article]=$seed('REPLAY');$job=['actor'=>'user1','kind'=>'batch','key'=>'batch-replay-race','body'=>['article_id'=>$article,'store_id'=>$stores['C'],'quantity'=>6]];
            $results=$this->pair($db,$schema,$barrier,[$job,$job]);self::assertSame([201,201],array_column($results,'status'));self::assertSame($results[0]['result'],$results[1]['result']);
            [$article,$origins]=$seed('LEGACY');
            // The competing legacy return targets the oldest consumption. Whichever
            // wins, a whole grouped deposit either commits or leaves no partial rows.
            $left=['actor'=>'user1','kind'=>'batch','key'=>'batch-against-legacy','body'=>['article_id'=>$article,'store_id'=>$stores['C'],'quantity'=>7]];
            $right=['actor'=>'user1','kind'=>'return','key'=>'legacy-against-batch','body'=>['consumption_id'=>$origins[0],'quantity'=>3]];
            $results=$this->pair($db,$schema,$barrier,[$left,$right]);$statuses=array_column($results,'status');sort($statuses);self::assertSame([201,409],$statuses);
            self::assertSame(0,(int)$db->fetchOne("SELECT count(*) FROM stock_movements m WHERE m.type='consumption' AND (SELECT coalesce(sum(r.quantity),0) FROM stock_movements r WHERE r.type='return' AND r.related_movement_id=m.id AND NOT EXISTS(SELECT 1 FROM stock_movements u WHERE u.type='reversal' AND u.related_movement_id=r.id))>m.quantity"));
            self::assertSame(0,(int)$db->fetchOne('SELECT count(*) FROM stock_reconciliation WHERE missing_balance OR difference<>0'));
            self::assertSame(0,(int)$db->fetchOne('SELECT count(*) FROM stock_return_groups g WHERE quantity<>(SELECT sum(quantity) FROM stock_movements WHERE return_group_id=g.id)'));
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
try { $actor=$container->get(App\Security\StockUserProvider::class)->loadUserByIdentifier($job->actor.'@example.test');$result=($job->kind === 'batch' ? $container->get(App\Stock\ReturnBatchEngine::class)->record($actor,$job->body,$job->key) : ($job->kind === 'return' ? $container->get(App\Stock\StockReturnEngine::class)->record($actor,$job->body,$job->key) : $container->get(App\Stock\StockCorrectionEngine::class)->correct($actor,$job->id,$job->body,$job->key,$job->cancel??false)));echo json_encode(['status'=>201,'result'=>$result],JSON_THROW_ON_ERROR); }
catch(App\Api\ApiProblem $error) { echo json_encode(['status'=>$error->status,'code'=>$error->errorCode],JSON_THROW_ON_ERROR); }$kernel->shutdown();
WORKER;
        $process=proc_open([PHP_BINARY,'-r',$script,$schema,(string)$barrier,json_encode($job,JSON_THROW_ON_ERROR)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));if(!is_resource($process)) { throw new \RuntimeException('Could not start test process.'); }fclose($pipes[0]);unset($pipes[0]);stream_set_timeout($pipes[1],25);stream_set_timeout($pipes[2],25);return ['process'=>$process,'pipes'=>$pipes];
    }
}
