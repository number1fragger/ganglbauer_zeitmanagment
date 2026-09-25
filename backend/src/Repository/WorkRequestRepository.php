<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\WorkRequest;
use App\Enum\WorkRequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkRequest>
 */
class WorkRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkRequest::class);
    }

    /** @return WorkRequest[] */
    public function findVisible(?User $user, bool $includeClosed = false): array
    {
        $qb = $this->createQueryBuilder('r')
            ->addSelect('u')
            ->join('r.user', 'u')
            ->orderBy('r.neededAt', 'ASC');

        if (null !== $user) {
            $qb->andWhere('r.user = :user')->setParameter('user', $user);
        }

        if (!$includeClosed) {
            $qb->andWhere('r.status = :open')->setParameter('open', WorkRequestStatus::Open);
        }

        return $qb->getQuery()->getResult();
    }

    public function findOpenFor(User $user): ?WorkRequest
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->andWhere('r.status = :open')
            ->setParameter('user', $user)
            ->setParameter('open', WorkRequestStatus::Open)
            ->orderBy('r.neededAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
