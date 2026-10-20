# Analyse: OutOfMemoryException beim FIT-Import (20.10.2026)

## Befund
- `var/logs/prod.log`: `OutOfMemoryException` "Allowed memory size of 134217728 bytes exhausted" in `inc/core/Parser/Activity/FileType/FitActivity.php` (Zeilen 854, 904, 907, 908). Auftritte am 20.10.: 21:15, 21:30, 21:33, 21:35 und 23:13.
- Alle Fehler treten kurz nach einer Serie von Meldungen `Successfull file upload of …fittemp` auf, also beim Einlesen mehrerer Garmin-Dateien in **einem** Request (Auswahlliste nach dem Garmin-Sync, bis zu 25 Aktivitäten pro Klick).
- Die Zeilen im Parser sind die Stellen, an denen pro Datensatz ein Wert an die `ContinuousData`-Arrays angehängt wird. Der Speicherbedarf wächst mit der Anzahl der Dateien und deren Länge. Die `.fit`-Dateien sind bis ca. 1,3 MB groß, die zugehörigen `.fittemp` bis ca. 15 MB.
- Die Fehler 15:49 (2×) und 15:58 (`dashboardAction()` verlangt `$account`) sind ein anderes Thema: Zugriff ohne gültige Sitzung.

## Ursache
Der Webserver nutzt Apache mit **mod_php 7.4** (`/etc/php/7.4/apache2/php.ini`). Dort steht `memory_limit = 128M`. Die CLI hat `-1`, deshalb fiel es bei Konsolentests nicht auf. Der installierte `php8.5-fpm` ist nicht der aktive Handler (`php7.4.conf` ist in Apache geladen), hat aber ebenfalls `128M`.

## Vorgeschlagene Behebung (noch nicht ausgeführt)
1. `memory_limit` in `/etc/php/7.4/apache2/php.ini` auf `512M`, dann `systemctl reload apache2`. Backup der php.ini vorher.
   Beim PHP-8.5-Umstieg denselben Wert in `/etc/php/8.5/fpm/php.ini` setzen.
2. Optional, kleinerer Eingriff im Code: weniger Dateien pro Klick einlesen (aktuell 25) oder den Speicher zwischen den Dateien freigeben.
3. `max_execution_time` ist ebenfalls 30 s in der Apache-php.ini; der Sync begrenzt sich selbst auf 40 s. Beim Anheben bedenken.

## Status
Die Änderung an der php.ini wurde vom Server per sudo verweigert (Berechtigungsprüfung) und daher nicht durchgeführt. Es wurde nichts am Server verändert. `git pull` war "Already up to date".
