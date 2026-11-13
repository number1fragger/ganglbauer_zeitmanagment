<?php

namespace App\Repository;

use App\Entity\User;
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
            ->orderBy('u.firstName', 'ASC')
            ->addOrderBy('u.lastName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Aktive Sekretaerinnen, Arbeiter und Lehrlinge – also alle, die selbst
     * Arbeiten zugeteilt bekommen. Die Reihenfolge bestimmt die Spalten im
     * Kalender; Lehrlinge stehen hinten.
     *
     * @return User[]
     */
    public function findWorkers(): array
    {
        $workers = $this->createQueryBuilder('u')
            ->andWhere('u.active = true')
            ->andWhere('u.role IN (:roles)')
            ->setParameter('roles', array_filter(UserRole::cases(), static fn (UserRole $role): bool => $role->worksInWorkshop()))
            ->orderBy('u.firstName', 'ASC')
            ->addOrderBy('u.lastName', 'ASC')
            ->getQuery()
            ->getResult();

        usort($workers, static fn (User $a, User $b): int => (int) $a->getRole()->canAssistOnJobs() <=> (int) $b->getRole()->canAssistOnJobs());

        return $workers;
    }
}
