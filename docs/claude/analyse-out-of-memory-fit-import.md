# Analyse: OutOfMemoryException beim FIT-Import (20.10.2026)

## Befund
- `var/logs/prod.log`: `OutOfMemoryException` "Allowed memory size of 134217728 bytes exhausted" in `inc/core/Parser/Activity/FileType/FitActivity.php` (Zeilen 854, 904, 907 und 908). Auftritte am 20.10.: 21:15, 21:30, 21:33, 21:35 und 23:13.
- Alle Fehler treten kurz nach einer Serie von Meldungen `Successfull file upload of …fittemp` auf, also beim Einlesen mehrerer Garmin-Dateien in **einem** Request (Auswahlliste nach dem Garmin-Sync, bis zu 25 Aktivitäten pro Klick).
- Die Zeilen im Parser sind die Stellen, an denen pro Datensatz ein Wert an die `ContinuousData`-Arrays angehängt wird. Der Speicherbedarf wächst mit der Anzahl der Dateien und deren Länge. Die `.fit`-Dateien sind bis ca. 1,3 MB groß, die zugehörigen `.fittemp` bis ca. 15 MB.
- Die Fehler 15:49 (2×) und 15:58 (`dashboardAction()` verlangt `$account`) sind ein anderes Thema: Zugriff ohne gültige Sitzung. Das gab es schon unter PHP 7.4.

## Ursache
Der Webserver nutzte Apache mit **mod_php 7.4** (`/etc/php/7.4/apache2/php.ini`). Dort stand `memory_limit = 128M`. Die CLI hat `-1`, deshalb fiel es bei Konsolentests nicht auf.

## Behebung
1. `memory_limit` in `/etc/php/7.4/apache2/php.ini` auf `326M` erhöht (vom Nutzer selbst gesetzt), Apache neu geladen.
2. Danach Umstieg auf PHP 8.5 (siehe `umstieg-php85.md`). In `/etc/php/8.5/apache2/php.ini` steht ebenfalls `memory_limit = 326M`.
3. Noch nicht ausgeführt: weniger Dateien pro Klick einlesen (aktuell 25) oder den Speicher zwischen den Dateien freigeben. Das wäre nur nötig, wenn die 326M bei einem Sync nicht reichen.
4. `max_execution_time` ist weiter 30 s in der Apache-php.ini; der Sync begrenzt sich selbst auf 40 s. Beim Anheben bedenken.

## Status
Nach dem Anheben des Limits trat im Log kein weiterer `OutOfMemoryException` auf. Ein Garmin-Sync mit vielen Dateien unter PHP 8.5 wurde noch nicht bewusst getestet.
