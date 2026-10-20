# Design: obere Leiste, Icon-Leiste links, größere Schrift, ruhigere Tabellen (21.10.2026)

Vorbild ist das neue Design von runalyze.com (siehe `runalyze-com-neuerungen.md`). Umgesetzt wurde bewusst nur ein Teil, und zwar ohne Less/gulp-Build.

## Was sich ändert
- **Obere Leiste** (54 px): links das Logo, in der Mitte ein **Suchfeld**, rechts runde Icons für **Neu** (+), **Hilfe** (Buch), **Benachrichtigungen** und **Profil** (gelber Kreis). Diese Menüs öffnen sich beim Darüberfahren und per Klick (Klick daneben schließt). Zwischen Icon und Menü darf keine Lücke sein, sonst geht das Menü beim Hinfahren mit der Maus zu (statt Abstand deshalb ein transparenter Rand). Das Profil ist ein gelber Kreis mit dunklem Symbol.
- **Suchfeld:** "Suche nach Tools, Einstellungen oder Seiten …". Es durchsucht alle Menüeinträge (Name und Menüname), zeigt bis zu 8 Treffer, Pfeiltasten und Enter wählen, Escape leert. Die Taste `/` springt ins Feld. Ein Treffer löst einen Klick auf den echten Menülink aus, die Fenster öffnen sich also wie sonst. Die Aktivitätssuche (Lupe links) bleibt davon getrennt.
- **Linke Icon-Leiste** (56 px): **Haus** (Start, mit gelbem Rand wenn man auf dem Dashboard ist), Konfiguration, Tools, Extras, Aktivitäten suchen. Fährt man mit der Maus über ein Icon, erscheint daneben eine **Spalte in voller Höhe** (280 px) mit Überschrift und den Einträgen. Die Leiste selbst wird nicht breiter. Auf Touch-Geräten öffnet ein Tipp die Spalte.
- **Schrift:** 13 px statt 11 px, Systemschrift (Segoe UI/Roboto/…) statt Verdana, Zeilenhöhe 1,45.
- **Flaches Layout statt Kacheln:** Kein Hintergrundbild mehr (die Einstellung für das Hintergrundbild greift auf dem Dashboard nicht mehr), stattdessen ein ruhiger hellblauer Grund (`--rz-page-bg`). Die Panels im Dashboard haben keinen Rahmen, keinen Schatten und keine Rundung, sondern nur eine feine Linie unter der Überschrift und Abstand dazwischen. Die rechte Spalte ist durch eine senkrechte Linie (2 px) abgesetzt. Die Farbe der Trennlinien steht in `--rz-divider` (#8a99b8), die Linie unter den Überschriften nutzt dieselbe Farbe. Gilt nur für Panels in `#container`, Fenster (Overlays) behalten ihren Rahmen.
- **Panels in Fenstern:** abgerundet (10 px), dezenter Schatten, Überschriften dunkelblau statt dunkelrot.
- **Kalender-Scrollleiste:** Das Icon-Menü rechts oben im Kalender ragte ohne Rand 1 px heraus und erzeugte eine waagerechte Scrollleiste. Gelöst mit `margin-right: 1px` auf `.panel-menu`.
- **Tabellen:** mehr Innenabstand, Kopfzeile fett mit kräftiger Linie, Zebrastreifen in hellem Blau, Hover-Zeile hellblau.
- **Farben:** Leiste dunkelblau (#14213d), Spalte etwas heller (#1d3157), Akzent gelb (#fcbf49), kein Rot/Grün. Alle Werte sind CSS-Variablen am Anfang von `runalyze-sidebar.css` (`:root`).
- **Tools:** Der Link zur Übersicht heißt "Alle Tools" im Tools-Untermenü (die Beschriftung selbst öffnet in der Leiste nur die Spalte).

## Wie es technisch funktioniert
- Das Menü steht weiter an **zwei Stellen** im Markup: `app/Resources/views/base_logged_in.twig` (alle Symfony-Seiten) und `inc/tpl/tpl.Frontend.header.php` (Dashboard).
- `web/assets/js/runalyze-sidebar.js` verschiebt beim Laden (nur ab 801 px Breite) die linken Menüs (Konfiguration, Tools, Extras) und den Lupen-Link aus `#headline` in eine neue Leiste `#rz-sidebar`, baut das Haus und das Suchfeld ein und markiert die rechten Menüs per Klasse (`rz-plus`, `rz-help`, `rz-notify`, `rz-user`), damit CSS sie ordnet. Beim Verkleinern unter 801 px wird alles zurückgesetzt, das mobile Menü bleibt unverändert.
- `web/assets/css/runalyze-sidebar.css` gestaltet obere Leiste, Icon-Leiste, Spalte, Suchfeld, Schrift, Panels und Tabellen.
- Eingebunden in `base.html.twig` und `tpl.Frontend.header.php`, mit dem Änderungsdatum der Datei als Version (kein Cache-Leeren im Browser nötig).

## Grenzen
- Nur ab 801 px Breite und nur für Seiten mit `body#home` (alle Seiten nach dem Login).
- Das Schriftbild betrifft die ganze Seite. Wo Spalten feste Breiten haben (Kalender, Wochenübersicht), kann es enger werden. Dann `--rz-font-size` verkleinern.
- Dark Mode, Premium-/Erfolge-Symbole und der Karten-Look von runalyze.com gibt es nicht. Die Suche kennt nur die Menüeinträge, nicht Einstellungen im Inneren der Fenster.
- Einige Beschriftungen in den Menüs sind weiter Englisch (z. B. "Privacy settings"), weil die deutsche Übersetzung fehlt.

## Rückgängig
`git revert <commit>`. Danach `rm -rf var/cache/prod`.
