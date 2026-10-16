# Werkstatt-Planung – Ganglbauer Landtechnik

Arbeitseinteilung und Terminplanung für die Werkstätte (siehe [Angabe.md](Angabe.md)).
Das Aussehen folgt dem Figma-Entwurf „Werkstatt-Planungs-App“.

| Teil      | Technik                                         | Ordner      |
| --------- | ----------------------------------------------- | ----------- |
| Backend   | Symfony 7.4, PHP 8.4, Doctrine, JWT-Login       | `backend/`  |
| Frontend  | Vue 3, TypeScript, Vite, Pinia                  | `frontend/` |
| Datenbank | MariaDB 11.4                                    | Docker      |
| Webserver | nginx: liefert die App aus, `/api` → PHP-FPM    | Docker      |

## Rollen

| Rolle       | Planung | Eigene Arbeiten | Zeit ändern | Als Lehrling einteilbar | Abwesenheiten | Auswertung | Benutzer |
| ----------- | :-----: | :-------------: | :---------: | :---------------------: | :-----------: | :--------: | :------: |
| Chef        |    ✓    |        ✓        |      ✓      |            –            |       ✓       |     ✓      | ✓ (inkl. löschen) |
| Sekretärin  |    ✓    |        ✓        |      ✓      |            –            |       ✓       |     ✓      |    –     |
| Arbeiter    |    –    |        ✓        | ✓ (eigene)  |            –            |       –       |     –      |    –     |
| Lehrling    |    –    |        ✓        | ✓ (eigene)  |            ✓            |       –       |     –      |    –     |

Arbeiter starten, pausieren und schließen ihre eigenen Arbeiten ab; Planung (anlegen, verschieben,
Umplanung anwenden, Abwesenheiten eintragen) bleibt Sekretärin und Chef vorbehalten.

- **Sekretärin** ist die frühere Rolle „Vorarbeiter“ – nur umbenannt, mit denselben Rechten. Die
  Migration `Version20261016090000` stellt bestehende Benutzer um (`vorarbeiter` → `sekretaerin`,
  Security-Rolle `ROLE_VORARBEITER` → `ROLE_SEKRETAERIN`). `app:create-user --role=vorarbeiter`
  wird weiterhin als Sekretärin angenommen.
- **Lehrling** (`lehrling`, `ROLE_LEHRLING`): eigene Aufträge wie ein Arbeiter. Zusätzlich kann er
  einem Auftrag als Unterstützung zugeordnet werden – dann sieht er den Auftrag in Kalender,
  „Meine Arbeiten“ und den Details (`JobVoter::VIEW`), Zeiterfassung und Abschluss bleiben beim
  Zuständigen (`JobVoter::WORK`). Nur Lehrlinge können so zugeordnet werden.

Die Rechte prüft das Backend bei jeder Anfrage (`#[IsGranted]`, `JobVoter`).
Der Vue-Router blendet Ansichten nur aus.

## Am Server installieren (SSH)

Voraussetzung: Docker mit dem Compose-Plugin.

```bash
git clone https://github.com/number1fragger/ganglbauer_zeitmanagment.git
cd ganglbauer_zeitmanagment

cp .env.example .env
# In .env alle Passwörter und Secrets ändern, z. B. mit: openssl rand -hex 24
nano .env

docker compose up -d --build
```

Beim Start legt das Backend die JWT-Schlüssel an und bringt die Datenbank per
Migration auf den aktuellen Stand. Die App läuft danach auf Port `HTTP_PORT`
(Standard 8080). Die Datenbank ist nur vom Server selbst erreichbar.

Ersten Chef anlegen (der Befehl fragt nach dem Passwort):

```bash
docker compose exec backend php bin/console app:create-user p.hofer Peter Hofer --role=chef
```

Alle weiteren Konten legt der Chef in der App unter „Benutzer & Rechte“ an.

- **Update:** `git pull && docker compose up -d --build`
- **Logs:** `docker compose logs -f backend` bzw. `frontend`
- **HTTPS:** einen Reverse-Proxy (z. B. Caddy oder den nginx am Host) vor Port `HTTP_PORT` setzen.

## Lokal entwickeln

Voraussetzung: PHP 8.4 mit `intl` und `pdo_mysql`, Composer, Node 22, Docker.

