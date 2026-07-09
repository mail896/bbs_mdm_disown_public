# Secret and Runtime File Placement

This file documents where operational secrets and runtime-only files belong.
Do not commit real credentials, private keys, service tokens, decrypted token data,
or generated API keys.

## Application Runtime Config

Directory:

```text
/etc/disown
```

Expected files:

```text
/etc/disown/db.conf
/etc/disown/jamf.conf
/etc/disown/asm-api.conf
/etc/disown/asm-release-broker.conf
```

Recommended permissions:

```bash
chown -R root:www-data /etc/disown
chmod 750 /etc/disown
chmod 640 /etc/disown/*.conf
```

Purpose:

- `db.conf`: database host, database name, user, password.
- `jamf.conf`: Jamf School API endpoint and credentials.
- `asm-api.conf`: Apple School Manager Public API credentials.
- `asm-release-broker.conf`: local Release Broker configuration and NanoDEP API key path.

## ASM Release Broker Files

Directory:

```text
/srv/protected/asm-release-broker
```

Expected files:

```text
/srv/protected/asm-release-broker/asm-release-broker.key
/srv/protected/asm-release-broker/asm-release-broker-public.pem
/srv/protected/asm-release-broker/asm-release-broker-token.p7m
/srv/protected/asm-release-broker/nanodep-api.key
/srv/protected/asm-release-broker/nanodep-db/
```

Optional/development-only decoded token artifacts, if temporarily needed:

```text
/srv/protected/asm-release-broker/asm-release-broker-token.plist
```

Recommended permissions:

```bash
chown -R root:www-data /srv/protected/asm-release-broker
chmod 750 /srv/protected/asm-release-broker
chmod 600 /srv/protected/asm-release-broker/asm-release-broker.key
chmod 640 /srv/protected/asm-release-broker/asm-release-broker-public.pem
chmod 640 /srv/protected/asm-release-broker/asm-release-broker-token.p7m
chmod 640 /srv/protected/asm-release-broker/nanodep-api.key
chmod 770 /srv/protected/asm-release-broker/nanodep-db
```

Purpose:

- Private key and ASM token bind this server to the ASM Release Broker device management service.
- `nanodep-api.key` protects the local NanoDEP HTTP endpoint on `127.0.0.1:9001`.
- `nanodep-db/` stores the imported DEP token data and sync cursor.

## Logs

Directory:

```text
/var/log/disown
```

Expected files after monitoring setup:

```text
/var/log/disown/nanodep-health.log
```

The service itself logs to journald under `disown-nanodep.service`.

Useful checks:

```bash
systemctl status disown-nanodep.service --no-pager
systemctl status disown-nanodep-health.timer --no-pager
journalctl -u disown-nanodep.service -n 50 --no-pager
tail -n 50 /var/log/disown/nanodep-health.log
```

## Rotation Rule

Monitoring setup installs:

```text
/etc/logrotate.d/disown-nanodep
```

This rotates the health log and keeps operational noise out of the repository.
