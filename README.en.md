# BBS Einbeck iPad Management

[Deutsche Version](README.md)

![Admin portal](images/Demo-iPad-Managemnt-BBS-01.png)

Web application for handling former student iPad returns and releases at BBS Einbeck. Version 2.3 adds a switchable email push watch for new requests; since version 2.0 DISOWN automates the workflow through Apple ADE/ASM release by using a local release broker.

## Status

- Version: `2.3`
- Date: `15 July 2026`
- Production path: `/var/www/sicher.bbs-einbeck.de/disown`
- Development path: `/var/www/sicher.bbs-einbeck.de/disown-dev`
- Public demo: sanitized variant without credentials and without local project-state files

## Features

- Self-service WebClip for release requests.
- Guard page for school-owned loaner/cart devices so they cannot be released accidentally.
- Admin portal with calmer auto-search, status filters, bulk workflow, metrics, rolling 12-month view and Jamf license estimate.
- Admin special release for defective devices by serial number, with explicit single-device review and without bypassing WebClip or bulk safeguards.
- Release broker status including ADE token expiration in the admin dashboard.
- Automated Jamf unenroll through the Jamf School API.
- Automated ASM/ADE release through the Apple School Manager Public API and a local NanoDEP release broker.
- Completion mail to school and private email addresses; partial mail failures are shown in red but the request can still be completed.
- Local clarification notes for individual devices.
- KUK device overview for staff iPads from Jamf School.
- ADE intake overview with ASM/ADE and Jamf School comparison.
- Audit log with dashboard, CSV export and recorded bulk serial-number lists.
- DEV mode with demo data and dry-run behavior for Jamf, ASM/ADE and mail.

## Screenshots

![Admin portal](images/disown-admin-portal.png)

![Admin portal mobile](images/disown-admin-mobile.png)

![Admin portal clarification cases](images/disown-admin-cases.png)

![ADE intake](images/disown-ade.png)

![KUK devices](images/disown-kuk.png)

![Audit log](images/disown-audit-log.png)

## Standard Workflow

1. The student submits a request through the WebClip.
2. An admin performs the Jamf unenroll.
3. The system assigns exactly this device to the release broker through the ASM Public API.
4. The local NanoDEP release broker performs the ADE/DEP disown call.
5. The system verifies that the broker can no longer access the device afterwards.
6. Completion mail is sent to all available recipient addresses.
7. The request is completed; partial failures and details are written to the audit log.

The legacy database column `asm_manual_done` is kept for compatibility. Since version 2.0 it means that the ASM/ADE release step has been completed, usually automatically.

## Admin Special Release

The rare special path for defective devices is placed at the bottom of the admin portal above the footer. Admins first check the serial number against Jamf, review the name and mail addresses, and then create only a local request. Jamf unenroll, ASM/ADE release and completion mail continue through the normal table row.

Only this admin-only path may intentionally bypass the school-device blocker. WebClip requests, normal requests and bulk processing remain protected against school-owned loaner/cart devices.

## Bulk Workflow

Bulk processing intentionally remains step-based:

1. Select requests.
2. Run `Jamf fuer Auswahl`.
3. The serial-number list is shown for copying and written to the audit log.
4. Run `ASM/ADE fuer Auswahl` to release the devices through the release broker.
5. Run `Mail fuer Auswahl` to send the prepared completion mails.

The selection is kept until the mail step has finished. This prevents admins from losing the selected devices between Jamf, ASM/ADE and mail.

## ASM/ADE Release Broker

Since version 2.0 DISOWN uses two separate Apple paths:

- Apple School Manager Public API: assigns one device to the release-broker MDM service.
- Apple ADE/DEP API through NanoDEP: performs the `disown` call for this device.

Important:

- Releasing a device from the Apple organization is irreversible.
- The broker only listens locally on `127.0.0.1:9001`.
- Secrets live outside the web root under `/srv/disown-protected/asm-release-broker` and `/etc/disown`.
- DEV only performs dry-runs.

Example configuration:

- `config/asm-release-broker.example.conf`

NanoDEP service installer:

- `tools/install-nanodep-service.sh`

## Installation and Operation

See:

- [German: docs/INSTALL.md](docs/INSTALL.md)
- [English: docs/INSTALL.en.md](docs/INSTALL.en.md)

Short version:

1. Provide PHP, MySQL/MariaDB and Apache.
2. Create the database and tables.
3. Configure the Jamf School API.
4. Configure an Apple School Manager API account.
5. Create the ASM release broker service, allow device release and import its token into NanoDEP.
6. Run `tools/install-nanodep-service.sh`.
7. Validate with a DEV dry-run and one single production test device.

## Important Files

- `index.php` - WebClip and request intake.
- `admin.php` - admin portal, bulk workflow, mail, dashboards.
- `asm_release.php` - ASM/ADE release through the broker.
- `jamf.php` - Jamf School integration and school-device guard.
- `ade.php` - ADE intake view.
- `kuk/index.php` - KUK devices.
- `audit_log.php` - audit log and reporting.
- `config/asm-release-broker.example.conf` - broker runtime configuration example.
- `tools/install-nanodep-service.sh` - systemd setup for the NanoDEP server.
- `docs/RELEASE.md` - release checklist with backup, smoke and public checks.
- `scripts/smoke_check.php` - read-only smoke check for deployments.

## Security

- No secrets in the repository.
- Runtime configuration lives under `/etc/disown`.
- Tokens and keys live under `/srv/protected`.
- The public repository is sanitized.
- Write actions are admin-only.
- KUK is read-only against Jamf.
- Critical actions are audited.

## History

- `2.3`: Switchable email push for new WebClip requests; the admin portal stores the on/off state in `app_settings`, recipients stay in `/etc/disown/notify.conf`.
- `2.2`: Visual release with a polished admin table, selected-row treatment, clearer actions and a unified look for admin, ADE intake, audit log and KUK.
- `2.1`: UI structure modernization; admin, ADE, audit and KUK styles/scripts live in `assets/`, search reacts more calmly and keeps focus after auto-refresh.
- `2.0`: automated ASM/ADE release through the release broker, NanoDEP service, improved bulk workflow, mail to both addresses with partial-success handling, updated docs and screenshots.
- `2.0` maintenance 2026-07-10: admin special release for defective devices, ADE token hint, mail dialog fix and waiting indicator for single actions.
- `1.9`: unified UI, rolling month overview, clarification cases, Jamf license indicator.
- `1.8`: KUK devices, ADE intake, audit dashboard.
