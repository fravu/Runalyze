# PHP-8.5-Fixes in der Aktivitätsansicht (21.10.2026)

Nach dem Umstieg auf PHP 8.5 warf das Öffnen von Aktivität 1530 (`ActivityShow`) nacheinander zwei TypeErrors. Ursache beider: leere oder nicht numerische Werte in den Streckendaten (Puls). PHP 7.4 hat sie stillschweigend akzeptiert, PHP 8 nicht.

1. `inc/core/Calculation/Distribution/TimeSeriesForTrackdata.php:87`: `Unsupported operand types: int * string`. Fix: Zeitdifferenz und Messwert werden vor der Multiplikation mit `(float)` umgewandelt.
2. `inc/training/view/section/table/class.TableZonesHeartrate.php`: `HrZonesCalculator::getZones(): Argument #1 ($minHr) must be of type int, string given`. Fix: Nicht numerische Schlüssel werden aus dem Pulshistogramm entfernt, Min/Max werden als `int` übergeben, ein leeres Histogramm ergibt eine leere Tabelle.

Danach lädt die Aktivität vollständig (vom Nutzer bestätigt).

## Hinweis zum Log
Seit dem Umstieg schreibt `var/logs/prod.log` die Zeiten in UTC (zwei Stunden hinter der Serverzeit), weil `date.timezone` in den php.ini auskommentiert ist. Beim Filtern nach Uhrzeit zählt daher UTC. Abhilfe: `date.timezone = Europe/Berlin` in `/etc/php/8.5/apache2/php.ini` und `/etc/php/8.5/cli/php.ini`.

## Weitere Fehler dieser Art
Selten genutzte Ansichten können unter PHP 8 weitere TypeErrors bei Streckendaten werfen. Vorgehen: `grep CRITICAL var/logs/prod.log | tail`, Zeile und Aufrufer lesen, Werte vor der Rechnung in Zahlen umwandeln.

## Dashboard ohne Sitzung: 500 statt Login (behoben 21.10.2026)
Ursache: `@Security("has_role('ROLE_USER')")` wird vom SensioFrameworkExtra-`SecurityListener` erst bei `kernel.controller_arguments` geprüft, also **nach** dem Auflösen der Argumente. Ohne Login findet `AccountValueResolver` keinen `Account`, und Symfony wirft "requires that you provide a value for the $account argument" (CRITICAL, HTTP 500). Das betraf alle Aktionen mit `Account $account` (Dashboard, Feedback usw.) und trat schon unter PHP 7.4 auf.

Fix in `src/CoreBundle/ArgumentResolver/AccountValueResolver.php`: Ist kein `Account` angemeldet und das Argument nicht optional, wirft der Resolver eine `AccessDeniedException`. Die Firewall leitet anonyme Nutzer daraufhin zum Login um.

Getestet: `/dashboard` und `/feedback` ohne Sitzung antworten mit 302 auf `/en/login`, die Login-Seite mit 200, kein neuer Logeintrag.

## Suche: 500er und "0 Trainings gefunden" (behoben 21.10.2026)
1. `inc/html/class.HTML.php:302`: `implode($additionalAttributes, '')` ist unter PHP 8 verboten (alte Argumentreihenfolge, `implode(): Argument #1 ($separator) must be of type string, array given`). Fix: `implode('', $additionalAttributes)`. Danach ließ sich die Suchmaske öffnen. Weitere `implode`-Aufrufe mit Array zuerst gibt es im Code nicht (per grep geprüft).
2. `inc/html/formular/class.FormularValueParser.php`, `parseTime()`: Ein leeres Feld (`''`) wurde mit `$value == 0` geprüft. Unter PHP 7.4 ist `'' == 0` wahr, unter PHP 8 falsch. Das Dauer-Feld der Suche zeigte deshalb `0:00` statt leer und die Suche filterte auf Dauer = 0, also immer "0 Trainings". Fix: leere Werte werden ausdrücklich geprüft.

Getestet im Browser: Suchmaske öffnet, Dauer ist leer, die Suche findet 2043 Trainings. Das Log hat keinen neuen Eintrag.

Typisches PHP-8-Muster für weitere Fehler: `'' == 0`, `null == 0` und `'abc' == 0` verhalten sich anders, ebenso `implode(array, string)`, Zugriff auf nicht existierende Array-Schlüssel (Warnung) und Typfehler bei int/string-Argumenten.
