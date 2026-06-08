#!/usr/bin/env bash
set -euo pipefail

# Telegram polling helper for the reseller panel.
# Usage:
#   POLL_URL="https://example.com/shop/telegram/poll/SECRET?timeout=55" ./telegram_poll_runner.sh
# or:
#   ./telegram_poll_runner.sh "https://example.com/shop/telegram/poll/SECRET?timeout=55"

POLL_URL="${1:-${POLL_URL:-}}"
CONNECT_TIMEOUT="${CONNECT_TIMEOUT:-10}"
MAX_TIME="${MAX_TIME:-65}"
LOCK_FILE="${LOCK_FILE:-/tmp/xui_reseller_tg_poll.lock}"
LOG_FILE="${LOG_FILE:-/tmp/xui_reseller_tg_poll.log}"

if [ -z "$POLL_URL" ]; then
  echo "Missing POLL_URL. Pass it as the first argument or export POLL_URL." >&2
  exit 1
fi

mkdir -p "$(dirname "$LOCK_FILE")" "$(dirname "$LOG_FILE")"
(
  flock -n 9 || exit 0
  /usr/bin/curl --silent --show-error --fail \
    --connect-timeout "$CONNECT_TIMEOUT" \
    --max-time "$MAX_TIME" \
    "$POLL_URL" >>"$LOG_FILE" 2>&1 || true
) 9>"$LOCK_FILE"
