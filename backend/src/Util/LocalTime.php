<?php

namespace App\Util;

/**
 * Doctrine speichert Zeitpunkte ohne Zeitzone. Damit "07:00" in der
 * Datenbank auch 07:00 in Wien bedeutet, wird jeder eingehende Zeitpunkt
 * vor dem Speichern in die Server-Zeitzone umgerechnet.
 */
final class LocalTime
{
    public static function of(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }
}
