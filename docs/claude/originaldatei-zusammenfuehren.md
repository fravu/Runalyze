# Originaldatei zusammenführen (21.10.2026)

Nachbau von "Upload or merge original files to complete existing activities" (runalyze.com, 06.10.2026). Erreichbar über **Extras → Originaldatei zusammenführen**, die Tools-Übersicht, das Auswertungs-Menü rechts oben in den Auswertungsseiten und die Schnellsuche.

## Wofür
Manche Werte kommen bei Synchronisationsdiensten nicht an (Garmin entfernt unbekannte Felder), zum Beispiel VO2max-Schätzung, Erholungszeit oder Performance Condition. Die Original-FIT-Datei aus Garmin Connect enthält sie. Das Tool ordnet die Datei einer vorhandenen Aktivität zu und trägt **nur fehlende Werte** ein. Vorhandenes wird nicht verändert.

## Ablauf
1. FIT-Datei wählen, "Datei prüfen". Die Datei wird mit dem normalen Importer gelesen (nichts wird importiert) und zwischengespeichert in `data/import/merge/<token>.fit` (Dateien älter als 1 Stunde werden beim nächsten Aufruf gelöscht).
2. Gesucht werden passende vorhandene Aktivitäten: gleiche Aktivitäts-Kennung (`activity_id`) oder Start innerhalb von 15 Minuten bei ähnlicher Dauer (±10 %, mindestens ±2 Minuten).
3. Pro Treffer steht, welche Werte ergänzt würden. "Diese Werte ergänzen" (mit Rückfrage) trägt sie ein. Es sind nur Aktivitäten erlaubt, die als Treffer für diese Datei angezeigt wurden.

## Was ergänzt wird
Alle FIT-Felder der Aktivität (VO2max-Schätzung, Erholungszeit, HRV-Analyse, Training Effect aerob/anaerob/Nutzen, Performance Condition Start/Ende, Laktatschwelle Puls/Tempo, Anstieg/Abstieg der Uhr, Gefühl, empfundene Anstrengung, Spitzenbelastung, Lauf-/Geh-/Stehzeit, Sekunden in Pulszonen, Schweißverlust) sowie Ø/Max-Atemfrequenz, Pulszonen des Geräts und Maximalpuls (Nutzer). "Fehlend" heißt leer oder 0.
**Nicht** verändert werden Datenreihen (Developer-Felder wie SmO2), Streckendaten, TRIMP, VO2max aus der Runalyze-Berechnung und alle vom Nutzer eingetragenen Werte.

## Code
- `src/CoreBundle/Component/Tool/Insights/OriginalFileMerger.php` (liest die Datei mit `app.file_importer` und `app.activity_data_container.converter`, vergleicht, speichert über `TrainingRepository::save()` und leert den Cache)
- Aktionen `mergeOriginal*Action` in `InsightsController`, Template `merge_original.html.twig`.
- Nur FIT-Dateien (`.fit`), nur per POST und Ajax.

## Getestet
- Echte FIT-Datei (Aktivität #2002) im Browser hochgeladen: wird gelesen, die Aktivität vom 29.09.2026 19:03 als Treffer gefunden ("gleiche Startzeit und Dauer"), Meldung "Nichts zu ergänzen" (alle Werte schon vorhanden).
- Schreibtest in einer Datenbank-Transaktion mit Rollback: Bei #2002 wurden `fit_vo2max_estimate` und `fit_recovery_time` gelöscht und aus der Datei wieder ergänzt (39,93 und 2603, die Performance Condition war nicht in der Datei und blieb leer). Ein zweiter Lauf ergänzte nichts mehr. Nach dem Rollback waren alle Werte wie am Anfang.
- Beim Test fiel der Upload-Fehler durch PHP 8.1+ (`full_path`) auf, siehe `php8-fixes-aktivitaetsansicht.md`.
- Echte Ergänzungen an deinen Daten wurden beim Test **nicht** gespeichert.
