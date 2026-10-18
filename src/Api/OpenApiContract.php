<?php

declare(strict_types=1);

namespace App\Api;

use Opis\JsonSchema\Validator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class OpenApiContract
{
    private array $document;

    public function __construct(#[Autowire('%kernel.project_dir%/docs/openapi.json')] string $path)
    {
        $this->document = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function document(): array
    {
        return $this->document;
    }

    public function operation(string $operationId): array
    {
        foreach ($this->document['paths'] as $path) {
            foreach ($path as $operation) {
                if (is_array($operation) && ($operation['operationId'] ?? null) === $operationId) {
                    return $operation;
                }
            }
        }
        throw new \LogicException('Opération OpenAPI inconnue.');
    }

    public function resolve(array $node): array
    {
        if (isset($node['$ref'])) {
            $value = $this->document;
            foreach (explode('/', substr($node['$ref'], 2)) as $part) {
                $value = $value[str_replace(['~1', '~0'], ['/', '~'], $part)];
            }
            return $value;
        }
        return $node;
    }

    public function valid(mixed $value, array $schema): bool
    {
        // OpenAPI 3.0 nullable devient l’union JSON Schema correspondante.
        $convert = function (mixed $node) use (&$convert): mixed {
            if (!is_array($node)) {
                return $node;
            }
            foreach ($node as $key => $child) {
                $node[$key] = $convert($child);
            }
            if (($node['nullable'] ?? false) && isset($node['type'])) {
                $node['type'] = [$node['type'], 'null'];
                unset($node['nullable']);
            }
            return $node;
        };
        $root = $convert($schema);
        $root['components'] = $convert($this->document['components']);
        $encoded = json_encode($root, JSON_THROW_ON_ERROR);
        // YAML représente {} par [] ; ces mots-clés sont des objets JSON.
        $decoded = json_decode($encoded, false, flags: JSON_THROW_ON_ERROR);
        $normalize = function (mixed $node) use (&$normalize): void {
            if (is_object($node)) {
                foreach ($node as $key => $child) {
                    if ($key === 'properties' && $child === []) {
                        $node->$key = new \stdClass();
                    } else {
                        $normalize($child);
                    }
                }
            } elseif (is_array($node)) {
                foreach ($node as $child) {
                    $normalize($child);
                }
            }
        };
        $normalize($decoded);
        return (new Validator())->validate($value, $decoded)->isValid();
    }
}
