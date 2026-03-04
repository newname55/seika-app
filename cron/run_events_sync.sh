#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="/var/www/html/seika-app"
PHP_BIN="/usr/bin/php"

# optional: set in /etc/environment or the cron user env
# export EVENTS_SYNC_KEY="YOUR_SECRET"

cd "$APP_ROOT"

# 直近 -7日〜+90日（events_sync.php のデフォルトと同じ）
# key gate を使う場合:
#   ENVに EVENTS_SYNC_KEY を入れておく + CLI引数でも渡す
KEY_ARG=""
if [[ -n "${EVENTS_SYNC_KEY:-}" ]]; then
  KEY_ARG="--key=${EVENTS_SYNC_KEY}"
fi

$PHP_BIN "$APP_ROOT/public/api/events_sync.php" $KEY_ARG