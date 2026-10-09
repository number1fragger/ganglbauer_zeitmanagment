<?php

namespace App\Service;

use App\Entity\Job;
use App\Repository\JobRepository;

/**
 * F5 – Arbeitszeit nachtraeglich erhoehen. Damit sich nichts
 * ueberschneidet, ruecken die noch nicht begonnenen Folgetermine des
 * Arbeiters mit. Begonnene oder abgeschlossene Arbeiten bleiben stehen.
 * Das Flush uebernimmt der Aufrufer.
 */
class JobScheduler
{
    public const MAX_EXTENSION_MINUTES = 8 * 60;

    public function __construct(private readonly JobRepository $jobs)
    {
    }

    public function extend(Job $job, int $minutes): void
    {
        if ($minutes <= 0 || $minutes > self::MAX_EXTENSION_MINUTES) {
            throw new \InvalidArgumentException(sprintf('Bitte zwischen 1 und %d Minuten angeben.', self::MAX_EXTENSION_MINUTES));
        }

        $previousEnd = $job->getEndsAt();
        $job->extendBy($minutes);

        if (null === $previousEnd) {
            return; // Aufgabe ohne Termin – im Kalender verschiebt sich nichts.
        }

        foreach ($this->jobs->findFollowing($job, $previousEnd) as $following) {
            if (!$following->isStarted()) {
                $following->shiftBy($minutes);
            }
        }
    }
}
