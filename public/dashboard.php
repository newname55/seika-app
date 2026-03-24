<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/repo_casts.php';
require_once __DIR__ . '/../app/service_messages.php';
require_once __DIR__ . '/../app/store.php';

require_login();
$pdo = db();

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('has_role')) {
  function has_role(string $role): bool {
    return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
  }
}

$userId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);

$isSuper   = has_role('super_user');
$isAdmin   = has_role('admin');
$isManager = has_role('manager');
$isCast    = has_role('cast');

// cast専用へ
if ($isCast && !$isAdmin && !$isManager && !$isSuper) {
  header('Location: /wbss/public/dashboard_cast.php');
  exit;
}

// ✅ super_user は「未選択なら店舗選択を挟む」
require_store_selected_for_super($isSuper, '/wbss/public/dashboard.php');

/**
 * ✅ 店舗一覧の方針
 * - super_user: 全店
 * - admin: 全店（切り替えたい）
 * - manager: 自分に紐づく店だけ
 */
$stores = [];
if ($isSuper || $isAdmin) {
  $stores = $pdo->query("SELECT id,name FROM stores WHERE is_active=1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} elseif ($isManager) {
  $stores = repo_allowed_stores($pdo, $userId, false);
}

$storeId = 0;
if ($stores) {
  // GET優先 → セッション（統合キー） → 先頭
  $candidate = (int)($_GET['store_id'] ?? 0);
  if ($candidate <= 0) $candidate = get_current_store_id();
  if ($candidate <= 0) $candidate = (int)$stores[0]['id'];

  $allowedIds = array_map(fn($s)=>(int)$s['id'], $stores);
  if (!in_array($candidate, $allowedIds, true)) {
    $candidate = (int)$stores[0]['id'];
  }

  $storeId = $candidate;
  set_current_store_id($storeId); // ✅ 統一して保存
}

// 店舗名
$storeName = '';
foreach ($stores as $s) {
  if ((int)$s['id'] === $storeId) { $storeName = (string)$s['name']; break; }
}
$canSwitchStores = count($stores) > 1;
// ===== dashboard safe defaults =====
$lineUnlinkedCount = 0;
$messageSummary = [
  'unread_count' => 0,
  'recent_thanks' => [],
];
$staffStores       = $staffStores ?? [];
$themeOptions = [
  'light' => 'Light',
  'dark'  => 'Dark',
  'cast'  => 'Pink',
  'staff' => 'Blue',
];
$currentTheme = function_exists('current_ui_theme') ? current_ui_theme() : 'dark';
$loginId = (string)($_SESSION['login_id'] ?? '');
$displayName = (string)($_SESSION['display_name'] ?? '');
$currentUserName = $displayName !== '' ? $displayName : $loginId;
$currentUserInitial = mb_strtoupper(mb_substr($currentUserName !== '' ? $currentUserName : 'U', 0, 1));

if ($storeId > 0 && $userId > 0) {
  $messageSummary = message_fetch_dashboard_summary($pdo, $storeId, $userId, 3);
}

if (!function_exists('dashboard_link')) {
  function dashboard_link(string $path, int $storeId = 0, array $extra = []): string {
    if ($storeId > 0) {
      $extra['store_id'] = $storeId;
    }
    if ($extra === []) {
      return $path;
    }
    $query = http_build_query($extra);
    return $path . (str_contains($path, '?') ? '&' : '?') . $query;
  }
}

if (!function_exists('render_dashboard_section')) {
  function render_dashboard_section(array $section, bool $isActive = false, int $index = 0): void {
    if (empty($section['items'])) {
      return;
    }
    $sectionClass = trim((string)($section['class'] ?? ''));
    $panelId = 'dashboard-panel-' . $index;
    ?>
    <section
      id="<?= h($panelId) ?>"
      class="dash-section dash-panel<?= $sectionClass !== '' ? ' ' . h($sectionClass) : '' ?><?= $isActive ? ' is-active' : '' ?>"
      data-tab-panel
      <?= $isActive ? '' : 'hidden' ?>
    >
      <div class="dash-section-head">
        <div>
          <h2><?= h((string)$section['title']) ?></h2>
          <?php if (!empty($section['lead'])): ?>
            <p><?= h((string)$section['lead']) ?></p>
          <?php endif; ?>
        </div>
      </div>
      <div class="dash-grid">
        <?php foreach ($section['items'] as $item): ?>
          <a class="dash-card<?= !empty($item['tone']) ? ' is-' . h((string)$item['tone']) : '' ?>" href="<?= h((string)$item['href']) ?>">
            <div class="dash-card-top">
              <span class="dash-icon"><?= h((string)$item['icon']) ?></span>
              <?php if (!empty($item['tag'])): ?>
                <span class="dash-tag"><?= h((string)$item['tag']) ?></span>
              <?php endif; ?>
            </div>
            <div class="dash-title"><?= h((string)$item['title']) ?></div>
            <div class="dash-desc"><?= h((string)$item['desc']) ?></div>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php
  }
}

if (!function_exists('render_dashboard_focus_actions')) {
  function render_dashboard_focus_actions(array $items): void {
    if (!$items) return;
    ?>
    <section class="dashboard-focus">
      <div class="dashboard-focus__head">
        <div>
          <h2>⭐️まずここから始めます⭐️</h2>
          <p>今日よく使う入口だけ先に置いています。</p>
        </div>
      </div>
      <div class="dashboard-focus__grid">
        <?php foreach ($items as $item): ?>
          <a class="focus-card<?= !empty($item['tone']) ? ' is-' . h((string)$item['tone']) : '' ?>" href="<?= h((string)$item['href']) ?>">
            <div class="focus-card__icon"><?= h((string)$item['icon']) ?></div>
            <div class="focus-card__body">
              <div class="focus-card__title"><?= h((string)$item['title']) ?></div>
              <div class="focus-card__desc"><?= h((string)$item['desc']) ?></div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php
  }
}

if (!function_exists('render_dashboard_message_summary')) {
  function render_dashboard_message_summary(array $summary, int $storeId): void {
    $recentThanks = $summary['recent_thanks'] ?? [];
    $unreadCount = (int)($summary['unread_count'] ?? 0);
    $monthlyThanksCount = (int)($summary['monthly_thanks_count'] ?? 0);
    ?>
    <section class="dashboard-message-strip">
      <a class="message-summary-card is-message" href="<?= h(dashboard_link('/wbss/public/messages.php', $storeId)) ?>">
        <div class="message-summary-card__top">
          <span class="message-summary-card__icon">📨</span>
          <span class="message-summary-card__pill"><?= $unreadCount > 0 ? '未読あり' : '確認済み' ?></span>
        </div>
        <div class="message-summary-card__title">未読メッセージ</div>
        <div class="message-summary-card__count"><?= (int)$unreadCount ?> 件</div>
        <div class="message-summary-card__desc">自分宛の業務連絡を確認します。</div>
        <div class="message-summary-card__links">
          <span>一覧を見る</span>
          <span>送信する</span>
        </div>
      </a>

      <a class="message-summary-card is-thanks" href="<?= h(dashboard_link('/wbss/public/thanks.php', $storeId)) ?>">
        <div class="message-summary-card__top">
          <span class="message-summary-card__icon">💐</span>
          <span class="message-summary-card__pill">最近のありがとう</span>
        </div>
        <div class="message-summary-card__title">感謝カード</div>
        <div class="message-summary-card__meta">今月 <?= (int)$monthlyThanksCount ?> 件</div>
        <?php if ($recentThanks): ?>
          <div class="message-summary-card__thanks-list">
            <?php foreach ($recentThanks as $thanks): ?>
              <div class="message-summary-card__thanks-item">
                <strong><?= h(trim((string)($thanks['title'] ?? '')) !== '' ? (string)$thanks['title'] : 'ありがとう') ?></strong>
                <span><?= h((string)($thanks['sender_name'] ?? '')) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="message-summary-card__desc">最近のありがとうはまだ届いていません。</div>
        <?php endif; ?>
        <div class="message-summary-card__links">
          <span>一覧を見る</span>
          <span>ありがとうを送る</span>
        </div>
      </a>
    </section>
    <?php
  }
}

$roleLabel = 'スタッフ';
if ($isSuper) {
  $roleLabel = '全体管理';
} elseif ($isAdmin) {
  $roleLabel = '管理者';
} elseif ($isManager) {
  $roleLabel = '店長';
}

$commonTodayItems = [
  [
    'icon' => '📋',
    'title' => '今日の出勤と予定',
    'desc' => '遅刻・欠勤連絡や今日の配置を最初に確認します。',
    'href' => '/wbss/public/manager_today_schedule.php',
    'tag'  => '最初に見る',
    'tone' => 'primary',
  ],
  [
    'icon' => '💰',
    'title' => '会計を開く',
    'desc' => 'お会計や伝票入力をすぐ始めます。',
    'href' => '/wbss/public/cashier/index.php',
    'tag'  => '毎日使う',
    'tone' => 'primary',
  ],
  [
    'icon' => '🍺',
    'title' => '注文対応を見る',
    'desc' => '注文状況を確認して現場対応します。',
    'href' => '/wbss/public/orders/dashboard_orders.php',
    'tag'  => '店内対応',
  ],
  [
    'icon' => '📦',
    'title' => '在庫を確認する',
    'desc' => '不足や仕入れの判断を行います。',
    'href' => '/wbss/public/stock/index.php',
    'tag'  => '確認',
  ],
];

$adminControlItems = [
  [
    'icon' => '🏬',
    'title' => '全店売上・出勤ビュー',
    'desc' => '全店舗の今の売上と出勤状況を1画面で確認します。',
    'href' => '/wbss/public/all_store_status.php',
    'tag'  => '全店ビュー',
    'tone' => 'primary',
  ],
  [
    'icon' => '🧑‍💼',
    'title' => '全店キャストKPI',
    'desc' => '全店舗のキャスト売上寄与と勤務実績を比較します。',
    'href' => '/wbss/public/all_cast_kpi.php',
    'tag'  => '全店比較',
    'tone' => 'primary',
  ],
  [
    'icon' => '🗂️',
    'title' => '全店キャスト一括登録',
    'desc' => '全店舗分のキャストをまとめて入力して自動割り当てします。',
    'href' => '/wbss/public/store_casts_bulk.php',
    'tag'  => '一括登録',
    'tone' => 'primary',
  ],
  [
    'icon' => '🗓️',
    'title' => '全店イベントカレンダー',
    'desc' => '複数店舗の予定をまとめて見て、店ごとの動きを比べます。',
    'href' => dashboard_link('/wbss/public/store_events/all.php', $storeId),
    'tag'  => '統合ビュー',
  ],
  [
    'icon' => '🧾',
    'title' => '面接者一覧',
    'desc' => '面接、体験入店、在籍、店舗移動の流れをまとめて管理します。',
    'href' => '/wbss/public/applicants/index.php',
    'tag'  => '採用台帳',
    'tone' => 'primary',
  ],
  [
    'icon' => '🏪',
    'title' => '店舗設定',
    'desc' => '営業時間や営業日切り替え時刻など店舗情報を管理します。',
    'href' => dashboard_link('/wbss/public/store_settings.php', $storeId),
    'tag'  => '店舗',
    'tone' => 'primary',
  ],
  [
    'icon' => '👤',
    'title' => 'ユーザー管理',
    'desc' => '権限や連携状況を管理します。',
    'href' => '/wbss/public/admin_users.php',
    'tag'  => '権限',
  ],
  [
    'icon' => '⚙️',
    'title' => '管理画面を開く',
    'desc' => '詳細な設定や管理メニューを開きます。',
    'href' => '/wbss/public/admin/index.php',
    'tag'  => '詳細',
  ],
];

if ($isSuper) {
  $adminControlItems[] = [
    'icon' => '🧯',
    'title' => '本番 deploy / rollback',
    'desc' => '本番履歴の確認と rollback 管理UIを開きます。',
    'href' => '/wbss/public/admin/deploy_history.php',
    'tag'  => 'super_user',
    'tone' => 'warn',
  ];
}

$adminSections = [
  [
    'title' => '今すぐ対応',
    'lead'  => '今日まず開く画面だけに絞っています。',
    'items' => array_merge($commonTodayItems, [
      [
        'icon' => '🕒',
        'title' => '出勤一覧を見る',
        'desc' => '今日来る人と、もう出勤した人を一覧で見ます。',
        'href' => '/wbss/public/attendance/index.php',
        'tag'  => '勤怠',
        'tone' => 'primary',
      ],
      [
        'icon' => '📈',
        'title' => '今日の数字を見る',
        'desc' => '売上やポイントKPIの流れを確認します。',
        'href' => dashboard_link('/wbss/public/points_kpi.php', $storeId),
        'tag'  => '数字',
      ],
    ]),
  ],
  [
    'title' => 'スタッフを整える',
    'lead'  => 'シフト、所属、招待、未連携確認をまとめています。',
    'class' => 'dash-section-staff',
    'items' => [
      [
        'icon' => '📆',
        'title' => '出勤予定を決める',
        'desc' => '今週のシフトを見たり入力したりします。',
        'href' => dashboard_link('/wbss/public/cast_week_plans.php', $storeId),
        'tag'  => 'シフト',
      ],
      [
        'icon' => '📋',
        'title' => '本日の予定',
        'desc' => '遅刻や欠勤の連絡をまとめて確認します。',
        'href' => dashboard_link('/wbss/public/manager_today_schedule.php', $storeId),
        'tag'  => '今日',
      ],
      [
        'icon' => '👥',
        'title' => 'キャスト管理',
        'desc' => '所属や招待リンク、LINE確認を行います。',
        'href' => dashboard_link('/wbss/public/store_casts.php', $storeId),
        'tag'  => 'スタッフ',
      ],
      [
        'icon' => '✏️',
        'title' => 'キャスト情報を直す',
        'desc' => '名前や雇用区分、店番を編集します。',
        'href' => dashboard_link('/wbss/public/admin/cast_edit.php', $storeId),
        'tag'  => '編集',
      ],
      [
        'icon' => '🔗',
        'title' => '招待リンク管理',
        'desc' => 'LINE登録用の招待リンクを発行してQR共有します。',
        'href' => dashboard_link('/wbss/public/store_casts_invites.php', $storeId),
        'tag'  => '招待',
      ],
      [
        'icon' => '⚠️',
        'title' => 'LINE未連携を確認',
        'desc' => 'まだ連携していない人だけ一覧で見ます。',
        'href' => dashboard_link('/wbss/public/store_casts_list.php', $storeId, ['filter' => 'line_unlinked']),
        'tag'  => $lineUnlinkedCount > 0 ? ('未連携 ' . $lineUnlinkedCount . '件') : '要確認',
        'tone' => 'warn',
      ],
      [
        'icon' => '📄',
        'title' => 'キャスト一覧を見る',
        'desc' => '今の店舗のキャスト一覧だけを見ます。',
        'href' => dashboard_link('/wbss/public/store_casts_list.php', $storeId),
        'tag'  => '一覧',
      ],
    ],
  ],
  [
    'title' => '数字と状況を見る',
    'lead'  => '今の店舗の数字、顧客、在庫、レポートを見返す画面です。',
    'items' => [
      [
        'icon' => '📦',
        'title' => '在庫を見る',
        'desc' => '在庫、仕入れ、粗利の状況を確認します。',
        'href' => '/wbss/public/stock/index.php',
        'tag'  => '在庫',
      ],
      [
        'icon' => '⭐',
        'title' => 'ポイントを入力',
        'desc' => '同伴や指名を日ごとに手入力します。レガシー運用です。',
        'href' => dashboard_link('/wbss/public/points_day.php', $storeId),
        'tag'  => 'レガシー',
      ],
      [
        'icon' => '📊',
        'title' => 'ポイント集計を見る',
        'desc' => '半月ごとの集計結果を確認します。',
        'href' => dashboard_link('/wbss/public/points_terms.php', $storeId),
        'tag'  => '集計',
      ],
      [
        'icon' => '⏱',
        'title' => '出勤レポート',
        'desc' => '日別やスタッフ別の勤務結果を見ます。',
        'href' => dashboard_link('/wbss/public/attendance/reports.php', $storeId),
        'tag'  => 'レポート',
      ],
      [
        'icon' => '👤',
        'title' => '顧客カルテ',
        'desc' => '来店履歴やNG情報などを確認します。',
        'href' => dashboard_link('/wbss/public/customer/', $storeId),
        'tag'  => 'お客様',
      ],
      [
        'icon' => '🗒️',
        'title' => '営業ノート',
        'desc' => 'お客様メモを残したり見直したりします。',
        'href' => dashboard_link('/wbss/public/customer.php', $storeId),
        'tag'  => 'メモ',
      ],
    ],
  ],
  [
    'title' => '予定と設定を整える',
    'lead'  => 'イベント準備や日常設定をまとめています。',
    'items' => [
      [
        'icon' => '🎉',
        'title' => '店舗イベント管理',
        'desc' => '月間カレンダーで店内イベントを確認して入力します。',
        'href' => dashboard_link('/wbss/public/store_events/index.php', $storeId, ['tab' => 'internal']),
        'tag'  => 'イベント',
        'tone' => 'primary',
      ],
      [
        'icon' => '🗓️',
        'title' => '全店イベントカレンダー',
        'desc' => '複数店舗の予定をまとめて見て、店ごとの動きを比べます。',
        'href' => dashboard_link('/wbss/public/store_events/all.php', $storeId),
        'tag'  => '統合ビュー',
      ],
      [
        'icon' => '🗺️',
        'title' => '岡山イベントを見る',
        'desc' => '外部イベント情報を確認して店内イベントに取り込みます。',
        'href' => '/wbss/public/events/list.php',
        'tag'  => '外部情報',
      ],
    ],
  ],
  [
    'title' => '全店・管理者',
    'lead'  => '全店舗ビューや管理画面など、管理者向けの項目だけを集めています。',
    'items' => $adminControlItems,
  ],
];

$managerSections = [
  [
    'title' => '今すぐ対応',
    'lead'  => '今日まず押す画面だけ先に並べています。',
    'items' => [
      [
        'icon' => '📋',
        'title' => '本日の予定',
        'desc' => '遅刻や欠勤の連絡を確認します。',
        'href' => dashboard_link('/wbss/public/manager_today_schedule.php', $storeId),
        'tag'  => '最初に見る',
        'tone' => 'primary',
      ],
      [
        'icon' => '📆',
        'title' => '出勤予定を決める',
        'desc' => '今週のシフトを見たり調整したりします。',
        'href' => dashboard_link('/wbss/public/cast_week_plans.php', $storeId),
        'tag'  => 'シフト',
        'tone' => 'primary',
      ],
      [
        'icon' => '📦',
        'title' => '在庫を見る',
        'desc' => '在庫一覧を開いて不足や残りを確認します。',
        'href' => '/wbss/public/stock/list.php',
        'tag'  => '確認',
      ],
      [
        'icon' => '📈',
        'title' => '今日の数字を見る',
        'desc' => '伝票から自動集計した日別KPIを確認します。',
        'href' => dashboard_link('/wbss/public/points_kpi.php', $storeId),
        'tag'  => '数字',
      ],
      [
        'icon' => '💰',
        'title' => '会計を開く',
        'desc' => 'お会計や伝票作成を始めます。',
        'href' => '/wbss/public/cashier/index.php',
        'tag'  => '会計',
      ],
    ],
  ],
  [
    'title' => 'スタッフを整える',
    'lead'  => 'キャスト情報、招待、未連携確認をまとめています。',
    'class' => 'dash-section-staff',
    'items' => [
      [
        'icon' => '✏️',
        'title' => 'キャスト情報を直す',
        'desc' => '名前や雇用区分、店番を編集します。',
        'href' => dashboard_link('/wbss/public/admin/cast_edit.php', $storeId),
        'tag'  => '編集',
      ],
      [
        'icon' => '👥',
        'title' => 'キャスト管理',
        'desc' => '所属や招待リンクをまとめて管理します。',
        'href' => dashboard_link('/wbss/public/store_casts.php', $storeId),
        'tag'  => 'スタッフ',
      ],
      [
        'icon' => '🔗',
        'title' => '招待リンク管理',
        'desc' => '招待リンクを発行してQR共有します。',
        'href' => dashboard_link('/wbss/public/store_casts_invites.php', $storeId),
        'tag'  => '招待',
      ],
      [
        'icon' => '⚠️',
        'title' => 'LINE未連携を確認',
        'desc' => 'まだ連携していない人だけを見ます。',
        'href' => dashboard_link('/wbss/public/store_casts_list.php', $storeId, ['filter' => 'line_unlinked']),
        'tag'  => $lineUnlinkedCount > 0 ? ('未連携 ' . $lineUnlinkedCount . '件') : '要確認',
        'tone' => 'warn',
      ],
    ],
  ],
  [
    'title' => '数字とお客様を見る',
    'lead'  => '集計、顧客、勤務結果などの見返し用です。',
    'items' => [
      [
        'icon' => '⭐',
        'title' => 'ポイントを入力',
        'desc' => '日ごとのポイントを手入力します。レガシー運用です。',
        'href' => dashboard_link('/wbss/public/points_day.php', $storeId),
        'tag'  => 'レガシー',
      ],
      [
        'icon' => '📊',
        'title' => 'ポイント集計を見る',
        'desc' => '半月ごとの集計結果を確認します。',
        'href' => dashboard_link('/wbss/public/points_terms.php', $storeId),
        'tag'  => '集計',
      ],
      [
        'icon' => '⏱',
        'title' => '出勤レポート',
        'desc' => '日別やスタッフ別で勤務結果を見ます。',
        'href' => dashboard_link('/wbss/public/attendance/reports.php', $storeId),
        'tag'  => 'レポート',
      ],
      [
        'icon' => '👤',
        'title' => '顧客カルテ',
        'desc' => '来店履歴や注意点を確認します。',
        'href' => dashboard_link('/wbss/public/customer/', $storeId),
        'tag'  => 'お客様',
      ],
      [
        'icon' => '🗒️',
        'title' => '営業ノート',
        'desc' => 'お客様メモを記録して見返します。',
        'href' => dashboard_link('/wbss/public/customer.php', $storeId),
        'tag'  => 'メモ',
      ],
      [
        'icon' => '🗓️',
        'title' => 'イベント予定を見る',
        'desc' => '自分が見られる店舗分をまとめて確認します。',
        'href' => dashboard_link('/wbss/public/store_events/all.php', $storeId),
        'tag'  => '予定',
      ],
      [
        'icon' => '🎉',
        'title' => '店舗イベント管理',
        'desc' => '月間カレンダーでイベント予定を入力します。',
        'href' => dashboard_link('/wbss/public/store_events/index.php', $storeId, ['tab' => 'internal']),
        'tag'  => 'イベント',
      ],
    ],
  ],
];

$sections = ($isAdmin || $isSuper) ? $adminSections : $managerSections;
$defaultTab = 0;
$focusActions = ($isAdmin || $isSuper)
  ? [
      $commonTodayItems[0],
      $commonTodayItems[1],
      [
        'icon' => '📈',
        'title' => '今日の数字を見る',
        'desc' => '売上とKPIの流れを確認します。',
        'href' => dashboard_link('/wbss/public/points_kpi.php', $storeId),
        'tone' => 'primary',
      ],
    ]
  : [
      [
        'icon' => '📋',
        'title' => '本日の予定',
        'desc' => '遅刻や欠勤の連絡を最初に見ます。',
        'href' => dashboard_link('/wbss/public/manager_today_schedule.php', $storeId),
        'tone' => 'primary',
      ],
      [
        'icon' => '📆',
        'title' => '出勤予定を決める',
        'desc' => '今週のシフトを調整します。',
        'href' => dashboard_link('/wbss/public/cast_week_plans.php', $storeId),
        'tone' => 'primary',
      ],
      [
        'icon' => '📦',
        'title' => '在庫を見る',
        'desc' => '不足や残りを先に確認します。',
        'href' => '/wbss/public/stock/list.php',
      ],
    ];

/* =========================
   表示
========================= */
render_page_start('ダッシュボード');
?>

<div class="page">
  <div class="admin-wrap dashboard-shell">
    <section class="dashboard-topbar">
      <div class="dashboard-hero-main">
        <div class="dashboard-hero-grid">
          <div class="dashboard-hero-copy">
            <div class="hero-store-label">現在の店舗</div>
            <div class="hero-store-name"><?= h($storeName ?: ('#' . $storeId)) ?></div>
            <div class="hero-store-meta">STORE #<?= (int)$storeId ?></div>
            <span class="hero-badge"><?= h($roleLabel) ?>メニュー</span>
            <h1>何をしたいかで選べる管理画面</h1>
            <p>ボタンを役割別ではなく、やりたいこと別に整理しました。迷ったら下の「まずここから」だけ見れば大丈夫です。</p>
          </div>

          <div class="dashboard-hero-tools">
            <?php if (($isSuper || $isAdmin || $isManager) && !empty($stores) && $canSwitchStores): ?>
            <form method="get" class="store-switch dashboard-inline-panel">
              <div class="store-switch-compact-row">
                <div class="store-switch-compact-meta">
                  <span class="store-switch-compact-label">現在の店舗</span>
                  <span class="store-switch-compact-name"><?= h($storeName ?: ('#' . $storeId)) ?> <small>(#<?= (int)$storeId ?>)</small></span>
                </div>
                <button
                  type="button"
                  class="store-switch-toggle"
                  data-store-switch-toggle
                  aria-expanded="false"
                  aria-controls="dashboard-store-switch-panel"
                  aria-label="店舗を変更"
                  title="店舗を変更"
                >✎</button>
              </div>
              <div class="store-switch-panel" id="dashboard-store-switch-panel" data-store-switch-panel>
                <div class="store-switch-label">店舗切り替え</div>
                <div class="store-switch-row">
                <select id="store_id" name="store_id" class="sel store-switch-select" onchange="this.form.submit()">
                  <?php foreach ($stores as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id'] === $storeId) ? 'selected' : '' ?>>
                      <?= h((string)$s['name']) ?> (#<?= (int)$s['id'] ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                </div>
                <div class="store-switch-help">この店舗が、在庫や顧客など他の画面でも使われます。</div>
              </div>
            </form>
            <?php endif; ?>

            <details class="dashboard-user-menu dashboard-inline-menu">
              <summary class="dashboard-user-menu__summary dashboard-user-menu__summary--compact">
                <span class="dashboard-user-menu__avatar"><?= h($currentUserInitial) ?></span>
                <span class="dashboard-user-menu__meta">
                  <span class="dashboard-user-menu__name"><?= h($currentUserName !== '' ? $currentUserName : '-') ?></span>
                  <span class="dashboard-user-menu__label">ログイン中</span>
                </span>
                <span class="dashboard-user-menu__hamburger" aria-hidden="true">☰</span>
              </summary>
              <div class="dashboard-user-menu__panel">
                <div class="dashboard-user-menu__section-title">ユーザーテーマ</div>
                <div class="theme-inline-row">
                  <?php foreach ($themeOptions as $themeKey => $themeLabel): ?>
                    <button
                      type="button"
                      class="theme-chip<?= $currentTheme === $themeKey ? ' is-on' : '' ?>"
                      data-theme="<?= h($themeKey) ?>"
                    ><?= h($themeLabel) ?></button>
                  <?php endforeach; ?>
                </div>
                <div class="dashboard-user-menu__sep"></div>
                <div
                  class="dashboard-user-push"
                  data-push-ui
                  data-store-id="<?= (int)$storeId ?>"
                  data-csrf="<?= h(csrf_token()) ?>"
                  data-unread-count="<?= (int)($messageSummary['unread_count'] ?? 0) ?>"
                >
                  <div class="dashboard-user-menu__section-title">通知</div>
                  <div class="dashboard-user-push__row">
                    <div class="dashboard-user-push__status" data-push-status>状態を確認中…</div>
                    <div class="dashboard-user-push__actions">
                      <button class="btn btn-micro" type="button" data-push-enable>ON</button>
                      <button class="btn btn-micro" type="button" data-push-disable hidden>OFF</button>
                    </div>
                  </div>
                </div>
                <div class="dashboard-user-menu__sep"></div>
                <a class="dashboard-user-menu__logout" href="/wbss/public/logout.php">ログアウト</a>
              </div>
            </details>
          </div>
        </div>
      </div>

      <div class="dashboard-side-stack">
        <?php render_dashboard_message_summary($messageSummary, $storeId); ?>
      </div>
    </section>

    <?php render_dashboard_focus_actions($focusActions); ?>

    <div class="dash-wrap">
      <div class="dash-tabs" role="tablist" aria-label="ダッシュボード切り替え">
        <?php foreach ($sections as $index => $section): ?>
          <?php $isActiveTab = ($index === $defaultTab); ?>
          <button
            type="button"
            class="dash-tab<?= $isActiveTab ? ' is-active' : '' ?>"
            data-tab-button
            data-target="dashboard-panel-<?= (int)$index ?>"
            role="tab"
            aria-selected="<?= $isActiveTab ? 'true' : 'false' ?>"
            aria-controls="dashboard-panel-<?= (int)$index ?>"
            id="dashboard-tab-<?= (int)$index ?>"
          ><?= h((string)$section['title']) ?></button>
        <?php endforeach; ?>
      </div>

      <?php foreach ($sections as $index => $section): ?>
        <?php render_dashboard_section($section, $index === $defaultTab, $index); ?>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<style>
.dashboard-shell{
  max-width:1320px;
  padding-bottom:28px;
}

.app-header{
  display:none;
}

.dashboard-topbar{
  display:grid;
  grid-template-columns: minmax(0, 1.9fr) minmax(250px, .82fr);
  gap:12px;
  margin-top:10px;
  margin-bottom:14px;
  align-items:start;
}

.dashboard-hero-main,
.dashboard-store-box,
.dash-section{
  border:1px solid var(--line);
  border-radius:22px;
  background:
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02)),
    var(--cardA);
  box-shadow:0 16px 40px rgba(0,0,0,.14);
}

.hero-badge{
  display:inline-flex;
  align-items:center;
  padding:6px 10px;
  border-radius:999px;
  font-size:12px;
  font-weight:800;
  color:#0f172a;
  background:linear-gradient(135deg, #facc15, #fb923c);
}

.hero-store-name{
  font-size:34px;
  font-weight:1000;
  color:var(--txt);
  line-height:1.05;
  letter-spacing:.01em;
}

.hero-store-label{
  font-size:12px;
  font-weight:900;
  color:var(--muted);
  text-transform:uppercase;
  letter-spacing:.08em;
}

.hero-store-meta{
  margin-top:6px;
  margin-bottom:10px;
  font-size:12px;
  font-weight:900;
  color:var(--muted);
}

.dashboard-hero-main{
  padding:20px 22px;
  min-height:152px;
}

.dashboard-hero-grid{
  display:grid;
  grid-template-columns:minmax(0, 1.55fr) minmax(220px, .62fr);
  gap:18px;
  align-items:start;
}

.dashboard-hero-copy{
  display:flex;
  flex-direction:column;
  justify-content:center;
  min-height:100%;
}

.dashboard-hero-tools{
  display:flex;
  flex-direction:column;
  gap:10px;
  align-items:stretch;
}

.dashboard-hero-tools .store-switch{
  width:100%;
}

.dashboard-hero-main h1{
  margin:10px 0 8px;
  font-size:25px;
  line-height:1.2;
}

.dashboard-hero-main p{
  margin:0;
  color:var(--muted);
  font-size:13px;
  line-height:1.55;
}

.dashboard-side-stack{
  display:grid;
  gap:12px;
  align-content:start;
}

.dashboard-user-mini{
  display:flex;
  align-items:center;
  gap:10px;
  padding:10px 12px;
  border:1px solid var(--line);
  border-radius:16px;
  background:rgba(255,255,255,.04);
}

.dashboard-inline-menu{
  width:100%;
}

.dashboard-user-mini__meta{
  min-width:0;
  display:flex;
  flex-direction:column;
  gap:2px;
}

.store-switch{
  display:flex;
  flex-direction:column;
  gap:6px;
}

.store-switch-compact-row{
  display:none;
}

.store-switch-compact-meta{
  min-width:0;
  display:grid;
  gap:2px;
}

.store-switch-compact-label{
  font-size:11px;
  font-weight:800;
  color:var(--muted);
}

.store-switch-compact-name{
  font-size:14px;
  font-weight:900;
  color:var(--txt);
  line-height:1.2;
}

.store-switch-compact-name small{
  font-size:11px;
  font-weight:800;
  color:var(--muted);
}

.store-switch-toggle{
  appearance:none;
  border:1px solid var(--line);
  background:rgba(255,255,255,.04);
  color:var(--txt);
  border-radius:999px;
  min-height:32px;
  width:32px;
  min-width:32px;
  padding:0;
  font-size:14px;
  font-weight:900;
  line-height:1;
  cursor:pointer;
  flex:0 0 auto;
  white-space:nowrap;
}

.store-switch-toggle:hover{
  border-color:color-mix(in srgb, var(--accent) 40%, var(--line));
  background:color-mix(in srgb, var(--accent) 10%, transparent);
}

.store-switch-panel{
  display:grid;
  gap:6px;
}

.store-current{
  display:flex;
  flex-direction:column;
  gap:6px;
  min-width:0;
}

.store-switch-label{
  font-size:13px;
  font-weight:900;
}

.store-switch-row{
  display:flex;
  gap:8px;
  align-items:center;
  flex-wrap:wrap;
}

.store-switch-select{
  width:100%;
  min-width:0;
}

.store-switch-help{
  margin:0;
  font-size:11px;
  color:var(--muted);
}

.dashboard-inline-panel{
  padding:10px 12px;
  border:1px solid var(--line);
  border-radius:16px;
  background:rgba(255,255,255,.03);
}

.theme-inline-row{
  display:flex;
  flex-wrap:wrap;
  gap:8px;
  align-items:center;
}

.dashboard-user-menu{
  position:relative;
  width:100%;
}

.dashboard-user-menu[open]{
  z-index:5;
}

.dashboard-user-menu__summary{
  list-style:none;
  display:flex;
  align-items:center;
  gap:10px;
  cursor:pointer;
  user-select:none;
  border:1px solid var(--line);
  border-radius:16px;
  padding:10px 12px;
  background:rgba(255,255,255,.04);
}

.dashboard-user-menu__summary::-webkit-details-marker{
  display:none;
}

.dashboard-user-menu__avatar{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  width:38px;
  height:38px;
  border-radius:999px;
  border:1px solid var(--line);
  background:transparent;
  font-size:15px;
  font-weight:900;
}

.dashboard-user-menu__meta{
  min-width:0;
  display:flex;
  flex-direction:column;
  gap:2px;
  flex:1;
}

.dashboard-user-menu__label{
  font-size:11px;
  color:var(--muted);
  font-weight:800;
}

.dashboard-user-menu__name{
  font-size:14px;
  font-weight:900;
  color:var(--txt);
  line-height:1.2;
}

.dashboard-user-menu__chev{
  color:var(--muted);
  font-size:13px;
}

.dashboard-user-menu__summary--compact{
  padding:10px 12px;
}

.dashboard-user-menu__hamburger{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  width:34px;
  height:34px;
  border-radius:12px;
  border:1px solid var(--line);
  font-size:15px;
  color:var(--txt);
  background:rgba(255,255,255,.03);
}

.dashboard-user-menu__panel{
  margin-top:8px;
  padding:12px;
  border:1px solid var(--line);
  border-radius:16px;
  background:
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02)),
    var(--cardA);
  box-shadow:0 14px 28px rgba(0,0,0,.14);
  min-width:220px;
}

.dashboard-user-menu__section-title{
  font-size:12px;
  font-weight:900;
  color:var(--muted);
  margin-bottom:8px;
}

.dashboard-user-menu__sep{
  height:1px;
  background:var(--line);
  margin:12px 0;
}

.dashboard-user-menu__logout{
  display:flex;
  align-items:center;
  justify-content:center;
  min-height:42px;
  border:1px solid var(--line);
  border-radius:12px;
  text-decoration:none;
  color:var(--txt);
  font-size:13px;
  font-weight:900;
  background:rgba(255,255,255,.03);
}

.dashboard-user-menu__logout:hover{
  border-color:color-mix(in srgb, #ef4444 42%, var(--line));
  background:color-mix(in srgb, #ef4444 10%, transparent);
}

.dashboard-user-push{
  display:grid;
  gap:6px;
}

.dashboard-user-push__row{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:8px;
}

.dashboard-user-push__status{
  font-size:11px;
  color:var(--muted);
  line-height:1.3;
}

.dashboard-user-push__actions{
  display:flex;
  gap:6px;
  flex-wrap:nowrap;
}

.theme-chip{
  appearance:none;
  border:1px solid var(--line);
  background:transparent;
  color:inherit;
  border-radius:999px;
  padding:8px 12px;
  font-size:12px;
  font-weight:800;
  line-height:1;
  cursor:pointer;
}

.theme-chip:hover{
  border-color:color-mix(in srgb, var(--accent) 40%, var(--line));
  background:color-mix(in srgb, var(--accent) 10%, transparent);
}

.theme-chip.is-on{
  border-color:color-mix(in srgb, var(--accent) 60%, var(--line));
  background:color-mix(in srgb, var(--accent) 18%, transparent);
}

.dash-wrap{
  display:flex;
  flex-direction:column;
  gap:12px;
}

.dashboard-focus{
  margin-bottom:12px;
  padding:14px 16px 16px;
  border:1px solid var(--line);
  border-radius:22px;
  background:
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02)),
    var(--cardA);
  box-shadow:0 14px 36px rgba(0,0,0,.12);
}

.dashboard-message-strip{
  display:grid;
  grid-template-columns:repeat(2, minmax(0, 1fr));
  gap:10px;
  margin-bottom:12px;
}

.dashboard-side-stack .dashboard-message-strip{
  grid-template-columns:repeat(2, minmax(0, 1fr));
  margin-bottom:0;
  gap:6px;
}

.dashboard-side-stack .message-summary-card{
  padding:10px 9px 11px;
  border-radius:14px;
  box-shadow:0 10px 24px rgba(0,0,0,.10);
}

.dashboard-side-stack .message-summary-card__icon{
  font-size:16px;
}

.dashboard-side-stack .message-summary-card__title{
  margin-top:4px;
  font-size:12px;
  line-height:1.35;
}

.dashboard-side-stack .message-summary-card__count{
  margin-top:3px;
  font-size:17px;
}

.dashboard-side-stack .message-summary-card__meta{
  margin-top:3px;
  font-size:9px;
}

.dashboard-side-stack .message-summary-card__desc{
  margin-top:4px;
  font-size:9px;
  line-height:1.4;
}

.dashboard-side-stack .message-summary-card__links{
  margin-top:7px;
  font-size:9px;
  gap:3px;
  flex-direction:column;
  align-items:flex-start;
}

.dashboard-side-stack .message-summary-card__links span{
  min-height:22px;
  padding:0 6px;
}

.dashboard-side-stack .message-summary-card__thanks-list{
  gap:3px;
  margin-top:5px;
}

.dashboard-side-stack .message-summary-card__thanks-item{
  padding:5px 6px;
  border-radius:10px;
}

.dashboard-side-stack .message-summary-card__thanks-item strong{
  font-size:10px;
}

.dashboard-side-stack .message-summary-card__thanks-item span{
  font-size:8px;
}

.message-summary-card{
  display:block;
  padding:13px 14px;
  border:1px solid var(--line);
  border-radius:18px;
  text-decoration:none;
  color:inherit;
  background:
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02)),
    var(--cardA);
  box-shadow:0 14px 36px rgba(0,0,0,.12);
  transition:transform .16s ease, box-shadow .16s ease, border-color .16s ease;
}

