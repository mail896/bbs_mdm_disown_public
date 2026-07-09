#!/usr/bin/env bash
set -euo pipefail

SERVICE="${NANODEP_SERVICE:-disown-nanodep.service}"
URL="${NANODEP_HEALTH_URL:-http://127.0.0.1:9001/version}"
TIMEOUT="${NANODEP_HEALTH_TIMEOUT:-5}"

timestamp() {
  date '+%Y-%m-%d %H:%M:%S %z'
}

echo "$(timestamp) checking ${URL}"

response="$(curl -fsS --max-time "${TIMEOUT}" "${URL}")"

if ! printf '%s' "${response}" | grep -q '"version"'; then
  echo "$(timestamp) unexpected NanoDEP response: ${response}" >&2
  exit 2
fi

if command -v systemctl >/dev/null 2>&1; then
  if ! systemctl is-active --quiet "${SERVICE}"; then
    echo "$(timestamp) ${SERVICE} is not active" >&2
    systemctl status "${SERVICE}" --no-pager || true
    exit 3
  fi
fi

echo "$(timestamp) NanoDEP health ok: ${response}"
