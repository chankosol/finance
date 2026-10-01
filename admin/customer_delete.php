<?php
// /finance/admin/customer_delete.php
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

fin_require_login();
fin_require_permission('delete_customer');
$business_id = fin_require_business();
global $pdo;

function h2($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

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
function fin_table_exists(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
  ");
  $st->execute([$table]);
  return (int)$st->fetchColumn() > 0;
}
function fin_first_col(PDO $pdo, string $table, array $candidates, string $fallback=''): string {
  foreach ($candidates as $c) {
    if (fin_col_exists($pdo, $table, $c)) return $c;
  }
  return $fallback;
}

$customersBizCol = fin_first_col($pdo, 'customers', ['business_id','biz_id'], 'business_id');
$loansBizCol     = fin_table_exists($pdo,'loans') ? fin_first_col($pdo, 'loans', ['business_id','biz_id'], '') : '';
$loanCustCol     = fin_table_exists($pdo,'loans') ? fin_first_col($pdo, 'loans', ['customer_id','cust_id'], '') : '';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
  header('Location: customers.php?err=invalid');
  exit;
}

/* ---------- load customer ---------- */
$st = $pdo->prepare("SELECT id, full_name, phone, is_active, photo FROM customers WHERE id = ? AND {$customersBizCol} = ? LIMIT 1");
$st->execute([$id, $business_id]);
$customer = $st->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
  header('Location: customers.php?err=notfound');
  exit;
}

/* ---------- count loans (if table/cols exist) ---------- */
$loanCount = 0;
if ($loansBizCol !== '' && $loanCustCol !== '') {
  $st = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE {$loansBizCol} = ? AND {$loanCustCol} = ?");
  $st->execute([$business_id, $id]);
  $loanCount = (int)$st->fetchColumn();
}

$err = '';
$ok  = '';