.message-summary-card:hover{
  transform:translateY(-3px);
  box-shadow:0 18px 36px rgba(0,0,0,.16);
}

.message-summary-card.is-message{
  border-color:rgba(96,165,250,.26);
}

.message-summary-card.is-thanks{
  border-color:rgba(251,191,36,.30);
  background:
    linear-gradient(180deg, rgba(250,204,21,.10), rgba(251,146,60,.04)),
    var(--cardA);
}

.message-summary-card__top{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
}

.message-summary-card__icon{
  font-size:22px;
  line-height:1;
}

.message-summary-card__pill{
  display:inline-flex;
  align-items:center;
  padding:4px 8px;
  border-radius:999px;
  font-size:10px;
  font-weight:900;
  background:rgba(255,255,255,.08);
}

.message-summary-card__title{
  margin-top:9px;
  font-size:15px;
  font-weight:1000;
}

.message-summary-card__count{
  margin-top:6px;
  font-size:26px;
  font-weight:1000;
  line-height:1;
}

.message-summary-card__meta{
  margin-top:6px;
  font-size:12px;
  font-weight:900;
  color:var(--muted);
}

.message-summary-card__desc{
  margin-top:8px;
  font-size:11px;
  line-height:1.5;
  color:var(--muted);
}

.message-summary-card__links{
  display:flex;
  gap:6px;
  flex-wrap:wrap;
  margin-top:10px;
  font-size:11px;
  font-weight:900;
}

