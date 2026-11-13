<?php

namespace App\Security;

use App\Entity\Job;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Wer planen darf (Sekretaerin, Chef), darf alles bei allen Arbeiten.
 * Sonst gilt:
 *  - VIEW: ansehen duerfen die zustaendige Person und ein eingetragener Lehrling.
 *  - WORK: Zeit erfassen, pausieren, abschliessen nur die zustaendige Person –
 *    ein Lehrling unterstuetzt, die Verantwortung bleibt beim Zustaendigen.
 *
 * @extends Voter<string, Job>
 */
final class JobVoter extends Voter
{
    /** Arbeit ansehen (Details inkl. Arbeitsabschnitte). */
    public const VIEW = 'JOB_VIEW';

    /** Arbeit starten, pausieren, abschliessen, wieder oeffnen. */
    public const WORK = 'JOB_WORK';

    public function __construct(private readonly AccessDecisionManagerInterface $decisions)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::WORK], true) && $subject instanceof Job;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        if ($this->decisions->decide($token, ['ROLE_SEKRETAERIN'])) {
            return true;
        }

        return self::VIEW === $attribute ? $subject->involves($user) : $subject->isAssignedTo($user);
    }
}
