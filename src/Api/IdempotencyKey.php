<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\Request;

final class IdempotencyKey
{
    public function read(Request $request): string
    {
        $key = $request->headers->get('Idempotency-Key', '');
        if (!preg_match('/^[A-Za-z0-9._:-]{8,160}$/D', $key)) {
            throw new ApiProblem(422, 'invalid_idempotency_key', 'Une clé Idempotency-Key de 8 à 160 caractères autorisés est requise.');
        }
        return $key;
    }

    public function fingerprint(string $actorId, string $method, string $path, \stdClass $body): string
    {
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if ($value instanceof \stdClass) {
                $properties = get_object_vars($value);
                ksort($properties, SORT_STRING);
                return (object) array_map($canonical, $properties);
            }
            return is_array($value) ? array_map($canonical, $value) : $value;
        };
        return hash('sha256', json_encode([$actorId, strtoupper($method), $path, $canonical($body)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
