#!/usr/bin/env bash
set -euo pipefail

PATH=/usr/sbin:/usr/bin:/sbin:/bin
export PATH
umask 027

readonly MODE="${1:-install}"
SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly SOURCE_DIR
readonly SOURCE_HELPER="$SOURCE_DIR/disown-settings-helper"
readonly TARGET_HELPER="/usr/local/sbin/disown-settings-helper"
readonly SUDOERS_FILE="/etc/sudoers.d/disown-settings-helper"
readonly WEB_USER="www-data"

if [[ "$(id -u)" != "0" ]]; then
  printf 'Bitte als root ausführen: sudo %s %s\n' "$0" "$MODE" >&2
  exit 2
fi

check_installation() {
  [[ -x "$TARGET_HELPER" ]] || { printf '[FAIL] Helper fehlt: %s\n' "$TARGET_HELPER"; return 1; }
  [[ -f "$SUDOERS_FILE" ]] || { printf '[FAIL] sudoers-Datei fehlt: %s\n' "$SUDOERS_FILE"; return 1; }
  visudo -cf "$SUDOERS_FILE"
  sudo -n -u "$WEB_USER" sudo -n "$TARGET_HELPER" status
  sudo -n -u "$WEB_USER" sudo -n "$TARGET_HELPER" backup-status
}

case "$MODE" in
  install)
    [[ -f "$SOURCE_HELPER" ]] || { printf 'Quelldatei fehlt: %s\n' "$SOURCE_HELPER" >&2; exit 1; }
    command -v visudo >/dev/null 2>&1 || { printf 'visudo fehlt. Bitte sudo installieren.\n' >&2; exit 1; }
    install -o root -g root -m 0755 "$SOURCE_HELPER" "$TARGET_HELPER"

    temporary_sudoers="$(mktemp)"
    trap 'rm -f "$temporary_sudoers"' EXIT
    cat >"$temporary_sudoers" <<EOF
Defaults!$TARGET_HELPER env_reset,secure_path=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin,!setenv
$WEB_USER ALL=(root) NOPASSWD: $TARGET_HELPER status
$WEB_USER ALL=(root) NOPASSWD: $TARGET_HELPER repair-log-permissions
$WEB_USER ALL=(root) NOPASSWD: $TARGET_HELPER backup-status
$WEB_USER ALL=(root) NOPASSWD: $TARGET_HELPER create-backup
EOF
    chmod 0440 "$temporary_sudoers"
    visudo -cf "$temporary_sudoers"
    install -o root -g root -m 0440 "$temporary_sudoers" "$SUDOERS_FILE"
    check_installation
    printf '[OK] DISOWN Root-Helper wurde installiert.\n'
    ;;
  check)
    check_installation
    ;;
  uninstall)
    rm -f "$SUDOERS_FILE" "$TARGET_HELPER"
    printf '[OK] DISOWN Root-Helper wurde entfernt.\n'
    ;;
  *)
    printf 'Usage: %s [install|check|uninstall]\n' "$0" >&2
    exit 2
    ;;
esac
