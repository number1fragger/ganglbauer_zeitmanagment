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
 * ohne Termin (To-do) und erscheint nicht im Kalender.
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

        #[Assert\GreaterThan(propertyPath: 'startsAt', message: 'Das Arbeitsende muss nach dem Arbeitsbeginn liegen.')]
        public readonly ?\DateTimeImmutable $endsAt = null,

        /** Geplante Arbeitszeit in Minuten; null = nicht festgelegt. */
        #[Assert\Range(min: 5, max: Job::MAX_PLANNED_MINUTES, notInRangeMessage: 'Die geplante Zeit muss zwischen 5 Minuten und einer Woche liegen.')]
        public readonly ?int $plannedMinutes = null,

        public readonly ?int $assigneeId = null,

        /** Veraltet (Abhaken ueber das Formular) – bitte /complete bzw. /reopen verwenden. */
        public readonly ?bool $done = null,

        /** Termin einer bereits begonnenen Arbeit bewusst aendern. */
        public readonly bool $confirmStartedChange = false,
    ) {
    }

    #[Assert\Callback]
    public function validateSchedule(ExecutionContextInterface $context): void
    {
        if (null === $this->startsAt && null !== $this->endsAt) {
            $context->buildViolation('Bitte auch den Arbeitsbeginn angeben.')->atPath('startsAt')->addViolation();
        }

        if (null !== $this->startsAt && null === $this->endsAt && null === $this->plannedMinutes) {
            $context->buildViolation('Für einen Termin bitte das Ende oder die geplante Arbeitszeit angeben.')->atPath('endsAt')->addViolation();
        }
    }
}
