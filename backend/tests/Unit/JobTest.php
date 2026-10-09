<?php

namespace App\Tests\Unit;

use App\Entity\Job;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Enum\JobStatus;
use App\Exception\InvalidInputException;
use App\Exception\WorkflowException;
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

    public function testTaskWithoutScheduleOrPlannedTime(): void
    {
        $job = (new Job())->setTitle('Werkzeugwand beschriften');

        self::assertFalse($job->isScheduled());
        self::assertNull($job->getStartsAt());
        self::assertNull($job->getPlannedMinutes());
        self::assertNull($job->getRemainingMinutes());
        self::assertFalse($job->isOverrun());
        self::assertSame(0, $job->getActualSeconds());
        self::assertSame(JobStatus::Open, $job->getStatus());
    }

    public function testScheduleWithoutEndOrPlannedTimeIsRejected(): void
    {
        $this->expectException(InvalidInputException::class);

        (new Job())->schedule(new \DateTimeImmutable('2026-09-28 07:00'));
    }

    public function testMultiDayScheduleKeepsCalendarAndWorkTimeApart(): void
    {
        $job = (new Job())
            ->setPlannedMinutes(360)
            ->schedule(new \DateTimeImmutable('2026-09-28 09:00'), null, new \DateTimeImmutable('2026-09-29 11:00'));

        self::assertSame(26 * 60, $job->getCalendarMinutes());
        self::assertSame(360, $job->getPlannedMinutes());
    }

    /** Beispiel aus der Angabe: Mo 09–12 und Di 08–11 ergeben 6 Stunden, nicht 26. */
    public function testActualTimeIsTheSumOfSectionsAcrossDays(): void
    {
        $job = (new Job())->schedule(new \DateTimeImmutable('2026-09-28 09:00'), 360);
        $user = new User();
        (new TimeEntry($user, $job, new \DateTimeImmutable('2026-09-28 09:00')))->stop(new \DateTimeImmutable('2026-09-28 12:00'));
        (new TimeEntry($user, $job, new \DateTimeImmutable('2026-09-29 08:00')))->stop(new \DateTimeImmutable('2026-09-29 11:00'));

        self::assertSame(360, $job->getActualMinutes());
        self::assertSame(2, $job->getWorkedDays());
        self::assertSame(2, $job->getEntryCount());
        self::assertSame('2026-09-28 09:00', $job->getFirstStartedAt()?->format('Y-m-d H:i'));
    }

    public function testCompletingTwiceIsRejected(): void
    {
        $job = (new Job())->complete();

        $this->expectException(WorkflowException::class);
        $job->complete();
    }

    public function testCompletingWhileTimeIsRunningIsRejected(): void
    {
        $job = new Job();
        new TimeEntry(new User(), $job, new \DateTimeImmutable('-10 minutes'));

        $this->expectException(WorkflowException::class);
        $job->complete();
    }

    public function testCompletedJobCannotBeStartedAgain(): void
    {
        $job = (new Job())->complete();

        $this->expectException(WorkflowException::class);
        $job->markInProgress();
    }

    public function testPausedMeansStartedButNotRunning(): void
    {
        $job = $this->jobWithWork(120, 30);
        $job->markInProgress();

        self::assertTrue($job->isPaused());
        self::assertFalse($job->isRunning());
    }

    public function testOriginalPlanIsFrozenOnceWorkStarted(): void
    {
        $job = $this->jobWithWork(120, 30);
        $job->setPlannedMinutes(240);

        self::assertSame(240, $job->getPlannedMinutes());
        self::assertSame(120, $job->getOriginalPlannedMinutes());
    }

    private function jobWithWork(int $planned, int $worked): Job
    {
        $start = new \DateTimeImmutable('2026-01-07 08:00:00');
        $job = (new Job())->schedule($start, $planned);
        (new TimeEntry(new User(), $job, $start))->stop($start->modify(sprintf('+%d minutes', $worked)));

        return $job;
    }
}
