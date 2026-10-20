# Analyse: Seite blockiert nach Garmin-Sync / Multi-Editor, 500er beim Import

Stand: Commit `14b0d247` auf `fravu/Runalyze` master. Nachgestellt im Browser (Playwright) mit echten FIT-Dateien.

## 1. "Alles blockiert, nur neuer Login hilft"

**Ursache (reproduziert):** Nach jedem Import ruft Runalyze `Runalyze.reloadContent()` auf. Diese Funktion setzt auf `#container` (den gesamten Hauptbereich) die CSS-Klasse `loading`. Solange die gesetzt ist, macht CSS alles darin unsichtbar und nicht klickbar (`.loading * { visibility: hidden }`). Entfernt wird sie nur, wenn das Nachladen erfolgreich war.

Das Nachladen ist aber abgestürzt, bevor es überhaupt startete: `Statistics.showsTraining()` rief `Training.isUrl(undefined)` auf, weil `Statistics.currentUrl` undefiniert war → `TypeError: Cannot read properties of undefined (reading 'lastIndexOf')`. Zusätzlich hat auch der Fehlerzweig bei einem Server-Fehler die Klasse nie entfernt.

Ergebnis: Der Hauptbereich blieb dauerhaft unsichtbar. Das Overlay (Garmin-Maske, Multi-Editor) ließ sich zwar schließen, aber darunter war nichts mehr bedienbar. Das betrifft nicht nur den Garmin-Sync, sondern jeden Import mit anschließendem Nachladen. Beim normalen Datei-Upload tritt der Fehler genauso auf.

**Fix:** `lib/runalyze.lib.statistics.js` und `lib/runalyze.lib.js` fangen den Fall ab (Fallback: normales Neuladen der Seite, Klasse wird im Fehlerfall entfernt). JS-Bundle `web/assets/js/scripts.min.js` neu gebaut.

## 2. "There was an error: 500 Internal Server Error"

**Wahrscheinlichste Ursache:** Der schon zuvor gesehene VO2max-Fehler. Die Spalten `vo2max*` sind `DECIMAL(5,2) UNSIGNED` (max. 999.99). Für Aktivitäten, die keine Läufe sind (z. B. Motorrad), berechnet Runalyze Unsinnswerte wie 1051.75. Der INSERT scheitert, und die ganze Import-Anfrage endet mit einem 500er.

**Fix:** `src/CoreBundle/Entity/Training.php` verwirft solche Werte, statt sie zu speichern (VO2max bleibt leer).

Falls der 500er trotzdem noch auftritt: die letzte `CRITICAL`-Zeile aus `var/logs/prod.log` schicken, dann ist es eine andere Ursache.

## 2b. 500er beim Abholen mehrerer Dateien (vor der Auswahlliste) — gelöst

**Ursache (aus prod.log, in der Sandbox reproduziert):** `Doctrine\ORM\NonUniqueResultException` in der Duplikat-Prüfung `TrainingRepository::isPossibleDuplicate()`. Die Abfrage erwartete höchstens einen Treffer. In der Datenbank liegen aber Aktivitäten doppelt vor (vermutlich durch die abgebrochenen Sync-Versuche), und dann stürzte die Prüfung selbst ab.

**Fix:** Commit `724c08d8`, die Abfrage ist auf ein Ergebnis begrenzt (`setMaxResults(1)`). Getestet: vorher HTTP 500, nachher erscheint die Auswahlliste mit Duplikat-Markierung.

**Aufräumen der doppelten Aktivitäten** (Tabellen-Präfix laut Log `runalyze43_`):

```sql
SELECT activity_id, COUNT(*) AS anzahl, GROUP_CONCAT(id) AS ids
FROM runalyze43_training
WHERE activity_id IS NOT NULL
GROUP BY accountid, activity_id
HAVING COUNT(*) > 1;
```

Die überzähligen Kopien über die Runalyze-Oberfläche löschen, nicht per SQL. Nur so werden auch die zugehörigen Daten (Streckendaten, Runden, Ausrüstung) mit entfernt.

## 2c. Sackgasse "Die Trainings wurden erfolgreich importiert." — gelöst

**Ursache:** Die Erfolgsseite schließt das Overlay nur, wenn das Formular per AJAX abgeschickt wurde. Wurde es als normaler Seitenwechsel abgeschickt, blieb nur die Meldung auf einer leeren Seite stehen. Das passiert typischerweise, wenn der Browser noch das alte `scripts.min.js` aus dem Cache nutzt. Die Adresse war immer `?v=4.3.0` und änderte sich bei Updates nie. Nachgestellt in der Sandbox: Der AJAX-Weg funktioniert, der Weg ohne AJAX endet genau in dieser Sackgasse.

**Fix (Commit `8d1cbccc`):**
- Ohne AJAX leitet die Erfolgsseite nach 1,5 s zum Dashboard weiter und zeigt zusätzlich einen Link "Zurück".
- "Multi-Editor danach anzeigen" ist standardmäßig angehakt, damit man die importierten Trainings direkt bearbeiten kann. Wurde nichts importiert (alles als Duplikat abgewählt), wird der Multi-Editor übersprungen.
- JS und CSS werden über das Änderungsdatum der Datei versioniert (`?v=<mtime>`). Neue Versionen landen dadurch automatisch im Browser, ohne dass der Cache manuell geleert werden muss.

