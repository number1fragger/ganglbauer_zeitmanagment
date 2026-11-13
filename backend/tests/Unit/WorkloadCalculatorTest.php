<?php

namespace App\Tests\Unit;

use App\Entity\User;
use App\Service\WorkloadCalculator;
use PHPUnit\Framework\TestCase;

/** Rechnet mit den zentralen Arbeitszeiten (Mo–Do 07:30–17:00, Fr bis 14:00, Pause 12–13). */
final class WorkloadCalculatorTest extends TestCase
{
    public function testRemainingWorkFinishesOnTheSameDay(): void
    {
        // Mittwoch, 08:00 – zwei Stunden Restarbeit enden um 10:00.
        $result = $this->calculate(120, '2026-01-07 08:00:00');

        self::assertSame('2026-01-07 10:00', $result->format('Y-m-d H:i'));
    }

    public function testLunchBreakDoesNotCount(): void
    {
        // Mittwoch 11:00 + 2 h Arbeit: 11–12 und 13–14.
        $result = $this->calculate(120, '2026-01-07 11:00:00');

        self::assertSame('2026-01-07 14:00', $result->format('Y-m-d H:i'));
    }

    public function testWorkSpillsIntoTheNextWorkingDay(): void
    {
        // Mittwoch 14:00: bis 17:00 drei Stunden, die restlichen fuenf am Donnerstag ab 07:30.
        $result = $this->calculate(480, '2026-01-07 14:00:00');

        self::assertSame('2026-01-08 13:30', $result->format('Y-m-d H:i'));
    }

    public function testWeekendIsSkipped(): void
    {
        // Samstag – die Arbeit beginnt erst am Montag um 07:30.
        $result = $this->calculate(60, '2026-01-10 09:00:00');

        self::assertSame('2026-01-12 08:30', $result->format('Y-m-d H:i'));
    }

    public function testFridayEndsEarly(): void
    {
        // Freitag 13:30 + 1 h: halbe Stunde bis 14:00, der Rest am Montag.
        $result = $this->calculate(60, '2026-01-09 13:30:00');

        self::assertSame('2026-01-12 08:00', $result->format('Y-m-d H:i'));
    }

    public function testWorkerWithoutOpenJobsIsFreeImmediately(): void
    {
        $result = $this->calculate(0, '2026-01-07 08:00:00');

        self::assertSame('2026-01-07 08:00', $result->format('Y-m-d H:i'));
    }

    public function testFreeAfterHoursMeansNextMorning(): void
    {
        $result = $this->calculate(0, '2026-01-07 17:30:00');

        self::assertSame('2026-01-08 07:30', $result->format('Y-m-d H:i'));
    }

    private function calculate(int $minutes, string $from): \DateTimeImmutable
    {
        $user = (new User())->setWeeklyHours(38.5);

        return (new WorkloadCalculator())->estimateAvailableFrom($user, $minutes, new \DateTimeImmutable($from));
    }
}
