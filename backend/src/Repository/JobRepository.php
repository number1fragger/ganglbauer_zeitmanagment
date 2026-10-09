<?php

namespace App\Repository;

use App\Entity\Job;
use App\Entity\User;
use App\Enum\JobStatus;
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
     * Arbeiten, die sich mit dem Zeitraum ueberschneiden – fuer den Kalender.
     *
     * @return Job[]
     */
    public function findInRange(\DateTimeImmutable $from, \DateTimeImmutable $to, ?User $assignee = null): array
    {
        $qb = $this->withRelations()
            ->andWhere('j.startsAt < :to')
            ->andWhere('j.endsAt > :from')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('j.startsAt', 'ASC');

        if (null !== $assignee) {
            $qb->andWhere('j.assignee = :assignee')->setParameter('assignee', $assignee);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Was ein Arbeiter an einem Tag sieht: alles, was an dem Tag geplant ist,
     * plus offene Arbeiten, die schon frueher haetten fertig sein sollen.
     *
     * @return Job[]
     */
    public function findForWorkerDay(User $user, \DateTimeImmutable $dayStart): array
    {
        $dayEnd = $dayStart->modify('+1 day');

        return $this->withRelations()
            ->andWhere('j.assignee = :user')
            ->andWhere('j.startsAt < :dayEnd')
            ->andWhere('j.endsAt > :dayStart OR j.status != :done')
            ->setParameter('user', $user)
            ->setParameter('dayStart', $dayStart)
            ->setParameter('dayEnd', $dayEnd)
            ->setParameter('done', JobStatus::Done)
            ->orderBy('j.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
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
     * Offene Arbeiten desselben Arbeiters, die nach dem angegebenen Zeitpunkt
     * beginnen – sie ruecken nach, wenn eine Arbeit laenger dauert (F5).
     *
     * @return Job[]
     */
    public function findFollowing(Job $job, \DateTimeImmutable $after): array
    {
        if (null === $job->getAssignee()) {
            return [];
        }

        return $this->createQueryBuilder('j')
            ->andWhere('j.assignee = :assignee')
            ->andWhere('j.id != :id')
            ->andWhere('j.status != :done')
            ->andWhere('j.startsAt >= :after')
            ->setParameter('assignee', $job->getAssignee())
            ->setParameter('id', $job->getId())
            ->setParameter('done', JobStatus::Done)
            ->setParameter('after', $after)
            ->getQuery()
            ->getResult();
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
            ->addSelect('u', 'e')
            ->leftJoin('j.assignee', 'u')
            ->leftJoin('j.timeEntries', 'e');
    }
}
