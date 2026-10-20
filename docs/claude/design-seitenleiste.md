# Design: Seitenleiste, größere Schrift, ruhigere Tabellen (21.10.2026)

Vorbild ist das neue Design von runalyze.com (siehe `runalyze-com-neuerungen.md`). Umgesetzt wurde bewusst nur ein Teil, und zwar ohne Less/gulp-Build.

## Was sich ändert
- **Linke Seitenleiste:** Die Menüs stehen nicht mehr oben, sondern links, schmal (56 px) mit Icons. Ein Klick auf ein Icon klappt die Leiste auf (248 px, mit Namen) und das zugehörige Untermenü darunter aus (Akkordeon). Der Pfeil oben klappt alles ein und aus. Ein Klick auf einen Eintrag, außerhalb oder Escape klappt die Leiste wieder zu. Beim Darüberfahren zeigt der Browser den Namen als Tooltip.
- **Oben** stehen Logo-Symbol, Ein-/Ausklappen, dann Konfiguration, Tools, Extras, Suche. **Unten** stehen Benutzer, Hilfe, Benachrichtigungen und "Neu".
- **Schrift:** 13 px statt 11 px, Systemschrift (Segoe UI/Roboto/…) statt Verdana, Zeilenhöhe 1,45.
- **Panels:** abgerundet (10 px), dezenter Schatten, Überschriften dunkelblau statt dunkelrot.
- **Tabellen:** mehr Innenabstand, Kopfzeile fett mit kräftiger Linie, Zebrastreifen in hellem Blau, Hover-Zeile hellblau.
- **Farben:** Leiste dunkelblau (#14213d), Akzent gelb (#fcbf49), kein Rot/Grün. Alle Werte sind CSS-Variablen am Anfang von `runalyze-sidebar.css` (`:root`).
- **Tools:** Der Link zur Übersicht heißt jetzt "Alle Tools" im Tools-Untermenü (die Beschriftung selbst öffnet in der Leiste nur das Untermenü).

## Dateien
- `web/assets/css/runalyze-sidebar.css` (neu, reines CSS).
- `web/assets/js/runalyze-sidebar.js` (neu, reines JavaScript, kein Bündel).
- Eingebunden in `app/Resources/views/base.html.twig` und `inc/tpl/tpl.Frontend.header.php`, jeweils nach dem Hauptstylesheet bzw. -skript. Die Versionsnummer ist das Änderungsdatum der Datei, ein neuer Stand landet also ohne Cache-Leeren im Browser.
- Das Menü selbst steht weiter in `base_logged_in.twig` und `tpl.Frontend.header.php`. Die Leiste ist nur CSS/JS über diesem Markup.

## Grenzen
- Gilt nur ab 801 px Breite. Darunter bleibt das bisherige mobile Menü.
- Es gilt nur für Seiten mit `body#home` (alle Seiten nach dem Login).
- Das Schriftbild betrifft die ganze Seite. Wo Spalten feste Breiten haben (Kalender, Wochenübersicht), kann es enger werden. Dann Schrift in `--rz-font-size` verkleinern.
- Dark Mode, globale Suche und Karten-Look wie auf runalyze.com gibt es noch nicht.

## Rückgängig
`git revert <commit>`. Danach `rm -rf var/cache/prod`.
