<?php

declare(strict_types=1);
namespace App\Access;

use App\Api\ApiProblem;

final class ArticleCodes
{
    public static function one(mixed $code): string
    {
        if (!is_string($code) || !preg_match('//u', $code)) { self::invalid(); }
        $code = trim($code);
        if ($code === '' || mb_strlen($code) > 512 || preg_match('/[\x00-\x1f\x7f]/u', $code)) { self::invalid(); }
        return $code;
    }
    public static function normalize(array $codes): array
    {
        if (count($codes) > 50) { self::invalid(); }
        $codes = array_map(self::one(...), $codes);
        if (count(array_unique($codes, SORT_STRING)) !== count($codes)) {
            throw new ApiProblem(422, 'duplicate_scan_code', 'Un même code apparaît plusieurs fois.');
        }
        sort($codes, SORT_STRING);
        return $codes;
    }
    public static function lines(string $text): array
    {
        if (strlen($text) > 60000) { self::invalid(); }
        return self::normalize(array_values(array_filter(preg_split('/\r\n|\r|\n/', $text), static fn(string $line) => trim($line) !== '')));
    }
    private static function invalid(): never
    {
        throw new ApiProblem(422, 'invalid_scan_code', 'Indiquez au plus 50 codes distincts de 1 à 512 caractères, sans saut de ligne dans un code.');
    }
}
