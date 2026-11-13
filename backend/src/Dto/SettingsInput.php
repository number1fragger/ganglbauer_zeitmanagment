<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Einstellungen der Werkstatt (PUT /api/settings). */
final class SettingsInput
{
    public function __construct(
        /** true: Arbeiten eines Mitarbeiters duerfen sich ueberschneiden; false: spaetere werden verschoben. */
        #[Assert\NotNull(message: 'Bitte angeben, ob parallel geplant werden darf.')]
        public readonly ?bool $parallelScheduling = null,
        /** true: nichts speichern, nur melden, welche Arbeiten beim Ausschalten verschoben wuerden. */
        public readonly ?bool $dryRun = false,
    ) {
    }
}
