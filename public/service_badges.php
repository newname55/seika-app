<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/service_quiz.php';
require_once __DIR__ . '/../app/service_training.php';
require_once __DIR__ . '/../app/service_training_badge_logic.php';

require_login();
require_role(['cast']);

$pdo = db();
$userId = service_quiz_current_user_id();
$storeId = service_quiz_resolve_cast_store_id($pdo, $userId);

if ($storeId <= 0) {
  http_response_code(400);
  exit('店舗が未設定です。管理者に所属店舗を設定してもらってください。');
}

$latestQuizResult = service_quiz_fetch_latest_result($pdo, $storeId, $userId);
$badgeState = service_training_user_badges($pdo, $storeId, $userId, $latestQuizResult);
$badges = (array)($badgeState['all'] ?? []);
$summary = (array)($badgeState['summary'] ?? []);

render_page_start('バッジ図鑑');
render_header('バッジ図鑑', [
  'back_href' => '/wbss/public/service_training.php',
  'back_label' => '← トレーニングへ戻る',
  'show_store' => false,
  'show_user' => false,
]);
?>
<div class="page badgesPage">
  <section class="card badgesHero">
    <div class="badgesHero__eyebrow">WBSS Growth Book</div>
    <h1 class="badgesHero__title">バッジ図鑑</h1>
    <p class="badgesHero__lead">積み重ねた行動を、称号として見える化します。</p>

    <div class="badgesStats">
      <div class="badgesStats__item">
        <span class="badgesStats__label">獲得数</span>
        <strong class="badgesStats__value"><?= (int)($summary['earned_count'] ?? 0) ?><small>/<?= (int)($summary['total_count'] ?? 0) ?></small></strong>
      </div>
      <div class="badgesStats__item">
        <span class="badgesStats__label">達成率</span>
        <strong class="badgesStats__value"><?= (int)($summary['achievement_rate'] ?? 0) ?><small>%</small></strong>
      </div>
    </div>
  </section>

  <?php if ($badges): ?>
    <section class="badgesGrid" aria-label="バッジ一覧">
      <?php foreach ($badges as $badge): ?>
        <?php
          $progress = (array)($badge['progress'] ?? []);
          $earned = !empty($badge['earned']);
          $ratio = max(0, min(100, (int)round(((float)($progress['ratio'] ?? 0)) * 100)));
          $name = (string)($badge['display_name'] ?? '');
          $description = (string)($badge['display_description'] ?? '');
          $conditionText = (string)($badge['condition_text'] ?? '');
          $progressText = (string)($progress['text'] ?? '');
          $progressHint = (string)($progress['hint'] ?? '');
          $rarity = (int)($badge['rarity'] ?? 1);
          $rarityLabel = (string)($badge['rarity_label'] ?? '');
          $rarityStars = (string)($badge['rarity_stars'] ?? '');
        ?>
        <button
          type="button"
          class="badgeCard<?= $earned ? ' is-earned' : ' is-locked' ?>"
          data-badge-modal
          data-name="<?= h($name) ?>"
          data-description="<?= h($description) ?>"
          data-condition="<?= h($conditionText) ?>"
          data-progress="<?= h($progressText) ?>"
          data-hint="<?= h($progressHint) ?>"
          data-rarity="<?= h($rarityLabel . ' ' . $rarityStars) ?>"
          data-earned="<?= $earned ? '1' : '0' ?>"
        >
          <div class="badgeCard__top">
            <span class="badgeCard__rarity"><?= h($rarityStars) ?></span>
            <span class="badgeCard__state"><?= $earned ? '獲得済み' : (!empty($badge['secret']) ? 'SECRET' : '挑戦中') ?></span>
          </div>
          <div class="badgeCard__title"><?= h($name) ?></div>
          <div class="badgeCard__desc"><?= h($description) ?></div>
          <div class="badgeCard__meta">
            <span><?= h($rarityLabel) ?></span>
            <span><?= h($earned ? '開放済み' : $progressHint) ?></span>
          </div>
          <div class="badgeCard__progress">
            <div class="badgeCard__progressBar"><span style="width: <?= $ratio ?>%;"></span></div>
            <div class="badgeCard__progressText"><?= h($progressText) ?></div>
          </div>
        </button>
      <?php endforeach; ?>
    </section>
  <?php else: ?>
    <section class="card badgesEmpty">
      まだ表示できるバッジがありません。
    </section>
  <?php endif; ?>
</div>

