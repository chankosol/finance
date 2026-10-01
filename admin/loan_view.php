<?php
// /finance/admin/loan_view.php
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

fin_require_login();
fin_require_permission('view_loans');
$business_id = fin_require_business();
global $pdo;

/* ============================
   Helpers
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
function h2($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (!function_exists('fin_format_date_kh')) {
    function fin_format_date_kh($dateStr): string {
        $trimmed = trim((string)$dateStr);
        if ($trimmed === '' || $trimmed === '0000-00-00' || $trimmed === '—' || $trimmed === '-') return '—';
        $time = strtotime($trimmed);
        if (!$time) return $trimmed;

        $d = date('d', $time);
        $m = (int)date('m', $time);
        $y = date('Y', $time);

        $khMonths = [
            1 => 'មករា',
            2 => 'កុម្ភៈ',
            3 => 'មីនា',
            4 => 'មេសា',
            5 => 'ឧសភា',
            6 => 'មិថុនា',
            7 => 'កក្កដា',
            8 => 'សីហា',
            9 => 'កញ្ញា',
            10 => 'តុលា',
            11 => 'វិច្ឆិកា',
            12 => 'ធ្នូ'
        ];

        $monthKh = $khMonths[$m] ?? '';
        return "{$d}-{$monthKh}-{$y}";
    }
}
function fin_num($n): string { return number_format((float)$n, 2); }

if (!function_exists('fin_ccy_norm')) {
    function fin_ccy_norm(string $ccy): string {
        $c = strtoupper(trim($ccy));
        if (in_array($c, ['$', 'USD', 'US$'], true)) return 'USD';
        if (in_array($c, ['KHR', 'RIEL', '៛'], true)) return 'KHR';
        return $c ?: 'USD';
    }
}
if (!function_exists('fin_format_amount')) {
    function fin_format_amount($amt, $ccy): string {
        $ccy = strtoupper(trim($ccy));
        if ($ccy === 'KHR' || $ccy === '៛') {
            return number_format((float)$amt, 0) . ' ៛';
        }
        return '$' . number_format((float)$amt, 2);
    }
}

/* PHP <8 safe starts_with */
function fin_starts_with(string $haystack, string $needle): bool {
    return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
}

/* ✅ convert DB relative path (uploads/...) to correct URL from /finance/admin/ */
function fin_public_url(?string $path): string {
    $p = trim((string)$path);
    if ($p === '') return '';
    if (preg_match('#^https?://#i', $p)) return $p;
    if (fin_starts_with($p, '/')) return $p;
    return '../' . ltrim($p, '/');
}

/* ✅ pick customer photo or fallback avatar by gender */
function fin_customer_avatar_path(?string $photo, ?string $gender): string {
    $p = trim((string)$photo);
    if ($p !== '') return $p;

    $g = strtolower(trim((string)$gender));
    if (in_array($g, ['male','m','ប្រុស'], true))   return 'uploads/avatars/male.png';
    if (in_array($g, ['female','f','ស្រី'], true)) return 'uploads/avatars/female.png';
    return 'uploads/avatars/default.png';
}

/* ============================
   Detect columns
============================ */
$loansBizCol     = fin_col_exists($pdo,'loans','business_id') ? 'business_id' : 'biz_id';
$customersBizCol = fin_col_exists($pdo,'customers','business_id') ? 'business_id' : 'biz_id';
$schedBizCol     = fin_col_exists($pdo,'loan_schedules','business_id') ? 'business_id' : 'biz_id';
$payBizCol       = fin_col_exists($pdo,'loan_payments','business_id') ? 'business_id' : (fin_col_exists($pdo,'loan_payments','biz_id') ? 'biz_id' : 'business_id');

$loanHasPrincipalAmount = fin_col_exists($pdo,'loans','principal_amount');
$loanHasPrincipalOld    = fin_col_exists($pdo,'loans','principal');

$loanHasCode       = fin_col_exists($pdo,'loans','loan_code');
$loanHasStartDate  = fin_col_exists($pdo,'loans','start_date');
$loanHasDueDate    = fin_col_exists($pdo,'loans','due_date');
$loanHasEndDateOld = fin_col_exists($pdo,'loans','end_date');

$loanHasTermMonths = fin_col_exists($pdo,'loans','term_months');
$loanHasCollateral = fin_col_exists($pdo,'loans','collateral');

$loanHasMethod     = fin_col_exists($pdo,'loans','interest_method');
$loanHasTypeOld    = fin_col_exists($pdo,'loans','interest_type');
$loanHasStatus     = fin_col_exists($pdo,'loans','status');

$payHasAmount      = fin_col_exists($pdo,'loan_payments','amount');
$payHasPayDate     = fin_col_exists($pdo,'loan_payments','pay_date');
$payHasMethod      = fin_col_exists($pdo,'loan_payments','method');
$payHasNote        = fin_col_exists($pdo,'loan_payments','note');

$schedHasPaidTotal = fin_col_exists($pdo,'loan_schedules','paid_total');
$schedHasStatus    = fin_col_exists($pdo,'loan_schedules','status');

