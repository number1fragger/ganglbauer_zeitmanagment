<?php

namespace App\Util;

use App\Exception\InvalidInputException;

/**
 * Reiner Algorithmus fuer "ein Mitarbeiter kann immer nur eine Aufgabe
 * gleichzeitig bearbeiten" – ohne Datenbank, nur mit Buchungen im Speicher.
 *
 * Eine Person (Arbeiter oder Lehrling) ist immer nur in einer Buchung. Beim
 * Verschieben bleibt die Arbeitszeit einer Buchung erhalten; Mittagspause,
 * Feierabend und Wochenenden werden uebersprungen (WorkingHours). Eine
 * verschobene Buchung weicht ausserdem Abwesenheiten aller ihrer Personen
 * aus. Nur wegen einer Abwesenheit wird nie verschoben.
 *
 * Feste Buchungen (Booking::$fixed) bewegen sich nie.
 *
 * Zwei Betriebsarten:
 *  - Einzelne Bearbeitung ($editedKey gesetzt): die bearbeitete Buchung weicht
 *    nur festen Buchungen aus. Danach werden die folgenden offenen Buchungen
 *    nach Beginn (dann Key) geprueft und verschoben, wenn sie eine
 *    "beruehrte" Buchung (bearbeitete oder schon verschobene) mit gemeinsamer
 *    Person ueberschneiden – auch ueber den Lehrling weiter (Kaskade).
 *    Bestehende Ueberschneidungen unbeteiligter Buchungen bleiben.
 *  - Neu sortieren ($editedKey null): alle nicht festen Buchungen werden nach
 *    Beginn abgearbeitet; jede Ueberschneidung mit einer schon bearbeiteten
 *    oder festen Buchung wird aufgeloest.
 */
final class ScheduleSolver
{
    public function __construct(private readonly int $maxIterations = 1000)
    {
    }

    /**
     * @param list<Booking>     $bookings  alle Buchungen (bei Einzelbearbeitung inkl. der bearbeiteten, mit ihren neuen Zeiten)
     * @param list<AbsenceSlot> $absences
     *
     * @throws InvalidInputException wenn kein freier Platz gefunden wird
     */
    public function solve(array $bookings, array $absences, ?int $editedKey = null): SolverPlan
    {
        $fixed = [];
        $open = [];
        $edited = null;
        foreach ($bookings as $booking) {
            if ($booking->key === $editedKey) {
                $edited = $booking;
            } elseif ($booking->fixed) {
                $fixed[] = $booking;
            } else {
                $open[] = $booking;
            }
        }
        usort($open, static fn (Booking $a, Booking $b): int => [$a->start, $a->key] <=> [$b->start, $b->key]);

        return null !== $editedKey
            ? $this->solveEdit($edited ?? throw new \LogicException('Die bearbeitete Buchung fehlt.'), $fixed, $open, $absences)
            : $this->resort($fixed, $open, $absences);
    }

    /**
     * @param list<Booking>     $fixed
     * @param list<Booking>     $open
     * @param list<AbsenceSlot> $absences
     */
    private function solveEdit(Booking $edited, array $fixed, array $open, array $absences): SolverPlan
    {
        $moves = [];
        $final = $edited;

        // 1. Die bearbeitete Buchung weicht nur festen Buchungen aus.
        $blocker = $this->blocker($edited, $fixed);
        if (null !== $blocker) {
            $final = $this->place($edited, $blocker->end, $fixed, $absences);
            $moves[$edited->key] = $final;
        }

        // 2. Folgende offene Buchungen rutschen nach hinten, wenn sie eine beruehrte ueberschneiden.
        $touched = [$final];
        $processed = [$final];
        foreach ($open as $booking) {
            $touchedBlocker = $this->blocker($booking, $touched);
            if (null === $touchedBlocker) {
                $processed[] = $booking;
                continue;
            }

            $moved = $this->place($booking, $touchedBlocker->end, [...$fixed, ...$processed], $absences);
            $moves[$booking->key] = $moved;
            $touched[] = $moved;
            $processed[] = $moved;
        }

        return new SolverPlan($moves, null !== $blocker);
    }

    /**
     * @param list<Booking>     $fixed
     * @param list<Booking>     $open
     * @param list<AbsenceSlot> $absences
     */
    private function resort(array $fixed, array $open, array $absences): SolverPlan
    {
        $moves = [];
        $processed = [];
        foreach ($open as $booking) {
            $blocker = $this->blocker($booking, [...$fixed, ...$processed]);
            if (null === $blocker) {
                $processed[] = $booking;
                continue;
            }

            $moved = $this->place($booking, $blocker->end, [...$fixed, ...$processed], $absences);
            $moves[$booking->key] = $moved;
            $processed[] = $moved;
        }

        return new SolverPlan($moves);
    }

    /**
     * Frueheste Position ab $after (Arbeitszeit), an der die Buchung mit
     * unveraenderter Arbeitszeit weder $obstacles noch eine Abwesenheit
     * ihrer Personen trifft.
     *
     * @param list<Booking>     $obstacles
     * @param list<AbsenceSlot> $absences
     */
    private function place(Booking $booking, \DateTimeImmutable $after, array $obstacles, array $absences): Booking
    {
        $minutes = max(1, WorkingHours::durationMinutes($booking->start, $booking->end));
        $start = WorkingHours::nextWorkingMoment($after);

        for ($i = 0; $i < $this->maxIterations; ++$i) {
            $end = WorkingHours::endFor($start, $minutes);
            $candidate = $booking->withTimes($start, $end);

            $until = $this->blocker($candidate, $obstacles)?->end;
            foreach ($absences as $absence) {
                if (\in_array($absence->personId, $booking->persons, true) && $absence->start < $end && $absence->end > $start) {
                    $until = max($until ?? $absence->end, $absence->end);
                }
            }

            if (null === $until) {
                return $candidate;
            }
            $start = WorkingHours::nextWorkingMoment($until);
        }

        throw new InvalidInputException('Es wurde kein freier Termin gefunden, an den die Arbeit verschoben werden kann.');
    }

    /**
     * Die Buchung mit dem spaetesten Ende, die sich mit $booking
     * ueberschneidet und mindestens eine Person teilt.
     *
     * @param list<Booking> $others
     */
    private function blocker(Booking $booking, array $others): ?Booking
    {
        $found = null;
        foreach ($others as $other) {
            if ($other->key !== $booking->key && $other->overlaps($booking->start, $booking->end) && $other->sharesPersonWith($booking)) {
                if (null === $found || $other->end > $found->end) {
                    $found = $other;
                }
            }
        }

        return $found;
    }
}
