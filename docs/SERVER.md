# Server Notes

These notes describe a generic production-style deployment. Keep real hostnames,
user names, secrets, backup paths, and local ACL details out of the public
repository.

## Paths

Example layout:

```text
/var/www/example.org/disown
/var/www/example.org/disown-dev
/etc/disown
```

Runtime configuration should live outside the web root. `db.php` is intended to
read local configuration and should not contain production credentials.

## Apache Protection

Protect internal paths at the Apache/vHost level and with `.htaccess` where
appropriate:

```text
vendor/
config/
migrations/
scripts/
tools/
templates/
generated-config/
.git/
.codex/
.agents/
```

Pretty URLs used by the application:

```text
/disown/admin
/disown/audit
/disown/kuk
```

After Apache changes, validate and reload:

```bash
apachectl configtest
systemctl reload apache2
```

## Runtime Configuration

Example config directory:

```text
/etc/disown
```

Expected local config files:

```text
db.conf
jamf.conf
mail.conf
notify.conf
asm.conf
oidc.conf
```

File ownership and ACLs depend on the distribution and hosting model. The PHP
runtime user needs read access to the required config files; write access is not
needed for secrets.

## Cron

Example staggered sync jobs:

```cron
17 7,13 * * * webuser /usr/bin/php /var/www/example.org/disown-dev/sync_ade_enrollments.php --days=90 >> /var/log/disown/ade-sync-dev.log 2>&1
29 7,13 * * * webuser /usr/bin/php /var/www/example.org/disown/sync_ade_enrollments.php --days=90 >> /var/log/disown/ade-sync-prod.log 2>&1
43 6 * * * webuser /usr/bin/php /var/www/example.org/disown-dev/sync_kuk_devices.php >> /var/log/disown/kuk-sync-dev.log 2>&1
53 6 * * * webuser /usr/bin/php /var/www/example.org/disown/sync_kuk_devices.php >> /var/log/disown/kuk-sync-prod.log 2>&1
```

## Quick Checks

Public/student page:

```bash
curl -k -I https://example.org/disown/
```

Admin login page:

```bash
curl -k -I https://example.org/disown/admin
```

Internal paths should not be web-accessible:

```bash
curl -k -I https://example.org/disown/vendor/autoload.php
curl -k -I https://example.org/disown/config/notify.example.conf
curl -k -I https://example.org/disown/.git/config
```

CLI syntax checks:

```bash
php -l index.php
php -l admin.php
php -l ade.php
php -l kuk.php
php -l audit_log.php
php -l sync_ade_enrollments.php
php -l sync_kuk_devices.php
```

## OIDC

Use `config/oidc.example.conf` as a template. Keep the real client secret out of
Git.

Typical scopes:

```text
openid email profile iserv:roles iserv:uuid
```

Use separate OIDC clients or redirect URIs for production and development where
possible.

## Backups

Before production changes, create both:

- an application archive
- a database dump

Store backups outside the web root and protect them with restrictive file
permissions.
