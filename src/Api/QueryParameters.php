<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\Request;

final class QueryParameters
{
    public function __construct(private readonly OpenApiContract $contract)
    {
    }

    public function read(Request $request, string $operationId): array
    {
        $definitions = [];
        foreach ($this->contract->operation($operationId)['parameters'] ?? [] as $parameter) {
            $parameter = $this->contract->resolve($parameter);
            if ($parameter['in'] === 'query') {
                $definitions[$parameter['name']] = $parameter['schema'];
            }
        }
        $query = $request->query->all();
        $result = [];
        foreach ($query as $key => $value) {
            if (!isset($definitions[$key]) || !is_string($value)) {
                throw new ApiProblem(422, 'invalid_query', 'Paramètre de recherche inconnu ou invalide.');
            }
        }
        foreach ($definitions as $name => $schema) {
            if (!array_key_exists($name, $query)) {
                if (isset($schema['default'])) {
                    $result[$name] = $schema['default'];
                }
                continue;
            }
            $value = $query[$name];
            if (($schema['type'] ?? '') === 'integer') {
                if (!preg_match('/^(0|[1-9][0-9]{0,8})$/D', $value)) {
                    throw new ApiProblem(422, 'invalid_query', 'Un entier borné est attendu.');
                }
                $value = (int) $value;
            }
            if (!$this->contract->valid($value, $schema)) {
                throw new ApiProblem(422, 'invalid_query', 'Une valeur de recherche est hors limites.');
            }
            $result[$name] = $value;
        }
        if (isset($result['date_from'], $result['date_to']) && $result['date_from'] > $result['date_to']) {
            throw new ApiProblem(422, 'invalid_date_range', 'La date de début doit précéder la date de fin.');
        }
        return $result;
    }
}
