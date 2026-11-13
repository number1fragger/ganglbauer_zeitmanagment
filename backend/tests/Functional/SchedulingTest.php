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
        self::assertSame([], $second['warnings'], 'gleichzeitige Auftraege sind erlaubt, kein Konflikt');
        self::assertSame('2026-09-28T08:00:00+02:00', $this->api('GET', "/api/jobs/{$first['id']}", $this->chef)['startsAt']);
    }

    public function testOnlySecretaryAndChefMayChangeSettings(): void
    {
        $this->api('PUT', '/api/settings', $this->worker, ['parallelScheduling' => false], 403);
        self::assertSame(['parallelScheduling' => false, 'shifted' => []], $this->api('PUT', '/api/settings', $this->chef, ['parallelScheduling' => false]));
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

    public function testDisablingResortsExistingOverlapsAndReturnsShifted(): void
    {
        $a = $this->createJob(['startsAt' => '2026-09-28T09:00:00+02:00', 'minutes' => 120]);
        $b = $this->createJob(['startsAt' => '2026-09-28T10:00:00+02:00', 'minutes' => 60]);
        $c = $this->createJob(['startsAt' => '2026-09-28T10:30:00+02:00', 'minutes' => 60]);
        $other = $this->createJob(['startsAt' => '2026-09-28T09:30:00+02:00', 'minutes' => 60, 'assigneeId' => $this->otherWorker->getId()]);

        $result = $this->api('PUT', '/api/settings', $this->chef, ['parallelScheduling' => false]);

        self::assertFalse($result['parallelScheduling']);
        self::assertSame([$b['id'], $c['id']], array_column($result['shifted'], 'id'));
        self::assertSame('2026-09-28T11:00:00+02:00', $result['shifted'][0]['startsAt']);
        self::assertSame('Mon 09:00-Mon 11:00', $this->times($a['id']));
        self::assertSame('Mon 11:00-Mon 12:00', $this->times($b['id']));
        self::assertSame('Mon 13:00-Mon 14:00', $this->times($c['id']));
        self::assertSame('Mon 09:30-Mon 10:30', $this->times($other['id']));

        // Erneutes Ausschalten und Einschalten sortiert nichts mehr.
        self::assertSame([], $this->api('PUT', '/api/settings', $this->chef, ['parallelScheduling' => false])['shifted']);
        self::assertSame([], $this->api('PUT', '/api/settings', $this->chef, ['parallelScheduling' => true])['shifted']);
    }

    public function testResortLeavesPastAndStartedJobsAlone(): void
    {
        $this->at('2026-09-28 10:00');
        $past = $this->createJob(['startsAt' => '2026-09-28T09:00:00+02:00', 'minutes' => 120]);
        $running = $this->createJob(['startsAt' => '2026-09-28T10:30:00+02:00', 'minutes' => 60]);
        $this->api('POST', "/api/jobs/{$running['id']}/start", $this->worker, null, 200);
        $later = $this->createJob(['startsAt' => '2026-09-28T11:00:00+02:00', 'minutes' => 60]);

        $result = $this->api('PUT', '/api/settings', $this->chef, ['parallelScheduling' => false]);

        // Vergangenes (beginnt vor jetzt) und Laufendes bleiben; nur die spaetere Arbeit weicht der laufenden aus.
        self::assertSame([$later['id']], array_column($result['shifted'], 'id'));
        self::assertSame('Mon 09:00-Mon 11:00', $this->times($past['id']));
        self::assertSame('Mon 10:30-Mon 11:30', $this->times($running['id']));
        self::assertSame('Mon 11:30-Mon 13:30', $this->times($later['id']));
    }

    public function testDryRunChangesNothing(): void
    {
        $this->createJob(['startsAt' => '2026-09-28T09:00:00+02:00', 'minutes' => 120]);
        $b = $this->createJob(['startsAt' => '2026-09-28T10:00:00+02:00', 'minutes' => 60]);

        $result = $this->api('PUT', '/api/settings', $this->chef, ['parallelScheduling' => false, 'dryRun' => true]);

        self::assertTrue($result['parallelScheduling'], 'gespeicherter Wert bleibt');
        self::assertSame([$b['id']], array_column($result['shifted'], 'id'));
        self::assertSame('2026-09-28T11:00:00+02:00', $result['shifted'][0]['startsAt']);
        self::assertSame('2026-09-28T12:00:00+02:00', $result['shifted'][0]['endsAt']);
        self::assertSame('Mon 10:00-Mon 11:00', $this->times($b['id']));
        self::assertTrue($this->api('GET', '/api/settings', $this->worker)['parallelScheduling']);

        // Auch beim Einschalten nichts zu verschieben.
        self::assertSame([], $this->api('PUT', '/api/settings', $this->chef, ['parallelScheduling' => true, 'dryRun' => true])['shifted']);
    }

    public function testWorkerMayNotUseSettingsDryRun(): void
    {
        $this->api('PUT', '/api/settings', $this->worker, ['parallelScheduling' => false, 'dryRun' => true], 403);
    }

    public function testCheckPreviewShowsShiftWhenParallelIsDisabled(): void
    {
        $this->parallel(false);
        $later = $this->createJob(['startsAt' => '2026-09-28T10:00:00+02:00', 'minutes' => 60, 'customer' => 'Huber']);
        $query = http_build_query([
            'startsAt' => '2026-09-28T09:00:00+02:00',
            'endsAt' => '2026-09-28T11:00:00+02:00',
            'assigneeId' => $this->worker->getId(),
        ]);

        $check = $this->api('GET', '/api/jobs/check?'.$query, $this->chef);

        self::assertSame('2026-09-28T09:00:00+02:00', $check['shift']['startsAt']);
        self::assertSame('2026-09-28T11:00:00+02:00', $check['shift']['endsAt']);
        self::assertSame([[
            'id' => $later['id'],
            'title' => 'Bremsen hinten',
            'customer' => 'Huber',
            'startsAt' => '2026-09-28T11:00:00+02:00',
            'endsAt' => '2026-09-28T12:00:00+02:00',
        ]], $check['shift']['moved']);
        self::assertSame([], $check['warnings'], 'Ueberschneidungen werden aufgeloest, nicht gewarnt');
        self::assertSame('Mon 10:00-Mon 11:00', $this->times($later['id']), 'Vorschau speichert nichts');
    }

    public function testCheckPreviewReportsMovedJobItself(): void
    {
        $this->parallel(false);
        $this->createJob(['startsAt' => '2026-09-28T08:00:00+02:00', 'minutes' => 120]);
        $query = http_build_query([
            'startsAt' => '2026-09-28T09:00:00+02:00',
            'endsAt' => '2026-09-28T10:00:00+02:00',
            'assigneeId' => $this->worker->getId(),
        ]);

        $check = $this->api('GET', '/api/jobs/check?'.$query, $this->chef);

        self::assertSame('2026-09-28T10:00:00+02:00', $check['shift']['startsAt']);
        self::assertSame('2026-09-28T11:00:00+02:00', $check['shift']['endsAt']);
        self::assertSame([], $check['shift']['moved']);
        self::assertSame('verschoben', $check['warnings'][0]['type']);
        self::assertSame('Wird wegen Überschneidung verschoben auf Mo 28.09. 10:00–11:00.', $check['warnings'][0]['message']);
    }

    public function testCheckShiftIsNullWhenParallelIsAllowed(): void
    {
        $this->createJob(['startsAt' => '2026-09-28T09:00:00+02:00', 'minutes' => 120]);
        $query = http_build_query([
            'startsAt' => '2026-09-28T10:00:00+02:00',
            'endsAt' => '2026-09-28T11:00:00+02:00',
            'assigneeId' => $this->worker->getId(),
        ]);

        $check = $this->api('GET', '/api/jobs/check?'.$query, $this->chef);

        self::assertNull($check['shift']);
        self::assertSame([], $check['warnings']);
    }

    public function testApprenticeCannotWorkOnTwoJobsAtOnce(): void
    {
        $this->parallel(false);
        $apprentice = $this->user('l.lehr', 'Lena', 'Lehr', \App\Enum\UserRole::Apprentice);
        $this->em->flush();

        $own = $this->createJob([
            'startsAt' => '2026-09-28T09:00:00+02:00', 'minutes' => 60,
            'assigneeId' => $this->otherWorker->getId(), 'apprenticeId' => $apprentice->getId(),
        ]);
        $team = $this->createJob([
            'startsAt' => '2026-09-28T08:00:00+02:00', 'minutes' => 120,
            'apprenticeId' => $apprentice->getId(),
        ]);

        self::assertSame([$own['id']], array_column($team['shifted'], 'id'));
        self::assertSame('Mon 10:00-Mon 11:00', $this->times($own['id']));
    }

    public function testJobsWithOnlyAnApprenticeAreShiftedToo(): void
    {
        $this->parallel(false);
        $apprentice = $this->user('l.lehr', 'Lena', 'Lehr', \App\Enum\UserRole::Apprentice);
        $this->em->flush();

        $first = $this->api('POST', '/api/jobs', $this->chef, [
            'title' => 'Lager', 'apprenticeId' => $apprentice->getId(),
            'startsAt' => '2026-09-28T09:00:00+02:00', 'endsAt' => '2026-09-28T10:00:00+02:00',
        ], 201);
        $preview = $this->api('GET', '/api/jobs/check?'.http_build_query([
            'startsAt' => '2026-09-28T09:30:00+02:00', 'endsAt' => '2026-09-28T10:30:00+02:00',
            'apprenticeId' => $apprentice->getId(),
        ]), $this->chef);
        $second = $this->api('POST', '/api/jobs', $this->chef, [
            'title' => 'Putzen', 'apprenticeId' => $apprentice->getId(),
            'startsAt' => '2026-09-28T09:30:00+02:00', 'endsAt' => '2026-09-28T10:30:00+02:00',
        ], 201);

        self::assertNotNull($preview['shift']);
        self::assertSame('Mon 09:00-Mon 10:00', $this->times($first['id']));
        self::assertSame('Mon 10:00-Mon 11:00', $this->times($second['id']));
    }
}
