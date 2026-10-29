<?php

declare(strict_types=1);
namespace App\Tests;
use App\Inventory\InventoryWorkflow;
use App\Security\StockUserProvider;
use App\Stock\StockMovementEngine;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Real PostgreSQL connections serialize crossings and idempotent requests. */
final class DailySummaryConcurrencyTest extends KernelTestCase
{
    private array $running=[];
    public function testConcurrentSchedulersCreateOneDailyBatchAndOneMailPerRecipient(): void
    {
        self::bootKernel();$db=static::getContainer()->get(Connection::class);$schema='inv01_race_'.bin2hex(random_bytes(6));$db->executeStatement('CREATE SCHEMA '.$schema);$barrier=random_int(1000000,1000000000);
        try {
            $db->executeStatement('SET search_path TO '.$schema);
            foreach(explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) {if(trim($sql)!=='') {$db->executeStatement($sql);}}
            \App\Tests\CatalogDeletionSchema::apply($db);
            $db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
            foreach(['20261018140000','20261018200000','20261018223000','20261020220000','20261020230000','20261021000000','20261021010000','20261021020000','20261021030000','20261021040000','20261021050000'] as $version) {require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';$class='DoctrineMigrations\\Version'.$version;$migration=new $class($db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());foreach($migration->getSql() as $sql) {$db->executeStatement($sql->getStatement());}}
            $db->executeStatement('INSERT INTO users (email,name,password_hash,role_id) VALUES (?,?,?,1)',['admin@example.test','Admin',password_hash('Inventory-race-2026!',PASSWORD_ARGON2ID)]);
            $store=$db->fetchOne("INSERT INTO stores (code,name) VALUES ('A','A') RETURNING id");$other=$db->fetchOne("INSERT INTO stores (code,name) VALUES ('B','B') RETURNING id");$article=$db->fetchOne("INSERT INTO articles (sku,name) VALUES ('RACE','Stock compté') RETURNING id");
            $db->executeStatement('INSERT INTO stock_levels(article_id,store_id,quantity,low_threshold) VALUES (?,?,0,5)',[$article,$store]);
            $db->executeStatement('INSERT INTO stock_levels(article_id,store_id,quantity,low_threshold) VALUES (?,?,0,5)',[$article,$other]);
            $job=['now'=>'2026-10-21T08:00:00+02:00'];$results=$this->pair($db,$schema,$barrier,[$job,$job]);$counts=array_column($results,'result');sort($counts);self::assertSame([0,1],$counts);
            self::assertSame(1,(int)$db->fetchOne('SELECT count(*) FROM daily_summary_batches'));self::assertSame(1,(int)$db->fetchOne("SELECT count(*) FROM notification_events WHERE type='daily_summary'"));self::assertSame(1,(int)$db->fetchOne('SELECT count(*) FROM daily_notification_runs'));self::assertSame(2,(int)$db->fetchOne('SELECT count(*) FROM daily_summary_items'));self::assertSame(1,(int)$db->fetchOne("SELECT count(*) FROM audit_logs WHERE action='notifications.daily_summary'"));
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
try {$actor=$container->get(App\Security\StockUserProvider::class)->loadUserByIdentifier('admin@example.test');$result=$container->get(App\Notification\DailySummaryGenerator::class)->generate(new DateTimeImmutable($job->now));echo json_encode(['status'=>200,'result'=>$result],JSON_THROW_ON_ERROR);}

catch(App\Api\ApiProblem $e) {echo json_encode(['status'=>$e->status,'code'=>$e->errorCode],JSON_THROW_ON_ERROR);}$kernel->shutdown();
WORKER;
        $process=proc_open([PHP_BINARY,'-r',$script,$schema,(string)$barrier,json_encode($job,JSON_THROW_ON_ERROR)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));if(!is_resource($process)) {throw new \RuntimeException('Impossible de lancer la recette concurrente.');}fclose($pipes[0]);unset($pipes[0]);stream_set_timeout($pipes[1],25);stream_set_timeout($pipes[2],25);$result=['process'=>$process,'pipes'=>$pipes];$this->running[]=$result;return $result;
    }
}
