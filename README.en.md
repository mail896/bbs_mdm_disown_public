# BBS MDM Disown

[Deutsche README](README.md)

Web application used by BBS Einbeck for iPad management around release requests, ADE enrollments, Jamf, and Apple School Manager.

The application reflects local MDM workflows: students submit release requests from an iPad Web Clip, the MDM team processes requests in the admin portal, reviews ADE enrollments, and documents relevant steps.

## Screenshots

### Desktop

![iPad Management desktop 01](images/Demo-iPad-Managemnt-BBS-01.png)

![iPad Management desktop 02](images/Demo-iPad-Managemnt-BBS-02.png)

![iPad Management desktop 03](images/Demo-iPad-Managemnt-BBS-03.png)

![iPad Management desktop 04](images/Demo-iPad-Managemnt-BBS-04.png)

### Mobile View

<p>
  <img src="images/Demo-iPad-Managemnt-BBS-mobile-01.jpeg" alt="iPad Management mobile 01" width="220">
  <img src="images/Demo-iPad-Managemnt-BBS-mobile-02.jpeg" alt="iPad Management mobile 02" width="220">
  <img src="images/Demo-iPad-Managemnt-BBS-mobile-03.jpeg" alt="iPad Management mobile 03" width="220">
</p>

### Web Clip on iPad

<p>
  <img src="images/WebClip%2000.jpeg" alt="Web Clip start 00" width="340">
  <img src="images/WebClip%2001.jpeg" alt="Web Clip start 01" width="340">
  <img src="images/WebClip%2002.jpeg" alt="Web Clip start 02" width="340">
</p>

## Web Clip / Student Page

Students enter the workflow through an iPad Web Clip. In normal operation, one shared Web Clip is deployed to all devices; Jamf/MDM expands the serial number variable.

Production:

```text
https://example.org/disown/?serial=%SerialNumber%
```

DEV/test:

```text
https://example.org/disown-dev/?serial=%SerialNumber%
```

The optional token feature in `config/app.example.conf` is prepared for special cases. In the default setup, keep `REQUIRE_SERIAL_TOKEN=0` so one mass-deployable Web Clip continues to work.

## Release Status

Current documented state:

```text
Version 1.7
Date: 13 June 2026
```

Important releases:

- 1.1: corrected workflow, private email, class, requested date, editable mail
- 1.2: bulk processing for Jamf, ASM, and mail
- 1.3: scheduled requests and admin notifications by cron
- 1.4: IServ OIDC for the admin area, authentication audit events, and favicon
- 1.5: ADE enrollments with ASM/Jamf correlation, filters, CSV export, and cron sync
- 1.6: login landing page, iPad management portal name, and read-only role
- 1.7: improved mobile layouts for admin, ADE enrollments, and audit log, including polished mobile mini cards for requests

## Features

- request submission by iPad serial number
- Jamf lookup by serial number
- warning text with mandatory confirmation before submission
- required class field, limited to six characters
- optional private email address
- requested release date, with past dates blocked
- duplicate protection for open requests with the same serial number
- admin portal with search, filters, and process display
- login landing page for IServ/OIDC authentication
- separate admin and read-only roles
- workflow: request -> Jamf -> ASM -> mail -> completed
- Jamf unenroll via API
- manual ASM/ADE confirmation
- mail preview with editable recipients, subject, and body
- mail delivery to school and/or private email address
- bulk processing for Jamf, ASM, and mail
- copyable ASM serial number list
- filters for open, completed, and scheduled requests
- responsive mobile view with compact mini cards for requests
- audit log with CSV export
- scheduled admin notifications by cron
- ADE enrollments overview with ASM/Jamf correlation, search, filters, and CSV export
- automatic ADE sync by cron for DEV and PROD
- development mode for tests without real Jamf or mail effects

## Production System

Production path:

```text
/var/www/example.org/disown
```

Production URL:

```text
https://example.org/disown/
```

Important paths:

```text
/disown/          student request / Web Clip entry
/disown/admin    admin portal
/disown/audit    audit log
/disown/ade.php  ADE enrollments
/disown/logout.php
```

The application runs on Apache/PHP and uses MySQL/MariaDB through `mysqli`.

## Development System

Development path:

```text
/var/www/example.org/disown-dev
```

Development URL:

```text
https://example.org/disown-dev/
```

Development mode is detected by the directory name `disown-dev`. In development mode, safety-critical actions such as Jamf unenroll and mail delivery are simulated.

## Workflow

### 1. Request

Requests are submitted through `index.php`. The iPad serial number is usually passed to the application by the Web Clip URL.

Before submitting the form, the student must confirm a warning that school-provided apps, profiles, and settings may be removed and that personal data must be backed up beforehand.

Stored request data:

- IServ username
- school email address
- device name
- serial number
- class
- requested release date
- optional private email address

### 2. Jamf

