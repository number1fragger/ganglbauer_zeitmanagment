<?php

namespace App\Util;

/**
 * Regulaere Arbeitszeiten der Werkstatt – die einzige Stelle, an der sie
 * hinterlegt sind. Das Frontend bekommt sie ueber GET /api/working-hours.
 *
 * Begriffe:
 *  - Arbeitsfenster: ein zusammenhaengender Abschnitt regulaerer Arbeitszeit,
 *    z. B. Mo 07:30–12:00 und Mo 13:00–17:00 (die Mittagspause trennt sie).
 *  - Dauer eines Termins: die Minuten des Termins, die in Arbeitsfenster
 *    fallen. Mittagspause, Abende, Naechte und Wochenenden zaehlen nicht.
 *    Beginnt ein Termin bewusst ausserhalb der Arbeitszeit (z. B. Samstag),
 *    zaehlt die Uhrzeit – sonst wuerde er unbemerkt auf einen anderen Tag springen.
 *  - Ende aus Beginn + Dauer: der frueheste Zeitpunkt, an dem die Dauer an
 *    Arbeitszeit erreicht ist; Pausen und arbeitsfreie Zeit werden uebersprungen,
 *    auch ueber mehrere Arbeitstage.
 */
final class WorkingHours
{
    /** ISO-Wochentag (1 = Montag) => [Beginn, Ende]. Fehlende Tage sind arbeitsfrei. */
    public const DAYS = [
        1 => ['07:30', '17:00'],
        2 => ['07:30', '17:00'],
        3 => ['07:30', '17:00'],
        4 => ['07:30', '17:00'],
        5 => ['07:30', '14:00'],
    ];

    /** Pausen an jedem Arbeitstag – in dieser Zeit wird nicht eingeplant. */
    public const BREAKS = [
        ['12:00', '13:00', 'Mittagspause'],
    ];

    /** Schutz vor Endlosschleifen bei absurd langen Dauern. */
    private const MAX_DAYS = 400;

    private const WEEKDAYS = [1 => 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

    /**
     * Arbeitsfenster eines Kalendertags (ohne Pausen), zeitlich sortiert.
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    public static function windowsOn(\DateTimeImmutable $day): array
    {
        $day = LocalTime::of($day);
        $hours = self::DAYS[(int) $day->format('N')] ?? null;
        if (null === $hours) {
            return [];
        }

        $windows = [[self::at($day, $hours[0]), self::at($day, $hours[1])]];
        foreach (self::BREAKS as [$from, $to]) {
            $breakStart = self::at($day, $from);
            $breakEnd = self::at($day, $to);
            $split = [];
            foreach ($windows as [$start, $end]) {
                if ($breakEnd <= $start || $breakStart >= $end) {
                    $split[] = [$start, $end];
                    continue;
                }
                if ($breakStart > $start) {
                    $split[] = [$start, $breakStart];
                }
                if ($breakEnd < $end) {
                    $split[] = [$breakEnd, $end];
                }
            }
            $windows = $split;
        }

        return $windows;
    }

    /** Liegt der Zeitpunkt als Beginn in der Arbeitszeit? (Fensterbeginn zaehlt, Fensterende nicht) */
    public static function isWorkingStart(\DateTimeImmutable $moment): bool
    {
        foreach (self::windowsOn($moment) as [$start, $end]) {
            if ($moment >= $start && $moment < $end) {
                return true;
            }
        }

        return false;
    }

    /** Liegt der Zeitpunkt als Ende in der Arbeitszeit? (Fensterende zaehlt, Fensterbeginn nicht) */
    public static function isWorkingEnd(\DateTimeImmutable $moment): bool
    {
        foreach (self::windowsOn($moment) as [$start, $end]) {
            if ($moment > $start && $moment <= $end) {
                return true;
            }
        }

        return false;
    }

    /** Minuten zwischen Beginn und Ende, die in Arbeitsfenster fallen. */
    public static function workingMinutesBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $from = LocalTime::of($from);
        $to = LocalTime::of($to);
        $seconds = 0;

        for ($day = $from->setTime(0, 0), $i = 0; $day < $to && $i < self::MAX_DAYS; $day = $day->modify('+1 day'), ++$i) {
            foreach (self::windowsOn($day) as [$start, $end]) {
                $overlap = min($end, $to)->getTimestamp() - max($start, $from)->getTimestamp();
                $seconds += max(0, $overlap);
            }
        }

        return intdiv($seconds, 60);
    }

    /**
     * Dauer eines Termins in Minuten (siehe Klassenkommentar): Arbeitszeit,
     * oder Uhrzeit, wenn der Termin ausserhalb der Arbeitszeit beginnt.
     */
    public static function durationMinutes(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): int
    {
        if (!self::isWorkingStart($startsAt)) {
            return max(0, intdiv($endsAt->getTimestamp() - $startsAt->getTimestamp(), 60));
        }

        return self::workingMinutesBetween($startsAt, $endsAt);
    }

