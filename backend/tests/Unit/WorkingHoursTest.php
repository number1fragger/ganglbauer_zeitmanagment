<?php

namespace App\Tests\Unit;

use App\Util\WorkingHours;
use PHPUnit\Framework\TestCase;

/**
 * Regulaere Arbeitszeiten: Mo–Do 07:30–17:00, Fr 07:30–14:00,
 * Mittagspause 12:00–13:00. 2026-09-28 ist ein Montag.
 */
final class WorkingHoursTest extends TestCase
{
    public function testWindowsExcludeTheLunchBreak(): void
    {
        self::assertSame([['07:30', '12:00'], ['13:00', '17:00']], $this->windows('2026-09-28'));
        self::assertSame([['07:30', '12:00'], ['13:00', '14:00']], $this->windows('2026-10-02'), 'Freitag bis 14:00');
        self::assertSame([], $this->windows('2026-10-03'), 'Samstag arbeitsfrei');
        self::assertSame([], $this->windows('2026-10-04'), 'Sonntag arbeitsfrei');
    }

    public function testThreeWorkingHoursAcrossLunchEndAtTwo(): void
    {
        // 10:00 + 3 h Arbeit: 10–12 und 13–14 – nicht 13:00.
        self::assertSame('2026-09-28 14:00', $this->end('2026-09-28 10:00', 180));
    }

    public function testEndIsTheEarliestMomentReachingTheDuration(): void
    {
        // 10:00 + 2 h endet um 12:00, nicht erst nach der Pause.
        self::assertSame('2026-09-28 12:00', $this->end('2026-09-28 10:00', 120));
    }

    public function testDurationContinuesOnTheNextWorkingDay(): void
    {
        // Mo 15:00 + 4 h: Mo 15–17, Di 07:30–09:30.
        self::assertSame('2026-09-29 09:30', $this->end('2026-09-28 15:00', 240));
        // Fr 13:00 + 3 h: Fr 13–14, Mo 07:30–09:30 – das Wochenende zaehlt nicht.
        self::assertSame('2026-10-05 09:30', $this->end('2026-10-02 13:00', 180));
    }

    public function testStartOutsideWorkingTimeCountsClockTime(): void
    {
        // Bewusst am Samstag oder nach Feierabend: nach Uhrzeit, kein Sprung auf Montag.
        self::assertSame('2026-10-03 11:00', $this->end('2026-10-03 09:00', 120));
        self::assertSame('2026-09-28 19:00', $this->end('2026-09-28 17:30', 90));
        self::assertSame(120, WorkingHours::durationMinutes(new \DateTimeImmutable('2026-10-03 09:00'), new \DateTimeImmutable('2026-10-03 11:00')));
    }

    public function testDurationOfAnAppointment(): void
    {
        $duration = static fn (string $from, string $to): int => WorkingHours::durationMinutes(new \DateTimeImmutable($from), new \DateTimeImmutable($to));

        self::assertSame(120, $duration('2026-09-28 11:00', '2026-09-28 14:00'), 'Mittagspause zaehlt nicht');
        self::assertSame(240, $duration('2026-09-28 15:00', '2026-09-29 09:30'), 'Nacht zaehlt nicht');
        self::assertSame(180, $duration('2026-10-02 13:00', '2026-10-05 09:30'), 'Wochenende zaehlt nicht');
    }

    public function testDurationAndEndAreConsistent(): void
    {
        foreach (['2026-09-28 07:30', '2026-09-28 11:15', '2026-09-29 16:45', '2026-10-02 13:30'] as $start) {
            foreach ([15, 60, 210, 480, 1000] as $minutes) {
                $from = new \DateTimeImmutable($start);
                $end = WorkingHours::endFor($from, $minutes);
                self::assertSame($minutes, WorkingHours::durationMinutes($from, $end), "$start + $minutes min");
            }
        }
    }

    public function testWarningsForStartOrEndOutsideWorkingTime(): void
    {
        self::assertSame([], $this->warnings('2026-09-28 11:00', '2026-09-28 14:00'), 'ueber die Pause laufen ist erlaubt');
        self::assertSame([], $this->warnings('2026-09-28 13:00', '2026-09-28 17:00'));
        self::assertSame([], $this->warnings('2026-09-28 10:00', '2026-09-28 12:00'));

        self::assertStringContainsString('Mittagspause', $this->warnings('2026-09-28 12:15', '2026-09-28 13:30')[0]);
        self::assertStringContainsString('Ende in der Mittagspause', $this->warnings('2026-09-28 11:00', '2026-09-28 12:30')[0]);
        self::assertStringContainsString('Ende in der Mittagspause', $this->warnings('2026-09-28 11:00', '2026-09-28 13:00')[0]);
        self::assertStringContainsString('außerhalb der Arbeitszeit (Freitag: 07:30–14:00', $this->warnings('2026-10-02 13:00', '2026-10-02 15:00')[0]);
        self::assertStringContainsString('arbeitsfreien Tag (Samstag)', $this->warnings('2026-10-03 08:00', '2026-10-03 09:00')[0]);
        self::assertCount(2, $this->warnings('2026-09-28 06:00', '2026-09-28 18:00'));
    }

    public function testConfigurationForTheFrontend(): void
    {
        $config = WorkingHours::toArray();

        self::assertSame(['start' => '07:30', 'end' => '17:00'], $config['days']['1']);
        self::assertSame(['start' => '07:30', 'end' => '14:00'], $config['days']['5']);
        self::assertNull($config['days']['6']);
        self::assertSame([['start' => '12:00', 'end' => '13:00', 'label' => 'Mittagspause']], $config['breaks']);
        self::assertSame('Mo–Do 07:30–17:00, Fr 07:30–14:00, Mittagspause 12:00–13:00', $config['summary']);
    }

    /** @return list<array{0: string, 1: string}> */
    private function windows(string $day): array
    {
        return array_map(
            static fn (array $w): array => [$w[0]->format('H:i'), $w[1]->format('H:i')],
            WorkingHours::windowsOn(new \DateTimeImmutable($day)),
        );
    }

    private function end(string $start, int $minutes): string
    {
        return WorkingHours::endFor(new \DateTimeImmutable($start), $minutes)->format('Y-m-d H:i');
    }

    /** @return list<string> */
    private function warnings(string $from, string $to): array
    {
        return WorkingHours::warnings(new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }
}
