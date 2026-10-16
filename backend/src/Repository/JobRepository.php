<?php

namespace App\Repository;

use App\Entity\Job;
use App\Entity\User;
use App\Enum\JobStatus;
use App\Enum\Priority;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Job>
 */
class JobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Job::class);
    }

    /**
     * Eingeplante Arbeiten, die sich mit dem Zeitraum ueberschneiden – fuer
     * den Kalender. Arbeiten ohne Termin sind hier nie dabei. Mit $person nur
     * die Arbeiten, bei denen sie zustaendig oder als Lehrling dabei ist.
     *
     * @return Job[]
     */
    public function findInRange(\DateTimeImmutable $from, \DateTimeImmutable $to, ?User $person = null): array
    {
        $qb = $this->withRelations()
            ->andWhere('j.startsAt IS NOT NULL')
            ->andWhere('j.startsAt < :to')
            ->andWhere('j.endsAt > :from')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('j.startsAt', 'ASC');

        if (null !== $person) {
            $qb->andWhere('j.assignee = :person OR j.apprentice = :person')->setParameter('person', $person);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * "Meine Arbeiten": alles Offene des Arbeiters (mit und ohne Termin,
     * auch als Lehrling) plus das, was er heute abgeschlossen hat.
     *
     * @return Job[]
     */
    public function findMine(User $user, \DateTimeImmutable $dayStart): array
    {
        return $this->withRelations()
            ->andWhere('j.assignee = :user OR j.apprentice = :user')
            ->andWhere('j.status != :done OR j.completedAt >= :dayStart')
            ->setParameter('user', $user)
            ->setParameter('dayStart', $dayStart)
            ->setParameter('done', JobStatus::Done)
            ->orderBy('j.startsAt', 'ASC')
            ->addOrderBy('j.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Aufgabenverwaltung (Kanban): alle offenen und laufenden Arbeiten,
     * abgeschlossene nur seit $doneSince. Mit $assignee: zustaendig oder als Lehrling dabei.
     *
     * @return Job[]
     */
    public function findBoard(\DateTimeImmutable $doneSince, ?User $assignee = null, ?Priority $priority = null, ?string $search = null): array
    {
        $qb = $this->withRelations()
            ->andWhere('j.status != :done OR j.completedAt >= :doneSince')
            ->setParameter('done', JobStatus::Done)
            ->setParameter('doneSince', $doneSince)
            ->orderBy('j.createdAt', 'DESC');

        if (null !== $assignee) {
            $qb->andWhere('j.assignee = :assignee OR j.apprentice = :assignee')->setParameter('assignee', $assignee);
        }

        if (null !== $priority) {
            $qb->andWhere('j.priority = :priority')->setParameter('priority', $priority);
        }

        if (null !== $search && '' !== trim($search)) {
            $qb->andWhere('LOWER(j.title) LIKE :q OR LOWER(j.customer) LIKE :q OR LOWER(j.description) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower(addcslashes(trim($search), '%_')).'%');
        }

        return $qb->getQuery()->getResult();
    }

    /** @return Job[] */
    public function findOpen(?User $assignee = null): array
    {
        $qb = $this->withRelations()
            ->andWhere('j.status != :done')
            ->setParameter('done', JobStatus::Done)
            ->orderBy('j.startsAt', 'ASC');

        if (null !== $assignee) {
            $qb->andWhere('j.assignee = :assignee')->setParameter('assignee', $assignee);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Offene, eingeplante Arbeiten desselben Arbeiters, die ab dem
     * angegebenen Zeitpunkt beginnen – in zeitlicher Reihenfolge.
     *
     * @return Job[]
     */
    public function findFollowing(Job $job, \DateTimeImmutable $after): array
    {
        if (null === $job->getAssignee()) {
            return [];
        }

        return $this->withRelations()
            ->andWhere('j.assignee = :assignee')
            ->andWhere('j.id != :id')
            ->andWhere('j.status != :done')
            ->andWhere('j.startsAt IS NOT NULL')
            ->andWhere('j.startsAt >= :after')
            ->setParameter('assignee', $job->getAssignee())
            ->setParameter('id', $job->getId())
            ->setParameter('done', JobStatus::Done)
            ->setParameter('after', $after)
            ->orderBy('j.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Eingeplante Arbeiten eines Arbeiters, die sich mit dem Zeitraum
     * ueberschneiden – fuer die Konflikterkennung.
     *
     * @param int[] $excludeIds
     *
     * @return Job[]
     */
    public function findOverlapping(User $assignee, \DateTimeImmutable $from, \DateTimeImmutable $to, array $excludeIds = []): array
    {
        $qb = $this->createQueryBuilder('j')
            ->andWhere('j.assignee = :assignee')
            ->andWhere('j.startsAt IS NOT NULL')
            ->andWhere('j.startsAt < :to')
            ->andWhere('j.endsAt > :from')
            ->setParameter('assignee', $assignee)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('j.startsAt', 'ASC');

        if ([] !== $excludeIds) {
            $qb->andWhere('j.id NOT IN (:exclude)')->setParameter('exclude', $excludeIds);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Noch nicht abgeschlossene, eingeplante Arbeiten einer Person (zustaendig
     * oder Lehrling) im Zeitraum – fuer Ueberschneidungs- und Abwesenheitshinweise.
     *
     * @param int[] $excludeIds
     *
     * @return Job[]
     */
    public function findOpenInvolving(User $person, \DateTimeImmutable $from, \DateTimeImmutable $to, array $excludeIds = []): array
    {
        $qb = $this->createQueryBuilder('j')
            ->addSelect('u', 'a')
            ->leftJoin('j.assignee', 'u')
            ->leftJoin('j.apprentice', 'a')
            ->andWhere('j.assignee = :person OR j.apprentice = :person')
            ->andWhere('j.status != :done')
            ->andWhere('j.startsAt IS NOT NULL')
            ->andWhere('j.startsAt < :to')
            ->andWhere('j.endsAt > :from')
            ->setParameter('person', $person)
            ->setParameter('done', JobStatus::Done)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('j.startsAt', 'ASC');

        if ([] !== $excludeIds) {
            $qb->andWhere('j.id NOT IN (:exclude)')->setParameter('exclude', $excludeIds);
        }

        return $qb->getQuery()->getResult();
    }

    /** Anzahl offener Arbeiten, bei denen die Person als Lehrling eingetragen ist. */
    public function countOpenAsApprentice(User $person): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->andWhere('j.apprentice = :person')
            ->andWhere('j.status != :done')
            ->setParameter('person', $person)
            ->setParameter('done', JobStatus::Done)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return Job[] */
    public function findCompletedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->withRelations()
            ->andWhere('j.status = :done')
            ->andWhere('j.completedAt >= :from')
            ->andWhere('j.completedAt < :to')
            ->setParameter('done', JobStatus::Done)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();
    }

    private function withRelations(): QueryBuilder
    {
        return $this->createQueryBuilder('j')
            ->addSelect('u', 'a', 'e')
            ->leftJoin('j.assignee', 'u')
            ->leftJoin('j.apprentice', 'a')
            ->leftJoin('j.timeEntries', 'e');
    }
}
