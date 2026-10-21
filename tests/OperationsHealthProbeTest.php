<?php

declare(strict_types=1);
namespace App\Tests;
use PHPUnit\Framework\TestCase;

final class OperationsHealthProbeTest extends TestCase
{
    private function process(array $command,string $input=''): int
    {
        $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);self::assertIsResource($process);fwrite($pipes[0],$input);fclose($pipes[0]);stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return proc_close($process);
    }
    public function testMissingStaleAndFutureWorkerHeartbeatsFail(): void
    {
        $path='/tmp/worker-heartbeat';$previous=is_file($path)?[file_get_contents($path),filemtime($path)]:null;
        try {
            @unlink($path);self::assertSame(1,$this->process([PHP_BINARY,dirname(__DIR__).'/docker/healthcheck.php','worker']));
            touch($path);self::assertSame(0,$this->process([PHP_BINARY,dirname(__DIR__).'/docker/healthcheck.php','worker']));
            touch($path,time()-121);self::assertSame(1,$this->process([PHP_BINARY,dirname(__DIR__).'/docker/healthcheck.php','worker']));
            touch($path,time()+120);self::assertSame(1,$this->process([PHP_BINARY,dirname(__DIR__).'/docker/healthcheck.php','worker']));
        } finally {if($previous===null) {@unlink($path);}else {file_put_contents($path,$previous[0]);touch($path,$previous[1]);}}
    }
    public function testContainerGateRejectsStoppedPausedMissingAndUnhealthyServices(): void
    {
        $healthy="app|running|healthy\nweb|running|healthy\ndb|running|healthy\nworker|running|healthy\nscheduler|running|healthy\nbackup|running|healthy\ninit|exited|\n";
        $command=['awk','-F','|','-v','require_backup=1','-f',dirname(__DIR__).'/docker/operations/check-containers.awk'];
        self::assertSame(0,$this->process($command,$healthy));
        foreach(['worker|exited|','worker|paused|healthy','worker|running|unhealthy'] as $bad) {self::assertSame(1,$this->process($command,str_replace('worker|running|healthy',$bad,$healthy)));}
        self::assertSame(1,$this->process($command,str_replace("worker|running|healthy\n",'',$healthy)));self::assertSame(1,$this->process($command,str_replace("backup|running|healthy\n",'',$healthy)));
        $command=['awk','-F','|','-v','require_backup=1','-v','require_proxy=1','-f',dirname(__DIR__).'/docker/operations/check-containers.awk'];
        self::assertSame(1,$this->process($command,$healthy));self::assertSame(0,$this->process($command,$healthy."proxy|running|healthy\n"));self::assertSame(1,$this->process($command,$healthy."proxy|running|unhealthy\n"));
    }
}
