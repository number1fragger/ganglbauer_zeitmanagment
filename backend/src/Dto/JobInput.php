<?php

namespace App\Dto;

use App\Entity\Job;
use App\Enum\Priority;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Formular "Arbeit anlegen / bearbeiten".
 *
 * Nur der Titel ist Pflicht. Ohne Beginn bleibt die Arbeit eine Aufgabe
 * ohne Termin (To-do) und erscheint nicht im Kalender. Das Ende gibt man
 * entweder direkt an (endsAt) oder als Dauer in Arbeitsminuten
 * (durationMinutes) – dann berechnet der Server das Ende nach den
 * Arbeitszeiten. Ohne beides: Standardlaenge (Job::DEFAULT_SLOT_MINUTES).
 */
final class JobInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Bitte die Arbeit bezeichnen.')]
        #[Assert\Length(max: 150)]
        public readonly string $title = '',

        #[Assert\Length(max: 5000)]
        public readonly ?string $description = null,

        #[Assert\Length(max: 150)]
        public readonly ?string $customer = null,

        public readonly Priority $priority = Priority::Medium,

        /** Termin-Beginn; null = kein Termin. */
        public readonly ?\DateTimeImmutable $startsAt = null,

        #[Assert\GreaterThan(propertyPath: 'startsAt', message: 'Das Ende des Termins muss nach dem Beginn liegen.')]
        public readonly ?\DateTimeImmutable $endsAt = null,

        /** Alternativ zum Ende: geplante Dauer in Arbeitsminuten. */
        #[Assert\Range(min: 1, max: Job::MAX_WORK_MINUTES, notInRangeMessage: 'Die Dauer muss zwischen 1 Minute und {{ max }} Minuten liegen.')]
        public readonly ?int $durationMinutes = null,

        public readonly ?int $assigneeId = null,

        /** Optional: Lehrling als zusaetzliche Unterstuetzung (nur Rolle "Lehrling"). */
        public readonly ?int $apprenticeId = null,

        /** Veraltet (Abhaken ueber das Formular) – bitte /complete bzw. /reopen verwenden. */
        public readonly ?bool $done = null,

        /** Termin einer bereits begonnenen Arbeit bewusst aendern. */
        public readonly bool $confirmStartedChange = false,
    ) {
    }

    #[Assert\Callback]
    public function validateSchedule(ExecutionContextInterface $context): void
    {
        if (null === $this->startsAt && (null !== $this->endsAt || null !== $this->durationMinutes)) {
            $context->buildViolation('Bitte auch den Arbeitsbeginn angeben.')->atPath('startsAt')->addViolation();
        }

        if (null !== $this->endsAt && null !== $this->durationMinutes) {
            $context->buildViolation('Bitte entweder das Ende oder die Dauer angeben, nicht beides.')->atPath('durationMinutes')->addViolation();
        }
    }
}