```bash
./dev.sh db        # MariaDB im Container
./dev.sh install   # composer + npm + JWT-Schlüssel
./dev.sh setup     # Schema + Demodaten
./dev.sh backend   # http://127.0.0.1:8000  (eigenes Terminal)
./dev.sh frontend  # http://localhost:5173  (eigenes Terminal)
```

Demo-Konten mit dem Passwort `werkstatt`: `p.hofer` (Chef), `c.mayr`
(Sekretärin), `a.huber`, `b.steiner` und `d.gruber` (Arbeiter), `l.wimmer` (Lehrling).

Tests: `./dev.sh test`

> Lokal muss PHP in Wiener Zeit laufen (im Docker-Image über `docker/php.ini` gesetzt). Ist in der
> lokalen `php.ini` keine Zeitzone eingetragen, Backend und Demodaten mit
> `php -d date.timezone=Europe/Vienna …` starten.

## Ansichten

| Ansicht        | Wer          | Inhalt                                                                 |
| -------------- | ------------ | ---------------------------------------------------------------------- |
| Dashboard      | Sekretärin+ | Wer arbeitet gerade woran, heute geplant, Hinweise (Konflikte, Umplanung, Anfragen) |
| Kalender       | alle¹        | Links die To-do-Liste (Aufträge ohne Termin), rechts Tag/Woche/Monat. Drag & Drop zum Einplanen, mobil als Liste |
| Aufgaben       | alle¹        | Kanban (Offen / In Bearbeitung / Abgeschlossen), Suche und Filter      |
| Meine Arbeiten | Arbeiter, Lehrling, Sekretärin | Starten, Pausieren, Fortsetzen, Abschließen, „Brauche Arbeit“ |
| Auswertung     | Sekretärin+ | Ist-Zeit je Arbeiter, je Tag und aufwändigste Aufträge                 |

Hell/Dunkel/System lässt sich unten in der Seitenleiste (mobil oben) umschalten. Die Wahl
wird im Browser und im Benutzerkonto gespeichert.

## Arbeitszeiten

| Tag        | Arbeitszeit   | Mittagspause  |
| ---------- | ------------- | ------------- |
| Mo – Do    | 07:30 – 17:00 | 12:00 – 13:00 |
| Fr         | 07:30 – 14:00 | 12:00 – 13:00 |
| Sa, So     | arbeitsfrei   |               |

Die Zeiten stehen **nur** in `backend/src/Util/WorkingHours.php`. Das Frontend lädt sie nach der
Anmeldung über `GET /api/working-hours` (Kalenderraster, Schattierung, Dauer-Berechnung) – es gibt
keine zweite Kopie. Der Kalender schattiert Mittagspause und Zeiten außerhalb der Arbeitszeit.

Termine dürfen bewusst außerhalb der Arbeitszeit liegen – es wird **gewarnt, nicht verhindert oder
verschoben**: Liegt Beginn oder Ende in der Mittagspause, vor 07:30, nach Arbeitsschluss oder am
Wochenende, zeigen Dialog (vor dem Speichern), Rückmeldung nach dem Speichern und ein Uhr-Symbol am
Kalendereintrag einen Hinweis. Ein Termin, der *über* die Mittagspause oder über Nacht läuft, ist in
Ordnung – diese Zeit zählt nur nicht zur Dauer.

## Aufgaben, Termine und Ist-Zeit

Ein Auftrag hat zwei voneinander unabhängige Zeitangaben:

| Angabe                | Feld(er)                 | Pflicht | Bedeutung                                              |
| --------------------- | ------------------------ | :-----: | ------------------------------------------------------ |
| Termin im Kalender    | `startsAt`, `endsAt`     |  nein   | Wann der Auftrag eingeplant ist – darf mehrere Tage umfassen |
| Dauer                 | `workMinutes` (berechnet) |    –    | Arbeitszeit innerhalb des Termins (siehe unten)        |
| Ist-Zeit              | Summe der `time_entry`   |    –    | Wie lange tatsächlich gearbeitet wurde                  |

### Dauer, Beginn und Ende

