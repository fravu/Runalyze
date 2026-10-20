# PHP-8.5-Fixes in der Aktivitätsansicht (21.10.2026)

Nach dem Umstieg auf PHP 8.5 warf das Öffnen von Aktivität 1530 (`ActivityShow`) nacheinander zwei TypeErrors. Ursache beider: leere oder nicht numerische Werte in den Streckendaten (Puls). PHP 7.4 hat sie stillschweigend akzeptiert, PHP 8 nicht.

1. `inc/core/Calculation/Distribution/TimeSeriesForTrackdata.php:87`: `Unsupported operand types: int * string`. Fix: Zeitdifferenz und Messwert werden vor der Multiplikation mit `(float)` umgewandelt.
2. `inc/training/view/section/table/class.TableZonesHeartrate.php`: `HrZonesCalculator::getZones(): Argument #1 ($minHr) must be of type int, string given`. Fix: Nicht numerische Schlüssel werden aus dem Pulshistogramm entfernt, Min/Max werden als `int` übergeben, ein leeres Histogramm ergibt eine leere Tabelle.

Danach lädt die Aktivität vollständig (vom Nutzer bestätigt).

## Hinweis zum Log
Seit dem Umstieg schreibt `var/logs/prod.log` die Zeiten in UTC (zwei Stunden hinter der Serverzeit), weil `date.timezone` in den php.ini auskommentiert ist. Beim Filtern nach Uhrzeit zählt daher UTC. Abhilfe: `date.timezone = Europe/Berlin` in `/etc/php/8.5/apache2/php.ini` und `/etc/php/8.5/cli/php.ini`.

## Weitere Fehler dieser Art
Selten genutzte Ansichten können unter PHP 8 weitere TypeErrors bei Streckendaten werfen. Vorgehen: `grep CRITICAL var/logs/prod.log | tail`, Zeile und Aufrufer lesen, Werte vor der Rechnung in Zahlen umwandeln.
