<?php

namespace App\Service;

use App\Entity\TimeEntry;
use App\Repository\JobRepository;
use App\Repository\TimeEntryRepository;
use Psr\Clock\ClockInterface;

/**
 * Auswertung der tatsaechlich geleisteten Arbeitszeit (Ist-Zeit) in einem
 * Zeitraum – je Arbeiter, je Wochentag und die aufwendigsten Arbeiten.
 *
 * Grundlage sind ausschliesslich die erfassten Arbeitsabschnitte. Ein
 * Abschnitt, der ueber die Grenze des Zeitraums reicht, zaehlt nur mit dem
 * Teil innerhalb des Zeitraums. Eine geplante Dauer gibt es nicht.
 */
class ActualTimeReport
{
    private const TOP_JOBS = 5;

    public function __construct(
        private readonly TimeEntryRepository $entries,
        private readonly JobRepository $jobs,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $entries = $this->entries->findInRange($from, $to);
        $previous = $this->entries->findInRange($from->sub($from->diff($to)), $from);
        $previousSeconds = array_sum(array_map(fn (TimeEntry $e): int => $this->secondsWithin($e, $from->sub($from->diff($to)), $from), $previous));

        $total = 0;
        $workers = [];
        $jobs = [];
        $days = [];
        $autoClosed = 0;

        foreach ($entries as $entry) {
            $seconds = $this->secondsWithin($entry, $from, $to);
            if ($seconds <= 0) {
                continue;
            }

            $total += $seconds;
            $autoClosed += $entry->isAutoClosed() ? 1 : 0;
            $user = $entry->getUser();
            $job = $entry->getJob();
            $day = $entry->getStartedAt()->format('Y-m-d');

            $workers[$user->getId()] ??= ['workerId' => $user->getId(), 'worker' => $user->getFullName(), 'seconds' => 0, 'jobs' => [], 'days' => []];
            $workers[$user->getId()]['seconds'] += $seconds;
            $workers[$user->getId()]['jobs'][$job->getId()] = true;
            $workers[$user->getId()]['days'][$day] = true;

            $jobs[$job->getId()] ??= [
                'jobId' => $job->getId(),
                'title' => $job->getTitle(),
                'worker' => $job->getAssignee()?->getShortName() ?? 'Nicht zugeteilt',
                'status' => $job->getStatus()->value,
                'seconds' => 0,
                'days' => [],
            ];
            $jobs[$job->getId()]['seconds'] += $seconds;
            $jobs[$job->getId()]['days'][$day] = true;

            $days[$day] = ($days[$day] ?? 0) + $seconds;
        }

        $perWorker = array_values(array_map(static fn (array $w): array => [
            'workerId' => $w['workerId'],
            'worker' => $w['worker'],
            'actualMinutes' => intdiv($w['seconds'], 60),
            'jobs' => \count($w['jobs']),
            'days' => \count($w['days']),
        ], $workers));
        usort($perWorker, static fn (array $a, array $b): int => $b['actualMinutes'] <=> $a['actualMinutes'] ?: strcmp($a['worker'], $b['worker']));

        $topJobs = array_values($jobs);
        usort($topJobs, static fn (array $a, array $b): int => $b['seconds'] <=> $a['seconds']);
        $topJobs = array_map(static fn (array $j): array => [
            'jobId' => $j['jobId'],
            'title' => $j['title'],
            'worker' => $j['worker'],
            'status' => $j['status'],
            'actualMinutes' => intdiv($j['seconds'], 60),
            'workedDays' => \count($j['days']),
        ], \array_slice($topJobs, 0, self::TOP_JOBS));

        $perDay = [];
        for ($day = $from; $day < $to; $day = $day->modify('+1 day')) {
            $perDay[] = ['date' => $day->format('Y-m-d'), 'actualMinutes' => intdiv($days[$day->format('Y-m-d')] ?? 0, 60)];
        }

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->modify('-1 day')->format('Y-m-d'),
            'totals' => [
                'actualMinutes' => intdiv($total, 60),
                'previousActualMinutes' => intdiv($previousSeconds, 60),
                'workers' => \count($workers),
                'jobsWorkedOn' => \count($jobs),
                'jobsCompleted' => \count($this->jobs->findCompletedBetween($from, $to)),
                'multiDayJobs' => \count(array_filter($jobs, static fn (array $j): bool => \count($j['days']) > 1)),
                'autoClosedEntries' => $autoClosed,
            ],
            'perWorker' => $perWorker,
            'perDay' => $perDay,
            'topJobs' => $topJobs,
        ];
    }

    /** Anteil eines Abschnitts innerhalb des Zeitraums in Sekunden. */
    private function secondsWithin(TimeEntry $entry, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $now = $this->clock->now();
        $end = $entry->getEndedAt() ?? min($now, $entry->autoCloseAt());
        $start = max($entry->getStartedAt(), $from);
        $end = min($end, $to);

        return max(0, $end->getTimestamp() - $start->getTimestamp());
    }
}
