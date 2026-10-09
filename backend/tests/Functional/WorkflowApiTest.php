<?php

namespace App\Tests\Functional;

use App\Entity\TimeEntry;
use App\Enum\JobStatus;

/**
 * Abnahmetests fuer Aufgaben, Ist-Zeiterfassung und Umplanung –
 * durchgespielt ueber die echte API mit Datenbank.
 */
final class WorkflowApiTest extends ApiTestCase
{
    /** 1 + 2: Aufgabe ohne Zeitangaben – in der Aufgabenliste, nicht im Kalender. */
    public function testTaskWithoutTimesAppearsOnBoardButNotInCalendar(): void
    {
        $job = $this->createJob(['title' => 'Werkzeugwand beschriften', 'description' => 'Alle Fächer neu']);

        self::assertFalse($job['scheduled']);
        self::assertNull($job['startsAt']);
        self::assertNull($job['endsAt']);
        self::assertNull($job['plannedMinutes']);
        self::assertSame('offen', $job['status']);
        self::assertSame(0, $job['actualSeconds']);
        self::assertSame('Alle Fächer neu', $job['description']);

        $board = $this->api('GET', '/api/jobs/board', $this->chef);
        self::assertContains($job['id'], array_column($board, 'id'));

        $calendar = $this->api('GET', '/api/jobs?from=2026-09-01T00:00:00Z&to=2026-11-01T00:00:00Z', $this->chef);
        self::assertSame([], $calendar);

        self::assertCount(0, $this->reload($job['id'])->getTimeEntries());
    }

    /** 3: Eingeplante Arbeit erscheint zur richtigen Zeit im Kalender. */
    public function testScheduledJobAppearsAtTheRightTime(): void
    {
        $job = $this->createJob(['startsAt' => '2026-09-29T07:30:00+02:00', 'plannedMinutes' => 90]);

        $day = $this->api('GET', '/api/jobs?from=2026-09-28T22:00:00Z&to=2026-09-29T22:00:00Z', $this->chef);
        self::assertSame([$job['id']], array_column($day, 'id'));
        self::assertSame('2026-09-29T07:30:00+02:00', $day[0]['startsAt']);
        self::assertSame('2026-09-29T09:00:00+02:00', $day[0]['endsAt']);

        $otherDay = $this->api('GET', '/api/jobs?from=2026-09-29T22:00:00Z&to=2026-09-30T22:00:00Z', $this->chef);
        self::assertSame([], $otherDay);
    }

    /** 4 + 5 + 6: Starten, Pausieren, Fortsetzen, Abschliessen – Pausen zaehlen nicht. */
    public function testStartPauseResumeComplete(): void
    {
        $job = $this->createJob(['plannedMinutes' => 180]);
        $id = $job['id'];

        $this->at('2026-09-28 09:00');
        $started = $this->api('POST', "/api/jobs/$id/start", $this->worker);
        self::assertSame('in_arbeit', $started['status']);
        self::assertTrue($started['running']);
        self::assertSame('2026-09-28T09:00:00+02:00', $started['runningSince']);

        $this->at('2026-09-28 10:00');
        $paused = $this->api('POST', "/api/jobs/$id/pause", $this->worker);
        self::assertFalse($paused['running']);
        self::assertTrue($paused['paused']);
        self::assertSame(60, $paused['actualMinutes']);

        $this->at('2026-09-28 11:30'); // 90 Minuten Pause
        $this->api('POST', "/api/jobs/$id/start", $this->worker);

        $this->at('2026-09-28 12:00');
        $done = $this->api('POST', "/api/jobs/$id/complete", $this->worker);
        self::assertSame('erledigt', $done['status']);
        self::assertFalse($done['running']);
        self::assertSame(90, $done['actualMinutes']);
        self::assertSame('2026-09-28T12:00:00+02:00', $done['completedAt']);

        // Dauerhaft gespeichert: frisch aus der Datenbank gelesen.
        $stored = $this->reload($id);
        self::assertSame(JobStatus::Done, $stored->getStatus());
        self::assertSame(90 * 60, $stored->getActualSeconds());
        self::assertCount(2, $stored->getTimeEntries());
    }