    /** Ende eines Termins aus Beginn und Dauer (Gegenstueck zu durationMinutes). */
    public static function endFor(\DateTimeImmutable $startsAt, int $minutes): \DateTimeImmutable
    {
        if ($minutes <= 0) {
            throw new \InvalidArgumentException('Die Dauer muss positiv sein.');
        }

        $startsAt = LocalTime::of($startsAt);
        if (!self::isWorkingStart($startsAt)) {
            return $startsAt->modify(sprintf('+%d minutes', $minutes));
        }

        $left = $minutes * 60;
        for ($day = $startsAt->setTime(0, 0), $i = 0; $i < self::MAX_DAYS; $day = $day->modify('+1 day'), ++$i) {
            foreach (self::windowsOn($day) as [$start, $end]) {
                $from = max($start, $startsAt);
                $available = $end->getTimestamp() - $from->getTimestamp();
                if ($available <= 0) {
                    continue;
                }
                if ($left <= $available) {
                    return $from->modify(sprintf('+%d seconds', $left));
                }
                $left -= $available;
            }
        }

        throw new \InvalidArgumentException('Die Dauer ist zu lang.');
    }

    /** Naechster Zeitpunkt in der Arbeitszeit ab $moment (oder $moment selbst). */
    public static function nextWorkingMoment(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        $moment = LocalTime::of($moment);
        for ($day = $moment->setTime(0, 0), $i = 0; $i < self::MAX_DAYS; $day = $day->modify('+1 day'), ++$i) {
            foreach (self::windowsOn($day) as [$start, $end]) {
                if ($moment < $end) {
                    return max($start, $moment);
                }
            }
        }

        return $moment;
    }

    /** Arbeitsschluss an diesem Tag – null an arbeitsfreien Tagen. */
    public static function dayEnd(\DateTimeImmutable $day): ?\DateTimeImmutable
    {
        $windows = self::windowsOn($day);

        return [] === $windows ? null : $windows[\count($windows) - 1][1];
    }

    /**
     * Hinweise, wenn ein Termin ausserhalb der regulaeren Arbeitszeit beginnt
     * oder endet. Ein Termin darf ueber die Mittagspause oder ueber Nacht
     * laufen (die Zeit zaehlt dann nicht) – Beginn und Ende muessen aber in
     * der Arbeitszeit liegen, sonst wird gewarnt (nicht verhindert).
     *
     * @return list<string>
     */
    public static function warnings(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): array
    {
        $warnings = [];

        if (!self::isWorkingStart($startsAt)) {
            $warnings[] = 'Beginn '.self::describeOutside($startsAt, true).'.';
        }
        if (!self::isWorkingEnd($endsAt)) {
            $warnings[] = 'Ende '.self::describeOutside($endsAt, false).'.';
        }

        return $warnings;
    }

    /** "Mo–Do 07:30–17:00, Fr 07:30–14:00, Mittagspause 12:00–13:00" */
    public static function summary(): string
    {
        $groups = [];
        foreach (self::DAYS as $day => [$from, $to]) {
            $key = $from.'–'.$to;
            $groups[$key] ??= [];
            $groups[$key][] = $day;
        }

        $parts = [];
        foreach ($groups as $range => $days) {
            $first = mb_substr(self::WEEKDAYS[$days[0]], 0, 2);
            $last = mb_substr(self::WEEKDAYS[$days[\count($days) - 1]], 0, 2);
            $parts[] = (\count($days) > 1 ? $first.'–'.$last : $first).' '.$range;
        }
        foreach (self::BREAKS as [$from, $to, $label]) {
            $parts[] = sprintf('%s %s–%s', $label, $from, $to);
        }

        return implode(', ', $parts);
    }

    /**
     * Fuer das Frontend.
     *
     * @return array<string, mixed>
     */
    public static function toArray(): array
    {
        $days = [];
        for ($day = 1; $day <= 7; ++$day) {
            $hours = self::DAYS[$day] ?? null;
            $days[(string) $day] = null === $hours ? null : ['start' => $hours[0], 'end' => $hours[1]];
        }

        return [
            'timezone' => date_default_timezone_get(),
            'days' => $days,
            'breaks' => array_map(static fn (array $b): array => ['start' => $b[0], 'end' => $b[1], 'label' => $b[2]], self::BREAKS),
            'summary' => self::summary(),
        ];
    }

    private static function describeOutside(\DateTimeImmutable $moment, bool $asStart): string
    {
        $moment = LocalTime::of($moment);
        $weekday = (int) $moment->format('N');
        $hours = self::DAYS[$weekday] ?? null;

        if (null === $hours) {
            return sprintf('an einem arbeitsfreien Tag (%s)', self::WEEKDAYS[$weekday]);
        }

        foreach (self::BREAKS as [$from, $to, $label]) {
            $breakStart = self::at($moment, $from);
            $breakEnd = self::at($moment, $to);
            if ($asStart ? $moment >= $breakStart && $moment < $breakEnd : $moment > $breakStart && $moment <= $breakEnd) {
                return sprintf('in der %s (%s–%s)', $label, $from, $to);
            }
        }

        return sprintf('außerhalb der Arbeitszeit (%s: %s–%s Uhr)', self::WEEKDAYS[$weekday], $hours[0], $hours[1]);
    }

    private static function at(\DateTimeImmutable $day, string $time): \DateTimeImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $day->setTime($hour, $minute);
    }
}
