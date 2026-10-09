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
        self::assertFalse($entry->isRunning());
    }

    public function testStoppingImmediatelyCountsAtLeastOneMinute(): void
    {
        $start = new \DateTimeImmutable('2026-01-07 08:00:00');

        self::assertSame(1, $this->entry($start)->stop($start)->getDurationMinutes());
    }

    public function testRunningEntryCountsUntilNow(): void
    {
        $entry = $this->entry(new \DateTimeImmutable('-90 minutes'));

        self::assertTrue($entry->isRunning());
        self::assertGreaterThanOrEqual(89, $entry->getDurationMinutes());
    }

    private function entry(\DateTimeImmutable $start): TimeEntry
    {
        return new TimeEntry(new User(), new Job(), $start);
    }
}
