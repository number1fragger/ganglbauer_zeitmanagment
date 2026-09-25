<?php

namespace App\Tests\Unit;

use App\Entity\Job;
use App\Entity\User;
use App\Enum\UserRole;
use App\Security\Voter\JobVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\Voter\RoleHierarchyVoter;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

class JobVoterTest extends TestCase
{
    public function testWorkerMayWorkOnOwnJobButNotEditIt(): void
    {
        $worker = $this->user(1, UserRole::Worker);
        $job = (new Job())->setAssignee($worker);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($worker, $job, JobVoter::WORK));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($worker, $job, JobVoter::EDIT));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($worker, $job, JobVoter::DELETE));
    }

    public function testWorkerMayNotTouchSomeoneElsesJob(): void
    {
        $worker = $this->user(1, UserRole::Worker);
        $job = (new Job())->setAssignee($this->user(2, UserRole::Worker));

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($worker, $job, JobVoter::VIEW));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($worker, $job, JobVoter::WORK));
    }

    public function testForemanAndChefMayDoEverything(): void
    {
        $job = (new Job())->setAssignee($this->user(2, UserRole::Worker));

        foreach ([UserRole::Foreman, UserRole::Admin] as $role) {
            $user = $this->user(9, $role);
            foreach ([JobVoter::VIEW, JobVoter::WORK, JobVoter::EDIT, JobVoter::DELETE] as $attribute) {
                self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($user, $job, $attribute), $role->name.' '.$attribute);
            }
        }
    }

    private function vote(User $user, Job $job, string $attribute): int
    {
        $hierarchy = new RoleHierarchy([
            'ROLE_ADMIN' => ['ROLE_FOREMAN'],
            'ROLE_FOREMAN' => ['ROLE_USER'],
        ]);
        $decisions = new AccessDecisionManager([new RoleHierarchyVoter($hierarchy)]);
        $token = new UsernamePasswordToken($user, 'api', $user->getRoles());

        return (new JobVoter($decisions))->vote($token, $job, [$attribute]);
    }

    private function user(int $id, UserRole $role): User
    {
        $user = (new User())->setEmail("user$id@test.at")->setRole($role);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
