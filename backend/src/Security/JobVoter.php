<?php

namespace App\Security;

use App\Entity\Job;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Arbeiter duerfen ihre eigenen Arbeiten sehen und daran arbeiten
 * (abhaken, Zeit erfassen, Zeit erhoehen). Wer planen darf, darf das
 * bei allen Arbeiten.
 *
 * @extends Voter<string, Job>
 */
final class JobVoter extends Voter
{
    /** Arbeit ansehen, abhaken, Zeit erfassen und verlaengern. */
    public const WORK = 'JOB_WORK';

    public function __construct(private readonly AccessDecisionManagerInterface $decisions)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::WORK === $attribute && $subject instanceof Job;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return $subject->isAssignedTo($user) || $this->decisions->decide($token, ['ROLE_VORARBEITER']);
    }
}
