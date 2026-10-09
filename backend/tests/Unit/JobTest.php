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
    public function testScheduleWithEnd(): void
    {
        $job = (new Job())->schedule(new \DateTimeImmutable('2026-09-28 12:00'), new \DateTimeImmutable('2026-09-28 15:00'));

        self::assertTrue($job->isScheduled());
        self::assertSame(180, $job->getCalendarMinutes());
    }

    /** Ohne Ende: technischer Standard-Kalenderblock, keine geplante Arbeitszeit. */
    public function testScheduleWithoutEndUsesTechnicalDefaultSlot(): void
    {
        $job = (new Job())->schedule(new \DateTimeImmutable('2026-09-28 12:00'));

        self::assertSame('13:00', $job->getEndsAt()?->format('H:i'));
        self::assertSame(Job::DEFAULT_SLOT_MINUTES, $job->getCalendarMinutes());
    }

    public function testEndBeforeStartIsRejected(): void
    {
        $this->expectException(InvalidInputException::class);

        (new Job())->schedule(new \DateTimeImmutable('2026-09-28 12:00'), new \DateTimeImmutable('2026-09-28 11:00'));
    }

    public function testIncomingUtcTimesAreStoredInLocalTime(): void
    {
        $job = (new Job())->schedule(new \DateTimeImmutable('2026-09-28T05:00:00Z'));

        self::assertSame('07:00', $job->getStartsAt()?->format('H:i'));
    }

    public function testNoPlannedTimeExistsAnymore(): void
    {
        self::assertFalse(method_exists(Job::class, 'getPlannedMinutes'));
        self::assertFalse(method_exists(Job::class, 'extendBy'));
        self::assertFalse(method_exists(Job::class, 'isOverrun'));
    }

    public function testTaskWithoutSchedule(): void
    {
        $job = (new Job())->setTitle('Werkzeugwand beschriften');

        self::assertFalse($job->isScheduled());
        self::assertNull($job->getStartsAt());
        self::assertNull($job->getCalendarMinutes());
        self::assertSame(0, $job->getActualSeconds());
        self::assertSame(JobStatus::Open, $job->getStatus());
    }

    /** Ausplanen loescht keine erfasste Zeit und aendert den Status nicht. */
    public function testUnschedulingKeepsActualTime(): void
    {
        $job = (new Job())->schedule(new \DateTimeImmutable('2026-09-28 09:00'), new \DateTimeImmutable('2026-09-28 12:00'));
        (new TimeEntry(new User(), $job, new \DateTimeImmutable('2026-09-28 09:00')))->stop(new \DateTimeImmutable('2026-09-28 10:30'));
        $job->markInProgress();

        $job->unschedule();

        self::assertFalse($job->isScheduled());
        self::assertSame(90, $job->getActualMinutes());
        self::assertSame(JobStatus::InProgress, $job->getStatus());
    }

    public function testMultiDayScheduleIsIndependentOfWorkTime(): void
    {
        $job = (new Job())->schedule(new \DateTimeImmutable('2026-09-28 09:00'), new \DateTimeImmutable('2026-09-29 11:00'));

        self::assertSame(26 * 60, $job->getCalendarMinutes());
        self::assertSame(0, $job->getActualMinutes());
    }

    /** Beispiel aus der Angabe: Mo 09–12 und Di 08–11 ergeben 6 Stunden, nicht 26. */
    public function testActualTimeIsTheSumOfSectionsAcrossDays(): void
    {
        $job = (new Job())->schedule(new \DateTimeImmutable('2026-09-28 09:00'), new \DateTimeImmutable('2026-09-29 11:00'));
        $user = new User();
        (new TimeEntry($user, $job, new \DateTimeImmutable('2026-09-28 09:00')))->stop(new \DateTimeImmutable('2026-09-28 12:00'));
        (new TimeEntry($user, $job, new \DateTimeImmutable('2026-09-29 08:00')))->stop(new \DateTimeImmutable('2026-09-29 11:00'));

        self::assertSame(360, $job->getActualMinutes());
        self::assertSame(2, $job->getWorkedDays());
        self::assertSame(2, $job->getEntryCount());
        self::assertSame('2026-09-28 09:00', $job->getFirstStartedAt()?->format('Y-m-d H:i'));
    }

    public function testCompletedJobCanBeReopenedAndKeepsTime(): void
    {
        $job = new Job();
        (new TimeEntry(new User(), $job, new \DateTimeImmutable('2026-01-07 08:00')))->stop(new \DateTimeImmutable('2026-01-07 09:30'));

        $job->complete();
        $job->reopen();

        self::assertSame(JobStatus::InProgress, $job->getStatus());
        self::assertNull($job->getCompletedAt());
        self::assertSame(90, $job->getActualMinutes());
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
        $job = new Job();
        (new TimeEntry(new User(), $job, new \DateTimeImmutable('2026-01-07 08:00')))->stop(new \DateTimeImmutable('2026-01-07 08:30'));
        $job->markInProgress();

        self::assertTrue($job->isPaused());
        self::assertFalse($job->isRunning());
    }
}
