#!/bin/sh
set -eu

ROOT_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT_DIR"

if [ -f "$ROOT_DIR/.env.screenshots" ]; then
  # shellcheck disable=SC1091
  . "$ROOT_DIR/.env.screenshots"
fi

if [ ! -d "$ROOT_DIR/node_modules/playwright" ]; then
  npm install
fi

npx playwright install chromium

CHROME_SHELL=$(find "$HOME/.cache/ms-playwright" -path '*/chrome-headless-shell' -type f 2>/dev/null | sort | tail -n 1 || true)
if [ -n "$CHROME_SHELL" ] && ldd "$CHROME_SHELL" 2>/dev/null | grep -q 'not found'; then
  echo "Chromium-Systembibliotheken fehlen. Einmalig als root ausführen:" >&2
  echo "apt-get update && apt-get install -y libatk1.0-0t64 libatk-bridge2.0-0t64 libxdamage1 libxkbcommon0 libasound2t64 libatspi2.0-0t64" >&2
  ldd "$CHROME_SHELL" 2>/dev/null | awk '/not found/{print "  fehlt: " $1}' >&2
  exit 1
fi

SERVER_PID=""
TMP_DIR=""
if [ -z "${DISOWN_SCREENSHOT_BASE_URL:-}" ]; then
  PORT="${DISOWN_SCREENSHOT_PORT:-8765}"
  SOURCE="${DISOWN_SCREENSHOT_SOURCE:-dev}"
  if [ "$SOURCE" = "prod" ] || [ "$SOURCE" = "real" ] || [ "$SOURCE" = "real-masked" ]; then
    APP_SLUG="disown"
    export DISOWN_SCREENSHOT_PROFILE="${DISOWN_SCREENSHOT_PROFILE:-real-masked}"
    export DISOWN_SCREENSHOT_MASK="${DISOWN_SCREENSHOT_MASK:-1}"
    export DISOWN_SCREENSHOT_DIR="${DISOWN_SCREENSHOT_DIR:-docs/screenshots/review-real-masked}"
  else
    APP_SLUG="disown-dev"
    export DISOWN_SCREENSHOT_PROFILE="${DISOWN_SCREENSHOT_PROFILE:-dev}"
  fi
  TMP_DIR=$(mktemp -d)
  cat > "$TMP_DIR/oidc.conf" <<'EOF'
OIDC_ENABLED=0
EOF
  export DISOWN_OIDC_CONFIG="$TMP_DIR/oidc.conf"
  export DISOWN_SCREENSHOT_BASE_URL="http://127.0.0.1:$PORT/$APP_SLUG"
  APP_ROOT=$(CDPATH='' cd -- "$ROOT_DIR/.." && pwd)
  php -S "127.0.0.1:$PORT" -t "$APP_ROOT" > "$TMP_DIR/php-server.log" 2>&1 &
  SERVER_PID=$!
  cleanup() {
    if [ -n "$SERVER_PID" ] && kill -0 "$SERVER_PID" 2>/dev/null; then
      kill "$SERVER_PID" 2>/dev/null || true
    fi
    if [ -n "$TMP_DIR" ]; then
      rm -rf "$TMP_DIR"
    fi
  }
  trap cleanup EXIT INT TERM
  sleep 1
  if ! kill -0 "$SERVER_PID" 2>/dev/null; then
    echo "Lokaler PHP-Screenshot-Server konnte nicht starten:" >&2
    cat "$TMP_DIR/php-server.log" >&2
    exit 1
  fi
  echo "Lokaler Screenshot-Server: $DISOWN_SCREENSHOT_BASE_URL"
fi

npm run screenshots
