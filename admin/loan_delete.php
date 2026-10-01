<?php
// /finance/admin/loan_delete.php
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

fin_require_login();
fin_require_permission('delete_loan');
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

/** Find best “loan id” foreign key column in a table */
function fin_find_loan_fk(PDO $pdo, string $table): string {
  return fin_first_col($pdo, $table, ['loan_id','loans_id','loanId'], '');
}

/** Count payments linked to a loan across possible tables */
function fin_count_loan_payments(PDO $pdo, int $bizId, int $loanId): int {
  $candidates = ['loan_payments','loan_payment','payments','repayments','payment_collections'];
  $total = 0;

  foreach ($candidates as $t) {
    if (!fin_table_exists($pdo, $t)) continue;

    $fk = fin_find_loan_fk($pdo, $t);
    if ($fk === '') continue;

    $bizCol = fin_first_col($pdo, $t, ['business_id','biz_id'], '');

    // If payments table has business column, include it; otherwise count by loan only.
    if ($bizCol !== '') {
      $st = $pdo->prepare("SELECT COUNT(*) FROM {$t} WHERE {$bizCol} = ? AND {$fk} = ?");
      $st->execute([$bizId, $loanId]);
    } else {
      $st = $pdo->prepare("SELECT COUNT(*) FROM {$t} WHERE {$fk} = ?");
      $st->execute([$loanId]);
    }

    $total += (int)$st->fetchColumn();
  }

  return $total;
}

$loansBizCol = fin_first_col($pdo, 'loans', ['business_id','biz_id'], 'business_id');
$loanCustCol = fin_first_col($pdo, 'loans', ['customer_id','cust_id'], '');
$custBizCol  = fin_table_exists($pdo,'customers') ? fin_first_col($pdo,'customers',['business_id','biz_id'], 'business_id') : '';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
  header('Location: loans.php?err=invalid');
  exit;
}

/* ---------- Load loan (+ customer if possible) ---------- */
$loan = null;

try {
  if ($loanCustCol !== '' && fin_table_exists($pdo,'customers')) {
    $sql = "
      SELECT
        l.*,
        c.full_name AS customer_name,
        c.phone     AS customer_phone
      FROM loans l
      LEFT JOIN customers c
        ON c.id = l.{$loanCustCol}
       AND c.{$custBizCol} = ?
      WHERE l.id = ?
        AND l.{$loansBizCol} = ?
      LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$business_id, $id, $business_id]);
    $loan = $st->fetch(PDO::FETCH_ASSOC);
  } else {
    $st = $pdo->prepare("SELECT * FROM loans WHERE id = ? AND {$loansBizCol} = ? LIMIT 1");
    $st->execute([$id, $business_id]);
    $loan = $st->fetch(PDO::FETCH_ASSOC);
  }
} catch (Throwable $e) {
  // fallback
  $st = $pdo->prepare("SELECT * FROM loans WHERE id = ? AND {$loansBizCol} = ? LIMIT 1");
  $st->execute([$id, $business_id]);
  $loan = $st->fetch(PDO::FETCH_ASSOC);
}

if (!$loan) {
  header('Location: loans.php?err=notfound');
  exit;
}

/* ---------- Count payments ---------- */
$paymentCount = fin_count_loan_payments($pdo, (int)$business_id, (int)$id);

$err = '';

/* ---------- Soft delete strategy: try columns in order ---------- */
function fin_soft_delete_loan(PDO $pdo, string $bizCol, int $bizId, int $loanId): bool {
  // 1) is_active = 0
  if (fin_col_exists($pdo,'loans','is_active')) {
    $st = $pdo->prepare("UPDATE loans SET is_active = 0 WHERE id = ? AND {$bizCol} = ? LIMIT 1");
    $st->execute([$loanId, $bizId]);
    return true;
  }

  // 2) status = 'cancelled'
  if (fin_col_exists($pdo,'loans','status')) {
    $st = $pdo->prepare("UPDATE loans SET status = 'cancelled' WHERE id = ? AND {$bizCol} = ? LIMIT 1");
    $st->execute([$loanId, $bizId]);
    return true;
  }

  // 3) deleted_at timestamp
  if (fin_col_exists($pdo,'loans','deleted_at')) {
    $st = $pdo->prepare("UPDATE loans SET deleted_at = NOW() WHERE id = ? AND {$bizCol} = ? LIMIT 1");
    $st->execute([$loanId, $bizId]);
    return true;
  }

  return false;
}

