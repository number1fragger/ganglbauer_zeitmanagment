<?php

namespace App\Tests\Unit;

use App\Exception\InvalidInputException;
use App\Util\AbsenceSlot;
use App\Util\Booking;
use App\Util\ScheduleSolver;
use App\Util\SolverPlan;
use PHPUnit\Framework\TestCase;

/**
 * Verschieben ohne Datenbank. Personen: 1 = Arbeiter W, 2 = Lehrling L, 3 = anderer Arbeiter.
 * 2026-09-28 ist ein Montag; Arbeitszeit Mo-Do 07:30-17:00, Fr 07:30-14:00, Pause 12-13.
 */
final class ScheduleSolverTest extends TestCase
{
    private const W = 1;
    private const L = 2;

    /** @param list<int> $persons */
    private function b(int $key, string $start, string $end, array $persons = [self::W], bool $fixed = false): Booking
    {
        return new Booking($key, new \DateTimeImmutable($start), new \DateTimeImmutable($end), $persons, $fixed);
    }

    private function times(SolverPlan $plan, int $key): string
    {
        $b = $plan->moves[$key] ?? self::fail("Buchung $key wurde nicht verschoben");

        return $b->start->format('D H:i').'-'.$b->end->format('D H:i');
    }

    public function testNothingMovesWithoutOverlap(): void
    {
        $plan = (new ScheduleSolver())->solve([
            $this->b(0, '2026-09-28 08:00', '2026-09-28 09:00'),
            $this->b(1, '2026-09-28 09:00', '2026-09-28 10:00'),
        ], [], 0);

        self::assertSame([], $plan->moves);
        self::assertFalse($plan->editedMoved);
    }

    public function testFollowingJobCrossesLunchBreak(): void
    {
        // Neu 08:00-11:30; das folgende (3 h Arbeit, 10-14 Uhr) beginnt 11:30 -> 11:30-12:00 + 13:00-15:30.
        $plan = (new ScheduleSolver())->solve([
            $this->b(0, '2026-09-28 08:00', '2026-09-28 11:30'),
            $this->b(1, '2026-09-28 10:00', '2026-09-28 14:00'),
        ], [], 0);

        self::assertSame('Mon 11:30-Mon 15:30', $this->times($plan, 1));
    }

    public function testFridayAfternoonJobContinuesOnMonday(): void
    {
        // Die feste Arbeit geht bis Fr 13:00; die 2-h-Arbeit weicht aus: Fr 13:00-14:00 + Mo 07:30-08:30.
        $plan = (new ScheduleSolver())->solve([
            $this->b(0, '2026-10-02 09:00', '2026-10-02 11:00'),
            $this->b(9, '2026-10-02 08:00', '2026-10-02 13:00', [self::W], true),
        ], [], 0);

        self::assertTrue($plan->editedMoved);
        self::assertSame('Fri 13:00-Mon 08:30', $this->times($plan, 0));
    }

    public function testFollowingJobStartingLateOnFridayContinuesOnMonday(): void
    {
        // Neu bis Fr 13:30; das folgende (1 h) hat bis 14:00 nur 30 min, der Rest ist am Montag.
        $plan = (new ScheduleSolver())->solve([
            $this->b(0, '2026-10-02 12:30', '2026-10-02 13:30'),
            $this->b(1, '2026-10-02 13:00', '2026-10-02 14:00'),
        ], [], 0);

        self::assertSame('Fri 13:30-Mon 08:00', $this->times($plan, 1));
    }

    public function testWeekendIsSkipped(): void
    {
        // Folgende Arbeit (1 h) hinter einer Arbeit, die Freitag bis 14:00 geht -> Montag 07:30.
        $plan = (new ScheduleSolver())->solve([
            $this->b(0, '2026-10-02 13:00', '2026-10-02 14:00'),
            $this->b(1, '2026-10-02 13:30', '2026-10-02 14:00'),
        ], [], 0);

        self::assertSame('Mon 07:30-Mon 08:00', $this->times($plan, 1));
    }