Die **Dauer** ist die Arbeitszeit innerhalb des Termins: Minuten, die in die Arbeitszeit fallen –
Mittagspause, Abende, Nächte und Wochenenden zählen nicht. Sie wird nicht gespeichert, sondern aus
Beginn und Ende berechnet; Dauer und Termin können daher nie auseinanderlaufen.

- **Dauer ändern** → Ende = frühester Zeitpunkt ab Beginn, an dem so viel Arbeitszeit erreicht ist.
  Beispiel: Mo 10:00 + 3 h → 14:00 (10–12 und 13–14). Mo 15:00 + 4 h → Di 09:30.
  Fr 13:00 + 3 h → Mo 09:30.
- **Beginn ändern** (auch Verschieben im Kalender) → die Dauer bleibt, das Ende rückt nach.
- **Ende ändern** (auch Ziehen am unteren Rand) → die Dauer wird neu berechnet.
- Beginnt ein Termin bewusst **außerhalb** der Arbeitszeit (z. B. Samstag 09:00), zählt die Uhrzeit
  (Samstag 09:00 + 2 h = 11:00) – sonst würde er unbemerkt auf Montag springen. Dazu gibt es eine
  Warnung.
- Die API nimmt beim Anlegen/Einplanen entweder `endsAt` oder `durationMinutes` (Arbeitsminuten).
  Dieselben Regeln rechnen im Frontend (`frontend/src/utils/workTime.ts`) und im Backend
  (`WorkingHours`); beide sind mit denselben Fällen getestet.

### Im Kalender verschieben, verlängern, verkürzen

- **Verschieben:** Termin per Drag & Drop an einen anderen Tag/Zeitpunkt (Tagesansicht: auch in
  eine andere Personen-Spalte) ziehen – die Dauer bleibt.
- **Verlängern/verkürzen:** am oberen bzw. unteren Rand eines Termins ziehen (15-Minuten-Raster).
  Beim Ziehen zeigt der Termin die neue Zeit und Dauer an. Mehrtägige Termine haben die Griffe am
  ersten bzw. letzten Tag; über mehrere Tage hinweg verlängert man im Auftrag.
- Die Anzeige reagiert sofort; gespeichert wird über `PUT /api/jobs/{id}/schedule`. Schlägt das
  Speichern fehl, erscheint die Fehlermeldung und der Kalender lädt den Stand des Servers neu.

### Überschneidungen und Konflikte

Eine Person darf mehreren Aufträgen zur gleichen Zeit zugeteilt sein – nichts wird blockiert oder
automatisch verschoben. Gleichzeitige Termine stehen **nebeneinander** (beide sichtbar und
anklickbar/ziehbar), sind rot umrandet und schraffiert, und eine Hinweisleiste über dem Kalender
nennt, wer wann für welche Aufträge gleichzeitig eingeplant ist. Das gilt auch für Lehrlinge und für
Abwesenheiten. Das Backend liefert dieselben Hinweise bei jedem Speichern (`warnings`), in der
Vorschau `GET /api/jobs/check` und im Dashboard (`/api/overview`: `conflicts`, `absenceConflicts`).

### Lehrling am Auftrag

Im Auftrag gibt es neben „Zuständig“ das Feld **Lehrling** (optional). Die Auswahl enthält nur
Benutzer mit der Rolle Lehrling; das Backend prüft das zusätzlich (`Job::setApprentice`). Zuständige
Person und Lehrling müssen verschieden sein. Der Auftrag erscheint in der Tagesansicht in beiden
Spalten (die Lehrlings-Kopie ist gestrichelt und als „Lehrling“ markiert), in der Woche in beiden
Spuren – beide verweisen auf denselben Auftrag. Eine Lehrlings-Rolle kann nicht geändert werden,
solange der Lehrling noch offenen Aufträgen zugeordnet ist.

### Urlaub und persönliche Termine