/* ---------- Handle POST ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  // re-check paymentCount at action time
  $paymentCount = fin_count_loan_payments($pdo, (int)$business_id, (int)$id);

  try {
    if ($action === 'delete') {
      if ($paymentCount > 0) {
        $err = "មិនអាចលុបកម្ចីនេះបានទេ ព្រោះមានប្រតិបត្តិការ/ការទូទាត់ភ្ជាប់ {$paymentCount}។ សូមប្រើ “បិទកម្ចី (Soft Delete)” ជំនួស។";
      } else {
        $pdo->beginTransaction();

        // If there are schedule rows, you may want to delete them too (optional).
        // We will delete common schedule tables if exist and have loan FK:
        $scheduleTables = ['loan_schedules','loan_schedule','schedules','repayment_schedules'];
        foreach ($scheduleTables as $t) {
          if (!fin_table_exists($pdo, $t)) continue;
          $fk = fin_find_loan_fk($pdo, $t);
          if ($fk === '') continue;
          $bizColT = fin_first_col($pdo, $t, ['business_id','biz_id'], '');
          if ($bizColT !== '') {
            $st = $pdo->prepare("DELETE FROM {$t} WHERE {$bizColT} = ? AND {$fk} = ?");
            $st->execute([$business_id, $id]);
          } else {
            $st = $pdo->prepare("DELETE FROM {$t} WHERE {$fk} = ?");
            $st->execute([$id]);
          }
        }

        $del = $pdo->prepare("DELETE FROM loans WHERE id = ? AND {$loansBizCol} = ? LIMIT 1");
        $del->execute([$id, $business_id]);

        $pdo->commit();
        header('Location: loans.php?deleted=1');
        exit;
      }
    }
    elseif ($action === 'soft_delete') {
      $ok = fin_soft_delete_loan($pdo, $loansBizCol, (int)$business_id, (int)$id);
      if (!$ok) {
        $err = "មិនអាច Soft Delete បានទេ (មិនឃើញ column: is_active / status / deleted_at)។ សូមប្រាប់ខ្ញុំ column នៅក្នុង loans table របស់អ្នក។";
      } else {
        header('Location: loans.php?deactivated=1');
        exit;
      }
    }
    else {
      $err = 'Invalid action.';
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = 'មានបញ្ហា៖ ' . $e->getMessage();
  }
}

/* ---------- Display helpers ---------- */
$customerName  = (string)($loan['customer_name'] ?? '');
$customerPhone = (string)($loan['customer_phone'] ?? '');
$principal     = $loan['principal'] ?? ($loan['amount'] ?? ($loan['loan_amount'] ?? ''));
?>
<!doctype html>
<html lang="km">
<head>
  <meta charset="utf-8">
  <title>លុបកម្ចី | Finance</title>
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
      <h4 class="page-title mb-0"><i class="bi bi-trash3-fill me-1"></i> លុបកម្ចី</h4>
      <div class="sub">សូមពិនិត្យម្តងទៀត មុនធ្វើសកម្មភាព</div>
    </div>
    <div>
      <a class="btn btn-outline-secondary" href="loans.php"><i class="bi bi-arrow-left me-1"></i> ត្រឡប់</a>
    </div>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-1"></i> <?= h2($err) ?></div>
  <?php endif; ?>

  <div class="cardx p-3 p-lg-4">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
      <div>
        <div class="fw-bold" style="font-size:1.05rem;">
          កម្ចី #<?= (int)$loan['id'] ?>
          <?php if ($principal !== '' && $principal !== null): ?>
            <span class="text-muted">— <?= h2($principal) ?></span>
          <?php endif; ?>
        </div>
        <div class="text-muted small">
          <?php if ($customerName !== '' || $customerPhone !== ''): ?>
            <span class="pill"><i class="bi bi-person"></i> <?= h2($customerName ?: '-') ?></span>
            <span class="pill"><i class="bi bi-telephone"></i> <?= h2($customerPhone ?: '-') ?></span>
          <?php else: ?>
            <span class="pill"><i class="bi bi-info-circle"></i> Customer info not joined</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="text-muted small">
        <span class="pill"><i class="bi bi-receipt"></i> Payments: <?= (int)$paymentCount ?></span>
      </div>
    </div>

    <?php if ($paymentCount > 0): ?>
      <div class="alert alert-warning" style="border-radius:14px;">
        <div class="fw-bold"><i class="bi bi-shield-exclamation me-1"></i> មិនអាចលុបបាន</div>
        <div class="small">
          កម្ចីនេះមានប្រតិបត្តិការ/ការទូទាត់ភ្ជាប់ <b><?= (int)$paymentCount ?></b>។
          សូមប្រើ <b>បិទកម្ចី (Soft Delete)</b> ជំនួស។
        </div>
      </div>

      <form method="post" class="d-flex gap-2 justify-content-end">
        <input type="hidden" name="id" value="<?= (int)$loan['id'] ?>">
        <input type="hidden" name="action" value="soft_delete">
        <a class="btn btn-outline-secondary" href="loans.php"><i class="bi bi-x-circle me-1"></i> បោះបង់</a>
        <button class="btn btn-primary" type="submit">
          <i class="bi bi-toggle2-off me-1"></i> បិទកម្ចី (Soft Delete)
        </button>
      </form>

    <?php else: ?>

      <div class="alert alert-warning" style="border-radius:14px;">
        <div class="fw-bold"><i class="bi bi-exclamation-triangle me-1"></i> ព្រមាន!</div>
        <div class="small">ការលុបនេះ <b>មិនអាចត្រឡប់វិញ</b> បានទេ។</div>
      </div>

      <form method="post" class="d-flex gap-2 justify-content-end">
        <input type="hidden" name="id" value="<?= (int)$loan['id'] ?>">
        <input type="hidden" name="action" value="delete">
        <a class="btn btn-outline-secondary" href="loans.php"><i class="bi bi-x-circle me-1"></i> បោះបង់</a>
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
