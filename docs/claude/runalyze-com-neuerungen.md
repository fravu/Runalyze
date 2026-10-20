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

## Nachtrag 21.10.2026: Durchsicht von runalyze.com im Browser

Quelle: Changelog `https://runalyze.com/changelog` und die Tools-Seite im angemeldeten Konto. Das Konto ist ein kostenloses ohne Aktivitäten, deshalb ließen sich die Ansichten nicht mit Daten ansehen. Viele Tools sind dort als Premium gekennzeichnet.

### Neu seit der ersten Liste (23.09.2026)
- 21.10.: Strava-Historienimport.
- 06.10.: Originaldatei hochladen oder zusammenführen. Fehlende Werte (VO2max-Schätzung, Erholungszeit, Performance Condition, Training Effect, TRIMP, Schweißverlust, FTP, Developer-Felder) werden in bestehende Aktivitäten nachgetragen, automatisch bei gleicher Startzeit und Dauer oder per Auswahl.
- 03.10.: Hammerhead-Sync repariert. 28.09.: Polar-JSON-Import behält manuelle Runden.
- 22.09.: Leistungsmodelle mit wählbarem Referenzmaximum (gesamte Zeit, ab Datum, gleitendes Fenster, manuell).
- 18.09.: Streak-Ansicht mit Mindestdistanz. 09.09.: neue Garmin-Aktivitäten mit höherer Sync-Priorität.
- 17.09. und früher: MCP-Server (Alpha seit 18.05.) mit Aktivitätsdetails, Streckendaten, EPOC, geplanten Wettkämpfen, Developer-Feldern; Grok-Unterstützung.

### Weitere Neuerungen 2026 (Changelog)
- 05.05.: Redesign (Dark/Light, Seitenleiste mit Suche).
- 29.05.: neuer Performance Status. 22.07. und 29.07.: Übersicht aller bestiegenen Gipfel mit Karte. 25.08.: Climb Score auch für Abstiege.
- 18.08.: Mini-Kalender-Panel (Distanz, Dauer, TRIMP, aktive Tage), Streak-Statistik, Massenbearbeitung von Aktivitäten, Massenlöschen von Gesundheitsdaten. 03.09.: aktueller Streak im Kalender.
- 08.09.: Langzeit-Zonenanalyse, neue Statistik zum gleitenden Kilometerstand, öffentliches Tool Laktat-Stufentest-Analyse. 04.09.: Trendanalyse mit gleitendem Durchschnitt; Histogramm mit umkehrbarer Stapelreihenfolge. 22.08.: Tags aus der Trendanalyse ausschließen.
- 19.08.: geplante Wettkämpfe im Kalender; 12.06.: Wettkampfplanung mit Kartenlayout.
- 16.09.: Garmin-DSGVO-Export importieren (Schlaf, Gewicht, Ruhepuls, HRV, Blutdruck).
- 15.07. und 14.07.: Tool "Beste Teilabschnitte" mit Pace/Power-Diagramm, Splits-Diagramm.
- 12.06.: Tiles-Counter-Historie. 05.06.: neues Datenfeld relativer Höhengewinn, Zeitleiste für Krankheiten.
- 28.04.: Zepp/Amazfit-Sync. 06.03.: Zwift-Sync. 22.12.2025: Rouvy-Sync. 11.03.: geänderte Sporterkennung für FIT-Dateien.
- 26.08.: Magic-Login-Link. 24.08.: Körperzusammensetzung in absoluten Werten, Google-Werbung entfernt.

### Tools-Seite (Konto ohne Premium)
Premium: Wiederkehrende Strecken, Beste Segmente, Qualitätseinheiten, Tiles Counter, Persönliches Streckennetz, Streaks, Eddington-Zahl, VO2max-Faktoranalyse, Gleitende Durchschnittskilometer.
Frei: Histogramme, Trendanalyse, ANOVA, Verlaufsdiagramm, Marathonform, Running Performance Status, Form-Diagramm, Leistungskurven (Power, Pace, GAP, Puls), Wettkampf-Leistungsauswertung, Gesundheitstabelle, Erholungsstatus, VO2max-Analyse, Postergenerator, Jahresstatistiken, Lauftabellen.

### Bezug zum Fork
- Schon vorhanden bzw. ähnlich: Heatmap, Anstiege, Segmente (eigene Umsetzung), Trendanalyse, ANOVA, Poster, VO2max-Analyse.
- Gut nachbaubar: Streaks, Eddington-Zahl, gleitende Kilometer, Leistungskurven, Zusammenführen von Originaldateien, Massenbearbeitung.
- Nicht nachbaubar ohne Partnerzugang: Sync mit Polar, Suunto, Strava, Zepp usw. und der MCP-Dienst.