Chef und Sekretärin tragen Abwesenheiten über „Abwesenheit“ im Kalender ein (Art, Person,
ganztägig von–bis **oder** Beginn/Ende mit Uhrzeit, optional interne Notiz; gespeichert werden auch
Ersteller und Erstellungszeitpunkt). Arten: Urlaub, Arzttermin, Persönlicher Termin, Berufsschule –
weitere kommen als neuer Fall in `App\Enum\AbsenceType` dazu (die Liste liefert
`GET /api/absences/types`). Im Kalender stehen Abwesenheiten violett gestreift als
„Abwesend: Urlaub“, „Abwesend: Arzttermin“ usw. Die Notiz sehen nur Chef und Sekretärin; andere
sehen nur ihre eigenen Abwesenheiten. Bereits eingeplante Aufträge werden nie verschoben oder
gelöscht – betroffene Aufträge werden beim Speichern gemeldet und im Kalender als Konflikt markiert.

Status (offen / in Bearbeitung / abgeschlossen) und Einplanung sind getrennt: ein offener Auftrag
kann einen Termin haben oder nicht.

### To-do-Liste und Einplanen

- Ein neuer Auftrag braucht nur einen Titel. Ohne Termin steht er in der **To-do-Liste** links neben
  dem Kalender (offene und laufende Aufträge ohne Termin; abgeschlossene nicht).
- **Einplanen:** Auftrag aus der Liste in die Tages- oder Wochenansicht ziehen. Tag und Position auf
  der Zeitachse (15-Minuten-Raster) bestimmen den Beginn; in der Tagesansicht bestimmt die Spalte auch
  den zuständigen Arbeiter. Während des Ziehens zeigt die App Ziel-Tag und -Uhrzeit bzw. „Hier nicht
  möglich“. Gespeichert wird über `PUT /api/jobs/{id}/schedule`.
- Ohne angegebenes Ende bekommt der Kalendereintrag eine rein **technische Standardlänge** von
  60 Minuten (`Job::DEFAULT_SLOT_MINUTES`), damit er im Kalender sichtbar ist. Das ist keine geplante
  Arbeitszeit; das Ende lässt sich im Auftrag ändern (auch mehrtägig).
- Ein Auftrag hat genau einen Termin – erneutes Einplanen verschiebt ihn, es entsteht nie ein zweiter.
- **Zurück in die To-do-Liste:** Termin aus dem Kalender auf die Liste ziehen oder im Auftrag „Aus dem
  Kalender nehmen“ (`DELETE /api/jobs/{id}/schedule`). Erfasste Ist-Zeit bleibt erhalten.
- Einplanen startet keine Zeiterfassung. Begonnene Aufträge lassen sich nicht versehentlich ziehen,
  abgeschlossene gar nicht verschieben.
- Auf Touch-Geräten und ohne Maus: Knopf „Einplanen“ an jedem Eintrag (Tag, Uhrzeit, optional Ende).
- Die Seitenleiste lässt sich einklappen (wird gemerkt); auf Tablet/Handy öffnet sie der Knopf „To-do“
  als Schublade.

### So wird die Ist-Zeit berechnet

- **Starten / Fortsetzen** legt einen Arbeitsabschnitt (`time_entry`) mit `started_at` an und setzt
  den Status auf „in Bearbeitung“. Nur der zugeteilte Arbeiter kann starten.
- **Pausieren** beendet den laufenden Abschnitt (`ended_at`). Die Arbeit bleibt „in Bearbeitung“.
- **Abschließen** beendet einen laufenden Abschnitt und setzt Status „erledigt“ und `completed_at`.
  Wurde nie gestartet, entsteht keine Arbeitszeit.
- **Ist-Zeit = Summe aller Abschnitte** (`ended_at − started_at`, sekundengenau). Pausen, Nächte und
  Wochenenden liegen *zwischen* den Abschnitten und zählen daher nie. Beispiel: Mo 09–12 und
  Di 08–11 ergeben 6 Stunden, nicht 26.
- Pro Arbeiter läuft höchstens ein Abschnitt. Wer eine andere Arbeit startet, pausiert die bisherige.
- **Vergessen zu pausieren?** Ein offener Abschnitt zählt höchstens bis 20:00 Uhr am Starttag
  (mindestens 4 Stunden nach dem Start) und wird dort automatisch beendet (`auto_closed = 1`,
  im Dialog als „auto. beendet“ markiert).
- **Doppelklicks und parallele Anfragen:** Jede Aktion läuft in einer Transaktion mit Zeilensperre
  (`SELECT … FOR UPDATE` auf Arbeiter bzw. Arbeit) und ist idempotent – zweimal „Starten“ legt nur
  einen Abschnitt an, zweimal „Abschließen“ liefert 409. Das Frontend schickt dieselbe Aktion
  zusätzlich nicht doppelt.
