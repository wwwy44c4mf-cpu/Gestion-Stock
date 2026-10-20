<?php

namespace App\Ui;

/** Presentation only: authorization belongs to the authenticated business controllers. */
final class Navigation
{
    public const SECTIONS = [
        'articles' => 'Articles', 'stocks' => 'Stocks', 'magasins' => 'Magasins', 'mouvements' => 'Mouvements',
        'consommations' => 'Consommations', 'inventaires' => 'Inventaires',
        'statistiques' => 'Statistiques', 'alertes' => 'Alertes', 'administration' => 'Administration',
    ];

    public function items(array $roles = [], ?string $storeId = null): array
    {
        $admin = in_array('ROLE_ADMIN', $roles, true);
        $manager = in_array('ROLE_GESTIONNAIRE', $roles, true);
        $user = in_array('ROLE_UTILISATEUR', $roles, true);
        if (!$admin && !$manager && !$user) {
            return [['label' => 'Connexion', 'route' => 'login', 'parameters' => [], 'active' => 'login']];
        }
        $items = [['label' => 'Tableau de bord', 'route' => 'home', 'parameters' => [], 'active' => 'home']];
        foreach (self::SECTIONS as $section => $label) {
            if ($section === 'administration' && !$admin) {
                continue;
            }
            if (in_array($section, ['mouvements', 'inventaires', 'alertes'], true) && !$admin && !($manager && $storeId !== null)) {
                continue;
            }
            if (!$admin && !$manager && !$user) {
                continue;
            }
            $items[] = ['label' => $label, 'route' => match ($section) { 'administration' => 'admin_users', 'magasins' => 'stores_list', 'articles' => 'catalog_list', 'stocks' => 'stocks_list', 'mouvements' => 'movements_list', 'inventaires' => 'inventories_list', 'consommations' => 'consumptions_new', default => 'ui_section' }, 'parameters' => in_array($section, ['administration','magasins','articles','stocks','consommations','mouvements','inventaires'], true) ? [] : ['section' => $section], 'active' => $section];
        }

        return $items;
    }
}
