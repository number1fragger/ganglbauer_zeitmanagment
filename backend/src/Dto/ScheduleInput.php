<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Einplanen einer Arbeit in den Kalender, z. B. per Drag & Drop aus der
 * To-do-Liste. Ohne Ende bekommt der Kalenderblock die technische
 * Standardlaenge; eine geplante Arbeitsdauer entsteht dadurch nicht.
 */
final class ScheduleInput
{
    public function __construct(
        #[Assert\NotNull(message: 'Bitte Tag und Uhrzeit angeben.')]
        public readonly ?\DateTimeImmutable $startsAt = null,

        #[Assert\GreaterThan(propertyPath: 'startsAt', message: 'Das Ende des Termins muss nach dem Beginn liegen.')]
        public readonly ?\DateTimeImmutable $endsAt = null,

        /** Zuständiger Arbeiter (z. B. Spalte in der Tagesansicht). Fehlt das Feld, bleibt die Zuteilung. */
        public readonly ?int $assigneeId = null,

        /** true: assigneeId wird uebernommen (auch null = niemand). */
        public readonly bool $changeAssignee = false,

        /** Termin einer bereits begonnenen Arbeit bewusst aendern. */
        public readonly bool $confirmStartedChange = false,
    ) {
    }
}