    /** 7 + 8: Beispiel aus der Angabe – Mo 09–12, Di 08–11 = 6 Stunden, nicht 26. */
    public function testWorkAcrossSeveralDays(): void
    {
        $job = $this->createJob([
            'plannedMinutes' => 480,
            'startsAt' => '2026-09-28T09:00:00+02:00',
            'endsAt' => '2026-09-29T16:00:00+02:00',
        ]);
        $id = $job['id'];
        self::assertSame(31 * 60, $job['calendarMinutes']);

        $this->at('2026-09-28 09:00');
        $this->api('POST', "/api/jobs/$id/start", $this->worker);
        $this->at('2026-09-28 12:00');
        $this->api('POST', "/api/jobs/$id/pause", $this->worker);

        // Ueber Nacht: offen, aber keine Arbeitszeit.
        $this->at('2026-09-29 07:59');
        $overnight = $this->api('GET', "/api/jobs/$id", $this->worker);
        self::assertSame(180, $overnight['actualMinutes']);
        self::assertSame('in_arbeit', $overnight['status']);

        $this->at('2026-09-29 08:00');
        $this->api('POST', "/api/jobs/$id/start", $this->worker);
        $this->at('2026-09-29 11:00');
        $done = $this->api('POST', "/api/jobs/$id/complete", $this->worker);

        self::assertSame(360, $done['actualMinutes']);
        self::assertSame(2, $done['workedDays']);
        self::assertSame($this->worker->getId(), $done['assignee']['id']);

        $detail = $this->api('GET', "/api/jobs/$id", $this->chef);
        self::assertCount(2, $detail['timeEntries']);
        self::assertSame(10800, $detail['timeEntries'][1]['durationSeconds']);
    }

    /** 9: Doppelklicks erzeugen keine doppelte Arbeitszeit. */
    public function testRepeatedClicksAreIdempotent(): void
    {
        $id = $this->createJob()['id'];

        $this->at('2026-09-28 09:00');
        $this->api('POST', "/api/jobs/$id/start", $this->worker);
        $this->at('+1 second');
        $this->api('POST', "/api/jobs/$id/start", $this->worker);
        $this->api('POST', "/api/jobs/$id/start", $this->worker);
        self::assertCount(1, $this->reload($id)->getTimeEntries());

        $this->at('2026-09-28 10:00');
        $this->api('POST', "/api/jobs/$id/pause", $this->worker);
        $this->at('2026-09-28 10:05');
        $again = $this->api('POST', "/api/jobs/$id/pause", $this->worker);
        self::assertSame(60, $again['actualMinutes']);

        $this->api('POST', "/api/jobs/$id/complete", $this->worker);
        $second = $this->api('POST', "/api/jobs/$id/complete", $this->worker, expected: 409);
        self::assertStringContainsString('bereits abgeschlossen', $second['title']);

        // Abgeschlossene Arbeit kann nicht weiterbearbeitet werden.
        $this->api('POST', "/api/jobs/$id/start", $this->worker, expected: 409);

        $stored = $this->reload($id);
        self::assertCount(1, $stored->getTimeEntries());
        self::assertSame(3600, $stored->getActualSeconds());
    }

    /** Abschliessen ohne Start erzeugt keine kuenstliche Arbeitszeit. */
    public function testCompletingUnstartedJobCreatesNoTime(): void
    {
        $id = $this->createJob(['plannedMinutes' => 120])['id'];

        $done = $this->api('POST', "/api/jobs/$id/complete", $this->worker);

        self::assertSame('erledigt', $done['status']);
        self::assertSame(0, $done['actualSeconds']);
        self::assertCount(0, $this->reload($id)->getTimeEntries());
    }