- Alle Zeitpunkte werden serverseitig gesetzt (Uhr des Servers, Zeitzone Europe/Vienna) und in der
  Datenbank gespeichert – sie überstehen Neuladen, neue Anmeldung und Server-Neustart.

Vergessene Abschnitte werden ohnehin beim nächsten Klick des Arbeiters geschlossen; optional als
Cronjob: `php bin/console app:time:close-stale`.

### Umplanung bei früherem (oder späterem) Abschluss

Wird eine eingeplante Arbeit früher fertig, schlägt die App vor, die direkt anschließenden, noch nicht
begonnenen Termine desselben Arbeiters nach vorne zu ziehen (Vorschau mit Auswahl, Anwenden nur durch
Sekretärin/Chef). Geplante Lücken (z. B. Kunde kommt erst um 13:00), begonnene oder abgeschlossene
Arbeiten, Tagesgrenzen und Überschneidungen beenden die Kette; Termine nach Arbeitsschluss werden nur
mit Warnung vorgeschlagen. Die Ist-Zeit abgeschlossener Arbeiten wird dabei nie verändert.

## Benutzer endgültig löschen

Der Chef kann Benutzer im Dialog „Benutzer bearbeiten“ endgültig löschen (`DELETE /api/users/{id}`,
nur `ROLE_CHEF`). Vorher zeigt eine Sicherheitsabfrage, was passiert, und verlangt die Eingabe des
Benutzernamens. Löschstrategie (`App\Service\UserDeletion`, eine Transaktion):

- Aufträge bleiben erhalten; die Zuteilung (zuständig/Lehrling) wird entfernt.
- Arbeitsabschnitte bleiben als Ist-Zeit-Nachweis mit dem gespeicherten Namen (`time_entry.user_name`).
- „Brauche Arbeit“-Anfragen bleiben für die Statistik (offene werden zurückgezogen).
- Abwesenheiten der Person werden gelöscht; von ihr angelegte Abwesenheiten anderer bleiben.
- Abgelehnt wird das eigene Konto und, solange die Person gerade Zeit erfasst.

## Statistik der Arbeitsanfragen

Unter „Auswertung“ zeigt der Abschnitt **Arbeitsanfragen** (Woche, Monat oder frei wählbarer
Zeitraum) die Anfragen je Mitarbeiter, die Gesamtzahl, den Anteil und den Vergleich zum
gleich langen Zeitraum davor (`GET /api/reports/arbeitsanfragen`, Chef und Sekretärin).

**Zählregel:** Eine Arbeitsanfrage ist eine über „Brauche Arbeit“ abgeschickte Anfrage – ein
Datensatz in `work_request`, gezählt nach dem Zeitpunkt der Anfrage. Ansehen und Neuladen erzeugen
keinen Datensatz. Ändert ein Mitarbeiter eine noch offene Anfrage, wird derselbe Datensatz angepasst
(vorher entstand ein neuer und der alte wurde zurückgezogen). Zurückgezogene Anfragen zählen nicht –
dadurch zählen auch die älteren Datensätze richtig. Es werden nur gespeicherte Anfragen ausgewertet.

## Datenbank

Migrationen liegen in `backend/migrations/`:

- `Version20261009150000` macht den Termin optional, ergänzt `job.description`,
  `time_entry.auto_closed` (+ Index) und `user.theme`.
- `Version20261010090000` entfernt die geplante Arbeitszeit (`job.planned_minutes`,
  `job.original_planned_minutes`). Termine, Auftragsdaten und Ist-Zeiten bleiben unverändert.
- `Version20261016090000` benennt die Rolle `vorarbeiter` in `sekretaerin` um, ergänzt
  `job.apprentice_id` (Lehrling), die Tabelle `absence` und `user_name` in `time_entry` und
  `work_request` (mit den bestehenden Namen befüllt). Diese beiden Tabellen verweisen beim Löschen
  eines Benutzers künftig auf `NULL` statt mitgelöscht zu werden. Nichts wird gelöscht; `down()`
  bricht ab, falls inzwischen Daten existieren, die das alte Schema nicht speichern kann.

