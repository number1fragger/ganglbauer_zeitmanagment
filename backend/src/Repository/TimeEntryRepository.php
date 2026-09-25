<?php

namespace App\Repository;

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

    /** @return TimeEntry[] */
    public function findInRange(User $user, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('e')
            ->addSelect('j')
            ->join('e.job', 'j')
            ->andWhere('e.user = :user')
            ->setParameter('user', $user)
            ->orderBy('e.startedAt', 'DESC');

        if (null !== $from) {
            $qb->andWhere('e.startedAt >= :from')->setParameter('from', $from);
        }

        if (null !== $to) {
            $qb->andWhere('e.startedAt <= :to')->setParameter('to', $to);
        }

        return $qb->getQuery()->getResult();
    }

    public function findRunning(User $user): ?TimeEntry
    {
        return $this->createQueryBuilder('e')
            ->addSelect('j')
            ->join('e.job', 'j')
            ->andWhere('e.user = :user')
            ->andWhere('e.endedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('e.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
