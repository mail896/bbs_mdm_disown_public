#!/bin/sh
set -eu

ROOT_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
SOURCE_DIR="${DISOWN_SCREENSHOT_REVIEW_DIR:-$ROOT_DIR/docs/screenshots/review-real-masked}"
PUBLIC_DIR="${DISOWN_PUBLIC_DIR:-}"

copy_file() {
  src="$1"
  dest="$2"
  if [ ! -f "$src" ]; then
    echo "Fehlt: $src" >&2
    exit 1
  fi
  mkdir -p "$(dirname "$dest")"
  cp "$src" "$dest"
  echo "aktualisiert: $dest"
}

copy_admin_portal() {
  repo_dir="$1"
  copy_file "$SOURCE_DIR/admin-all-desktop.png" "$repo_dir/images/disown-admin-portal.png"
  copy_file "$SOURCE_DIR/admin-all-mobile.png" "$repo_dir/images/disown-admin-mobile.png"
  copy_file "$SOURCE_DIR/admin-cases-desktop.png" "$repo_dir/images/disown-admin-cases.png"
  copy_file "$SOURCE_DIR/admin-cases-mobile.png" "$repo_dir/images/disown-admin-cases-mobile.png"
  copy_file "$SOURCE_DIR/ade-desktop.png" "$repo_dir/images/disown-ade.png"
  copy_file "$SOURCE_DIR/ade-mobile.png" "$repo_dir/images/disown-ade-mobile.png"
  copy_file "$SOURCE_DIR/kuk-desktop.png" "$repo_dir/images/disown-kuk.png"
  copy_file "$SOURCE_DIR/kuk-mobile.png" "$repo_dir/images/disown-kuk-mobile.png"
  copy_file "$SOURCE_DIR/audit-log-desktop.png" "$repo_dir/images/disown-audit-log.png"
  copy_file "$SOURCE_DIR/audit-log-mobile.png" "$repo_dir/images/disown-audit-log-mobile.png"
  copy_file "$SOURCE_DIR/settings-system-desktop.png" "$repo_dir/images/disown-settings.png"
  copy_file "$SOURCE_DIR/settings-system-mobile.png" "$repo_dir/images/disown-settings-mobile.png"
}

copy_admin_portal "$ROOT_DIR"

if [ -n "$PUBLIC_DIR" ]; then
  copy_admin_portal "$PUBLIC_DIR"
fi