Getestet in der Sandbox: Import per AJAX öffnet den Multi-Editor, Import ohne AJAX mit und ohne Multi-Editor landet nicht mehr in einer Sackgasse.

## 2d. Multi-Editor lässt sich nicht beenden (X/Done ohne Wirkung) — gelöst

**Ursache:** Die Formulare auf der Garmin-Seite (Login, Startdatum) und der Link "Verbindung trennen" waren normale Seitenwechsel. Danach lief die Garmin-Seite als eigenständige Seite ohne Dashboard darunter, und alles, was von dort geöffnet wurde (Auswahlliste, Multi-Editor), ebenfalls. `Runalyze.Overlay.close()` tut nur etwas, wenn das Dashboard darunter liegt. Auf einer eigenständigen Seite waren X und Done deshalb sichtbar, aber wirkungslos.

**Fix (Commits `e97d3219`, `14b0d247`):**
- Garmin-Formulare und der Link zum Trennen laufen per AJAX, man bleibt im Overlay über dem Dashboard.
- `Overlay.close()` auf einer eigenständigen Seite springt zur Startseite zurück, statt nichts zu tun.
- Wird der Multi-Editor direkt als Seite aufgerufen, leitet er zum Dashboard um und öffnet sich dort im Overlay (`dashboard#multi-editor=<ids>`).
- "Done" im Multi-Editor ist fett und hat ein X-Symbol.

Getestet: Startdatum setzen → URL bleibt `/dashboard`, X schließt. Multi-Editor per AJAX und als Seite aufgerufen: Done und X schließen jeweils.

## 3. Weitere Fixes aus dieser Runde

- Multi-Editor: sichtbarer Link "Done/Fertig" oben in der Navigationsleiste (das normale X kann von der Leiste verdeckt werden).
- Garmin-Sync lädt die Auswahlseite per AJAX ins bestehende Overlay statt per Seitenwechsel.

## Deployment

`runalyze-fix6.zip` (4 Dateien, 2d) kommt zusätzlich zu `runalyze-fix5.zip` (6 Dateien, 2c). `runalyze-komplett.zip` enthält alle 19 geänderten Laufzeit-Dateien seit dem Ausgangsstand (`cc0d271f`):

1. Ins Runalyze-Verzeichnis entpacken und überschreiben
2. `rm -rf var/cache/prod`
3. Browser-Cache leeren bzw. Strg+F5, weil sich `scripts.min.js` geändert hat

## Offizielle Runalyze-Version

Das Open-Source-Projekt wurde 2019 eingestellt. Der letzte Code-Commit im offiziellen Repo ist vom März 2018. Es gibt seitdem keine neuere offizielle Open-Source-Version. Alle späteren Verbesserungen in diesem Fork stammen von codeproducer198. Die kommerzielle Version auf runalyze.com ist Closed Source.

## Absturz beim Sync über lange Zeiträume (z. B. ein Jahr), Commit `818f6d56`, `runalyze-fix12-garmin.zip`

**Ursachen:**
1. **Fortsetzungsfehler:** Garmin liefert die neuesten Aktivitäten zuerst. Bei mehr als 100 Treffern lud das Skript die 100 neuesten und speicherte das Datum der *ältesten* davon als neuen Startpunkt. Der nächste Klick holte dadurch wieder dieselben 100, ältere wurden nie erreicht.
2. **Zu große Portionen:** 100 Downloads pro Klick und anschließend das Einlesen von 100 Dateien in einem Request sprengen die Zeitlimits von Webserver und PHP.
3. **TCX/GPX:** Ältere Uploads liegen bei Garmin teils als TCX/GPX statt FIT vor und wurden als Fehler verworfen.
4. **Eine kaputte Datei bricht alles ab:** Ein unerwarteter Fehler beim Lesen einer einzelnen Datei ließ die ganze Auswahlliste mit 500 abstürzen. In der Sandbox reproduziert mit einer FIT-Datei, deren Developer-Feld einen Text statt einer Zahl enthielt.

**Fix:**
- Liste aller Aktivitäten seit dem Startdatum holen, dann **älteste zuerst** laden. Weiter geht es ab der neuesten verarbeiteten Aktivität: lückenlos und ohne Dubletten.
- Pro Klick höchstens 25 Aktivitäten bzw. 40 s. Die Meldung nennt, wie viele noch offen sind.
- .fit, .tcx und .gpx werden übernommen.
- Vorübergehende Fehler (Rate-Limit 429, Netzwerk): Abbruch ohne Überspringen, der nächste Klick setzt genau dort fort. Dauerhafte Fehler (keine Datei, z. B. manuell erfasste Aktivität) werden mit Datum angezeigt und übersprungen.
- Einzelne fehlerhafte Datei: Sie wird in der Auswahlliste als Fehler angezeigt, die anderen lassen sich normal importieren.

**Getestet** mit einem simulierten Garmin-Konto (250 Aktivitäten über ein Jahr, mit TCX, manuellen Einträgen, 404 und 429):
- Alle 250 wurden in 11 Läufen verarbeitet, keine fehlt, keine doppelt.
- Im Browser liefen zwei Klicks nacheinander mit je ca. 12 s, und beide Male erschien die Auswahlliste.
