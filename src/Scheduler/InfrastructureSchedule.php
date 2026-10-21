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
        // Le contrôle périodique rattrape uniquement le jour courant après un arrêt.
        $paris = new \DateTimeZone('Europe/Paris');
        return (new Schedule())->add(RecurringMessage::cron('0 8 * * *', new \App\Message\GenerateDailySummaries(), $paris), RecurringMessage::cron('* * * * *', new \App\Message\GenerateDailySummaries(), $paris), RecurringMessage::every('1 minute', new InfrastructureProbe()), RecurringMessage::every('10 seconds', new \App\Message\DispatchNotifications()));
    }
}
