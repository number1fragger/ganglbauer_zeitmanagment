<?php

namespace App\Repository;

use App\Entity\Job;
use App\Entity\TimeEntry;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TimeEntry>
 */
class TimeEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TimeEntry::class);
    }

    public function findRunning(User $user): ?TimeEntry
    {
        return $this->findAllRunning($user)[0] ?? null;
    }

    /**
     * Laufende Abschnitte – eines Arbeiters oder aller.
     *
     * @return list<TimeEntry>
     */
    public function findAllRunning(?User $user = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->addSelect('j')
            ->join('e.job', 'j')
            ->andWhere('e.endedAt IS NULL')
            ->orderBy('e.startedAt', 'ASC');

        if (null !== $user) {
            $qb->andWhere('e.user = :user')->setParameter('user', $user);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<TimeEntry> */
    public function findRunningForJob(Job $job): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.job = :job')
            ->andWhere('e.endedAt IS NULL')
            ->setParameter('job', $job)
            ->getQuery()
            ->getResult();
    }

    /**
     * Abschnitte, die sich mit dem Zeitraum ueberschneiden – fuer die
     * Ist-Zeit-Auswertung. Laufende Abschnitte zaehlen mit.
     *
     * @return list<TimeEntry>
     */
    public function findInRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('e')
            ->addSelect('j', 'u')
            ->join('e.job', 'j')
            ->join('e.user', 'u')
            ->andWhere('e.startedAt < :to')
            ->andWhere('e.endedAt IS NULL OR e.endedAt > :from')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.startedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
