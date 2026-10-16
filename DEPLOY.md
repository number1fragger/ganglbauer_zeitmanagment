# Auf einen Server bringen

Schritt-für-Schritt-Anleitung für einen eigenen Linux-Server (z. B. ein vServer bei Hetzner,
netcup, IONOS oder ein Rechner im Firmennetz). Am Ende läuft die App unter
`https://<eure-domain>` mit automatischem HTTPS-Zertifikat, täglichem Backup und
automatischen Datenbank-Updates bei jedem neuen Stand.

```
Internet ──443──▶ Caddy (HTTPS) ──▶ nginx (Vue-App, /api) ──▶ PHP-FPM (Symfony) ──▶ MariaDB
```

Alles läuft in Docker. Auf dem Server selbst braucht es nur Docker und Git.

---

## 0. Vorher erledigen

| Was | Warum |
| --- | --- |
| **Code auf GitHub bringen** – Branch `feature/arbeitszeiten-lehrling-abwesenheiten` pushen und in `main` mergen | Der Server holt den Code per `git clone`. Was nur lokal am Mac liegt, kommt dort nicht an. |
| **Server** mit Ubuntu 24.04 oder Debian 12, mindestens **2 GB RAM**, 20 GB Platte | Der Build (npm, Composer) braucht beim ersten Mal etwas Speicher. |
| **Domain/Subdomain**, z. B. `zeit.ganglbauer.at`, mit einem **A-Eintrag auf die IP des Servers** (beim Domain-Anbieter einstellen) | Caddy holt damit das HTTPS-Zertifikat bei Let's Encrypt. |
| **Ports 80 und 443** am Server (bzw. in der Firewall des Anbieters) offen | Für das Zertifikat und für den Zugriff. |