/* ---------- handle POST actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  // Re-check loan count at action time
  if ($loansBizCol !== '' && $loanCustCol !== '') {
    $st = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE {$loansBizCol} = ? AND {$loanCustCol} = ?");
    $st->execute([$business_id, $id]);
    $loanCount = (int)$st->fetchColumn();
  } else {
    $loanCount = 0;
  }

  try {
    if ($action === 'delete') {
      if ($loanCount > 0) {
        $err = "មិនអាចលុបបានទេ ព្រោះអតិថិជននេះមានកម្ចីភ្ជាប់ចំនួន {$loanCount}។ សូមប្រើ “បិទសកម្ម (Deactivate)” ជំនួស។";
      } else {
        $pdo->beginTransaction();

        // delete file only if under uploads/customers/
        $photo = (string)($customer['photo'] ?? '');

        $del = $pdo->prepare("DELETE FROM customers WHERE id = ? AND {$customersBizCol} = ? LIMIT 1");
        $del->execute([$id, $business_id]);

        if ($photo !== '' && !preg_match('#^https?://#i', $photo)) {
          if (str_starts_with($photo, 'uploads/customers/')) {
            $fsPath = dirname(__DIR__) . '/' . $photo; // /finance
            if (is_file($fsPath)) @unlink($fsPath);
          }
        }

        $pdo->commit();
        header('Location: customers.php?deleted=1');
        exit;
      }
    }
    elseif ($action === 'deactivate') {
      // Soft delete = set is_active = 0
      $up = $pdo->prepare("UPDATE customers SET is_active = 0 WHERE id = ? AND {$customersBizCol} = ? LIMIT 1");
      $up->execute([$id, $business_id]);
      header('Location: customers.php?deactivated=1');
      exit;
    }
    else {
      $err = 'Invalid action.';
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = 'មានបញ្ហា៖ ' . $e->getMessage();
  }
}
?>
<!doctype html>
<html lang="km">
<head>
  <meta charset="utf-8">
  <title>លុបអតិថិជន | Finance</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Battambang:wght@300;400;700;900&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

  <style>
    :root{ --bg:#f3f4f6; --card:#fff; --ink:#0f172a; --muted:#64748b; --line:#e5e7eb; --danger:#dc2626; --brand:#2563eb; }
    body{font-family:'Battambang',sans-serif;background:var(--bg); color:var(--ink);}
    .cardx{ background:var(--card); border:1px solid rgba(15,23,42,.08); border-radius:18px; box-shadow:none; }
    .page-title{ font-weight:900; }
    .sub{ color:var(--muted); font-size:.92rem; }
    .btn{ border-radius:12px; font-weight:800; padding:.5rem .85rem; }
    .btn-danger{ background:var(--danger); border-color:var(--danger); }
    .btn-primary{ background:var(--brand); border-color:var(--brand); }
    .pill{ display:inline-flex; align-items:center; gap:.35rem; padding:.25rem .55rem; border-radius:999px; border:1px solid var(--line); background:#fff; font-size:.85rem; color:#334155; }
  </style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="container py-4">

  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
    <div>
      <h4 class="page-title mb-0"><i class="bi bi-trash3-fill me-1"></i> លុបអតិថិជន</h4>
      <div class="sub">សូមពិនិត្យម្តងទៀត មុនធ្វើសកម្មភាព</div>
    </div>
    <div>
      <a class="btn btn-outline-secondary" href="customers.php"><i class="bi bi-arrow-left me-1"></i> ត្រឡប់</a>
    </div>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-1"></i> <?= h2($err) ?></div>
  <?php endif; ?>

  <div class="cardx p-3 p-lg-4">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
      <div>
        <div class="fw-bold" style="font-size:1.05rem;"><?= h2($customer['full_name'] ?? '') ?></div>
        <div class="text-muted small">
          <span class="pill"><i class="bi bi-telephone"></i> <?= h2($customer['phone'] ?? '-') ?></span>
          <span class="pill"><i class="bi bi-hash"></i> ID: <?= (int)$customer['id'] ?></span>
          <span class="pill"><i class="bi bi-toggle2-<?= ((int)$customer['is_active']===1?'on':'off') ?>"></i> <?= ((int)$customer['is_active']===1?'Active':'Inactive') ?></span>
        </div>
      </div>
      <div class="text-muted small">
        <span class="pill"><i class="bi bi-folder2-open"></i> កម្ចីភ្ជាប់: <?= (int)$loanCount ?></span>
      </div>
    </div>

    <?php if ($loanCount > 0): ?>
      <div class="alert alert-warning" style="border-radius:14px;">
        <div class="fw-bold"><i class="bi bi-shield-exclamation me-1"></i> មិនអាចលុបបាន</div>
        <div class="small">
          អតិថិជននេះមានកម្ចីភ្ជាប់ចំនួន <b><?= (int)$loanCount ?></b> ដូច្នេះសូមប្រើ <b>បិទសកម្ម (Deactivate)</b> ជំនួស។
        </div>
      </div>
      <form method="post" class="d-flex gap-2 justify-content-end">
        <input type="hidden" name="id" value="<?= (int)$customer['id'] ?>">
        <input type="hidden" name="action" value="deactivate">
        <a class="btn btn-outline-secondary" href="customers.php"><i class="bi bi-x-circle me-1"></i> បោះបង់</a>
        <button class="btn btn-primary" type="submit">
          <i class="bi bi-toggle2-off me-1"></i> បិទសកម្ម (Deactivate)
        </button>
      </form>
    <?php else: ?>
      <div class="alert alert-warning" style="border-radius:14px;">
        <div class="fw-bold"><i class="bi bi-exclamation-triangle me-1"></i> ព្រមាន!</div>
        <div class="small">ការលុបនេះ <b>មិនអាចត្រឡប់វិញ</b> បានទេ។</div>
      </div>
      <form method="post" class="d-flex gap-2 justify-content-end">
        <input type="hidden" name="id" value="<?= (int)$customer['id'] ?>">
        <input type="hidden" name="action" value="delete">
        <a class="btn btn-outline-secondary" href="customers.php"><i class="bi bi-x-circle me-1"></i> បោះបង់</a>
        <button class="btn btn-danger" type="submit">
          <i class="bi bi-trash3 me-1"></i> បញ្ជាក់លុប
        </button>
      </form>
    <?php endif; ?>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
