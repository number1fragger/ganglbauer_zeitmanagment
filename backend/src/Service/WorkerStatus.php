<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\JobRepository;

/**
 * F9 – wie lange ist ein Arbeiter noch ausgelastet und ab wann braucht
 * er wieder neue Arbeit?
 */
class WorkerStatus
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly WorkloadCalculator $workload,
    ) {
    }

    /**
     * @return array{remainingMinutes: int, openJobs: int, availableFrom: string}
     */
    public function of(User $user, \DateTimeImmutable $now): array
    {
        $remaining = 0;
        $lastEnd = $now;
        $openJobs = $this->jobs->findOpen($user);

        foreach ($openJobs as $job) {
            // Ohne geplante Zeit ist die Restarbeit unbekannt und zaehlt nicht.
            $remaining += $job->getRemainingMinutes() ?? 0;
            if (null !== $job->getEndsAt()) {
                $lastEnd = max($lastEnd, $job->getEndsAt());
            }
        }

        // Frei ist er erst, wenn die Restarbeit getan UND der letzte Termin vorbei ist.
        $availableFrom = max($this->workload->estimateAvailableFrom($user, $remaining, $now), $lastEnd);

        return [
            'remainingMinutes' => $remaining,
            'openJobs' => \count($openJobs),
            'availableFrom' => $availableFrom->format(\DATE_ATOM),
        ];
    }
}
