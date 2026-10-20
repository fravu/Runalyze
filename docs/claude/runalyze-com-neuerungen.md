# Neuerungen bei runalyze.com seit dem Open-Source-Stand (4.3, 2018)

Quelle: Websuche vom 23.09.2026. Changelog und Blog von runalyze.com waren aus der Sandbox nicht direkt abrufbar, die Liste ist deshalb möglicherweise unvollständig.

## Gesundheit / Wellness
- Überarbeiteter Health-Bereich (ab 2019/2020): Gewicht, Ruhepuls, Körperfett/-wasser/-muskel, Schlafdauer
- Sync der Gesundheitsdaten von Garmin, Polar, Suunto, Fitbit, Withings, Oura, Whoop, Zepp/Amazfit
- Tägliche HRV-Werte (Oura, Garmin), erweiterte HRV-Analyse (Premium)
- Diagramme für Blutdruck, Blutzucker, Körpertemperatur
- Import der Garmin-Historie aus dem DSGVO-Export: Aktivitäten, HRV, Blutdruck, Gewicht, Schlaf, Ruhepuls

## Analyse
- Climb Score mit Kategorien 5 bis 1 und HC, Übersicht aller Anstiege mit Karte
- Leistungskurven für Power, Pace und Puls pro Aktivität (Bestwerte für alle Zeitfenster)
- Running Effectiveness / Efficiency Index
- Wettkampfprognose mit mehreren Modellen (VDOT, CPP, Steffny, Cameron)
- Tool zur VO2max-Faktor-Analyse
- Streaks, Tiles-Zähler, Heatmaps über die gesamte Historie, Quality-Sessions-Ansicht, Bestzeiten für Segmente aus beliebigen Aktivitäten, gleitender Durchschnitt des Umfangs (meist Premium)
- Monotonie-Formel angepasst (04/2025)
- Workout-Runden zeigen den Intensitätstyp aus Garmin-FIT (05/2026)
- Tool zur Kadenzkorrektur (05/2026)

## Komfort / KI
- Automatische Routennamen aus den durchfahrenen Orten (Premium)
- Beta: Aktivitätstyp per Machine Learning erraten
- Neues Design mit Dark/Light Mode und besserer Mobil-Bedienung (2026)

## Schnittstellen
- Sync mit Suunto, Concept2 (FIT), Rouvy (02/2026), Zepp/Amazfit, Whoop
- Runtastic/Adidas-Integration eingestellt (06/2025)
- Neue API (05/2025), Widgets per Scriptable
- MCP-Server (Alpha, 05/2026)

## Einordnung für die eigene Instanz
- Vieles davon hängt an Diensten mit Partner-Zugang (Garmin Health API, Polar usw.). Das ist selbst gehostet nicht nachbaubar.
- Gut nachbaubar mit vorhandenen Daten: Leistungskurven (Power/Pace/HR), Climb Score/Anstiegsübersicht, Streaks, gleitender Durchschnitt, weitere Prognosemodelle, Kadenzkorrektur.
- Gesundheitsdaten: Ruhepuls, HRV, Schlaf und Gewicht wären über das inoffizielle `garminconnect`-Paket abholbar, genau wie der Aktivitäts-Sync.
- Der Fork von codeproducer198 hat einiges schon eigenständig umgesetzt: HR-Zonen aus FIT, Run/Walk, PacePro, Workout-Ziele, neue FIT-Felder.

## Quellen
- https://runalyze.com/changelog
- https://blog.runalyze.com/kategorie/changes/
- https://blog.runalyze.com/changes/runalyze-development-updates-4/
- https://blog.runalyze.com/changes/runalyze-development-updates-5/
- https://blog.runalyze.com/allgemein-en/runalyze-development-updates-6/
- https://blog.runalyze.com/features/runalyze-now-supports-daily-hrv-readings/
- https://blog.runalyze.com/allgemein-en/import-your-garmin-history/
- https://blog.runalyze.com/releases/the-new-runalyze-design-is-now-live/
- https://runalyze.com/help/article/features
- https://www.runifyapp.com/blog/trainingpeaks-vs-runalyze-2026
