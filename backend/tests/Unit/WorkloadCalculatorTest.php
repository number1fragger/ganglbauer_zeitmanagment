<?php

namespace App\Tests\Unit;

use App\Entity\User;
use App\Service\WorkloadCalculator;
use PHPUnit\Framework\TestCase;

class WorkloadCalculatorTest extends TestCase
{
    public function testRemainingWorkFinishesOnTheSameDay(): void
    {
        // Mittwoch, 08:00 – zwei Stunden Restarbeit enden um 10:00.
        $result = $this->calculate(120, '2026-01-07 08:00:00');

        self::assertSame('2026-01-07 10:00', $result->format('Y-m-d H:i'));
    }

    public function testWorkSpillsIntoTheNextWorkingDay(): void
    {
        // 462 Minuten Tagesleistung bei 38,5 Wochenstunden.
        // Start Mittwoch 14:00 – der Rest landet am Donnerstag.
        $result = $this->calculate(480, '2026-01-07 14:00:00');

        self::assertSame('2026-01-08', $result->format('Y-m-d'));
    }

    public function testWeekendIsSkipped(): void
    {
        // Samstag – die Arbeit beginnt erst am Montag um 07:00.
        $result = $this->calculate(60, '2026-01-10 09:00:00');

        self::assertSame('2026-01-12 08:00', $result->format('Y-m-d H:i'));
    }

    public function testWorkerWithoutOpenJobsIsFreeImmediately(): void
    {
        $result = $this->calculate(0, '2026-01-07 08:00:00');

        self::assertSame('2026-01-07 08:00', $result->format('Y-m-d H:i'));
    }

    private function calculate(int $minutes, string $from): \DateTimeImmutable
    {
        $user = (new User())->setWeeklyHours(38.5);

        return (new WorkloadCalculator())->estimateAvailableFrom($user, $minutes, new \DateTimeImmutable($from));
    }
}
