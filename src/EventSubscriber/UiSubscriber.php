<?php

namespace App\EventSubscriber;

use App\Ui\Navigation;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

final class UiSubscriber implements EventSubscriberInterface
{
    public function __construct(private Environment $twig, private Navigation $navigation) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onController'];
    }

    public function onController(ControllerEvent $event): void
    {
        // Anonymous until AUTH-01/03 supplies trusted roles and authorized stores.
        $section = $event->getRequest()->attributes->get('section', 'home');
        $this->twig->addGlobal('navigation_items', $this->navigation->items());
        $this->twig->addGlobal('current_section', $section);
        $this->twig->addGlobal('page_title', Navigation::SECTIONS[$section] ?? 'Tableau de bord');
    }
}
