<?php

declare(strict_types=1);
namespace App\Tests;
use App\Notification\SmtpDelivery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;
final class SmtpDeliveryTest extends TestCase
{
    public function testRealLocalSmtpDialogueAndMultipartBody(): void
    {
        $server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);self::assertIsResource($server);$address=stream_socket_get_name($server,false);$secret=tempnam('/tmp','smtp_secret_');$capture=tempnam('/tmp','smtp_capture_');file_put_contents($secret,'smtp://'.$address);
        $pid=pcntl_fork();if($pid===0) {
            $peer=stream_socket_accept($server,8);if(!$peer) {exit(2);}stream_set_timeout($peer,8);fwrite($peer,"220 localhost ESMTP\r\n");$data=false;$message='';
            while(($line=fgets($peer))!==false) {
                if($data) {if($line===".\r\n") {$data=false;file_put_contents($capture,$message);fwrite($peer,"250 Accepted\r\n");}else {$message.=$line;}continue;}
                if(str_starts_with($line,'EHLO')) {fwrite($peer,"250-localhost\r\n250 SIZE 1000000\r\n");}
                elseif(str_starts_with($line,'DATA')) {$data=true;fwrite($peer,"354 Continue\r\n");}
                elseif(str_starts_with($line,'QUIT')) {fwrite($peer,"221 Bye\r\n");break;}
                else {fwrite($peer,"250 OK\r\n");}
            }fclose($peer);fclose($server);exit(0);
        }
        self::assertGreaterThan(0,$pid);fclose($server);
        try {(new SmtpDelivery($secret))->send((new Email())->from('sender@example.test')->to('recipient@example.test')->subject('Test SMTP local')->text('Version texte')->html('<p>Version HTML</p>'));pcntl_waitpid($pid,$status);self::assertSame(0,pcntl_wexitstatus($status));$raw=file_get_contents($capture);self::assertStringContainsString('multipart/alternative',$raw);self::assertStringContainsString('Version texte',$raw);self::assertStringContainsString('Version HTML',$raw);}
        finally {pcntl_waitpid($pid,$status,WNOHANG);@unlink($secret);@unlink($capture);}
    }
    public function testSmtpRefusalDoesNotExposeSecretOrOriginalException(): void
    {
        $server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($server,false);fclose($server);$secret=tempnam('/tmp','smtp_secret_');file_put_contents($secret,'smtp://private-user:private-password@'.$address);
        try {(new SmtpDelivery($secret))->send((new Email())->from('sender@example.test')->to('recipient@example.test')->text('Fictif'));self::fail('Refus SMTP attendu');}
        catch(\RuntimeException $e) {self::assertSame('smtp_delivery_failed',$e->getMessage());self::assertNull($e->getPrevious());self::assertStringNotContainsString('private-password',(string)$e);}
        finally {@unlink($secret);}
    }
    public function testNonSmtpTransportCannotSilentlyDiscardMail(): void
    {
        $secret=tempnam('/tmp','smtp_secret_');file_put_contents($secret,'null://null');
        try {(new SmtpDelivery($secret))->send((new Email())->from('sender@example.test')->to('recipient@example.test')->text('Fictif'));self::fail('Transport interdit');}
        catch(\RuntimeException $e) {self::assertSame('smtp_delivery_failed',$e->getMessage());}
        finally {@unlink($secret);}
    }
}
