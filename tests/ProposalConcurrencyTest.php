<?php

declare(strict_types=1);

namespace App\Tests;

use App\Administration\ProposalAdministration;
use App\Security\StockUserProvider;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProposalConcurrencyTest extends KernelTestCase
{
    private array $running=[];
    public function testConcurrentSubmissionsDecisionsAndDuplicatePublications(): void
    {
        self::bootKernel();$db=static::getContainer()->get(Connection::class);$schema='art02_race_'.bin2hex(random_bytes(6));$lock=random_int(1000000,1000000000);$db->executeStatement('CREATE SCHEMA '.$schema);
        try {
            $db->executeStatement('SET search_path TO '.$schema);foreach(explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) { if(trim($sql)!=='') { $db->executeStatement($sql); } }$db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
            foreach(['20261018140000','20261018150000'] as $version) { require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';$class='DoctrineMigrations\\Version'.$version;$migration=new $class($db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) { $db->executeStatement($sql->getStatement()); } }
            $hash=password_hash('Proposal-race-2026!',PASSWORD_ARGON2ID);foreach(['admin'=>1,'admin2'=>1,'manager'=>2,'manager2'=>2] as $name=>$role) { $db->executeStatement('INSERT INTO users (email,name,password_hash,role_id) VALUES (?,?,?,?)',[$name.'@example.test',$name,$hash,$role]); }
            $job=['actor'=>'manager','mode'=>'submit','key'=>'concurrent-submit-key','body'=>['operation'=>'create','payload'=>['sku'=>'RACE-FIRST','name'=>'Premier article']]];
            $responses=$this->pair($db,$schema,$lock,[$job,$job]);self::assertSame([201,201],array_column($responses,'status'));self::assertEquals($responses[0]['result'],$responses[1]['result']);$first=$responses[0]['result']['data']['id'];
            $job=['actor'=>'admin','mode'=>'review','id'=>$first,'key'=>'concurrent-review-key','body'=>['decision'=>'approved']];$responses=$this->pair($db,$schema,$lock,[$job,$job]);self::assertSame([200,200],array_column($responses,'status'));self::assertEquals($responses[0]['result'],$responses[1]['result']);$article=$responses[0]['result']['data']['published_article_id'];
            $service=static::getContainer()->get(ProposalAdministration::class);$provider=static::getContainer()->get(StockUserProvider::class);$manager=$provider->loadUserByIdentifier('manager@example.test');$manager2=$provider->loadUserByIdentifier('manager2@example.test');
            $second=$service->submit($manager,(object)['operation'=>'update','article_id'=>$article,'base_version'=>1,'payload'=>(object)['name'=>'Modification concurrente']],'update-proposal-key')['data']['id'];
            $jobs=[];foreach(['admin','admin2'] as $actor) { $jobs[]=['actor'=>$actor,'mode'=>'review','id'=>$second,'key'=>'review-'.$actor.'-update','body'=>['decision'=>'approved']]; }$responses=$this->pair($db,$schema,$lock,$jobs);$statuses=array_column($responses,'status');sort($statuses);self::assertSame([200,409],$statuses);self::assertSame(2,(int)$db->fetchOne('SELECT version FROM articles WHERE id = ?',[$article]));
            $third=$service->submit($manager,(object)['operation'=>'create','payload'=>(object)['sku'=>'DUPLICATE','name'=>'Candidat A']],'candidate-a-key')['data']['id'];$fourth=$service->submit($manager2,(object)['operation'=>'create','payload'=>(object)['sku'=>'DUPLICATE','name'=>'Candidat B']],'candidate-b-key')['data']['id'];
            $jobs=[];foreach([$third,$fourth] as $id) { $jobs[]=['actor'=>'admin','mode'=>'review','id'=>$id,'key'=>'publish-'.$id,'body'=>['decision'=>'approved']]; }$responses=$this->pair($db,$schema,$lock,$jobs);$statuses=array_column($responses,'status');sort($statuses);self::assertSame([200,409],$statuses);
            self::assertSame(2,(int)$db->fetchOne('SELECT count(*) FROM articles'));self::assertSame(4,(int)$db->fetchOne('SELECT count(*) FROM article_proposals'));self::assertSame(3,(int)$db->fetchOne("SELECT count(*) FROM article_proposals WHERE status='approved'"));self::assertSame(1,(int)$db->fetchOne("SELECT count(*) FROM article_proposals WHERE status='pending'"));self::assertSame(7,(int)$db->fetchOne('SELECT count(*) FROM proposal_requests'));self::assertSame(10,(int)$db->fetchOne('SELECT count(*) FROM audit_logs'));
        } finally {
            $db->executeQuery('SELECT pg_advisory_unlock(?)',[$lock]);foreach($this->running as $job) { if(is_resource($job['process'])) { proc_terminate($job['process']); }foreach($job['pipes'] as $pipe) { if(is_resource($pipe)) { fclose($pipe); } }if(is_resource($job['process'])) { proc_close($job['process']); } }$this->running=[];$db->executeStatement('SET search_path TO public');$db->executeStatement('DROP SCHEMA '.$schema.' CASCADE');
        }
    }
    private function pair(Connection $db,string $schema,int $lock,array $jobs): array
    {
        $db->executeQuery('SELECT pg_advisory_lock(?)',[$lock]);foreach($jobs as $job) { $this->running[]=$this->start($schema,$lock,$job); }foreach($this->running as $job) { self::assertSame("READY\n",fgets($job['pipes'][1])); }$db->executeQuery('SELECT pg_advisory_unlock(?)',[$lock]);$responses=[];
        foreach($this->running as $job) { $output=stream_get_contents($job['pipes'][1]);$error=stream_get_contents($job['pipes'][2]);fclose($job['pipes'][1]);fclose($job['pipes'][2]);self::assertSame(0,proc_close($job['process']),$error);$responses[]=json_decode($output,true,flags:JSON_THROW_ON_ERROR); }$this->running=[];return $responses;
    }
    private function start(string $schema,int $lock,array $job): array
    {
        $script= <<<'WORKER'
require 'vendor/autoload.php';$kernel=new App\Kernel('test',false);$kernel->boot();$container=$kernel->getContainer()->get('test.service_container');$db=$container->get(Doctrine\DBAL\Connection::class);$db->executeStatement('SET search_path TO '.$argv[1]);$job=json_decode($argv[3],false,flags:JSON_THROW_ON_ERROR);fwrite(STDOUT,"READY\n");fflush(STDOUT);$db->executeQuery('SELECT pg_advisory_lock_shared(?)',[(int)$argv[2]]);
try { $actor=$container->get(App\Security\StockUserProvider::class)->loadUserByIdentifier($job->actor.'@example.test');$service=$container->get(App\Administration\ProposalAdministration::class);$result=$job->mode==='submit'?$service->submit($actor,$job->body,$job->key):$service->review($actor,$job->id,$job->body,$job->key);echo json_encode(['status'=>$job->mode==='submit'?201:200,'result'=>$result],JSON_THROW_ON_ERROR); }
catch(App\Api\ApiProblem $error) { echo json_encode(['status'=>$error->status,'code'=>$error->errorCode],JSON_THROW_ON_ERROR); }$kernel->shutdown();
WORKER;
        $process=proc_open([PHP_BINARY,'-r',$script,$schema,(string)$lock,json_encode($job,JSON_THROW_ON_ERROR)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));if(!is_resource($process)) { throw new \RuntimeException('Could not start test process.'); }fclose($pipes[0]);unset($pipes[0]);stream_set_timeout($pipes[1],20);stream_set_timeout($pipes[2],20);return ['process'=>$process,'pipes'=>$pipes];
    }
}
