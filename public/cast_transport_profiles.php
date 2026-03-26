<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/store_access.php';
require_once __DIR__ . '/../app/service_transport.php';

require_login();
require_role(['manager', 'admin', 'super_user']);

$pdo = db();
$msg = '';
$err = '';
$userId = (int)(current_user_id() ?? 0);
$hasPickupTarget = transport_profile_has_pickup_target_field($pdo);
$hasSecondary = transport_profile_has_secondary_fields($pdo);

try {
  $stores = transport_allowed_stores($pdo);
  $storeId = transport_resolve_store_id($pdo, (int)($_GET['store_id'] ?? $_POST['store_id'] ?? 0));
  $storeName = store_access_find_store_name($stores, $storeId);
} catch (Throwable $e) {
  $stores = [];
  $storeId = 0;
  $storeName = '';
  $err = $e->getMessage();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $storeId > 0 && $err === '') {
  try {
    csrf_verify($_POST['csrf_token'] ?? null);
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    transport_save_profile($pdo, $storeId, $targetUserId, $_POST, $userId);
    $msg = '送迎設定を保存しました';
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$rows = [];
if ($storeId > 0) {
  try {
    $rows = transport_fetch_profiles($pdo, $storeId);
  } catch (Throwable $e) {
    if ($err === '') {
      $err = $e->getMessage();
    }
  }
}

$addressReadyCount = 0;
$coordReadyCount = 0;
$pickupEnabledCount = 0;
$subAddressReadyCount = 0;
foreach ($rows as $row) {
  if (!empty($row['has_address'])) $addressReadyCount++;
  if (!empty($row['has_coords'])) $coordReadyCount++;
  if (!empty($row['has_sub_address'])) $subAddressReadyCount++;
  if (transport_pickup_target_requires_pickup((string)($row['pickup_target'] ?? 'primary'), (int)($row['pickup_enabled'] ?? 1))) {
    $pickupEnabledCount++;
  }
}

$headerActions = '';
if ($storeId > 0) {
  $headerActions .= '<a class="btn" href="/wbss/public/store_casts.php?store_id=' . (int)$storeId . '">キャスト一覧へ</a> ';
  $headerActions .= '<a class="btn" href="/wbss/public/transport/map.php?store_id=' . (int)$storeId . '">送迎マップへ</a> ';
  $headerActions .= '<a class="btn" href="/wbss/public/transport_routes.php?store_id=' . (int)$storeId . '">送迎ルートへ</a>';
}

render_page_start('送迎設定');
render_header('送迎設定', [
  'back_href' => $storeId > 0 ? '/wbss/public/store_casts.php?store_id=' . (int)$storeId : '/wbss/public/dashboard.php',
  'back_label' => '← キャスト一覧',
  'right_html' => $headerActions,
]);
?>
<div class="page">
  <div class="admin-wrap transportProfilesPage transportShell">
    <?php if ($msg !== ''): ?>
      <div class="card noticeOk"><?= h($msg) ?></div>
    <?php endif; ?>
    <?php if ($err !== ''): ?>
      <div class="card" style="border-color:#ef4444"><?= h($err) ?></div>
    <?php endif; ?>

    <div class="pageHero dashboardStyleHero">
      <div class="rowTop">
        <div class="titleWrap">
          <div class="title">キャスト送迎プロフィール</div>
          <div class="heroMeta">
            <span class="heroChip">店舗 <b><?= h($storeName !== '' ? $storeName : '-') ?></b></span>
            <span class="heroChip">対象 <b><?= count($rows) ?></b> 名</span>
            <span class="heroChip">2地点対応 <b><?= $hasSecondary ? 'ON' : '準備前' ?></b></span>
          </div>
        </div>
      </div>
      <div class="subInfo">
        <div class="muted">キャストプロフィールと同じく、メイン地点とサブ地点の2つを登録できます。今日の迎車地点もここで切り替えられます。</div>
      </div>
    </div>

    <section class="transportToolbar transportPanel dashboardTonePanel">
      <form method="get" class="searchRow transportFilterRow">
        <label class="muted">店舗</label>
        <select name="store_id" class="sel" onchange="this.form.submit()">
          <?php foreach ($stores as $store): ?>
            <?php $sid = (int)($store['id'] ?? 0); ?>
            <option value="<?= $sid ?>" <?= $sid === $storeId ? 'selected' : '' ?>>
              <?= h((string)($store['name'] ?? '')) ?> (#<?= $sid ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </form>

      <div class="transportStatGrid">
        <div class="transportStatCard">
          <span class="transportStatLabel">送迎対象</span>
          <b class="transportStatValue"><?= (int)$pickupEnabledCount ?></b>
        </div>
        <div class="transportStatCard">
          <span class="transportStatLabel">メイン住所</span>
          <b class="transportStatValue"><?= (int)$addressReadyCount ?></b>
        </div>
        <div class="transportStatCard">
          <span class="transportStatLabel">メイン座標</span>
          <b class="transportStatValue"><?= (int)$coordReadyCount ?></b>
        </div>
        <div class="transportStatCard">
          <span class="transportStatLabel">サブ地点</span>
          <b class="transportStatValue"><?= (int)$subAddressReadyCount ?></b>
        </div>
      </div>
    </section>

    <div class="transportProfileList">
      <?php foreach ($rows as $row): ?>
        <?php
          $targetUserId = (int)($row['user_id'] ?? 0);
          $shopTag = trim((string)($row['shop_tag'] ?? ''));
          $shopLabel = $shopTag !== '' ? $shopTag : (string)$targetUserId;
          $defaultStart = (string)($row['default_start_time'] ?? '');
          $primarySummary = trim(implode(' ', array_filter([
            (string)($row['pickup_prefecture'] ?? ''),
            (string)($row['pickup_city'] ?? ''),
            (string)($row['pickup_address1'] ?? ''),
            (string)($row['pickup_address2'] ?? ''),
            (string)($row['pickup_building'] ?? ''),
          ], static fn(string $value): bool => trim($value) !== '')));
          $secondarySummary = trim(implode(' ', array_filter([
            (string)($row['pickup_sub_prefecture'] ?? ''),
            (string)($row['pickup_sub_city'] ?? ''),
            (string)($row['pickup_sub_address1'] ?? ''),
            (string)($row['pickup_sub_address2'] ?? ''),
            (string)($row['pickup_sub_building'] ?? ''),
          ], static fn(string $value): bool => trim($value) !== '')));
        ?>
        <form method="post" class="card transportProfileCard transportPanel" id="cast-<?= $targetUserId ?>">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
          <input type="hidden" name="user_id" value="<?= $targetUserId ?>">

          <div class="transportProfileHead">
            <div class="transportProfileIdentity">
              <div class="transportProfileEyebrow">CAST #<?= h($shopLabel) ?></div>
              <div class="transportProfileName"><?= h((string)($row['display_name'] ?? '')) ?></div>
              <div class="transportMetaRow">
                <span class="metaPill">開店時間 <?= h($defaultStart !== '' ? substr($defaultStart, 0, 5) : '-') ?></span>
                <span class="metaPill">権限 <?= h((string)$row['privacy_level']) ?></span>
                <?php if ($hasPickupTarget): ?>
                  <span class="metaPill">今日の来店 <?= h(transport_pickup_target_label((string)($row['pickup_target'] ?? 'primary'))) ?></span>
                <?php endif; ?>
              </div>
            </div>
            <div class="transportBadges">
              <span class="badge <?= !empty($row['has_address']) ? 'ok' : 'ng' ?>"><?= !empty($row['has_address']) ? 'メイン住所あり' : 'メイン住所なし' ?></span>
              <span class="badge <?= !empty($row['has_coords']) ? 'ok' : 'ng' ?>"><?= !empty($row['has_coords']) ? 'メイン座標あり' : 'メイン座標なし' ?></span>
              <?php if ($hasSecondary): ?>
                <span class="badge <?= !empty($row['has_sub_address']) ? 'ok' : 'ng' ?>"><?= !empty($row['has_sub_address']) ? 'サブ住所あり' : 'サブ住所なし' ?></span>
              <?php endif; ?>
            </div>
          </div>

          <div class="transportStickySave">
            <div class="transportStickySaveMeta">変更したらここからすぐ保存できます</div>
            <button type="submit" class="btn btn-primary">保存</button>
          </div>

          <?php if ($hasPickupTarget && $hasSecondary): ?>
            <div class="pickupTargetSwitch managerPickupSwitch">
              <button type="button" class="pickupTargetBtn <?= ((string)($row['pickup_target'] ?? 'primary') === 'primary') ? 'is-active' : '' ?>" data-pickup-target-value="primary">メイン住所に迎え</button>
              <button type="button" class="pickupTargetBtn <?= ((string)($row['pickup_target'] ?? 'primary') === 'secondary') ? 'is-active' : '' ?>" data-pickup-target-value="secondary">サブ住所迎え</button>
              <button type="button" class="pickupTargetBtn <?= ((string)($row['pickup_target'] ?? 'primary') === 'self') ? 'is-active' : '' ?>" data-pickup-target-value="self">自走</button>
              <input type="hidden" name="pickup_target" value="<?= h((string)($row['pickup_target'] ?? 'primary')) ?>">
            </div>
          <?php endif; ?>

          <div class="transportBlocks">
            <details class="transportBlock">
              <summary class="transportBlockSummary">
                <div class="transportBlockHead">
                  <div class="transportBlockSummaryLeft">
                    <span class="transportHamburger" aria-hidden="true">☰</span>
                    <div>
                      <div class="transportBlockTitle">メイン地点</div>
                      <div class="transportBlockLead">一番よく使う迎車場所</div>
                    </div>
                  </div>
                  <span class="transportBlockBadge">メイン地点</span>
                </div>
                <div class="transportBlockSummaryText">
                  <?= h($primarySummary !== '' ? $primarySummary : '住所未登録') ?>
                </div>
              </summary>
              <div class="transportBlockBody">
              <div class="transportAddressGrid">
                <label class="field fieldFull zipField">
                  <span class="fieldLabel">郵便番号</span>
                  <div class="zipLookupRow">
                    <input class="in" name="pickup_zip" maxlength="16" value="<?= h((string)$row['pickup_zip']) ?>" placeholder="1600022">
                    <button type="button" class="miniBtn js-zip-lookup" data-zip-prefix="pickup_">住所取得</button>
                  </div>
                  <div class="muted js-zip-status" data-zip-prefix="pickup_">郵便番号から都道府県・市区町村・町名を補完できます</div>
                </label>
                <label class="field compactField">
                  <span class="fieldLabel">都道府県</span>
                  <input class="in" name="pickup_prefecture" maxlength="64" value="<?= h((string)$row['pickup_prefecture']) ?>">
                </label>
                <label class="field compactField">
                  <span class="fieldLabel">市区町村</span>
                  <input class="in" name="pickup_city" maxlength="128" value="<?= h((string)$row['pickup_city']) ?>">
                </label>
                <label class="field fieldFull">
                  <span class="fieldLabel">町名・番地</span>
                  <input class="in" name="pickup_address1" maxlength="255" value="<?= h((string)$row['pickup_address1']) ?>">
                </label>
                <label class="field">
                  <span class="fieldLabel">建物・部屋番号</span>
                  <input class="in" name="pickup_address2" maxlength="255" value="<?= h((string)$row['pickup_address2']) ?>">
                </label>
                <label class="field">
                  <span class="fieldLabel">建物補足</span>
                  <input class="in" name="pickup_building" maxlength="255" value="<?= h((string)$row['pickup_building']) ?>">
                </label>
                <label class="field coordField">
                  <span class="fieldLabel">緯度</span>
                  <input class="in mono" name="pickup_lat" inputmode="decimal" value="<?= h((string)$row['pickup_lat']) ?>" placeholder="35.0000000">
                  <div class="muted">空欄なら住所から自動取得します</div>
                </label>
                <label class="field coordField">
                  <span class="fieldLabel">経度</span>
                  <input class="in mono" name="pickup_lng" inputmode="decimal" value="<?= h((string)$row['pickup_lng']) ?>" placeholder="139.0000000">
                  <div class="muted">手入力した場合はその値を優先します</div>
                </label>
                <div class="field fieldFull geoActionField">
                  <button type="button" class="btn js-geocode-address" data-geo-prefix="pickup_">住所から緯度経度を取得</button>
                  <div class="muted js-geo-status" data-geo-prefix="pickup_">住所を編集したあとに、保存前でも座標を取得できます</div>
                </div>
                <label class="field fieldFull">
                  <span class="fieldLabel">目印・メモ</span>
                  <textarea class="in ta" name="pickup_note" rows="2"><?= h((string)$row['pickup_note']) ?></textarea>
                </label>
              </div>
              </div>
            </details>

            <?php if ($hasSecondary): ?>
              <details class="transportBlock is-secondary">
                <summary class="transportBlockSummary">
                  <div class="transportBlockHead">
                    <div class="transportBlockSummaryLeft">
                      <span class="transportHamburger" aria-hidden="true">☰</span>
                      <div>
                        <div class="transportBlockTitle">サブ地点</div>
                        <div class="transportBlockLead">予備の迎車場所や待ち合わせ場所</div>
                      </div>
                    </div>
                    <span class="transportBlockBadge is-secondary">サブ地点</span>
                  </div>
                  <div class="transportBlockSummaryText">
                    <?= h($secondarySummary !== '' ? $secondarySummary : '住所未登録') ?>
                  </div>
                </summary>
                <div class="transportBlockBody">
                <div class="transportAddressGrid">
                  <label class="field fieldFull zipField">
                    <span class="fieldLabel">郵便番号</span>
                    <div class="zipLookupRow">
                      <input class="in" name="pickup_sub_zip" maxlength="16" value="<?= h((string)$row['pickup_sub_zip']) ?>" placeholder="1600022">
                      <button type="button" class="miniBtn js-zip-lookup" data-zip-prefix="pickup_sub_">住所取得</button>
                    </div>
                    <div class="muted js-zip-status" data-zip-prefix="pickup_sub_">郵便番号から都道府県・市区町村・町名を補完できます</div>
                  </label>
                  <label class="field compactField">
                    <span class="fieldLabel">都道府県</span>
                    <input class="in" name="pickup_sub_prefecture" maxlength="64" value="<?= h((string)$row['pickup_sub_prefecture']) ?>">
                  </label>
                  <label class="field compactField">
                    <span class="fieldLabel">市区町村</span>
                    <input class="in" name="pickup_sub_city" maxlength="128" value="<?= h((string)$row['pickup_sub_city']) ?>">
                  </label>
                  <label class="field fieldFull">
                    <span class="fieldLabel">町名・番地</span>
                    <input class="in" name="pickup_sub_address1" maxlength="255" value="<?= h((string)$row['pickup_sub_address1']) ?>">
                  </label>
                  <label class="field">
                    <span class="fieldLabel">建物・部屋番号</span>
                    <input class="in" name="pickup_sub_address2" maxlength="255" value="<?= h((string)$row['pickup_sub_address2']) ?>">
                  </label>
                  <label class="field">
                    <span class="fieldLabel">建物補足</span>
                    <input class="in" name="pickup_sub_building" maxlength="255" value="<?= h((string)$row['pickup_sub_building']) ?>">
                  </label>
                  <label class="field coordField">
                    <span class="fieldLabel">緯度</span>
                    <input class="in mono" name="pickup_sub_lat" inputmode="decimal" value="<?= h((string)$row['pickup_sub_lat']) ?>" placeholder="35.0000000">
                    <div class="muted">空欄なら住所から自動取得します</div>
                  </label>
                  <label class="field coordField">
                    <span class="fieldLabel">経度</span>
                    <input class="in mono" name="pickup_sub_lng" inputmode="decimal" value="<?= h((string)$row['pickup_sub_lng']) ?>" placeholder="139.0000000">
                    <div class="muted">手入力した場合はその値を優先します</div>
                  </label>
                  <div class="field fieldFull geoActionField">
                    <button type="button" class="btn js-geocode-address" data-geo-prefix="pickup_sub_">住所から緯度経度を取得</button>
                    <div class="muted js-geo-status" data-geo-prefix="pickup_sub_">住所を編集したあとに、保存前でも座標を取得できます</div>
                  </div>
                  <label class="field fieldFull">
                    <span class="fieldLabel">目印・メモ</span>
                    <textarea class="in ta" name="pickup_sub_note" rows="2"><?= h((string)$row['pickup_sub_note']) ?></textarea>
                  </label>
                </div>
                </div>
              </details>
            <?php endif; ?>
          </div>

          <details class="transportMetaMenu">
            <summary class="transportMetaSummary">
              <div class="transportMetaSummaryLeft">
                <span class="transportHamburger" aria-hidden="true">☰</span>
                <div>
                  <div class="transportMetaSummaryTitle">詳細設定</div>
                  <div class="transportMetaSummaryLead">送迎利用、公開範囲、保存操作</div>
                </div>
              </div>
              <div class="transportMetaInline">
                <span><?= (int)$row['pickup_enabled'] === 1 ? '送迎対象' : '送迎対象外' ?></span>
                <span><?= h((string)$row['privacy_level']) ?></span>
              </div>
            </summary>

            <div class="transportMetaBody">
              <div class="transportSettingsGrid">
                <label class="field">
                  <span class="fieldLabel">送迎利用</span>
                  <select class="in" name="pickup_enabled">
                    <option value="1" <?= (int)$row['pickup_enabled'] === 1 ? 'selected' : '' ?>>対象</option>
                    <option value="0" <?= (int)$row['pickup_enabled'] === 0 ? 'selected' : '' ?>>対象外</option>
                  </select>
                </label>
                <label class="field">
                  <span class="fieldLabel">公開範囲</span>
                  <select class="in" name="privacy_level">
                    <option value="manager_only" <?= (string)$row['privacy_level'] === 'manager_only' ? 'selected' : '' ?>>manager以上</option>
                    <option value="admin_only" <?= (string)$row['privacy_level'] === 'admin_only' ? 'selected' : '' ?>>admin以上</option>
                  </select>
                </label>
                <?php if ($hasPickupTarget && !$hasSecondary): ?>
                  <label class="field">
                    <span class="fieldLabel">今日の迎車地点</span>
                    <select class="in" name="pickup_target">
                      <option value="primary" <?= (string)($row['pickup_target'] ?? 'primary') === 'primary' ? 'selected' : '' ?>>メインを使う</option>
                      <option value="secondary" <?= (string)($row['pickup_target'] ?? 'primary') === 'secondary' ? 'selected' : '' ?>>サブを使う</option>
                      <option value="self" <?= (string)($row['pickup_target'] ?? 'primary') === 'self' ? 'selected' : '' ?>>自走する</option>
                    </select>
                  </label>
                <?php endif; ?>
              </div>

              <div class="transportProfileFoot compactFoot">
                <div class="transportMetaNote">
                  最終更新 <?= h((string)($row['updated_at'] !== '' ? $row['updated_at'] : '-')) ?>
                  / メイン座標更新 <?= h((string)($row['pickup_geocoded_at'] !== '' ? $row['pickup_geocoded_at'] : '-')) ?>
                  <?php if ($hasSecondary): ?>
                    / サブ座標更新 <?= h((string)($row['pickup_sub_geocoded_at'] !== '' ? $row['pickup_sub_geocoded_at'] : '-')) ?>
                  <?php endif; ?>
                </div>
                <div class="transportActionRow">
                  <a class="btn" href="/wbss/public/admin/cast_shift_edit.php?store_id=<?= (int)$storeId ?>&user_id=<?= $targetUserId ?>">出勤編集</a>
                </div>
              </div>
            </div>
          </details>
        </form>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<style>
.transportShell{ display:grid; gap:14px; padding-bottom:28px; }
.transportPanel{
  border:1px solid rgba(255,255,255,.08);
  border-radius:26px;
  background:
    radial-gradient(circle at top right, rgba(56,189,248,.14), transparent 34%),
    linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.025)),
    var(--cardA);
  box-shadow:0 18px 48px rgba(3,7,18,.18);
}
.dashboardStyleHero{
  border:1px solid rgba(255,255,255,.08);
  border-radius:28px;
  padding:18px 20px;
  background:
    radial-gradient(circle at left top, rgba(34,197,94,.14), transparent 32%),
    radial-gradient(circle at right top, rgba(59,130,246,.14), transparent 28%),
    linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.02)),
    var(--cardA);
  box-shadow:0 18px 48px rgba(3,7,18,.16);
}
.dashboardTonePanel{ padding:16px 18px; display:grid; gap:14px; }
.transportFilterRow{ margin:0; }
.transportStatGrid{ display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:10px; }
.transportStatCard{ padding:14px 16px; border:1px solid rgba(255,255,255,.08); border-radius:18px; background:rgba(255,255,255,.045); display:grid; gap:4px; }
.transportStatLabel{ font-size:11px; font-weight:800; color:var(--muted); }
.transportStatValue{ font-size:20px; font-weight:900; }
.transportProfilesPage .transportProfileList{ display:grid; gap:14px; }
.transportProfilesPage .transportProfileCard{ display:grid; gap:18px; padding:20px; position:relative; overflow:hidden; }
.transportProfilesPage .transportProfileCard::before{
  content:"";
  position:absolute;
  inset:0 auto auto 0;
  width:100%;
  height:4px;
  background:linear-gradient(90deg, #38bdf8, #22c55e, #f59e0b);
}
.transportProfileHead{ display:flex; justify-content:space-between; gap:12px; align-items:flex-start; position:relative; z-index:1; }
.transportProfileIdentity{ display:grid; gap:8px; }
.transportProfileEyebrow{ font-size:11px; font-weight:900; letter-spacing:.08em; color:var(--muted); }
.transportProfileName{ font-size:22px; font-weight:900; letter-spacing:.01em; }
.transportMetaRow{ display:flex; gap:8px; flex-wrap:wrap; }
.metaPill{ padding:6px 10px; border-radius:999px; border:1px solid rgba(255,255,255,.1); background:rgba(255,255,255,.05); color:var(--muted); font-size:12px; font-weight:700; }
.transportBadges{ display:flex; gap:8px; flex-wrap:wrap; }
.transportStickySave{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:10px;
  padding:10px 14px;
  border:1px solid rgba(255,255,255,.08);
  border-radius:18px;
  background:rgba(255,255,255,.04);
}
.transportStickySaveMeta{
  color:var(--muted);
  font-size:12px;
  font-weight:700;
}
.managerPickupSwitch{ margin-top:-4px; }
.pickupTargetSwitch{ display:flex; gap:8px; flex-wrap:wrap; }
.pickupTargetBtn{
  min-height:44px;
  padding:10px 16px;
  border-radius:999px;
  border:1px solid rgba(255,255,255,.08);
  background:rgba(255,255,255,.04);
  color:inherit;
  font-weight:800;
}
.pickupTargetBtn.is-active{
  background:linear-gradient(135deg, rgba(56,189,248,.18), rgba(34,197,94,.12));
  border-color:rgba(56,189,248,.35);
}
.transportBlocks{ display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:14px; }
.transportBlock{ border:1px solid rgba(255,255,255,.08); border-radius:22px; background:rgba(255,255,255,.035); display:grid; gap:0; overflow:hidden; }
.transportBlock.is-secondary{ background:linear-gradient(180deg, rgba(251,191,36,.08), rgba(255,255,255,.03)); }
.transportBlockSummary{ list-style:none; cursor:pointer; padding:16px; }
.transportBlockSummary::-webkit-details-marker{ display:none; }
.transportBlockBody{ padding:0 16px 16px; display:grid; gap:14px; }
.transportBlockHead{ display:flex; justify-content:space-between; gap:10px; align-items:flex-start; }
.transportBlockSummaryLeft{ display:flex; gap:12px; align-items:flex-start; }
.transportBlockSummaryText{
  margin-top:10px;
  color:var(--muted);
  font-size:12px;
  line-height:1.55;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
.transportHamburger{
  width:36px;
  height:36px;
  display:grid;
  place-items:center;
  border-radius:12px;
  background:rgba(255,255,255,.05);
  border:1px solid rgba(255,255,255,.08);
  font-size:18px;
}
.transportBlockTitle{ font-size:16px; font-weight:900; }
.transportBlockLead{ color:var(--muted); font-size:12px; }
.transportBlockBadge{ padding:6px 10px; border-radius:999px; background:rgba(56,189,248,.14); border:1px solid rgba(56,189,248,.28); font-size:11px; font-weight:900; letter-spacing:.08em; }
.transportBlockBadge.is-secondary{ background:rgba(245,158,11,.14); border-color:rgba(245,158,11,.28); }
.transportAddressGrid{ display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; }
.transportProfileGrid{ display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:12px; }
.transportProfileGrid .field,
.transportSettingsGrid .field{ display:grid; gap:6px; }
.transportAddressGrid .field{ display:grid; gap:6px; }
.transportProfileGrid .fieldWide{ grid-column:span 2; }
.transportProfileGrid .fieldFull{ grid-column:1 / -1; }
.transportAddressGrid .fieldFull{ grid-column:1 / -1; }
.zipField{
  padding:12px;
  border-radius:18px;
  border:1px solid rgba(255,255,255,.08);
  background:rgba(255,255,255,.04);
}
.compactField .in{ min-height:48px; }
.coordField{
  padding-top:4px;
  border-top:1px dashed rgba(255,255,255,.08);
}
.geoActionField{
  padding:10px 12px;
  border-radius:16px;
  border:1px dashed rgba(255,255,255,.12);
  background:rgba(255,255,255,.03);
}
.transportMetaMenu{
  border:1px solid rgba(255,255,255,.08);
  border-radius:20px;
  background:rgba(255,255,255,.03);
  overflow:hidden;
}
.transportMetaSummary{
  list-style:none;
  cursor:pointer;
  padding:14px 16px;
}
.transportMetaSummary::-webkit-details-marker{ display:none; }
.transportMetaSummaryLeft{ display:flex; gap:12px; align-items:flex-start; }
.transportMetaSummaryTitle{ font-size:14px; font-weight:900; }
.transportMetaSummaryLead{ color:var(--muted); font-size:12px; }
.transportMetaInline{
  margin-left:auto;
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  color:var(--muted);
  font-size:12px;
  font-weight:700;
}
.transportMetaSummary{
  display:flex;
  justify-content:space-between;
  gap:12px;
  align-items:center;
}
.transportMetaBody{
  padding:0 16px 16px;
  display:grid;
  gap:12px;
}
.transportSettingsGrid{ display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:12px; }
.transportProfileFoot{ display:flex; justify-content:space-between; gap:12px; align-items:center; }
.compactFoot{
  padding-top:10px;
  border-top:1px dashed rgba(255,255,255,.08);
}
.transportMetaNote{ font-size:12px; color:var(--muted); line-height:1.55; }
.transportActionRow{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.zipLookupRow{ display:grid; grid-template-columns:minmax(0, 180px) auto; gap:8px; align-items:center; }
.zipLookupRow .miniBtn{ min-height:44px; }
.ta{ min-height:72px; }
@media (max-width: 900px){
  .transportStatGrid,
  .transportBlocks,
  .transportSettingsGrid,
  .transportAddressGrid,
  .transportProfileGrid{ grid-template-columns:repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 640px){
  .transportProfileHead,
  .transportProfileFoot,
  .transportMetaSummary,
  .transportStickySave{ flex-direction:column; align-items:stretch; }
  .transportStatGrid,
  .transportBlocks,
  .transportSettingsGrid,
  .transportAddressGrid,
  .transportProfileGrid{ grid-template-columns:1fr; }
  .transportProfileGrid .fieldWide,
  .transportProfileGrid .fieldFull,
  .transportAddressGrid .fieldFull{ grid-column:auto; }
  .zipLookupRow{ grid-template-columns:1fr; }
}
</style>
<script>
document.querySelectorAll('.pickupTargetSwitch').forEach((wrap) => {
  const hidden = wrap.querySelector('input[name="pickup_target"]');
  const buttons = Array.from(wrap.querySelectorAll('.pickupTargetBtn'));
  if (!hidden || buttons.length === 0) return;
  buttons.forEach((button) => {
    button.addEventListener('click', () => {
      hidden.value = button.dataset.pickupTargetValue || 'primary';
      buttons.forEach((btn) => btn.classList.toggle('is-active', btn === button));
    });
  });
});

document.querySelectorAll('.transportProfileCard').forEach((form) => {
  form.querySelectorAll('.js-zip-lookup').forEach((zipBtn) => {
    zipBtn.addEventListener('click', async () => {
      const prefix = zipBtn.dataset.zipPrefix || 'pickup_';
      const zipInput = form.querySelector(`input[name="${prefix}zip"]`);
      const prefInput = form.querySelector(`input[name="${prefix}prefecture"]`);
      const cityInput = form.querySelector(`input[name="${prefix}city"]`);
      const address1Input = form.querySelector(`input[name="${prefix}address1"]`);
      const latInput = form.querySelector(`input[name="${prefix}lat"]`);
      const lngInput = form.querySelector(`input[name="${prefix}lng"]`);
      const csrfInput = form.querySelector('input[name="csrf_token"]');
      const status = form.querySelector(`.js-zip-status[data-zip-prefix="${prefix}"]`);
      const zip = (zipInput?.value || '').trim();

      if (!zipInput || !prefInput || !cityInput || !address1Input || !csrfInput || !status) {
        return;
      }

      status.textContent = '住所を取得しています...';
      zipBtn.disabled = true;
      try {
        const body = new URLSearchParams();
        body.set('csrf_token', csrfInput.value);
        body.set('zip', zip);

        const res = await fetch('/wbss/public/api/transport_lookup_zip.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          credentials: 'same-origin',
          body: body.toString(),
        });
        const json = await res.json();
        if (!res.ok || !json.ok) {
          throw new Error(json.error || '住所取得に失敗しました');
        }

        prefInput.value = json.prefecture || prefInput.value;
        cityInput.value = json.city || cityInput.value;
        address1Input.value = json.address1 || address1Input.value;
        if (latInput && lngInput && !String(latInput.value || '').trim() && !String(lngInput.value || '').trim() && json.lat && json.lng) {
          latInput.value = json.lat;
          lngInput.value = json.lng;
        }
        status.textContent = json.formatted_address || '住所を反映しました';
      } catch (error) {
        status.textContent = error instanceof Error ? error.message : '住所取得に失敗しました';
      } finally {
        zipBtn.disabled = false;
      }
    });
  });

  form.querySelectorAll('.js-geocode-address').forEach((geoBtn) => {
    geoBtn.addEventListener('click', async () => {
      const prefix = geoBtn.dataset.geoPrefix || 'pickup_';
      const prefInput = form.querySelector(`input[name="${prefix}prefecture"]`);
      const cityInput = form.querySelector(`input[name="${prefix}city"]`);
      const address1Input = form.querySelector(`input[name="${prefix}address1"]`);
      const address2Input = form.querySelector(`input[name="${prefix}address2"]`);
      const buildingInput = form.querySelector(`input[name="${prefix}building"]`);
      const latInput = form.querySelector(`input[name="${prefix}lat"]`);
      const lngInput = form.querySelector(`input[name="${prefix}lng"]`);
      const csrfInput = form.querySelector('input[name="csrf_token"]');
      const status = form.querySelector(`.js-geo-status[data-geo-prefix="${prefix}"]`);

      if (!prefInput || !cityInput || !address1Input || !latInput || !lngInput || !csrfInput || !status) {
        return;
      }

      status.textContent = '座標を取得しています...';
      geoBtn.disabled = true;
      try {
        const body = new URLSearchParams();
        body.set('csrf_token', csrfInput.value);
        body.set('prefecture', prefInput.value || '');
        body.set('city', cityInput.value || '');
        body.set('address1', address1Input.value || '');
        body.set('address2', address2Input ? address2Input.value || '' : '');
        body.set('building', buildingInput ? buildingInput.value || '' : '');

        const res = await fetch('/wbss/public/api/transport_geocode_address.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          credentials: 'same-origin',
          body: body.toString(),
        });
        const json = await res.json();
        if (!res.ok || !json.ok) {
          throw new Error(json.error || '座標取得に失敗しました');
        }

        latInput.value = json.lat || '';
        lngInput.value = json.lng || '';
        status.textContent = json.formatted_address || '座標を反映しました';
      } catch (error) {
        status.textContent = error instanceof Error ? error.message : '座標取得に失敗しました';
      } finally {
        geoBtn.disabled = false;
      }
    });
  });
});
</script>
<?php render_page_end(); ?>
