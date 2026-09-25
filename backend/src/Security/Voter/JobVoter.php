<?php

namespace App\Security\Voter;

use App\Entity\Job;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Wer darf was mit einer Arbeit?
 *
 *  - VIEW:   Chef und Vorarbeiter alle, Arbeiter nur die eigenen
 *  - WORK:   Zeit erfassen, Zeit erhoehen, abhaken – wie VIEW
 *  - EDIT:   Titel, Kunde, Prioritaet, Termine, Zuteilung – nur Chef/Vorarbeiter
 *  - DELETE: nur Chef/Vorarbeiter
 *
 * @extends Voter<string, Job>
 */
class JobVoter extends Voter
{
    public const VIEW = 'JOB_VIEW';
    public const WORK = 'JOB_WORK';
    public const EDIT = 'JOB_EDIT';
    public const DELETE = 'JOB_DELETE';

    public function __construct(private readonly AccessDecisionManagerInterface $decisions)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Job
            && \in_array($attribute, [self::VIEW, self::WORK, self::EDIT, self::DELETE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        // Chef und Vorarbeiter planen die Werkstatt und duerfen alles.
        if ($this->decisions->decide($token, ['ROLE_FOREMAN'])) {
            return true;
        }

        return match ($attribute) {
            self::VIEW, self::WORK => $subject->getAssignee()?->getId() === $user->getId(),
            default => false,
        };
    }
}
