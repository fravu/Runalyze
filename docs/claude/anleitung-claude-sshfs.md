# Claude Code auf dem PC, Runalyze-Server per SSH und sshfs

Claude Code läuft auf deinem PC. Den Server-Code siehst du über sshfs als lokalen Ordner. Befehle, die auf dem Server laufen müssen (php, mysql, Cache löschen, Logs), führt Claude per `ssh runalyze '…'` aus. Das regelt die `CLAUDE.md`.

## 1. SSH-Zugang mit Schlüssel (einmalig, auf dem PC)
```bash
ssh-keygen -t ed25519            # falls noch kein Schlüssel existiert
ssh-copy-id frank@runalyze.fv0.de
```
In `~/.ssh/config`:
```
Host runalyze
    HostName runalyze.fv0.de
    User frank
    ServerAliveInterval 15
```
Test: `ssh runalyze 'php -v'`.

## 2. Schreibrechte (einmalig, auf dem Server)
Die Dateien gehören www-data, und PHP legt laufend neue an (Cache, Logs, Import). ACLs geben deinem Benutzer zusätzlich volle Rechte, auch auf künftig neu angelegte Dateien:
```bash
sudo apt install acl
D=/var/www/runalyze-codeproducer198
sudo setfacl -R -m u:frank:rwX,u:www-data:rwX $D
sudo setfacl -R -d -m u:frank:rwX,u:www-data:rwX $D
```
Prüfen: `touch $D/var/test && rm $D/var/test && echo ok`. Danach den sshfs-Mount neu einhängen.

## 3. Mounten (auf dem PC)
```bash
sudo apt install sshfs
mkdir -p ~/runalyze-server
sshfs runalyze:/var/www/runalyze-codeproducer198 ~/runalyze-server -o reconnect,ServerAliveInterval=15,ServerAliveCountMax=3
```
Aushängen: `fusermount -u ~/runalyze-server`

## 4. Kontext hineinlegen und starten
```bash
cp CLAUDE.md ~/runalyze-server/CLAUDE.md
mkdir -p ~/runalyze-server/docs/claude
cp kontext/*.md ~/runalyze-server/docs/claude/
cd ~/runalyze-server
claude
```
(Der Webserver liefert nur `web/` aus. `CLAUDE.md` und `docs/` im Projektverzeichnis sind von außen nicht erreichbar.)

## Hinweise
- Vor größeren Änderungen ein Backup anlegen: `ssh runalyze 'mysqldump runalyze_backup > ~/runalyze-$(date +%F).sql'`.
- Über sshfs dauert das Durchsuchen von `vendor/` lange (Tausende Dateien); die `CLAUDE.md` sagt Claude, `vendor/` nicht zu durchsuchen.
- Die Claude-Sitzung auf dem PC ist eine neue Sitzung; das Wissen aus dieser Unterhaltung steckt in `docs/claude/`.
