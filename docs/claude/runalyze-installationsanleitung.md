# RUNALYZE lokal installieren (codeproducer198-Fork)

Diese Anleitung basiert auf dem, was in der Cloud-Sandbox tatsächlich getestet und zum Laufen gebracht wurde: `fravu/Runalyze`, Branch `master` (= Stand von `codeproducer198/Runalyze` + ein PHP8-Kompatibilitätsfix).

Wichtiger Vorteil dieses Forks: **`vendor/`, `web/vendor/` und die gebauten CSS/JS-Assets sind direkt im Git-Repo committed.** Du brauchst also normalerweise **kein** `composer install`, `npm install`, `bower install` oder `gulp` — einfach klonen und konfigurieren reicht.

## Voraussetzungen

- **PHP 7.4** (vom Autor empfohlen, siehe README) **oder PHP 8.x** (mit dem Fix aus Commit `0294a1ff`, sonst stürzt das Dashboard ab)
  - Extensions: `pdo_mysql`, `mysqli`, `gd`, `intl`, `zip`, `xml`, `redis`, `mbstring`
- **MySQL oder MariaDB**
- **Redis** (wird von SncRedisBundle/Bernard-Queue vorausgesetzt, läuft nicht ohne)
- **Apache** (mit `mod_rewrite`) oder **nginx** — für einen echten Betrieb, nicht nur zum Testen
- `git`

## 1. Repo klonen

```bash
git clone https://github.com/fravu/Runalyze.git
cd Runalyze
```

## 2. Konfigurationsdatei anlegen

Es gibt keine `data/config.yml.dist` — die Datei musst du selbst anlegen (sie ist in `.gitignore`, landet also nie im Repo):

```bash
cat > data/config.yml <<'EOF'
parameters:
    database_host: 127.0.0.1
    database_port: 3306
    database_name: runalyze
    database_user: runalyze
    database_password: HIER_EIGENES_PASSWORT_EINTRAGEN
    secret: HIER_EIGENEN_ZUFALLSSTRING_EINTRAGEN
    router.request_context.host: deine-domain-oder-localhost
    router.request_context.scheme: http
EOF
```

`secret` sollte ein langer, zufälliger String sein (z. B. `openssl rand -hex 32`). Alle weiteren möglichen Parameter (Mail, API-Keys für Wetter/Karten, Perl/Python-Pfade etc.) stehen mit Default-Werten in `app/config/default_config.yml` — die musst du nur überschreiben, wenn du sie brauchst.

## 3. Datenbank anlegen

```bash
mysql -u root -p -e "CREATE DATABASE runalyze CHARACTER SET utf8mb4; \
  CREATE USER 'runalyze'@'localhost' IDENTIFIED BY 'HIER_EIGENES_PASSWORT_EINTRAGEN'; \
  GRANT ALL PRIVILEGES ON runalyze.* TO 'runalyze'@'localhost'; FLUSH PRIVILEGES;"

mysql -u root -p runalyze < inc/install/structure.sql
```

Danach zur Sicherheit die Doctrine-Migrationen laufen lassen, falls `structure.sql` nicht ganz aktuell ist (im Repo liegen 59 Migrationsdateien unter `app/DoctrineMigrations/`):

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

## 4. Redis starten

```bash
sudo systemctl enable --now redis-server
```

(Unter macOS z. B. `brew services start redis`.)

## 5. Verzeichnisrechte

Der Webserver-User braucht Schreibrechte auf:

```bash
chmod -R 775 var/ data/
```

## 6. Webserver einrichten

**Docroot ist `web/`.** Wichtige Stolperfalle: Das Legacy-Skript `dashboard.php` wird per relativem Pfad `../dashboard.php` eingebunden — das funktioniert nur, wenn der PHP-Prozess mit Arbeitsverzeichnis `web/` läuft. Bei Apache/nginx mit `DocumentRoot`/`root` auf `web/` ist das automatisch der Fall.

### Apache (Beispiel-VHost)

```apache
<VirtualHost *:80>
    ServerName runalyze.local
    DocumentRoot /pfad/zu/Runalyze/web
    <Directory /pfad/zu/Runalyze/web>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

`web/app.php` ist der Produktions-Entry-Point, `web/app_dev.php` der Dev-Entry-Point (zeigt volle Fehler-Stacktraces, ist aber standardmäßig nur von `127.0.0.1`/`::1` erreichbar — praktisch zum Debuggen).

### Schneller Test ohne Apache/nginx (PHP Built-in-Server)

Nur zum Ausprobieren geeignet, nicht für Dauerbetrieb:

```bash
cd web
php -S 127.0.0.1:8080 app.php
```

**Wichtig:** unbedingt aus dem `web/`-Verzeichnis heraus starten (wegen des relativen Includes aus Schritt 6), sonst bekommst du eine leere Seite bzw. einen 500er.

Dann im Browser: `http://127.0.0.1:8080/`

## 7. Ersten Account anlegen

Am einfachsten über die normale Registrierungsseite (`/de/register` bzw. den Link auf der Login-Seite) — die App verschickt dabei ggf. eine Aktivierungsmail (SMTP-Konfiguration in `data/config.yml` nötig, sonst bleibt der Account bis zur manuellen Aktivierung in der DB inaktiv).

Alternative: Account direkt per SQL anlegen und sofort aktivieren (Passwort-Hashing-Schema der App: `sha256(trim(passwort) + salt)`):

```bash
php -r '
$salt = bin2hex(random_bytes(32));
$password = "DeinPasswort123!";
$hash = hash("sha256", trim($password).$salt);
echo "SALT=$salt\nHASH=$hash\n";
'
```

```sql
INSERT INTO runalyze_account
(username, name, mail, language, timezone, password, salt, registerdate, lastaction, allow_mails, allow_support, role, gender, birthyear, activation_hash)
VALUES
('admin', 'Admin', 'admin@example.com', 'de', 0, '<HASH aus dem PHP-Befehl>', '<SALT aus dem PHP-Befehl>', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 1, 0, 2, 0, NULL, NULL);
```

`role = 2` ist Administrator, `role = 1` normaler Nutzer. `activation_hash = NULL` bedeutet: Account ist sofort aktiv (kein Freischalt-Mail nötig).

## Bekannte Stolperfallen (aus der Sandbox-Erfahrung)

- **Leere Seite/500 beim Dashboard nach Login unter PHP 8**: Fix ist bereits in `master` (Commit `0294a1ff`) — `inc/system/class.PDOforRunalyze.php`.
- **Leere Seite ohne Fehlermeldung im Prod-Modus**: liegt fast immer am falschen Arbeitsverzeichnis (siehe Schritt 6) — im Zweifel testweise `web/app_dev.php` aufrufen, das zeigt echte Fehler.
- **Redis-Verbindung fehlt**: Container-Boot schlägt komplett fehl, wenn kein Redis erreichbar ist (`redis.default.dsn` in `app/config/expert_config.yml`).
- Optional in `data/config.yml`: `openweathermap_api_key`, `mapbox_api_key`, `thunderforest_api_key` etc. für Wetter-/Kartenfeatures — ohne die funktioniert die App trotzdem, nur ohne diese Zusatzfeatures.

## Offizielle Doku (Original-Projekt, nicht 1:1 auf diesen Fork zutreffend)

- Nutzer-FAQ: https://help.runalyze.com
- Admin/Entwickler-Doku: https://docs.runalyze.com
