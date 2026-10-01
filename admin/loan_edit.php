<?php
// /finance/admin/loan_edit.php
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

fin_require_login();
fin_require_permission('edit_loan');
$business_id = fin_require_business();
global $pdo;

function h2($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ============================
   Column detection helpers
============================ */
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

/* ============================
   Biz column detect
============================ */
$loansBizCol = fin_col_exists($pdo,'loans','business_id') ? 'business_id' : (fin_col_exists($pdo,'loans','biz_id') ? 'biz_id' : 'business_id');
$custBizCol  = fin_col_exists($pdo,'customers','business_id') ? 'business_id' : (fin_col_exists($pdo,'customers','biz_id') ? 'biz_id' : 'business_id');
$schedBizCol = fin_col_exists($pdo,'loan_schedules','business_id') ? 'business_id' : (fin_col_exists($pdo,'loan_schedules','biz_id') ? 'biz_id' : 'business_id');

/* ============================
   Ensure required tables
============================ */
if (!fin_table_exists($pdo,'loans')) { http_response_code(500); exit('Missing table: loans'); }
if (!fin_table_exists($pdo,'customers')) { http_response_code(500); exit('Missing table: customers'); }

/* ============================
   Loan Types (Dropdown)
============================ */
$stTypes = $pdo->prepare("SELECT code, label_km FROM dropdown_items WHERE business_id = ? AND category = 'loan_type' AND is_active = 1 ORDER BY sort_order ASC, id ASC");
$stTypes->execute([$business_id]);
$dbTypes = $stTypes->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

if (!empty($dbTypes)) {
  $LOAN_TYPES = $dbTypes;
  $LOAN_TYPES['other'] = 'ផ្សេងៗ (សូមបញ្ជាក់)';
} else {
  $LOAN_TYPES = [
    'installment_motor' => 'បង់រំលស់ម៉ូតូ',
    'installment_phone' => 'បង់រំលស់ទូរស័ព្ទ',
    'monthly'           => 'កម្ចីសងប្រចាំខែ',
    'daily'             => 'កម្ចីសងប្រចាំថ្ងៃ',
    'weekly'            => 'កម្ចីសងប្រចាំសប្ដាហ៍',
    'business'          => 'កម្ចីរបរកស៊ី',
    'emergency'         => 'កម្ចីបន្ទាន់',
    'refinance'         => 'បង្រួមបំណុល / Refinance',
    'other'             => 'ផ្សេងៗ (សូមបញ្ជាក់)'
  ];
}

/* ============================
   ✅ Status Detail Khmer map
============================ */
function fin_status_detail_kh_map(): array {
  return [
    'normal'      => 'បន្តបង់ធម្មតា',
    'paid_off'    => 'បង់ផ្តាច់រួច',
    'partial'     => 'បន្តបង់ខ្លះៗ',
    'no_contact'  => 'ទាក់ទងមិនបាន',
    'no_pay'      => 'អត់បង់សោះ',
    'capacity'    => 'បន្តបង់តាមលទ្ធភាព',
    'written_off' => 'កាត់ចោល',

    'បន្តបង់ធម្មតា'     => 'បន្តបង់ធម្មតា',
    'បង់ផ្តាច់រួច'      => 'បង់ផ្តាច់រួច',
    'បន្តបង់ខ្លះៗ'      => 'បន្តបង់ខ្លះៗ',
    'ទាក់ទងមិនបាន'      => 'ទាក់ទងមិនបាន',
    'អត់បង់សោះ'         => 'អត់បង់សោះ',
    'បន្តបង់តាមលទ្ធភាព' => 'បន្តបង់តាមលទ្ធភាព',
    'កាត់ចោល'           => 'កាត់ចោល',
  ];
}
function fin_normalize_status_detail_to_kh(string $v): string {
  $v = trim($v);
  if ($v === '') return 'បន្តបង់ធម្មតា';
  $map = fin_status_detail_kh_map();
  return $map[$v] ?? $v;
}

/* ============================
   Date helpers (same as add)
============================ */
function clamp_day_in_month(DateTime $d, int $day): void {
  $day = max(1, min(31, $day));
  $last = (int)$d->format('t');
  $d->setDate((int)$d->format('Y'), (int)$d->format('m'), min($day, $last));
}
function calc_due_date(string $start_date, int $months_to_add, int $repayment_day): string {
  $d = new DateTime($start_date);
  $d->setTime(0,0,0);
  $d->modify("+{$months_to_add} months");
  clamp_day_in_month($d, $repayment_day);
  return $d->format('Y-m-d');
}

/* ============================
   Schedule generator (same as add)
============================ */
function fin_generate_schedule(PDO $pdo, int $business_id, int $loan_id, string $schedBizCol, array $loan): void {
  if (!fin_table_exists($pdo,'loan_schedules')) return;

  $principal      = (float)($loan['principal_amount'] ?? $loan['principal'] ?? 0);
  $term_months    = (int)($loan['term_months'] ?? 0);
  $rate           = (float)($loan['interest_rate'] ?? 0);
  $interest_type  = 'flat';
  if (isset($loan['interest_type'])) {
      $interest_type = (string)$loan['interest_type'];
  } elseif (isset($loan['interest_method'])) {
      $interest_type = (string)$loan['interest_method'];
  }
  $interest_type  = strtolower(trim($interest_type));
  $start_date     = (string)($loan['start_date'] ?? date('Y-m-d'));
  $repayment_day  = (int)($loan['repayment_day'] ?? (int)date('d'));
  if ($repayment_day < 1 || $repayment_day > 31) $repayment_day = (int)date('d');

  if ($principal <= 0 || $term_months <= 0) return;

  $pdo->prepare("DELETE FROM loan_schedules WHERE loan_id=? AND {$schedBizCol}=?")->execute([$loan_id, $business_id]);

  $principal_per = $principal / $term_months;
  $remaining_principal = $principal;

  $ins = $pdo->prepare("
    INSERT INTO loan_schedules
      ({$schedBizCol}, loan_id, installment_no, due_date,
       principal_due, interest_due, fee_due, total_due, paid_total, status)
    VALUES
      (?,?,?,?,?,?,?,?,?,?)
  ");

  for ($i=1; $i<=$term_months; $i++) {
    $due_date = calc_due_date($start_date, $i, $repayment_day);

    $p_due = ($i === $term_months) ? $remaining_principal : $principal_per;
    $p_due = round($p_due, 2);

    $interest_base = ($interest_type === 'declining' || $interest_type === 'reducing')
      ? $remaining_principal
      : $principal;

    $i_due = round($interest_base * ($rate/100.0), 2);
    $fee   = 0.00;
    $total = round($p_due + $i_due + $fee, 2);

    $ins->execute([
      $business_id,
      $loan_id,
      $i,
      $due_date,
      $p_due,
      $i_due,
      $fee,
      $total,
      0.00,
      'DUE'
    ]);

    $remaining_principal = round($remaining_principal - $p_due, 8);
    if ($remaining_principal < 0) $remaining_principal = 0;
  }

  // Re-apply any existing payments (FIFO)
  if (fin_table_exists($pdo, 'loan_payments')) {
      $stP = $pdo->prepare("SELECT amount FROM loan_payments WHERE loan_id = ? ORDER BY id ASC");
      $stP->execute([$loan_id]);
      $payments = $stP->fetchAll(PDO::FETCH_COLUMN) ?: [];
      
      $totalPaid = array_sum(array_map('floatval', $payments));
      if ($totalPaid > 0.0001) {
          $stS = $pdo->prepare("SELECT id, total_due FROM loan_schedules WHERE loan_id = ? ORDER BY installment_no ASC, due_date ASC");
          $stS->execute([$loan_id]);
          $schedules = $stS->fetchAll(PDO::FETCH_ASSOC) ?: [];
          
          $remainingPay = $totalPaid;
          foreach ($schedules as $sc) {
              if ($remainingPay <= 0.0001) break;
              
              $scId = (int)$sc['id'];
              $total = (float)$sc['total_due'];
              
              $use = min($total, $remainingPay);
              $remainingPay -= $use;
              
              $newStatus = 'PARTIAL';
              if ($use >= $total - 0.0001) {
                  $use = $total;
                  $newStatus = 'PAID';
              }
              
              $pdo->prepare("
                  UPDATE loan_schedules
                  SET paid_total = ?, status = ?
                  WHERE id = ?
              ")->execute([$use, $newStatus, $scId]);
          }
      }
  }
}

/* ============================
   Load customers for dropdown
============================ */
$custNameCol = 'full_name';
try {
  $st = $pdo->query("SHOW COLUMNS FROM customers");
  $cols = $st->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
  if (!in_array('full_name', $cols, true) && in_array('name', $cols, true)) $custNameCol = 'name';
} catch(Throwable $e){}

$st = $pdo->prepare("
  SELECT id, `$custNameCol` AS full_name
  FROM customers
  WHERE {$custBizCol} = ?
  ORDER BY `$custNameCol` ASC
");
$st->execute([$business_id]);
$customers = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ============================
   Load loan by id (must belong to business)
============================ */
$loan_id = (int)($_GET['id'] ?? 0);
if ($loan_id <= 0) { http_response_code(400); exit('Missing loan id'); }

$st = $pdo->prepare("
  SELECT *
  FROM loans
  WHERE id=? AND {$loansBizCol}=?
  LIMIT 1
");
$st->execute([$loan_id, $business_id]);
$loan = $st->fetch(PDO::FETCH_ASSOC);
if (!$loan) { http_response_code(404); exit('Loan not found'); }

/* ============================
   Map DB -> form defaults
============================ */
$errors = [];

$customer_id     = (int)($loan['customer_id'] ?? 0);
$principal       = (string)($loan['principal_amount'] ?? $loan['principal'] ?? '');
$currency_code   = strtoupper((string)($loan['currency_code'] ?? 'USD'));

$interest_rate   = (string)($loan['interest_rate'] ?? '');
$interest_type   = 'flat';
if (isset($loan['interest_type'])) {
    $interest_type = (string)$loan['interest_type'];
} elseif (isset($loan['interest_method'])) {
    $interest_type = (string)$loan['interest_method'];
}
$interest_type   = strtolower(trim($interest_type));

$term_months     = (int)($loan['term_months'] ?? 1);
$repayment_day   = (int)($loan['repayment_day'] ?? (int)date('d'));

$status          = (string)($loan['status'] ?? 'active');
$status_detail   = fin_normalize_status_detail_to_kh((string)($loan['status_detail'] ?? 'បន្តបង់ធម្មតា'));

$purpose         = (string)($loan['purpose'] ?? '');
$collateral      = (string)($loan['collateral'] ?? '');
$note            = (string)($loan['note'] ?? '');

$start_date      = (string)($loan['start_date'] ?? date('Y-m-d'));
$due_date        = (string)($loan['due_date'] ?? calc_due_date($start_date, 1, $repayment_day));
$end_date        = (string)($loan['end_date'] ?? calc_due_date($start_date, max(1,$term_months), $repayment_day));

/* ============================
   Detect loan type selection
============================ */
$loan_type_db = trim((string)($loan['loan_type'] ?? ''));
$loan_type_key = '';
$loan_type_other = '';

if ($loan_type_db !== '') {
  // try match a predefined label
  $found = null;
  foreach ($LOAN_TYPES as $k => $label) {
    if ($k === 'other') continue;
    if (trim($label) === $loan_type_db) { $found = $k; break; }
  }
  if ($found) {
    $loan_type_key = $found;
    $loan_type_other = '';
  } else {
    $loan_type_key = 'other';
    $loan_type_other = $loan_type_db;
  }
}

/* ============================
   POST handler (update)
============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $customer_id   = (int)($_POST['customer_id'] ?? 0);

  $principal     = trim($_POST['principal'] ?? '');
  $currency_code = strtoupper(trim($_POST['currency_code'] ?? 'USD'));

  $interest_rate = trim($_POST['interest_rate'] ?? '');
  $interest_type = trim($_POST['interest_type'] ?? 'flat');

  $term_months   = (int)($_POST['term_months'] ?? 0);
  $repayment_day = (int)($_POST['repayment_day'] ?? 0);

  $loan_type_key   = trim($_POST['loan_type_key'] ?? '');
  $loan_type_other = trim($_POST['loan_type_other'] ?? '');
  $status          = trim($_POST['status'] ?? 'active');

  $status_detail_raw = trim($_POST['status_detail'] ?? 'បន្តបង់ធម្មតា');
  $status_detail = fin_normalize_status_detail_to_kh($status_detail_raw);

  $purpose       = trim($_POST['purpose'] ?? '');
  $collateral    = trim($_POST['collateral'] ?? '');
  $note          = trim($_POST['note'] ?? '');

  $start_date    = trim($_POST['start_date'] ?? '');

  /* ---------------- Validation ---------------- */
  if ($customer_id <= 0) $errors[] = 'សូមជ្រើសរើសអតិថិជន';

  if ($principal === '' || !is_numeric($principal) || (float)$principal <= 0) {
    $errors[] = 'សូមបញ្ចូលចំនួនប្រាក់ខ្ចីត្រឹមត្រូវ ( > 0 )';
  }

  if (!in_array($currency_code, ['USD','KHR'], true)) $currency_code = 'USD';

  if ($term_months <= 0) $errors[] = 'ចំនួនខែត្រូវខ្ចី ត្រូវ >= 1';

  if ($repayment_day < 1 || $repayment_day > 31) $errors[] = 'ថ្ងៃបង់ (Repayment day) ត្រូវនៅចន្លោះ 1 ដល់ 31';

  if ($loan_type_key === '') $errors[] = 'សូមជ្រើសរើសប្រភេទកម្ចី';

  if ($loan_type_key === 'other') {
    if ($loan_type_other === '') $errors[] = 'សូមបញ្ជាក់ប្រភេទកម្ចី (ផ្សេងៗ)';
    $loan_type = $loan_type_other;
  } else {
    $loan_type = $LOAN_TYPES[$loan_type_key] ?? '';
  }

  if ($start_date === '') $errors[] = 'សូមបញ្ចូលថ្ងៃចាប់ផ្តើម';

  if ($interest_rate !== '' && !is_numeric($interest_rate)) $errors[] = 'អត្រាការប្រាក់ ត្រូវជាលេខ';

  /* security: customer must belong to business */
  if ($customer_id > 0) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE id=? AND {$custBizCol}=?");
    $st->execute([$customer_id, $business_id]);
    if ((int)$st->fetchColumn() !== 1) $errors[] = 'អតិថិជនមិនត្រឹមត្រូវសម្រាប់ក្រុមហ៊ុននេះ';
  }

  /* ✅ compute due_date + end_date based on input */
  if ($start_date !== '' && $term_months > 0 && $repayment_day >= 1 && $repayment_day <= 31) {
    $due_date = calc_due_date($start_date, 1, $repayment_day);
    $end_date = calc_due_date($start_date, $term_months, $repayment_day);
  }

  if ($due_date === '') $errors[] = 'សូមពិនិត្យ Due date';
  if ($end_date === '') $errors[] = 'សូមពិនិត្យថ្ងៃដល់កំណត់';
  if ($start_date !== '' && $end_date !== '' && strtotime($end_date) < strtotime($start_date)) {
    $errors[] = 'ថ្ងៃដល់កំណត់ ត្រូវ >= ថ្ងៃចាប់ផ្តើម';
  }

  /* ---------------- Save (UPDATE) ---------------- */
  if (!$errors) {
    $pdo->beginTransaction();
    try {
      $updateFields = [];
      $params = [];

      $updateFields[] = "customer_id=?"; $params[] = $customer_id;
      
      if (fin_col_exists($pdo, 'loans', 'principal')) { $updateFields[] = "principal=?"; $params[] = (float)$principal; }
      if (fin_col_exists($pdo, 'loans', 'principal_amount')) { $updateFields[] = "principal_amount=?"; $params[] = (float)$principal; }
      if (fin_col_exists($pdo, 'loans', 'currency_code')) { $updateFields[] = "currency_code=?"; $params[] = $currency_code; }
      
      $updateFields[] = "interest_rate=?"; $params[] = ($interest_rate === '' ? null : (float)$interest_rate);
      
      if (fin_col_exists($pdo, 'loans', 'interest_method')) { $updateFields[] = "interest_method=?"; $params[] = $interest_type; }
      elseif (fin_col_exists($pdo, 'loans', 'interest_type')) { $updateFields[] = "interest_type=?"; $params[] = $interest_type; }
      
      if (fin_col_exists($pdo, 'loans', 'term_months')) { $updateFields[] = "term_months=?"; $params[] = $term_months; }
      if (fin_col_exists($pdo, 'loans', 'repayment_day')) { $updateFields[] = "repayment_day=?"; $params[] = $repayment_day; }
      if (fin_col_exists($pdo, 'loans', 'loan_type')) { $updateFields[] = "loan_type=?"; $params[] = ($loan_type !== '' ? $loan_type : null); }
      if (fin_col_exists($pdo, 'loans', 'status')) { $updateFields[] = "status=?"; $params[] = $status; }
      if (fin_col_exists($pdo, 'loans', 'status_detail')) { $updateFields[] = "status_detail=?"; $params[] = $status_detail; }
      if (fin_col_exists($pdo, 'loans', 'purpose')) { $updateFields[] = "purpose=?"; $params[] = ($purpose !== '' ? $purpose : null); }
      if (fin_col_exists($pdo, 'loans', 'collateral')) { $updateFields[] = "collateral=?"; $params[] = ($collateral !== '' ? $collateral : null); }
      if (fin_col_exists($pdo, 'loans', 'note')) { $updateFields[] = "note=?"; $params[] = ($note !== '' ? $note : null); }
      if (fin_col_exists($pdo, 'loans', 'start_date')) { $updateFields[] = "start_date=?"; $params[] = $start_date; }
      if (fin_col_exists($pdo, 'loans', 'due_date')) { $updateFields[] = "due_date=?"; $params[] = $end_date; }
      if (fin_col_exists($pdo, 'loans', 'end_date')) { $updateFields[] = "end_date=?"; $params[] = $end_date; }

      $params[] = $loan_id;
      $params[] = $business_id;

      $sqlUpdate = "UPDATE loans SET " . implode(", ", $updateFields) . " WHERE id=? AND {$loansBizCol}=? LIMIT 1";
      $st = $pdo->prepare($sqlUpdate);
      $st->execute($params);

      // regenerate schedule (safe default)
      $loanRow = [
        'principal_amount' => (float)$principal,
        'principal' => (float)$principal,
        'term_months' => $term_months,
        'repayment_day' => $repayment_day,
        'interest_rate' => ($interest_rate === '' ? 0 : (float)$interest_rate),
        'interest_type' => $interest_type,
        'start_date' => $start_date
      ];
      fin_generate_schedule($pdo, $business_id, $loan_id, $schedBizCol, $loanRow);

      $pdo->commit();

      header('Location: loan_view.php?id='.(int)$loan_id);
      exit;

    } catch(Throwable $e) {
      $pdo->rollBack();
      $errors[] = 'មានបញ្ហា៖ ' . $e->getMessage();
    }
  }
}

?>
<!doctype html>
<html lang="km">
<head>
<meta charset="utf-8">
<title>កែប្រែកម្ចី</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://fonts.googleapis.com/css2?family=Battambang:wght@300;400;700;900&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

<link href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" rel="stylesheet">

<style>
  :root{
    --bg:#f3f4f6; --card:#fff; --ink:#0f172a; --muted:#64748b; --line:#e5e7eb;
  }
  body{font-family:'Battambang',sans-serif;background:var(--bg); color:var(--ink)}
  .cardx{background:var(--card);border-radius:18px;box-shadow:0 10px 25px rgba(0,0,0,.06); border:1px solid rgba(15,23,42,.06)}
  .page-title{font-weight:900}
  .form-control,.form-select{border-radius:12px}
  .btn-soft{border:1px solid var(--line);background:#fff;border-radius:12px}
  .sub{color:var(--muted);font-size:.9rem}
  .section-title{font-weight:900; font-size:1rem; display:flex; align-items:center; gap:.5rem; margin-bottom:.2rem; min-height:24px;}
  .help{color:var(--muted); font-size:.88rem; margin-top:.35rem}
  .req{color:#dc2626; font-weight:900;}
  .input-group-text{border-radius:12px;}
  .pill{
    border:1px dashed rgba(15,23,42,.18);
    background:linear-gradient(180deg,#ffffff,#fbfdff);
    border-radius:16px;
    padding:.75rem .85rem;
  }
  .input-group .form-control,
  .input-group .form-select,
  .form-select,.form-control{min-height:44px;}
  .flatpickr-calendar{ font-family:'Battambang',sans-serif; }
</style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="container py-4">

  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
      <h4 class="page-title mb-0"><i class="bi bi-pencil-square me-1"></i> កែប្រែកម្ចី</h4>
      <div class="sub">Loan Edit</div>
    </div>

    <div class="d-flex gap-2 flex-wrap">
      <a href="loan_view.php?id=<?= (int)$loan_id ?>" class="btn btn-soft">
        <i class="bi bi-eye me-1"></i> មើល
      </a>
      <a href="loans.php" class="btn btn-soft">
        <i class="bi bi-arrow-left me-1"></i> ត្រឡប់
      </a>
    </div>
  </div>

  <div class="pill mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div class="help" style="margin-top:0">
      <i class="bi bi-hash me-1"></i> Loan ID: <span class="fw-bold"><?= (int)$loan_id ?></span>
    </div>
    <?php if ($customer_id): ?>
      <a class="btn btn-outline-secondary btn-sm" href="customer_view.php?id=<?= (int)$customer_id ?>" target="_blank">
        <i class="bi bi-person-vcard me-1"></i> អតិថិជន
      </a>
    <?php endif; ?>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i> សូមពិនិត្យម្តងទៀត</div>
      <?php foreach($errors as $e): ?><div>• <?= h2($e) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="cardx p-3 p-lg-4">
    <form method="post" class="row g-3">

      <!-- ROW 1 -->
      <div class="col-12 col-lg-6">
        <div class="section-title"><i class="bi bi-person-badge"></i> អតិថិជន <span class="req">*</span></div>
        <div class="help mb-2" style="margin-top:0">ជ្រើសរើសអតិថិជនដែលខ្ចីប្រាក់</div>

        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-person"></i></span>
          <select name="customer_id" class="form-select" required>
            <option value="">-- ជ្រើសរើស --</option>
            <?php foreach($customers as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= ($customer_id==(int)$c['id']) ? 'selected':''; ?>>
                <?= h2($c['full_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if ($customer_id): ?>
          <a href="customer_view.php?id=<?= (int)$customer_id ?>" class="btn btn-outline-primary btn-sm mt-2" target="_blank">
            <i class="bi bi-person-vcard me-1"></i> មើលព័ត៌មានអតិថិជន
          </a>
        <?php endif; ?>
      </div>

      <div class="col-12 col-md-6 col-lg-3">
        <div class="section-title"><i class="bi bi-cash-stack"></i> ចំនួនប្រាក់ <span class="req">*</span></div>
        <div class="input-group">
          <span class="input-group-text" id="amount_currency_symbol"><i class="bi bi-coin"></i></span>
          <input type="number" step="0.01" min="0" name="principal" class="form-control"
                 value="<?= h2($principal) ?>" required placeholder="0.00">
        </div>
        <div class="help">ចំនួនប្រាក់ខ្ចី (principal)</div>
      </div>

      <div class="col-12 col-md-6 col-lg-3">
        <div class="section-title"><i class="bi bi-currency-exchange"></i> រូបិយប័ណ្ណ</div>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-cash-coin"></i></span>
          <select name="currency_code" id="currency_code" class="form-select">
            <option value="USD" <?= ($currency_code==='USD'?'selected':'') ?>>USD ($)</option>
            <option value="KHR" <?= ($currency_code==='KHR'?'selected':'') ?>>KHR (៛)</option>
          </select>
        </div>
      </div>

      <!-- ROW 2 -->
      <div class="col-12 col-md-6 col-lg-4">
        <div class="section-title"><i class="bi bi-percent"></i> អត្រាការប្រាក់ (%)ខែ</div>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-graph-up-arrow"></i></span>
          <input type="number" step="0.01" min="0" name="interest_rate" class="form-control"
                 value="<?= h2($interest_rate) ?>" placeholder="ឧ: 3.00">
        </div>
        <div class="help">ទុកទទេបាន (Optional)</div>
      </div>

      <div class="col-12 col-md-6 col-lg-4">
        <div class="section-title"><i class="bi bi-sliders"></i> ប្រភេទការប្រាក់</div>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-ui-checks"></i></span>
          <select name="interest_type" class="form-select">
            <option value="fixed" <?= ($interest_type==='fixed'?'selected':'') ?>>ថេរ (Fixed)</option>
            <option value="flat" <?= ($interest_type==='flat'?'selected':'') ?>>ផ្ទាត់ (Flat)</option>
            <option value="declining" <?= ($interest_type==='declining'?'selected':'') ?>>ថយចុះ (Declining)</option>
          </select>
        </div>
      </div>

      <div class="col-12 col-md-6 col-lg-2">
        <div class="section-title"><i class="bi bi-calendar3"></i> ចំនួនខែ <span class="req">*</span></div>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-calendar2-week"></i></span>
          <input type="number" min="1" name="term_months" id="term_months"
                 class="form-control" value="<?= (int)$term_months ?>" required>
        </div>
        <div class="help">សម្រាប់ schedule</div>
      </div>

      <div class="col-12 col-md-6 col-lg-2">
        <div class="section-title"><i class="bi bi-calendar-date"></i> ថ្ងៃបង់ <span class="req">*</span></div>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-calendar-heart"></i></span>
          <select name="repayment_day" id="repayment_day" class="form-select" required>
            <?php for($d=1;$d<=31;$d++): ?>
              <option value="<?= $d ?>" <?= ((int)$repayment_day===$d?'selected':'') ?>><?= $d ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="help">Due date រាល់ខែ</div>
      </div>

      <!-- ROW 3 -->
      <div class="col-12 col-lg-6">
        <div class="section-title"><i class="bi bi-tags"></i> ប្រភេទកម្ចី <span class="req">*</span></div>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-bookmark-star"></i></span>
          <select name="loan_type_key" id="loan_type_key" class="form-select" required>
            <option value="">-- ជ្រើសរើស --</option>
            <?php foreach ($LOAN_TYPES as $k => $label): ?>
              <option value="<?= h2($k) ?>" <?= ($loan_type_key === $k ? 'selected' : '') ?>>
                <?= h2($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mt-2" id="loan_type_other_wrap" style="display:none;">
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-pencil"></i></span>
            <input type="text" name="loan_type_other" id="loan_type_other" class="form-control"
                   placeholder="សូមបញ្ជាក់ប្រភេទកម្ចី..." value="<?= h2($loan_type_other) ?>">
          </div>
        </div>
      </div>

      <div class="col-12 col-md-6 col-lg-3">
        <div class="section-title"><i class="bi bi-activity"></i> ស្ថានភាព</div>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-flag"></i></span>
          <select name="status" class="form-select">
            <option value="active" <?= ($status==='active'?'selected':'') ?>>កំពុងដំណើរការ</option>
            <option value="overdue" <?= ($status==='overdue'?'selected':'') ?>>ហួសកំណត់</option>
            <option value="closed" <?= ($status==='closed'?'selected':'') ?>>បិទ</option>
          </select>
        </div>
      </div>

      <div class="col-12 col-md-6 col-lg-3">
        <div class="section-title"><i class="bi bi-info-circle"></i> ស្ថានភាពលម្អិត</div>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-list-check"></i></span>
          <select name="status_detail" class="form-select">
            <?php
              $khOptions = [
                'បន្តបង់ធម្មតា',
                'បង់ផ្តាច់រួច',
                'បន្តបង់ខ្លះៗ',
                'ទាក់ទងមិនបាន',
                'អត់បង់សោះ',
                'បន្តបង់តាមលទ្ធភាព',
                'កាត់ចោល'
              ];
              $status_detail = fin_normalize_status_detail_to_kh((string)$status_detail);
              foreach($khOptions as $opt):
            ?>
              <option value="<?= h2($opt) ?>" <?= ($status_detail===$opt?'selected':'') ?>><?= h2($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- ROW 4: Dates -->
      <div class="col-12 col-md-6">
        <div class="section-title"><i class="bi bi-calendar-event"></i> ថ្ងៃចាប់ផ្តើម <span class="req">*</span></div>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-calendar-plus"></i></span>
          <input type="text" name="start_date" id="start_date" class="form-control js-kh-date"
                 value="<?= h2($start_date) ?>" required placeholder="ថ្ងៃ-ខែ-ឆ្នាំ" inputmode="numeric">
        </div>
        <div class="help">ចុចក្នុងប្រអប់នេះ ដើម្បីជ្រើសថ្ងៃ (Calendar ខ្មែរ)</div>
      </div>

      <div class="col-12 col-md-6">
        <div class="section-title"><i class="bi bi-calendar2-check"></i> ថ្ងៃត្រូវបង់ដំបូង (Due) <span class="req">*</span></div>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-calendar2-week"></i></span>
          <input type="text" name="due_date" id="due_date" class="form-control js-kh-date"
                 value="<?= h2($due_date) ?>" readonly placeholder="ថ្ងៃ-ខែ-ឆ្នាំ">
        </div>
        <div class="help">Auto = Start + 1 Month + Repayment day</div>
      </div>

      <div class="col-12">
        <div class="section-title"><i class="bi bi-calendar2-check"></i> ថ្ងៃដល់កំណត់ (End) <span class="req">*</span></div>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-calendar-check"></i></span>
          <input type="text" name="end_date" id="end_date" class="form-control js-kh-date"
                 value="<?= h2($end_date) ?>" required readonly placeholder="ថ្ងៃ-ខែ-ឆ្នាំ">
        </div>
        <div class="help">Auto = Start + Months + Repayment day</div>
      </div>

      <!-- ROW 5 -->
      <div class="col-12 col-md-6">
        <div class="section-title"><i class="bi bi-bullseye"></i> ខ្ចីដើម្បីអ្វី?</div>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-chat-left-text"></i></span>
          <input type="text" name="purpose" class="form-control" value="<?= h2($purpose) ?>" placeholder="ឧ: ពង្រីកអាជីវកម្ម">
        </div>
      </div>

      <div class="col-12 col-md-6">
        <div class="section-title"><i class="bi bi-shield-lock"></i> វត្ថុធានា</div>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-safe"></i></span>
          <input type="text" name="collateral" class="form-control" value="<?= h2($collateral) ?>" placeholder="ឧ: ម៉ូតូ / ទូរស័ព្ទ / ដី">
        </div>
      </div>

      <div class="col-12">
        <div class="section-title"><i class="bi bi-journal-text"></i> សម្គាល់</div>
        <textarea name="note" class="form-control" rows="4" placeholder="សរសេរបន្ថែម..."><?= h2($note) ?></textarea>
      </div>

      <div class="col-12 d-flex gap-2 flex-wrap">
        <button class="btn btn-primary" type="submit">
          <i class="bi bi-save2 me-1"></i> រក្សាទុក
        </button>
        <a href="loan_view.php?id=<?= (int)$loan_id ?>" class="btn btn-soft">
          <i class="bi bi-x-circle me-1"></i> មិនរក្សាទុក
        </a>
      </div>

    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/km.js"></script>

<script>
(function(){
  const sel = document.getElementById('loan_type_key');
  const wrap = document.getElementById('loan_type_other_wrap');
  const other = document.getElementById('loan_type_other');

  const start = document.getElementById('start_date');
  const due   = document.getElementById('due_date');
  const end   = document.getElementById('end_date');
  const term  = document.getElementById('term_months');
  const repay = document.getElementById('repayment_day');

  function syncOther(){
    const isOther = sel && sel.value === 'other';
    if (!wrap) return;
    wrap.style.display = isOther ? 'block' : 'none';
    if (other) other.required = isOther;
  }

  function daysInMonth(y,m){ return new Date(y, m, 0).getDate(); } // m=1..12
  function addMonthsKeepDay(dateObj, monthsToAdd, day){
    const y = dateObj.getFullYear();
    const m = dateObj.getMonth(); // 0..11
    const target = new Date(y, m + monthsToAdd, 1);
    const ty = target.getFullYear();
    const tm = target.getMonth() + 1; // 1..12
    const last = daysInMonth(ty, tm);
    const dd = Math.min(Math.max(Number(day||1),1), last);
    const mmStr = String(tm).padStart(2,'0');
    const ddStr = String(dd).padStart(2,'0');
    return `${ty}-${mmStr}-${ddStr}`;
  }

  function calcAll(){
    if(!start || !due || !end || !term || !repay) return;
    if(!start.value) return;

    const d0 = new Date(start.value + 'T00:00:00');
    if (isNaN(d0.getTime())) return;

    const rday = parseInt(repay.value||'1',10);
    const months = parseInt(term.value||'0',10);

    const dVal = addMonthsKeepDay(d0, 1, rday);
    if (due._flatpickr) due._flatpickr.setDate(dVal, false);
    else due.value = dVal;

    if (months >= 1) {
      const eVal = addMonthsKeepDay(d0, months, rday);
      if (end._flatpickr) end._flatpickr.setDate(eVal, false);
      else end.value = eVal;
    }
  }

  function initKhPicker(el, isReadOnly){
    if(!el) return;
    flatpickr(el, {
      dateFormat: "Y-m-d",
      allowInput: true,
      locale: "km",
      disableMobile: true,
      clickOpens: !isReadOnly,
      altInput: true,
      altFormat: "d-F-Y"
    });
  }

  if (sel) sel.addEventListener('change', syncOther);

  if (start) start.addEventListener('change', calcAll);
  if (term) term.addEventListener('input', calcAll);
  if (repay) repay.addEventListener('change', calcAll);

  // Dynamic currency symbol update
  const curCode = document.getElementById('currency_code');
  const curSym  = document.getElementById('amount_currency_symbol');
  function updateSymbol(){
    if(!curCode || !curSym) return;
    if(curCode.value === 'KHR'){
      curSym.innerHTML = '<span style="font-weight:700; font-family:\'Battambang\',sans-serif; font-size:0.9rem;">៛</span>';
    } else {
      curSym.innerHTML = '<i class="bi bi-currency-dollar" style="font-size:0.95rem;"></i>';
    }
  }
  if(curCode) curCode.addEventListener('change', updateSymbol);

  syncOther();
  calcAll();
  updateSymbol();

  initKhPicker(start, false);
  initKhPicker(due, true);
  initKhPicker(end, true);
})();
</script>

</body>
</html>
