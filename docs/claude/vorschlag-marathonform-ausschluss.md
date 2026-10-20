# Marathonform 223 %: Ursache und Vorschlag

## Ursache (im Code nachvollzogen)

- Die Marathonform (`inc/core/Calculation/BasicEndurance.php`) zählt alle Trainings der Laufsportart (`sportid = runningSport`) und nutzt dabei nur Distanz und Datum. Lange "Läufe" gehen quadratisch ein. Eine 100-km-Motorradfahrt, die als Lauf gespeichert ist, sprengt den Wert.
- Wie landet Motorrad bei Laufen? Garmin schreibt `sport = motorcycling (22)` in die FIT-Datei. Runalyze kennt dafür kein Profil und bildet es auf "Generic" ab. Hat der Account keine Sportart mit Profil "Generic", fällt der Import auf die **Hauptsportart** zurück, also Laufen (`ActivityDataContainerToActivityContextConverter::tryToSetSportFor`). Dasselbe passiert bei Autofahren, Boot, Fliegen, Golf usw.
- Es gibt bereits ein Feld pro Training, `use_vo2max` ("Für VO2max-Form verwenden"), das von der Marathonform aber ignoriert wird.

## Vorschlag

1. **Import korrigieren:** Enthält die FIT-Datei eine Sportart, die Runalyze nicht kennt, landet das Training bei einer Sportart "Sonstiges" (Profil Generic) statt bei der Hauptsportart. Gibt es keine solche Sportart, wird sie automatisch angelegt. Dateien ganz ohne Sportangabe landen weiterhin bei der Hauptsportart.
2. **Ein Schalter für alle Formberechnungen:** Die Marathonform berücksichtigt künftig auch `use_vo2max`. Mit einer Checkbox im Training ist es dann aus VO2max-Form und Marathonform raus.
3. **Plausibilitätsschutz:** Ein Lauf mit Ø-Geschwindigkeit über 25 km/h (schneller als 2:24 min/km) bekommt beim Import automatisch `use_vo2max = 0` und wird in der Marathonform ignoriert. Den Grenzwert kann man anpassen.
4. **Bestand bereinigen:** betroffene Trainings finden, dann über Suche → Multi-Editor die Sportart ändern (z. B. neue Sportart "Motorrad" anlegen). Danach Tools → Neuberechnen.

## SQL zum Finden der Kandidaten (Präfix runalyze43_)

```sql
SELECT t.id, FROM_UNIXTIME(t.time) AS datum, t.distance AS km,
       SEC_TO_TIME(t.s) AS dauer, ROUND(t.distance / (t.s/3600), 1) AS kmh
FROM runalyze43_training t
JOIN runalyze43_sport s ON s.id = t.sportid
WHERE s.internal_sport_id = 1 AND t.s > 0
  AND t.distance / (t.s/3600) > 25
ORDER BY kmh DESC;
```

## Umsetzung (Commit `a93c70fe`, `runalyze-fix7.zip`)

Punkte 1–3 sind umgesetzt:
- **Import:** Nennt die Datei eine Sportart, die der Account nicht hat (z. B. Motorrad), landet das Training bei der Sportart mit Profil "Generic". Fehlt diese, wird sie als "Sonstiges" angelegt. Laufen-Dateien und Dateien ohne Sportangabe verhalten sich wie bisher.
- **Import:** Ein "Lauf" mit mehr als 25 km/h Schnitt bekommt `use_vo2max = 0`.
- **Berechnung:** Die Marathonform berücksichtigt `use_vo2max`. Marathonform und VO2max-Form ignorieren Läufe über 25 km/h, auch bei schon gespeicherten Trainings. Der Grenzwert steht in `Training::MAX_PLAUSIBLE_RUNNING_SPEED_KMH`.

Getestet in der Sandbox mit präparierten FIT-Dateien:
- `sport = motorcycling` → "Sonstiges"
- Lauf mit 30 km/h → `use_vo2max = 0`
- Marathonform mit zwei 39-km-"Läufen" mit 30 km/h: vorher 31 %, nachher 0 %
- Ein normaler Lauf zählt weiterhin.

