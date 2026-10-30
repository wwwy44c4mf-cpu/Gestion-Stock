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
        #[Autowire('%kernel.environment%')] private readonly string $environment, private readonly ?\App\Security\PasswordReset $passwordReset=null,
        #[Autowire('%env(default::MAIL_PUBLIC_URL)%')] private readonly ?string $mailPublicUrl=null) {}
    public function render(array $event,string $recipient): Email
    {
        $payload=json_decode($event['payload'],true,flags:JSON_THROW_ON_ERROR);$this->payloads->validate($event['type'],$payload);
        if(in_array($event['type'],['password_reset','account_activation'],true)) {
            if($this->passwordReset===null) {throw new \LogicException('password_reset_renderer_unavailable');}
            $payload=['path'=>$this->passwordReset->deliveryPath($payload['reset_id'],$event['recipient_id'],$recipient,$event['type']==='account_activation'?'activation':'reset')];
        }
        $baseUrl=$this->mailPublicUrl?:$this->baseUrl;
        $url=parse_url($baseUrl);
        if(!$url||!isset($url['host'])||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])||!in_array($url['scheme']??'',$this->environment==='prod'?['https']:['https','http'],true)) {throw new \InvalidArgumentException('notification_base_url_invalid');}
        $subject=match($event['type']) {'stock_low'=>'Stock bas : '.$payload['article'],'stock_critical'=>'Stock critique : '.$payload['article'],'account_activation'=>'Créer votre mot de passe Gestion-Stock','password_reset'=>'Réinitialiser votre mot de passe','information'=>$payload['subject'],'daily_summary'=>'Votre récapitulatif des alertes de stock'};
        $context=['type'=>$event['type'],'payload'=>$payload,'base_url'=>rtrim($baseUrl,'/')];
        $email=(new Email())->from(new Address($this->from,'Gestion de stock'))->to(new Address($recipient))->subject($subject)->text($this->twig->render('emails/notification.txt.twig',$context))->html($this->twig->render('emails/notification.html.twig',$context));
        $email->getHeaders()->addIdHeader('Message-ID','notification.'.$event['id'].'@'.$url['host']);
        return $email;
    }
}
