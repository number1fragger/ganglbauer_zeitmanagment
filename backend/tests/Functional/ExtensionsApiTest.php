<?php

namespace App\Tests\Functional;

use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\WorkRequest;
use App\Enum\UserRole;

/**
 * Arbeitszeiten und Dauer, Verlaengern/Verkuerzen, Ueberschneidungen,
 * Arbeitsanfragen-Statistik, Benutzer loeschen, Rollen Sekretaerin und
 * Lehrling, Lehrling am Auftrag sowie Abwesenheiten.
 *
 * Die Uhr steht auf Mo 2026-09-28 08:00 (Europe/Vienna).
 */
final class ExtensionsApiTest extends ApiTestCase
{
    private User $secretary;
    private User $apprentice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secretary = $this->user('c.mayr', 'Clara', 'Mayr', UserRole::Secretary);
        $this->apprentice = $this->user('l.wimmer', 'Lukas', 'Wimmer', UserRole::Apprentice);
        $this->em->flush();
    }

    // ---- Arbeitszeiten und Dauer -------------------------------------------

    public function testWorkingHoursAreServedFromOneCentralPlace(): void
    {
        $config = $this->api('GET', '/api/working-hours', $this->worker);

        self::assertSame(['start' => '07:30', 'end' => '17:00'], $config['days']['4']);
        self::assertSame(['start' => '07:30', 'end' => '14:00'], $config['days']['5']);
        self::assertNull($config['days']['7']);
        self::assertSame('12:00', $config['breaks'][0]['start']);
    }

    /** Dauer -> Ende (Mittagspause zaehlt nicht), Beginn aendern behaelt die Dauer. */
    public function testDurationDeterminesTheEnd(): void
    {
        $job = $this->createJob(['startsAt' => '2026-09-28T10:00:00+02:00', 'durationMinutes' => 180]);
        self::assertSame('2026-09-28T14:00:00+02:00', $job['endsAt']);
        self::assertSame(180, $job['workMinutes']);
        self::assertSame([], $job['warnings']);

        // Beginn auf 11:00 verschoben, Dauer bleibt 3 h -> 11–12 und 13–15.
        $moved = $this->api('PUT', "/api/jobs/{$job['id']}", $this->chef, [
            'title' => 'Bremsen hinten',
            'assigneeId' => $this->worker->getId(),
            'startsAt' => '2026-09-28T11:00:00+02:00',
            'durationMinutes' => 180,
        ]);
        self::assertSame('2026-09-28T15:00:00+02:00', $moved['endsAt']);
        self::assertSame('15:00', $this->reload($job['id'])->getEndsAt()?->format('H:i'));

        // Ende direkt geaendert -> Dauer wird neu berechnet.
        $changed = $this->api('PUT', "/api/jobs/{$job['id']}", $this->chef, [
            'title' => 'Bremsen hinten',
            'assigneeId' => $this->worker->getId(),
            'startsAt' => '2026-09-28T11:00:00+02:00',
            'endsAt' => '2026-09-28T16:30:00+02:00',
        ]);
        self::assertSame(270, $changed['workMinutes']);

        // Mehrtaegig: Fr 13:00 + 3 h endet Mo 09:30.
        $long = $this->createJob(['startsAt' => '2026-10-02T13:00:00+02:00', 'durationMinutes' => 180]);
        self::assertSame('2026-10-05T09:30:00+02:00', $long['endsAt']);

        // Widerspruechliche Angaben werden abgelehnt.
        $this->api('POST', '/api/jobs', $this->chef, [
            'title' => 'X',
            'startsAt' => '2026-09-28T10:00:00+02:00',
            'endsAt' => '2026-09-28T11:00:00+02:00',
            'durationMinutes' => 60,
        ], 422);
        $this->api('POST', '/api/jobs', $this->chef, ['title' => 'X', 'durationMinutes' => 60], 422);
    }

    /** Planung in der Mittagspause oder nach Arbeitsschluss: gespeichert, aber mit Warnung. */
    public function testPlanningOutsideWorkingHoursWarnsButIsSaved(): void
    {
        $job = $this->createJob(['startsAt' => '2026-09-28T12:15:00+02:00', 'endsAt' => '2026-09-28T13:00:00+02:00']);
        self::assertSame(['arbeitszeit', 'arbeitszeit'], array_column($job['warnings'], 'type'));
        self::assertStringContainsString('Mittagspause', $job['warnings'][0]['message']);
        self::assertSame('12:15', $this->reload($job['id'])->getStartsAt()?->format('H:i'), 'nicht automatisch verschoben');

        $friday = $this->createJob(['startsAt' => '2026-10-02T13:00:00+02:00', 'endsAt' => '2026-10-02T15:00:00+02:00']);
        self::assertStringContainsString('Ende außerhalb der Arbeitszeit', $friday['warnings'][0]['message']);

        $check = $this->api('GET', '/api/jobs/check?startsAt='.urlencode('2026-09-28T11:00:00+02:00').'&durationMinutes=120', $this->chef);
        self::assertSame('2026-09-28T14:00:00+02:00', $check['endsAt']);
        self::assertSame(120, $check['workMinutes']);
        self::assertSame([], $check['warnings']);
    }

    /** Ziehen am unteren/oberen Rand: PUT /schedule mit neuem Ende bzw. Beginn. */
    public function testResizingPersistsAndUpdatesTheDuration(): void
    {
        $id = $this->createJob(['startsAt' => '2026-09-28T08:00:00+02:00', 'minutes' => 120])['id'];

        $longer = $this->api('PUT', "/api/jobs/$id/schedule", $this->chef, [
            'startsAt' => '2026-09-28T08:00:00+02:00',
            'endsAt' => '2026-09-28T11:30:00+02:00',
        ]);
        self::assertSame(210, $longer['workMinutes']);

        $shorter = $this->api('PUT', "/api/jobs/$id/schedule", $this->chef, [
            'startsAt' => '2026-09-28T09:00:00+02:00',
            'endsAt' => '2026-09-28T11:30:00+02:00',
        ]);
        self::assertSame(150, $shorter['workMinutes']);

        $job = $this->reload($id);
        self::assertSame('09:00', $job->getStartsAt()?->format('H:i'));
        self::assertSame('11:30', $job->getEndsAt()?->format('H:i'));
        self::assertSame($this->worker->getId(), $job->getAssignee()?->getId(), 'Zuteilung bleibt');

        // Kuerzer als nichts geht nicht.
        $this->api('PUT', "/api/jobs/$id/schedule", $this->chef, [
            'startsAt' => '2026-09-28T11:30:00+02:00',
            'endsAt' => '2026-09-28T11:30:00+02:00',
        ], 422);
        $this->api('PUT', "/api/jobs/$id/schedule", $this->worker, ['startsAt' => '2026-09-28T09:00:00+02:00'], 403);
    }

    // ---- Ueberschneidungen ---------------------------------------------------

    public function testOverlappingJobsAreSavedAndReported(): void
    {
        $a = $this->createJob(['title' => 'A', 'startsAt' => '2026-09-28T09:00:00+02:00', 'minutes' => 120]);
        $b = $this->createJob(['title' => 'B', 'startsAt' => '2026-09-28T10:00:00+02:00', 'minutes' => 60]);

        self::assertSame('ueberschneidung', $b['warnings'][0]['type']);
        self::assertStringContainsString('Anton Huber ist zur selben Zeit auch für „A“', $b['warnings'][0]['message']);
        self::assertSame($a['id'], $b['warnings'][0]['jobId']);

        // Beide bleiben gespeichert und im Kalender.
        $calendar = $this->api('GET', '/api/jobs?from=2026-09-27T22:00:00Z&to=2026-09-28T22:00:00Z', $this->chef);
        self::assertEqualsCanonicalizing([$a['id'], $b['id']], array_column($calendar, 'id'));
        self::assertCount(1, $this->api('GET', '/api/overview', $this->chef)['conflicts']);
    }

    // ---- Statistik Arbeitsanfragen ------------------------------------------

    public function testWorkRequestsAreCountedOncePerRequest(): void
    {
        $first = $this->api('POST', '/api/work-requests', $this->worker, ['neededAt' => '2026-09-29T07:30:00+02:00'], 201);
        // Datum geaendert und doppelt abgeschickt: bleibt dieselbe Anfrage.
        $changed = $this->api('POST', '/api/work-requests', $this->worker, ['neededAt' => '2026-09-30T07:30:00+02:00']);
        $this->api('POST', '/api/work-requests', $this->worker, ['neededAt' => '2026-09-30T07:30:00+02:00']);
        self::assertSame($first['id'], $changed['id']);
        self::assertSame(1, $this->em->getRepository(WorkRequest::class)->count([]));

        // Ansehen und Neuladen zaehlen nicht.
        $this->api('GET', '/api/work-requests/mine', $this->worker);
        $this->api('GET', '/api/work-requests/mine', $this->worker);

        $this->api('POST', "/api/work-requests/{$first['id']}/fulfil", $this->chef, expected: 204);
        $this->api('POST', '/api/work-requests', $this->worker, ['neededAt' => '2026-10-01T07:30:00+02:00'], 201);

        $withdrawn = $this->api('POST', '/api/work-requests', $this->otherWorker, ['neededAt' => '2026-09-29T07:30:00+02:00'], 201);
        $this->api('POST', "/api/work-requests/{$withdrawn['id']}/withdraw", $this->otherWorker, expected: 204);

        $stats = $this->api('GET', '/api/reports/arbeitsanfragen?from=2026-09-28&to=2026-09-28', $this->secretary);
        self::assertSame(2, $stats['totals']['requests']);
        self::assertSame(1, $stats['totals']['withdrawn']);
        self::assertSame(1, $stats['totals']['fulfilled']);
        self::assertSame(1, $stats['totals']['open']);

        $rows = array_column($stats['perWorker'], null, 'worker');
        self::assertSame(2, $rows['Anton Huber']['requests']);
        self::assertSame(0, $rows['Bernd Steiner']['requests']);
        self::assertSame(1, $rows['Bernd Steiner']['withdrawn']);
        self::assertSame(0, $rows['Lukas Wimmer']['requests'], 'alle Mitarbeiter erscheinen zum Vergleich');
        self::assertEquals(100, $rows['Anton Huber']['share']);

        // Anderer Zeitraum: nichts.
        self::assertSame(0, $this->api('GET', '/api/reports/arbeitsanfragen?from=2026-10-05&to=2026-10-11', $this->chef)['totals']['requests']);

        $this->api('GET', '/api/reports/arbeitsanfragen', $this->worker, expected: 403);
        $this->api('GET', '/api/reports/arbeitsanfragen', $this->apprentice, expected: 403);
    }

    // ---- Benutzer endgueltig loeschen ---------------------------------------

    public function testUserCanBeDeletedWithoutLosingHistory(): void
    {
        $job = $this->createJob(['startsAt' => '2026-09-28T08:00:00+02:00', 'minutes' => 120])['id'];
        $this->api('POST', "/api/jobs/$job/start", $this->worker);
        $this->at('2026-09-28 09:00');

        // Waehrend der Zeiterfassung nicht loeschbar.
        $this->api('DELETE', "/api/users/{$this->worker->getId()}", $this->chef, expected: 409);
        $this->api('POST', "/api/jobs/$job/pause", $this->worker);
        $this->api('POST', '/api/work-requests', $this->worker, ['neededAt' => '2026-09-29T07:30:00+02:00'], 201);

        $impact = $this->api('GET', "/api/users/{$this->worker->getId()}/deletion-impact", $this->chef);
        self::assertSame(1, $impact['openJobs']);
        self::assertSame(1, $impact['timeEntries']);
        self::assertFalse($impact['running']);

        // Nur der Chef – nicht die Sekretaerin, nicht der Betroffene, nicht sich selbst.
        $this->api('DELETE', "/api/users/{$this->worker->getId()}", $this->secretary, expected: 403);
        $this->api('DELETE', "/api/users/{$this->worker->getId()}", $this->worker, expected: 403);
        $this->api('DELETE', "/api/users/{$this->chef->getId()}", $this->chef, expected: 409);

        $workerId = $this->worker->getId();
        $this->api('DELETE', "/api/users/$workerId", $this->chef, expected: 204);

        $this->em->clear();
        self::assertNull($this->em->find(User::class, $workerId));
        $remaining = $this->reload($job);
        self::assertNull($remaining->getAssignee(), 'Auftrag bleibt, nur nicht mehr zugeteilt');
        self::assertSame(3600, $remaining->getActualSeconds(), 'Ist-Zeit bleibt erhalten');
        $entry = $this->em->getRepository(TimeEntry::class)->findAll()[0];
        self::assertNull($entry->getUser());
        self::assertSame('Anton Huber', $entry->getUserName());

        $report = $this->api('GET', '/api/reports/ist-zeit?from=2026-09-28&to=2026-09-28', $this->chef);
        self::assertSame('Anton Huber', $report['perWorker'][0]['worker']);
        self::assertNull($report['perWorker'][0]['workerId']);

        $stats = $this->api('GET', '/api/reports/arbeitsanfragen?from=2026-09-28&to=2026-09-28', $this->chef);
        self::assertSame(0, $stats['totals']['requests'], 'offene Anfrage wurde zurueckgezogen');
        self::assertSame(1, $stats['totals']['withdrawn']);

        $users = array_column($this->api('GET', '/api/users', $this->chef), 'username');
        self::assertNotContains('a.huber', $users);
        $this->api('GET', "/api/jobs/$job", $this->chef);
    }

    // ---- Rollen ---------------------------------------------------------------

    public function testSecretaryHasThePlanningRightsOfTheFormerForeman(): void
    {
        $this->api('POST', '/api/jobs', $this->secretary, ['title' => 'Ölwechsel'], 201);
        $this->api('GET', '/api/overview', $this->secretary);
        $this->api('GET', '/api/reports/ist-zeit', $this->secretary);
        $this->api('GET', '/api/users', $this->secretary, expected: 403);
        self::assertSame('sekretaerin', $this->api('GET', '/api/me', $this->secretary)['role']);

        // Rollenwerte: "vorarbeiter" gibt es nicht mehr, "lehrling" ist waehlbar.
        $this->api('POST', '/api/users', $this->chef, ['username' => 'x.alt', 'firstName' => 'X', 'lastName' => 'Alt', 'role' => 'vorarbeiter', 'password' => 'geheim123'], 422);
        $created = $this->api('POST', '/api/users', $this->chef, ['username' => 'm.neu', 'firstName' => 'Max', 'lastName' => 'Neu', 'role' => 'lehrling', 'password' => 'geheim123'], 201);
        self::assertSame('lehrling', $created['role']);
    }

    public function testApprenticeRights(): void
    {
        $this->api('POST', '/api/jobs', $this->apprentice, ['title' => 'Nicht erlaubt'], 403);
        $this->api('GET', '/api/overview', $this->apprentice, expected: 403);
        $this->api('GET', '/api/reports/ist-zeit', $this->apprentice, expected: 403);

        $helping = $this->createJob(['title' => 'Mit Lehrling', 'startsAt' => '2026-09-28T09:00:00+02:00', 'minutes' => 60, 'apprenticeId' => $this->apprentice->getId()])['id'];
        $foreign = $this->createJob(['title' => 'Ohne Lehrling', 'startsAt' => '2026-09-28T09:00:00+02:00', 'minutes' => 60, 'assigneeId' => $this->otherWorker->getId()])['id'];

        // Sieht den Auftrag im Kalender, in "Meine Arbeiten" und in den Details ...
        $calendar = $this->api('GET', '/api/jobs?from=2026-09-27T22:00:00Z&to=2026-09-28T22:00:00Z', $this->apprentice);
        self::assertSame([$helping], array_column($calendar, 'id'));
        self::assertSame([$helping], array_column($this->api('GET', '/api/jobs/mine', $this->apprentice), 'id'));
        self::assertSame('Lukas Wimmer', $this->api('GET', "/api/jobs/$helping", $this->apprentice)['apprentice']['fullName']);
        $this->api('GET', "/api/jobs/$foreign", $this->apprentice, expected: 403);

        // ... aber die Zeiterfassung bleibt beim Zustaendigen.
        $this->api('POST', "/api/jobs/$helping/start", $this->apprentice, expected: 403);
        $this->api('POST', "/api/jobs/$helping/complete", $this->apprentice, expected: 403);
        $this->api('POST', "/api/jobs/$helping/start", $this->worker);
    }

    // ---- Lehrling am Auftrag -----------------------------------------------------

    public function testOnlyApprenticesCanBeAddedToAJob(): void
    {
        $job = $this->createJob(['title' => 'Service']);
        self::assertNull($job['apprentice']);

        // Ein Arbeiter ist kein Lehrling.
        $error = $this->api('PUT', "/api/jobs/{$job['id']}", $this->chef, ['title' => 'Service', 'assigneeId' => $this->worker->getId(), 'apprenticeId' => $this->otherWorker->getId()], 422);
        self::assertStringContainsString('nicht die Rolle „Lehrling“', $error['title']);

        // Lehrling zuordnen – die Zustaendigkeit bleibt.
        $updated = $this->api('PUT', "/api/jobs/{$job['id']}", $this->chef, ['title' => 'Service', 'assigneeId' => $this->worker->getId(), 'apprenticeId' => $this->apprentice->getId()]);
        self::assertSame($this->apprentice->getId(), $updated['apprentice']['id']);
        self::assertSame('lehrling', $updated['apprentice']['role']);
        self::assertSame($this->worker->getId(), $updated['assignee']['id']);
        self::assertSame($this->apprentice->getId(), $this->reload($job['id'])->getApprentice()?->getId());

        // Einplanen per Drag & Drop laesst den Lehrling unveraendert.
        $this->api('PUT', "/api/jobs/{$job['id']}/schedule", $this->chef, ['startsAt' => '2026-09-29T08:00:00+02:00', 'assigneeId' => $this->otherWorker->getId(), 'changeAssignee' => true]);
        self::assertSame($this->apprentice->getId(), $this->reload($job['id'])->getApprentice()?->getId());

        // Lehrling als Zustaendiger und zugleich als Lehrling geht nicht.
        $this->api('PUT', "/api/jobs/{$job['id']}", $this->chef, ['title' => 'Service', 'assigneeId' => $this->apprentice->getId(), 'apprenticeId' => $this->apprentice->getId()], 422);

        // Rolle eines eingeteilten Lehrlings kann nicht einfach geaendert werden.
        $this->api('PUT', "/api/users/{$this->apprentice->getId()}", $this->chef, ['firstName' => 'Lukas', 'lastName' => 'Wimmer', 'role' => 'arbeiter'], 422);

        // Entfernen.
        $removed = $this->api('PUT', "/api/jobs/{$job['id']}", $this->chef, ['title' => 'Service', 'assigneeId' => $this->worker->getId(), 'apprenticeId' => null]);
        self::assertNull($removed['apprentice']);
        self::assertNull($this->reload($job['id'])->getApprentice());
        $this->api('PUT', "/api/users/{$this->apprentice->getId()}", $this->chef, ['firstName' => 'Lukas', 'lastName' => 'Wimmer', 'role' => 'arbeiter']);
    }

    public function testApprenticeOverlapIsReportedButAllowed(): void
    {
        $this->createJob(['title' => 'A', 'startsAt' => '2026-09-28T09:00:00+02:00', 'minutes' => 120, 'apprenticeId' => $this->apprentice->getId()]);
        $b = $this->createJob(['title' => 'B', 'startsAt' => '2026-09-28T10:00:00+02:00', 'minutes' => 60, 'assigneeId' => $this->otherWorker->getId(), 'apprenticeId' => $this->apprentice->getId()]);

        self::assertSame($this->apprentice->getId(), $b['apprentice']['id']);
        self::assertSame('ueberschneidung', $b['warnings'][0]['type']);
        self::assertStringContainsString('Lukas Wimmer', $b['warnings'][0]['message']);

        $conflicts = $this->api('GET', '/api/overview', $this->chef)['conflicts'];
        self::assertSame(['L. Wimmer'], array_column($conflicts, 'worker'));
    }

    // ---- Abwesenheiten -----------------------------------------------------------

    public function testAbsencesAreManagedByAuthorisedUsersOnly(): void
    {
        $vacation = [
            'userId' => $this->worker->getId(),
            'type' => 'urlaub',
            'allDay' => true,
            'startDate' => '2026-09-30',
            'endDate' => '2026-10-02',
            'note' => 'Hochzeit',
        ];

        $this->api('POST', '/api/absences', $this->worker, $vacation, 403);
        $this->api('POST', '/api/absences', $this->apprentice, $vacation, 403);

        $created = $this->api('POST', '/api/absences', $this->secretary, $vacation, 201);
        self::assertSame('2026-09-30T00:00:00+02:00', $created['startsAt']);
        self::assertSame('2026-10-03T00:00:00+02:00', $created['endsAt'], 'Ende exklusiv nach dem letzten Tag');
        self::assertSame('2026-10-02', $created['lastDay']);
        self::assertSame('Urlaub', $created['label']);
        self::assertSame('Clara Mayr', $created['createdBy']['fullName']);
        self::assertNotEmpty($created['createdAt']);

        // Mehrtaegig im Kalender sichtbar (Do liegt mittendrin).
        $thursday = $this->api('GET', '/api/absences?from=2026-09-30T22:00:00Z&to=2026-10-01T22:00:00Z', $this->chef);
        self::assertSame([$created['id']], array_column($thursday, 'id'));
        self::assertSame('Hochzeit', $thursday[0]['note']);

        // Der Mitarbeiter sieht seine eigene Abwesenheit – ohne interne Notiz – und keine fremden.
        $own = $this->api('GET', '/api/absences?from=2026-09-28T00:00:00Z&to=2026-10-05T00:00:00Z', $this->worker);
        self::assertSame([$created['id']], array_column($own, 'id'));
        self::assertArrayNotHasKey('note', $own[0]);
        self::assertSame([], $this->api('GET', '/api/absences?from=2026-09-28T00:00:00Z&to=2026-10-05T00:00:00Z', $this->otherWorker));

        // Arzttermin stundenweise, danach geaendert und geloescht.
        $doctor = $this->api('POST', '/api/absences', $this->chef, [
            'userId' => $this->otherWorker->getId(),
            'type' => 'arzttermin',
            'allDay' => false,
            'startsAt' => '2026-09-29T09:00:00+02:00',
            'endsAt' => '2026-09-29T10:30:00+02:00',
        ], 201);
        self::assertFalse($doctor['allDay']);
        $this->api('PUT', "/api/absences/{$doctor['id']}", $this->worker, ['userId' => $this->otherWorker->getId(), 'type' => 'arzttermin', 'allDay' => false, 'startsAt' => '2026-09-29T09:00:00+02:00', 'endsAt' => '2026-09-29T11:00:00+02:00'], 403);
        $changed = $this->api('PUT', "/api/absences/{$doctor['id']}", $this->secretary, ['userId' => $this->otherWorker->getId(), 'type' => 'persoenlich', 'allDay' => false, 'startsAt' => '2026-09-29T09:00:00+02:00', 'endsAt' => '2026-09-29T11:00:00+02:00']);
        self::assertSame('Persönlicher Termin', $changed['label']);
        self::assertNotNull($changed['updatedAt']);
        $this->api('DELETE', "/api/absences/{$doctor['id']}", $this->worker, expected: 403);
        $this->api('DELETE', "/api/absences/{$doctor['id']}", $this->secretary, expected: 204);

        // Ungueltige Eingaben.
        $this->api('POST', '/api/absences', $this->chef, ['userId' => $this->worker->getId(), 'type' => 'urlaub', 'allDay' => true, 'startDate' => '2026-10-05', 'endDate' => '2026-10-01'], 422);
        $this->api('POST', '/api/absences', $this->chef, ['userId' => $this->worker->getId(), 'type' => 'kur', 'allDay' => true, 'startDate' => '2026-10-05', 'endDate' => '2026-10-06'], 422);
        self::assertContains('berufsschule', array_column($this->api('GET', '/api/absences/types', $this->worker), 'value'));
    }

    public function testConflictsBetweenJobsAndAbsencesAreVisible(): void
    {
        // Ein Auftrag steht schon im Kalender, dann wird Urlaub eingetragen.
        $job = $this->createJob(['title' => 'Inspektion', 'startsAt' => '2026-10-01T08:00:00+02:00', 'minutes' => 120, 'apprenticeId' => $this->apprentice->getId()]);
        self::assertSame([], $job['warnings']);

        $absence = $this->api('POST', '/api/absences', $this->chef, [
            'userId' => $this->worker->getId(),
            'type' => 'urlaub',
            'allDay' => true,
            'startDate' => '2026-09-30',
            'endDate' => '2026-10-02',
        ], 201);
        self::assertSame([$job['id']], array_column($absence['conflicts'], 'id'));

        // Der Auftrag wurde nicht verschoben oder geloescht.
        self::assertSame('2026-10-01 08:00', $this->reload($job['id'])->getStartsAt()?->format('Y-m-d H:i'));

        $details = $this->api('GET', "/api/jobs/{$job['id']}", $this->chef);
        self::assertSame('abwesenheit', $details['warnings'][0]['type']);
        self::assertStringContainsString('Anton Huber ist abwesend: Urlaub (30.09.–02.10.)', $details['warnings'][0]['message']);

        $overview = $this->api('GET', '/api/overview', $this->chef);
        self::assertSame($job['id'], $overview['absenceConflicts'][0]['job']['id']);
        self::assertSame('Urlaub', $overview['absenceConflicts'][0]['absence']['label']);

        // Neuer Auftrag waehrend eines Arzttermins des Lehrlings -> Warnung.
        $this->api('POST', '/api/absences', $this->chef, [
            'userId' => $this->apprentice->getId(),
            'type' => 'arzttermin',
            'allDay' => false,
            'startsAt' => '2026-10-05T09:00:00+02:00',
            'endsAt' => '2026-10-05T10:00:00+02:00',
        ], 201);
        $other = $this->createJob(['title' => 'Service', 'assigneeId' => $this->otherWorker->getId(), 'apprenticeId' => $this->apprentice->getId(), 'startsAt' => '2026-10-05T08:00:00+02:00', 'minutes' => 120]);
        self::assertSame(['abwesenheit'], array_column($other['warnings'], 'type'));
        self::assertStringContainsString('Lukas Wimmer ist abwesend: Arzttermin', $other['warnings'][0]['message']);
    }
}