.message-summary-card__links span{
  display:inline-flex;
  align-items:center;
  min-height:28px;
  padding:0 8px;
  border-radius:999px;
  border:1px solid rgba(255,255,255,.10);
  background:rgba(255,255,255,.04);
}

.message-summary-card__thanks-list{
  display:grid;
  gap:6px;
  margin-top:10px;
}

.message-summary-card__thanks-item{
  display:grid;
  gap:2px;
  padding:8px 10px;
  border-radius:12px;
  background:rgba(255,255,255,.06);
}

.message-summary-card__thanks-item strong{
  font-size:12px;
  line-height:1.45;
}

.message-summary-card__thanks-item span{
  font-size:10px;
  color:var(--muted);
}

.btn-micro{
  min-height:24px;
  padding:3px 8px;
  border-radius:999px;
  font-size:10px;
  font-weight:900;
}

.dashboard-focus__head h2{
  margin:0;
  font-size:18px;
}

.dashboard-focus__head p{
  margin:4px 0 0;
  font-size:12px;
  color:var(--muted);
}

.dashboard-focus__grid{
  display:grid;
  grid-template-columns:repeat(3, minmax(0, 1fr));
  gap:10px;
  margin-top:12px;
}

.focus-card{
  display:flex;
  gap:12px;
  align-items:flex-start;
  padding:14px;
  border-radius:18px;
  border:1px solid rgba(255,255,255,.10);
  background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.02));
  text-decoration:none;
  color:inherit;
  transition:transform .16s ease, box-shadow .16s ease, border-color .16s ease;
}

