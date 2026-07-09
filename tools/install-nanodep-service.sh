#!/usr/bin/env bash
set -euo pipefail

NANODEP_SRC="${NANODEP_SRC:-/tmp/nanodep-test/nanodep-linux-amd64-v0.7.0}"
NANODEP_VERSION="${NANODEP_VERSION:-v0.7.0}"
INSTALL_DIR="/usr/local/lib/nanodep/${NANODEP_VERSION}"
BROKER_DIR="/srv/protected/asm-release-broker"
BROKER_DB="${BROKER_DIR}/nanodep-db"
BROKER_API_KEY="${BROKER_DIR}/nanodep-api.key"
WRAPPER="/usr/local/sbin/disown-nanodep-server"
SERVICE="/etc/systemd/system/disown-nanodep.service"

if [[ "${EUID}" -ne 0 ]]; then
  echo "Bitte als root ausfuehren." >&2
  exit 1
fi

for binary in depserver-linux-amd64 depsyncer-linux-amd64 deptokens-linux-amd64; do
  if [[ ! -x "${NANODEP_SRC}/${binary}" ]]; then
    echo "NanoDEP-Binary fehlt oder ist nicht ausfuehrbar: ${NANODEP_SRC}/${binary}" >&2
    exit 1
  fi
done

if [[ ! -f "${BROKER_API_KEY}" ]]; then
  echo "Broker API-Key fehlt: ${BROKER_API_KEY}" >&2
  exit 1
fi

install -d -o root -g root -m 0755 "${INSTALL_DIR}"
install -o root -g root -m 0755 "${NANODEP_SRC}/depserver-linux-amd64" "${INSTALL_DIR}/depserver-linux-amd64"
install -o root -g root -m 0755 "${NANODEP_SRC}/depsyncer-linux-amd64" "${INSTALL_DIR}/depsyncer-linux-amd64"
install -o root -g root -m 0755 "${NANODEP_SRC}/deptokens-linux-amd64" "${INSTALL_DIR}/deptokens-linux-amd64"

install -d -o www-data -g www-data -m 0750 "${BROKER_DB}"
chown -R www-data:www-data "${BROKER_DB}"
chgrp www-data "${BROKER_API_KEY}"
chmod 0640 "${BROKER_API_KEY}"

cat > "${WRAPPER}" <<EOF
#!/usr/bin/env bash
set -euo pipefail

API_KEY="\$(tr -d '\\r\\n' < '${BROKER_API_KEY}')"
if [[ -z "\${API_KEY}" ]]; then
  echo "Broker API-Key ist leer." >&2
  exit 1
fi

export NANODEP_API="\${API_KEY}"
exec '${INSTALL_DIR}/depserver-linux-amd64' \\
  -listen 127.0.0.1:9001 \\
  -storage filekv \\
  -storage-dsn '${BROKER_DB}'
EOF
chmod 0755 "${WRAPPER}"

cat > "${SERVICE}" <<EOF
[Unit]
Description=BBS Einbeck DISOWN NanoDEP Release Broker
Documentation=https://github.com/micromdm/nanodep
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
ExecStart=${WRAPPER}
Restart=on-failure
RestartSec=5s
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadOnlyPaths=/usr/local/lib/nanodep /etc/disown
ReadWritePaths=${BROKER_DIR}
RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6

[Install]
WantedBy=multi-user.target
EOF

if [[ -f "${BROKER_DIR}/depserver.pid" ]]; then
  OLD_PID="$(cat "${BROKER_DIR}/depserver.pid" 2>/dev/null || true)"
  if [[ -n "${OLD_PID}" ]] && kill -0 "${OLD_PID}" 2>/dev/null; then
    if ps -p "${OLD_PID}" -o args= | grep -q 'depserver-linux-amd64'; then
      echo "Stoppe manuell gestarteten depserver PID ${OLD_PID}."
      kill "${OLD_PID}"
      sleep 1
    fi
  fi
fi

systemctl daemon-reload
systemctl enable --now disown-nanodep.service
systemctl --no-pager --full status disown-nanodep.service
curl -sS http://127.0.0.1:9001/version || true
echo
echo "NanoDEP Release Broker Dienst ist eingerichtet."