/* ✅ customer avatar columns */
$customerHasPhoto  = fin_col_exists($pdo,'customers','photo');
$customerHasGender = fin_col_exists($pdo,'customers','gender');

/* ============================
   Read loan id
============================ */
$loan_id = (int)($_GET['id'] ?? 0);
if ($loan_id <= 0) {
    header('Location: loans.php');
    exit;
}

/* ============================
   Fetch loan + customer (secure by business)
============================ */
$principalExpr = $loanHasPrincipalAmount ? "l.principal_amount" : ($loanHasPrincipalOld ? "l.principal" : "0");
$dueExpr       = $loanHasDueDate ? "l.due_date" : ($loanHasEndDateOld ? "l.end_date" : "NULL");
$startExpr     = $loanHasStartDate ? "l.start_date" : "NULL";
$codeExpr      = $loanHasCode ? "l.loan_code" : "''";
$methodExpr    = $loanHasMethod ? "l.interest_method" : ($loanHasTypeOld ? "l.interest_type" : "''");
$statusExpr    = $loanHasStatus ? "l.status" : "''";

$sqlLoan = "
    SELECT
      l.*,
      {$codeExpr} AS loan_code_show,
      {$principalExpr} AS principal_show,
      {$startExpr} AS start_show,
      {$dueExpr} AS due_show,
      {$methodExpr} AS method_show,
      {$statusExpr} AS status_show,
      c.full_name AS customer_name,
      ".($customerHasPhoto  ? "c.photo AS customer_photo," : "'' AS customer_photo,")."
      ".($customerHasGender ? "c.gender AS customer_gender" : "'' AS customer_gender")."
    FROM loans l
    JOIN customers c ON c.id = l.customer_id
    WHERE l.id = ?
      AND l.{$loansBizCol} = ?
      AND c.{$customersBizCol} = ?
    LIMIT 1
";
$st = $pdo->prepare($sqlLoan);
$st->execute([$loan_id, $business_id, $business_id]);
$loan = $st->fetch(PDO::FETCH_ASSOC);

if (!$loan) {
    http_response_code(404);
    echo "<h3 style='font-family:Battambang,sans-serif'>404</h3><p style='font-family:Battambang,sans-serif'>រកមិនឃើញឥណទាននេះទេ។</p>";
    exit;
}

/* ✅ build avatar url */
$avatarDbPath = fin_customer_avatar_path($loan['customer_photo'] ?? '', $loan['customer_gender'] ?? '');
$avatarUrl    = fin_public_url($avatarDbPath);

/* ============================
   Currency Code
============================ */
$ccyCol = fin_col_exists($pdo,'loans','currency_code') ? 'currency_code' : (fin_col_exists($pdo,'loans','currency') ? 'currency' : 'ccy');
$ccy = fin_ccy_norm($loan[$ccyCol] ?? 'USD');

/* ============================
   Schedules
============================ */
$selectSchedCols = "
  id,
  COALESCE(installment_no,0) AS installment_no,
  due_date,
  COALESCE(principal_due,0) AS principal_due,
  COALESCE(interest_due,0) AS interest_due,
  COALESCE(fee_due,0) AS fee_due,
  COALESCE(total_due,0) AS total_due,
  ".($schedHasPaidTotal ? "COALESCE(paid_total,0) AS paid_total" : "0 AS paid_total").",
  (COALESCE(total_due,0) - ".($schedHasPaidTotal ? "COALESCE(paid_total,0)" : "0").") AS remaining,
  ".($schedHasStatus ? "status" : "'' AS status")."
";