.focus-card:hover{
  transform:translateY(-3px);
  box-shadow:0 16px 30px rgba(0,0,0,.16);
  border-color:rgba(255,255,255,.22);
}

.focus-card.is-primary{
  border-color:rgba(250,204,21,.45);
  background:linear-gradient(180deg, rgba(250,204,21,.14), rgba(255,255,255,.03));
}

.focus-card__icon{
  font-size:26px;
  line-height:1;
  flex:0 0 auto;
}

.focus-card__body{
  display:grid;
  gap:4px;
}

.focus-card__title{
  font-size:15px;
  font-weight:1000;
  line-height:1.3;
}

.focus-card__desc{
  font-size:12px;
  line-height:1.5;
  color:var(--muted);
}

.dash-tabs{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  padding:4px;
  border:1px solid var(--line);
  border-radius:18px;
  background:
    linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.02)),
    var(--cardA);
  box-shadow:0 10px 28px rgba(0,0,0,.10);
}

.dash-tab{
  appearance:none;
  border:1px solid transparent;
  background:transparent;
  color:var(--muted);
  border-radius:14px;
  min-height:58px;
  padding:14px 18px;
  font-size:16px;
  font-weight:900;
  line-height:1.2;
  cursor:pointer;
  transition:background .16s ease, color .16s ease, border-color .16s ease, transform .16s ease;
}

