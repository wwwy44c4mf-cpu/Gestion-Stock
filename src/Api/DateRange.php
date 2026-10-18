<?php

declare(strict_types=1);

namespace App\Api;

final readonly class DateRange
{
    public function __construct(public \DateTimeImmutable $startUtc, public \DateTimeImmutable $endUtcExclusive)
    {
    }

    public static function fromInclusiveDates(string $from, string $to, string $timezone = 'Europe/Paris'): self
    {
        try {
            $zone = new \DateTimeZone($timezone);
        } catch (\Exception) {
            throw new ApiProblem(422, 'invalid_timezone', 'Fuseau horaire invalide.');
        }
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $from, $zone);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $to, $zone);
        if (!$start || !$end || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to || $start > $end) {
            throw new ApiProblem(422, 'invalid_date_range', 'Période de dates invalide.');
        }
        $utc = new \DateTimeZone('UTC');
        return new self($start->setTimezone($utc), $end->modify('+1 day')->setTimezone($utc));
    }
}
