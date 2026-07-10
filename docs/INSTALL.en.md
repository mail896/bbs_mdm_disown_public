# Installation and Operation

This guide describes the runtime requirements for DISOWN version 2.0.

## 1. Requirements

- Debian/Apache with PHP 8.x.
- MariaDB or MySQL.
- PHP extensions: `mysqli`, `curl`, `json`, `openssl`.
- Web-server write access to required runtime/log paths.
- Jamf School API access for device lookup and Jamf unenroll.
- Apple School Manager API account for device lookup and MDM service assignment.
- Apple School Manager Device Management Service for the release broker with device release permission enabled.
- NanoDEP `v0.7.0` or compatible for ADE/DEP API access.
- `systemd`, `curl`, `openssl`, `jq` for setup and diagnostics.

## 2. Directories

Recommended layout:

```text
/var/www/example.org/disown                     # production
/var/www/example.org/disown-dev                 # development
/srv/protected/disown                          # protected app data, if needed
/srv/protected/asm-release-broker              # NanoDEP token, key, DB
/etc/disown                                    # runtime configuration
```

`/srv/protected` and `/etc/disown` must not be reachable through the web server.

## 3. Application Configuration

Runtime configuration lives outside the repository. Typical files:

```text
/etc/disown/db.conf
/etc/disown/jamf.conf
/etc/disown/asm-api.conf
/etc/disown/asm-release-broker.conf
```

Public and example configurations only contain placeholders. Never commit secrets.

## 4. ASM/ADE Release Broker

Version 2.0 automates the Apple release in two steps:

1. The Apple School Manager Public API assigns exactly one device to the release-broker MDM service.
2. NanoDEP performs the ADE/DEP disown call for this broker.

### 4.1 Create the Release Broker in ASM

1. Create a new Device Management Service in Apple School Manager, e.g. `asm-release-broker`.
2. Upload a dedicated certificate/public key.
3. Enable `Allow this device management service to release devices`.
4. Download the service token.
5. Store the token and private key below `/srv/protected/asm-release-broker`.

Example files:

```text
/srv/protected/asm-release-broker/asm-release-broker.key
/srv/protected/asm-release-broker/asm-release-broker-public.pem
/srv/protected/asm-release-broker/asm-release-broker-token.p7m
/srv/protected/asm-release-broker/nanodep-api.key
/srv/protected/asm-release-broker/nanodep-db/
```

Permissions should be restrictive:

```bash
chmod 700 /srv/protected/asm-release-broker
chmod 600 /srv/protected/asm-release-broker/*.key
chmod 600 /srv/protected/asm-release-broker/nanodep-api.key
```

### 4.2 Prepare NanoDEP

NanoDEP may be staged locally, for example:

```text
/tmp/nanodep-test/nanodep-linux-amd64-v0.7.0
```

Import the ASM token once into the NanoDEP storage. Depending on the token format, the `.p7m` file downloaded from ASM can be processed directly. If ASM provides an S/MIME file with headers, use `openssl smime -decrypt` without `-inform DER`.

After import, this check must work:

```bash
curl -sS http://127.0.0.1:9001/version
```

### 4.3 Install the systemd Service

The repository contains the installer:

```bash
sudo /var/www/example.org/disown/tools/install-nanodep-service.sh
```

The service:

- is named `disown-nanodep.service`
- runs as `www-data`
- listens only on `127.0.0.1:9001`
- uses `/srv/protected/asm-release-broker/nanodep-db`
- reads the API key from `/srv/protected/asm-release-broker/nanodep-api.key`

Check:

```bash
systemctl status disown-nanodep.service --no-pager
curl -sS http://127.0.0.1:9001/version
```

The admin dashboard shows the release broker status and the known ADE token expiration. The expiration value can be read from runtime configuration or a protected token file; secrets and token files remain outside the repository.

### 4.4 Health Check and Log Rotation

Optional, recommended for production:

```bash
sudo /var/www/example.org/disown/tools/install-nanodep-monitoring.sh
```

The installer creates:

- `disown-nanodep-health.timer` every 5 minutes.
- `/usr/local/sbin/disown-nanodep-health`.
- `/var/log/disown/nanodep-health.log`.
- `/etc/logrotate.d/disown-nanodep`.

Check:

```bash
systemctl status disown-nanodep-health.timer --no-pager
tail -n 50 /var/log/disown/nanodep-health.log
journalctl -u disown-nanodep.service -n 50 --no-pager
```

### 4.5 App Configuration

Template:

```text
config/asm-release-broker.example.conf
```

Copy it to `/etc/disown/asm-release-broker.conf` and set:

```bash
ASM_JAMF_MDM_SERVER_ID="..."
ASM_BROKER_MDM_SERVER_ID="..."
ASM_BROKER_DEP_BASE_URL="http://127.0.0.1:9001"
ASM_BROKER_DEP_NAME="asm-release-broker"
ASM_BROKER_DEP_API_KEY_FILE="/srv/protected/asm-release-broker/nanodep-api.key"
```

The MDM server IDs come from the Apple School Manager API (`mdmServers`). The tool only assigns the concrete device currently being processed to the broker, never the full fleet.

## 5. Database

The application stores:

- requests
- audit log entries
- KUK devices and local owner history
- clarification cases
- workflow and mail status

Always create a database backup before production changes.

## 6. Testing

Recommended order:

1. Open DEV and test with demo data.
2. Test Jamf unenroll in DEV dry-run mode.
3. Test ASM/ADE in DEV dry-run mode.
4. Check the local NanoDEP service.
5. Use exactly one production test device.
6. Verify:
   - Jamf unenroll succeeded.
   - ASM/ADE release succeeded.
   - ASM shows `Date Removed from Organization`.
   - Audit log contains all steps.
   - Mail status is green or red on partial failure.

## 7. Operations

- Create code and DB backups before larger changes.
- Monitor the NanoDEP service.
- Track expiration of broker token and ASM API credentials.
- Only publish sanitized files to the public repository.
- Do not publish `PROJECT_STATE.*`.

### 7.1 Admin Special Release for Defective Devices

The admin special path is intended for individual defective devices that cannot use the normal WebClip request. Workflow:

1. Check the serial number at the bottom of the admin portal.
2. Review the Jamf data and adjust mail addresses if needed.
3. Create the local request.
4. Process the table row normally through Jamf, ASM/ADE and mail.

Only this single-device admin path intentionally bypasses the school-device blocker. Bulk and WebClip keep the guard against school-owned loaner/cart devices.

## 8. Rollback

If something goes wrong:

1. Restore the web application from backup.
2. Restore the DB backup.
3. Run `systemctl stop disown-nanodep.service` if the broker should be isolated.
4. Review affected requests in the audit log.

Devices already released through ADE/DEP cannot be automatically returned to the organization. They must be re-added through Apple Configurator or the ASM intake process.
