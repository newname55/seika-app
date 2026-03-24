#!/bin/bash

set -u

cd /var/www/html/wbss || exit 1

ENV_FILE="/var/www/.wbss_env"
if [ -f "$ENV_FILE" ]; then
  # shellcheck disable=SC1090
  source "$ENV_FILE"
else
  echo "[WARN] ENVファイルなし"
fi

LINE_TOKEN="${LINE_DEPLOY_TOKEN:-}"
LINE_USER="${LINE_DEPLOY_USER:-}"
CRON_SECRET_VALUE="${CRON_SECRET:-}"
APP_BASE_URL="${APP_BASE_URL:-https://ss5456ds1fds2f1dsf.asuscomm.com/wbss}"

DB_HOST="${DB_HOST:-localhost}"
DB_NAME="${DB_NAME:-wbss}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"

HOSTNAME_SHORT="$(hostname)"
EXECUTED_BY="$(whoami)"
START="$(date '+%Y-%m-%d %H:%M:%S')"
CURRENT_FULL="$(git rev-parse HEAD)"
CURRENT_COMMIT="$(git rev-parse --short=12 HEAD)"
TARGET_INPUT="${1:-}"
TARGET_COMMIT=""

send_line() {
  local msg="$1"

  if [ -z "$LINE_TOKEN" ] || [ -z "$LINE_USER" ]; then
    echo "[WARN] LINE未設定"
    return 0
  fi

  local json
  json=$(python3 - <<PY
import json
print(json.dumps({
  "to": """$LINE_USER""",
  "messages": [{"type": "text", "text": """$msg"""}]
}, ensure_ascii=False))
PY
)

  curl -sS -X POST "https://api.line.me/v2/bot/message/push" \
    -H "Authorization: Bearer ${LINE_TOKEN}" \
    -H "Content-Type: application/json" \
    -d "$json" >/dev/null
}

log_deploy_http() {
  local status="$1"
  local detail="$2"
  local before_commit="$3"
  local after_commit="$4"

  if [ -z "$CRON_SECRET_VALUE" ]; then
    return 1
  fi

  curl -sS -X POST "${APP_BASE_URL}/public/api/deploy_log.php" \
    -d "secret=${CRON_SECRET_VALUE}" \
    -d "environment=rollback" \
    -d "host_name=${HOSTNAME_SHORT}" \
    -d "branch_name=main" \
    -d "before_commit=${before_commit}" \
    -d "after_commit=${after_commit}" \
    -d "status=${status}" \
    -d "executed_by=${EXECUTED_BY}" \
    --data-urlencode "detail_text=${detail}" >/dev/null
}

log_deploy_db() {
  local status="$1"
  local detail="$2"
  local before_commit="$3"
  local after_commit="$4"

  if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ]; then
    return 1
  fi

  mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<SQL >/dev/null 2>&1
INSERT INTO deploy_logs (
  environment,
  host_name,
  branch_name,
  before_commit,
  after_commit,
  status,
  executed_by,
  detail_text,
  created_at
) VALUES (
  'rollback',
  '$HOSTNAME_SHORT',
  'main',
  '$before_commit',
  '$after_commit',
  '$status',
  '$EXECUTED_BY',
  '$detail',
  NOW()
);
SQL
}

log_deploy() {
  local status="$1"
  local detail="$2"
  local before_commit="$3"
  local after_commit="$4"

  log_deploy_http "$status" "$detail" "$before_commit" "$after_commit" || \
    log_deploy_db "$status" "$detail" "$before_commit" "$after_commit" || true
}

extract_hash() {
  local value="$1"
  printf '%s\n' "$value" | grep -oE '[0-9a-fA-F]{7,40}' | head -n 1 | tr 'A-F' 'a-f'
}