.dash-tab:hover{
  color:var(--txt);
  border-color:rgba(255,255,255,.10);
  background:rgba(255,255,255,.04);
}

.dash-tab.is-active{
  color:#0f172a;
  border-color:rgba(250,204,21,.35);
  background:linear-gradient(135deg, #facc15, #fb923c);
}

.dash-panel{
  animation:dashPanelIn .18s ease;
}

.dash-section{
  padding:16px 18px 18px;
}

.dash-section-head{
  margin-bottom:10px;
}

.dash-section-head h2{
  margin:0;
  font-size:18px;
}

.dash-section-head p{
  margin:4px 0 0;
  font-size:12px;
  color:var(--muted);
}

.dash-grid{
  display:grid;
  grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));
  gap:10px;
}

.dash-card{
  display:block;
  padding:13px 13px 12px;
  border-radius:15px;
  border:1px solid rgba(255,255,255,.10);
  background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.02));
  text-decoration:none;
  color:inherit;
  transition:transform .16s ease, box-shadow .16s ease, border-color .16s ease;
}

.dash-card:hover{
  transform:translateY(-3px);
  box-shadow:0 16px 30px rgba(0,0,0,.18);
  border-color:rgba(255,255,255,.22);
}

.dash-card.is-primary{
  border-color:rgba(250,204,21,.45);
  background:linear-gradient(180deg, rgba(250,204,21,.14), rgba(255,255,255,.03));
}

