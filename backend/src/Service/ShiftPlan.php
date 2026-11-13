<?php

namespace App\Service;

/**
 * Plan des ScheduleShifter: was wuerde verschoben? Aendert nichts, bis
 * apply() aufgerufen wird.
 */
final class ShiftPlan
{
    /**
     * @param list<PlannedMove>       $moved      andere Arbeiten, die verschoben wuerden (neue Zeiten)
     * @param bool                    $selfMoved  die bearbeitete Arbeit selbst wird verschoben
     * @param \DateTimeImmutable|null $startsAt   Beginn der bearbeiteten Arbeit nach dem Plan (nur bei Einzelbearbeitung)
     * @param \DateTimeImmutable|null $endsAt     Ende der bearbeiteten Arbeit nach dem Plan
     */
    public function __construct(
        public readonly array $moved = [],
        public readonly bool $selfMoved = false,
        public readonly ?\DateTimeImmutable $startsAt = null,
        public readonly ?\DateTimeImmutable $endsAt = null,
    ) {
    }

    /** Verschiebt die anderen Arbeiten (die bearbeitete Arbeit setzt der Aufrufer selbst). */
    public function applyMoved(): void
    {
        foreach ($this->moved as $move) {
            $move->apply();
        }
    }
}