resolve_default_target() {
  local current="$1"
  local seen_current=0
  local row
  while IFS= read -r row; do
    local hash
    hash="$(extract_hash "$row")"
    [ -n "$hash" ] || continue

    if [ "$seen_current" -eq 0 ]; then
      if [[ "$current" == "$hash"* ]] || [[ "$hash" == "$current"* ]]; then
        seen_current=1
      fi
      continue
    fi

    printf '%s\n' "$hash"
    return 0
  done < <(
    mysql \
      -h "$DB_HOST" \
      -u "$DB_USER" \
      -p"$DB_PASS" \
      "$DB_NAME" \
      -N -e "
SELECT after_commit
FROM deploy_logs
WHERE environment='prod'
  AND status='success'
ORDER BY id DESC
LIMIT 200;
" 2>/dev/null
  )

  return 1
}

target_exists_in_prod_success() {
  local target="$1"
  local row
  while IFS= read -r row; do
    local hash
    hash="$(extract_hash "$row")"
    [ -n "$hash" ] || continue
    if [[ "$target" == "$hash"* ]] || [[ "$hash" == "$target"* ]]; then
      return 0
    fi
  done < <(
    mysql \
      -h "$DB_HOST" \
      -u "$DB_USER" \
      -p"$DB_PASS" \
      "$DB_NAME" \
      -N -e "
SELECT after_commit
FROM deploy_logs
WHERE environment='prod'
  AND status='success'
ORDER BY id DESC
LIMIT 200;
" 2>/dev/null
  )
  return 1
}

fail_and_exit() {
  local detail="$1"
  local after_commit="${2:-$TARGET_COMMIT}"
  local end
  end="$(date '+%Y-%m-%d %H:%M:%S')"

  log_deploy "failed" "$detail" "$CURRENT_COMMIT" "$after_commit"

  send_line "❌ WBSS 本番rollback失敗
host: $HOSTNAME_SHORT
by: $EXECUTED_BY
from: $CURRENT_COMMIT
to:   ${after_commit:-unknown}
start: $START
end: $end
detail: $detail"
  echo "$detail" >&2
  exit 1
}

if [ -n "$TARGET_INPUT" ]; then
  if ! printf '%s' "$TARGET_INPUT" | grep -Eq '^[0-9a-fA-F]{7,40}$'; then
    fail_and_exit "rollback対象commitが不正です" "$TARGET_INPUT"
  fi
  TARGET_COMMIT="$(printf '%s' "$TARGET_INPUT" | tr 'A-F' 'a-f')"
else
  TARGET_COMMIT="$(resolve_default_target "$CURRENT_FULL")" || fail_and_exit "rollback対象なし"
fi

target_exists_in_prod_success "$TARGET_COMMIT" || fail_and_exit "prod success 履歴に存在しないcommitです" "$TARGET_COMMIT"

if [[ "$CURRENT_FULL" == "$TARGET_COMMIT"* ]] || [[ "$TARGET_COMMIT" == "$CURRENT_FULL"* ]]; then
  fail_and_exit "すでに対象commitです" "$TARGET_COMMIT"
fi

echo "rollback → $TARGET_COMMIT"

git checkout main >/dev/null 2>&1 || fail_and_exit "main checkout失敗" "$TARGET_COMMIT"
git fetch origin main >/dev/null 2>&1 || fail_and_exit "git fetch失敗" "$TARGET_COMMIT"
git reset --hard "$TARGET_COMMIT" >/dev/null 2>&1 || fail_and_exit "git reset失敗" "$TARGET_COMMIT"

FINAL_COMMIT="$(git rev-parse --short=12 HEAD 2>/dev/null || true)"
[ -n "$FINAL_COMMIT" ] || FINAL_COMMIT="$TARGET_COMMIT"

END="$(date '+%Y-%m-%d %H:%M:%S')"

log_deploy "success" "manual rollback" "$CURRENT_COMMIT" "$FINAL_COMMIT"

send_line "⚠️ WBSS 本番rollback
host: $HOSTNAME_SHORT
by: $EXECUTED_BY
from: $CURRENT_COMMIT
to:   $FINAL_COMMIT
start: $START
end: $END
${APP_BASE_URL}/"

echo "rollback完了"
