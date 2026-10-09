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

| Rolle       | Planung | Eigene Arbeiten | Zeit ändern | Auswertung | Benutzer |
| ----------- | :-----: | :-------------: | :---------: | :--------: | :------: |
| Chef        |    ✓    |        ✓        |      ✓      |     ✓      |    ✓     |
| Vorarbeiter |    ✓    |        ✓        |      ✓      |     ✓      |    –     |
| Arbeiter    |    –    |        ✓        | ✓ (eigene)  |     –      |    –     |

Arbeiter starten, pausieren und schließen ihre eigenen Arbeiten ab; Planung (anlegen, verschieben,
Umplanung anwenden) bleibt Vorarbeiter und Chef vorbehalten.

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
(Vorarbeiterin), `a.huber`, `b.steiner` und `d.gruber` (Arbeiter).

Tests: `./dev.sh test`

## Ansichten

| Ansicht        | Wer          | Inhalt                                                                 |
| -------------- | ------------ | ---------------------------------------------------------------------- |
| Dashboard      | Vorarbeiter+ | Wer arbeitet gerade woran, heute geplant, Hinweise (Konflikte, Umplanung, Anfragen) |
| Kalender       | alle¹        | Links die To-do-Liste (Aufträge ohne Termin), rechts Tag/Woche/Monat. Drag & Drop zum Einplanen, mobil als Liste |
| Aufgaben       | alle¹        | Kanban (Offen / In Bearbeitung / Abgeschlossen), Suche und Filter      |
| Meine Arbeiten | Arbeiter, Vorarbeiter | Starten, Pausieren, Fortsetzen, Abschließen, „Brauche Arbeit“ |
| Auswertung     | Vorarbeiter+ | Ist-Zeit je Arbeiter, je Tag und aufwändigste Aufträge                 |

Hell/Dunkel/System lässt sich unten in der Seitenleiste (mobil oben) umschalten. Die Wahl
wird im Browser und im Benutzerkonto gespeichert.

## Aufgaben, Termine und Ist-Zeit

Ein Auftrag hat zwei voneinander unabhängige Zeitangaben – eine geplante Arbeitsdauer gibt es nicht:

| Angabe                | Feld(er)                 | Pflicht | Bedeutung                                              |
| --------------------- | ------------------------ | :-----: | ------------------------------------------------------ |
| Termin im Kalender    | `startsAt`, `endsAt`     |  nein   | Wann der Auftrag eingeplant ist – darf mehrere Tage umfassen |
| Ist-Zeit              | Summe der `time_entry`   |    –    | Wie lange tatsächlich gearbeitet wurde                  |

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
Vorarbeiter/Chef). Geplante Lücken (z. B. Kunde kommt erst um 13:00), begonnene oder abgeschlossene
Arbeiten, Tagesgrenzen und Überschneidungen beenden die Kette; Termine nach Arbeitsschluss werden nur
mit Warnung vorgeschlagen. Die Ist-Zeit abgeschlossener Arbeiten wird dabei nie verändert.

## Datenbank

Migrationen liegen in `backend/migrations/`:

- `Version20261009150000` macht den Termin optional, ergänzt `job.description`,
  `time_entry.auto_closed` (+ Index) und `user.theme`.
- `Version20261010090000` entfernt die geplante Arbeitszeit (`job.planned_minutes`,
  `job.original_planned_minutes`). Termine, Auftragsdaten und Ist-Zeiten bleiben unverändert.

Beim Docker-Start läuft die Migration automatisch, lokal:

```bash
./dev.sh console doctrine:migrations:migrate
```

## Tests

`./dev.sh test` führt PHPUnit (Unit- und API-Tests), den TypeScript-Check und den Linter aus.
Die API-Tests laufen gegen SQLite (`backend/.env.test`, braucht `pdo_sqlite`) und erzeugen ihre
JWT-Schlüssel selbst unter `backend/var/jwt-test/`.

## API (Auszug)

| Methode     | Pfad                                      | Wer             | Zweck                                  |
| ----------- | ----------------------------------------- | --------------- | -------------------------------------- |
| POST        | `/api/login`                              | alle            | Anmelden, liefert ein JWT              |
| GET         | `/api/jobs?from=&to=`                     | alle¹           | Kalender (nur eingeplante Arbeiten)    |
| GET         | `/api/jobs/board?assignee=&priority=&q=&doneDays=` | alle¹  | Aufgabenverwaltung (mit und ohne Termin) |
| GET         | `/api/jobs/mine`                          | alle            | Meine offenen Arbeiten + heute erledigt |
| GET         | `/api/jobs/{id}`                          | Zuständige²     | Details inkl. Arbeitsabschnitte        |
| POST        | `/api/jobs`, PUT/DELETE `/api/jobs/{id}`  | Vorarbeiter+    | Arbeiten anlegen/planen (nur Titel Pflicht) |
| PUT/DELETE  | `/api/jobs/{id}/schedule`                 | Vorarbeiter+    | Einplanen/verschieben bzw. aus dem Kalender nehmen |
| POST        | `/api/jobs/{id}/start`                    | zugeteilter Arbeiter | Starten bzw. Fortsetzen           |
| POST        | `/api/jobs/{id}/pause`                    | Zuständige²     | Pausieren                              |
| POST        | `/api/jobs/{id}/complete`, `/reopen`      | Zuständige²     | Abschließen bzw. wieder öffnen         |
| GET/POST    | `/api/jobs/{id}/follow-up`                | Zuständige² / Vorarbeiter+ | Umplanungsvorschlag ansehen / anwenden |
| POST        | `/api/time/stop`                          | alle            | Eigene laufende Zeiterfassung stoppen  |
| PUT         | `/api/me/preferences`                     | alle            | Farbschema (light/dark/system)         |
| POST        | `/api/work-requests`                      | alle            | „Brauche Arbeit“ ab morgen (F7, F8)    |
| GET         | `/api/overview`                           | Vorarbeiter+    | Frei ab (laut Kalender), Anfragen, Konflikte, Umplanung |
| GET         | `/api/reports/ist-zeit?from=&to=`         | Vorarbeiter+    | Auswertung der Ist-Zeit                |
| GET/POST/PUT | `/api/users`                             | Chef            | Benutzer & Rechte                      |

¹ Arbeiter sehen nur ihre eigenen Arbeiten.
² Der zugeteilte Arbeiter oder Vorarbeiter/Chef.
