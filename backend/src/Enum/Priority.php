<?php

namespace App\Enum;

/** F2 – Prioritaet einer Arbeit. */
enum Priority: string
{
    case High = 'hoch';
    case Medium = 'mittel';
    case Low = 'nieder';

    /** Je hoeher, desto wichtiger – fuer die Sortierung. */
    public function weight(): int
    {
        return match ($this) {
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }
}
