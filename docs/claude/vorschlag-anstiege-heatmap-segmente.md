# Vorschlag: Anstiege (Climb Score), Heatmap pro Sportart, Segmente

Visueller Entwurf: https://claude.ai/artifact/MEEd3ZKUd7AXfrskehyasL (Datei `anstiege-heatmap-entwurf.html`)

## Vorhanden im Code
- Routen speichern Geohashes, korrigierte Höhen sowie Bounding-Box (`min`/`max`) und Start-/Endpunkt.
- Plugin "Strecken" hat ein Streckennetz: alle Routen einer Sportart und eines Jahres als Linien auf einer Leaflet-Karte.
- Leaflet und d3 sind eingebunden.

## 1. Anstiege (Laufen, Wandern, Rad)
- Erkennung aus `elevations_corrected`: Höhe auf 50 m glätten; ein Anstieg braucht mindestens 3 % Ø und 300 m Länge; kurze Flachstücke werden überbrückt.
- Score = Länge (m) × Ø-Steigung (%). Eigene Formel, die von runalyze.com ist nicht öffentlich.
- Kategorien je Sportart: Rad 8k/16k/32k/64k/80k (Kat. 4 bis HC); Laufen und Wandern halbe Schwellen plus Kat. 5.
- Übersicht: Tabelle mit Filter nach Sportart, Jahr und Kategorie. Spalten: Länge, Höhe, Ø/max, Score, Bestzeit, VAM (Hm/h), Anzahl.
- Detail: Profil eingefärbt nach Steigung, alle Versuche, Abschnitt auf der Karte markiert.
- Gleiche Anstiege aus verschiedenen Aktivitäten: Start und Ende jeweils < 50 m entfernt.

## 2. Heatmap pro Sportart
- Erweiterung des Streckennetzes: Canvas-Layer mit additiver Transparenz, mehrere Sportarten in eigenen Farben, Zeitraum, Klick auf eine Stelle zeigt die Aktivitäten dort.
- Server liefert vereinfachte Linien pro Sportart als JSON und cacht sie.

## 3. Segmente
- Anlegen per Start/Ende auf Karte oder Profil; jeder Anstieg lässt sich zum Segment machen.
- Neue Tabellen `climb`, `segment`, `segment_effort` (Doctrine-Migration).
- Treffer: Start/Ende < 25 m, ≥ 90 % der Punkte < 30 m; Vorfilter über die Bounding-Box.
- Prüfung beim Import und einmalig für den Bestand (Tool).

## Reihenfolge
- A: Heatmap (klein)
- B: Anstiege (mittel)
- C: Segmente (mittel bis groß)

## Umsetzung (Commit `79a8f3e3`, `runalyze-fix9-karten-anstiege-segmente.zip`)

Unter Tools gibt es jetzt den Abschnitt "Karten & Anstiege" mit drei Einträgen.

**Heatmap**
- Alle GPS-Strecken auf einer Leaflet-Karte (Canvas-Layer), je Sportart eingefärbt.
- Sportarten per Chip ein- und ausschaltbar, Zeitraum von/bis Jahr, Deckkraft einstellbar.
- Klick auf die Karte listet die Aktivitäten an dieser Stelle; ein Klick darauf öffnet die Aktivität.
- Die Routen werden auf Geohash-Genauigkeit 8 (ca. 38 × 19 m) reduziert und in `var/cache/prod/heatmap` gecacht, bis sich Aktivitäten ändern.

**Anstiege**
- Beim ersten Öffnen werden alle Aktivitäten mit GPS und Höhe analysiert, in Portionen von je 15 s. Der Fortschritt wird angezeigt.
- Erkennung: Höhe über ±25 m geglättet, Tal-Gipfel-Hysterese 10 m, flache Anfangs- und Endstücke werden abgeschnitten. Ein Anstieg braucht mindestens 300 m Länge, 3 % Steigung und 20 Hm.
- Score = Länge (m) × Ø-Steigung (%). Kategorien für Rad: 4k/8k/16k/32k/64k/80k (5 bis HC); Laufen, Wandern und alle anderen Sportarten: die halben Werte.
- Übersicht mit Filter nach Sportart, Jahr und Kategorie; gleiche Anstiege (Start und Ende < 75 m) werden zusammengefasst, mit Bestzeit, VAM und Anzahl.
- Detail: Profil nach Steigung eingefärbt, Karte mit Hover-Marker, alle Versuche. Buttons "Als Segment speichern" und "Abschnitt anpassen".

