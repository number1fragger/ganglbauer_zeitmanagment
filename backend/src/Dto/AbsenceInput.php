<?php

namespace App\Dto;

use App\Enum\AbsenceType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Abwesenheit eintragen/aendern (nur Chef und Sekretaerin).
 *
 * Ganztaegig (allDay): erster und letzter Tag als Datum (JJJJ-MM-TT), ohne
 * Uhrzeiten. Sonst Beginn und Ende als Zeitpunkt, z. B. Arzttermin 09:00–10:30.
 */
final class AbsenceInput
{
    public function __construct(
        #[Assert\NotNull(message: 'Bitte den Mitarbeiter auswählen.')]
        public readonly ?int $userId = null,

        #[Assert\NotNull(message: 'Bitte die Art der Abwesenheit auswählen.')]
        public readonly ?AbsenceType $type = null,

        public readonly bool $allDay = true,

        #[Assert\Date(message: 'Bitte ein gültiges Datum angeben.')]
        public readonly ?string $startDate = null,

        #[Assert\Date(message: 'Bitte ein gültiges Datum angeben.')]
        public readonly ?string $endDate = null,

        public readonly ?\DateTimeImmutable $startsAt = null,

        public readonly ?\DateTimeImmutable $endsAt = null,

        #[Assert\Length(max: 2000)]
        public readonly ?string $note = null,
    ) {
    }

    #[Assert\Callback]
    public function validatePeriod(ExecutionContextInterface $context): void
    {
        if ($this->allDay) {
            if (null === $this->startDate || '' === $this->startDate) {
                $context->buildViolation('Bitte den ersten Tag angeben.')->atPath('startDate')->addViolation();
            }
            if (null === $this->endDate || '' === $this->endDate) {
                $context->buildViolation('Bitte den letzten Tag angeben.')->atPath('endDate')->addViolation();
            }

            return;
        }

        if (null === $this->startsAt) {
            $context->buildViolation('Bitte den Beginn angeben.')->atPath('startsAt')->addViolation();
        }
        if (null === $this->endsAt) {
            $context->buildViolation('Bitte das Ende angeben.')->atPath('endsAt')->addViolation();
        }
    }
}
