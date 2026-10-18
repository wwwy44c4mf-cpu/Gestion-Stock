<?php

declare(strict_types=1);

namespace App\Tests;

use App\Api\ApiProblem;
use App\Api\DateRange;
use App\Api\IdempotencyKey;
use App\Api\Pagination;
use PHPUnit\Framework\TestCase;

final class ApiValuesTest extends TestCase
{
    public function testInclusiveLocalDatesRespectBothClockChanges(): void
    {
        $spring = DateRange::fromInclusiveDates('2026-03-29', '2026-03-29');
        $autumn = DateRange::fromInclusiveDates('2026-10-25', '2026-10-25');
        self::assertSame(23 * 3600, $spring->endUtcExclusive->getTimestamp() - $spring->startUtc->getTimestamp());
        self::assertSame(25 * 3600, $autumn->endUtcExclusive->getTimestamp() - $autumn->startUtc->getTimestamp());
        self::assertSame('UTC', $spring->startUtc->getTimezone()->getName());
    }

    public function testInvalidCalendarDateIsRejected(): void
    {
        $this->expectException(ApiProblem::class);
        DateRange::fromInclusiveDates('2026-02-30', '2026-03-01');
    }

    public function testInvalidTimezoneIsRejected(): void
    {
        $this->expectException(ApiProblem::class);
        DateRange::fromInclusiveDates('2026-10-01', '2026-10-18', 'invalid-timezone');
    }

    public function testCanonicalFingerprintIgnoresObjectOrderAndScopesActorAndPath(): void
    {
        $keys = new IdempotencyKey();
        $a = json_decode('{"article":"a","lines":[{"quantity":1,"id":"x"}]}');
        $b = json_decode('{"lines":[{"id":"x","quantity":1}],"article":"a"}');
        $hash = $keys->fingerprint('actor-a', 'post', '/api/v1/movements/entries', $a);
        self::assertSame($hash, $keys->fingerprint('actor-a', 'POST', '/api/v1/movements/entries', $b));
        self::assertNotSame($hash, $keys->fingerprint('actor-b', 'POST', '/api/v1/movements/entries', $b));
        self::assertNotSame($hash, $keys->fingerprint('actor-a', 'POST', '/api/v1/movements/consumptions', $b));
        $b->lines[0]->quantity = 2;
        self::assertNotSame($hash, $keys->fingerprint('actor-a', 'POST', '/api/v1/movements/entries', $b));
    }

    public function testEmptyAndLastPageMetadata(): void
    {
        $page = new Pagination(3, 10);
        self::assertSame(20, $page->offset());
        self::assertSame(3, $page->response([], 21)['meta']['total_pages']);
        self::assertSame(0, (new Pagination())->response([], 0)['meta']['total_pages']);
    }
}
