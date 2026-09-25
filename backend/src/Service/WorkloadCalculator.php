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

    /**
     * Legt eine Arbeit auf die Arbeitszeit des Arbeiters: Beginnt sie um
     * 14:00 und dauert acht Stunden, entstehen zwei Bloecke – der Rest des
     * Tages und der Vormittag des naechsten Werktags. Daraus zeichnet der
     * Kalender die Karten.
     *
     * @return list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}>
     */
    public function splitIntoWorkingBlocks(User $user, \DateTimeImmutable $start, int $minutes): array
    {
        $daily = max(1, $user->getDailyMinutes());
        $cursor = $this->alignToWorkingTime($start, $daily);
        $left = max(1, $minutes);
        $blocks = [];

        for ($guard = 0; $left > 0 && $guard < 500; ++$guard) {
            $dayEnd = $cursor->setTime(self::DAY_START_HOUR, 0)->modify(sprintf('+%d minutes', $daily));
            $capacity = (int) floor(($dayEnd->getTimestamp() - $cursor->getTimestamp()) / 60);

            if ($capacity <= 0) {
                $cursor = $this->alignToWorkingTime($cursor->modify('+1 day')->setTime(0, 0), $daily);
                continue;
            }

            $take = min($left, $capacity);
            $end = $cursor->modify(sprintf('+%d minutes', $take));
            $blocks[] = ['start' => $cursor, 'end' => $end];

            $left -= $take;
            $cursor = $this->alignToWorkingTime($end->modify('+1 minute'), $daily);
        }

        return $blocks;
    }

    /** Ende der Arbeitszeit eines Tages, z. B. 14:42 bei 38,5 Wochenstunden. */
    public function dayEnd(User $user, \DateTimeImmutable $day): \DateTimeImmutable
    {
        return $day->setTime(self::DAY_START_HOUR, 0)->modify(sprintf('+%d minutes', max(1, $user->getDailyMinutes())));
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
