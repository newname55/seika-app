<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';
require_once __DIR__ . '/../../app/store_access.php';
require_once __DIR__ . '/../../app/repo_applicants.php';

require_login();
require_role(['admin', 'manager', 'super_user']);

$pdo = db();
$personId = (int)($_GET['id'] ?? 0);
$stores = store_access_allowed_stores($pdo);
$interviewers = repo_applicants_fetch_interviewers($pdo);

$person = $personId > 0 ? repo_applicants_find_person($pdo, $personId) : null;
if ($personId > 0 && !$person) {
  http_response_code(404);
  exit('面接者が見つかりません');
}

$photos = $person ? repo_applicants_fetch_photos($pdo, $personId) : [];
$interviews = $person ? repo_applicants_fetch_interviews($pdo, $personId) : [];
$assignments = $person ? repo_applicants_fetch_assignments($pdo, $personId) : [];
$logs = $person ? repo_applicants_fetch_logs($pdo, $personId) : [];

$msg = trim((string)($_GET['msg'] ?? ''));
$err = trim((string)($_GET['err'] ?? ''));

function h(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function person_value(?array $person, string $key): string {
  return h((string)($person[$key] ?? ''));
}

function detail_status_badge(?array $person): array {
  $status = (string)($person['current_status'] ?? 'interviewing');
  $active = (int)($person['is_currently_employed'] ?? 0);
  if ($active === 1 || $status === 'active') {
    return ['在籍中', 'ok'];
  }
  return match ($status) {
    'trial' => ['体験入店', 'trial'],
    'left' => ['退店', 'off'],
    'hold' => ['保留', 'hold'],
    default => ['面接中', 'base'],
  };
}

function detail_result_label(?string $value): string {
  return match ((string)$value) {
    'pass' => '合格',
    'hold' => '保留',
    'reject' => '不採用',
    'joined' => '入店',
    'pending' => '未判定',
    default => '—',
  };
}

function detail_trial_label(?string $value): string {
  return match ((string)$value) {
    'scheduled' => '予定',
    'completed' => '実施',
    'passed' => '合格',
    'failed' => '見送り',
    'cancelled' => '取消',
    'not_set' => '未設定',
    default => '—',
  };
}

function detail_transition_label(?string $value): string {
  return match ((string)$value) {
    'join' => '入店',
    'rejoin' => '再入店',
    'move' => '移動',
    default => '—',
  };
}

function detail_display(mixed $value, string $fallback = '—'): string {
  $text = trim((string)$value);
  return $text !== '' ? h($text) : h($fallback);
}

[$statusText, $statusClass] = detail_status_badge($person ?? []);

render_page_start($person ? '面接者詳細 / 編集' : '面接者新規作成');
render_header($person ? '面接者詳細 / 編集' : '面接者新規作成', [
  'back_href' => '/wbss/public/applicants/index.php',
  'back_label' => '← 面接者一覧',
]);
?>
<style>
  .page{
    max-width:1380px;
    margin:0 auto;
    padding:14px;
  }
  .detailWrap{
    display:grid;
    gap:14px;
  }
  .shellCard{
    border:1px solid var(--line,#d7deea);
    border-radius:24px;
    background:
      linear-gradient(180deg, rgba(255,255,255,.96), rgba(248,250,252,.92)),
      var(--cardA,#fff);
    box-shadow:0 18px 42px rgba(15,23,42,.08);
  }
  .heroPanel{
    display:grid;
    grid-template-columns:minmax(0,1.7fr) minmax(280px,.9fr);
    gap:14px;
  }
  .heroMain,
  .heroSide,
  .summaryCard,
  .photoCard,
  .basicCard,
  .tabCard,
  .subCard{
    border:1px solid var(--line,#d7deea);
    border-radius:24px;
    background:linear-gradient(180deg, rgba(255,255,255,.98), rgba(248,250,252,.94));
    box-shadow:0 16px 34px rgba(15,23,42,.07);
  }
  .heroMain{
    padding:22px 24px;
    background:
      radial-gradient(circle at top right, rgba(56,189,248,.16), transparent 34%),
      radial-gradient(circle at bottom left, rgba(59,130,246,.10), transparent 30%),
      linear-gradient(180deg, #ffffff, #f8fbff);
  }
  .heroHead{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:16px;
    flex-wrap:wrap;
  }
  .eyebrow{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:6px 11px;
    border-radius:999px;
    background:linear-gradient(135deg,#fef3c7,#fdba74);
    color:#7c2d12;
    font-size:12px;
    font-weight:900;
  }
  .heroTitle{
    margin:10px 0 8px;
    font-size:31px;
    line-height:1.1;
    font-weight:1000;
    color:#0f172a;
  }
  .heroLead{
    margin:0;
    max-width:760px;
    color:#475569;
    font-size:13px;
    line-height:1.7;
  }
  .heroMeta{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-top:14px;
  }
  .heroMetaChip,
  .storeChip,
  .badge{
    display:inline-flex;
    align-items:center;
    border-radius:999px;
    padding:7px 12px;
    font-size:12px;
    font-weight:900;
    border:1px solid transparent;
    white-space:nowrap;
  }
  .heroMetaChip{
    background:#eef2ff;
    color:#3730a3;
    border-color:#c7d2fe;
  }
  .badge.ok{background:rgba(34,197,94,.12);color:#166534;border-color:rgba(34,197,94,.22)}
  .badge.trial{background:rgba(245,158,11,.14);color:#92400e;border-color:rgba(245,158,11,.22)}
  .badge.off{background:rgba(239,68,68,.12);color:#991b1b;border-color:rgba(239,68,68,.20)}
  .badge.hold{background:rgba(99,102,241,.12);color:#3730a3;border-color:rgba(99,102,241,.18)}
  .badge.base{background:rgba(148,163,184,.12);color:#374151;border-color:rgba(148,163,184,.18)}
  .storeChip{
    background:rgba(37,99,235,.10);
    color:#1d4ed8;
    border-color:rgba(37,99,235,.14);
  }
  .heroSide{
    padding:18px;
    display:grid;
    gap:10px;
    align-content:start;
    background:
      radial-gradient(circle at top left, rgba(16,185,129,.12), transparent 28%),
      linear-gradient(180deg, #ffffff, #f8fafc);
  }
  .sideTitle{
    font-size:13px;
    font-weight:1000;
    color:#334155;
  }
  .sideList{
    display:grid;
    gap:10px;
  }
  .sideMetric{
    padding:12px 14px;
    border-radius:18px;
    border:1px solid var(--line,#d7deea);
    background:rgba(255,255,255,.88);
  }
  .sideMetric span,
  .sumCard span,
  .infoItem span,
  .mini{
    display:block;
    font-size:12px;
    color:#64748b;
    line-height:1.6;
  }
  .sideMetric strong,
  .sumCard strong,
  .infoItem strong{
    display:block;
    margin-top:3px;
    font-size:19px;
    line-height:1.3;
    color:#0f172a;
    font-weight:1000;
  }
  .summaryGrid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:12px;
  }
  .summaryCard{
    padding:0;
    overflow:hidden;
  }
  .sumCard{
    padding:16px 18px;
    border:none;
    border-radius:0;
    box-shadow:none;
    background:transparent;
  }
  .boardGrid{
    display:grid;
    grid-template-columns:320px minmax(0,1fr);
    gap:14px;
    align-items:start;
  }
  .photoCard,
  .basicCard,
  .tabCard{
    padding:18px;
  }
  .sectionHead{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:14px;
  }
  .sectionTitle{
    font-size:18px;
    font-weight:1000;
    color:#0f172a;
    margin:0;
  }
  .sectionDesc{
    margin:4px 0 0;
    font-size:12px;
    color:#64748b;
    line-height:1.6;
  }
  .photoPreview{
    width:100%;
    aspect-ratio:3/4;
    border-radius:20px;
    background:#f1f5f9;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    border:1px dashed #cbd5e1;
  }
  .photoPreview img{
    width:100%;
    height:100%;
    object-fit:cover;
  }
  .compactStack{
    display:grid;
    gap:12px;
  }
  .formGrid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:12px;
  }
  .basicGrid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:12px;
  }
  .field{
    display:grid;
    gap:6px;
  }
  .field.full{
    grid-column:1 / -1;
  }
  .field.span2{
    grid-column:span 2;
  }
  .label{
    font-size:12px;
    color:#475569;
    font-weight:900;
  }
  .input,
  .select,
  .textarea{
    width:100%;
    min-height:52px;
    border-radius:18px;
    border:1px solid var(--line,#d7deea);
    padding:12px 16px;
    background:rgba(255,255,255,.96);
    color:#0f172a;
  }
  .input[disabled]{
    color:#334155;
    background:#f8fafc;
  }
  .textarea{
    min-height:110px;
    resize:vertical;
  }
  .btnLine{
    width:auto;
    min-height:52px;
    border-radius:18px;
    border:1px solid var(--line,#d7deea);
    padding:12px 16px;
    background:rgba(255,255,255,.96);
    display:inline-flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    font-weight:1000;
    text-decoration:none;
    transition:.18s ease;
  }
  .btnLine:hover{
    transform:translateY(-1px);
    box-shadow:0 10px 20px rgba(15,23,42,.08);
  }
  .btnPrimary{
    background:#0f172a;
    color:#fff;
    border-color:#0f172a;
  }
  .btnSoft{
    background:#fff;
    color:#0f172a;
  }
  .infoGrid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:10px;
    margin-bottom:12px;
  }
  .infoItem{
    padding:13px 15px;
    border:1px solid var(--line,#d7deea);
    border-radius:18px;
    background:#fff;
  }
  .saveBar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-top:14px;
    padding-top:14px;
    border-top:1px solid #e2e8f0;
    flex-wrap:wrap;
  }
  .tabs{
    display:flex;
    gap:8px;
    overflow:auto;
    padding-bottom:4px;
  }
  .tabBtn{
    border:1px solid var(--line,#d7deea);
    background:#fff;
    border-radius:999px;
    padding:10px 16px;
    font-weight:1000;
    cursor:pointer;
    white-space:nowrap;
    color:#334155;
  }
  .tabBtn.is-active{
    background:#0f172a;
    color:#fff;
    border-color:#0f172a;
  }
  .tabPanel{
    display:none;
    margin-top:14px;
  }
  .tabPanel.is-active{
    display:block;
  }
  .panelHead{
    margin-bottom:14px;
  }
  .panelTitle{
    margin:0;
    font-size:17px;
    font-weight:1000;
    color:#0f172a;
  }
  .panelLead{
    margin:4px 0 0;
    font-size:12px;
    color:#64748b;
    line-height:1.6;
  }
  .subGrid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:12px;
  }
  .subCard{
    padding:14px;
  }
  .tableWrap{
    overflow:auto;
    border:1px solid #e2e8f0;
    border-radius:18px;
    background:#fff;
  }
  table{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
  }
  th,
  td{
    padding:12px 14px;
    border-bottom:1px solid #e2e8f0;
    vertical-align:top;
    text-align:left;
  }
  th{
    font-size:12px;
    color:#64748b;
    font-weight:1000;
    background:#f8fafc;
    position:sticky;
    top:0;
  }
  tr:last-child td{
    border-bottom:none;
  }
  .noticeOk,
  .noticeErr{
    padding:13px 16px;
    border-radius:16px;
    font-weight:1000;
  }
  .noticeOk{
    background:#ecfdf5;
    color:#166534;
    border:1px solid #a7f3d0;
  }
  .noticeErr{
    background:#fef2f2;
    color:#991b1b;
    border:1px solid #fecaca;
  }
  @media (max-width: 1180px){
    .heroPanel,
    .boardGrid{
      grid-template-columns:1fr;
    }
    .summaryGrid{
      grid-template-columns:repeat(2,minmax(0,1fr));
    }
    .basicGrid,
    .formGrid,
    .subGrid,
    .infoGrid{
      grid-template-columns:repeat(2,minmax(0,1fr));
    }
  }
  @media (max-width: 760px){
    .page{
      padding:10px;
    }
    .heroMain,
    .heroSide,
    .photoCard,
    .basicCard,
    .tabCard{
      padding:14px;
      border-radius:20px;
    }
    .heroTitle{
      font-size:25px;
    }
    .summaryGrid,
    .basicGrid,
    .formGrid,
    .subGrid,
    .infoGrid{
      grid-template-columns:1fr;
    }
    .field.span2{
      grid-column:auto;
    }
  }
</style>

<div class="page">
  <div class="detailWrap">
  <?php if ($msg !== ''): ?><div class="noticeOk"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err !== ''): ?><div class="noticeErr"><?= h($err) ?></div><?php endif; ?>

  <section class="heroPanel">
    <div class="heroMain">
      <div class="heroHead">
        <div>
          <span class="eyebrow">見ればすぐ分かる面接者ページ</span>
          <h1 class="heroTitle"><?= $person ? h(trim((string)$person['last_name'] . ' ' . (string)$person['first_name'])) : '新規面接者を登録' ?></h1>
          <p class="heroLead">
            <?php if ($person): ?>
              基本情報はこのまま下で編集できます。面接記録や在籍化、履歴確認はタブで切り替えるだけにして、迷いにくい画面にしています。
            <?php else: ?>
              まずは名前・連絡先・顔写真を入れると、この画面で続けて管理できるようになります。
            <?php endif; ?>
          </p>
          <div class="heroMeta">
            <span class="badge <?= h($statusClass) ?>"><?= h($statusText) ?></span>
            <?php if ($person && !empty($person['current_store_name'])): ?>
              <span class="storeChip">現在店舗: <?= h((string)$person['current_store_name']) ?></span>
            <?php endif; ?>
            <?php if ($person): ?>
              <span class="heroMetaChip">person #<?= (int)$person['id'] ?></span>
              <span class="heroMetaChip">最新面接ID #<?= (int)($person['latest_interview_id'] ?? 0) ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <aside class="heroSide">
      <div class="sideTitle">いま分かること</div>
      <div class="sideList">
        <div class="sideMetric">
          <span>最新面接日</span>
          <strong><?= detail_display($person['latest_interviewed_at'] ?? '') ?></strong>
        </div>
        <div class="sideMetric">
          <span>最新結果</span>
          <strong><?= h(detail_result_label($person['latest_interview_result'] ?? null)) ?></strong>
        </div>
        <div class="sideMetric">
          <span>現在の源氏名</span>
          <strong><?= detail_display($person['current_stage_name'] ?? '') ?></strong>
        </div>
      </div>
    </aside>
  </section>

  <section class="summaryGrid">
    <div class="summaryCard"><div class="sumCard"><span>在籍状況</span><strong><?= h($statusText) ?></strong></div></div>
    <div class="summaryCard"><div class="sumCard"><span>現在店舗</span><strong><?= detail_display($person['current_store_name'] ?? '') ?></strong></div></div>
    <div class="summaryCard"><div class="sumCard"><span>面接履歴</span><strong><?= (int)count($interviews) ?> 件</strong></div></div>
    <div class="summaryCard"><div class="sumCard"><span>履歴ログ</span><strong><?= (int)count($logs) ?> 件</strong></div></div>
  </section>

  <section class="boardGrid">
    <aside class="compactStack">
      <div class="photoCard">
        <div class="sectionHead">
          <div>
            <h2 class="sectionTitle">顔写真</h2>
            <p class="sectionDesc">一覧でひと目で分かるように、ここだけは見失わない位置に置いています。</p>
          </div>
        </div>
        <div class="photoPreview">
          <?php if ($person && !empty($person['primary_photo_path'])): ?>
            <img src="<?= h((string)$person['primary_photo_path']) ?>" alt="顔写真">
          <?php else: ?>
            <div class="mini">顔写真未登録</div>
          <?php endif; ?>
        </div>
        <div class="mini" style="margin-top:10px;">顔写真は必須です。登録しておくと一覧でも確認しやすくなります。</div>

        <?php if ($person): ?>
          <form method="post" action="/wbss/public/applicants/upload_photo.php" enctype="multipart/form-data" style="margin-top:12px;display:grid;gap:10px;">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="person_id" value="<?= (int)$person['id'] ?>">
            <input class="input" type="file" name="face_photo" accept="image/jpeg,image/png,image/webp" required>
            <button class="btnLine btnSoft">写真を更新</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="photoCard">
        <div class="sectionHead">
          <div>
            <h2 class="sectionTitle">すぐ確認したい項目</h2>
            <p class="sectionDesc">電話や住所など、問い合わせ時に見返しやすい形でまとめています。</p>
          </div>
        </div>
        <div class="infoGrid">
          <div class="infoItem"><span>電話番号</span><strong><?= detail_display($person['phone'] ?? '') ?></strong></div>
          <div class="infoItem"><span>生年月日</span><strong><?= detail_display($person['birth_date'] ?? '') ?></strong></div>
          <div class="infoItem"><span>血液型</span><strong><?= detail_display($person['blood_type'] ?? '') ?></strong></div>
          <div class="infoItem"><span>郵便番号</span><strong><?= detail_display($person['postal_code'] ?? '') ?></strong></div>
          <div class="infoItem"><span>前職</span><strong><?= detail_display($person['previous_job'] ?? '') ?></strong></div>
          <div class="infoItem"><span>希望時給</span><strong><?= detail_display($person['desired_hourly_wage'] ?? '') ?></strong></div>
        </div>
      </div>
    </aside>

    <div class="compactStack">
      <form method="post" action="/wbss/public/applicants/save.php" enctype="multipart/form-data" class="basicCard">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="person_id" value="<?= (int)($person['id'] ?? 0) ?>">

        <div class="sectionHead">
          <div>
            <h2 class="sectionTitle">基本情報</h2>
            <p class="sectionDesc">この画面では基本情報を常に表示し、他の作業だけタブで切り替えます。</p>
          </div>
        </div>

        <?php if (!$person): ?>
          <div class="field full" style="margin-bottom:12px;">
            <label class="label">顔写真</label>
            <input class="input" type="file" name="face_photo" accept="image/jpeg,image/png,image/webp" required>
          </div>
        <?php endif; ?>

        <div class="basicGrid">
          <div class="field"><label class="label">姓</label><input class="input" name="last_name" value="<?= person_value($person, 'last_name') ?>" required></div>
          <div class="field"><label class="label">名</label><input class="input" name="first_name" value="<?= person_value($person, 'first_name') ?>" required></div>
          <div class="field"><label class="label">姓かな</label><input class="input" name="last_name_kana" value="<?= person_value($person, 'last_name_kana') ?>"></div>
          <div class="field"><label class="label">名かな</label><input class="input" name="first_name_kana" value="<?= person_value($person, 'first_name_kana') ?>"></div>

          <div class="field"><label class="label">生年月日</label><input class="input" type="date" name="birth_date" value="<?= person_value($person, 'birth_date') ?>"></div>
          <div class="field"><label class="label">電話番号</label><input class="input" name="phone" value="<?= person_value($person, 'phone') ?>"></div>
          <div class="field"><label class="label">郵便番号</label><input class="input" name="postal_code" value="<?= person_value($person, 'postal_code') ?>"></div>
          <div class="field"><label class="label">血液型</label><input class="input" name="blood_type" value="<?= person_value($person, 'blood_type') ?>" placeholder="A / B / O / AB"></div>

          <div class="field span2"><label class="label">現住所</label><input class="input" name="current_address" value="<?= person_value($person, 'current_address') ?>"></div>
          <div class="field span2"><label class="label">以前住所</label><input class="input" name="previous_address" value="<?= person_value($person, 'previous_address') ?>"></div>

          <div class="field"><label class="label">前職</label><input class="input" name="previous_job" value="<?= person_value($person, 'previous_job') ?>"></div>
          <div class="field"><label class="label">希望時給</label><input class="input" type="number" step="1" name="desired_hourly_wage" value="<?= person_value($person, 'desired_hourly_wage') ?>"></div>
          <div class="field"><label class="label">希望日給</label><input class="input" type="number" step="1" name="desired_daily_wage" value="<?= person_value($person, 'desired_daily_wage') ?>"></div>
          <div class="field"><label class="label">person_code</label><input class="input" name="person_code" value="<?= person_value($person, 'person_code') ?>"></div>

          <div class="field"><label class="label">legacy_source</label><input class="input" name="legacy_source" value="<?= person_value($person, 'legacy_source') ?>"></div>
          <div class="field"><label class="label">legacy_record_no</label><input class="input" name="legacy_record_no" value="<?= person_value($person, 'legacy_record_no') ?>"></div>
          <div class="field"><label class="label">身長(cm)</label><input class="input" type="number" name="body_height_cm" value="<?= person_value($person, 'body_height_cm') ?>"></div>
          <div class="field"><label class="label">体重(kg)</label><input class="input" type="number" step="0.1" name="body_weight_kg" value="<?= person_value($person, 'body_weight_kg') ?>"></div>

          <div class="field"><label class="label">バスト</label><input class="input" type="number" name="bust_cm" value="<?= person_value($person, 'bust_cm') ?>"></div>
          <div class="field"><label class="label">ウエスト</label><input class="input" type="number" name="waist_cm" value="<?= person_value($person, 'waist_cm') ?>"></div>
          <div class="field"><label class="label">ヒップ</label><input class="input" type="number" name="hip_cm" value="<?= person_value($person, 'hip_cm') ?>"></div>
          <div class="field"><label class="label">カップ</label><input class="input" name="cup_size" value="<?= person_value($person, 'cup_size') ?>"></div>

          <div class="field"><label class="label">靴サイズ</label><input class="input" type="number" step="0.5" name="shoe_size" value="<?= person_value($person, 'shoe_size') ?>"></div>
          <div class="field"><label class="label">上服サイズ</label><input class="input" name="clothing_top_size" value="<?= person_value($person, 'clothing_top_size') ?>"></div>
          <div class="field"><label class="label">下服サイズ</label><input class="input" name="clothing_bottom_size" value="<?= person_value($person, 'clothing_bottom_size') ?>"></div>
          <div class="field"><label class="label">現在ステータス</label><input class="input" value="<?= h($statusText) ?>" disabled></div>

          <div class="field"><label class="label">現在店舗</label><input class="input" value="<?= detail_display($person['current_store_name'] ?? '', '') ?>" disabled></div>
          <div class="field"><label class="label">現在の源氏名</label><input class="input" value="<?= person_value($person, 'current_stage_name') ?>" disabled></div>
          <div class="field"><label class="label">最新面接日</label><input class="input" value="<?= person_value($person, 'latest_interviewed_at') ?>" disabled></div>
          <div class="field"><label class="label">最新結果</label><input class="input" value="<?= h(detail_result_label($person['latest_interview_result'] ?? null)) ?>" disabled></div>

          <div class="field full"><label class="label">担当者メモ</label><textarea class="textarea" name="notes"><?= person_value($person, 'notes') ?></textarea></div>
        </div>

        <div class="saveBar">
          <div class="mini">基本情報はここでまとめて保存できます。下のタブは別操作です。</div>
          <button class="btnLine btnPrimary">基本情報を保存</button>
        </div>
      </form>

      <?php if ($person): ?>
        <section class="tabCard">
          <div class="sectionHead">
            <div>
              <h2 class="sectionTitle">詳細操作</h2>
              <p class="sectionDesc">面接追加、状態変更、履歴確認はここだけで完結します。</p>
            </div>
          </div>

          <div class="tabs" data-tabs>
            <?php foreach ([
              'interview-form' => '面接を追加',
              'status-action' => '状態を更新',
              'interview-history' => '面接履歴',
              'assignment-history' => '在籍 / 移動履歴',
              'logs' => '履歴ログ',
            ] as $tabKey => $tabLabel): ?>
              <button type="button" class="tabBtn<?= $tabKey === 'interview-form' ? ' is-active' : '' ?>" data-tab-target="<?= h($tabKey) ?>"><?= h($tabLabel) ?></button>
            <?php endforeach; ?>
          </div>

          <div class="tabPanel is-active" data-tab-panel="interview-form">
            <div class="panelHead">
              <h3 class="panelTitle">新しい面接記録を追加</h3>
              <p class="panelLead">1回の面接で必要になる情報を、上から順に入れれば終わる並びにしています。</p>
            </div>
            <form method="post" action="/wbss/public/applicants/actions/add_interview.php">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="person_id" value="<?= (int)$person['id'] ?>">
              <div class="formGrid">
                <div class="field"><label class="label">面接日</label><input class="input" type="date" name="interview_date" value="<?= h(date('Y-m-d')) ?>" required></div>
                <div class="field"><label class="label">面接時刻</label><input class="input" type="time" name="interview_time"></div>
                <div class="field"><label class="label">面接店舗</label><select class="select" name="interview_store_id" required><option value="">選択してください</option><?php foreach ($stores as $store): ?><option value="<?= (int)$store['id'] ?>"><?= h((string)$store['name']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label class="label">面接担当者</label><select class="select" name="interviewer_user_id"><option value="">未設定</option><?php foreach ($interviewers as $user): ?><option value="<?= (int)$user['id'] ?>"><?= h((string)$user['display_name']) ?></option><?php endforeach; ?></select></div>

                <div class="field"><label class="label">面接結果</label><select class="select" name="interview_result"><?php foreach (['pending' => '未判定', 'pass' => '合格', 'hold' => '保留', 'reject' => '不採用', 'joined' => '入店'] as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label class="label">応募経路</label><input class="input" name="application_route"></div>
                <div class="field"><label class="label">希望時給</label><input class="input" type="number" step="1" name="desired_hourly_wage"></div>
                <div class="field"><label class="label">希望日給</label><input class="input" type="number" step="1" name="desired_daily_wage"></div>

                <div class="field"><label class="label">体験入店</label><select class="select" name="trial_status"><?php foreach (['not_set' => '未設定', 'scheduled' => '予定', 'completed' => '実施', 'passed' => '合格', 'failed' => '見送り', 'cancelled' => '取消'] as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label class="label">体験入店日</label><input class="input" type="date" name="trial_date"></div>
                <div class="field"><label class="label">入店判定</label><select class="select" name="join_decision"><?php foreach (['undecided' => '未判定', 'approved' => '承認', 'rejected' => '不採用', 'deferred' => '保留'] as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label class="label">入店予定日</label><input class="input" type="date" name="join_date"></div>

                <div class="field"><label class="label">見た目</label><input class="input" type="number" name="appearance_score" min="0" max="100"></div>
                <div class="field"><label class="label">会話力</label><input class="input" type="number" name="communication_score" min="0" max="100"></div>
                <div class="field"><label class="label">意欲</label><input class="input" type="number" name="motivation_score" min="0" max="100"></div>
                <div class="field"><label class="label">清潔感</label><input class="input" type="number" name="cleanliness_score" min="0" max="100"></div>
                <div class="field"><label class="label">営業力</label><input class="input" type="number" name="sales_potential_score" min="0" max="100"></div>
                <div class="field"><label class="label">定着見込</label><input class="input" type="number" name="retention_potential_score" min="0" max="100"></div>

                <div class="field full"><label class="label">面接メモ</label><textarea class="textarea" name="interview_notes"></textarea></div>
                <div class="field full"><label class="label">評価コメント</label><textarea class="textarea" name="score_comment"></textarea></div>
                <div class="field full"><label class="label">体験入店フィードバック / 次アクション</label><textarea class="textarea" name="trial_feedback"></textarea></div>
              </div>
              <button class="btnLine btnPrimary" style="margin-top:14px;">面接記録を追加</button>
            </form>
          </div>

          <div class="tabPanel" data-tab-panel="status-action">
            <div class="panelHead">
              <h3 class="panelTitle">状態を更新</h3>
              <p class="panelLead">体験入店、在籍化、退店、店舗移動を用途ごとに分けてあります。</p>
            </div>
            <div class="subGrid">
              <form method="post" action="/wbss/public/applicants/actions/change_status.php" class="subCard">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="person_id" value="<?= (int)$person['id'] ?>">
                <input type="hidden" name="action" value="trial">
                <div class="panelHead">
                  <h3 class="panelTitle">体験入店</h3>
                  <p class="panelLead">予定や実施結果を更新します。</p>
                </div>
                <div class="field"><label class="label">体験入店状態</label><select class="select" name="trial_status"><?php foreach (['scheduled' => '予定', 'completed' => '実施', 'passed' => '合格', 'failed' => '見送り', 'cancelled' => '取消'] as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label class="label">体験入店日</label><input class="input" type="date" name="trial_date" value="<?= h(date('Y-m-d')) ?>"></div>
                <div class="field"><label class="label">メモ</label><textarea class="textarea" name="trial_feedback"></textarea></div>
                <button class="btnLine btnSoft" style="margin-top:10px;">体験入店へ更新</button>
              </form>

              <form method="post" action="/wbss/public/applicants/actions/change_status.php" class="subCard">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="person_id" value="<?= (int)$person['id'] ?>">
                <input type="hidden" name="action" value="active">
                <div class="panelHead">
                  <h3 class="panelTitle">在籍化</h3>
                  <p class="panelLead">採用後の入店登録を行います。</p>
                </div>
                <div class="field"><label class="label">入店店舗</label><select class="select" name="store_id" required><option value="">選択してください</option><?php foreach ($stores as $store): ?><option value="<?= (int)$store['id'] ?>"><?= h((string)$store['name']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label class="label">入店日</label><input class="input" type="date" name="effective_date" value="<?= h(date('Y-m-d')) ?>" required></div>
                <div class="field"><label class="label">源氏名</label><input class="input" name="genji_name"></div>
                <div class="field"><label class="label">メモ</label><textarea class="textarea" name="note"></textarea></div>
                <button class="btnLine btnSoft" style="margin-top:10px;">在籍化する</button>
              </form>

              <form method="post" action="/wbss/public/applicants/actions/change_status.php" class="subCard">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="person_id" value="<?= (int)$person['id'] ?>">
                <input type="hidden" name="action" value="left">
                <div class="panelHead">
                  <h3 class="panelTitle">退店</h3>
                  <p class="panelLead">退店日と理由を残します。</p>
                </div>
                <div class="field"><label class="label">退店日</label><input class="input" type="date" name="effective_date" value="<?= h(date('Y-m-d')) ?>" required></div>
                <div class="field"><label class="label">退店理由</label><textarea class="textarea" name="leave_reason"></textarea></div>
                <button class="btnLine btnSoft" style="margin-top:10px;">退店に更新</button>
              </form>
            </div>

            <form method="post" action="/wbss/public/applicants/actions/move_store.php" class="subCard" style="margin-top:12px;">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="person_id" value="<?= (int)$person['id'] ?>">
              <div class="panelHead">
                <h3 class="panelTitle">店舗移動</h3>
                <p class="panelLead">現在店舗から別店舗へ移すときだけ使います。</p>
              </div>
              <div class="formGrid">
                <div class="field"><label class="label">現在店舗</label><input class="input" value="<?= h((string)($person['current_store_name'] ?? '')) ?>" disabled></div>
                <div class="field"><label class="label">移動先店舗</label><select class="select" name="to_store_id" required><option value="">選択してください</option><?php foreach ($stores as $store): ?><option value="<?= (int)$store['id'] ?>"><?= h((string)$store['name']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label class="label">移動日</label><input class="input" type="date" name="move_date" value="<?= h(date('Y-m-d')) ?>" required></div>
                <div class="field"><label class="label">新店舗での源氏名</label><input class="input" name="genji_name"></div>
                <div class="field full"><label class="label">移動理由</label><textarea class="textarea" name="move_reason"></textarea></div>
              </div>
              <button class="btnLine btnPrimary" style="margin-top:12px;">店舗移動を登録</button>
            </form>
          </div>

          <div class="tabPanel" data-tab-panel="interview-history">
            <div class="panelHead">
              <h3 class="panelTitle">面接履歴一覧</h3>
              <p class="panelLead">過去の面接の結果、担当者、メモをまとめて確認できます。</p>
            </div>
            <div class="tableWrap">
              <?php if ($interviews): ?>
                <table>
                  <thead>
                    <tr>
                      <th>ID</th>
                      <th>面接日</th>
                      <th>店舗</th>
                      <th>担当者</th>
                      <th>結果</th>
                      <th>体験入店</th>
                      <th>総合点</th>
                      <th>メモ</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($interviews as $row): ?>
                      <tr>
                        <td>#<?= (int)$row['id'] ?></td>
                        <td><?= h((string)$row['interview_date']) ?></td>
                        <td><?= h((string)($row['interview_store_name'] ?? '')) ?></td>
                        <td><?= h((string)($row['interviewer_name'] ?? '')) ?></td>
                        <td><?= h(detail_result_label($row['interview_result'] ?? null)) ?></td>
                        <td><?= h(detail_trial_label($row['trial_status'] ?? null)) ?></td>
                        <td><?= h((string)($row['total_score'] ?? '—')) ?></td>
                        <td><?= nl2br(h(trim((string)($row['interview_notes'] ?? '')) !== '' ? (string)$row['interview_notes'] : (string)($row['score_comment'] ?? ''))) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php else: ?>
                <div class="mini" style="padding:16px;">面接履歴はまだありません。</div>
              <?php endif; ?>
            </div>
          </div>

          <div class="tabPanel" data-tab-panel="assignment-history">
            <div class="panelHead">
              <h3 class="panelTitle">店舗移動履歴 / 在籍履歴</h3>
              <p class="panelLead">いつ、どの店舗で、どんな区分だったかを時系列で見られます。</p>
            </div>
            <div class="tableWrap">
              <?php if ($assignments): ?>
                <table>
                  <thead>
                    <tr>
                      <th>状態</th>
                      <th>店舗</th>
                      <th>区分</th>
                      <th>開始日</th>
                      <th>終了日</th>
                      <th>源氏名</th>
                      <th>理由</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($assignments as $row): ?>
                      <tr>
                        <td><?= (int)$row['is_current'] === 1 ? '<span class="badge ok">現在在籍</span>' : '<span class="badge off">履歴</span>' ?></td>
                        <td><?= h((string)$row['store_name']) ?></td>
                        <td><?= h(detail_transition_label($row['transition_type'] ?? null)) ?></td>
                        <td><?= h((string)$row['start_date']) ?></td>
                        <td><?= h((string)($row['end_date'] ?? '—')) ?></td>
                        <td><?= h((string)($row['genji_name'] ?? '—')) ?></td>
                        <td><?= h((string)($row['move_reason'] ?: $row['leave_reason'] ?: '—')) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php else: ?>
                <div class="mini" style="padding:16px;">在籍履歴はまだありません。</div>
              <?php endif; ?>
            </div>
          </div>

          <div class="tabPanel" data-tab-panel="logs">
            <div class="panelHead">
              <h3 class="panelTitle">履歴ログ</h3>
              <p class="panelLead">誰が、いつ、どの状態に変えたかを追えるログです。</p>
            </div>
            <div class="tableWrap">
              <?php if ($logs): ?>
                <table>
                  <thead>
                    <tr>
                      <th>日時</th>
                      <th>操作</th>
                      <th>状態遷移</th>
                      <th>店舗</th>
                      <th>担当者</th>
                      <th>内容</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($logs as $row): ?>
                      <tr>
                        <td><?= h((string)$row['created_at']) ?></td>
                        <td><?= h((string)$row['action_type']) ?></td>
                        <td><?= h(trim((string)($row['from_status'] ?? '') . ' → ' . (string)($row['to_status'] ?? ''))) ?></td>
                        <td><?= h(trim((string)($row['store_name'] ?? '') . ((string)($row['target_store_name'] ?? '') !== '' ? ' → ' . (string)$row['target_store_name'] : ''))) ?></td>
                        <td><?= h((string)($row['actor_name'] ?? '')) ?></td>
                        <td><?= nl2br(h((string)($row['action_note'] ?? ''))) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php else: ?>
                <div class="mini" style="padding:16px;">履歴ログはまだありません。</div>
              <?php endif; ?>
            </div>
          </div>
        </section>
      <?php endif; ?>
    </div>
  </section>
  </div>
</div>

<script>
document.querySelectorAll('[data-tabs]').forEach((tabsRoot) => {
  const buttons = tabsRoot.querySelectorAll('[data-tab-target]');
  const host = tabsRoot.parentElement;
  if (!host) return;
  const panels = host.querySelectorAll('[data-tab-panel]');
  buttons.forEach((button) => {
    button.addEventListener('click', () => {
      const target = button.getAttribute('data-tab-target');
      buttons.forEach((btn) => btn.classList.toggle('is-active', btn === button));
      panels.forEach((panel) => panel.classList.toggle('is-active', panel.getAttribute('data-tab-panel') === target));
    });
  });
});
</script>

<?php render_page_end(); ?>
