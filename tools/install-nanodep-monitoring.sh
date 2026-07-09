#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="${APP_ROOT:-$(cd "${SCRIPT_DIR}/.." && pwd)}"
HEALTH_SRC="${APP_ROOT}/tools/check-nanodep-health.sh"
HEALTH_BIN="/usr/local/sbin/disown-nanodep-health"
LOG_DIR="/var/log/disown"
LOG_FILE="${LOG_DIR}/nanodep-health.log"
SERVICE="/etc/systemd/system/disown-nanodep-health.service"
TIMER="/etc/systemd/system/disown-nanodep-health.timer"
LOGROTATE="/etc/logrotate.d/disown-nanodep"

if [[ $EUID -ne 0 ]]; then
  echo "Bitte als root ausfuehren." >&2
  exit 1
fi

if [[ ! -f "${HEALTH_SRC}" ]]; then
  echo "Healthcheck-Script nicht gefunden: ${HEALTH_SRC}" >&2
  exit 1
fi

install -o root -g root -m 0755 "${HEALTH_SRC}" "${HEALTH_BIN}"
install -d -o root -g adm -m 0750 "${LOG_DIR}"
touch "${LOG_FILE}"
chown root:adm "${LOG_FILE}"
chmod 0640 "${LOG_FILE}"

cat > "${SERVICE}" <<EOF
[Unit]
Description=BBS Einbeck DISOWN NanoDEP Healthcheck
After=disown-nanodep.service

[Service]
Type=oneshot
ExecStart=/bin/bash -lc '${HEALTH_BIN} >> ${LOG_FILE} 2>&1'
EOF

cat > "${TIMER}" <<EOF
[Unit]
Description=BBS Einbeck DISOWN NanoDEP Healthcheck Timer

[Timer]
OnBootSec=2min
OnUnitActiveSec=5min
AccuracySec=30s
Persistent=true

[Install]
WantedBy=timers.target
EOF

cat > "${LOGROTATE}" <<EOF
${LOG_FILE} {
    weekly
    rotate 8
    missingok
    notifempty
    compress
    delaycompress
    create 0640 root adm
}
EOF

systemctl daemon-reload
systemctl enable --now disown-nanodep-health.timer
systemctl start disown-nanodep-health.service

systemctl --no-pager --full status disown-nanodep-health.timer
tail -n 20 "${LOG_FILE}" || true

echo "NanoDEP Healthcheck, Timer und Logrotation sind eingerichtet."
