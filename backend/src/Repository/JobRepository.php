<?php

namespace App\Repository;

use App\Entity\Job;
use App\Entity\User;
use App\Enum\JobStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
     * Arbeitsliste, optional gefiltert. Sortiert nach Prioritaet und
     * geplantem Ende, damit oben steht, was zuerst dran ist.
     *
     * @return Job[]
     */
    public function findFiltered(?User $assignee = null, ?JobStatus $status = null, bool $includeDone = false): array
    {
        $qb = $this->createQueryBuilder('j')
            ->addSelect('u', 'e')
            ->leftJoin('j.assignee', 'u')
            ->leftJoin('j.timeEntries', 'e');

        if (null !== $assignee) {
            $qb->andWhere('j.assignee = :assignee')->setParameter('assignee', $assignee);
        }

        if (null !== $status) {
            $qb->andWhere('j.status = :status')->setParameter('status', $status);
        } elseif (!$includeDone) {
            $qb->andWhere('j.status != :done')->setParameter('done', JobStatus::Done);
        }

        /** @var Job[] $jobs */
        $jobs = $qb->getQuery()->getResult();

        usort($jobs, static function (Job $a, Job $b): int {
            $byPriority = $b->getPriority()->weight() <=> $a->getPriority()->weight();
            if (0 !== $byPriority) {
                return $byPriority;
            }

            $aDue = $a->getDueAt()?->getTimestamp() ?? \PHP_INT_MAX;
            $bDue = $b->getDueAt()?->getTimestamp() ?? \PHP_INT_MAX;

            return $aDue <=> $bDue;
        });

        return $jobs;
    }

    /** Offene Arbeiten eines Arbeiters inklusive Zeiteintraegen. */
    /** @return Job[] */
    public function findOpenFor(User $user): array
    {
        return $this->createQueryBuilder('j')
            ->addSelect('e')
            ->leftJoin('j.timeEntries', 'e')
            ->andWhere('j.assignee = :user')
            ->andWhere('j.status != :done')
            ->setParameter('user', $user)
            ->setParameter('done', JobStatus::Done)
            ->getQuery()
            ->getResult();
    }

    /** @return Job[] */
    public function findCompletedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('j')
            ->addSelect('u', 'e')
            ->leftJoin('j.assignee', 'u')
            ->leftJoin('j.timeEntries', 'e')
            ->andWhere('j.status = :done')
            ->andWhere('j.completedAt >= :from')
            ->andWhere('j.completedAt <= :to')
            ->setParameter('done', JobStatus::Done)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('j.completedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Eingeplante Arbeiten, die im Zeitraum liegen koennten. Weil eine Arbeit
     * ueber mehrere Tage laufen kann, wird auch etwas vor "from" gesucht –
     * den genauen Schnitt macht der CalendarBuilder.
     *
     * @return Job[]
     */
    public function findScheduledAround(\DateTimeImmutable $from, \DateTimeImmutable $to, ?User $assignee = null): array
    {
        $qb = $this->createQueryBuilder('j')
            ->addSelect('u', 'e')
            ->join('j.assignee', 'u')
            ->leftJoin('j.timeEntries', 'e')
            ->andWhere('j.startsAt IS NOT NULL')
            ->andWhere('j.startsAt <= :to')
            ->andWhere('j.startsAt >= :lookBack')
            ->setParameter('to', $to)
            ->setParameter('lookBack', $from->modify('-60 days'))
            ->orderBy('j.startsAt', 'ASC');

        if (null !== $assignee) {
            $qb->andWhere('j.assignee = :assignee')->setParameter('assignee', $assignee);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Offene Arbeiten ohne Beginn oder ohne Arbeiter – die tauchen im
     * Kalender noch nicht auf und muessen erst eingeplant werden.
     *
     * @return Job[]
     */
    public function findUnscheduled(?User $assignee = null): array
    {
        $qb = $this->createQueryBuilder('j')
            ->addSelect('u')
            ->leftJoin('j.assignee', 'u')
            ->andWhere('j.status != :done')
            ->andWhere('j.startsAt IS NULL OR j.assignee IS NULL')
            ->setParameter('done', JobStatus::Done)
            ->orderBy('j.createdAt', 'ASC');

        if (null !== $assignee) {
            $qb->andWhere('j.assignee = :assignee')->setParameter('assignee', $assignee);
        }

        return $qb->getQuery()->getResult();
    }
}
