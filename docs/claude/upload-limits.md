# Upload-Limits (21.10.2026)

Datei: `/etc/php/8.5/apache2/php.ini` (mod_php; gilt nicht für die CLI).

| Einstellung | vorher | jetzt |
|---|---|---|
| `upload_max_filesize` | 2M | 64M |
| `post_max_size` | 8M | 72M |

- `post_max_size` muss größer sein als `upload_max_filesize`, sonst wird der Upload trotzdem abgelehnt.
- Grund: ZIP-Archive mit mehreren FIT-Dateien passten nicht in 2 MB.
- Gesetzt per `sudo sed`, danach `apache2ctl configtest` und `systemctl reload apache2`.
- Backup der alten Datei: `/etc/php/8.5/apache2/php.ini.bak-upload`.
- Rückgängig: Werte in der `php.ini` zurücksetzen oder das Backup zurückkopieren, Apache neu laden.
- Nicht mit einer großen Datei getestet.
