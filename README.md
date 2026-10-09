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

## API (Auszug)

| Methode     | Pfad                                      | Wer             | Zweck                                  |
| ----------- | ----------------------------------------- | --------------- | -------------------------------------- |
| POST        | `/api/login`                              | alle            | Anmelden, liefert ein JWT              |
| GET         | `/api/jobs?from=&to=`                     | alle¹           | Kalender                               |
| GET         | `/api/jobs/mine`                          | alle            | Meine Arbeiten heute                   |
| POST        | `/api/jobs`, PUT/DELETE `/api/jobs/{id}`  | Vorarbeiter+    | Arbeiten planen (F1–F4)                |
| POST        | `/api/jobs/{id}/extend`                   | Zuständige²     | Zeit erhöhen, Folgetermine rücken (F5) |
| POST        | `/api/jobs/{id}/complete`, `/reopen`      | Zuständige²     | Abhaken (F4)                           |
| POST        | `/api/jobs/{id}/start`, `/api/time/stop`  | Zuständige²     | Zeiterfassung (Ist-Zeit)               |
| POST        | `/api/work-requests`                      | alle            | „Brauche Arbeit“ ab morgen (F7, F8)    |
| GET         | `/api/overview`                           | Vorarbeiter+    | Kapazität, Anfragen, Warnungen (F6, F9) |
| GET         | `/api/reports/soll-ist?from=&to=`         | Vorarbeiter+    | Soll/Ist-Vergleich (A1)                |
| GET/POST/PUT | `/api/users`                             | Chef            | Benutzer & Rechte                      |

¹ Arbeiter sehen nur ihre eigenen Arbeiten.
² Der zugeteilte Arbeiter oder Vorarbeiter/Chef.
