<?php

declare(strict_types=1);

namespace App\Ui;

use App\Access\AccessDirectory;
use App\Api\ApiProblem;
use App\Security\StockUser;
use Symfony\Component\HttpFoundation\Request;

final class StoreContext
{
    public function __construct(private readonly AccessDirectory $directory) {}
    public function read(Request $request, StockUser $actor): array
    {
        $options = $this->directory->storeOptions($actor);
        $session = $request->getSession();
        $preference = [$actor->id, $actor->favoriteStoreId];
        if ($session->has('store_context_preference') && $session->get('store_context_preference') !== $preference) {
            $session->remove('store_context');
        }
        $session->set('store_context_preference', $preference);
        $defaults = array_column(array_filter($options, static fn (array $store): bool => (bool) $store['active']), 'id');
        $selected = $session->has('store_context') ? $session->get('store_context') : (in_array($actor->favoriteStoreId, $defaults, true) ? $actor->favoriteStoreId : null);
        if ($selected !== null && (!is_string($selected) || !in_array($selected, array_column($options, 'id'), true))) {
            $request->getSession()->remove('store_context'); $selected = null;
        }
        return ['options' => $options, 'selected' => $selected];
    }
    /** A manual context takes precedence over the saved default, within operation permissions. */
    public function preferred(Request $request, StockUser $actor, array $options): ?string
    {
        $context = $this->read($request, $actor);
        $selected = $request->getSession()->has('store_context') ? $context['selected'] : ($context['selected'] ?? $actor->favoriteStoreId);
        return in_array($selected, array_column($options, 'id'), true) ? $selected : null;
    }
    public function select(Request $request, StockUser $actor, string $id): void
    {
        $context = $this->read($request, $actor);
        if ($id !== '' && !in_array($id, array_column($context['options'], 'id'), true)) {
            throw new ApiProblem(404, 'resource_not_found', 'Ressource introuvable.');
        }
        if ($id === '') { $request->getSession()->set('store_context', null); }
        else { $request->getSession()->set('store_context', $id); }
    }
}
