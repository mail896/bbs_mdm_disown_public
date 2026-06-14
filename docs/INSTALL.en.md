# Installation

This guide describes a fresh installation on your own server. It intentionally
uses generic placeholders. Real credentials, school names, domains, serial
numbers, and personal data must not be committed.

## Requirements

- Apache or another PHP-capable web server
- PHP 8.1 or newer
- PHP extensions: `mysqli`, `curl`, `json`, `openssl`, `mbstring`
- MariaDB or MySQL
- Composer
- shell access for cron jobs and file permissions
- IServ/OpenID Connect client for the admin area
- Jamf School API access
- Apple School Manager API access for ADE enrollments
- SMTP access for completion and admin notification mails

## Layout

Recommended separation:

```text
/var/www/example.org/disown        application code
/etc/disown/*.conf                 runtime secrets and local config
/var/lib/disown                    state files, for example notify state
/var/log/disown                    cron logs
```

The repository contains only example configuration files in
`config/*.example.conf`. Real config files live outside the webroot.

## 1. Deploy Code

```bash
cd /var/www/example.org
git clone https://github.com/example/bbs_mdm_disown_public.git disown
cd disown
composer install --no-dev --optimize-autoloader
```

For a development system:

```bash
cd /var/www/example.org
git clone https://github.com/example/bbs_mdm_disown_public.git disown-dev
cd disown-dev
composer install --no-dev --optimize-autoloader
```

The directory name `disown-dev` enables development mode. Jamf unenroll and
mail delivery are simulated there.

## 2. Run The Setup Helper

The helper does not write to `/etc`. It creates reviewable runtime config files
in `generated-config/` and prints the next commands.

```bash
./scripts/install.sh
```

Review the generated files before copying them to `/etc/disown`.

## 3. Runtime Config

Minimum files:

```text
/etc/disown/db.conf
/etc/disown/mail.conf
/etc/disown/jamf.conf
/etc/disown/notify.conf
/etc/disown/oidc.conf
```

For a separate development OIDC client:

```text
/etc/disown/oidc-dev.conf
```

For ADE enrollments:

```text
/etc/disown/asm.conf
/etc/disown/asm-jwt.py
/etc/disown/asm-private-key.pem
```

Recommended permissions:

```bash
chown -R root:root /etc/disown
chmod 750 /etc/disown
chmod 640 /etc/disown/*.conf
setfacl -m u:www-data:r /etc/disown/*.conf
setfacl -m u:www-data:rx /etc/disown/asm-jwt.py
```

## 4. Database

Example:

```sql
CREATE DATABASE disown CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'disown_app'@'localhost' IDENTIFIED BY 'change-me';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX ON disown.* TO 'disown_app'@'localhost';
FLUSH PRIVILEGES;
```

Run the available migrations:

```bash
php migrate_add_new_workflow_columns.php
php migrations/20260613_create_ade_enrollments.php
php migrations/20260613_add_jamf_state_to_ade_enrollments.php
```

A matching base `requests` table must exist before these migrations are useful.
If you migrate from an existing local deployment, import the database dump first.

## 5. IServ/OIDC

Recommended settings:

- Grant type: `Authorization code`
- Scopes: `openid`, `email`, `profile`, `iserv:roles`, optional `iserv:uuid`
- Redirect URIs:

```text
https://example.org/disown/oidc_callback.php
https://example.org/disown-dev/oidc_callback.php
```

Example roles:

```ini
OIDC_ALLOWED_ROLES="MDM_ADMINS"
OIDC_VIEWER_ROLES="MDM_VIEWERS"
```

Viewer users can read, filter, and export. Write actions are blocked server-side.

## 6. Web Server Protection

These paths must not be directly reachable from the web:

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

Either enable the provided `.htaccess` protections or configure equivalent
rules in your VirtualHost.

## 7. Cron Jobs

Admin notifications:

```cron
30 7,13 * * 1-5 www-data /usr/bin/php /var/www/example.org/disown/notify_admins.php >> /var/log/disown/notify.log 2>&1
```

ADE sync:

```cron
17 7,13 * * * www-data /usr/bin/php /var/www/example.org/disown-dev/sync_ade_enrollments.php --days=90 >> /var/log/disown/ade-sync-dev.log 2>&1
29 7,13 * * * www-data /usr/bin/php /var/www/example.org/disown/sync_ade_enrollments.php --days=90 >> /var/log/disown/ade-sync-prod.log 2>&1
```

## 8. Check

```bash
php scripts/check_requirements.php
```

The check validates PHP, extensions, Composer dependencies, runtime config,
database connection, and important paths.
