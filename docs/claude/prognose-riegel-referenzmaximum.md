# Prognose: Riegel-Modell, bessere Referenzergebnisse, einstellbares Referenzmaximum (21.10.2026)

## Riegel-Modell
- Neue Klasse `inc/core/Sports/Running/Prognosis/Riegel.php`: T2 = T1 × (D2/D1)^1,06. Referenz ist das beste Ergebnis.
- Eingebunden im Panel "Prognose" (Einstellung "Prognosemodell": Peter Riegel) und im Prognose-Rechner (Fenster, Modell "Peter Riegel").
- Geprüft: 20:00 über 5 km ergibt 41:41 (10 km), 1:32:00 (Halbmarathon), 3:11:49 (Marathon).

## Referenzergebnisse für Steffny, Cameron, Riegel und CPP
Ursache für unsinnige Prognosen (z. B. Marathon in 41 Minuten): `TopResults` nahm die Läufe mit dem höchsten `vo2max_by_time`, darunter die als Laufen gespeicherten Motorradfahrten. Jetzt nur noch Läufe mit "für VO2max-Form verwenden" und höchstens 25 km/h Schnitt (`Training::sqlPlausibleRunningSpeed()`, wie bei der Marathonform). Datei `inc/core/Calculation/Prognosis/TopResults.php`.

## Referenzmaximum für ATL/CTL (Panel "Rechenspiele")
Die Prozentwerte für Müdigkeit (ATL) und Fitnessgrad (CTL) beziehen sich auf ein Maximum. Bisher war das immer der Höchstwert über die gesamte Historie. Neue Panel-Einstellungen (Zahnrad am Panel):

| Einstellung | Wirkung |
|---|---|
| Referenzmaximum: Gesamte Zeit (Standard) | wie bisher, das gespeicherte Maximum wird nachgeführt |
| Seit einem Datum | Höchstwert seit `reference_max_since` (YYYY-MM-DD) |
| Gleitendes Fenster | Höchstwert der letzten `reference_max_days` Tage (Standard 365) |
| Von Hand | feste Werte `reference_max_atl` und `reference_max_ctl` (0 = gespeichertes Maximum) |

Die anderen Modi ändern das gespeicherte Gesamtmaximum nicht. Das Diagramm "Form" und die Monotonie nutzen weiter das gespeicherte Maximum.
Datei: `plugin/RunalyzePluginPanel_Rechenspiele/class.RunalyzePluginPanel_Rechenspiele.php`.

## Getestet
- Beide Panels laden ohne Fehler, die neuen Felder erscheinen im Einstellungsformular.
- Gleitendes Fenster mit 30 Tagen: ATL 23 % und CTL 47 % statt 4 % und 12 % bei "Gesamte Zeit". Danach wieder auf "Gesamte Zeit" gestellt, die Werte stimmen mit dem Ausgangszustand überein.
- Prognose-Panel mit Riegel, Cameron und Steffny liefert zueinander passende Werte. CPP braucht zwei Ergebnisse mit unterschiedlicher Distanz und zeigt hier nichts. Danach wieder auf "Effektives VO2max" gestellt.
