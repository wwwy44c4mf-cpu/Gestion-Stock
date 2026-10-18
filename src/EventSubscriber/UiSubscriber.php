<?php

namespace App\EventSubscriber;

use App\Ui\Navigation;
use App\Security\StockUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

final class UiSubscriber implements EventSubscriberInterface
{
    public function __construct(private Environment $twig, private Navigation $navigation, private Security $security) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onController'];
    }

    public function onController(ControllerEvent $event): void
    {
        // Trusted session identity; store selection and authorization follow in AUTH-03.
        $user = $this->security->getUser();
        $route = $event->getRequest()->attributes->get('_route');
        $section = in_array($route, ['login', 'profile'], true) ? $route : $event->getRequest()->attributes->get('section', 'home');
        $this->twig->addGlobal('navigation_items', $this->navigation->items($user instanceof StockUser ? $user->getRoles() : []));
        $this->twig->addGlobal('current_section', $section);
        $this->twig->addGlobal('page_title', ($event->getRequest()->attributes->get('_route') === 'login' ? 'Connexion' : ($event->getRequest()->attributes->get('_route') === 'profile' ? 'Mon profil' : (Navigation::SECTIONS[$section] ?? 'Tableau de bord'))));
    }
}