    /** Wer eine andere Arbeit startet, pausiert die bisherige automatisch. */
    public function testStartingAnotherJobPausesThePreviousOne(): void
    {
        $first = $this->createJob(['title' => 'A'])['id'];
        $second = $this->createJob(['title' => 'B'])['id'];

        $this->at('2026-09-28 09:00');
        $this->api('POST', "/api/jobs/$first/start", $this->worker);
        $this->at('2026-09-28 09:45');
        $this->api('POST', "/api/jobs/$second/start", $this->worker);

        $a = $this->api('GET', "/api/jobs/$first", $this->worker);
        self::assertTrue($a['paused']);
        self::assertSame(45, $a['actualMinutes']);
        self::assertSame(1, $this->em->getRepository(TimeEntry::class)->count(['endedAt' => null]));
    }

    /** Vergessen zu pausieren: ueber Nacht entsteht keine Arbeitszeit. */
    public function testForgottenTimerDoesNotCountOvernight(): void
    {
        $id = $this->createJob()['id'];

        $this->at('2026-09-28 15:00');
        $this->api('POST', "/api/jobs/$id/start", $this->worker);

        $this->at('2026-09-29 07:00');
        $next = $this->api('GET', "/api/jobs/$id", $this->worker);
        self::assertSame(5 * 60, $next['actualMinutes'], 'gekappt um 20:00');

        // Der naechste Start beendet den vergessenen Abschnitt sauber und beginnt einen neuen.
        $this->api('POST', "/api/jobs/$id/start", $this->worker);
        $detail = $this->api('GET', "/api/jobs/$id", $this->worker);
        self::assertCount(2, $detail['timeEntries']);
        self::assertTrue($detail['timeEntries'][0]['autoClosed']);
        self::assertSame(5 * 3600, $detail['timeEntries'][0]['durationSeconds']);
    }

    /** Berechtigungen: nur der zugeteilte Arbeiter startet; fremde Arbeiten sind tabu. */
    public function testOnlyTheAssigneeCanStart(): void
    {
        $id = $this->createJob()['id'];

        $this->api('POST', "/api/jobs/$id/start", $this->otherWorker, expected: 403);
        $this->api('POST', "/api/jobs/$id/complete", $this->otherWorker, expected: 403);
        $this->api('POST', "/api/jobs/$id/start", $this->chef, expected: 409);
        $this->api('POST', '/api/jobs', $this->worker, ['title' => 'x'], 403);
    }

    /** 10 + 11: Frueher fertig – Folgearbeiten ruecken nach Bestaetigung nach vorne. */
    public function testEarlyCompletionProposesAndAppliesRescheduling(): void
    {
        $first = $this->createJob(['title' => 'Service', 'startsAt' => '2026-09-28T07:00:00+02:00', 'plannedMinutes' => 120])['id'];
        $second = $this->createJob(['title' => 'Reifen', 'startsAt' => '2026-09-28T09:00:00+02:00', 'plannedMinutes' => 60])['id'];
        $third = $this->createJob(['title' => 'Ölwechsel', 'startsAt' => '2026-09-28T10:00:00+02:00', 'plannedMinutes' => 60])['id'];
        // Geplante Luecke: Kunde kommt erst um 13:00 – bleibt stehen.
        $later = $this->createJob(['title' => 'Kundentermin', 'startsAt' => '2026-09-28T13:00:00+02:00', 'plannedMinutes' => 60])['id'];

        $this->at('2026-09-28 07:00');
        $this->api('POST', "/api/jobs/$first/start", $this->worker);
        $this->at('2026-09-28 08:10');
        $this->api('POST', "/api/jobs/$first/complete", $this->worker);

        $preview = $this->api('GET', "/api/jobs/$first/follow-up", $this->chef);
        self::assertSame('earlier', $preview['direction']);
        self::assertSame(-45, $preview['shiftMinutes']);
        self::assertSame([$second, $third], array_column($preview['moves'], 'jobId'));
        self::assertSame('2026-09-28T08:15:00+02:00', $preview['moves'][0]['to']['startsAt']);
        self::assertSame('2026-09-28T09:15:00+02:00', $preview['moves'][1]['to']['startsAt']);

        // Ein Arbeiter darf die Planung nicht selbst aendern.
        $this->api('POST', "/api/jobs/$first/follow-up", $this->worker, ['jobIds' => [$second, $third]], 403);

        $result = $this->api('POST', "/api/jobs/$first/follow-up", $this->chef, ['jobIds' => [$second, $third]]);
        self::assertCount(2, $result['moved']);

        self::assertSame('08:15', $this->reload($second)->getStartsAt()?->format('H:i'));
        self::assertSame('10:15', $this->reload($third)->getEndsAt()?->format('H:i'));
        self::assertSame('13:00', $this->reload($later)->getStartsAt()?->format('H:i'));

        // Ist-Zeit der abgeschlossenen Arbeit bleibt unveraendert, ihr Termin zeigt das echte Ende.
        $done = $this->reload($first);
        self::assertSame(70 * 60, $done->getActualSeconds());
        self::assertSame('08:15', $done->getEndsAt()?->format('H:i'));

        // Danach gibt es keinen Vorschlag mehr.
        $this->api('GET', "/api/jobs/$first/follow-up", $this->chef, expected: 204);
    }

