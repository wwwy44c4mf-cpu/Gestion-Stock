<?php

declare(strict_types=1);
namespace App\History;
use App\Api\ApiProblem;

/** Calendar dates belong to the account timezone; the upper UTC bound is exclusive. */
final class MovementDates
{
    public function bounds(array $filters,string $timezone): array
    {
        $zone=new \DateTimeZone($timezone);$bounds=[];
        foreach(['date_from','date_to'] as $field) {
            if(!isset($filters[$field])) { continue; }
            $value=$filters[$field];
            if(!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$value) || substr($value,0,4)==='0000') { $this->invalid(); }
            $day=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,$zone);
            if(!$day || $day->format('Y-m-d')!==$value) { $this->invalid(); }
            if($field==='date_to') { $day=$day->modify('+1 day'); }
            // Epoch seconds preserve very early/late calendar dates without year-zero SQL literals.
            $bounds[$field]=$day->getTimestamp();
        }
        if(isset($filters['date_from'],$filters['date_to']) && $filters['date_from']>$filters['date_to']) { throw new ApiProblem(422,'invalid_date_range','La date de début doit précéder la date de fin.'); }
        return $bounds;
    }
    private function invalid(): never { throw new ApiProblem(422,'invalid_date_range','Choisissez une date de calendrier valide.'); }
}
