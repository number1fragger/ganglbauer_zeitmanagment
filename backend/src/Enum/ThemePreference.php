<?php

namespace App\Enum;

/** Farbschema der Oberflaeche – pro Benutzer gespeichert. */
enum ThemePreference: string
{
    case Light = 'light';
    case Dark = 'dark';
    case System = 'system';
}
