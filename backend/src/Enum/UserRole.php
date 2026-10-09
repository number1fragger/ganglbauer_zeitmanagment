<?php

namespace App\Enum;

/**
 * Rolle in der Werkstatt. Bestimmt, welche Ansichten und API-Aufrufe
 * erlaubt sind (siehe role_hierarchy in config/packages/security.yaml).
 */
enum UserRole: string
{
    case Chef = 'chef';
    case Foreman = 'vorarbeiter';
    case Worker = 'arbeiter';

    public function securityRole(): string
    {
        return match ($this) {
            self::Chef => 'ROLE_CHEF',
            self::Foreman => 'ROLE_VORARBEITER',
            self::Worker => 'ROLE_ARBEITER',
        };
    }

    /** Vorarbeiter und Arbeiter bekommen selbst Arbeiten zugeteilt, der Chef plant nur. */
    public function worksInWorkshop(): bool
    {
        return self::Chef !== $this;
    }
}