    public function testStartedFollowersAreNeverMoved(): void
    {
        $first = $this->createJob(['startsAt' => '2026-09-28T07:00:00+02:00', 'plannedMinutes' => 120])['id'];
        $second = $this->createJob(['title' => 'Schon begonnen', 'startsAt' => '2026-09-28T09:00:00+02:00', 'plannedMinutes' => 60])['id'];

        $this->at('2026-09-28 07:00');
        $this->api('POST', "/api/jobs/$second/start", $this->worker);
        $this->api('POST', "/api/jobs/$second/pause", $this->worker);
        $this->api('POST', "/api/jobs/$first/start", $this->worker);
        $this->at('2026-09-28 08:00');
        $this->api('POST', "/api/jobs/$first/complete", $this->worker);

        $preview = $this->api('GET', "/api/jobs/$first/follow-up", $this->chef);
        self::assertSame([], $preview['moves']);
        self::assertStringContainsString('bereits begonnen', $preview['warnings'][0]);
    }

    /** 12 + 13: Kalenderaenderungen werden gespeichert, Konflikte erkannt. */
    public function testCalendarChangesPersistAndConflictsAreReported(): void
    {
        $a = $this->createJob(['title' => 'A', 'startsAt' => '2026-09-28T09:00:00+02:00', 'plannedMinutes' => 120])['id'];
        $b = $this->createJob(['title' => 'B', 'startsAt' => '2026-09-28T13:00:00+02:00', 'plannedMinutes' => 60])['id'];

        // Per Drag & Drop auf 10:00 gezogen -> ueberschneidet sich mit A.
        $this->api('PUT', "/api/jobs/$b", $this->chef, [
            'title' => 'B',
            'startsAt' => '2026-09-28T10:00:00+02:00',
            'endsAt' => '2026-09-28T11:00:00+02:00',
            'plannedMinutes' => 60,
            'assigneeId' => $this->worker->getId(),
        ]);
        self::assertSame('10:00', $this->reload($b)->getStartsAt()?->format('H:i'));

        $overview = $this->api('GET', '/api/overview', $this->chef);
        self::assertCount(1, $overview['conflicts']);
        self::assertEqualsCanonicalizing([$a, $b], array_column($overview['conflicts'][0]['jobs'], 'id'));
    }

