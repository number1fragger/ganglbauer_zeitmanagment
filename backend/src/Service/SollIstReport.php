<?php

namespace App\Service;

use App\Entity\Job;
use App\Repository\JobRepository;

/**
 * A1 – Soll/Ist-Vergleich: urspruenglich geplante gegenueber tatsaechlich
 * benoetigter Zeit, je Arbeiter und je Arbeit.
 *
 * Arbeiten ohne erfasste Zeit fliessen nicht ein, weil ihr Ist-Wert
 * unbekannt ist und den Vergleich verfaelschen wuerde.
 */
class SollIstReport
{
    private const TOP_DEVIATIONS = 4;

    public function __construct(private readonly JobRepository $jobs)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $jobs = $this->trackedJobs($from, $to);
        $previous = $this->trackedJobs($from->sub($from->diff($to)), $from);

        $planned = $this->sum($jobs, static fn (Job $job): int => (int) $job->getOriginalPlannedMinutes());
        $actual = $this->sum($jobs, static fn (Job $job): int => $job->getActualMinutes());
        $accuracy = $this->accuracy($jobs);
        $previousAccuracy = $this->accuracy($previous);

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->modify('-1 day')->format('Y-m-d'),
            'totals' => [
                'plannedMinutes' => $planned,
                'actualMinutes' => $actual,
                'jobs' => \count($jobs),
                'workers' => \count(array_unique(array_map(static fn (Job $job): ?int => $job->getAssignee()?->getId(), $jobs))),
                'accuracyPercent' => $accuracy,
                'accuracyDelta' => null !== $accuracy && null !== $previousAccuracy ? round($accuracy - $previousAccuracy, 1) : null,
                'overruns' => \count(array_filter($jobs, fn (Job $job): bool => $this->diff($job) > 0)),
            ],
            'perWorker' => $this->perWorker($jobs),
            'deviations' => $this->deviations($jobs),
        ];
    }

    /** @return Job[] */
    private function trackedJobs(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return array_values(array_filter(
            $this->jobs->findCompletedBetween($from, $to),
            // Ohne Soll-Wert (keine geplante Zeit) ist kein Vergleich moeglich.
            static fn (Job $job): bool => $job->getActualMinutes() > 0 && null !== $job->getOriginalPlannedMinutes(),
        ));
    }

    private function diff(Job $job): int
    {
        return $job->getActualMinutes() - (int) $job->getOriginalPlannedMinutes();
    }

    /**
     * 100 % heisst: Ist entspricht genau dem Soll. Jede Abweichung –
     * egal in welche Richtung – senkt die Genauigkeit.
     *
     * @param Job[] $jobs
     */
    private function accuracy(array $jobs): ?float
    {
        $planned = $this->sum($jobs, static fn (Job $job): int => (int) $job->getOriginalPlannedMinutes());

        if (0 === $planned) {
            return null;
        }

        $deviation = $this->sum($jobs, fn (Job $job): int => abs($this->diff($job)));

        return round(max(0, 100 - $deviation / $planned * 100), 1);
    }

    /**
     * @param Job[] $jobs
     *
     * @return list<array<string, mixed>>
     */
    private function perWorker(array $jobs): array
    {
        $rows = [];

        foreach ($jobs as $job) {
            $worker = $job->getAssignee();
            $key = $worker?->getId() ?? 0;

            $rows[$key] ??= [
                'workerId' => $worker?->getId(),
                'worker' => $worker?->getFullName() ?? 'Nicht zugeteilt',
                'plannedMinutes' => 0,
                'actualMinutes' => 0,
            ];
            $rows[$key]['plannedMinutes'] += (int) $job->getOriginalPlannedMinutes();
            $rows[$key]['actualMinutes'] += $job->getActualMinutes();
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['worker'], $b['worker']));

        return $rows;
    }

    /**
     * @param Job[] $jobs
     *
     * @return list<array<string, mixed>>
     */
    private function deviations(array $jobs): array
    {
        $deviating = array_filter($jobs, fn (Job $job): bool => 0 !== $this->diff($job));
        usort($deviating, fn (Job $a, Job $b): int => abs($this->diff($b)) <=> abs($this->diff($a)));

        return array_map(fn (Job $job): array => [
            'jobId' => $job->getId(),
            'title' => $job->getTitle(),
            'worker' => $job->getAssignee()?->getShortName() ?? 'Nicht zugeteilt',
            'diffMinutes' => $this->diff($job),
        ], \array_slice($deviating, 0, self::TOP_DEVIATIONS));
    }

    /**
     * @param Job[]                $jobs
     * @param callable(Job): int   $value
     */
    private function sum(array $jobs, callable $value): int
    {
        return array_sum(array_map($value, $jobs));
    }
}
