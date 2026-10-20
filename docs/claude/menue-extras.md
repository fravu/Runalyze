# Menü: neuer Hauptpunkt "Extras" (21.10.2026)

Alles, was bisher unter Tools → "Weiteres" (Übersichtsseite `tools`) stand und nicht schon im Tools-Menü war, hat jetzt einen eigenen Hauptmenüpunkt rechts neben "Tools".

**Extras:** Heatmap, Anstiege, Segmente · Garmin Connect Sync, Multi-Editor · Datenbank-Export, -Import, -Bereinigung.

**Tools** behält Trendanalyse, ANOVA, VO2max-Analyse, Lauftabellen, Poster. Der Link "Weiteres" und der Trenner entfallen. Die Übersichtsseite ist weiter über die Überschrift "Tools" erreichbar.

## Wo das Menü steht
Es gibt **zwei** Stellen, beide müssen geändert werden:
- `app/Resources/views/base_logged_in.twig`: alle Symfony-Seiten.
- `inc/tpl/tpl.Frontend.header.php`: Dashboard (Legacy-Header).

Nach Änderungen: `rm -rf var/cache/prod`, im Browser Strg+F5.

Die Beschriftungen "Extras", "Heatmap", "Anstiege", "Segmente" stehen fest auf Deutsch im Template, wie schon "Karten & Anstiege" in `tools_list.html.twig`.
