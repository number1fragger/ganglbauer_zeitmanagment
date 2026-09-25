<?php

namespace App\Enum;

/** F2 – Prioritaet einer Arbeit. */
enum Priority: string
{
    case Low = 'niedrig';
    case Normal = 'normal';
    case High = 'hoch';
    case Urgent = 'dringend';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Niedrig',
            self::Normal => 'Normal',
            self::High => 'Hoch',
            self::Urgent => 'Dringend',
        };
    }

    /** Je hoeher, desto wichtiger – fuer die Sortierung der Arbeitsliste. */
    public function weight(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Normal => 1,
            self::High => 2,
            self::Urgent => 3,
        };
    }
}
