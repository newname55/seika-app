<div class="card">
  <div class="h2">基本情報（編集）</div>
  <div class="muted" style="font-size:12px; margin-bottom:8px;">
    名前・特徴・固定メモ・状態・最終来店・担当(user_id)
  </div>

  <form method="post" action="/seika-app/public/api/customer_save.php" class="row">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
    <input type="hidden" name="customer_id" value="<?= (int)$customerId ?>">

    <div style="flex:1; min-width:220px;">
      <div class="muted" style="font-size:12px;">名前</div>
      <input name="display_name" value="<?= h((string)$customer['display_name']) ?>">
    </div>

    <div style="flex:1; min-width:220px;">
      <div class="muted" style="font-size:12px;">特徴（見分けポイント）</div>
      <input name="features" value="<?= h((string)$customer['features']) ?>">
    </div>

    <div style="min-width:160px;">
      <div class="muted" style="font-size:12px;">状態</div>
      <select name="status">
        <option value="active" <?= ((string)$customer['status']==='active')?'selected':''; ?>>在籍</option>
        <option value="inactive" <?= ((string)$customer['status']==='inactive')?'selected':''; ?>>休眠</option>
      </select>
    </div>

    <div style="min-width:200px;">
      <div class="muted" style="font-size:12px;">最終来店（任意）</div>
      <input name="last_visit_at" value="<?= h((string)($customer['last_visit_at'] ?? '')) ?>" placeholder="2026-03-01 21:00:00">
    </div>

    <div style="min-width:200px;">
      <div class="muted" style="font-size:12px;">担当(user_id / 任意)</div>
      <input name="assigned_user_id" value="<?= h((string)($customer['assigned_user_id'] ?? '')) ?>" placeholder="例：12">
    </div>

    <div style="flex:1; min-width:260px;">
      <div class="muted" style="font-size:12px;">店全体メモ（固定）</div>
      <textarea name="note_public"><?= h((string)($customer['note_public'] ?? '')) ?></textarea>
    </div>

    <div style="min-width:160px; align-self:end;">
      <button type="submit">保存</button>
    </div>
  </form>
</div>