$stS = $pdo->prepare("
    SELECT {$selectSchedCols}
    FROM loan_schedules
    WHERE loan_id = ?
      AND {$schedBizCol} = ?
    ORDER BY due_date ASC, installment_no ASC, id ASC
");
$stS->execute([$loan_id, $business_id]);
$schedules = $stS->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ============================
   Payments
============================ */
$selectPayCols = "id";
if ($payHasPayDate)  $selectPayCols .= ", pay_date";
if ($payHasAmount)   $selectPayCols .= ", amount";
if ($payHasMethod)   $selectPayCols .= ", method";
if ($payHasNote)     $selectPayCols .= ", note";

$stP = $pdo->prepare("
    SELECT {$selectPayCols}
    FROM loan_payments
    WHERE loan_id = ?
      AND {$payBizCol} = ?
    ORDER BY id DESC
    LIMIT 300
");
$stP->execute([$loan_id, $business_id]);
$payments = $stP->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ============================
   Totals
============================ */
$total_due = 0.0; $total_paid = 0.0;
foreach ($schedules as $sc) {
    $p_due = (float)($sc['principal_due'] ?? 0);
    $i_due = (float)($sc['interest_due'] ?? 0);
    $f_due = (float)($sc['fee_due'] ?? 0);
    $p_paid = (float)($sc['paid_total'] ?? 0);

    $total_due  += $p_due;
    $pr_paid = max(0.0, $p_paid - $i_due - $f_due);
    $total_paid += min($p_due, $pr_paid);
}
if ($total_due <= 0.0 && isset($loan['principal_show'])) {
    $total_due = (float)$loan['principal_show'];
}
$outstanding = max(0.0, $total_due - $total_paid);
$paid_pct = ($total_due > 0) ? round(($total_paid / $total_due) * 100, 1) : 0.0;
$out_pct  = ($total_due > 0) ? round(($outstanding / $total_due) * 100, 1) : 0.0;
$paid_pct_css = max(0, min(100, (float)$paid_pct));

/* ✅ find FIRST PENDING schedule id (for green line) */
$firstPendingId = 0;
foreach ($schedules as $sc) {
    $scStatus  = strtoupper(trim((string)($sc['status'] ?? '')));
    $remaining = max(0.0, (float)($sc['remaining'] ?? 0));
    $totalRow  = (float)($sc['total_due'] ?? 0);
    $isPaid = ($scStatus === 'PAID') || ($remaining <= 0.00001 && $totalRow > 0);
    if (!$isPaid) { $firstPendingId = (int)$sc['id']; break; }
}

function fin_status_badge(string $status): array {
    $s = strtoupper(trim($status));
    if ($s === 'ACTIVE' || $s === 'active')   return ['success', 'កំពុងដំណើរការ'];
    if ($s === 'DEFAULT' || $s === 'overdue') return ['danger', 'ហួសកំណត់'];
    if ($s === 'CLOSED' || $s === 'closed')   return ['secondary', 'បិទ'];
    return ['secondary', $status ?: '—'];
}
function fin_method_label(string $m): string {
    $m2 = strtoupper(trim($m));
    if ($m2 === 'REDUCING' || $m2 === 'DECLINING') return 'ថយចុះ (Reducing)';
    if ($m2 === 'FLAT') return 'ផ្ទាត់ (Flat)';
    if ($m2 === 'FIXED') return 'ថេរ (Fixed)';
    return $m ?: '—';
}
?>
<!doctype html>
<html lang="km">
<head>
  <meta charset="utf-8">
  <title>លម្អិតឥណទាន | Finance</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://fonts.googleapis.com/css2?family=Battambang:wght@300;400;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

  <style>
    body{font-family:'Battambang',sans-serif;background:#f3f4f6;}
    .page-title{font-weight:900;letter-spacing:.2px}
    .subtext{color:#6b7280;font-size:.92rem;}
    .money{font-variant-numeric:tabular-nums;}
    .pill{
      background:#eef2ff;
      color:#3730a3;
      border-radius:999px;
      padding:.10rem .45rem;
      font-weight:700;
      font-size:.78rem;
      line-height:1.1;
      display:inline-block;
      vertical-align:middle;
    }
    .btn-icon{display:inline-flex;align-items:center;gap:.35rem;}
    @media (max-width:576px){ .hide-sm{display:none;} }

    .hero{
      background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
      border-radius:18px;
      padding:14px;
      box-shadow:0 12px 28px rgba(15,23,42,.06);
      border:1px solid rgba(15,23,42,.06);
    }
    .hero-top{
      display:flex; gap:12px; flex-wrap:wrap;
      align-items:flex-start; justify-content:space-between;
      margin-bottom:10px;
    }
    .hero-title{display:flex; gap:10px; align-items:flex-start;}

    /* ✅ avatar */
    .hero-title .avatar-ring{
      width:46px;height:46px;border-radius:14px;
      display:flex;align-items:center;justify-content:center;
      background:#eef2ff;
      border:1px solid rgba(15,23,42,.10);
      flex:0 0 auto;
      overflow:hidden;
      border-radius:100%;
    }
    .hero-title .avatar-ring img{
      width:100%;height:100%;
      object-fit:cover;
      display:block;
    }

    .hero-title h4{margin:0;font-weight:900;}
    .hero-actions{display:flex;gap:8px;flex-wrap:wrap}

    .stats-grid{
      display:grid;
      grid-template-columns: 1fr 2.1fr;
      gap:12px;
      align-items:stretch;
    }
    @media (max-width:992px){ .stats-grid{grid-template-columns: 1fr;} }

    .panel{
      background:#fff;border-radius:16px;
      padding:12px 12px;
      border:1px solid rgba(15,23,42,.06);
      box-shadow:0 10px 20px rgba(2,6,23,.04);
    }
    .panel h6{margin:0 0 8px 0;font-weight:900;}

    .donut{
      --p: 0;
      --size: 102px;
      --thick: 12px;
      width: var(--size);
      height: var(--size);
      border-radius: 50%;
      background: conic-gradient(#16a34a calc(var(--p) * 1%), #e5e7eb 0);
      position: relative;
      flex: 0 0 auto;
    }
    .donut::before{
      content:"";
      position:absolute;
      inset: calc(var(--thick));
      background:#fff;
      border-radius: 50%;
      box-shadow: inset 0 0 0 1px rgba(15,23,42,.06);
    }
    .donut .label{
      position:absolute; inset:0;
      display:flex; align-items:center; justify-content:center;
      font-weight:900; color:#0f172a;
      font-size: 1.15rem;
      z-index:2;
    }

    /* ✅ Move donut + badges a bit down */
    .progress-row{
      display:flex;
      align-items:flex-start;
      gap:12px;
      flex-wrap:nowrap;
      padding-top:8px;     /* ✅ push down */
    }
    @media (max-width:576px){
      .progress-row{flex-wrap:wrap;}
    }
    .badge-stack{
      display:flex;
      flex-direction:column;
      gap:7px;
      align-items:flex-start;
      margin-top:10px;     /* ✅ badges lower */
    }

    .mini-badge{
      display:inline-flex; align-items:center; gap:6px;
      padding:6px 8px;
      border-radius:999px;
      background:#f8fafc;
      border:1px solid rgba(15,23,42,.08);
      font-size:.78rem;
      font-weight:800;
      line-height:1;
      white-space:nowrap;
    }
    .mini-badge .dot{width:8px;height:8px;border-radius:50%;flex:0 0 auto;}
    .mini-badge.total .dot{background:#64748b;}
    .mini-badge.paid  .dot{background:#16a34a;}
    .mini-badge.out   .dot{background:#f59e0b;}
    .mini-badge .lbl{color:#334155;font-weight:800;}
    .mini-badge .val{color:#0f172a;font-weight:900;}
    .mini-badge .pct{color:#64748b;font-weight:900;font-size:.75rem;margin-left:2px;}

    .loan-grid{
      display:grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap:8px;
    }
    @media (max-width:576px){ .loan-grid{grid-template-columns: 1fr;} }

    .field{
      background:#f8fafc;
      border:1px solid rgba(15,23,42,.06);
      border-radius:12px;
      padding:8px 10px;
      min-height:60px;
    }
    .field .k{color:#64748b;font-size:.80rem;margin-bottom:2px}
    .field .v{font-weight:900;font-size:.95rem}
    .field .v small{font-weight:900;color:#16a34a}

    tr.row-paid > td{background:#eafbf0;border-top: 1px solid #e5e7eb !important;}
    tr.row-next > td{border-top: 3px solid #16a34a !important;border-bottom: 3px solid #16a34a !important;background:#ffffff;}
    .badge-paid{
      display:inline-flex; align-items:center; gap:.35rem;
      background:#dcfce7; color:#166534;
      padding:.25rem .55rem; border-radius:999px;
      font-weight:900;
      border:1px solid rgba(22,101,52,.18);
    }
    .badge-paid-off {
      background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%) !important;
      color: #14532d !important;
      border: 1px solid #86efac !important;
      font-weight: 700 !important;
      border-radius: 999px !important;
      padding: .25rem .6rem !important;
      display: inline-flex !important;
      align-items: center !important;
      gap: .3rem !important;
      font-size: .74rem !important;
      box-shadow: 0 1px 3px rgba(22, 163, 74, 0.12);
    }
    .badge-paid-off i {
      color: #16a34a !important;
    }
  </style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="container py-4">
  <?php
    [$badge, $statusLabel] = fin_status_badge((string)($loan['status_show'] ?? ''));
    $loanCode = (string)($loan['loan_code_show'] ?? '');
  ?>

  <div class="hero mb-3">
    <div class="hero-top">
      <div class="hero-title">
        <!-- ✅ replaced icon by customer photo -->
        <div class="avatar-ring">
          <img src="<?= h2($avatarUrl) ?>"
               alt="customer"
               onerror="this.onerror=null;this.src='../uploads/avatars/default.png';">
        </div>

        <div>
          <h4 class="page-title">លម្អិតឥណទាន</h4>
          <div class="subtext">
            <?php 
              $rating = fin_calculate_customer_rating($pdo, (int)$loan['customer_id']);
              $isPaidOff = (($rating['rating'] ?? '') === 'paid_off');
            ?>
            អតិថិជន៖ <span class="fw-bold"><?= h2($loan['customer_name']) ?></span><?php if ($isPaidOff): ?><i class="bi bi-patch-check-fill text-success ms-1" style="font-size: 0.95rem; vertical-align: middle;" title="អតិថិជនបានបង់ផ្តាច់កម្ចីរួចរាល់"></i><?php endif; ?>
            <span class="badge <?= $rating['badge'] ?> ms-1" style="font-size: 0.8rem;" title="<?= h2($rating['desc']) ?>"><?php if ($isPaidOff): ?><i class="bi bi-check-circle-fill me-1"></i><?php endif; ?><?= h2($rating['text']) ?></span>
            <?php if ($loanCode !== ''): ?>
              • លេខកូដ៖ <span class="pill"><?= h2($loanCode) ?></span>
            <?php endif; ?>
            • <span class="badge bg-<?= h2($badge) ?>"><?= h2($statusLabel) ?></span>
          </div>
        </div>
      </div>

      <div class="hero-actions">
        <?php 
          $statusVal = strtoupper(trim((string)($loan['status_show'] ?? '')));
          $isLoanClosed = ($statusVal === 'CLOSED' || ($outstanding <= 0.00001 && $total_due > 0));
        ?>
        <?php if (!$isLoanClosed): ?>
          <a href="loan_payment_add.php?loan_id=<?= (int)$loan_id ?>" class="btn btn-primary btn-icon"><i class="bi bi-cash-coin"></i> បង់ប្រាក់</a>
          <a href="loan_payment_add.php?loan_id=<?= (int)$loan_id ?>&settle=1" class="btn btn-success btn-icon"><i class="bi bi-check2-circle"></i> បង់ផ្តាច់</a>
        <?php else: ?>
          <a href="loan_add.php?customer_id=<?= (int)$loan['customer_id'] ?>" class="btn btn-primary btn-icon"><i class="bi bi-plus-circle"></i> ស្នើកម្ចីថ្មី</a>
          <?php if (!empty($payments)): ?>
            <a href="loan_payment_receipt.php?id=<?= (int)$payments[0]['id'] ?>" target="_blank" class="btn btn-outline-success btn-icon"><i class="bi bi-receipt"></i> វិក័យបត្របង់ផ្តាច់</a>
          <?php endif; ?>
          <span class="badge-paid-off" style="padding: 0.35rem 0.8rem; font-size: 0.85rem;"><i class="bi bi-patch-check-fill me-1"></i> បង់ផ្តាច់រួច</span>
        <?php endif; ?>
        <a href="loans.php" class="btn btn-outline-secondary btn-icon"><i class="bi bi-arrow-left"></i> បញ្ជីឥណទាន</a>
        <a href="loan_edit.php?id=<?= (int)$loan_id ?>" class="btn btn-warning btn-icon"><i class="bi bi-pencil-square"></i> កែ</a>
        <a href="loan_agreement_print.php?id=<?= (int)$loan_id ?>" target="_blank" class="btn btn-outline-primary btn-icon"><i class="bi bi-file-earmark-text"></i> កិច្ចសន្យាខ្ចីប្រាក់</a>
      </div>
    </div>

    <div class="stats-grid">
      <div class="panel">
        <h6><i class="bi bi-graph-up-arrow me-1"></i> វឌ្ឍនភាព (បានបង់ ធៀបនឹង ត្រូវបង់សរុប)</h6>

        <div class="progress-row">
          <div class="donut" style="--p: <?= $paid_pct_css ?>;">
            <div class="label"><?= h2($paid_pct) ?>%</div>
          </div>

          <div class="badge-stack">
            <div class="mini-badge total">
              <span class="dot"></span>
              <span class="lbl">ត្រូវបង់សរុប</span>
              <span class="val money"><?= fin_format_amount($total_due, $ccy) ?></span>
            </div>
            <div class="mini-badge paid">
              <span class="dot"></span>
              <span class="lbl">បានបង់</span>
              <span class="val money"><?= fin_format_amount($total_paid, $ccy) ?></span>
              <span class="pct">(<?= h2($paid_pct) ?>%)</span>
            </div>
            <div class="mini-badge out">
              <span class="dot"></span>
              <span class="lbl">នៅសល់</span>
              <span class="val money"><?= fin_format_amount($outstanding, $ccy) ?></span>
              <span class="pct">(<?= h2($out_pct) ?>%)</span>
            </div>
          </div>
        </div>
      </div>

      <div class="panel">
        <h6><i class="bi bi-info-circle me-1"></i> ព័ត៌មានឥណទាន</h6>

        <div class="loan-grid">
          <div class="field">
            <div class="k">ប្រាក់ខ្ចី (ប្រាក់ដើម)</div>
            <div class="v money"><?= fin_format_amount($loan['principal_show'] ?? 0, $ccy) ?></div>
          </div>
          <div class="field">
            <div class="k">ថ្ងៃចាប់ផ្តើម</div>
            <div class="v"><?= h2(fin_format_date_kh($loan['start_show'] ?? '')) ?></div>
          </div>
          <div class="field">
            <div class="k">ថ្ងៃដល់កំណត់</div>
            <div class="v"><?= h2(fin_format_date_kh($loan['due_show'] ?? '')) ?></div>
          </div>
          <div class="field">
            <div class="k">ការប្រាក់</div>
            <div class="v">
              <?= number_format((float)($loan['interest_rate'] ?? 0), 2) ?>%
              <small>• <?= h2(fin_method_label((string)($loan['method_show'] ?? ''))) ?></small>
            </div>
          </div>
          <div class="field">
            <div class="k">ចំនួនខែ</div>
            <div class="v">
              <?php
                $term = (int)($loan['term_months'] ?? 0);
                if ($term <= 0) $term = count($schedules) ?: 1;
                echo $term;
              ?> ខែ
            </div>
          </div>
          <div class="field">
            <div class="k">វត្ថុធានា</div>
            <div class="v"><?= h2($loanHasCollateral ? ($loan['collateral'] ?? '—') : '—') ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ===================== SCHEDULE TABLE ===================== -->
  <div class="card mb-3" style="border:0;border-radius:16px;box-shadow:0 10px 25px rgba(0,0,0,.06);">
    <div class="card-body p-3 p-lg-4">
      <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <div class="fw-bold"><i class="bi bi-calendar-check me-1"></i> កាលវិភាគបង់ប្រាក់</div>
        <div class="d-flex align-items-center gap-2">
          <div class="subtext me-2">ចំនួនដង៖ <?= count($schedules) ?></div>
          <a href="loan_schedule_print.php?id=<?= (int)$loan_id ?>" target="_blank" class="btn btn-outline-primary btn-sm px-3" style="border-radius: 10px; font-weight: 500;">
            <i class="bi bi-printer me-1"></i> បោះពុម្ភកាលវិភាគ
          </a>
        </div>
      </div>

      <!-- Desktop View Table -->
      <div class="table-responsive d-none d-md-block">
        <table class="table table-sm table-striped align-middle">
          <thead>
            <tr>
              <th style="width:56px">#</th>
              <th>ថ្ងៃដល់កំណត់</th>
              <th class="text-end hide-sm">ប្រាក់ដើម</th>
              <th class="text-end hide-sm">ការប្រាក់</th>
              <th class="text-end hide-sm">កម្រៃសេវា</th>
              <th class="text-end">សរុប</th>
              <th class="text-end">បានបង់</th>
              <th class="text-end">នៅសល់</th>
              <th>ស្ថានភាព</th>
              <th class="text-end">សកម្មភាព</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$schedules): ?>
            <tr><td colspan="10" class="text-center text-muted py-3">មិនមានកាលវិភាគ។</td></tr>
          <?php else: ?>
            <?php $i=0; foreach ($schedules as $sc): $i++; ?>
              <?php
                $scStatus  = strtoupper(trim((string)($sc['status'] ?? '')));
                $remaining = max(0,(float)($sc['remaining'] ?? 0));
                $totalRow  = (float)($sc['total_due'] ?? 0);
                $isPaid    = ($scStatus === 'PAID') || ($remaining <= 0.00001 && $totalRow > 0);

                $isNext = (!$isPaid && $firstPendingId > 0 && (int)$sc['id'] === $firstPendingId);

                $payUrl = "loan_payment_add.php?loan_id=".(int)$loan_id
                        ."&schedule_id=".(int)$sc['id']
                        ."&installment_no=".$i
                        ."&amount=".urlencode(number_format($remaining,2,'.',''))
                        ."&pay_date=".urlencode(date('Y-m-d'));
              ?>
              <tr class="<?= $isPaid ? 'row-paid' : '' ?> <?= $isNext ? 'row-next' : '' ?>">
                <td class="fw-bold"><?= $i ?></td>
                <td><?= h2(fin_format_date_kh($sc['due_date'])) ?></td>
                <td class="text-end money hide-sm"><?= fin_format_amount((float)$sc['principal_due'], $ccy) ?></td>
                <td class="text-end money hide-sm"><?= fin_format_amount((float)$sc['interest_due'], $ccy) ?></td>
                <td class="text-end money hide-sm"><?= fin_format_amount((float)$sc['fee_due'], $ccy) ?></td>
                <td class="text-end money fw-bold"><?= fin_format_amount((float)$sc['total_due'], $ccy) ?></td>
                <td class="text-end money"><?= fin_format_amount((float)$sc['paid_total'], $ccy) ?></td>
                <td class="text-end money fw-bold"><?= fin_format_amount($remaining, $ccy) ?></td>
                <td>
                  <?php
                    if ($isPaid) {
                        echo '<span class="badge-paid"><i class="bi bi-check2-square"></i> បង់រួច</span>';
                    } elseif ($scStatus === 'MISSED') {
                        echo '<span class="badge bg-danger">មិនបានបង់</span>';
                    } elseif ((float)($sc['paid_total'] ?? 0) > 0.009) {
                        echo '<span class="badge bg-warning text-dark">បង់បានខ្លះ</span>';
                    } else {
                        echo '<span class="badge bg-secondary">មិនទាន់បង់</span>';
                    }
                  ?>
                </td>
                <td class="text-end">
                  <?php if (!$isPaid && $remaining > 0.00001): ?>
                    <a class="btn btn-sm btn-success" href="<?= h2($payUrl) ?>"><i class="bi bi-cash-coin me-1"></i> បង់ប្រាក់</a>
                  <?php else: ?>
                    <button class="btn btn-sm btn-outline-secondary" disabled><i class="bi bi-check2"></i> បង់ចប់</button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Mobile View Cards -->
      <div class="d-md-none">
        <?php if (!$schedules): ?>
          <div class="text-center text-muted py-3">មិនមានកាលវិភាគ។</div>
        <?php else: ?>
          <?php $i=0; foreach ($schedules as $sc): $i++; ?>
            <?php
              $scStatus  = strtoupper(trim((string)($sc['status'] ?? '')));
              $remaining = max(0,(float)($sc['remaining'] ?? 0));
              $totalRow  = (float)($sc['total_due'] ?? 0);
              $isPaid    = ($scStatus === 'PAID') || ($remaining <= 0.00001 && $totalRow > 0);

              $isNext = (!$isPaid && $firstPendingId > 0 && (int)$sc['id'] === $firstPendingId);

              $payUrl = "loan_payment_add.php?loan_id=".(int)$loan_id
                      ."&schedule_id=".(int)$sc['id']
                      ."&installment_no=".$i
                      ."&amount=".urlencode(number_format($remaining,2,'.',''))
                      ."&pay_date=".urlencode(date('Y-m-d'));

              $cardStyle = $isNext ? 'border-color: rgba(22, 163, 74, 0.4); background: rgba(22, 163, 74, 0.03);' : '';
            ?>
            <div class="card p-3 mb-2" style="border: 1px solid rgba(15,23,42,.08); border-radius: 12px; <?= $cardStyle ?>">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="fw-bold text-primary" style="font-size: 0.95rem;">
                  លើកទី #<?= $i ?>
                </div>
                <div>
                  <?php if ($isPaid): ?>
                    <span class="badge-paid" style="font-size: 0.78rem; padding: 0.15rem 0.45rem;"><i class="bi bi-check2-square"></i> បង់រួច</span>
                  <?php elseif ($scStatus === 'MISSED'): ?>
                    <span class="badge bg-danger" style="font-size: 0.78rem; padding: 0.15rem 0.45rem;">មិនបានបង់</span>
                  <?php elseif ((float)($sc['paid_total'] ?? 0) > 0.009): ?>
                    <span class="badge bg-warning text-dark" style="font-size: 0.78rem; padding: 0.15rem 0.45rem;">បង់បានខ្លះ</span>
                  <?php else: ?>
                    <span class="badge bg-secondary" style="font-size: 0.78rem; padding: 0.15rem 0.45rem;">មិនទាន់បង់</span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="row g-2 text-start">
                <div class="col-6">
                  <div class="text-muted" style="font-size: 0.78rem;"><i class="bi bi-calendar-event me-1"></i> ថ្ងៃដល់កំណត់</div>
                  <div class="mono fw-semibold" style="font-size: 0.88rem;"><?= h2(fin_format_date_kh($sc['due_date'])) ?></div>
                </div>
                <div class="col-6 text-end">
                  <div class="text-muted" style="font-size: 0.78rem;"><i class="bi bi-cash-stack me-1"></i> ត្រូវបង់</div>
                  <div class="mono fw-bold" style="font-size: 0.88rem;"><?= fin_format_amount((float)$sc['total_due'], $ccy) ?></div>
                </div>
                <div class="col-6">
                  <div class="text-muted" style="font-size: 0.78rem;"><i class="bi bi-check2-circle me-1"></i> បានបង់</div>
                  <div class="mono" style="font-size: 0.88rem;"><?= fin_format_amount((float)$sc['paid_total'], $ccy) ?></div>
                </div>
                <div class="col-6 text-end">
                  <div class="text-muted" style="font-size: 0.78rem;"><i class="bi bi-hourglass-split me-1"></i> នៅសល់</div>
                  <div class="mono fw-bold text-danger" style="font-size: 0.88rem;"><?= fin_format_amount($remaining, $ccy) ?></div>
                </div>

                <div class="col-12 mt-2 pt-2 border-top d-flex justify-content-end">
                  <?php if (!$isPaid && $remaining > 0.00001): ?>
                    <a class="btn btn-sm btn-success btn-icon" href="<?= h2($payUrl) ?>"><i class="bi bi-cash-coin"></i> បង់ប្រាក់</a>
                  <?php else: ?>
                    <button class="btn btn-sm btn-outline-secondary btn-icon" disabled><i class="bi bi-check2"></i> បង់ចប់</button>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ===================== PAYMENT HISTORY ===================== -->
  <div class="card mb-3" style="border:0;border-radius:16px;box-shadow:0 10px 25px rgba(0,0,0,.06);">
    <div class="card-body p-3 p-lg-4">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="fw-bold"><i class="bi bi-receipt me-1"></i> ប្រវត្តិបង់ប្រាក់</div>
        <div class="subtext">ចំនួនបង់៖ <?= count($payments) ?></div>
      </div>

      <!-- Desktop View Table -->
      <div class="table-responsive d-none d-md-block">
        <table class="table table-sm table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th style="width:56px">#</th>
              <th>ថ្ងៃបង់</th>
              <th class="text-end">ចំនួន</th>
              <th class="hide-sm">វិធីបង់</th>
              <th>សម្គាល់</th>
              <th class="text-end">បង្កាន់ដៃ</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$payments): ?>
            <tr><td colspan="6" class="text-center text-muted py-3">មិនទាន់មានការបង់ប្រាក់ទេ។</td></tr>
          <?php else: ?>
            <?php $pi=0; foreach ($payments as $p): $pi++; ?>
              <?php
                $pNote = (string)($p['note'] ?? '');
                $isPayoffRow = ($isLoanClosed && $pi === 1) || (mb_strpos($pNote, 'បង់ផ្តាច់') !== false);
              ?>
              <tr class="<?= $isPayoffRow ? 'table-success bg-opacity-25' : '' ?>">
                <td class="fw-bold"><?= $pi ?></td>
                <td><?= h2(fin_format_date_kh($p['pay_date'] ?? '')) ?></td>
                <td class="text-end money fw-bold">
                  <?= fin_format_amount($p['amount'] ?? 0, $ccy) ?>
                  <?php if ($isPayoffRow): ?>
                    <span class="badge bg-success ms-1" style="font-size: 0.72rem; vertical-align: middle;"><i class="bi bi-patch-check-fill me-1"></i>បង់ផ្តាច់</span>
                  <?php endif; ?>
                </td>
                <td class="hide-sm"><?= h2($p['method'] ?? '') ?></td>
                <td><?= h2($pNote) ?></td>
                <td class="text-end">
                  <a class="btn btn-sm <?= $isPayoffRow ? 'btn-success' : 'btn-outline-primary' ?>"
                     href="loan_payment_receipt.php?id=<?= (int)($p['id'] ?? 0) ?>"
                     target="_blank" rel="noopener"><i class="bi bi-receipt me-1"></i> <?= $isPayoffRow ? 'វិក័យបត្របង់ផ្តាច់' : 'បង្កាន់ដៃ' ?></a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Mobile View Cards -->
      <div class="d-md-none">
        <?php if (!$payments): ?>
          <div class="text-center text-muted py-3">មិនទាន់មានការបង់ប្រាក់ទេ។</div>
        <?php else: ?>
          <?php $pi=0; foreach ($payments as $p): $pi++; ?>
            <?php
              $pNote = (string)($p['note'] ?? '');
              $isPayoffRow = ($isLoanClosed && $pi === 1) || (mb_strpos($pNote, 'បង់ផ្តាច់') !== false);
            ?>
            <div class="card p-3 mb-2" style="border: 1px solid rgba(15,23,42,.08); border-radius: 12px;<?= $isPayoffRow ? ' border-left: 4.5px solid #16a34a; background: #f0fdf4;' : '' ?>">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="fw-bold text-primary" style="font-size: 0.95rem;">
                  ការបង់ទី #<?= $pi ?>
                  <?php if ($isPayoffRow): ?>
                    <span class="badge bg-success ms-1" style="font-size: 0.72rem;"><i class="bi bi-patch-check-fill me-1"></i>បង់ផ្តាច់</span>
                  <?php endif; ?>
                </div>
                <div class="fw-bold text-success" style="font-size: 0.95rem;">
                  <?= fin_format_amount($p['amount'] ?? 0, $ccy) ?>
                </div>
              </div>
              <div class="row g-2 text-start">
                <div class="col-6">
                  <div class="text-muted" style="font-size: 0.78rem;"><i class="bi bi-calendar-check me-1"></i> ថ្ងៃបង់</div>
                  <div class="mono" style="font-size: 0.88rem;"><?= h2(fin_format_date_kh($p['pay_date'] ?? '')) ?></div>
                </div>
                <div class="col-6 text-end">
                  <div class="text-muted" style="font-size: 0.78rem;"><i class="bi bi-credit-card me-1"></i> វិធីបង់</div>
                  <div class="mono" style="font-size: 0.88rem;"><?= h2($p['method'] ?? '') ?></div>
                </div>
                <?php if (trim($pNote) !== ''): ?>
                  <div class="col-12">
                    <div class="text-muted" style="font-size: 0.78rem;"><i class="bi bi-chat-left-dots me-1"></i> សម្គាល់</div>
                    <div class="mono text-wrap" style="font-size: 0.88rem;"><?= h2($pNote) ?></div>
                  </div>
                <?php endif; ?>
                <div class="col-12 mt-2 pt-2 border-top d-flex justify-content-end">
                  <a class="btn btn-sm <?= $isPayoffRow ? 'btn-success text-white' : 'btn-outline-primary' ?> btn-icon"
                     href="loan_payment_receipt.php?id=<?= (int)($p['id'] ?? 0) ?>"
                     target="_blank" rel="noopener"><i class="bi bi-receipt"></i> <?= $isPayoffRow ? 'វិក័យបត្របង់ផ្តាច់' : 'បង្កាន់ដៃ' ?></a>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="d-flex gap-2 flex-wrap mt-2">
        <?php if ($outstanding > 0.00001): ?>
          <a href="loan_payment_add.php?loan_id=<?= (int)$loan_id ?>" class="btn btn-primary btn-icon"><i class="bi bi-credit-card-2-front"></i> បង់ប្រាក់</a>
        <?php endif; ?>
        <a href="loans.php" class="btn btn-outline-secondary btn-icon"><i class="bi bi-arrow-left"></i> ត្រឡប់</a>
      </div>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
