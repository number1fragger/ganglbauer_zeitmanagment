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

    public function estimateAvailableFrom(User $user, int $remainingMinutes, ?\DateTimeImmutable $from = null): \DateTimeImmutable
    {
        $daily = max(1, $user->getDailyMinutes());
        $cursor = $this->alignToWorkingTime($from ?? new \DateTimeImmutable(), $daily);
        $left = max(0, $remainingMinutes);

        // Obergrenze, damit eine absurd grosse Restzeit keine Endlosschleife baut.
        for ($guard = 0; $left > 0 && $guard < 500; ++$guard) {
            $dayEnd = $cursor->setTime(self::DAY_START_HOUR, 0)->modify(sprintf('+%d minutes', $daily));
            $capacity = (int) floor(($dayEnd->getTimestamp() - $cursor->getTimestamp()) / 60);

            if ($capacity <= 0) {
                $cursor = $this->alignToWorkingTime($cursor->modify('+1 day')->setTime(0, 0), $daily);
                continue;
            }

            if ($left <= $capacity) {
                return $cursor->modify(sprintf('+%d minutes', $left));
            }

            $left -= $capacity;
            $cursor = $this->alignToWorkingTime($dayEnd->modify('+1 minute'), $daily);
        }

        return $cursor;
    }

    /** Schiebt einen Zeitpunkt auf die naechste Arbeitszeit (Mo–Fr, ab 07:00). */
    private function alignToWorkingTime(\DateTimeImmutable $moment, int $daily): \DateTimeImmutable
    {
        $cursor = $moment;

        for ($i = 0; $i < 30; ++$i) {
            $dayStart = $cursor->setTime(self::DAY_START_HOUR, 0);
            $dayEnd = $dayStart->modify(sprintf('+%d minutes', $daily));

            if ((int) $cursor->format('N') >= 6) {
                $cursor = $cursor->modify('+1 day')->setTime(self::DAY_START_HOUR, 0);
                continue;
            }

            if ($cursor < $dayStart) {
                return $dayStart;
            }

            if ($cursor >= $dayEnd) {
                $cursor = $cursor->modify('+1 day')->setTime(self::DAY_START_HOUR, 0);
                continue;
            }

            return $cursor;
        }

        return $cursor;
    }
}
