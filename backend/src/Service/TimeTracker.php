<?php

namespace App\Service;

use App\Entity\Job;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Repository\TimeEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Start/Stopp der Zeiterfassung. Pro Arbeiter laeuft hoechstens ein
 * Eintrag gleichzeitig; ein neuer Start beendet den vorherigen.
 * Das Flush uebernimmt der Aufrufer.
 */
class TimeTracker
{
    public function __construct(
        private readonly TimeEntryRepository $entries,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function start(User $user, Job $job): TimeEntry
    {
        $this->stop($user);

        $entry = new TimeEntry($user, $job);
        $job->markInProgress();
        $this->em->persist($entry);

        return $entry;
    }

    public function stop(User $user): ?TimeEntry
    {
        return $this->entries->findRunning($user)?->stop();
    }
}
