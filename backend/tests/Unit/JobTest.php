<?php

namespace App\Tests\Unit;

use App\Entity\Job;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Enum\JobStatus;
use PHPUnit\Framework\TestCase;

final class JobTest extends TestCase
{
    public function testEndDefaultsToStartPlusPlannedTime(): void
    {
        $job = (new Job())->schedule(new \DateTimeImmutable('2026-09-28 12:00'), 180);

        self::assertSame('2026-09-28 15:00', $job->getEndsAt()->format('Y-m-d H:i'));
        self::assertSame(180, $job->getOriginalPlannedMinutes());
    }

    public function testEndBeforeStartIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Job())->schedule(new \DateTimeImmutable('2026-09-28 12:00'), 60, new \DateTimeImmutable('2026-09-28 11:00'));
    }

    public function testIncomingUtcTimesAreStoredInLocalTime(): void
    {
        $job = (new Job())->schedule(new \DateTimeImmutable('2026-09-28T05:00:00Z'), 60);

        self::assertSame('07:00', $job->getStartsAt()->format('H:i'));
    }

    public function testExtendingMovesTheEndButKeepsTheOriginalPlan(): void
    {
        $job = (new Job())->schedule(new \DateTimeImmutable('2026-09-28 12:00'), 120);

        $job->extendBy(60);

        self::assertSame(180, $job->getPlannedMinutes());
        self::assertSame(120, $job->getOriginalPlannedMinutes());
        self::assertSame('15:00', $job->getEndsAt()->format('H:i'));
    }

    public function testOverrunIsDetectedWhenActualExceedsPlanned(): void
    {
        $job = $this->jobWithWork(120, 150);

        self::assertTrue($job->isOverrun());
        self::assertSame(30, $job->getOverrunMinutes());
        self::assertSame(0, $job->getRemainingMinutes());
    }

    public function testRemainingMinutesShrinkWithLoggedTime(): void
    {
        $job = $this->jobWithWork(240, 90);

        self::assertFalse($job->isOverrun());
        self::assertSame(150, $job->getRemainingMinutes());
    }

    public function testCompletedJobHasNoRemainingTimeAndCanBeReopened(): void
    {
        $job = $this->jobWithWork(240, 90);

        $job->complete();
        self::assertSame(0, $job->getRemainingMinutes());

        $job->reopen();
        self::assertSame(JobStatus::InProgress, $job->getStatus());
        self::assertNull($job->getCompletedAt());
    }

    private function jobWithWork(int $planned, int $worked): Job
    {
        $start = new \DateTimeImmutable('2026-01-07 08:00:00');
        $job = (new Job())->schedule($start, $planned);
        (new TimeEntry(new User(), $job, $start))->stop($start->modify(sprintf('+%d minutes', $worked)));

        return $job;
    }
}
