<?php

namespace App\Service;

use App\Entity\Absence;
use App\Entity\Job;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\WorkRequest;
use App\Enum\JobStatus;
use App\Enum\WorkRequestStatus;
use App\Exception\WorkflowException;
use App\Repository\TimeEntryRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Benutzer endgueltig loeschen – ohne verwaiste Datensaetze und ohne die
 * Historie der Werkstatt zu verlieren:
 *
 *  - Auftraege bleiben erhalten; war die Person zustaendig oder als Lehrling
 *    eingetragen, wird die Zuordnung entfernt (offene Auftraege erscheinen
 *    danach als "Nicht zugeteilt").
 *  - Arbeitsabschnitte (Ist-Zeit) bleiben als Nachweis erhalten, mit dem
 *    gespeicherten Namen statt der Verknuepfung.
 *  - "Brauche Arbeit"-Anfragen bleiben fuer die Statistik erhalten; offene
 *    werden zurueckgezogen.
 *  - Abwesenheiten der Person werden geloescht (sie betreffen niemanden mehr);
 *    von ihr angelegte Abwesenheiten anderer bleiben, ohne Ersteller.
 *
 * Abgelehnt wird, solange die Person gerade Zeit erfasst, und das eigene Konto.
 * Alles laeuft in einer Transaktion.
 */
class UserDeletion
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TimeEntryRepository $entries,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Was das Loeschen bewirken wuerde – fuer die Sicherheitsabfrage.
     *
     * @return array<string, int|bool>
     */
    public function impact(User $user): array
    {
        $count = fn (string $dql): int => (int) $this->em->createQuery($dql)
            ->setParameter('user', $user)
            ->getSingleScalarResult();

        return [
            'openJobs' => (int) $this->em->createQuery(sprintf('SELECT COUNT(j.id) FROM %s j WHERE j.assignee = :user AND j.status != :done', Job::class))
                ->setParameter('user', $user)->setParameter('done', JobStatus::Done)->getSingleScalarResult(),
            'doneJobs' => (int) $this->em->createQuery(sprintf('SELECT COUNT(j.id) FROM %s j WHERE j.assignee = :user AND j.status = :done', Job::class))
                ->setParameter('user', $user)->setParameter('done', JobStatus::Done)->getSingleScalarResult(),
            'apprenticeJobs' => $count(sprintf('SELECT COUNT(j.id) FROM %s j WHERE j.apprentice = :user', Job::class)),
            'timeEntries' => $count(sprintf('SELECT COUNT(e.id) FROM %s e WHERE e.user = :user', TimeEntry::class)),
            'workRequests' => $count(sprintf('SELECT COUNT(r.id) FROM %s r WHERE r.user = :user', WorkRequest::class)),
            'absences' => $count(sprintf('SELECT COUNT(a.id) FROM %s a WHERE a.user = :user', Absence::class)),
            'running' => [] !== $this->runningEntries($user),
        ];
    }

    public function delete(User $user, User $actor): void
    {
        if ($user->getId() === $actor->getId()) {
            throw new WorkflowException('Das eigene Konto kann man nicht selbst löschen.');
        }

        $this->em->wrapInTransaction(function () use ($user): void {
            $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);

            if ([] !== $this->runningEntries($user)) {
                throw new WorkflowException(sprintf('%s erfasst gerade Arbeitszeit. Bitte die Arbeit zuerst pausieren oder abschließen.', $user->getFullName()));
            }

            $update = fn (string $dql, array $params = []) => $this->em->createQuery($dql)
                ->setParameters(['user' => $user] + $params)
                ->execute();

            $update(sprintf('UPDATE %s j SET j.assignee = NULL WHERE j.assignee = :user', Job::class));
            $update(sprintf('UPDATE %s j SET j.apprentice = NULL WHERE j.apprentice = :user', Job::class));
            $update(sprintf('UPDATE %s e SET e.user = NULL WHERE e.user = :user', TimeEntry::class));
            $update(sprintf('UPDATE %s r SET r.status = :withdrawn WHERE r.user = :user AND r.status = :open', WorkRequest::class), [
                'withdrawn' => WorkRequestStatus::Withdrawn,
                'open' => WorkRequestStatus::Open,
            ]);
            $update(sprintf('UPDATE %s r SET r.user = NULL WHERE r.user = :user', WorkRequest::class));
            $update(sprintf('DELETE FROM %s a WHERE a.user = :user', Absence::class));
            $update(sprintf('UPDATE %s a SET a.createdBy = NULL WHERE a.createdBy = :user', Absence::class));

            $this->em->remove($user);
            $this->em->flush();
        });

        // Bereits geladene Objekte koennten noch auf den Benutzer zeigen.
        $this->em->clear();
    }

    /**
     * Laufende Abschnitte – vergessene (ueber der Kappungsgrenze) zaehlen nicht
     * und werden dabei beendet.
     *
     * @return list<TimeEntry>
     */
    private function runningEntries(User $user): array
    {
        $now = $this->clock->now();

        return array_values(array_filter(
            $this->entries->findAllRunning($user),
            static fn (TimeEntry $entry): bool => !$entry->closeIfStale($now),
        ));
    }
}
