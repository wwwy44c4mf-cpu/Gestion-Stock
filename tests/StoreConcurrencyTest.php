<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Two real PHP processes against committed, disposable PostgreSQL data. */
final class StoreConcurrencyTest extends KernelTestCase
{
    public function testConcurrentReplaysAndCodeConflictsAreAtomic(): void
    {
        self::bootKernel(); $db=static::getContainer()->get(Connection::class);
        $schema='mag01_race_'.bin2hex(random_bytes(6)); $jobs=[]; $lock=random_int(1000000,1000000000);
        $db->executeStatement('CREATE SCHEMA '.$schema);
        try {
            $db->executeStatement('SET search_path TO '.$schema);
            foreach (explode('-- migrate:statement',file_get_contents(dirname(__DIR__).'/migrations/sql/001_schema.sql')) as $sql) { if (trim($sql)!=='') { $db->executeStatement($sql); } }
            $db->executeStatement('ALTER TABLE users ADD development_only boolean NOT NULL DEFAULT false');
            require_once dirname(__DIR__).'/migrations/Version20261018130000.php';
            $migration=new \DoctrineMigrations\Version20261018130000($db,new \Psr\Log\NullLogger());$migration->up(new \Doctrine\DBAL\Schema\Schema());
            foreach ($migration->getSql() as $sql) { $db->executeStatement($sql->getStatement()); }
            $db->executeStatement("INSERT INTO users (email,name,password_hash,role_id) VALUES ('race@example.test','Race',?,1)",[password_hash('Race-test-2026!',PASSWORD_ARGON2ID)]);
            foreach (['replay','duplicate'] as $case) {
                $db->executeQuery('SELECT pg_advisory_lock(?)',[$lock]);
                foreach ([1,2] as $number) {
                    $key=$case==='replay'?'race-replay-123':'race-duplicate-'.$number;
                    $code=$case==='replay'?'RACE-REPLAY':'RACE-DUPLICATE';
                    $jobs[]=$this->start($schema,$lock,$key,$code);
                }
                foreach ($jobs as $job) { self::assertSame("READY\n",fgets($job['pipes'][1])); }
                $db->executeQuery('SELECT pg_advisory_unlock(?)',[$lock]);
                $responses=[];
                foreach ($jobs as $job) {
                    $output=stream_get_contents($job['pipes'][1]);$error=stream_get_contents($job['pipes'][2]);
                    fclose($job['pipes'][1]);fclose($job['pipes'][2]);self::assertSame(0,proc_close($job['process']),$error);
                    $responses[]=json_decode($output,true,flags:JSON_THROW_ON_ERROR);
                }
                $jobs=[];
                if ($case==='replay') {
                    self::assertSame(201,$responses[0]['status']);self::assertSame(201,$responses[1]['status']);self::assertEquals($responses[0]['data'],$responses[1]['data']);
                } else {
                    $statuses=array_column($responses,'status');sort($statuses);self::assertSame([201,409],$statuses);
                }
            }
            self::assertSame(2,(int)$db->fetchOne('SELECT count(*) FROM stores'));
            self::assertSame(2,(int)$db->fetchOne('SELECT count(*) FROM store_creation_requests'));
            self::assertSame(2,(int)$db->fetchOne('SELECT count(*) FROM audit_logs'));
        } finally {
            $db->executeQuery('SELECT pg_advisory_unlock(?)',[$lock]);
            foreach ($jobs as $job) { if (is_resource($job['process'])) { proc_terminate($job['process']); } foreach ($job['pipes'] as $pipe) { if (is_resource($pipe)) { fclose($pipe); } } if (is_resource($job['process'])) { proc_close($job['process']); } }
            $db->executeStatement('SET search_path TO public');$db->executeStatement('DROP SCHEMA '.$schema.' CASCADE');
        }
    }
    private function start(string $schema,int $lock,string $key,string $code): array
    {
        $script= <<<'WORKER'
require 'vendor/autoload.php';
$kernel=new App\Kernel('test',false);$kernel->boot();
$container=$kernel->getContainer()->get('test.service_container');
$db=$container->get(Doctrine\DBAL\Connection::class);
$db->executeStatement('SET search_path TO '.$argv[1]);
fwrite(STDOUT,"READY\n");fflush(STDOUT);
$db->executeQuery('SELECT pg_advisory_lock_shared(?)',[(int)$argv[2]]);
try {
    $actor=$container->get(App\Security\StockUserProvider::class)->loadUserByIdentifier('race@example.test');
    $result=$container->get(App\Administration\StoreAdministration::class)->create($actor,(object)['code'=>$argv[4],'name'=>'Magasin concurrent'],$argv[3]);
    echo json_encode(['status'=>201,'data'=>$result],JSON_THROW_ON_ERROR);
} catch (App\Api\ApiProblem $error) {
    echo json_encode(['status'=>$error->status,'code'=>$error->errorCode],JSON_THROW_ON_ERROR);
}
$kernel->shutdown();
WORKER;
        $process=proc_open([PHP_BINARY,'-r',$script,$schema,(string)$lock,$key,$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
        if (!is_resource($process)) { throw new \RuntimeException('Could not start test process.'); }
        fclose($pipes[0]);unset($pipes[0]);stream_set_timeout($pipes[1],20);stream_set_timeout($pipes[2],20);
        return ['process'=>$process,'pipes'=>$pipes];
    }
}