<div class="badgeModal" id="badge-modal" hidden>
  <button type="button" class="badgeModal__backdrop" data-badge-close aria-label="閉じる"></button>
  <div class="badgeModal__dialog card" role="dialog" aria-modal="true" aria-labelledby="badge-modal-title">
    <button type="button" class="badgeModal__close" data-badge-close aria-label="閉じる">×</button>
    <div class="badgeModal__rarity" id="badge-modal-rarity"></div>
    <h2 class="badgeModal__title" id="badge-modal-title"></h2>
    <p class="badgeModal__desc" id="badge-modal-description"></p>
    <dl class="badgeModal__list">
      <div>
        <dt>条件</dt>
        <dd id="badge-modal-condition"></dd>
      </div>
      <div>
        <dt>現在進捗</dt>
        <dd id="badge-modal-progress"></dd>
      </div>
      <div>
        <dt>状況</dt>
        <dd id="badge-modal-hint"></dd>
      </div>
    </dl>
  </div>
</div>

<style>
.badgesPage{padding-bottom:36px}
.badgesHero{
  background:
    radial-gradient(circle at top right, rgba(255,255,255,.86), transparent 30%),
    linear-gradient(180deg, #ffffff, #fafbff);
  border-color:#e8ebf3;
  box-shadow:0 18px 44px rgba(15,23,42,.06);
}
.badgesHero__eyebrow{
  display:inline-flex;
  padding:7px 11px;
  border-radius:999px;
  border:1px solid #e3e8f3;
  background:#ffffff;
  color:#6b7280;
  font-size:11px;
  font-weight:900;
  letter-spacing:.08em;
}
.badgesHero__title{margin:14px 0 0;font-size:30px;line-height:1.15;font-weight:1000;color:#111827}
.badgesHero__lead{margin:10px 0 0;color:#6b7280;font-size:14px;line-height:1.8}
.badgesStats{
  display:grid;
  grid-template-columns:repeat(2, minmax(0, 1fr));
  gap:14px;
  margin-top:18px;
}
.badgesStats__item{
  padding:16px 18px;
  border-radius:18px;
  background:#f8fafc;
  border:1px solid #e6ebf2;
}
.badgesStats__label{display:block;font-size:12px;font-weight:800;color:#6b7280}
.badgesStats__value{display:block;margin-top:6px;font-size:30px;line-height:1;font-weight:1000;color:#111827}
.badgesStats__value small{font-size:14px;font-weight:800;color:#6b7280}
.badgesGrid{
  display:grid;
  grid-template-columns:repeat(3, minmax(0, 1fr));
  gap:16px;
  margin-top:20px;
}
.badgeCard{
  display:block;
  width:100%;
  padding:18px;
  border:1px solid #e6ebf2;
  border-radius:22px;
  background:#ffffff;
  box-shadow:0 14px 34px rgba(15,23,42,.06);
  text-align:left;
  transition:transform .16s ease, box-shadow .16s ease, background-color .16s ease, border-color .16s ease;
}
.badgeCard:hover{transform:translateY(-2px);box-shadow:0 18px 40px rgba(15,23,42,.10)}
.badgeCard:active{transform:scale(.985)}
.badgeCard.is-earned{
  background:linear-gradient(180deg, #fff8ee 0%, #ffffff 100%);
  border-color:#f4d8ab;
}
.badgeCard.is-locked{
  background:linear-gradient(180deg, #f9fafb 0%, #f3f4f6 100%);
  border-color:#e5e7eb;
}
.badgeCard__top{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
}
.badgeCard__rarity{font-size:13px;font-weight:900;color:#f59e0b;letter-spacing:.08em}
.badgeCard__state{font-size:11px;font-weight:900;color:#6b7280;letter-spacing:.08em}
.badgeCard__title{margin-top:14px;font-size:20px;line-height:1.3;font-weight:1000;color:#111827}
.badgeCard__desc{margin-top:10px;min-height:48px;color:#4b5563;font-size:14px;line-height:1.7}
.badgeCard.is-locked .badgeCard__title,
.badgeCard.is-locked .badgeCard__desc,
.badgeCard.is-locked .badgeCard__meta,
.badgeCard.is-locked .badgeCard__progressText{color:#9ca3af}
.badgeCard__meta{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  margin-top:14px;
  font-size:12px;
  font-weight:800;
  color:#6b7280;
}
.badgeCard__progress{margin-top:12px}
.badgeCard__progressBar{
  height:10px;
  border-radius:999px;
  overflow:hidden;
  background:#e5e7eb;
}
.badgeCard__progressBar span{
  display:block;
  height:100%;
  border-radius:999px;
  background:linear-gradient(90deg, #fb923c 0%, #f59e0b 100%);
}
.badgeCard.is-locked .badgeCard__progressBar span{
  background:linear-gradient(90deg, #cbd5e1 0%, #94a3b8 100%);
}
.badgeCard__progressText{margin-top:8px;font-size:12px;font-weight:900;color:#374151}
.badgesEmpty{margin-top:20px;text-align:center;color:#6b7280}
.badgeModal[hidden]{display:none}
.badgeModal{
  position:fixed;
  inset:0;
  z-index:90;
}
.badgeModal__backdrop{
  position:absolute;
  inset:0;
  border:none;
  background:rgba(15,23,42,.45);
}
.badgeModal__dialog{
  position:relative;
  width:min(560px, calc(100vw - 28px));
  margin:7vh auto 0;
  padding:24px;
  background:#ffffff;
  border:1px solid #e5e7eb;
  box-shadow:0 30px 70px rgba(15,23,42,.22);
  animation:badgeFade .18s ease;
}
.badgeModal__close{
  position:absolute;
  top:14px;
  right:14px;
  width:36px;
  height:36px;
  border:none;
  border-radius:999px;
  background:#f3f4f6;
  color:#111827;
  font-size:20px;
}
.badgeModal__rarity{font-size:12px;font-weight:900;color:#f59e0b;letter-spacing:.08em}
.badgeModal__title{margin:10px 0 0;font-size:28px;line-height:1.2;font-weight:1000;color:#111827}
.badgeModal__desc{margin:12px 0 0;color:#4b5563;font-size:15px;line-height:1.8}
.badgeModal__list{margin:18px 0 0}
.badgeModal__list div{padding:14px 0;border-top:1px solid #eef2f7}
.badgeModal__list dt{font-size:12px;font-weight:900;color:#6b7280}
.badgeModal__list dd{margin:6px 0 0;font-size:15px;line-height:1.8;color:#111827}
@keyframes badgeFade{
  from{opacity:0;transform:translateY(8px)}
  to{opacity:1;transform:translateY(0)}
}
body[data-theme="dark"] .badgesHero,
body[data-theme="dark"] .badgesStats__item,
body[data-theme="dark"] .badgeCard,
body[data-theme="dark"] .badgeModal__dialog{
  background:
    radial-gradient(circle at top right, rgba(255,146,194,.18), transparent 40%),
    linear-gradient(180deg, rgba(38,43,61,.98), rgba(44,50,71,.96));
  border-color:rgba(255,255,255,.08);
}
body[data-theme="dark"] .badgesHero__title,
body[data-theme="dark"] .badgesStats__value,
body[data-theme="dark"] .badgeCard__title,
body[data-theme="dark"] .badgeModal__title,
body[data-theme="dark"] .badgeModal__list dd{
  color:#fff8fc;
}
body[data-theme="dark"] .badgesHero__lead,
body[data-theme="dark"] .badgesStats__label,
body[data-theme="dark"] .badgeCard__desc,
body[data-theme="dark"] .badgeCard__meta,
body[data-theme="dark"] .badgeCard__state,
body[data-theme="dark"] .badgeCard__progressText,
body[data-theme="dark"] .badgeModal__desc,
body[data-theme="dark"] .badgeModal__list dt{
  color:rgba(230,223,240,.82);
}
@media (max-width: 980px){
  .badgesGrid{grid-template-columns:repeat(2, minmax(0, 1fr))}
}
@media (max-width: 640px){
  .badgesHero__title{font-size:26px}
  .badgesGrid,
  .badgesStats{grid-template-columns:1fr}
  .badgeCard{padding:16px}
  .badgeModal__dialog{margin:10vh auto 0;padding:20px}
}
</style>

<script>
(() => {
  const modal = document.getElementById('badge-modal');
  if (!modal) return;

  const title = document.getElementById('badge-modal-title');
  const rarity = document.getElementById('badge-modal-rarity');
  const description = document.getElementById('badge-modal-description');
  const condition = document.getElementById('badge-modal-condition');
  const progress = document.getElementById('badge-modal-progress');
  const hint = document.getElementById('badge-modal-hint');

  const openModal = (button) => {
    title.textContent = button.dataset.name || '';
    rarity.textContent = button.dataset.rarity || '';
    description.textContent = button.dataset.description || '';
    condition.textContent = button.dataset.condition || '';
    progress.textContent = button.dataset.progress || '';
    hint.textContent = button.dataset.hint || '';
    modal.hidden = false;
    document.body.classList.add('is-modal-open');
  };

  const closeModal = () => {
    modal.hidden = true;
    document.body.classList.remove('is-modal-open');
  };

  document.querySelectorAll('[data-badge-modal]').forEach((button) => {
    button.addEventListener('click', () => openModal(button));
  });
  modal.querySelectorAll('[data-badge-close]').forEach((button) => {
    button.addEventListener('click', closeModal);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !modal.hidden) {
      closeModal();
    }
  });
})();
</script>
<?php render_page_end(); ?>
