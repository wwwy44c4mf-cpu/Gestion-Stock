<?php

namespace App\Scheduler;

use App\Message\InfrastructureProbe;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule]
final class InfrastructureSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        // MAIL-03 ajoutera le récapitulatif métier à 08 h Europe/Paris.
        return (new Schedule())->add(RecurringMessage::every('1 minute', new InfrastructureProbe()));
    }
}