.dash-card.is-warn{
  border-color:rgba(245,158,11,.45);
  background:linear-gradient(180deg, rgba(245,158,11,.14), rgba(255,255,255,.03));
}

.dash-card-top{
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:8px;
  margin-bottom:8px;
}

.dash-icon{
  font-size:24px;
  line-height:1;
}

.dash-tag{
  display:inline-flex;
  align-items:center;
  padding:4px 7px;
  border-radius:999px;
  font-size:10px;
  font-weight:800;
  background:color-mix(in srgb, var(--txt) 8%, transparent);
  color:var(--txt);
  white-space:nowrap;
}

.dash-title{
  font-size:15px;
  font-weight:900;
  line-height:1.35;
}

.dash-desc{
  margin-top:4px;
  font-size:11px;
  line-height:1.45;
  color:var(--muted);
}

@keyframes dashPanelIn{
  from{
    opacity:.0;
    transform:translateY(4px);
  }
  to{
    opacity:1;
    transform:translateY(0);
  }
}

@media (min-width: 1180px){
  .dashboard-hero-tools .store-switch{
    max-width:200px;
  }

  .dashboard-hero-tools .dashboard-inline-menu{
    max-width:200px;
  }

  .dash-grid{
    grid-template-columns:repeat(5, minmax(0, 1fr));
  }

  .dash-section-staff .dash-grid{
    grid-template-columns:repeat(6, minmax(0, 1fr));
  }
}

