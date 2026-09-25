<?php

namespace App\Tests\Unit;

use App\Entity\User;
use App\Enum\UserRole;
use PHPUnit\Framework\TestCase;

class UserRoleTest extends TestCase
{
    public function testNewUserIsWorker(): void
    {
        $user = new User();

        self::assertSame('ROLE_USER', $user->getRole());
        self::assertSame('Arbeiter', $user->getRoleLabel());
    }

    public function testHighestRoleWins(): void
    {
        self::assertSame(UserRole::Admin, UserRole::highestOf(['ROLE_USER', 'ROLE_FOREMAN', 'ROLE_ADMIN']));
        self::assertSame(UserRole::Foreman, UserRole::highestOf(['ROLE_USER', 'ROLE_FOREMAN']));
    }

    public function testSetRoleReplacesPreviousRole(): void
    {
        $user = (new User())->setRole(UserRole::Admin);
        $user->setRole(UserRole::Foreman);

        self::assertSame(['ROLE_FOREMAN', 'ROLE_USER'], $user->getRoles());
        self::assertSame('Vorarbeiter', $user->getRoleLabel());
    }

    public function testInitials(): void
    {
        $user = (new User())->setFirstName('kevin')->setLastName('Lichtl');

        self::assertSame('KL', $user->getInitials());
    }
}
