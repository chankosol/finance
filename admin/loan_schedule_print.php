<?php
// /finance/admin/loan_schedule_print.php
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

fin_require_login();
fin_require_permission('view_loans');
$business_id = fin_require_business();
global $pdo;

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

if (!function_exists('fin_format_money_with_symbol')) {
    function fin_format_money_with_symbol($amount, $currency): string {
        $amount_str = number_format((float)$amount, ($currency === 'KHR' ? 0 : 2));
        if ($currency === 'KHR') {
            return $amount_str . ' ៛';
        } else {
            return '$' . $amount_str;
        }
    }
}
if (!function_exists('fin_method_kh')) {
    function fin_method_kh($m): string {
        $v = strtoupper(trim((string)$m));
        if ($v === 'REDUCING' || $v === 'DECLINING') return 'ថយចុះ (Reducing)';
        if ($v === 'FLAT') return 'ផ្ទាត់ (Flat)';
        if ($v === 'FIXED') return 'ថេរ (Fixed)';
        return $m ?: '—';
    }
}
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

function fin_first_existing_col(PDO $pdo, string $table, array $candidates, string $fallback=''): string {
    foreach ($candidates as $c) {
        if (fin_col_exists($pdo, $table, $c)) return $c;
    }
    return $fallback;
}

$loan_id = (int)($_GET['id'] ?? 0);
if ($loan_id <= 0) {
    http_response_code(400);
    echo "<h3>400 Bad Request</h3><p>បាត់លេខសម្គាល់កម្ចី។</p>";
    exit;
}

/* Detect Columns */
$loansBizCol     = fin_col_exists($pdo,'loans','business_id') ? 'business_id' : 'biz_id';
$loanHasCode       = fin_col_exists($pdo,'loans','loan_code');
$loanHasStartDate  = fin_col_exists($pdo,'loans','start_date');
$loanHasDueDate    = fin_col_exists($pdo,'loans','due_date');
$loanHasEndDateOld = fin_col_exists($pdo,'loans','end_date');
$loanHasPrincipalAmount = fin_col_exists($pdo,'loans','principal_amount');
$loanHasPrincipalOld    = fin_col_exists($pdo,'loans','principal');
$loanHasMethod          = fin_col_exists($pdo,'loans','interest_method');
$loanHasTypeOld         = fin_col_exists($pdo,'loans','interest_type');
$loanHasStatus          = fin_col_exists($pdo,'loans','status');
$loanHasTermMonths      = fin_col_exists($pdo,'loans','term_months');
$loanHasPurpose         = fin_col_exists($pdo,'loans','purpose');
$loanHasCollateral      = fin_col_exists($pdo,'loans','collateral');
$bizNameCol   = fin_first_existing_col($pdo,'businesses',['name','business_name'],'name');

/* Query Loan & Customer & Business info */
$codeExpr      = $loanHasCode ? "l.loan_code" : "''";
$principalExpr = $loanHasPrincipalAmount ? "l.principal_amount" : ($loanHasPrincipalOld ? "l.principal" : "0");
$startExpr     = $loanHasStartDate ? "l.start_date" : "NULL";
$dueExpr       = $loanHasDueDate ? "l.due_date" : ($loanHasEndDateOld ? "l.end_date" : "NULL");
$methodExpr    = $loanHasMethod ? "l.interest_method" : ($loanHasTypeOld ? "l.interest_type" : "''");
$statusExpr    = $loanHasStatus ? "l.status" : "''";

$sql = "
    SELECT
      l.id                         AS loan_id,
      l.currency_code              AS currency_code,
      l.interest_rate              AS interest_rate,
      b.`{$bizNameCol}`            AS business_name,
      b.phone                      AS business_phone,
      b.address                    AS business_address,
      c.full_name                  AS customer_name,
      c.phone                      AS customer_phone,
      c.address                    AS customer_address,
      {$codeExpr}                  AS loan_code,
      {$principalExpr}             AS principal,
      {$startExpr}                 AS start_date,
      {$dueExpr}                   AS due_date,
      {$methodExpr}                AS interest_method,
      {$statusExpr}                AS loan_status,
      " . ($loanHasTermMonths ? "l.term_months" : "NULL") . " AS term_months,
      " . ($loanHasPurpose ? "l.purpose" : "NULL") . "      AS purpose,
      " . ($loanHasCollateral ? "l.collateral" : "NULL") . " AS collateral
    FROM loans l
    JOIN customers c  ON c.id = l.customer_id
    JOIN businesses b ON b.id = l.{$loansBizCol}
    WHERE l.id = ? AND l.{$loansBizCol} = ?
    LIMIT 1
