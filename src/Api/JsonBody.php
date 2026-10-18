<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\Request;

final class JsonBody
{
    public function __construct(private readonly OpenApiContract $contract)
    {
    }

    public function read(Request $request, string $schema): \stdClass
    {
        if ($request->query->count() > 0) {
            throw new ApiProblem(422, 'invalid_query', 'Aucun paramètre de recherche n’est accepté sur cette écriture.');
        }
        if (strtolower(trim(explode(';', $request->headers->get('Content-Type', ''))[0])) !== 'application/json') {
            throw new ApiProblem(415, 'unsupported_media_type', 'Un corps application/json est requis.');
        }
        $stream = $request->getContent(true);
        $raw = stream_get_contents($stream, 65537);
        if ($raw === false || strlen($raw) > 65536) {
            throw new ApiProblem(413, 'payload_too_large', 'Le corps dépasse la limite de 64 Kio.');
        }
        try {
            $data = json_decode($raw, false, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'invalid_json', 'Le corps JSON est invalide.');
        }
        if (!$data instanceof \stdClass) {
            throw new ApiProblem(400, 'invalid_json_object', 'Un objet JSON est requis.');
        }
        // Les entrées numériques du MVP sont entières. Contrôle lexical avant
        // toute conversion flottante : même une fraction infime est refusée.
        $nonInteger = '/"(?:[^"\\\\]|\\\\.)*"(*SKIP)(*F)|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+(?:[eE][+-]?[0-9]+)?|[eE][+-]?[0-9]+)/s';
        if (preg_match($nonInteger, $raw) || !$this->contract->valid($data, ['$ref' => '#/components/schemas/'.$schema])) {
            throw new ApiProblem(422, 'validation_failed', 'Le contenu ne respecte pas le schéma attendu.', [
                ['field' => 'body', 'message' => 'Vérifier les champs, les types et les bornes ; nombres entiers sans décimale ni exposant.'],
            ]);
        }
        return $data;
    }
}
