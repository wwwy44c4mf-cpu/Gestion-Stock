<?php

declare(strict_types=1);
namespace App\Notification;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;
final class NotificationRenderer
{
    public function __construct(private readonly Environment $twig,private readonly NotificationPayload $payloads,
        #[Autowire('%env(MAIL_FROM_ADDRESS)%')] private readonly string $from,
        #[Autowire('%env(APP_PUBLIC_URL)%')] private readonly string $baseUrl,
        #[Autowire('%kernel.environment%')] private readonly string $environment) {}
    public function render(array $event,string $recipient): Email
    {
        $payload=json_decode($event['payload'],true,flags:JSON_THROW_ON_ERROR);$this->payloads->validate($event['type'],$payload);
        $url=parse_url($this->baseUrl);
        if(!$url||!isset($url['host'])||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])||!in_array($url['scheme']??'',$this->environment==='prod'?['https']:['https','http'],true)) {throw new \InvalidArgumentException('notification_base_url_invalid');}
        $subject=match($event['type']) {'stock_low'=>'Stock bas : '.$payload['article'],'stock_critical'=>'Stock critique : '.$payload['article'],'password_reset'=>'Réinitialiser votre mot de passe','information'=>$payload['subject'],'daily_summary'=>'Votre récapitulatif des alertes de stock'};
        $context=['type'=>$event['type'],'payload'=>$payload,'base_url'=>rtrim($this->baseUrl,'/')];
        $email=(new Email())->from(new Address($this->from,'Gestion de stock'))->to(new Address($recipient))->subject($subject)->text($this->twig->render('emails/notification.txt.twig',$context))->html($this->twig->render('emails/notification.html.twig',$context));
        $email->getHeaders()->addIdHeader('Message-ID','notification.'.$event['id'].'@'.$url['host']);
        return $email;
    }
}
