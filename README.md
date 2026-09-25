# Ganglbauer Zeitmanagement

Werkstatt-Planung für Ganglbauer Landtechnik: Arbeiten einplanen, Zeiten erfassen
und auf einen Blick sehen, wer wann wieder neue Arbeit braucht.
Die Anforderungen stehen in [Angabe.md](Angabe.md), die ausführliche
**Projektdokumentation** in [docs/Dokumentation.md](docs/Dokumentation.md).

- **Backend:** Symfony 7.4 (PHP 8.4), JWT-Login, Doctrine – Ordner `backend/`
- **Frontend:** Vue 3 (JavaScript), Vue Router, Pinia, Vite – Ordner `frontend/`
- **Datenbank:** MariaDB 11

## Rollen

| | Chef | Vorarbeiter | Arbeiter |
|---|:-:|:-:|:-:|
| Kalender (Tag / Woche / Monat), Drag & Drop | ✓ | ✓ | – |
| Arbeiten anlegen, bearbeiten, zuteilen, löschen | ✓ | ✓ | – |
| Eigene Arbeiten: Zeit erfassen, verlängern, abhaken | ✓ | ✓ | ✓ |
| „Brauche Arbeit“ melden | ✓ | ✓ | ✓ |
| Anfragen als zugeteilt markieren, Auswertung | ✓ | ✓ | – |
| Benutzer & Rechte | ✓ | – | – |

Die Rechte werden im **Backend** geprüft (`security.yaml`, `JobVoter`). Das Frontend
blendet nur die Seiten aus, die eine Rolle ohnehin nicht benutzen darf.

## Entwicklung (Datenbank in Docker, Rest lokal)

Voraussetzungen: PHP 8.4 mit `pdo_mysql`, Composer, Node 22, Docker.

```bash
./dev.sh db         # MariaDB starten (Port 3307)
./dev.sh install    # composer install, JWT-Schlüssel, npm install
./dev.sh setup      # Schema anlegen und Demodaten laden
./dev.sh backend    # API auf http://127.0.0.1:8000
./dev.sh frontend   # App auf http://localhost:5173
./dev.sh test       # PHPUnit und ESLint
```

Demo-Zugänge aus den Fixtures:

| Rolle | E-Mail | Passwort |
|---|---|---|
| Chef | meister@ganglbauer.at | admin1234 |
| Vorarbeiter | vorarbeiter@ganglbauer.at | test1234 |
| Arbeiter | kevin@ganglbauer.at (auch resul@, toni@) | test1234 |

## Kompletter Stack in Docker

Datenbank, Symfony-Backend und Vue-Frontend laufen als eigene Container
(`ganglbauer-db`, `ganglbauer-backend`, `ganglbauer-frontend`):

```bash
cd docker
cp .env.example .env      # einmalig, Passwörter und ersten Chef eintragen
docker compose up -d --build
```

Die App läuft dann unter http://localhost:8080. Der Chef aus `ADMIN_EMAIL` /
`ADMIN_PASSWORD` wird beim ersten Start automatisch angelegt.

Serverbetrieb mit HTTPS, Updates und Datensicherung: [docs/Deployment.md](docs/Deployment.md)

## Wichtige API-Endpunkte

| Pfad | Wer | Zweck |
|---|---|---|
| `POST /api/login` | alle | JWT holen |
| `GET /api/calendar?from=…&to=…` | alle (Arbeiter nur eigene) | Kalenderabschnitte, max. 45 Tage |
| `GET/POST/PATCH/DELETE /api/jobs` | siehe Rollen | Arbeiten |
| `POST /api/work-requests` | alle | „Brauche Arbeit“, frühestens für den nächsten Tag |
| `GET /api/overview` | Chef, Vorarbeiter | Wer ist wann wieder frei |
| `GET /api/workers` | Chef, Vorarbeiter | Aktive Arbeiter zum Zuteilen |
| `GET /api/reports/soll-ist` | Chef, Vorarbeiter | Soll/Ist-Vergleich |
| `/api/users` | Chef | Benutzer & Rechte |