Beim Docker-Start läuft die Migration automatisch, lokal:

```bash
./dev.sh console doctrine:migrations:migrate
```

## Tests

`./dev.sh test` führt PHPUnit (Unit- und API-Tests), die Tests der Zeitberechnung im Frontend
(`npm run test:unit`, Node-Testrunner), den TypeScript-Check und den Linter aus.
Die API-Tests laufen gegen SQLite (`backend/.env.test`, braucht `pdo_sqlite`) und erzeugen ihre
JWT-Schlüssel selbst unter `backend/var/jwt-test/`.

## API (Auszug)

| Methode     | Pfad                                      | Wer             | Zweck                                  |
| ----------- | ----------------------------------------- | --------------- | -------------------------------------- |
| POST        | `/api/login`                              | alle            | Anmelden, liefert ein JWT              |
| GET         | `/api/jobs?from=&to=`                     | alle¹           | Kalender (nur eingeplante Arbeiten)    |
| GET         | `/api/jobs/board?assignee=&priority=&q=&doneDays=` | alle¹  | Aufgabenverwaltung (mit und ohne Termin) |
| GET         | `/api/jobs/mine`                          | alle            | Meine offenen Arbeiten + heute erledigt |
| GET         | `/api/jobs/{id}`                          | Zuständige², Lehrling | Details inkl. Arbeitsabschnitte und Hinweise |
| POST        | `/api/jobs`, PUT/DELETE `/api/jobs/{id}`  | Sekretärin+    | Arbeiten anlegen/planen (nur Titel Pflicht) |
| PUT/DELETE  | `/api/jobs/{id}/schedule`                 | Sekretärin+    | Einplanen/verschieben bzw. aus dem Kalender nehmen |
| POST        | `/api/jobs/{id}/start`                    | zugeteilter Arbeiter | Starten bzw. Fortsetzen           |
| POST        | `/api/jobs/{id}/pause`                    | Zuständige²     | Pausieren                              |
| POST        | `/api/jobs/{id}/complete`, `/reopen`      | Zuständige²     | Abschließen bzw. wieder öffnen         |
| GET/POST    | `/api/jobs/{id}/follow-up`                | Zuständige² / Sekretärin+ | Umplanungsvorschlag ansehen / anwenden |
| POST        | `/api/time/stop`                          | alle            | Eigene laufende Zeiterfassung stoppen  |
| PUT         | `/api/me/preferences`                     | alle            | Farbschema (light/dark/system)         |
| POST        | `/api/work-requests`                      | alle            | „Brauche Arbeit“ ab morgen (F7, F8)    |
| GET         | `/api/overview`                           | Sekretärin+    | Frei ab (laut Kalender), Anfragen, Konflikte, Umplanung |
| GET         | `/api/reports/ist-zeit?from=&to=`         | Sekretärin+    | Auswertung der Ist-Zeit                |
| GET/POST/PUT | `/api/users`                             | Chef            | Benutzer & Rechte                      |
| GET/DELETE  | `/api/users/{id}/deletion-impact`, `/api/users/{id}` | Chef | Auswirkung prüfen / endgültig löschen |
| GET         | `/api/working-hours`                      | alle            | Arbeitszeiten und Pausen (einzige Quelle) |
| GET         | `/api/jobs/check?startsAt=&endsAt=\|durationMinutes=&assigneeId=&apprenticeId=&jobId=` | Sekretärin+ | Vorschau: Ende/Dauer und Hinweise |
| GET         | `/api/absences?from=&to=`, `/api/absences/types` | alle¹ | Abwesenheiten im Kalender, Arten       |
| POST/PUT/DELETE | `/api/absences`, `/api/absences/{id}`  | Sekretärin+     | Urlaub/persönliche Termine verwalten   |
| GET         | `/api/reports/arbeitsanfragen?from=&to=`  | Sekretärin+     | Statistik der Arbeitsanfragen          |

¹ Arbeiter und Lehrlinge sehen nur ihre eigenen Arbeiten (Lehrlinge auch die, bei denen sie als Lehrling eingetragen sind) und ihre eigenen Abwesenheiten.
² Der zugeteilte Arbeiter oder Sekretärin/Chef.