@media (max-width: 820px){
  .dashboard-topbar{
    grid-template-columns:1fr;
    gap:8px;
    margin-top:8px;
    margin-bottom:10px;
  }

  .dashboard-hero-grid{
    grid-template-columns:1fr;
    gap:10px;
  }

  .hero-store-name{
    font-size:26px;
  }

  .dashboard-hero-main h1{
    font-size:22px;
    margin:8px 0 6px;
    line-height:1.18;
  }

  .dashboard-hero-main{
    padding:16px 16px 14px;
    min-height:0;
  }

  .hero-store-meta{
    margin-top:4px;
    margin-bottom:8px;
    font-size:11px;
  }

  .hero-badge{
    padding:5px 9px;
    font-size:11px;
  }

  .dashboard-hero-main p{
    font-size:12px;
    line-height:1.45;
  }

  .dashboard-hero-tools{
    display:grid;
    grid-template-columns:minmax(0, 1fr) minmax(124px, .72fr);
    gap:8px;
    align-items:start;
  }

  .dashboard-hero-tools .store-switch,
  .dashboard-hero-tools .dashboard-inline-menu{
    max-width:none;
  }

  .dashboard-inline-panel,
  .dashboard-user-menu__summary{
    padding:9px 10px;
    border-radius:14px;
  }

  .store-switch{
    gap:0;
  }

  .store-switch-label{
    font-size:12px;
  }

  .store-switch-help{
    display:none;
  }

  .store-switch-select{
    min-height:42px;
    font-size:14px;
  }

  .dashboard-user-menu__avatar{
    width:34px;
    height:34px;
    font-size:14px;
  }

  .dashboard-user-menu__summary{
    gap:8px;
    align-items:center;
  }

  .dashboard-user-menu__meta{
    gap:1px;
    align-items:flex-start;
  }

  .dashboard-user-menu__label{
    font-size:10px;
  }

  .dashboard-user-menu__name{
    font-size:14px;
    line-height:1.15;
  }

  .dashboard-user-menu__hamburger{
    width:30px;
    height:30px;
    border-radius:10px;
    font-size:14px;
  }

  .dashboard-focus__grid{
    grid-template-columns:1fr;
  }

  .dashboard-message-strip{
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:8px;
  }

  .dashboard-side-stack .dashboard-message-strip{
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:8px;
  }

  .dashboard-side-stack .message-summary-card{
    padding:10px 10px 11px;
    border-radius:14px;
  }

  .dashboard-side-stack .message-summary-card__top{
    gap:8px;
  }

  .dashboard-side-stack .message-summary-card__pill{
    padding:3px 6px;
    font-size:9px;
  }

  .dashboard-side-stack .message-summary-card__title{
    margin-top:5px;
    font-size:13px;
  }

  .dashboard-side-stack .message-summary-card__count{
    margin-top:3px;
    font-size:22px;
  }

  .dashboard-side-stack .message-summary-card__meta{
    margin-top:4px;
    font-size:10px;
  }

  .dashboard-side-stack .message-summary-card__desc{
    margin-top:4px;
    font-size:10px;
    line-height:1.35;
  }

  .dashboard-side-stack .message-summary-card__links{
    margin-top:7px;
    font-size:10px;
    gap:4px;
  }

  .push-optin-card__actions{
    width:100%;
  }
}

