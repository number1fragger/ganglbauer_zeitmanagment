<?php

namespace App\Tests\Unit;

use App\Entity\Job;
use App\Entity\TimeEntry;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class TimeEntryTest extends TestCase
{
    public function testDurationOfClosedEntry(): void
    {
        $entry = $this->entry(new \DateTimeImmutable('2026-01-07 08:00:00'))
            ->stop(new \DateTimeImmutable('2026-01-07 12:30:00'));

        self::assertSame(270, $entry->getDurationMinutes());
        self::assertSame(270 * 60, $entry->getDurationSeconds());
        self::assertFalse($entry->isRunning());
        self::assertFalse($entry->isAutoClosed());
    }

    /** Start und sofortiger Stopp (Doppelklick) erzeugen keine kuenstliche Arbeitszeit. */
    public function testStoppingImmediatelyCountsNothing(): void
    {
        $start = new \DateTimeImmutable('2026-01-07 08:00:00');

        self::assertSame(0, $this->entry($start)->stop($start)->getDurationSeconds());
    }

    public function testEndBeforeStartIsClampedToZero(): void
    {
        $entry = $this->entry(new \DateTimeImmutable('2026-01-07 08:00:00'))
            ->stop(new \DateTimeImmutable('2026-01-07 07:00:00'));

        self::assertSame(0, $entry->getDurationSeconds());
    }

    public function testStoppingTwiceKeepsTheFirstEnd(): void
    {
        $entry = $this->entry(new \DateTimeImmutable('2026-01-07 08:00:00'))
            ->stop(new \DateTimeImmutable('2026-01-07 09:00:00'))
            ->stop(new \DateTimeImmutable('2026-01-07 11:00:00'));

        self::assertSame(60, $entry->getDurationMinutes());
    }

    public function testRunningEntryCountsUntilNow(): void
    {
        $entry = $this->entry(new \DateTimeImmutable('-90 minutes'));

        self::assertTrue($entry->isRunning());
        self::assertGreaterThanOrEqual(89, $entry->getDurationMinutes());
    }

    /** Vergessen zu stoppen: ueber Nacht entsteht keine Arbeitszeit. */
    public function testForgottenEntryIsCappedInTheEvening(): void
    {
        $entry = $this->entry(new \DateTimeImmutable('2026-01-07 08:00:00'));
        $nextMorning = new \DateTimeImmutable('2026-01-08 07:30:00');

        self::assertSame(12 * 3600, $entry->getDurationSeconds($nextMorning));
        self::assertTrue($entry->closeIfStale($nextMorning));
        self::assertTrue($entry->isAutoClosed());
        self::assertSame('2026-01-07 20:00', $entry->getEndedAt()?->format('Y-m-d H:i'));
    }

    public function testLateStartIsCappedAfterFourHours(): void
    {
        $entry = $this->entry(new \DateTimeImmutable('2026-01-07 21:00:00'));

        self::assertSame('2026-01-08 01:00', $entry->autoCloseAt()->format('Y-m-d H:i'));
    }

    public function testEntryWithinTheDayIsNotStale(): void
    {
        $entry = $this->entry(new \DateTimeImmutable('2026-01-07 08:00:00'));

        self::assertFalse($entry->closeIfStale(new \DateTimeImmutable('2026-01-07 16:00:00')));
        self::assertTrue($entry->isRunning());
    }

    private function entry(\DateTimeImmutable $start): TimeEntry
    {
        return new TimeEntry(new User(), new Job(), $start);
    }
}
