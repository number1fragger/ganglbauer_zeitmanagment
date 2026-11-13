<?php

namespace App\Util;

/** Abwesenheit einer Person (Urlaub, Arzttermin, ...) fuer den ScheduleSolver. */
final class AbsenceSlot
{
    public function __construct(
        public readonly int $personId,
        public readonly \DateTimeImmutable $start,
        public readonly \DateTimeImmutable $end,
    ) {
    }
}
