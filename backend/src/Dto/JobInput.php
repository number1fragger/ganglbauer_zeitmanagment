<?php

namespace App\Dto;

use App\Enum\Priority;
use Symfony\Component\Validator\Constraints as Assert;

/** Formular "Arbeit anlegen / bearbeiten" (F1–F4). */
final class JobInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Bitte die Arbeit bezeichnen.')]
        #[Assert\Length(max: 150)]
        public readonly string $title = '',

        #[Assert\Length(max: 150)]
        public readonly ?string $customer = null,

        public readonly Priority $priority = Priority::Medium,

        #[Assert\NotNull(message: 'Bitte den Arbeitsbeginn angeben.')]
        public readonly ?\DateTimeImmutable $startsAt = null,

        #[Assert\GreaterThan(propertyPath: 'startsAt', message: 'Das Arbeitsende muss nach dem Arbeitsbeginn liegen.')]
        public readonly ?\DateTimeImmutable $endsAt = null,

        #[Assert\Range(min: 15, max: 10080, notInRangeMessage: 'Die geplante Zeit muss zwischen 15 Minuten und einer Woche liegen.')]
        public readonly int $plannedMinutes = 60,

        public readonly ?int $assigneeId = null,

        public readonly bool $done = false,
    ) {
    }
}
