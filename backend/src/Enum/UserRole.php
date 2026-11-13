<?php

namespace App\Enum;

/**
 * Rolle in der Werkstatt. Bestimmt, welche Ansichten und API-Aufrufe
 * erlaubt sind (siehe role_hierarchy in config/packages/security.yaml).
 *
 * Die Sekretaerin hiess frueher "Vorarbeiter" (Wert "vorarbeiter"); die
 * Migration Version20261016090000 hat bestehende Zuordnungen umgestellt,
 * die Rechte sind unveraendert.
 */
enum UserRole: string
{
    case Chef = 'chef';
    case Secretary = 'sekretaerin';
    case Worker = 'arbeiter';
    case Apprentice = 'lehrling';

    public function securityRole(): string
    {
        return match ($this) {
            self::Chef => 'ROLE_CHEF',
            self::Secretary => 'ROLE_SEKRETAERIN',
            self::Worker => 'ROLE_ARBEITER',
            self::Apprentice => 'ROLE_LEHRLING',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Chef => 'Chef',
            self::Secretary => 'Sekretärin',
            self::Worker => 'Arbeiter',
            self::Apprentice => 'Lehrling',
        };
    }

    /** Alle ausser dem Chef bekommen selbst Arbeiten zugeteilt und haben eine Spalte im Kalender. */
    public function worksInWorkshop(): bool
    {
        return self::Chef !== $this;
    }

    /** Nur Lehrlinge koennen einem Auftrag zusaetzlich als Unterstuetzung zugeordnet werden. */
    public function canAssistOnJobs(): bool
    {
        return self::Apprentice === $this;
    }
}
