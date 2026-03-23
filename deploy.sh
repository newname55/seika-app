#!/bin/bash

set -u

cd /var/www/html/wbss || exit 1

# ===== 環境変数読み込み =====
ENV_FILE="/var/www/.wbss_env"
[ -f "$ENV_FILE" ] && source "$ENV_FILE"

LINE_TOKEN="${LINE_DEPLOY_TOKEN:-}"
LINE_USER="${LINE_DEPLOY_USER:-}"
CRON_SECRET_VALUE="${CRON_SECRET:-}"

APP_BASE_URL="${APP_BASE_URL:-https://haruto.asuscomm.com/wbss}"

echo "LINE_TOKEN_SET=$([ -n "$LINE_TOKEN" ] && echo yes || echo no)"
echo "LINE_USER_SET=$([ -n "$LINE_USER" ] && echo yes || echo no)"
echo "MSG_BEGIN"
printf '%s\n' "$MSG"
echo "MSG_END"

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
    -d "$json"
}

log_deploy() {
  local env_name="$1"
  local branch_name="$2"
  local before_commit="$3"
  local after_commit="$4"
  local status="$5"
  local detail="$6"

  if [ -z "$CRON_SECRET_VALUE" ]; then
    echo "[WARN] CRON_SECRET 未設定のため deploy_logs 保存をスキップ"
    return 0
  fi

  curl -sS -X POST "${APP_BASE_URL}/public/api/deploy_log.php" \
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

BEFORE_R4="$(git log --oneline -1 2>/dev/null || echo 'unknown')"
AFTER_R4=""
R4_STATUS=0
R5_STATUS=0
R5_RESULT=""

echo "===== RASPI4 DEPLOY (dev) ====="
date
git reset --hard HEAD || R4_STATUS=$?
git pull origin dev || R4_STATUS=$?

AFTER_R4="$(git log --oneline -1 2>/dev/null || echo 'unknown')"

echo "===== RASPI5 DEPLOY (main) ====="
#R5_RESULT="$(ssh raspi5-deploy 'cd /var/www/html/wbss && ./deploy_prod.sh' 2>&1)" || R5_STATUS=$?
R5_RESULT="skip"
R5_STATUS=0

END="$(date '+%Y-%m-%d %H:%M:%S')"

if [ "$R4_STATUS" -eq 0 ] && [ "$R5_STATUS" -eq 0 ]; then
  if [ "$BEFORE_R4" = "$AFTER_R4" ]; then
    MSG="ℹ️ WBSS deploy完了（更新なし）
host: $HOSTNAME_SHORT
by: $EXECUTED_BY
start: $START
end: $END
raspi4(dev): $AFTER_R4
raspi5(main): OK
${APP_BASE_URL}/"

    log_deploy "dev_to_main" "dev/main" "$BEFORE_R4" "$AFTER_R4" "success" "更新なし / raspi5(main) 同期成功"
  else
    MSG="✅ WBSS deploy成功
host: $HOSTNAME_SHORT
by: $EXECUTED_BY
start: $START
end: $END
raspi4(dev): $AFTER_R4
raspi5(main): OK
${APP_BASE_URL}/"

    log_deploy "dev_to_main" "dev/main" "$BEFORE_R4" "$AFTER_R4" "success" "raspi4(dev) 更新・raspi5(main) 同期成功"
  fi
else
  DETAIL="raspi4_status=$R4_STATUS / raspi5_status=$R5_STATUS"
  if [ -n "$R5_RESULT" ]; then
    DETAIL="$DETAIL / raspi5_log=$(printf '%s' "$R5_RESULT" | tail -n 5 | tr '\n' ' ')"
  fi

  MSG="❌ WBSS deploy失敗
host: $HOSTNAME_SHORT
by: $EXECUTED_BY
start: $START
end: $END
raspi4_status: $R4_STATUS
raspi5_status: $R5_STATUS"

  log_deploy "dev_to_main" "dev/main" "$BEFORE_R4" "$AFTER_R4" "failed" "$DETAIL"
fi

send_line "$MSG"

echo "===== DONE ====="
