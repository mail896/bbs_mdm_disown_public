# Server notes

These notes document production-specific setup that is intentionally not fully
represented in Git.

## Paths

- Production app: `/var/www/sicher.bbs-einbeck.de/disown`
- Development app: `/var/www/sicher.bbs-einbeck.de/disown-dev`
- Public repository working copy: `/var/www/sicher.bbs-einbeck.de/disown-public`
- Runtime config: `/etc/disown`
- Backups: `/root/disown-backups`

For Codex work, open the workspace at:

```bash
/var/www/sicher.bbs-einbeck.de
```

This keeps production, development, and the public repository in one workspace
and avoids temporary export directories under `/tmp`.

## Runtime configuration

Database credentials are stored outside the webroot:

```bash
/etc/disown/db.conf
```

The file must be readable by the PHP/Apache user and by the maintenance user:

```bash
setfacl -m u:www-data:r /etc/disown/db.conf
setfacl -m u:marc:r /etc/disown/db.conf
```

Expected ACL shape:

```text
user:www-data:r--
user:marc:r--
other::---
```

`db.php` is a local runtime file and is ignored by Git. It should read
`/etc/disown/db.conf` and must not contain production credentials as a fallback.

## Apache protection

The Apache vHost protects internal paths with `<Directory>` rules in:

```bash
/etc/apache2/sites-enabled/sicher.bbs-einbeck.de.conf
```

Production paths that must not be web-accessible:

- `/var/www/sicher.bbs-einbeck.de/disown/vendor`
- `/var/www/sicher.bbs-einbeck.de/disown/config`
- `/var/www/sicher.bbs-einbeck.de/disown/.git`
- `/var/www/sicher.bbs-einbeck.de/disown/.codex`
- `/var/www/sicher.bbs-einbeck.de/disown/.agents`
- `/var/www/sicher.bbs-einbeck.de/disown/templates`

Development paths should use the same protection:

- `/var/www/sicher.bbs-einbeck.de/disown-dev/vendor`
- `/var/www/sicher.bbs-einbeck.de/disown-dev/config`
- `/var/www/sicher.bbs-einbeck.de/disown-dev/.git`
- `/var/www/sicher.bbs-einbeck.de/disown-dev/.codex`
- `/var/www/sicher.bbs-einbeck.de/disown-dev/.agents`
- `/var/www/sicher.bbs-einbeck.de/disown-dev/templates`

The public repository working copy is only a local Git workspace. It is located
under the webroot for convenience, but its directory permissions intentionally
prevent web access. A request to `/disown-public/` should return `403 Forbidden`.

## Local user restrictions

The local Linux user `kloke` is intentionally excluded from all Disown working
copies:

- `/var/www/sicher.bbs-einbeck.de/disown`
- `/var/www/sicher.bbs-einbeck.de/disown-dev`
- `/var/www/sicher.bbs-einbeck.de/disown-public`

Use explicit ACLs so the restriction survives later group permission changes:

```bash
setfacl -R -m u:kloke:--- /var/www/sicher.bbs-einbeck.de/disown
setfacl -R -m u:kloke:--- /var/www/sicher.bbs-einbeck.de/disown-dev
setfacl -R -m u:kloke:--- /var/www/sicher.bbs-einbeck.de/disown-public

find /var/www/sicher.bbs-einbeck.de/disown /var/www/sicher.bbs-einbeck.de/disown-dev /var/www/sicher.bbs-einbeck.de/disown-public \
  -type d -exec setfacl -m d:u:kloke:--- {} +
```

After Apache changes:

```bash
apachectl configtest && systemctl reload apache2
```

## Quick checks

Public app should return `200 OK`:

```bash
curl -k -I https://sicher.bbs-einbeck.de/disown/
```

Admin should request Basic Auth and return `401 Unauthorized` without login:

```bash
curl -k -I https://sicher.bbs-einbeck.de/disown/admin
```

Internal paths should return `403 Forbidden`:

```bash
curl -k -I https://sicher.bbs-einbeck.de/disown/db.php
curl -k -I https://sicher.bbs-einbeck.de/disown/vendor/autoload.php
curl -k -I https://sicher.bbs-einbeck.de/disown/config/notify.example.conf
curl -k -I https://sicher.bbs-einbeck.de/disown/.git/config
```

CLI DB check:

```bash
php -r 'require "db.php"; echo $mysqli->query("SELECT COUNT(*) FROM requests")->fetch_row()[0], "\n";'
```

If the browser shows `DB-Konfiguration nicht lesbar.`, check the ACL on
`/etc/disown/db.conf`, especially `u:www-data:r`.

## IServ OIDC login

The app can use IServ OpenID Connect for the admin area. The production and
development instances use separate config files so OIDC can be tested in DEV
without switching PROD:

```bash
/etc/disown/oidc.conf
/etc/disown/oidc-dev.conf
```

Use `config/oidc.example.conf` as the template. Keep the real client secret out
of Git.

The IServ issuer is:

```text
https://bbs-einbeck.de
```

The IServ SSO client needs these redirect URIs:

```text
https://sicher.bbs-einbeck.de/disown/oidc_callback.php
https://sicher.bbs-einbeck.de/disown-dev/oidc_callback.php
```

Recommended scopes:

```text
openid email profile iserv:roles iserv:uuid
```

Recommended grant type:

```text
Authorization code
```

Recommended role split:

```text
OIDC_ALLOWED_ROLES="IPAD_MDM_ADMINS,ROLE_IPAD_MDM_ADMINS"
OIDC_VIEWER_ROLES="IPAD_MDM_VIEWERS"
```

Users with the viewer role can read, filter, and export the admin portal,
ADE enrollments, and audit log. Write actions remain server-side blocked.

Suggested file permissions:

```bash
chown nobody:nogroup /etc/disown/oidc.conf /etc/disown/oidc-dev.conf
chmod 640 /etc/disown/oidc.conf /etc/disown/oidc-dev.conf
setfacl -m u:www-data:r,u:marc:r /etc/disown/oidc.conf /etc/disown/oidc-dev.conf
```

## Vendor directory

`vendor/` is created by Composer and contains third-party PHP dependencies.
For this project it includes PHPMailer and Composer's autoloader. The app needs
these files on disk, but they should never be directly reachable from the web.

`vendor/` is ignored by Git and must be restored by Composer or copied as part
of the server deployment process.

## Backups

Before production changes, run:

```bash
sudo -n /usr/local/sbin/backup-disown.sh
```

The backup script stores a code archive and a database dump in
`/root/disown-backups`.
