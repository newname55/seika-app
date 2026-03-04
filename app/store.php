<?php
declare(strict_types=1);

/**
 * Store context helper (session-based)
 * - super_user/admin/manager: store can be selected (superは必須にしてもOK)
 * - cast: 基本この仕組みは使わない（cast側は自分のstore固定ロジックが別にある）
 *
 * 互換のため:
 * - current_store_id() を用意（stock側が呼んでても落ちない）
 * - require_store_selected_for_super() を提供
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

const STORE_SESSION_KEY = '__store_id';

function set_current_store_id(int $storeId): void {
  if ($storeId <= 0) {
    unset($_SESSION[STORE_SESSION_KEY]);
    return;
  }
  $_SESSION[STORE_SESSION_KEY] = $storeId;
}

function get_current_store_id(): int {
  return (int)($_SESSION[STORE_SESSION_KEY] ?? 0);
}

/** 互換：古いコードが current_store_id() を呼ぶ想定がある */
function current_store_id(): int {
  return get_current_store_id();
}

/**
 * super_user が「店舗未選択のまま」ダッシュボード等へ来たら
 * store_select.php に飛ばして選択させる（return付き）
 */
function require_store_selected_for_super(bool $isSuper, string $returnTo): void {
  if (!$isSuper) return;
  if (get_current_store_id() > 0) return;

  $ret = $returnTo !== '' ? $returnTo : '/seika-app/public/dashboard.php';
  header('Location: /seika-app/public/store_select.php?return=' . rawurlencode($ret));
  exit;
}

/**
 * 一般用途：ストア未選択なら store_select へ（return付き）
 * ※admin/manager でも「必須」にしたいページで呼ぶ用
 */
function require_store_selected(string $returnTo): int {
  $sid = get_current_store_id();
  if ($sid > 0) return $sid;

  $ret = $returnTo !== '' ? $returnTo : '/seika-app/public/dashboard.php';
  header('Location: /seika-app/public/store_select.php?return=' . rawurlencode($ret));
  exit;
}