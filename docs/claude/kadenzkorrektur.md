# Kadenzkorrektur (21.10.2026)

Nachbau des Import-Tools "Kadenzkorrektur" von runalyze.com. Erreichbar über **Extras → Kadenzkorrektur**, die Tools-Übersicht, das Auswertungs-Menü rechts oben in den Auswertungsseiten und die Schnellsuche.

## Was es tut
- Zeigt Aktivitäten einer Sportart, deren Kadenz vermutlich halbiert oder verdoppelt gespeichert wurde. Grenzen einstellbar ("zu niedrig bis", "zu hoch ab"), Standard für den Lauf: bis 50 und ab 110.
- Die Kadenz steht so in der Datenbank, wie das Gerät sie meldet. Beim Laufen ist das der Wert **pro Bein** (typisch etwa 80 für 160 Schritte pro Minute). Deshalb ist 150 beim Laufen meist doppelt gespeichert, 40 meist halbiert.
- Auswahl per Häkchen, dann "Ausgewählte korrigieren" (mit Rückfrage). Faktor × 2 für zu niedrige, ÷ 2 für zu hohe Werte.
- Geändert werden: `training.cadence`, die Kadenzkurve in `trackdata.cadence` und die aus der Kadenz abgeleitete `training.stride_length` (umgekehrt zum Faktor, höchstens 255 cm). Außerdem `edited`.
- Rückgängig: derselbe Weg mit dem entgegengesetzten Faktor. Kadenz und Kurve sind danach identisch, die Schrittlänge kann durch die Rundung um 1 cm abweichen.

## Code
- `src/CoreBundle/Component/Tool/Insights/CadenceCorrection.php`
- Aktionen `cadenceCorrectionAction` und `cadenceCorrectionApplyAction` in `InsightsController` (Routen `tools-cadence-correction`, `tools-cadence-correction-apply`). Das Speichern geht nur per POST und Ajax, nur für das eigene Konto, nur mit Faktor 2 oder 0,5.
- Template `app/Resources/views/tools/insights/cadence_correction.html.twig`.

## Getestet
- Schreibtest in einer Datenbank-Transaktion mit Rollback an einer echten Laufaktivität (Kadenz 75, Schrittlänge 101): × 2 ergab 150 und Schrittlänge 50, ÷ 2 danach wieder 75 und 100, Kadenzkurve exakt identisch. Ein fremdes Konto änderte nichts (0), Faktor 3 wurde abgelehnt. Nach dem Rollback waren alle Werte wie am Anfang.
- Seite mit Standardgrenzen: 7 Kandidaten für Laufen, 1 für Radfahren (Grenzen 40/100), 16 bei engen Grenzen 60/90. Keine Fehler im Log.
- Echte Korrekturen an deinen Daten wurden beim Test **nicht** gespeichert.
