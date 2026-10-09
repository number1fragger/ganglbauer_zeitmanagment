<?php

namespace App\Tests\Unit;

use App\Entity\Job;
use App\Repository\JobRepository;
use App\Service\JobScheduler;
use PHPUnit\Framework\TestCase;

/** F5 – Folgetermine ruecken nach, wenn eine Arbeit laenger dauert. */
final class JobSchedulerTest extends TestCase
{
    public function testFollowingJobsAreShifted(): void
    {
        $job = (new Job())->schedule(new \DateTimeImmutable('2026-09-28 07:00'), 120);
        $next = (new Job())->schedule(new \DateTimeImmutable('2026-09-28 09:00'), 60);

        $repository = $this->createMock(JobRepository::class);
        $repository->expects(self::once())
            ->method('findFollowing')
            ->with($job, new \DateTimeImmutable('2026-09-28 09:00'))
            ->willReturn([$next]);

        (new JobScheduler($repository))->extend($job, 60);

        self::assertSame('10:00', $job->getEndsAt()->format('H:i'));
        self::assertSame('10:00', $next->getStartsAt()->format('H:i'));
        self::assertSame('11:00', $next->getEndsAt()->format('H:i'));
    }

    public function testNonPositiveExtensionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new JobScheduler($this->createStub(JobRepository::class)))->extend(new Job(), 0);
    }
}
