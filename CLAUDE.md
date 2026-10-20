# Runalyze auf runalyze.fv0.de – Arbeitsregeln für Claude Code

Antworte auf Deutsch, direkt und ohne Floskeln. Ergebnisse jeder Unterhaltung in einer md-Datei unter `docs/claude/` festhalten.

## Umgebung
- Dieses Verzeichnis ist per **sshfs** vom Server gemountet: Server `runalyze` (SSH-Alias), Pfad `/var/www/runalyze-codeproducer198`.
- **Befehle nie lokal ausführen**, wenn sie PHP, Datenbank, Cache oder Dienste betreffen. Immer per SSH auf dem Server:
  `ssh runalyze 'cd /var/www/runalyze-codeproducer198 && <befehl>'`
  Das gilt für `php`, `bin/console`, `composer`, `mysql`/`mysqldump`, `systemctl`, `rm -rf var/cache/...` und das Lesen von Logs.
- Dateien lesen und bearbeiten geht direkt im gemounteten Ordner.
- `vendor/` und `node_modules/` nicht durchsuchen (sshfs, zu langsam), nur gezielt einzelne Dateien lesen.
- Datenbank: `runalyze_backup`, Tabellen-Präfix `runalyze43_`. Zugangsdaten stehen in `data/config.yml`; nicht ausgeben und nicht kopieren.
- Vor schreibenden DB-Änderungen oder Migrationen ein Backup anlegen: `ssh runalyze 'mysqldump runalyze_backup > ~/runalyze-$(date +%F-%H%M).sql'`.

## Projekt
- Runalyze 4.3 (Fork von codeproducer198), Symfony 3.4, Doctrine, Twig 1.x, Legacy-Code in `inc/`, Plugins in `plugin/`.
- Quellcode-Repository: GitHub `fravu/Runalyze`, Branch `master`. Das Server-Verzeichnis ist ein git-Arbeitsverzeichnis dieses Repos (Remote `origin`, Deploy-Key mit Schreibrecht).

## git-Workflow
- git **immer auf dem Server** ausführen, nie über sshfs (zu langsam): `ssh runalyze 'cd /var/www/runalyze-codeproducer198 && git status --short'`
- Vor Beginn einer Aufgabe: `git status` prüfen und `git pull --ff-only origin master`.
- Nach jeder abgeschlossenen, getesteten Änderung: gezielt `git add <dateien>` (nie `git add -A` ohne vorher `git status` zu prüfen), `git commit -m "…"` mit aussagekräftiger Nachricht, dann `git push origin master`.
- Nie committen: `data/config.yml`, `var/`, `data/garmin_session/`, `data/garmin_venv/`, Importdateien. Die `.gitignore` deckt das ab; trotzdem jeden Commit vor dem Push mit `git show --stat HEAD` prüfen.
- Rückgängig machen einer Änderung: `git revert <commit>` statt Dateien von Hand zurückzubauen.
- Fehler stehen in `var/logs/prod.log` (Zeilen mit `CRITICAL`): `ssh runalyze 'grep CRITICAL /var/www/runalyze-codeproducer198/var/logs/prod.log | tail -5'`
- Nach Änderungen an Templates, Routen oder Entities: `ssh runalyze 'cd /var/www/runalyze-codeproducer198 && rm -rf var/cache/prod'` (bei Entity-Änderungen auch `var/cache/doctrine`).
- Migrationen: `ssh runalyze 'cd /var/www/runalyze-codeproducer198 && php bin/console doctrine:migrations:migrate --no-interaction -e prod'`
- JS-Quellen in `lib/` werden per gulp zu `web/assets/js/scripts.min.js` gebündelt; das Bundle ist eingecheckt.
- Garmin-Sync: `call/python/garmin_sync.py`, Python-venv in `data/garmin_venv`, `python3_path` in `data/config.yml`.

## Vorgaben des Nutzers
- Farben: **kein Rot/Grün**, Gelb/Blau mit hohem Kontrast; Farben möglichst einstellbar.
- Erst fragen, wenn nur gefragt wird, ob etwas geht; nicht ungefragt loslegen.
- PHP auf dem Server: aktuell 7.4, Umstieg auf 8.5 geplant (siehe `docs/claude/umstieg-php85.md`).

## Hintergrund
Siehe `docs/claude/`: Garmin-Sync-Analyse, Installationsanleitung, Marathonform/TRIMP-Ausschluss, Anstiege/Heatmap/Segmente, PHP-8.5-Umstieg.