**Einspielen:**
1. ZIP entpacken
2. `rm -rf var/cache/prod`
3. Tools → Datenbank bereinigen/Neuberechnen, damit die Marathonform neu berechnet wird

Die Motorradfahrten, die schon als Laufen gespeichert sind, fallen durch die 25-km/h-Grenze automatisch aus der Berechnung. Sauberer ist es trotzdem, ihre Sportart zu ändern (SQL-Abfrage oben, dann Suche → Multi-Editor). Sonst stehen sie weiter in den Laufstatistiken (km/Woche usw.).

## Welche Werte sind von Motorradfahrten betroffen?

| Wert (Panel "Rechenspiele") | Basis | Sportarten | Durch fix7 bereinigt? |
|---|---|---|---|
| VO2max-Form | VO2max je Lauf, 30 Tage | nur Laufen | ja (25-km/h-Grenze, `use_vo2max`) |
| Marathonform | Lauf-km, lange Läufe | nur Laufen | ja |
| Wettkampfprognose | VO2max-Form | nur Laufen | ja, indirekt |
| ATL / CTL / TSB | TRIMP (Puls × Dauer) | **alle** | **nein** |
| Monotonie / Training Strain | TRIMP, 7 Tage | **alle** | **nein** |

Motorradfahrten haben einen niedrigen Puls, dauern aber lange und bringen deshalb TRIMP-Punkte. Die verfälschen ATL/CTL/TSB auch dann, wenn sie als "Sonstiges" gespeichert sind. Mögliche Abhilfe: Sportarten mit einem Schalter "nicht in Trainingsbelastung (TRIMP) einrechnen" versehen, oder beim Import für solche Sportarten `trimp = 0` setzen.

## Umsetzung TRIMP-Ausschluss (Commit `bce607c1`, `runalyze-fix8.zip`)

- Neue Option pro Sportart: "Nicht in Trainingsbelastung einrechnen" (Einstellungen → Sportarten → Sportart bearbeiten). Trainings dieser Sportart zählen nicht für ATL, CTL, TSB, Monotonie und Training Strain, weder im Panel noch im Diagramm.
- Im Diagramm "Form" nutzen die Kurven für Marathonform und VO2max jetzt denselben Plausibilitätsfilter (25 km/h, `use_vo2max`).
- Das Sportart-Formular lässt die interne Sportart "Generic" jetzt stehen. Vorher wurde sie beim Speichern gelöscht, und der Import hätte ein zweites "Sonstiges" angelegt.
- Rechenspiele-Panel: verschachtelter Ternary-Operator geklammert. Unter PHP 8 war das ein fataler Fehler, unter 7.4 war die Farbe der Monotonie-Anzeige falsch.

Getestet in der Sandbox: Mit einer Motorradfahrt (120 TRIMP) in der Sportart "Sonstiges" lag ATL bei 49 %, CTL bei 62 % und Training Strain bei 99. Mit Ausschluss: ATL 11 %, CTL 15 %, Training Strain 21.

### Einspielen (Reihenfolge wichtig)

1. **Zuerst** die Spalte anlegen, sonst gibt es einen 500er:
   ```sql
   ALTER TABLE runalyze43_sport ADD `exclude_from_trimp` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `outside`;
   ```
2. ZIP entpacken
3. Caches löschen:
   - `rm -rf var/cache/prod var/cache/doctrine`
   - `rm -f data/cache-/sp/*.txt data/cache-/pl/*.txt`
4. Für Motorrad bzw. Sonstiges den Haken setzen
5. Tools → Datenbank bereinigen: die Maximalwerte für ATL/CTL/TRIMP neu berechnen lassen. Die Prozentwerte beziehen sich auf diese Maxima.

## VO2max pro Training

Eine VO2max wird pro Training nur für die Sportart mit dem internen Profil "Laufen" berechnet (`ActivityListener::calculateVO2max`). Ändert man die Sportart, wird der Wert beim Speichern gelöscht. Für Motorradfahrten, die korrekt als Motorrad oder Sonstiges gespeichert sind, gibt es also keine VO2max. Bei solchen, die noch als Laufen gespeichert sind, greift die 25-km/h-Grenze. Der Korrekturfaktor aus den Wettkämpfen ist davon nicht betroffen.

