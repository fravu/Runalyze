# Massenbearbeitung (21.10.2026)

Nachbau der Massenbearbeitung von runalyze.com. Erreichbar über **Extras → Massenbearbeitung**, die Tools-Übersicht, das Auswertungs-Menü oben rechts in den Auswertungsseiten und die Schnellsuche.

## Bedienung
1. Filter: Sportart, Zeitraum, Titel enthält. Die Liste zeigt höchstens 300 Aktivitäten, die neuesten zuerst.
2. Oben im Rahmen "Für die ausgewählten Aktivitäten ändern" wählen, was geändert wird (leer = unverändert): **Sportart**, **Typ** (oder "kein Typ"), **Öffentlich** ja/nein, **Tag hinzufügen**, **Tag entfernen**.
3. Aktivitäten ankreuzen (Kopf-Häkchen wählt alle), "Änderungen speichern", Rückfrage bestätigen. Es wird in Portionen zu je 25 gespeichert, das Ergebnis und übersprungene Aktivitäten stehen darunter.

## Regeln
- Gespeichert wird wie im Aktivitäts-Editor: Entity ändern, `TrainingRepository::save()`, Cache der Aktivität leeren. Dadurch laufen die Neuberechnungen mit (beim Wechsel der Sportart z. B. wird die VO2max neu bestimmt, bei Sportarten ohne VO2max bleibt sie leer).
- Ein Aktivitätstyp gehört zu einer Sportart. Wechselt die Sportart und der alte Typ passt nicht mehr, wird der Typ entfernt. Wird ein Typ einer anderen Sportart gewählt, wird die Aktivität übersprungen und aufgelistet.
- Nur Aktivitäten, Sportarten, Typen und Tags des eigenen Kontos. Speichern geht nur per POST und Ajax. Höchstens 200 Aktivitäten pro Aufruf.
- **Ausrüstung** lässt sich hier nicht ändern, weil dafür Summen (Distanz, Dauer) mitgeführt werden. Dafür weiter den Editor nutzen.

## Code
- `src/CoreBundle/Component/Tool/Insights/BulkEditor.php`
- Aktionen `bulkEditAction` und `bulkEditApplyAction` in `InsightsController` (Routen `tools-bulk-edit`, `tools-bulk-edit-apply`).
- Template `app/Resources/views/tools/insights/bulk_edit.html.twig`.

## Getestet
- Schreibtest in einer Datenbank-Transaktion mit Rollback an einer echten Aktivität: Öffentlich umgedreht, Tag hinzugefügt und entfernt, Typ einer fremden Sportart abgelehnt, Typ der eigenen Sportart und "kein Typ" gesetzt, Sportart gewechselt (VO2max wurde neu bestimmt, Typ entfernt), unbekannte Aktivität und unbekannte Sportart abgewiesen. Nach dem Rollback waren alle Werte wie am Anfang.
- Seite mit Filterleiste, Auswahlfeldern und Liste im Browser angesehen (Änderungsbereich steht oberhalb der Tabelle, damit man nicht durch 300 Zeilen scrollen muss).
- Echte Änderungen an deinen Daten wurden beim Test **nicht** gespeichert. Eine fremde Aktivität konnte nicht getestet werden, weil in der Datenbank nur Aktivitäten dieses Kontos liegen. Die Prüfung auf das eigene Konto steht im Code (`getAccount()->getId()`).
