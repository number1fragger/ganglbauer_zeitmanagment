<?php

namespace App\Repository;

use App\Entity\Job;
use App\Entity\User;
use App\Enum\JobStatus;
use App\Enum\UserRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }

    /** @return User[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Aktive Benutzer – alle, die man einer Arbeit zuteilen kann. @return User[] */
    public function findActiveOrdered(): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.active = true')
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Wer in Kalender, Kapazitaet und Zuteilung auftaucht: alle aktiven
     * Arbeiter und Vorarbeiter. Der Chef nur, wenn ihm gerade selbst eine
     * offene Arbeit zugeteilt ist – sonst stuende er dauerhaft als "frei" da.
     *
     * @return User[]
     */
    public function findWorkforce(): array
    {
        $withOpenJobs = array_column($this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT IDENTITY(j.assignee) AS id')
            ->from(Job::class, 'j')
            ->andWhere('j.assignee IS NOT NULL')
            ->andWhere('j.status != :done')
            ->setParameter('done', JobStatus::Done)
            ->getQuery()
            ->getScalarResult(), 'id');

        return array_values(array_filter(
            $this->findActiveOrdered(),
            static fn (User $user): bool => !$user->hasRole(UserRole::Admin)
                || \in_array((string) $user->getId(), array_map('strval', $withOpenJobs), true),
        ));
    }
}
