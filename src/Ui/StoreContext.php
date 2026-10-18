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
        $selected = $request->getSession()->get('store_context');
        if (!is_string($selected) || !in_array($selected, array_column($options, 'id'), true)) {
            $request->getSession()->remove('store_context'); $selected = null;
        }
        return ['options' => $options, 'selected' => $selected];
    }
    public function select(Request $request, StockUser $actor, string $id): void
    {
        $context = $this->read($request, $actor);
        if ($id !== '' && !in_array($id, array_column($context['options'], 'id'), true)) {
            throw new ApiProblem(404, 'resource_not_found', 'Ressource introuvable.');
        }
        if ($id === '') { $request->getSession()->remove('store_context'); }
        else { $request->getSession()->set('store_context', $id); }
    }
}