@media (max-width: 640px){
  .dashboard-shell{
    padding-bottom:28px;
  }

  .dashboard-hero-main,
  .dash-section{
    border-radius:18px;
  }

  .dashboard-hero-main{
    padding:14px 14px 12px;
  }

  .hero-store-name{
    font-size:23px;
  }

  .store-switch-row{
    flex-direction:row;
    align-items:center;
  }

  .dash-tabs{
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
  }

  .dash-tab{
    width:100%;
    min-height:54px;
    padding:12px 14px;
    font-size:15px;
  }

  .dash-grid{
    grid-template-columns:1fr;
  }

  .dashboard-hero-main h1{
    font-size:19px;
    margin:7px 0 5px;
  }

  .dashboard-hero-main p{
    font-size:11px;
    line-height:1.4;
  }

  .dashboard-hero-tools{
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:7px;
  }

  .dashboard-inline-panel,
  .dashboard-user-menu__summary{
    padding:8px 9px;
  }

  .store-switch-compact-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
  }

  .store-switch-panel{
    display:none;
    margin-top:8px;
  }

  .store-switch.is-open .store-switch-panel{
    display:grid;
  }

  .store-switch-label{
    font-size:11px;
  }

  .store-switch-select{
    min-height:40px;
    font-size:13px;
  }

  .store-switch-toggle{
    width:28px;
    min-width:28px;
    min-height:28px;
    font-size:13px;
  }

  .dashboard-user-menu{
    width:100%;
    position:relative;
  }

  .dashboard-user-menu__meta{
    gap:1px;
  }

  .dashboard-user-menu__summary{
    display:grid;
    grid-template-columns:32px minmax(72px, 1fr) 28px;
    gap:8px;
    align-items:center;
  }

  .dashboard-user-menu__name{
    font-size:15px;
  }

  .dashboard-user-menu__summary--compact{
    gap:8px;
  }

  .dashboard-user-menu__hamburger{
    width:28px;
    height:28px;
    font-size:13px;
  }

  .dashboard-user-menu__panel{
    position:absolute;
    top:calc(100% + 8px);
    right:0;
    width:min(264px, calc(100vw - 44px));
    min-width:0;
    padding:12px;
    z-index:12;
  }

  .theme-inline-row{
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:8px;
  }

  .theme-chip{
    width:100%;
    min-height:38px;
    padding:8px 10px;
    text-align:center;
  }

  .dashboard-user-push__row{
    align-items:flex-start;
    gap:10px;
  }

  .dashboard-user-push__status{
    flex:1;
    font-size:10px;
    line-height:1.45;
  }

  .dashboard-user-push__actions{
    flex:0 0 auto;
    gap:8px;
  }

  .dashboard-user-menu__logout{
    min-height:40px;
  }

  .dashboard-message-strip,
  .dashboard-side-stack .dashboard-message-strip{
    gap:6px;
  }

  .dashboard-side-stack .message-summary-card{
    padding:9px 9px 10px;
  }

  .dashboard-side-stack .message-summary-card__icon{
    font-size:15px;
  }

  .dashboard-side-stack .message-summary-card__title{
    font-size:12px;
  }

  .dashboard-side-stack .message-summary-card__count{
    font-size:19px;
  }

  .dashboard-side-stack .message-summary-card__desc{
    font-size:9px;
  }

  .dashboard-side-stack .message-summary-card__links span{
    min-height:20px;
    padding:0 5px;
  }

}
</style>

<script>
document.addEventListener('click', async function(e){
  const btn = e.target.closest('.theme-chip');
  if (!btn) return;

  const theme = btn.getAttribute('data-theme') || '';
  if (!theme) return;

  try {
    const res = await fetch('/wbss/public/api/set_theme.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      credentials: 'same-origin',
      body: 'theme=' + encodeURIComponent(theme)
    });
    const j = await res.json().catch(function(){ return {}; });
    if (j && j.ok) {
      document.body.setAttribute('data-theme', theme);
      document.querySelectorAll('.theme-chip').forEach(function(el){
        el.classList.remove('is-on');
      });
      btn.classList.add('is-on');
      return;
    }
  } catch (err) {}

  alert('テーマ変更に失敗しました');
});

document.addEventListener('click', function(e){
  const storeToggle = e.target.closest('[data-store-switch-toggle]');
  if (storeToggle) {
    const form = storeToggle.closest('.store-switch');
    if (!form) return;
    const nextOpen = !form.classList.contains('is-open');
    form.classList.toggle('is-open', nextOpen);
    storeToggle.setAttribute('aria-expanded', nextOpen ? 'true' : 'false');
    return;
  }

  const tab = e.target.closest('[data-tab-button]');
  if (!tab) return;

  const targetId = tab.getAttribute('data-target') || '';
  if (!targetId) return;

  document.querySelectorAll('[data-tab-button]').forEach(function(el){
    const isActive = el === tab;
    el.classList.toggle('is-active', isActive);
    el.setAttribute('aria-selected', isActive ? 'true' : 'false');
  });

  document.querySelectorAll('[data-tab-panel]').forEach(function(panel){
    const isActive = panel.id === targetId;
    panel.classList.toggle('is-active', isActive);
    panel.hidden = !isActive;
  });
});
</script>
<script src="/wbss/public/assets/js/push_notifications.js?v=20260320b"></script>

<?php render_page_end(); ?>
