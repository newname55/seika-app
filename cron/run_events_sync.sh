#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="/var/www/html/wbss"
PHP_BIN="/usr/bin/php"
APACHE_ENV_FILE="/etc/apache2/conf-enabled/seika-app-db.conf"

# optional: set in /etc/environment or the cron user env
# export EVENTS_SYNC_KEY="YOUR_SECRET"

load_apache_setenv_file() {
  local file="$1"
  [[ -f "$file" ]] || return 0

  while IFS= read -r line; do
    [[ "$line" =~ ^[[:space:]]*SetEnv[[:space:]]+([^[:space:]]+)[[:space:]]+(.+)$ ]] || continue
    local key="${BASH_REMATCH[1]}"
    local value="${BASH_REMATCH[2]}"

    value="${value%\"}"
    value="${value#\"}"
    export "$key=$value"
  done < "$file"
}

load_apache_setenv_file "$APACHE_ENV_FILE"

cd "$APP_ROOT"

required_vars=(
  "WBSS_DB_HOST"
  "WBSS_DB_NAME"
  "WBSS_DB_USER"
  "WBSS_DB_PASS"
)

for key in "${required_vars[@]}"; do
  if [[ -z "${!key:-}" && -z "${SEIKA_DB_HOST:-}" && "$key" == "WBSS_DB_HOST" ]]; then
    continue
  fi
  if [[ -z "${!key:-}" ]]; then
    case "$key" in
      WBSS_DB_HOST) [[ -n "${SEIKA_DB_HOST:-}" ]] && continue ;;
      WBSS_DB_NAME) [[ -n "${SEIKA_DB_NAME:-}" ]] && continue ;;
      WBSS_DB_USER) [[ -n "${SEIKA_DB_USER:-}" ]] && continue ;;
      WBSS_DB_PASS) [[ -n "${SEIKA_DB_PASS:-}" ]] && continue ;;
    esac
    echo "ERROR: missing required DB env: $key" >&2
    exit 1
  fi
done

# 直近 -7日〜+90日（events_sync.php のデフォルトと同じ）
# key gate を使う場合:
#   ENVに EVENTS_SYNC_KEY を入れておく + CLI引数でも渡す
KEY_ARG=""
if [[ -n "${EVENTS_SYNC_KEY:-}" ]]; then
  KEY_ARG="--key=${EVENTS_SYNC_KEY}"
fi

$PHP_BIN "$APP_ROOT/public/api/events_sync.php" $KEY_ARG
