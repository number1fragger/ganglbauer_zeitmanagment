<?php

namespace App\Tests\Unit;

use App\Entity\Job;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Enum\JobStatus;
use PHPUnit\Framework\TestCase;

class JobTest extends TestCase
{
    public function testPlannedTimeCanBeExtended(): void
    {
        $job = new Job();
        $job->setPlannedMinutes(120);

        $job->extendBy(60);

        self::assertSame(180, $job->getPlannedMinutes());
        self::assertSame(120, $job->getOriginalPlannedMinutes());
        self::assertSame(60, $job->getExtendedMinutes());
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
        self::assertSame(38, $job->getProgressPercent());
    }

    public function testCompletedJobHasNoRemainingTime(): void
    {
        $job = $this->jobWithWork(240, 90);
        $job->complete();

        self::assertSame(JobStatus::Done, $job->getStatus());
        self::assertSame(0, $job->getRemainingMinutes());
        self::assertNotNull($job->getCompletedAt());
    }

    private function jobWithWork(int $planned, int $worked): Job
    {
        $job = new Job();
        $job->setPlannedMinutes($planned);

        $start = new \DateTimeImmutable('2026-01-07 08:00:00');
        $entry = (new TimeEntry())
            ->setUser(new User())
            ->setJob($job)
            ->setStartedAt($start)
            ->setEndedAt($start->modify(sprintf('+%d minutes', $worked)));

        $job->getTimeEntries()->add($entry);

        return $job;
    }

    public function testMoveKeepsTheDuration(): void
    {
        $job = (new Job())
            ->setStartsAt(new \DateTimeImmutable('2026-01-07 08:00'))
            ->setDueAt(new \DateTimeImmutable('2026-01-07 11:00'));

        $job->moveTo(new \DateTimeImmutable('2026-01-08 13:30'));

        self::assertSame('2026-01-08 13:30', $job->getStartsAt()?->format('Y-m-d H:i'));
        self::assertSame('2026-01-08 16:30', $job->getDueAt()?->format('Y-m-d H:i'));
    }
}
