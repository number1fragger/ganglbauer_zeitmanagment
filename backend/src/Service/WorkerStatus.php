<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\JobRepository;

/**
 * F9 – ab wann braucht ein Arbeiter wieder neue Arbeit?
 */
class WorkerStatus
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly WorkloadCalculator $workload,
    ) {
    }

    /**
     * Frei ist ein Arbeiter, sobald sein letzter offener Termin im Kalender
     * vorbei ist (naechste Arbeitszeit danach). Aufgaben ohne Termin werden
     * nur gezaehlt – eine geplante Dauer gibt es nicht.
     *
     * @return array{openJobs: int, unscheduledJobs: int, availableFrom: string}
     */
    public function of(User $user, \DateTimeImmutable $now): array
    {
        $lastEnd = $now;
        $unscheduled = 0;
        $openJobs = $this->jobs->findOpen($user);

        foreach ($openJobs as $job) {
            if (null === $job->getEndsAt()) {
                ++$unscheduled;
                continue;
            }
            $lastEnd = max($lastEnd, $job->getEndsAt());
        }

        return [
            'openJobs' => \count($openJobs),
            'unscheduledJobs' => $unscheduled,
            'availableFrom' => $this->workload->estimateAvailableFrom($user, 0, $lastEnd)->format(\DATE_ATOM),
        ];
    }
}
