<?php

namespace App\Tests\Unit;

use App\Entity\Job;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Repository\JobRepository;
use App\Service\SollIstReport;
use PHPUnit\Framework\TestCase;

/** A1 – Soll/Ist-Vergleich. */
final class SollIstReportTest extends TestCase
{
    public function testTotalsAccuracyAndDeviations(): void
    {
        $anton = (new User())->setFirstName('Anton')->setLastName('Huber');
        $jobs = [
            $this->done($anton, 'Kupplung', 120, 180),
            $this->done($anton, 'Ölwechsel', 60, 30),
            $this->done($anton, 'Ohne Zeiterfassung', 60, 0),
        ];

        $repository = $this->createStub(JobRepository::class);
        $repository->method('findCompletedBetween')->willReturnOnConsecutiveCalls($jobs, []);

        $report = (new SollIstReport($repository))->build(
            new \DateTimeImmutable('2026-09-28'),
            new \DateTimeImmutable('2026-10-05'),
        );

        self::assertSame(180, $report['totals']['plannedMinutes']);
        self::assertSame(210, $report['totals']['actualMinutes']);
        self::assertSame(2, $report['totals']['jobs']);
        self::assertSame(1, $report['totals']['overruns']);
        // 90 Minuten Abweichung bei 180 Minuten Soll
        self::assertSame(50.0, $report['totals']['accuracyPercent']);
        self::assertNull($report['totals']['accuracyDelta']);
        self::assertSame(['Kupplung', 'Ölwechsel'], array_column($report['deviations'], 'title'));
        self::assertSame([60, -30], array_column($report['deviations'], 'diffMinutes'));
        self::assertSame('2026-10-04', $report['to']);
    }

    private function done(User $worker, string $title, int $planned, int $actual): Job
    {
        $start = new \DateTimeImmutable('2026-09-29 07:00');
        $job = (new Job())->setTitle($title)->setAssignee($worker)->schedule($start, $planned);

        if ($actual > 0) {
            (new TimeEntry($worker, $job, $start))->stop($start->modify(sprintf('+%d minutes', $actual)));
        }

        return $job->complete();
    }
}
