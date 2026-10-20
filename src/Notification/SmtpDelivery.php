<?php

declare(strict_types=1);
namespace App\Notification;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mime\Email;

/** Secrets and SMTP diagnostic strings never cross this boundary. */
final class SmtpDelivery implements MailDelivery
{
    public function __construct(#[Autowire('%env(MAILER_DSN_FILE)%')] private readonly string $secretFile) {}
    public function send(Email $email): void
    {
        try {
            $dsn=trim((string)@file_get_contents($this->secretFile));
            if(!preg_match('#^smtps?://#D',$dsn) || preg_match('/[\r\n]/',$dsn)) {throw new \RuntimeException();}
            $transport=Transport::fromDsn($dsn);
            if(!$transport instanceof SmtpTransport) {throw new \RuntimeException();}
            $transport->getStream()->setTimeout(10);
            (new Mailer($transport))->send($email);
        } catch(\Throwable) {throw new \RuntimeException('smtp_delivery_failed');}
    }
}