    /** Begonnene und abgeschlossene Arbeiten werden nicht versehentlich verschoben. */
    public function testStartedAndDoneJobsAreProtected(): void
    {
        $id = $this->createJob(['title' => 'A', 'startsAt' => '2026-09-28T09:00:00+02:00', 'plannedMinutes' => 60])['id'];
        $move = [
            'title' => 'A',
            'startsAt' => '2026-09-28T11:00:00+02:00',
            'plannedMinutes' => 60,
            'assigneeId' => $this->worker->getId(),
        ];

        $this->at('2026-09-28 09:00');
        $this->api('POST', "/api/jobs/$id/start", $this->worker);
        $this->api('POST', "/api/jobs/$id/pause", $this->worker);
        $this->api('PUT', "/api/jobs/$id", $this->chef, $move, 409);
        $this->api('PUT', "/api/jobs/$id", $this->chef, $move + ['confirmStartedChange' => true]);

        $this->api('POST', "/api/jobs/$id/complete", $this->worker);
        $this->api('PUT', "/api/jobs/$id", $this->chef, ['startsAt' => '2026-09-28T12:00:00+02:00'] + $move + ['confirmStartedChange' => true], 409);

        // Titel aendern ohne Terminaenderung bleibt moeglich.
        $this->api('PUT', "/api/jobs/$id", $this->chef, ['title' => 'A (Kunde informiert)'] + $move);
        self::assertSame('A (Kunde informiert)', $this->reload($id)->getTitle());
    }

    /** Eine Aufgabe nachtraeglich einplanen und wieder aus dem Kalender nehmen. */
    public function testScheduleAndUnscheduleTask(): void
    {
        $id = $this->createJob()['id'];

        $this->api('PUT', "/api/jobs/$id", $this->chef, [
            'title' => 'Bremsen hinten',
            'startsAt' => '2026-09-30T07:00:00+02:00',
            'endsAt' => '2026-09-30T09:00:00+02:00',
            'assigneeId' => $this->worker->getId(),
        ]);
        self::assertTrue($this->reload($id)->isScheduled());
        self::assertNull($this->reload($id)->getPlannedMinutes(), 'Kalenderdauer ist nicht automatisch Arbeitszeit');

        $this->api('PUT', "/api/jobs/$id", $this->chef, ['title' => 'Bremsen hinten', 'assigneeId' => $this->worker->getId()]);
        self::assertFalse($this->reload($id)->isScheduled());
    }

    public function testInvalidScheduleIsRejected(): void
    {
        $error = $this->api('POST', '/api/jobs', $this->chef, ['title' => 'X', 'startsAt' => '2026-09-30T07:00:00+02:00'], 422);
        self::assertStringContainsString('Ende oder die geplante Arbeitszeit', $error['title'].json_encode($error['errors'] ?? []));

        $this->api('POST', '/api/jobs', $this->chef, [
            'title' => 'X',
            'startsAt' => '2026-09-30T09:00:00+02:00',
            'endsAt' => '2026-09-30T08:00:00+02:00',
        ], 422);
    }

    /** "Meine Arbeiten" enthaelt auch Aufgaben ohne Termin. */
    public function testMyJobsIncludeUnscheduledTasks(): void
    {
        $task = $this->createJob(['title' => 'Ohne Termin'])['id'];
        $foreign = $this->createJob(['title' => 'Fremd', 'assigneeId' => $this->otherWorker->getId()])['id'];

        $mine = array_column($this->api('GET', '/api/jobs/mine', $this->worker), 'id');

        self::assertContains($task, $mine);
        self::assertNotContains($foreign, $mine);
        self::assertSame([$task], array_column($this->api('GET', '/api/jobs/board', $this->worker), 'id'));
    }

    public function testThemePreferenceIsStoredPerUser(): void
    {
        self::assertSame('system', $this->api('GET', '/api/me', $this->worker)['theme']);

        $this->api('PUT', '/api/me/preferences', $this->worker, ['theme' => 'dark']);

        self::assertSame('dark', $this->api('GET', '/api/me', $this->worker)['theme']);
        self::assertSame('system', $this->api('GET', '/api/me', $this->chef)['theme']);
        $this->api('PUT', '/api/me/preferences', $this->worker, ['theme' => 'pink'], 422);
    }
}
