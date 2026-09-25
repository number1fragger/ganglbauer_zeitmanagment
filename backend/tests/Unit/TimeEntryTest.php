<?php

namespace App\Tests\Unit;

use App\Entity\TimeEntry;
use PHPUnit\Framework\TestCase;

class TimeEntryTest extends TestCase
{
    public function testDurationOfClosedEntry(): void
    {
        $entry = new TimeEntry();
        $entry->setStartedAt(new \DateTimeImmutable('2026-01-07 08:00:00'));
        $entry->setEndedAt(new \DateTimeImmutable('2026-01-07 12:30:00'));

        self::assertSame(270, $entry->getDurationMinutes());
        self::assertFalse($entry->isRunning());
    }

    public function testRunningEntryHasNoEnd(): void
    {
        $entry = new TimeEntry();
        $entry->setStartedAt(new \DateTimeImmutable('-90 minutes'));

        self::assertTrue($entry->isRunning());
        self::assertGreaterThanOrEqual(89, $entry->getDurationMinutes());
    }
}
