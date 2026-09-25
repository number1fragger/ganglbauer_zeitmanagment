<?php

namespace App\Service;

use App\Entity\Job;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Enum\JobStatus;
use App\Repository\TimeEntryRepository;

/**
 * Start/Stopp der Zeiterfassung. Pro Arbeiter laeuft hoechstens ein
 * Eintrag gleichzeitig; ein neuer Start beendet den vorherigen.
 * Das Flush uebernimmt der Aufrufer.
 */
class TimeTracker
{
    public function __construct(private readonly TimeEntryRepository $entries)
    {
    }

    public function start(User $user, Job $job, ?string $note = null): TimeEntry
    {
        $this->stopRunning($user);

        $entry = new TimeEntry();
        $entry->setUser($user);
        $entry->setJob($job);
        $entry->setNote($note);
        $entry->setStartedAt(new \DateTimeImmutable());

        if (JobStatus::Open === $job->getStatus()) {
            $job->setStatus(JobStatus::InProgress);
        }

        return $entry;
    }

    public function stopRunning(User $user, ?\DateTimeImmutable $at = null): ?TimeEntry
    {
        $running = $this->entries->findRunning($user);

        if (null === $running) {
            return null;
        }

        $end = $at ?? new \DateTimeImmutable();

        // Mindestens eine Minute, damit die Validierung (Ende > Beginn) haelt.
        if ($end <= $running->getStartedAt()) {
            $end = $running->getStartedAt()->modify('+1 minute');
        }

        $running->setEndedAt($end);

        return $running;
    }
}