Ohne Domain, nur im Firmennetz: siehe [Ohne Domain](#ohne-domain-nur-im-firmennetz).

---

## 1. Docker installieren

Per SSH am Server anmelden (`ssh root@<server-ip>`), dann:

```bash
curl -fsSL https://get.docker.com | sh
```

Prüfen (Compose muss 2.20 oder neuer sein):

```bash
docker compose version
```

Firewall: nur SSH, HTTP und HTTPS zulassen.

```bash
ufw allow OpenSSH && ufw allow 80 && ufw allow 443 && ufw enable
```

Die Datenbank (Port 3307) und der interne Port 8080 sind nur am Server selbst erreichbar,
auch ohne Firewall-Regel.

## 2. Code holen

```bash
git clone https://github.com/number1fragger/ganglbauer_zeitmanagment.git /opt/werkstatt
cd /opt/werkstatt
```

Ist das Repository privat, fragt Git nach Benutzername und Passwort. Als Passwort dient dort ein
GitHub-Token (GitHub → Settings → Developer settings → Personal access tokens, Recht
„Contents: read“).

## 3. Passwörter erzeugen

```bash
./deploy/init-env.sh zeit.ganglbauer.at
```

Das legt `/opt/werkstatt/.env` mit zufälligen Passwörtern für Datenbank, App und
Login-Schlüssel an. Die Datei bleibt am Server, kommt nie ins Git und sollte nicht
weitergegeben werden. Sie gehört aber zur Sicherung (siehe Schritt 7).

## 4. Starten

```bash
docker compose up -d --build
```

Der erste Build dauert ein paar Minuten. Beim Start passiert automatisch:

- Die Datenbank wird angelegt, und alle Migrationen laufen.
- Die Schlüssel für das Login (JWT) werden erzeugt.
- Caddy holt das HTTPS-Zertifikat.

Prüfen:

```bash
docker compose ps
```

Alle vier Dienste (`database`, `backend`, `frontend`, `caddy`) sollten „Up“ zeigen.
Danach `https://zeit.ganglbauer.at` im Browser öffnen. Die Anmeldeseite sollte erscheinen.

## 5. Erster Chef **oder** bestehende Daten übernehmen

**Neu anfangen:** ersten Chef anlegen (fragt nach dem Passwort, mind. 8 Zeichen).

```bash
docker compose exec -u www-data backend php bin/console app:create-user p.hofer Peter Hofer --role=chef
```

Alle weiteren Konten legt der Chef in der App unter „Benutzer & Rechte“ an.

**Daten von der bisherigen Installation (z. B. dem Mac in der Werkstatt) mitnehmen:**

1. Am **alten Rechner** im Projektordner eine Sicherung ziehen:
   ```bash
   ./deploy/backup.sh
   ```
2. Die Datei auf den Server kopieren (Dateiname anpassen):
   ```bash
   scp backups/werkstatt-2026-10-20-1700.sql.gz root@<server-ip>:/opt/werkstatt/backups/
   ```
3. Am **Server** einspielen. Das Skript zeigt vorher an, welche Datenbank es überschreibt,
   und fragt nach:
   ```bash
   ./deploy/restore.sh backups/werkstatt-2026-10-20-1700.sql.gz
   ```
4. Backend neu starten. Fehlende Migrationen laufen dabei automatisch:
   ```bash
   docker compose restart backend
   ```

Alle Konten und Passwörter sind danach wie bisher. Am besten in einem ruhigen Moment
umziehen und die alte Installation danach nicht mehr verwenden, sonst laufen zwei Stände
auseinander.

## 6. Cronjobs einrichten

```bash
crontab -e
```

Diese zwei Zeilen am Ende einfügen:

```cron
# taeglich 02:30 Datenbank sichern (behaelt 30 Tage)
30 2 * * * /opt/werkstatt/deploy/backup.sh >> /var/log/werkstatt-backup.log 2>&1
# abends vergessene Zeiterfassungen beenden
15 21 * * * cd /opt/werkstatt && docker compose exec -T -u www-data backend php bin/console app:time:close-stale >> /var/log/werkstatt-cron.log 2>&1
```

## 7. Sicherungen außer Haus

`deploy/backup.sh` legt die Sicherungen unter `/opt/werkstatt/backups/` ab. Fällt der Server
aus, sind sie mit weg. Deshalb regelmäßig auf einen anderen Rechner holen, etwa:

```bash
scp -r root@<server-ip>:/opt/werkstatt/backups ./werkstatt-backups
```

Ebenfalls sicher aufbewahren: `/opt/werkstatt/.env`. Ohne das darin stehende Datenbank-Passwort
lässt sich ein neuer Server mit der Sicherung trotzdem aufsetzen (neue `.env` erzeugen, dann
`restore.sh`). Mit der alten `.env` bleibt aber alles gleich, auch die Logins.

---

## Im Betrieb

| Aufgabe | Befehl (im Ordner `/opt/werkstatt`) |
| --- | --- |
| **Neuen Stand einspielen** | `./deploy/backup.sh && git pull && docker compose up -d --build` |
| Status | `docker compose ps` |
| Logs ansehen | `docker compose logs -f backend` (bzw. `frontend`, `caddy`, `database`) |
| Neu starten | `docker compose restart` |
| Stoppen / starten | `docker compose down` / `docker compose up -d` |
| Konsolenbefehl | `docker compose exec -u www-data backend php bin/console <befehl>` |

`docker compose down -v` niemals ausführen: Das `-v` löscht die Datenbank.

Bei einem Update laufen neue Datenbank-Migrationen beim Start automatisch. Deshalb vorher
immer `backup.sh`, so wie in der Tabelle oben.

### Sicherheit, die schon eingebaut ist

- HTTPS mit automatisch erneuertem Zertifikat. HTTP leitet auf HTTPS um, und es gibt HSTS.
- Login-Sperre nach 5 Fehlversuchen je Konto und IP für 15 Minuten. Die App meldet dann
  „Zu viele Fehlversuche …“.
- Eigene, zufällige Passwörter je Server. Fehlen sie, startet der Server-Aufbau gar nicht erst.
- Datenbank und PHP sind von außen nicht erreichbar, nur Caddy auf 80/443.
- Die Rechte prüft das Backend bei jeder Anfrage. Demodaten lassen sich in Produktion nicht laden.

---

## Ohne Domain (nur im Firmennetz)

Wenn der Server nur im Werkstatt-Netz erreichbar sein soll:

```bash
./deploy/init-env.sh :80
docker compose up -d --build
```

Die App läuft dann unter `http://<server-ip>`, **ohne HTTPS**. Das ist nur im eigenen Netz
vertretbar. Passwörter gehen dabei unverschlüsselt durchs Netz, also nie so ins Internet
stellen. Wird später doch eine Domain verwendet: in `.env` `DOMAIN=` auf die Domain ändern
und `docker compose up -d` ausführen.

## Probleme lösen

| Problem | Lösung |
| --- | --- |
| `required variable … is missing a value` | `.env` fehlt oder ist unvollständig → `./deploy/init-env.sh <domain>` |
| Seite lädt nicht, Zertifikatsfehler | `docker compose logs caddy`. Meist zeigt die Domain (noch) nicht auf den Server, oder Port 80/443 ist zu. DNS-Änderungen brauchen manchmal ein paar Stunden. |
| „Unerwarteter Fehler (502)“ | Das Backend läuft nicht → `docker compose logs backend`. Oft ist die Datenbank beim allerersten Start noch nicht fertig; nach einer Minute erneut versuchen. |
| Jemand ist durch Fehlversuche gesperrt | 15 Minuten warten oder `docker compose restart backend` |
| Build bricht ab, „killed“ / zu wenig Speicher | Server mit mehr RAM nehmen oder Swap anlegen: `fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile` |
| Uhrzeiten falsch | Die App rechnet fix in Europe/Vienna. Die Zeitzone des Servers spielt keine Rolle. |

## Dateien zum Server-Aufbau

| Datei | Zweck |
| --- | --- |
| `docker-compose.yml` | Datenbank, Backend, Frontend (gilt auch lokal) |
| `docker-compose.server.yml` | Server-Zusatz: Pflicht-Passwörter, Caddy für HTTPS |
| `.env.example` | Vorlage der `.env`. Erzeugt wird sie am einfachsten mit `deploy/init-env.sh` |
| `deploy/Caddyfile` | HTTPS-Proxy |
| `deploy/init-env.sh` | `.env` mit zufälligen Passwörtern anlegen |
| `deploy/backup.sh`, `deploy/restore.sh` | Datenbank sichern bzw. zurückspielen |
