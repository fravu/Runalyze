# Neue Auswertungen: Streaks, Eddington-Zahl, gleitende Kilometer, Leistungskurven (21.10.2026)

Nachbau von Funktionen der runalyze.com (siehe `runalyze-com-neuerungen.md`). Alle vier nur lesend, keine neuen Tabellen.

Erreichbar über das Menü **Extras**, die Tools-Übersicht ("Auswertungen") und die Schnellsuche oben.

| Seite | Route | Inhalt |
|---|---|---|
| Streaks | `/my/tools/streaks` | aktuelle und längste Serie aufeinanderfolgender Tage, Top 15, Filter Sportart und Mindest-Tagesdistanz |
| Eddington-Zahl | `/my/tools/eddington` | E, fehlende Tage bis E+1, Tabelle um E herum, E am Jahresende; km oder Meilen, Filter Sportart |
| Gleitende Kilometer | `/my/tools/moving-mileage` | Diagramm der Kilometer der letzten X Tage (7/30/90/180/365) pro Tag, Summe oder Ø pro Tag/Woche/Monat |
| Leistungskurven | `/my/tools/performance-curves` | Bestwerte über feste Zeitfenster für Leistung, Pace oder Herzfrequenz, Zeitraum 30 Tage bis gesamt |

## Code
- `src/CoreBundle/Controller/My/Tools/InsightsController.php`
- `src/CoreBundle/Component/Tool/Insights/DayStatistics.php` (Tageswerte, Streaks, Eddington, Tagesreihe)
- `src/CoreBundle/Component/Tool/Insights/PerformanceCurve.php` (Bestwerte aus Streckendaten)
- Templates unter `app/Resources/views/tools/insights/`; die Diagramme sind kleines Inline-SVG, kein Bibliothek.

## Festlegungen
- Ein "Tag" ist das lokale Datum (Startzeit + `timezone_offset`), Distanzen werden pro Tag über alle Aktivitäten der Sportart summiert.
- Streak: aktuelle Serie zählt, wenn sie heute oder gestern endet.
- **Leistungskurven:**
  - Streckendaten sind pipe-getrennt (`time` = kumulative Sekunden, `distance` = kumulative km), werden auf 1 Wert pro Sekunde umgerechnet.
  - **Leistung** zählt nur gemessen (`is_power_calculated = 0`). Von Runalyze berechnete Leistung lieferte Unsinn (z. B. 3682 W im Schnitt bei einer Motorradtour).
  - **Puls:** Werte unter 30 oder über 250 bpm werden ignoriert. Leistung über 2500 W ebenfalls (Messfehler).
  - **Pace:** nur Aktivitäten mit höchstens 25 km/h Durchschnitt (sonst zählen als Lauf gespeicherte Motorradfahrten, siehe `vorschlag-marathonform-ausschluss.md`), Fenster ab 30 s, Geschwindigkeiten über 30 km/h werden als GPS-Sprung verworfen.
  - Höchstens 120 Aktivitäten und 25 s Rechenzeit pro Aufruf, die Seite weist darauf hin.

## Getestet
Alle Seiten antworten fehlerfrei und schnell (unter 1 s, Pace über die gesamte Zeit 2 s). Streaks: 15 Tage als längste Serie (24.08. bis 07.09.2019), 1813 aktive Tage. Eddington (alle Sportarten): 130, für 131 fehlen 2 Tage. Laufpace über 365 Tage plausibel (60 min = 6:13/km).
