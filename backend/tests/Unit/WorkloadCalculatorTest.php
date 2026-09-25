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

    public function testShortJobIsOneBlock(): void
    {
        $blocks = $this->split('2026-01-07 09:00:00', 90);

        self::assertCount(1, $blocks);
        self::assertSame('2026-01-07 09:00', $blocks[0]['start']->format('Y-m-d H:i'));
        self::assertSame('2026-01-07 10:30', $blocks[0]['end']->format('Y-m-d H:i'));
    }

    public function testLongJobIsSplitAcrossWorkingDays(): void
    {
        // Tagesende bei 38,5 h: 07:00 + 462 min = 14:42.
        // Start Mittwoch 13:00, 300 min: 102 min am Mittwoch, 198 min am Donnerstag.
        $blocks = $this->split('2026-01-07 13:00:00', 300);

        self::assertCount(2, $blocks);
        self::assertSame('2026-01-07 14:42', $blocks[0]['end']->format('Y-m-d H:i'));
        self::assertSame('2026-01-08 07:00', $blocks[1]['start']->format('Y-m-d H:i'));
        self::assertSame('2026-01-08 10:18', $blocks[1]['end']->format('Y-m-d H:i'));
    }

    public function testSplitSkipsTheWeekend(): void
    {
        // Freitag 14:00 – der Rest geht am Montag weiter.
        $blocks = $this->split('2026-01-09 14:00:00', 120);

        self::assertCount(2, $blocks);
        self::assertSame('2026-01-12 07:00', $blocks[1]['start']->format('Y-m-d H:i'));
    }

    public function testStartBeforeWorkingHoursIsMovedToDayStart(): void
    {
        $blocks = $this->split('2026-01-07 05:30:00', 60);

        self::assertSame('2026-01-07 07:00', $blocks[0]['start']->format('Y-m-d H:i'));
    }

    /** @return list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}> */
    private function split(string $start, int $minutes): array
    {
        $user = (new User())->setWeeklyHours(38.5);

        return (new WorkloadCalculator())->splitIntoWorkingBlocks($user, new \DateTimeImmutable($start), $minutes);
    }

    private function calculate(int $minutes, string $from): \DateTimeImmutable
    {
        $user = (new User())->setWeeklyHours(38.5);

        return (new WorkloadCalculator())->estimateAvailableFrom($user, $minutes, new \DateTimeImmutable($from));
    }
}
