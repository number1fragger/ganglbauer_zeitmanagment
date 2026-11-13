<?php

namespace App\Tests\Functional;

/**
 * Geplante Arbeitszeit, Einstellung "paralleles Einplanen" und automatisches
 * Verschieben. Die Uhr steht auf Mo 2026-09-28 08:00 (Europe/Vienna).
 */
final class SchedulingTest extends ApiTestCase
{
    private function parallel(bool $allowed): void
    {
        $this->api('PUT', '/api/settings', $this->chef, ['parallelScheduling' => $allowed]);
    }

    /** @return array<string, mixed> */
    private function schedule(int $id, string $startsAt, ?int $minutes = null, bool $other = false): array
    {
        return $this->api('PUT', "/api/jobs/$id/schedule", $this->chef, array_filter([
            'startsAt' => $startsAt,
            'durationMinutes' => $minutes,
            'assigneeId' => $other ? $this->otherWorker->getId() : null,
            'changeAssignee' => $other ?: null,
        ]));
    }

    private function times(int $id): string
    {
        $job = $this->reload($id);

        return $job->getStartsAt()?->format('D H:i').'-'.$job->getEndsAt()?->format('D H:i');
    }

    public function testParallelSchedulingIsAllowedByDefault(): void
    {
        self::assertSame(['parallelScheduling' => true], $this->api('GET', '/api/settings', $this->worker));

        $first = $this->createJob(['startsAt' => '2026-09-28T08:00:00+02:00', 'minutes' => 120]);
        $second = $this->createJob(['startsAt' => '2026-09-28T09:00:00+02:00', 'minutes' => 60]);

        self::assertSame('2026-09-28T09:00:00+02:00', $second['startsAt']);
        self::assertSame([], $second['shifted']);
        self::assertSame('ueberschneidung', $second['warnings'][0]['type']);
        self::assertSame($first['id'], $second['warnings'][0]['jobId']);
    }

    public function testOnlySecretaryAndChefMayChangeSettings(): void
    {
        $this->api('PUT', '/api/settings', $this->worker, ['parallelScheduling' => false], 403);
        self::assertSame(['parallelScheduling' => false], $this->api('PUT', '/api/settings', $this->chef, ['parallelScheduling' => false]));
        self::assertSame(['parallelScheduling' => false], $this->api('GET', '/api/settings', $this->worker));
        $this->api('PUT', '/api/settings', $this->chef, [], 422);
    }

    public function testPlannedMinutesAreTheDefaultDuration(): void
    {
        $job = $this->createJob(['startsAt' => '2026-09-28T10:00:00+02:00', 'plannedMinutes' => 180]);
        self::assertSame(180, $job['plannedMinutes']);
        self::assertSame('2026-09-28T14:00:00+02:00', $job['endsAt']);

        // Ohne plannedMinutes bleibt es bei der Standardlaenge.
        $plain = $this->createJob(['startsAt' => '2026-09-29T10:00:00+02:00']);
        self::assertNull($plain['plannedMinutes']);
        self::assertSame('2026-09-29T11:00:00+02:00', $plain['endsAt']);

        // Aufgabe ohne Termin per Drag & Drop einplanen.
        $todo = $this->createJob(['plannedMinutes' => 90]);
        self::assertNull($todo['startsAt']);
        $scheduled = $this->schedule($todo['id'], '2026-09-30T07:30:00+02:00');
        self::assertSame('2026-09-30T09:00:00+02:00', $scheduled['endsAt']);

        // Eine ausdrueckliche Dauer geht vor.
        $explicit = $this->schedule($todo['id'], '2026-09-30T07:30:00+02:00', 30);
        self::assertSame('2026-09-30T08:00:00+02:00', $explicit['endsAt']);

        $this->api('POST', '/api/jobs', $this->chef, ['title' => 'X', 'plannedMinutes' => 0], 422);
    }

    public function testNewJobPushesLaterJobBehindItAndKeepsItsDuration(): void
    {
        $this->parallel(false);
        $later = $this->createJob(['startsAt' => '2026-09-28T10:00:00+02:00', 'minutes' => 60]);

        // 09:00-11:00 ueberschneidet; das spaetere (1 h) rutscht auf 11:00-12:00.
        $new = $this->createJob(['startsAt' => '2026-09-28T09:00:00+02:00', 'minutes' => 120]);

        self::assertSame('2026-09-28T09:00:00+02:00', $new['startsAt']);
        self::assertSame([$later['id']], array_column($new['shifted'], 'id'));
        self::assertSame('2026-09-28T11:00:00+02:00', $new['shifted'][0]['startsAt']);
        self::assertSame('Mon 11:00-Mon 12:00', $this->times($later['id']));
        self::assertSame([], $new['warnings']);
    }

    public function testShiftedJobSkipsLunchBreak(): void
    {
        $this->parallel(false);
        $later = $this->createJob(['startsAt' => '2026-09-28T10:00:00+02:00', 'durationMinutes' => 180]);
        self::assertSame('2026-09-28T14:00:00+02:00', $later['endsAt']);

        // Neu 08:00-11:30; das spaetere (3 h Arbeit) beginnt 11:30 -> 11:30-12:00 + 13:00-15:30.
        $this->createJob(['startsAt' => '2026-09-28T08:00:00+02:00', 'minutes' => 210]);

        self::assertSame('Mon 11:30-Mon 15:30', $this->times($later['id']));
    }

