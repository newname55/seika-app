#!/bin/bash

# ===== 安定設定 =====
set +u

cd /var/www/html/wbss || exit 1

# ===== 環境変数 =====
ENV_FILE="/var/www/.wbss_env"
if [ -f "$ENV_FILE" ]; then
  source "$ENV_FILE"
else
  echo "[WARN] ENVファイルなし: $ENV_FILE"
fi

LINE_TOKEN="${LINE_DEPLOY_TOKEN:-}"
LINE_USER="${LINE_DEPLOY_USER:-}"
CRON_SECRET_VALUE="${CRON_SECRET:-}"
APP_BASE_URL="${APP_BASE_URL:-https://haruto.asuscomm.com/wbss}"

# ===== 基本情報 =====
START="$(date '+%Y-%m-%d %H:%M:%S')"
END=""
HOSTNAME_SHORT="$(hostname)"
EXECUTED_BY="$(whoami)"

BEFORE_R4="$(git log --oneline -1 2>/dev/null || echo 'unknown')"
AFTER_R4=""
R4_STATUS=0

# ===== LINE送信 =====
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

# ===== deployログ保存 =====
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
    --data-urlencode "detail_text=${detail}" >/dev/null
}

# ===== DEPLOY開始 =====
echo "===== RASPI4 DEPLOY (dev) ====="
date

git reset --hard HEAD || R4_STATUS=$?
git fetch origin dev || R4_STATUS=$?
git reset --hard origin/dev || R4_STATUS=$?

AFTER_R4="$(git log --oneline -1 2>/dev/null || echo 'unknown')"

END="$(date '+%Y-%m-%d %H:%M:%S')"

# ===== 判定 =====
if [ "$R4_STATUS" -eq 0 ]; then

  if [ "$BEFORE_R4" = "$AFTER_R4" ]; then
    MSG="ℹ️ WBSS dev deploy（更新なし）
host: $HOSTNAME_SHORT
by: $EXECUTED_BY
start: $START
end: $END
commit: $AFTER_R4
${APP_BASE_URL}/"

    log_deploy "dev" "dev" "$BEFORE_R4" "$AFTER_R4" "success" "更新なし"
  else
    MSG="✅ WBSS dev deploy成功
host: $HOSTNAME_SHORT
by: $EXECUTED_BY
start: $START
end: $END
before: $BEFORE_R4
after:  $AFTER_R4
${APP_BASE_URL}/"

    log_deploy "dev" "dev" "$BEFORE_R4" "$AFTER_R4" "success" "更新あり"
  fi

else
  MSG="❌ WBSS dev deploy失敗
host: $HOSTNAME_SHORT
by: $EXECUTED_BY
start: $START
end: $END
status: $R4_STATUS"

  log_deploy "dev" "dev" "$BEFORE_R4" "$AFTER_R4" "failed" "gitエラー"
fi

# ===== LINE送信 =====
send_line "$MSG"

echo "===== DONE ====="