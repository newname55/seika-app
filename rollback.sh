#!/bin/bash

set +u

cd /var/www/html/wbss || exit 1

# ===== 環境変数 =====
ENV_FILE="/var/www/.wbss_env"
if [ -f "$ENV_FILE" ]; then
  source "$ENV_FILE"
else
  echo "[WARN] ENVファイルなし"
fi

LINE_TOKEN="${LINE_DEPLOY_TOKEN:-}"
LINE_USER="${LINE_DEPLOY_USER:-}"
APP_BASE_URL="${APP_BASE_URL:-https://haruto.asuscomm.com/wbss}"

DB_HOST="${DB_HOST:-localhost}"
DB_NAME="${DB_NAME:-wbss}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"

HOSTNAME_SHORT="$(hostname)"
EXECUTED_BY="$(whoami)"
START="$(date '+%Y-%m-%d %H:%M:%S')"

# ===== 現在commit =====
CURRENT_COMMIT="$(git rev-parse --short HEAD)"

# ===== rollback対象取得 =====
TARGET_COMMIT=$(mysql \
  -h "$DB_HOST" \
  -u "$DB_USER" \
  -p"$DB_PASS" \
  "$DB_NAME" \
  -N -e "
SELECT before_commit
FROM deploy_logs
WHERE environment='dev_to_main'
AND status='success'
ORDER BY id DESC
LIMIT 1;
" 2>/dev/null)

if [ -z "$TARGET_COMMIT" ]; then
  echo "rollback対象なし"
  exit 1
fi

# ===== 同一チェック =====
if [ "$TARGET_COMMIT" = "$CURRENT_COMMIT" ]; then
  echo "すでに対象commitです: $TARGET_COMMIT"
  exit 0
fi

echo "rollback → $TARGET_COMMIT"

# ===== rollback実行 =====
git fetch origin main
git reset --hard "$TARGET_COMMIT"

END="$(date '+%Y-%m-%d %H:%M:%S')"

MSG="⚠️ WBSS 本番rollback
host: $HOSTNAME_SHORT
by: $EXECUTED_BY
from: $CURRENT_COMMIT
to:   $TARGET_COMMIT
start: $START
end: $END
${APP_BASE_URL}/"

# ===== LINE通知 =====
if [ -n "$LINE_TOKEN" ] && [ -n "$LINE_USER" ]; then
  curl -sS -X POST "https://api.line.me/v2/bot/message/push" \
    -H "Authorization: Bearer ${LINE_TOKEN}" \
    -H "Content-Type: application/json" \
    -d "{\"to\":\"${LINE_USER}\",\"messages\":[{\"type\":\"text\",\"text\":\"$MSG\"}]}" >/dev/null
else
  echo "[WARN] LINE未設定"
fi

# ===== deployログにも記録（任意） =====
if [ -n "$DB_PASS" ]; then
  mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<SQL
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
  '$CURRENT_COMMIT',
  '$TARGET_COMMIT',
  'success',
  '$EXECUTED_BY',
  'manual rollback',
  NOW()
);
SQL
fi

echo "rollback完了"