    public function testShiftedJobContinuesOnNextWorkday(): void
    {
        $this->parallel(false);
        // Freitag 13:00-14:00 (1 h).
        $later = $this->createJob(['startsAt' => '2026-10-02T13:00:00+02:00', 'minutes' => 60]);
        // Neu Freitag 12:30-13:30 -> das spaetere startet 13:30 und hat bis 14:00 nur 30 min, der Rest am Montag.
        $this->createJob(['startsAt' => '2026-10-02T12:30:00+02:00', 'minutes' => 60]);

        self::assertSame('Fri 13:30-Mon 08:00', $this->times($later['id']));

        // Neu bis Freitag 14:00 -> das naechste beginnt am Montag 07:30.
        $this->createJob(['startsAt' => '2026-10-02T13:30:00+02:00', 'minutes' => 30, 'title' => 'Z']);
        self::assertSame('Mon 07:30-Mon 08:30', $this->times($later['id']));
    }

    public function testCascadeOverThreeJobs(): void
    {
        $this->parallel(false);
        $a = $this->createJob(['startsAt' => '2026-09-28T08:00:00+02:00', 'minutes' => 60]);
        $b = $this->createJob(['startsAt' => '2026-09-28T09:00:00+02:00', 'minutes' => 60]);
        $c = $this->createJob(['startsAt' => '2026-09-28T10:00:00+02:00', 'minutes' => 60]);
        $free = $this->createJob(['startsAt' => '2026-09-28T15:00:00+02:00', 'minutes' => 60]);

        // Neu 08:00-09:30 -> a 09:30, b 10:30, c 11:30 (bis 13:30, Mittagspause); die freie Arbeit am Nachmittag bleibt.
        $new = $this->createJob(['startsAt' => '2026-09-28T08:00:00+02:00', 'minutes' => 90]);

        self::assertSame([$a['id'], $b['id'], $c['id']], array_column($new['shifted'], 'id'));
        self::assertSame('Mon 09:30-Mon 10:30', $this->times($a['id']));
        self::assertSame('Mon 10:30-Mon 11:30', $this->times($b['id']));
        self::assertSame('Mon 11:30-Mon 13:30', $this->times($c['id']));
        self::assertSame('Mon 15:00-Mon 16:00', $this->times($free['id']));
    }

    public function testNewJobInsideEarlierJobIsMovedBehindIt(): void
    {
        $this->parallel(false);
        $existing = $this->createJob(['startsAt' => '2026-09-28T08:00:00+02:00', 'minutes' => 120]);

        $new = $this->createJob(['startsAt' => '2026-09-28T09:00:00+02:00', 'minutes' => 60, 'title' => 'Neu']);

        self::assertSame('2026-09-28T10:00:00+02:00', $new['startsAt']);
        self::assertSame('2026-09-28T11:00:00+02:00', $new['endsAt']);
        self::assertSame([], $new['shifted']);
        self::assertSame('Mon 08:00-Mon 10:00', $this->times($existing['id']));
        self::assertSame('verschoben', $new['warnings'][0]['type']);
        self::assertSame('Wegen Überschneidung verschoben auf Mo 28.09. 10:00–11:00.', $new['warnings'][0]['message']);
    }

    public function testJobInProgressIsNeverMoved(): void
    {
        $this->parallel(false);
        $running = $this->createJob(['startsAt' => '2026-09-28T08:00:00+02:00', 'minutes' => 120]);
        $this->api('POST', "/api/jobs/{$running['id']}/start", $this->worker, null, 200);

        // Neu 07:30-09:00 liegt vor der laufenden Arbeit, ueberschneidet sie aber: die neue weicht aus.
        $new = $this->createJob(['startsAt' => '2026-09-28T07:30:00+02:00', 'minutes' => 90, 'title' => 'Neu']);

        self::assertSame('2026-09-28T10:00:00+02:00', $new['startsAt']);
        self::assertSame('2026-09-28T11:30:00+02:00', $new['endsAt']);
        self::assertSame('Mon 08:00-Mon 10:00', $this->times($running['id']));
        self::assertSame([], $new['shifted']);
    }

    public function testOtherWorkersAreUntouched(): void
    {
        $this->parallel(false);
        $others = $this->createJob(['startsAt' => '2026-09-28T10:00:00+02:00', 'minutes' => 60, 'assigneeId' => $this->otherWorker->getId()]);

        $new = $this->createJob(['startsAt' => '2026-09-28T09:30:00+02:00', 'minutes' => 120]);

        self::assertSame([], $new['shifted']);
        self::assertSame([], $new['warnings']);
        self::assertSame('Mon 10:00-Mon 11:00', $this->times($others['id']));
    }

    public function testRescheduleViaDragAndDropShiftsFollowingJobs(): void
    {
        $this->parallel(false);
        $a = $this->createJob(['startsAt' => '2026-09-28T08:00:00+02:00', 'minutes' => 60]);
        $b = $this->createJob(['startsAt' => '2026-09-29T08:00:00+02:00', 'minutes' => 60]);

        $moved = $this->schedule($b['id'], '2026-09-28T07:30:00+02:00', 60);

        // b beginnt 07:30 und endet 08:30; a (offen, spaeter) rutscht auf 08:30-09:30.
        self::assertSame([$a['id']], array_column($moved['shifted'], 'id'));
        self::assertSame('Mon 08:30-Mon 09:30', $this->times($a['id']));
    }
}