";
$st = $pdo->prepare($sql);
$st->execute([$loan_id, $business_id]);
$loan = $st->fetch(PDO::FETCH_ASSOC);

if (!$loan) {
    http_response_code(404);
    echo "<h3>404 Not Found</h3><p>រកមិនឃើញព័ត៌មានកម្ចីនេះទេ។</p>";
    exit;
}

$ccy = $loan['currency_code'] ?? 'USD';

/* Query Payment Schedules */
$schedHasPaidTotal = fin_col_exists($pdo,'loan_schedules','paid_total');
$sqlS = "
    SELECT
      id,
      installment_no,
      due_date,
      principal_due,
      interest_due,
      fee_due,
      total_due,
      ".($schedHasPaidTotal ? "paid_total" : "0 AS paid_total").",
      status
    FROM loan_schedules
    WHERE loan_id = ?
    ORDER BY due_date ASC, installment_no ASC, id ASC
";
$stS = $pdo->prepare($sqlS);
$stS->execute([$loan_id]);
$schedules = $stS->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<!DOCTYPE html>
<html lang="km">
<head>
  <meta charset="UTF-8">
  <script>
    if (window.screen && Math.min(window.screen.width, window.screen.height) < 768) {
      document.documentElement.classList.add('mobile-zoom');
    }
  </script>
  <title>កាលវិភាគបង់ប្រាក់ - <?= h2($loan['loan_code'] ?: ('LN#'.$loan_id)) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Battambang:wght@300;400;700;900&family=Moul&family=Roboto:wght@300;400;700;900&display=swap" rel="stylesheet">
  <style>
    :root {
      --font-family: 'Battambang', sans-serif;
      --font-family-title: 'Moul', serif;
      --primary: #0f172a;
      --ink: #0f172a;
      --muted: #64748b;
      --line: rgba(15,23,42,.08);
      --bg: #fff;
    }
    body {
      font-family: var(--font-family);
      color: var(--ink);
      background: var(--bg);
      font-size: 0.9rem;
      line-height: 1.5;
      padding: 20px;
    }
    .print-header {
      border-bottom: 2px solid var(--primary);
      padding-bottom: 15px;
      margin-bottom: 25px;
    }
    .biz-name {
      font-family: var(--font-family-title);
      font-size: 1.4rem;
      color: var(--primary);
      margin-bottom: 5px;
    }
    .biz-meta {
      font-size: 0.8rem;
      color: var(--muted);
    }
    .doc-title {
      font-family: var(--font-family-title);
      font-size: 1.3rem;
      text-align: center;
      margin: 15px 0;
      color: var(--primary);
    }
    .info-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 15px;
      margin-bottom: 25px;
      background: #f8fafc;
      padding: 15px;
      border-radius: 8px;
      border: 1px solid var(--line);
    }
    .info-grid div span {
      color: var(--muted);
      font-size: 0.82rem;
    }
    .info-grid div strong {
      color: var(--ink);
    }
    .table th {
      background: #f1f5f9 !important;
      color: var(--primary);
      font-weight: 700;
      border-bottom: 1.5px solid var(--primary) !important;
      font-size: 0.85rem;
    }
    .table td {
      border-bottom: 1px solid var(--line);
      font-size: 0.85rem;
    }
    .font-mono {
      font-family: 'Roboto', sans-serif;
    }
    .signature-section {
      margin-top: 50px;
    }
    .sig-col {
      text-align: center;
      min-height: 120px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }
    .sig-title {
      font-weight: 700;
      color: var(--primary);
    }
    .sig-space {
      margin-top: 60px;
      border-bottom: 1px dotted var(--muted);
      width: 60%;
      margin-left: auto;
      margin-right: auto;
    }
    .sbadge {
      display: inline-flex;
      align-items: center;
      padding: 0.15rem 0.45rem;
      font-size: 0.72rem;
      font-weight: 700;
      border-radius: 6px;
      border: 1px solid transparent;
      line-height: 1.2;
    }
    .mobile-zoom .print-actions-bar {
      flex-direction: column !important;
      gap: 16px !important;
      text-align: center;
      padding: 16px !important;
    }
    .mobile-zoom .print-instructions {
      display: none !important;
    }
    .mobile-zoom .print-buttons-group {
      width: 100% !important;
      display: flex !important;
      gap: 15px !important;
    }
    .mobile-zoom .print-buttons-group .btn {
      flex: 1 !important;
      height: 120px !important;
      font-size: 2.5rem !important;
      font-weight: 900 !important;
      border-radius: 20px !important;
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center;
    }
    @media print {
      body {
        padding: 0;
      }
      .no-print {
        display: none !important;
      }
      .info-grid {
        background: transparent !important;
        border: 1px solid #000;
      }
      .table th {
        background: #f1f5f9 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }
    }
  </style>
</head>
<body>

  <!-- Print Actions bar -->
  <div class="d-flex justify-content-between align-items-center mb-4 no-print bg-light p-3 rounded border print-actions-bar">
    <div class="print-instructions">
      <i class="bi bi-printer me-1 text-primary"></i> <strong>របៀបបោះពុម្ភ៖</strong> អ្នកអាចបោះពុម្ភកាលវិភាគបង់ប្រាក់នេះជូនអតិថិជន។
    </div>
    <div class="d-flex gap-2 print-buttons-group">
      <button onclick="window.print()" class="btn btn-primary px-4"><i class="bi bi-printer me-1"></i> បោះពុម្ភ (Print)</button>
      <button onclick="window.close()" class="btn btn-outline-secondary">បិទ (Close)</button>
    </div>
  </div>

  <!-- Main printable document -->
  <div class="container-fluid">
    <div class="row align-items-center print-header">
      <div class="col-8">
        <div class="biz-name"><?= h2($loan['business_name']) ?></div>
        <div class="biz-meta">
          <?php if (trim($loan['business_phone'] ?? '') !== ''): ?>
            <div><i class="bi bi-telephone me-1"></i> <?= h2($loan['business_phone']) ?></div>
          <?php endif; ?>
          <?php if (trim($loan['business_address'] ?? '') !== ''): ?>
            <div><i class="bi bi-geo-alt me-1"></i> <?= h2($loan['business_address']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-4 text-end">
        <div class="fw-bold text-muted small">លេខកូដកម្ចី / Loan Code:</div>
        <div class="font-mono fw-bold fs-5 text-primary"><?= h2($loan['loan_code'] ?: ('LN#'.$loan_id)) ?></div>
      </div>
    </div>

    <div class="doc-title">កាលវិភាគបង់ប្រាក់ / PAYMENT SCHEDULE</div>

    <div class="info-grid">
      <div>
        <div><span>អតិថិជន / Customer:</span> <strong><?= h2($loan['customer_name']) ?></strong></div>
        <?php if (trim($loan['customer_phone'] ?? '') !== ''): ?>
          <div><span>លេខទូរស័ព្ទ / Phone:</span> <strong class="font-mono"><?= h2($loan['customer_phone']) ?></strong></div>
        <?php endif; ?>
        <?php if (trim($loan['customer_address'] ?? '') !== ''): ?>
          <div><span>អាសយដ្ឋាន / Address:</span> <strong><?= h2($loan['customer_address']) ?></strong></div>
        <?php endif; ?>
      </div>
      <div>
        <div><span>ប្រាក់ខ្ចី (ប្រាក់ដើម) / Principal:</span> <strong class="font-mono"><?= fin_format_money_with_symbol((float)$loan['principal'], $ccy) ?></strong></div>
        <div><span>ការប្រាក់ / Interest Rate:</span> <strong><?= number_format((float)($loan['interest_rate'] ?? 0), 2) ?>% (<?= h2(fin_method_kh((string)$loan['interest_method'])) ?>)</strong></div>
        <div><span>ថ្ងៃចាប់ផ្តើម / Start:</span> <strong class="font-mono"><?= h2(fin_format_date_kh($loan['start_date'])) ?></strong></div>
        <div><span>ថ្ងៃដល់កំណត់ / Due:</span> <strong class="font-mono"><?= h2(fin_format_date_kh($loan['due_date'])) ?></strong></div>
      </div>
    </div>

    <table class="table align-middle table-bordered">
      <thead>
        <tr>
          <th style="width: 50px;" class="text-center">#</th>
          <th style="width: 150px;" class="text-nowrap">ថ្ងៃត្រូវបង់ / Due Date</th>
          <th class="text-end">ប្រាក់ដើម / Principal</th>
          <th class="text-end">ការប្រាក់ / Interest</th>
          <th class="text-end">កម្រៃសេវា / Fee</th>
          <th class="text-end">សរុប / Total Due</th>
          <th class="text-center">ស្ថានភាព / Status</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$schedules): ?>
        <tr><td colspan="7" class="text-center py-4 text-muted">មិនមានទិន្នន័យកាលវិភាគ។</td></tr>
      <?php else: ?>
        <?php 
        $sumP = 0; $sumI = 0; $sumF = 0; $sumT = 0;
        foreach ($schedules as $sc): 
          $sumP += (float)$sc['principal_due'];
          $sumI += (float)$sc['interest_due'];
          $sumF += (float)$sc['fee_due'];
          $sumT += (float)$sc['total_due'];
          
          $stTxt = strtoupper(trim((string)($sc['status'] ?? '')));
          $badgeBg = '#f1f5f9'; $badgeTx = '#334155'; $badgeSt = 'មិនទាន់បង់';
          if ($stTxt === 'PAID') {
            $badgeBg = '#dcfce7'; $badgeTx = '#166534'; $badgeSt = 'បង់រួច';
          } elseif ($stTxt === 'MISSED') {
            $badgeBg = '#fee2e2'; $badgeTx = '#991b1b'; $badgeSt = 'មិនបានបង់';
          }
        ?>
          <tr>
            <td class="text-center font-mono"><?= (int)$sc['installment_no'] ?></td>
            <td class="font-mono text-nowrap"><?= h2(fin_format_date_kh($sc['due_date'])) ?></td>
            <td class="text-end font-mono text-nowrap"><?= fin_format_money_with_symbol((float)$sc['principal_due'], $ccy) ?></td>
            <td class="text-end font-mono text-nowrap"><?= fin_format_money_with_symbol((float)$sc['interest_due'], $ccy) ?></td>
            <td class="text-end font-mono text-nowrap"><?= fin_format_money_with_symbol((float)$sc['fee_due'], $ccy) ?></td>
            <td class="text-end font-mono fw-bold text-nowrap"><?= fin_format_money_with_symbol((float)$sc['total_due'], $ccy) ?></td>
            <td class="text-center">
              <span class="sbadge" style="background: <?= $badgeBg ?>; color: <?= $badgeTx ?>; border-color: rgba(0,0,0,0.05);"><?= $badgeSt ?></span>
            </td>
          </tr>
        <?php endforeach; ?>
        <tr class="fw-bold bg-light">
          <td colspan="2" class="text-center">សរុបរួម / TOTAL:</td>
          <td class="text-end font-mono text-nowrap"><?= fin_format_money_with_symbol($sumP, $ccy) ?></td>
          <td class="text-end font-mono text-nowrap"><?= fin_format_money_with_symbol($sumI, $ccy) ?></td>
          <td class="text-end font-mono text-nowrap"><?= fin_format_money_with_symbol($sumF, $ccy) ?></td>
          <td colspan="2" class="text-center font-mono text-primary fs-5 text-nowrap">:<?= fin_format_money_with_symbol($sumT, $ccy) ?></td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>

    <div class="row signature-section">
      <div class="col-6 sig-col">
        <div class="sig-title">ហត្ថលេខាអតិថិជន / Customer Signature</div>
        <div class="sig-space"></div>
      </div>
      <div class="col-6 sig-col">
        <div class="sig-title">ហត្ថលេខាអ្នកប្រមូល / Collector Signature</div>
        <div class="sig-space"></div>
      </div>
    </div>
  </div>

</body>
</html>