**Segmente**
- Anlegen aus einem Anstieg oder im Profil einer Aktivität durch Ziehen mit der Maus.
- Treffer: Start und Ende < 25 m, ≥ 90 % der Strecke < 30 m; Vorfilter über die Bounding-Box.
- Neue Aktivitäten werden beim Öffnen der Segmentliste geprüft, neue Segmente sofort beim Anlegen.
- Detail: Karte, Profil, Zeitverlauf über alle Versuche, Rangliste mit Tempo und Rückstand; Umbenennen, neu prüfen, löschen.

**Getestet in der Sandbox:** alle Seiten mit echten FIT-Dateien. Ein Trail-Lauf mit 968 Hm auf 5,75 km wird als HC erkannt. Zwei identische Läufe werden zum selben Anstieg gruppiert und als zwei Versuche desselben Segments gefunden. Die Kartenkacheln sind in der Sandbox gesperrt, die Linien werden trotzdem gezeichnet.

**Einspielen**
1. ZIP ins Runalyze-Verzeichnis entpacken.
2. `php bin/console doctrine:migrations:migrate --no-interaction -e prod` (legt 4 Tabellen an).
3. `rm -rf var/cache/prod`
4. Tools → Anstiege öffnen und die Erstanalyse laufen lassen. Bei einigen tausend Aktivitäten dauert das ein paar Minuten, das Fenster muss dabei offen bleiben.

**Grenzen**
- Die erste Heatmap-Berechnung liest alle Routen; bei sehr vielen Aktivitäten dauert das einmalig länger.
- Wird eine Aktivität nachträglich bearbeitet (z. B. Höhenkorrektur), werden ihre Anstiege erst nach "Alle Anstiege neu berechnen" aktualisiert.

## Nachtrag: fehlende Tabellen und Farben (Commit `3ce72ce8`, `runalyze-fix10.zip`)

- **Fehler "Table runalyze43_climb_scan doesn't exist":** Die Migration war auf dem Server nicht gelaufen. Anstiege und Segmente legen ihre 4 Tabellen jetzt beim ersten Aufruf selbst an (`RouteAnalysisSchema::ensureTables`). Die Migration bleibt als regulärer Weg erhalten. In der Sandbox getestet: Tabellen gelöscht, Seiten aufgerufen, Tabellen waren wieder da, und alles lief.
- **Farben:** Vorgabe ist, kein Rot/Grün zu verwenden, sondern Gelb/Blau mit hohem Kontrast.
  - Steigung: grau (bergab), gelb (< 5 %), hellblau (5–10 %), blau (10–15 %), dunkelblau (> 15 %).
  - Kategorien: 5 hellgelb, 4 gelb, 3 hellblau, 2 blau, 1 dunkelblau, HC schwarz.
  - Karte: Linie blau, Start gelb, Ende dunkelblau (je mit schwarzem Rand), Auswahl gelb-orange.
  - Heatmap-Sportarten: blau, gold, schwarz, cyan, violett, orange, magenta, grau.

## Nachtrag: einstellbare Farben und Trefferliste in der Heatmap (`runalyze-fix11.zip`)

- **Farben:** Neue Seite "Farben", erreichbar über den Menüpunkt oben rechts in Heatmap, Anstiegen und Segmenten.
  - Einstellbar: Steigungsstufen, Kategorien, Strecke, Start, Ende, Auswahl und die Farbe jeder Sportart in der Heatmap.
  - Live-Vorschau mit Profil, Kategorien und Legende.
  - Gespeichert pro Konto in `conf` (Kategorie `route_analysis`); "Standardfarben wiederherstellen" setzt auf Gelb/Blau zurück.
  - Die Textfarbe auf Kategorie-Plaketten wird je nach Helligkeit automatisch schwarz oder weiß.
- **Heatmap:** Ein Klick auf eine Strecke setzt einen Marker und zeigt rechts neben der Karte alle Aktivitäten an dieser Stelle.
  - Anklicken wählt aus: mehrere möglich, in der Auswahlfarbe mit schwarzem Rand.
  - Maus darüber zeigt die Strecke gestrichelt.
  - Buttons: "Nur diese in der Karte", "Alle auswählen", "Auswahl aufheben", "Auf Auswahl zoomen".
  - Das Symbol rechts öffnet die Aktivität.
- `runalyze-fix11.zip` enthält alle Änderungen seit fix9 und ersetzt fix10 (fix9 muss installiert sein).
