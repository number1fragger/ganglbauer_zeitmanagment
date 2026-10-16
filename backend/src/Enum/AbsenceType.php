<?php

namespace App\Enum;

/**
 * Art einer Abwesenheit. Weitere Arten kommen einfach als neuer Fall dazu –
 * Datenbank (VARCHAR) und Frontend (liest die Liste aus GET /api/absences/types)
 * brauchen dafuer keine Aenderung.
 */
enum AbsenceType: string
{
    case Vacation = 'urlaub';
    case Doctor = 'arzttermin';
    case Personal = 'persoenlich';
    case School = 'berufsschule';

    public function label(): string
    {
        return match ($this) {
            self::Vacation => 'Urlaub',
            self::Doctor => 'Arzttermin',
            self::Personal => 'Persönlicher Termin',
            self::School => 'Berufsschule',
        };
    }

    /** Vorschlag im Formular: Urlaub und Berufsschule meist ganztaegig, Termine stundenweise. */
    public function allDayByDefault(): bool
    {
        return self::Vacation === $this || self::School === $this;
    }

    /**
     * @return list<array{value: string, label: string, allDay: bool}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
            'allDay' => $type->allDayByDefault(),
        ], self::cases());
    }
}
