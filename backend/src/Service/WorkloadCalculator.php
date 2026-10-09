<?php

namespace App\Service;

use App\Entity\User;

/**
 * Rechnet offene Minuten in einen Zeitpunkt um: "ab wann ist der
 * Arbeiter voraussichtlich wieder frei" (F9).
 *
 * Gerechnet wird mit Werktagen (Mo–Fr) und der taeglichen Arbeitszeit
 * des Arbeiters, beginnend um 07:00.
 */
class WorkloadCalculator
{
    public const DAY_START_HOUR = 7;

    /** Schutz vor Endlosschleifen bei absurd grosser Restzeit. */
    private const MAX_DAYS = 500;

    public function estimateAvailableFrom(User $user, int $remainingMinutes, \DateTimeImmutable $from): \DateTimeImmutable
    {
        $daily = max(1, $user->getDailyMinutes());
        $cursor = $this->alignToWorkingTime($from, $daily);
        $left = max(0, $remainingMinutes);

        for ($day = 0; $left > 0 && $day < self::MAX_DAYS; ++$day) {
            $dayEnd = $this->dayEnd($cursor, $daily);
            $capacity = intdiv($dayEnd->getTimestamp() - $cursor->getTimestamp(), 60);

            if ($left <= $capacity) {
                return $cursor->modify(sprintf('+%d minutes', $left));
            }

            $left -= $capacity;
            $cursor = $this->alignToWorkingTime($dayEnd, $daily);
        }

        return $cursor;
    }

    /** Schiebt einen Zeitpunkt auf die naechste Arbeitszeit (Mo–Fr, ab 07:00). */
    private function alignToWorkingTime(\DateTimeImmutable $moment, int $daily): \DateTimeImmutable
    {
        $cursor = $moment;

        while (true) {
            $dayStart = $cursor->setTime(self::DAY_START_HOUR, 0);

            if ((int) $cursor->format('N') >= 6 || $cursor >= $this->dayEnd($cursor, $daily)) {
                $cursor = $dayStart->modify('+1 day');
                continue;
            }

            return max($cursor, $dayStart);
        }
    }

    private function dayEnd(\DateTimeImmutable $day, int $daily): \DateTimeImmutable
    {
        return $day->setTime(self::DAY_START_HOUR, 0)->modify(sprintf('+%d minutes', $daily));
    }
}
