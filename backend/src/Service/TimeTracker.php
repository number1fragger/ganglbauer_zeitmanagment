<?php

namespace App\Service;

use App\Entity\Job;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Exception\WorkflowException;
use App\Repository\TimeEntryRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Ist-Zeiterfassung: Starten, Pausieren, Fortsetzen und Abschliessen.
 *
 * Regeln:
 *  - Jeder Start/Fortsetzen legt einen Arbeitsabschnitt (TimeEntry) an,
 *    Pausieren und Abschliessen beenden ihn. Die Ist-Zeit ist die Summe
 *    der Abschnitte – Pausen, Naechte und Wochenenden zaehlen nie.
 *  - Pro Arbeiter laeuft hoechstens ein Abschnitt. Startet er eine andere
 *    Arbeit, wird die bisherige automatisch pausiert.
 *  - Alle Aktionen sind idempotent: ein zweiter Klick auf "Starten"
 *    oder "Pausieren" aendert nichts.
 *  - Jede Aktion laeuft in einer Transaktion und sperrt Arbeiter bzw.
 *    Arbeit (SELECT ... FOR UPDATE). Parallele Requests werden dadurch
 *    nacheinander abgearbeitet und koennen keine doppelten Abschnitte
 *    erzeugen.
 *  - Vergessene Abschnitte werden an der Kappungsgrenze beendet
 *    (siehe TimeEntry::autoCloseAt()).
 *
 * Die Methoden speichern selbst (flush + commit).
 */
class TimeTracker
{
    public function __construct(
        private readonly TimeEntryRepository $entries,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Arbeit starten bzw. fortsetzen. */
    public function start(User $user, Job $job): Job
    {
        $this->em->wrapInTransaction(function () use ($user, $job): void {
            $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($job, LockMode::PESSIMISTIC_WRITE);
            $now = $this->now();

            if ($job->isDone()) {
                throw new WorkflowException('Diese Arbeit ist bereits abgeschlossen.');
            }

            if (null === $job->getAssignee()) {
                if (!$user->getRole()->worksInWorkshop()) {
                    throw new WorkflowException('Diese Arbeit ist noch niemandem zugeteilt.');
                }
                $job->setAssignee($user);
            } elseif (!$job->isAssignedTo($user)) {
                throw new WorkflowException('Nur der zugeteilte Arbeiter kann diese Arbeit starten.');
            }

            foreach ($this->runningOf($user, $now) as $running) {
                if ($running->getJob() === $job) {
                    return; // laeuft bereits – doppelter Klick
                }
                $running->stop($now); // andere Arbeit wird pausiert
            }

            $this->em->persist(new TimeEntry($user, $job, $now));
            $job->markInProgress();
        });

        return $job;
    }

    /** Laufende Abschnitte dieser Arbeit beenden. Ohne laufenden Abschnitt: keine Aenderung. */
    public function pause(Job $job): Job
    {
        $this->em->wrapInTransaction(function () use ($job): void {
            $this->em->refresh($job, LockMode::PESSIMISTIC_WRITE);
            $this->stopRunningOf($job, $this->now());
        });

        return $job;
    }

    /**
     * Arbeit abschliessen: laufenden Abschnitt beenden, Status und
     * Abschlusszeit setzen. Wurde nie gestartet, entsteht keine Ist-Zeit.
     */
    public function complete(Job $job): Job
    {
        $this->em->wrapInTransaction(function () use ($job): void {
            $this->em->refresh($job, LockMode::PESSIMISTIC_WRITE);

            if ($job->isDone()) {
                throw new WorkflowException('Diese Arbeit ist bereits abgeschlossen.');
            }

            $now = $this->now();
            $this->stopRunningOf($job, $now);
            $job->complete($now);
        });

        return $job;
    }

    /** Ausdrueckliche Wiedereroeffnung einer abgeschlossenen Arbeit. */
    public function reopen(Job $job): Job
    {
        $this->em->wrapInTransaction(function () use ($job): void {
            $this->em->refresh($job, LockMode::PESSIMISTIC_WRITE);
            $job->reopen();
        });

        return $job;
    }

    /** Laufenden Abschnitt des Arbeiters beenden (egal bei welcher Arbeit). */
    public function stop(User $user): ?TimeEntry
    {
        return $this->em->wrapInTransaction(function () use ($user): ?TimeEntry {
            $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
            $now = $this->now();
            $stopped = null;
            foreach ($this->runningOf($user, $now) as $running) {
                $stopped = $running->stop($now);
            }

            return $stopped;
        });
    }

    /**
     * Beendet alle vergessenen Abschnitte (z. B. per Cronjob).
     *
     * @return int Anzahl beendeter Abschnitte
     */
    public function closeStale(): int
    {
        return $this->em->wrapInTransaction(function (): int {
            $now = $this->now();
            $closed = 0;
            foreach ($this->entries->findAllRunning() as $entry) {
                $closed += $entry->closeIfStale($now) ? 1 : 0;
            }

            return $closed;
        });
    }

    private function stopRunningOf(Job $job, \DateTimeImmutable $now): void
    {
        foreach ($this->entries->findRunningForJob($job) as $entry) {
            $entry->stop($now);
        }
    }

    /**
     * Laufende Abschnitte des Arbeiters; vergessene werden dabei an der
     * Kappungsgrenze beendet und nicht mehr geliefert.
     *
     * @return list<TimeEntry>
     */
    private function runningOf(User $user, \DateTimeImmutable $now): array
    {
        $running = [];
        foreach ($this->entries->findAllRunning($user) as $entry) {
            if (!$entry->closeIfStale($now)) {
                $running[] = $entry;
            }
        }

        return $running;
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }
}
