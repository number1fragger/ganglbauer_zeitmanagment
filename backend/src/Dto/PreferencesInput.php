<?php

namespace App\Dto;

use App\Enum\ThemePreference;

/** Persoenliche Einstellungen des angemeldeten Benutzers. */
final class PreferencesInput
{
    public function __construct(
        public readonly ThemePreference $theme = ThemePreference::System,
    ) {
    }
}
