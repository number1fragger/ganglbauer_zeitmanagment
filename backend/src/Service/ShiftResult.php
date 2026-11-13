<?php

namespace App\Service;

use App\Entity\Job;

/** Ergebnis von ScheduleShifter::resolve(): was wurde verschoben? */
final class ShiftResult
{
    /**
     * @param list<Job> $moved       andere Arbeiten, die verschoben wurden
     * @param bool      $selfMoved   die bearbeitete Arbeit selbst wurde verschoben
     */
    public function __construct(
        public readonly array $moved = [],
        public readonly bool $selfMoved = false,
    ) {
    }
}