    public function testMultiDayJobKeepsItsWorkingHours(): void
    {
        // Arbeitszeit: Mo 08-12 (4 h) + 13-17 (4 h) + Di 07:30-10:00 (2,5 h) = 10,5 h.
        $long = $this->b(1, '2026-09-28 08:00', '2026-09-29 10:00');
        $plan = (new ScheduleSolver())->solve([
            $this->b(0, '2026-09-28 07:30', '2026-09-28 09:00'),
            $long,
        ], [], 0);

        // Ab 09:00: 3 h + 4 h am Montag, 3,5 h am Dienstag (07:30-11:00).
        self::assertSame('Mon 09:00-Tue 11:00', $this->times($plan, 1));
    }

    public function testMovedJobSkipsVacation(): void
    {
        $vacation = new AbsenceSlot(self::W, new \DateTimeImmutable('2026-09-29 00:00'), new \DateTimeImmutable('2026-09-30 23:59'));
        // Neu 16:00-17:00 am Montag, das folgende (1 h) kaeme Dienstag 07:30 -> Urlaub bis Mittwoch -> Donnerstag 07:30.
        $plan = (new ScheduleSolver())->solve([
            $this->b(0, '2026-09-28 16:00', '2026-09-28 17:00'),
            $this->b(1, '2026-09-28 16:30', '2026-09-28 17:00'),
        ], [$vacation], 0);

        self::assertSame('Thu 07:30-Thu 08:00', $this->times($plan, 1));
    }

    public function testAbsenceOfOtherPersonDoesNotMatter(): void
    {
        $vacation = new AbsenceSlot(3, new \DateTimeImmutable('2026-09-29 00:00'), new \DateTimeImmutable('2026-09-30 23:59'));
        $plan = (new ScheduleSolver())->solve([
            $this->b(0, '2026-09-28 16:00', '2026-09-28 17:00'),
            $this->b(1, '2026-09-28 16:30', '2026-09-28 17:00'),
        ], [$vacation], 0);

        self::assertSame('Tue 07:30-Tue 08:00', $this->times($plan, 1));
    }

    public function testEditedJobInsideAbsenceWithoutOverlapDoesNotMove(): void
    {
        $vacation = new AbsenceSlot(self::W, new \DateTimeImmutable('2026-09-28 00:00'), new \DateTimeImmutable('2026-09-28 23:59'));
        $plan = (new ScheduleSolver())->solve([$this->b(0, '2026-09-28 08:00', '2026-09-28 09:00')], [$vacation], 0);

        self::assertSame([], $plan->moves);
        self::assertFalse($plan->editedMoved);
    }

    public function testEditedJobMovedBehindFixedJobAlsoAvoidsAbsence(): void
    {
        $absence = new AbsenceSlot(self::W, new \DateTimeImmutable('2026-09-28 10:00'), new \DateTimeImmutable('2026-09-28 11:00'));
        $plan = (new ScheduleSolver())->solve([
            $this->b(0, '2026-09-28 08:30', '2026-09-28 09:30'),
            $this->b(9, '2026-09-28 08:00', '2026-09-28 09:00', [self::W], true),
        ], [$absence], 0);

        // Hinter der festen Arbeit waere 09:00-10:00 frei (Abwesenheit beginnt 10:00).
        self::assertTrue($plan->editedMoved);
        self::assertSame('Mon 09:00-Mon 10:00', $this->times($plan, 0));

        $plan = (new ScheduleSolver())->solve([
            $this->b(0, '2026-09-28 08:30', '2026-09-28 10:00'),
            $this->b(9, '2026-09-28 08:00', '2026-09-28 09:00', [self::W], true),
        ], [$absence], 0);
        // 1,5 h ab 09:00 trifft die Abwesenheit -> 11:00-12:00 + 13:00-13:30.
        self::assertSame('Mon 11:00-Mon 13:30', $this->times($plan, 0));
    }

    public function testApprenticeConflictMovesTheApprenticesOwnJob(): void
    {
        // Auftrag A (W mit Lehrling L) 08-10; Lehrlings eigener Auftrag B (L) 09-10 wird hinter A geschoben.
        $plan = (new ScheduleSolver())->solve([
            $this->b(0, '2026-09-28 08:00', '2026-09-28 10:00', [self::W, self::L]),
            $this->b(1, '2026-09-28 09:00', '2026-09-28 10:00', [self::L]),
        ], [], 0);

        self::assertSame('Mon 10:00-Mon 11:00', $this->times($plan, 1));
    }