In the admin portal, the Jamf unenroll step can be executed for one request or as a bulk action. The Jamf integration is implemented in:

```text
jamf.php
```

After a successful Jamf unenroll, the request remains open and waits for the manual ASM step.

### 3. ASM/ADE

The ASM/ADE release is intentionally performed manually in Apple School Manager. The admin portal provides a copyable serial number list for this step.

After the release has been completed in ASM, the admin confirms the ASM step in the portal. Only then does the mail step become available.

### 4. Mail

After ASM confirmation, the completion mail can be sent. The mail dialog supports:

- selecting the school email address
- selecting the private email address, if available
- manually editing recipients
- manually editing the subject and message body

The request is marked as completed only after the mail has been sent successfully.

## Status Logic

The intended business process is:

```text
request
-> Jamf unenroll
-> manual ASM confirmation
-> mail sent
-> completed
```

Important fields in `requests`:

```text
jamf_unenrolled
jamf_unenrolled_at
asm_manual_done
asm_manual_done_at
mail_sent
mail_sent_at
status
completed_at
completed_by
private_email
class_name
requested_release_date
```

`status='erledigt'` is set only after the completion mail has been sent successfully.

## Bulk Processing

The admin portal supports bulk processing for requests that are currently at the same workflow step.

Supported bulk actions:

- Jamf for selected requests
- copy ASM list
- confirm ASM
- mail selected requests

Bulk buttons are enabled according to the next open workflow step. Mixed selections are not processed as the wrong step.

After bulk Jamf processing, the portal displays a comma-separated serial number list for ASM. The list can be pasted into Apple School Manager device search.

## Installation

Detailed installation notes:

```text
docs/INSTALL.en.md
```

For a fresh installation, two helper scripts are available:

```text
scripts/install.sh              generates local example runtime config files
scripts/check_requirements.php  checks PHP, extensions, config, and database
```

PHP dependencies are installed with Composer:

```bash
composer install --no-dev --optimize-autoloader
```

`vendor/` is intentionally not versioned in the repository.

## Scheduled Requests

Students can provide a requested release date.

The admin portal separates requests with a future release date as scheduled requests. Due requests are handled through the normal open workflow.

## Admin Notifications

The script `notify_admins.php` sends email notifications about requests due today.
Future scheduled requests do not trigger mail on their own; they are shown only as additional context when due requests exist.

Example cron entry:

```cron
30 7,13 * * 1-5 /usr/bin/php /var/www/example.org/disown/notify_admins.php >> /var/log/disown-notify.log 2>&1
```

The script sends mail only when due requests exist and the notification state has changed since the last run.

Useful options:

```bash
php notify_admins.php --preview
php notify_admins.php --test-mail
php notify_admins.php --mark-current
php notify_admins.php --force
```

The notification script does not modify requests. Jamf, ASM, and mail remain admin portal actions.

## ADE Enrollments

The `ade.php` page shows devices that were added to Apple School Manager/ADE or updated recently. It is a read-only admin page and does not modify devices or requests.

The data is combined from two sources:

- Apple School Manager: serial number, added date, updated date, model, order number, MDM assignment
- Jamf School: device name, asset tag, owner, model, enrollment/trash state

The page provides:

- search by serial number, name, asset tag, owner, or order
- filters for all, missing in Jamf, trash, enrolled, and recently updated
- date range filters for 7, 30, 90, 365 days, or all data
- CSV export

The sync runs as a CLI script:

```bash
php sync_ade_enrollments.php --days=90
```

Example staggered cron jobs for DEV and PROD:

```cron
17 7,13 * * * webuser /usr/bin/php /var/www/example.org/disown-dev/sync_ade_enrollments.php --days=90 >> /var/log/disown/ade-sync-dev.log 2>&1
29 7,13 * * * webuser /usr/bin/php /var/www/example.org/disown/sync_ade_enrollments.php --days=90 >> /var/log/disown/ade-sync-prod.log 2>&1
```

The ADE sync reads ASM/Jamf and only writes to the local `ade_enrollments` table.

## Configuration

Sensitive configuration is stored outside the web root.

### Database

The local file `db.php` contains the database connection and is not versioned.

### Jamf

Path:

```text
/etc/disown/jamf.conf
```

Example:

```ini
JAMF_URL=...
JAMF_NETWORK_ID=...
JAMF_API_KEY=...
```

### Mail

Path:

```text
/etc/disown/mail.conf
```

The application uses PHPMailer.

### Notify

Path:

```text
/etc/disown/notify.conf
```

Example file in the repository:

```text
config/notify.example.conf
```

### Apple School Manager API

Path:

```text
/etc/disown/asm.conf
```

The private key for the Apple School Manager API is stored outside the web root. The application uses it only for the read-only ADE sync.

### IServ OpenID Connect

The admin area can be protected with IServ/OpenID Connect. In production, OIDC
is intended for the admin portal, ADE enrollments, and the audit log.

