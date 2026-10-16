<?php

namespace App\Service;

use App\Entity\User;
use App\Util\WorkingHours;

/**
 * Rechnet offene Minuten in einen Zeitpunkt um: "ab wann ist der
 * Arbeiter voraussichtlich wieder frei" (F9).
 *
 * Gerechnet wird mit den regulaeren Arbeitszeiten der Werkstatt
 * (App\Util\WorkingHours) – Mittagspause, Feierabend und Wochenenden
 * zaehlen nicht.
 */
class WorkloadCalculator
{
    public function estimateAvailableFrom(User $user, int $remainingMinutes, \DateTimeImmutable $from): \DateTimeImmutable
    {
        $cursor = WorkingHours::nextWorkingMoment($from);

        return $remainingMinutes > 0 ? WorkingHours::endFor($cursor, $remainingMinutes) : $cursor;
    }
}
