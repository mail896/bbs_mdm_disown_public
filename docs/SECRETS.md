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
/srv/disown-protected/asm-release-broker
```

Expected files:

```text
/srv/disown-protected/asm-release-broker/asm-release-broker.key
/srv/disown-protected/asm-release-broker/asm-release-broker-public.pem
/srv/disown-protected/asm-release-broker/asm-release-broker-token.p7m
/srv/disown-protected/asm-release-broker/nanodep-api.key
/srv/disown-protected/asm-release-broker/nanodep-db/
```

Optional/development-only decoded token artifacts, if temporarily needed:

```text
/srv/disown-protected/asm-release-broker/asm-release-broker-token.plist
```

Recommended permissions:

```bash
chown -R root:root /srv/disown-protected/asm-release-broker
chmod 711 /srv/disown-protected
chmod 750 /srv/disown-protected/asm-release-broker
chmod 600 /srv/disown-protected/asm-release-broker/asm-release-broker.key
chmod 600 /srv/disown-protected/asm-release-broker/asm-release-broker-public.pem
chmod 600 /srv/disown-protected/asm-release-broker/asm-release-broker-token.p7m
chmod 600 /srv/disown-protected/asm-release-broker/nanodep-api.key
chmod 700 /srv/disown-protected/asm-release-broker/nanodep-db
setfacl -m u:www-data:--x /srv/disown-protected
setfacl -m u:www-data:r-x /srv/disown-protected/asm-release-broker
setfacl -m u:www-data:r /srv/disown-protected/asm-release-broker/nanodep-api.key
setfacl -R -m u:www-data:rwX /srv/disown-protected/asm-release-broker/nanodep-db
setfacl -R -d -m u:www-data:rwX /srv/disown-protected/asm-release-broker/nanodep-db
```

Use user ACLs for `www-data`, not group ownership, because other local users
may be members of the `www-data` group.

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