IServ issuer:

```text
https://mein-iserv.de
```

Redirect URIs for the IServ SSO client:

```text
https://example.org/disown/oidc_callback.php
https://example.org/disown-dev/oidc_callback.php
```

Recommended scopes:

```text
openid email profile iserv:roles iserv:uuid
```

Recommended grant type:

```text
Authorization code
```

OIDC configuration is stored outside the web root:

```text
/etc/disown/oidc.conf
/etc/disown/oidc-dev.conf
```

Example file in the repository:

```text
config/oidc.example.conf
```

Never commit the client secret. Development and production can use separate
configuration files so OIDC can be tested in DEV first.

The application verifies the OIDC `id_token` against the provider's published
`jwks_uri`. The provider must issue signed ID tokens with `RS256` and expose
OpenID Connect metadata at `/.well-known/openid-configuration`.

The OIDC login supports two access levels:

- admin role: may execute Jamf, ASM, mail, bulk, and template actions
- viewer role: may read, filter, and export the admin portal, ADE enrollments, and audit log

Example roles:

```text
OIDC_ALLOWED_ROLES="MDM_ADMINS,ROLE_MDM_ADMINS"
OIDC_VIEWER_ROLES="MDM_VIEWERS"
```

Depending on the setup, IServ may emit roles with a `ROLE_` prefix in the token.
Add the admin and viewer roles in both forms when needed.

## Security

- admin portal and audit log are protected by IServ OpenID Connect
- OIDC `id_token` signatures are verified via provider JWKS and `RS256`
- write actions are blocked server-side for read-only users
- DEV and PROD can use separate OIDC configuration files
- Apache Basic Auth can be used as fallback/legacy protection, but is not the current target mode
- PHP writes successful logins, denied logins, login errors, and logout events to the audit log
- admin POST actions use CSRF checks
- database operations with user input use prepared statements
- HTML output is escaped
- Jamf, mail, notify, ASM, and OIDC configuration files are stored outside the web root
- `db.php`, SQL dumps, and backups are not versioned
- admin actions are written to the audit log
- Linux file permissions and ACLs can additionally exclude local users from the project

For a public repository:

- do not commit real credentials
- do not publish real student data or real serial numbers in screenshots
- keep example configuration files anonymous
- scan the Git history for secrets before publication

## Audit Log

The audit log stores relevant admin actions in the table:

```text
request_audit_log
```

Typical actions:

- `JAMF_UNENROLL_SUCCESS`
- `JAMF_UNENROLL_FAILED`
- `ASM_MANUAL_DONE`
- `MAIL_SENT`
- `MAIL_FAILED`
- `TEMPLATE_UPDATED`
- `AUTH_LOGIN_SUCCESS`
- `AUTH_LOGIN_DENIED`
- `AUTH_LOGIN_ERROR`
- `AUTH_LOGOUT`
- bulk actions

The audit page provides filters and CSV export.

## Backup

Before production changes, create a backup.

On the server, this is handled by:

```bash
backup-disown.sh
```

The script creates:

- code archive without `vendor` and `.git`
- database dump

Backups are stored outside the web root.

## Git Workflow

Recommended workflow:

```bash
git status
git pull
```

Before production deployment:

```bash
php -l index.php
php -l admin.php
php -l audit_log.php
php -l ade.php
php -l ade_api.php
php -l jamf.php
php -l notify_admins.php
php -l sync_ade_enrollments.php
php -l logout.php
```

Then:

```bash
git diff
git add <files>
git commit -m "Short description"
git push
```

## Project Files

Important files:

```text
index.php                         student Web Clip and request form
admin.php                         admin portal
audit_log.php                     audit log
ade.php                           ADE enrollments
ade_api.php                       ASM/Jamf helpers for ADE enrollments
jamf.php                          Jamf API integration
notify_admins.php                 admin notification script
sync_ade_enrollments.php          CLI sync for ADE enrollments
auth.php                          authentication and OIDC helpers
oidc_callback.php                 OIDC callback
logout.php                        logout page
templates/mail_release.txt        mail template
config/notify.example.conf        example notify configuration
config/oidc.example.conf          example OIDC configuration
config/db.example.conf            example database configuration
config/app.example.conf           example app / Web Clip token configuration
config/mail.example.conf          example SMTP configuration
config/jamf.example.conf          example Jamf configuration
config/asm.example.conf           example ASM configuration
docs/INSTALL.en.md                installation guide
scripts/install.sh                interactive setup helper without /etc writes
scripts/check_requirements.php    installation and runtime check
scripts/generate_webclip_token.php generate a Web Clip token for one serial number
images/                           anonymized screenshots
```

## Operational Notes

This application processes personal data and device information. Access, backups, screenshots, and CSV exports must be handled carefully.

CSV files exported from the admin portal or audit log should not be shared without protection or stored in the web root.
