# School MDM Disown

[Deutsche README](README.md)

Web application used by Example School to release school-managed iPads from Jamf and Apple School Manager.

The application reflects a local school workflow: students submit a release request from an iPad Web Clip, the MDM team processes the request in the admin portal, and every relevant step is documented.

## Screenshots

### Student Web Clip

![Web Clip start](images/WebClip%2000.jpeg)

![Web Clip request form](images/WebClip%2001.jpeg)

![Web Clip confirmation](images/WebClip%2002.jpeg)

### Admin Portal

![Admin portal overview](images/Demo01_dsgvo.jpg)

![Mail preview](images/Demo02_dsgvo.jpg)

## Features

- request submission by iPad serial number
- Jamf lookup by serial number
- warning text with mandatory confirmation before submission
- required class field, limited to six characters
- optional private email address
- requested release date, with past dates blocked
- duplicate protection for open requests with the same serial number
- admin portal with search, filters, and process display
- workflow: request -> Jamf -> ASM -> mail -> completed
- Jamf unenroll via API
- manual ASM/ADE confirmation
- mail preview with editable recipients, subject, and body
- mail delivery to school and/or private email address
- bulk processing for Jamf, ASM, and mail
- copyable ASM serial number list
- filters for open, completed, and scheduled requests
- audit log with CSV export
- scheduled admin notifications by cron
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

### IServ OpenID Connect

The admin area can be protected with IServ/OpenID Connect. In production, OIDC
is intended for both the admin portal and the audit log.

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

## Security

- admin portal and audit log are protected by IServ OpenID Connect
- DEV and PROD can use separate OIDC configuration files
- Apache Basic Auth can be used as fallback/legacy protection, but is not the current target mode for 1.4
- PHP writes successful logins, denied logins, login errors, and logout events to the audit log
- admin POST actions use CSRF checks
- database operations with user input use prepared statements
- HTML output is escaped
- Jamf, mail, notify, and OIDC configuration files are stored outside the web root
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

## Release Status

Current documented state:

```text
Version 1.4
Date: 12 June 2026
```

Important releases:

- 1.1: corrected workflow, private email, class, requested date, editable mail
- 1.2: bulk processing for Jamf, ASM, and mail
- 1.3: scheduled requests and admin notifications by cron
- 1.4: IServ OIDC for the admin area, authentication audit events, and favicon

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
php -l jamf.php
php -l notify_admins.php
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
jamf.php                          Jamf API integration
notify_admins.php                 admin notification script
auth.php                          authentication and OIDC helpers
oidc_callback.php                 OIDC callback
logout.php                        logout page
templates/mail_release.txt        mail template
config/notify.example.conf        example notify configuration
config/oidc.example.conf          example OIDC configuration
images/                           anonymized screenshots
```

## Operational Notes

This application processes personal data and device information. Access, backups, screenshots, and CSV exports must be handled carefully.

CSV files exported from the admin portal or audit log should not be shared without protection or stored in the web root.
