<?php

declare(strict_types=1);
namespace App\Notification;
use Symfony\Component\Mime\Email;
interface MailDelivery { public function send(Email $email): void; }
