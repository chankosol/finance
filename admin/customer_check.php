<?php
// /finance/admin/customer_check.php
// Live customer lookup while typing (Name / Phone / NID)
// Returns JSON: { ok:true, found:true/false, matches:[...] }

require_once '../includes/auth.php';
require_once '../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

fin_require_login();
$business_id = fin_require_business();
global $pdo;

/* ===================== Helpers ===================== */
function fin_col_exists(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
  ");
  $st->execute([$table, $col]);
  return (int)$st->fetchColumn() > 0;
}

function fin_only_digits(string $s): string {
  return preg_replace('/\D+/', '', $s) ?? '';
}

function fin_normalize_phone(?string $phone): string {
  $p = trim((string)$phone);
  if ($p === '') return '';
  $d = fin_only_digits($p);
  // Cambodia +855XXXXXXXX -> 0XXXXXXXX
  if (str_starts_with($d, '855') && strlen($d) >= 11) {
    $d = '0' . substr($d, 3);
  }
  return $d;
}

function fin_json($arr){
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

/* ===================== Detect biz column ===================== */
$customersBizCol = fin_col_exists($pdo,'customers','business_id')
  ? 'business_id'
  : (fin_col_exists($pdo,'customers','biz_id') ? 'biz_id' : 'business_id');

/* ===================== Input ===================== */
/**
 * We accept:
 * - q  (single search text)
 * JS can send phone/name/nid too, but we only need q.
 */
$q = trim((string)($_GET['q'] ?? ''));

// If your JS also sends these, we can combine (optional)
$phone = trim((string)($_GET['phone'] ?? ''));
$name  = trim((string)($_GET['name'] ?? ''));
$nid   = trim((string)($_GET['nid'] ?? ''));

// Prefer q, else combine the first non-empty
if ($q === '') {
  $q = $phone !== '' ? $phone : ($nid !== '' ? $nid : $name);
}
$q = trim($q);

if ($q === '' || mb_strlen($q) < 1) {
  fin_json(['ok'=>true, 'found'=>false, 'matches'=>[]]);
}

/* Normalize numeric query for phone searching */
$qDigits = fin_only_digits($q);
$qPhone  = fin_normalize_phone($q); // also handles +855

// LIKE needs %...%
// We search "contains" in: full_name, phone, phone2, nid, id_card
$likeText = '%' . $q . '%';
$likeDigits = $qDigits !== '' ? '%' . $qDigits . '%' : null;
$likePhone  = $qPhone !== '' ? '%' . $qPhone . '%' : null;

try {
  // Build SQL with optional digit/phone filters
  // NOTE: We keep it fast by LIMIT 10 and only selecting needed fields.
  $sql = "
    SELECT id, full_name, phone, phone2, nid
    FROM customers
    WHERE {$customersBizCol} = :biz
      AND (
        full_name LIKE :likeText
        OR nid      LIKE :likeText
        OR id_card  LIKE :likeText
        OR phone    LIKE :likeText
        OR phone2   LIKE :likeText
  ";

  // Add digit-based matching for phones (so '016 536 939' still matches stored '016536939')
  if ($likeDigits !== null) {
    // phone REGEXP replace is expensive; instead we match digits against phone/phone2 assuming stored phones are normalized.
    $sql .= " OR phone  LIKE :likeDigits OR phone2 LIKE :likeDigits ";
  }

  // Add normalized phone matching as well
  if ($likePhone !== null && $likePhone !== $likeDigits) {
    $sql .= " OR phone LIKE :likePhone OR phone2 LIKE :likePhone ";
  }

  $sql .= ")
    ORDER BY id DESC
    LIMIT 10
  ";

  $st = $pdo->prepare($sql);
  $st->bindValue(':biz', (int)$business_id, PDO::PARAM_INT);
  $st->bindValue(':likeText', $likeText, PDO::PARAM_STR);

  if ($likeDigits !== null) $st->bindValue(':likeDigits', $likeDigits, PDO::PARAM_STR);
  if ($likePhone  !== null && $likePhone !== $likeDigits) $st->bindValue(':likePhone', $likePhone, PDO::PARAM_STR);

  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  fin_json([
    'ok'      => true,
    'found'   => count($rows) > 0,
    'matches' => $rows
  ]);

} catch (Throwable $e) {
  fin_json([
    'ok' => false,
    'error' => $e->getMessage(),
    'found' => false,
    'matches' => []
  ]);
}
