<?php

declare(strict_types=1);
namespace App\Tests;
use App\Api\ApiProblem;
use App\History\MovementDates;
use PHPUnit\Framework\TestCase;

final class MovementDatesTest extends TestCase
{
    public function testInclusiveParisDaysFollowBothDaylightSavingTransitions(): void
    {
        $dates=new MovementDates();
        foreach(['2026-03-29'=>23,'2026-10-25'=>25,'2026-10-20'=>24] as $day=>$hours) {
            $bounds=$dates->bounds(['date_from'=>$day,'date_to'=>$day],'Europe/Paris');self::assertSame($hours*3600,$bounds['date_to']-$bounds['date_from']);
            self::assertSame($day.' 00:00',(new \DateTimeImmutable('@'.$bounds['date_from']))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d H:i'));
        }
    }
    public function testOtherTimezonesAndOpenEndedRanges(): void
    {
        $dates=new MovementDates();self::assertSame([],$dates->bounds([],'UTC'));
        self::assertSame(['date_from'=>strtotime('2026-10-20T04:00:00Z')],$dates->bounds(['date_from'=>'2026-10-20'],'America/New_York'));
        self::assertSame(['date_to'=>strtotime('2026-10-20T15:00:00Z')],$dates->bounds(['date_to'=>'2026-10-20'],'Asia/Tokyo'));
    }
    public function testInvalidCalendarDatesAndReversedRangesAreExplicitErrors(): void
    {
        foreach([['date_from'=>'2026-02-30'],['date_to'=>'2026-13-01'],['date_from'=>'0000-01-01'],['date_from'=>['2026-10-20']],['date_from'=>'2026-10-21','date_to'=>'2026-10-20']] as $filters) {
            try { (new MovementDates())->bounds($filters,'Europe/Paris');self::fail('Date invalide acceptée'); }catch(ApiProblem $error) { self::assertSame(422,$error->status); }
        }
    }
}
