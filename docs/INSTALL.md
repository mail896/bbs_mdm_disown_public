# Installation

Diese Anleitung beschreibt eine neue Installation auf einem eigenen Server. Sie
ist bewusst generisch gehalten: echte Zugangsdaten, Schulnamen, Domains und
Seriennummern gehoeren nicht ins Repository.

## Voraussetzungen

- Apache oder ein anderer PHP-faehiger Webserver
- PHP 8.1 oder neuer
- PHP-Erweiterungen: `mysqli`, `curl`, `json`, `openssl`, `mbstring`
- MariaDB oder MySQL
- Composer
- Shell-Zugriff fuer Cronjobs und Rechtepflege
- IServ/OpenID-Connect-Client fuer das Adminportal
- Jamf-School-API-Zugang
- Apple-School-Manager-API-Zugang fuer ADE-Aufnahmen
- SMTP-Zugang fuer Abschluss- und Admin-Mails

## Grundidee

Die Anwendung erwartet Code und sensible Konfiguration getrennt:

```text
/var/www/example.org/disown        Anwendungscode
/etc/disown/*.conf                 Zugangsdaten und lokale Konfiguration
/var/lib/disown                    Statusdateien, zum Beispiel Notify-State
/var/log/disown                    Cron-Logs
```

Das Repository enthaelt nur Beispielkonfigurationen unter `config/*.example.conf`.
Echte Dateien liegen ausserhalb des Webroots.

## 1. Code bereitstellen

Beispiel:

```bash
cd /var/www/example.org
git clone https://github.com/example/bbs_mdm_disown_public.git disown
cd disown
composer install --no-dev --optimize-autoloader
```

Wenn ein Entwicklungssystem gewuenscht ist:

```bash
cd /var/www/example.org
git clone https://github.com/example/bbs_mdm_disown_public.git disown-dev
cd disown-dev
composer install --no-dev --optimize-autoloader
```

Der Ordnername `disown-dev` aktiviert den DEV-Modus. Dort werden Jamf-Unenroll
und Mailversand simuliert.

## 2. Setup-Assistent ausfuehren

Der Assistent schreibt keine Dateien nach `/etc`. Er erzeugt lokale Vorschlaege
unter `generated-config/` und zeigt die naechsten Kommandos an.

```bash
./scripts/install.sh
```

Die generierten Dateien danach pruefen und als root nach `/etc/disown` kopieren.

## 3. Runtime-Konfiguration anlegen

Minimal benoetigt:

```text
/etc/disown/db.conf
/etc/disown/mail.conf
/etc/disown/jamf.conf
/etc/disown/notify.conf
/etc/disown/oidc.conf
```

Fuer DEV mit eigener OIDC-Konfiguration:

```text
/etc/disown/oidc-dev.conf
```

Fuer ADE-Aufnahmen zusaetzlich:

```text
/etc/disown/asm.conf
/etc/disown/asm-jwt.py
/etc/disown/asm-private-key.pem
```

Empfohlene Rechte:

```bash
chown -R root:root /etc/disown
chmod 750 /etc/disown
chmod 640 /etc/disown/*.conf
setfacl -m u:www-data:r /etc/disown/*.conf
setfacl -m u:www-data:rx /etc/disown/asm-jwt.py
```

Den Wartungsbenutzer bei Bedarf ebenfalls per ACL berechtigen.

## 4. Datenbank vorbereiten

Beispiel:

```sql
CREATE DATABASE disown CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'disown_app'@'localhost' IDENTIFIED BY 'change-me';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX ON disown.* TO 'disown_app'@'localhost';
FLUSH PRIVILEGES;
```

Danach die vorhandenen Migrationen ausfuehren:

```bash
php migrate_add_new_workflow_columns.php
php migrations/20260613_create_ade_enrollments.php
php migrations/20260613_add_jamf_state_to_ade_enrollments.php
```

Bei einer Neuinstallation muss vorher eine passende Basistabelle `requests`
existieren. Falls die Anwendung aus einer bestehenden lokalen Installation
uebernommen wird, sollte der Datenbankdump vor den Migrationen importiert werden.

## 5. IServ/OIDC konfigurieren

Im IServ-SSO-Client:

- Grant Type: `Authorization code`
- Scopes: `openid`, `email`, `profile`, `iserv:roles`, optional `iserv:uuid`
- Redirect URIs:

```text
https://example.org/disown/oidc_callback.php
https://example.org/disown-dev/oidc_callback.php
```

Beispielrollen:

```ini
OIDC_ALLOWED_ROLES="MDM_ADMINS"
OIDC_VIEWER_ROLES="MDM_VIEWERS"
```

Viewer duerfen lesen, filtern und exportieren. Schreibaktionen bleiben
serverseitig blockiert.

## 6. Webserver absichern

Diese Pfade duerfen nicht direkt aus dem Web erreichbar sein:

```text
.git
.codex
.agents
config
vendor
templates
generated-config
scripts
```

Im Repository liegen `.htaccess`-Dateien fuer einige interne Ordner. In Apache
muss `AllowOverride` dafuer passend erlaubt sein, oder die Regeln muessen im
VirtualHost direkt gesetzt werden.

## 7. Cronjobs

Admin-Benachrichtigung:

```cron
30 7,13 * * 1-5 www-data /usr/bin/php /var/www/example.org/disown/notify_admins.php >> /var/log/disown/notify.log 2>&1
```

ADE-Sync:

```cron
17 7,13 * * * www-data /usr/bin/php /var/www/example.org/disown-dev/sync_ade_enrollments.php --days=90 >> /var/log/disown/ade-sync-dev.log 2>&1
29 7,13 * * * www-data /usr/bin/php /var/www/example.org/disown/sync_ade_enrollments.php --days=90 >> /var/log/disown/ade-sync-prod.log 2>&1
```

## 8. Pruefen

Nach der Installation:

```bash
php scripts/check_requirements.php
```

Das Skript prueft PHP, Extensions, Composer-Abhaengigkeiten, lokale
Konfiguration, Datenbankverbindung und wichtige Pfade.

## 9. Erste Funktionstests

1. Studentenseite mit einer bekannten Seriennummer aufrufen.
2. Antrag im DEV stellen.
3. Adminportal per OIDC anmelden.
4. Jamf-Schritt in DEV simulieren.
5. ASM bestaetigen.
6. Mail in DEV simulieren.
7. ADE-Sync im DEV ausfuehren:

```bash
php sync_ade_enrollments.php --days=30
```

Erst danach PROD aktivieren.

## Hinweise

Diese Anwendung verarbeitet personenbezogene Daten und Geraeteinformationen.
Backups, Screenshots, CSV-Exporte und Logs sollten entsprechend geschuetzt
werden.