    public function testCascadeRunsThroughTheApprentice(): void
    {
        // Neu A (W) 08-09:30. B (W + L) 08:30-09:30 -> 09:30-10:30. C (nur L) 10:00-11:00 ueberschneidet B neu -> 10:30-11:30.
        $plan = (new ScheduleSolver())->solve([
            $this->b(0, '2026-09-28 08:00', '2026-09-28 09:30'),
            $this->b(1, '2026-09-28 08:30', '2026-09-28 09:30', [self::W, self::L]),
            $this->b(2, '2026-09-28 10:00', '2026-09-28 11:00', [self::L]),
            $this->b(3, '2026-09-28 14:00', '2026-09-28 15:00', [self::L]),
        ], [], 0);

        self::assertSame([1, 2], array_keys($plan->moves));
        self::assertSame('Mon 09:30-Mon 10:30', $this->times($plan, 1));
        self::assertSame('Mon 10:30-Mon 11:30', $this->times($plan, 2));
    }

    public function testFixedJobsNeverMove(): void
    {
        // in_arbeit/erledigt = fest: die folgende feste Arbeit bleibt, obwohl sie ueberschneidet.
        $plan = (new ScheduleSolver())->solve([
            $this->b(0, '2026-09-28 09:00', '2026-09-28 10:00'),
            $this->b(1, '2026-09-28 09:30', '2026-09-28 10:30', [self::W], true),
            $this->b(2, '2026-09-28 10:00', '2026-09-28 11:00'),
        ], [], 0);

        // Neu weicht der festen aus (-> 10:30-11:30), die offene Arbeit 2 weicht der neuen aus.
        self::assertTrue($plan->editedMoved);
        self::assertSame('Mon 10:30-Mon 11:30', $this->times($plan, 0));
        self::assertArrayNotHasKey(1, $plan->moves);
        self::assertSame('Mon 11:30-Mon 13:30', $this->times($plan, 2));
    }

    public function testUntouchedOverlapStaysInEditModeButIsResolvedOnResort(): void
    {
        $bookings = [
            $this->b(0, '2026-09-28 08:00', '2026-09-28 09:00'),
            $this->b(1, '2026-09-29 08:00', '2026-09-29 10:00'),
            $this->b(2, '2026-09-29 09:00', '2026-09-29 10:00'),
        ];
        $solver = new ScheduleSolver();

        self::assertSame([], $solver->solve($bookings, [], 0)->moves);

        $plan = $solver->solve($bookings, []);
        self::assertSame([2], array_keys($plan->moves));
        self::assertSame('Tue 10:00-Tue 11:00', $this->times($plan, 2));
        self::assertFalse($plan->editedMoved);
    }

    public function testResortKeepsFixedJobsAndResolvesChains(): void
    {
        $plan = (new ScheduleSolver())->solve([
            $this->b(1, '2026-09-28 08:00', '2026-09-28 09:00', [self::W], true),
            $this->b(2, '2026-09-28 08:30', '2026-09-28 09:30'),
            $this->b(3, '2026-09-28 09:00', '2026-09-28 10:00'),
        ], []);

        self::assertSame([2, 3], array_keys($plan->moves));
        self::assertSame('Mon 09:00-Mon 10:00', $this->times($plan, 2));
        self::assertSame('Mon 10:00-Mon 11:00', $this->times($plan, 3));
    }

    public function testGuardThrowsWhenNoSlotIsFound(): void
    {
        // Jeder Tag der naechsten zwei Wochen ist Urlaub: mit nur 5 Versuchen wird kein Platz gefunden.
        $days = [];
        for ($i = 0; $i < 14; ++$i) {
            $day = (new \DateTimeImmutable('2026-09-28 00:00'))->modify("+$i days");
            $days[] = new AbsenceSlot(self::W, $day, $day->modify('+1 day'));
        }

        $this->expectException(InvalidInputException::class);
        (new ScheduleSolver(5))->solve([
            $this->b(0, '2026-09-28 08:00', '2026-09-28 09:00'),
            $this->b(1, '2026-09-28 08:30', '2026-09-28 09:30'),
        ], $days, 0);
    }
}
