<?php

namespace App\Repository;

use App\Entity\Absence;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Absence>
 */
class AbsenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Absence::class);
    }

    /**
     * Abwesenheiten, die sich mit dem Zeitraum ueberschneiden – aller oder
     * bestimmter Mitarbeiter.
     *
     * @param User[]|null $users
     *
     * @return Absence[]
     */
    public function findInRange(\DateTimeImmutable $from, \DateTimeImmutable $to, ?array $users = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->addSelect('u')
            ->join('a.user', 'u')
            ->andWhere('a.startsAt < :to')
            ->andWhere('a.endsAt > :from')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('a.startsAt', 'ASC');

        if (null !== $users) {
            if ([] === $users) {
                return [];
            }
            $qb->andWhere('a.user IN (:users)')->setParameter('users', $users);
        }

        return $qb->getQuery()->getResult();
    }
}