## Nachtrag: 500er "Unknown column hr_zone_bounds" nach fix8

**Ursache:** Das ist nicht die neue Spalte. Deiner Datenbank fehlen ältere Migrationen des codeproducer-Forks, mindestens `Version20240516200000` (hr_zone_bounds u. a.) und vermutlich `Version20251007210000` (with_goal, kcal_rest …). Bisher fiel das nicht auf, weil `var/cache/doctrine` noch die Metadaten der alten Entity-Klassen enthielt. Durch das Löschen des Caches benutzt Doctrine jetzt die echten Klassen und erwartet deren Spalten.

**Lösung:**
1. `php bin/console doctrine:migrations:status -e prod`
2. Steht "Current Version" auf 2023-11-15 (`20231115200000`) oder später: `php bin/console doctrine:migrations:migrate --no-interaction -e prod`
3. Ist sie älter: erst die Ausgabe prüfen. `Version20230111150000` rechnet RPE-Werte um und darf keinesfalls doppelt laufen.

### Ergebnis `migrations:status` auf dem Server

Es sind 57 Migrationen verfügbar, im Repo sind es 60. Auf dem Server fehlen die Dateien `Version20231115200000`, `Version20240516200000` und `Version20251007210000`. Die Server-Installation ist also eine ältere Fork-Version als das Repo. Einzelne Dateien aus den ZIPs (Sport.php, Training.php) stammen aus dem neueren Stand und erwarten diese Spalten.

**Behebung:**
1. `runalyze-migrationen.zip` entpacken. Es enthält die 3 Migrationen, die nur Spalten hinzufügen.
2. `php bin/console doctrine:migrations:migrate --no-interaction -e prod`
3. `rm -rf var/cache/prod var/cache/doctrine`

**Empfehlung:** Mittelfristig den kompletten Repo-Stand auf den Server bringen, statt einzelne Dateien zu mischen.

## Nachtrag 2: "Unknown column type pipe_array_str", Umstieg auf den vollständigen Stand

Der Server hat einen älteren Fork-Stand, und das Mischen mit neueren Einzeldateien bricht jetzt überall. Lösung: `runalyze-vollstaendig-1-code.zip` + `runalyze-vollstaendig-2-vendor.zip`, der komplette Repo-Stand `bce607c1` ohne `data/`, `tests/` und `node_modules/`, mit `vendor/`. Deine Konfiguration `data/config.yml` und deine Daten bleiben unberührt.

```bash
cp -a /var/www/runalyze-codeproducer198 /var/www/runalyze-codeproducer198.bak
cd /var/www/runalyze-codeproducer198
unzip -o /pfad/zu/runalyze-vollstaendig-1-code.zip
unzip -o /pfad/zu/runalyze-vollstaendig-2-vendor.zip
php bin/console doctrine:migrations:migrate --no-interaction -e prod
rm -rf var/cache/prod var/cache/doctrine
rm -f data/cache-/*/*.txt
chown -R www-data:www-data var data
```
Rückweg bei Problemen: das `.bak`-Verzeichnis zurückkopieren. Die Datenbank-Spalten, die inzwischen dazugekommen sind, stören den alten Code nicht.

## Stand 23.09.2026, abends

Der vollständige Repo-Stand `bce607c1` läuft auf dem Server, laut Rückmeldung ohne Fehler. Server und Repo sind damit wieder auf demselben Stand. Künftige Änderungen lassen sich wieder als einzelne Dateien ausliefern, ohne dass alte und neue Fork-Stände gemischt werden.

Offen:
- In den Einstellungen für Motorrad/Sonstiges "Nicht in Trainingsbelastung einrechnen" aktivieren.
- Unter Tools → Datenbank bereinigen die Maximalwerte für ATL/CTL/TRIMP neu berechnen.
- Die Motorradfahrten, die noch als Laufen gespeichert sind, umstellen: SQL-Abfrage oben, dann Suche → Multi-Editor.
- Prüfen, ob `runalyze_backup` wirklich die produktive Datenbank ist.
- `/var/www/runalyze-codeproducer198.bak` löschen, wenn alles eine Weile stabil läuft.
