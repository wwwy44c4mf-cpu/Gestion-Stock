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
    public function __construct(private Environment $twig, private Navigation $navigation, private Security $security, private \App\Ui\StoreContext $context) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onController'];
    }

    public function onController(ControllerEvent $event): void
    {
        // Revalidate the context against live assignments on every authenticated request.
        $user = $this->security->getUser();
        $route = $event->getRequest()->attributes->get('_route');
        $section = in_array($route, ['login', 'profile'], true) ? $route : $event->getRequest()->attributes->get('section', 'home');
        if (str_starts_with($route ?? '', 'admin_users')) { $section = 'administration'; }
        $context = $user instanceof StockUser ? $this->context->read($event->getRequest(), $user) : ['options' => [], 'selected' => null];
        if (str_starts_with($route ?? '', 'stocks_')) { $section = 'stocks'; }
        if (str_starts_with($route ?? '', 'stores_')) { $section = 'magasins'; }
        if ((str_starts_with($route ?? '', 'catalog_') || str_starts_with($route ?? '', 'proposals_'))) { $section = 'articles'; }
        $this->twig->addGlobal('store_options', $context['options']);
        $this->twig->addGlobal('selected_store', $context['selected']);
        $this->twig->addGlobal('navigation_items', $this->navigation->items($user instanceof StockUser ? $user->getRoles() : [], $context['selected'] ?? ($context['options'][0]['id'] ?? null)));
        $this->twig->addGlobal('current_section', $section);
        $this->twig->addGlobal('page_title', ($event->getRequest()->attributes->get('_route') === 'login' ? 'Connexion' : ($event->getRequest()->attributes->get('_route') === 'profile' ? 'Mon profil' : (Navigation::SECTIONS[$section] ?? 'Tableau de bord'))));
    }
}
