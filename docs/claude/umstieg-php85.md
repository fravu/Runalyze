# Umstieg PHP 7.4 → 8.5 (Runalyze, runalyze.fv0.de)

## Ausgangslage
- Die Sandbox läuft mit PHP 8.4.19. Auf dieser Version wurde alles bisher getestet: Dashboard, Import, Multi-Editor, Garmin-Sync, Rechenspiele, Heatmap, Anstiege und Segmente.
- Für PHP 8 bereits behoben: Signatur in PDOforRunalyze, verschachtelter Ternary im Rechenspiele-Panel, TypeError im FIT-Parser.
- PHP 8.5 selbst ist nicht getestet. 8.5 bringt vor allem neue Deprecations; die erscheinen nur im Log und brechen nichts ab. Restrisiko: Seiten, die bisher nie aufgerufen wurden, können unter PHP 8 noch TypeErrors werfen.
- `utf8_encode` (Plot-Export) ist seit 8.2 als veraltet markiert, funktioniert aber noch.

## Schritte
1. Backup: Verzeichnis kopieren, Datenbank sichern (`mysqldump runalyze_backup > runalyze-$(date +%F).sql`).
2. PHP 8.5 mit Erweiterungen installieren:
   `apt install php8.5-cli php8.5-fpm php8.5-mysql php8.5-xml php8.5-mbstring php8.5-intl php8.5-gd php8.5-zip php8.5-curl php8.5-sqlite3`
   (bei Apache mit mod_php: `libapache2-mod-php8.5` statt `-fpm`)
3. php.ini-Werte aus 7.4 übernehmen: `memory_limit`, `upload_max_filesize`, `post_max_size`, `max_execution_time`, `date.timezone`
   (alt: `/etc/php/7.4/fpm/php.ini`, neu: `/etc/php/8.5/fpm/php.ini`).
4. Umschalten:
   - FPM: im vHost bzw. in der Pool-Konfiguration den Socket `php7.4-fpm.sock` → `php8.5-fpm.sock`, dann `systemctl reload nginx` bzw. `apache2`
   - Apache mit mod_php: `a2dismod php7.4 && a2enmod php8.5 && systemctl restart apache2`
   - CLI: `update-alternatives --set php /usr/bin/php8.5`
5. `rm -rf var/cache/prod var/cache/doctrine` und `data/cache-/*/*.txt` löschen.
6. Testen: Dashboard, Aktivität öffnen und bearbeiten, Import, Garmin-Sync, Tools. Dabei `var/logs/prod.log` auf `CRITICAL` prüfen.
7. Rückweg: Socket bzw. Modul wieder auf 7.4 umstellen. 7.4 bleibt installiert.
