<?php

declare(strict_types=1);
namespace App\Tests;
use App\Inventory\InventoryWorkflow;
use App\Security\StockUserProvider;
use App\Stock\StockMovementEngine;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Independent PostgreSQL connections prove reset cooldown and single-use under concurrent requests. */
final class PasswordResetConcurrencyTest extends KernelTestCase
{
    private array $running=[];
    public function testTwoRequestsQueueOnlyOneLink(): void { $this->race('request'); }
    public function testTwoConfirmationsConsumeOneTokenOnly(): void { $this->race('confirm'); }
    private function race(string $operation): void
    {
        self::bootKernel();$db=static::getContainer()->get(Connection::class);$schema='auth04_race_'.bin2hex(random_bytes(6));$db->executeStatement('CREATE SCHEMA '.$schema);$barrier=random_int(1000000,1000000000);
        try {
            $db->executeStatement('SET search_path TO '.$schema);
            foreach(explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) {if(trim($sql)!=='') {$db->executeStatement($sql);}}
            \App\Tests\CatalogDeletionSchema::apply($db);
            $db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
            foreach(['20261018140000','20261018200000','20261018223000','20261020220000','20261020230000','20261021000000','20261021010000','20261021020000','20261021030000'] as $version) {require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';$class='DoctrineMigrations\\Version'.$version;$migration=new $class($db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) {$db->executeStatement($sql->getStatement());}}
            $id=$db->fetchOne("INSERT INTO users(email,name,password_hash,role_id) VALUES ('person@example.test','Fictif',?,3) RETURNING id",[password_hash('Old-test-password-2026!',PASSWORD_ARGON2ID)]);
            $reset=static::getContainer()->get(\App\Security\PasswordReset::class);$token='';
            if($operation==='confirm') {
                $reset->request('person@example.test');$selector=$db->fetchOne('SELECT id FROM password_reset_tokens');
                parse_str(parse_url($reset->deliveryPath($selector,$id,'person@example.test'),PHP_URL_QUERY),$query);$token=$query['token'];
            }
            $results=$this->pair($db,$schema,$barrier,[['kind'=>$operation,'token'=>$token],['kind'=>$operation,'token'=>$token]]);
            $statuses=array_column($results,'status');sort($statuses);self::assertSame($operation==='request'?[200,200]:[200,422],$statuses);
            self::assertSame(1,(int)$db->fetchOne('SELECT count(*) FROM password_reset_tokens'));
            self::assertSame($operation==='confirm'?2:1,(int)$db->fetchOne('SELECT count(*) FROM notification_events'));
            self::assertSame($operation==='confirm'?1:0,(int)$db->fetchOne('SELECT auth_version FROM users'));
            self::assertSame($operation==='confirm'?1:0,(int)$db->fetchOne("SELECT count(*) FROM audit_logs WHERE action='auth.password_reset_completed'"));
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
require 'vendor/autoload.php';$kernel=new App\Kernel('test',false);$kernel->boot();$container=$kernel->getContainer()->get('test.service_container');$db=$container->get(Doctrine\DBAL\Connection::class);$db->executeStatement('SET search_path TO '.$argv[1]);$db->executeStatement("SET statement_timeout TO '15s'");$job=json_decode(stream_get_contents(STDIN),false,flags:JSON_THROW_ON_ERROR);echo 'READY:'.$db->fetchOne('SELECT pg_backend_pid()')."\n";fflush(STDOUT);if((int)$argv[2]!==0) {$db->executeQuery('SELECT pg_advisory_lock_shared(?)',[(int)$argv[2]]);}
try {
$reset=$container->get(App\Security\PasswordReset::class);
if($job->kind==='request') {$reset->request('person@example.test');}
else {$reset->confirm($job->token,'New-test-password-2026!');}
echo json_encode(['status'=>200]);
}
catch(App\Api\ApiProblem $e) {echo json_encode(['status'=>$e->status,'code'=>$e->errorCode],JSON_THROW_ON_ERROR);}$kernel->shutdown();
WORKER;
        $process=proc_open([PHP_BINARY,'-r',$script,$schema,(string)$barrier],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));if(!is_resource($process)) {throw new \RuntimeException('Impossible de lancer la recette concurrente.');}fwrite($pipes[0],json_encode($job,JSON_THROW_ON_ERROR));fclose($pipes[0]);unset($pipes[0]);stream_set_timeout($pipes[1],25);stream_set_timeout($pipes[2],25);$result=['process'=>$process,'pipes'=>$pipes];$this->running[]=$result;return $result;
    }
}
