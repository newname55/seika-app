#!/bin/bash

set +u

cd /var/www/html/wbss || exit 1

# ===== 環境変数 =====
ENV_FILE="/var/www/.wbss_env"
[ -f "$ENV_FILE" ] && source "$ENV_FILE"

LINE_TOKEN="${LINE_DEPLOY_TOKEN:-}"
LINE_USER="${LINE_DEPLOY_USER:-}"
CRON_SECRET_VALUE="${CRON_SECRET:-}"

# 👇 ここ重要（publicなし）
APP_BASE_URL="${APP_BASE_URL:-https://ss5456ds1fds2f1dsf.asuscomm.com/wbss}"

send_line() {
  local msg="$1"

  if [ -z "$LINE_TOKEN" ] || [ -z "$LINE_USER" ]; then
    echo "[WARN] LINE設定なし"
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

log_deploy() {
  local env_name="$1"
  local branch_name="$2"
  local before_commit="$3"
  local after_commit="$4"
  local status="$5"
  local detail="$6"

  if [ -z "$CRON_SECRET_VALUE" ]; then
    echo "[WARN] CRON_SECRET 未設定"
    return 0
  fi

  local url="${APP_BASE_URL}/public/api/deploy_log.php"

  echo "LOG SEND → $url"

  curl -sS -X POST "$url" \
    -d "secret=${CRON_SECRET_VALUE}" \
    -d "environment=${env_name}" \
    -d "host_name=${HOSTNAME_SHORT}" \
    -d "branch_name=${branch_name}" \
    -d "before_commit=${before_commit}" \
    -d "after_commit=${after_commit}" \
    -d "status=${status}" \
    -d "executed_by=${EXECUTED_BY}" \
    --data-urlencode "detail_text=${detail}"
}

START="$(date '+%Y-%m-%d %H:%M:%S')"
HOSTNAME_SHORT="$(hostname)"
EXECUTED_BY="$(whoami)"
BEFORE_MAIN="$(git rev-parse --short HEAD 2>/dev/null || echo 'unknown')"

MAIN_STATUS=0

echo "===== RASPI5 DEPLOY (main) ====="
date

git checkout main || MAIN_STATUS=$?
git fetch origin || MAIN_STATUS=$?
git reset --hard origin/main || MAIN_STATUS=$?

AFTER_MAIN="$(git rev-parse --short HEAD 2>/dev/null || echo 'unknown')"
END="$(date '+%Y-%m-%d %H:%M:%S')"

if [ "$MAIN_STATUS" -eq 0 ]; then
  if [ "$BEFORE_MAIN" = "$AFTER_MAIN" ]; then
    MSG="ℹ️ WBSS 本番deploy完了（更新なし）
host: $HOSTNAME_SHORT
by: $EXECUTED_BY
start: $START
end: $END
main: $AFTER_MAIN
${APP_BASE_URL}/"

    log_deploy "prod" "main" "$BEFORE_MAIN" "$AFTER_MAIN" "success" "更新なし"
  else
    MSG="✅ WBSS 本番deploy成功
host: $HOSTNAME_SHORT
by: $EXECUTED_BY
start: $START
end: $END
before: $BEFORE_MAIN
after: $AFTER_MAIN
${APP_BASE_URL}/"

    log_deploy "prod" "main" "$BEFORE_MAIN" "$AFTER_MAIN" "success" "更新あり"
  fi
else
  MSG="❌ WBSS 本番deploy失敗
host: $HOSTNAME_SHORT
by: $EXECUTED_BY
start: $START
end: $END
main_status: $MAIN_STATUS"

  log_deploy "prod" "main" "$BEFORE_MAIN" "$AFTER_MAIN" "failed" "deploy失敗"
fi

send_line "$MSG"

echo "===== DONE ====="