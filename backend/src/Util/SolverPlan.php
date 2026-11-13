<?php

namespace App\Util;

/** Ergebnis des ScheduleSolver: welche Buchungen wandern wohin? */
final class SolverPlan
{
    /**
     * @param array<int, Booking> $moves        verschobene Buchungen (mit neuen Zeiten), Schluessel = Booking::key, in Bearbeitungsreihenfolge
     * @param bool                $editedMoved  die bearbeitete Buchung selbst wurde verschoben
     */
    public function __construct(
        public readonly array $moves = [],
        public readonly bool $editedMoved = false,
    ) {
    }
}
