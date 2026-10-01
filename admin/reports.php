<?php
// /finance/admin/reports.php
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

fin_require_login();
fin_require_permission('view_reports');
$business_id = fin_require_business();
global $pdo;

// Safe HTML display
if (!function_exists('h2')) {
  function h2($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* ===================== DB helpers ===================== */
if (!function_exists('fin_table_exists')) {
  function fin_table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("
      SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
  }
}

if (!function_exists('fin_col_exists')) {
  function fin_col_exists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("
      SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
  }
}

if (!function_exists('fin_first_col')) {
  function fin_first_col(PDO $pdo, string $table, array $candidates, string $fallback=''): string {
    foreach ($candidates as $c) {
      if ($c && fin_col_exists($pdo, $table, $c)) return $c;
    }
    return $fallback;
  }
}

// Columns detection
$loansBizCol     = fin_col_exists($pdo,'loans','business_id') ? 'business_id' : (fin_col_exists($pdo,'loans','biz_id') ? 'biz_id' : 'business_id');
$customersBizCol = fin_col_exists($pdo,'customers','business_id') ? 'business_id' : (fin_col_exists($pdo,'customers','biz_id') ? 'biz_id' : 'business_id');
$schedBizCol     = fin_table_exists($pdo, 'loan_schedules') ? (fin_col_exists($pdo,'loan_schedules','business_id') ? 'business_id' : (fin_col_exists($pdo,'loan_schedules','biz_id') ? 'biz_id' : 'business_id')) : '';
$payBizCol       = fin_table_exists($pdo, 'loan_payments') ? (fin_col_exists($pdo,'loan_payments','business_id') ? 'business_id' : (fin_col_exists($pdo,'loan_payments','biz_id') ? 'biz_id' : 'business_id')) : '';

$loanPrincipalCol = fin_first_col($pdo,'loans',['principal_amount','principal','amount'],'principal');
$loanCurrencyCol  = fin_first_col($pdo,'loans',['currency_code','currency','ccy'],'');
$loanTypeCol      = fin_first_col($pdo,'loans',['loan_type','type','repayment_type','product_type'],'');
$loanStatusCol    = fin_first_col($pdo,'loans',['status','loan_status'],'status');
$loanStartCol     = fin_first_col($pdo,'loans',['start_date','created_at','date_start'],'start_date');

$schedDueCol      = fin_table_exists($pdo, 'loan_schedules') ? fin_first_col($pdo,'loan_schedules',['due_date','date_due'],'due_date') : '';
$schedTotalCol    = fin_table_exists($pdo, 'loan_schedules') ? fin_first_col($pdo,'loan_schedules',['total_due','amount_due','total'],'total_due') : '';
$schedPaidCol     = fin_table_exists($pdo, 'loan_schedules') ? fin_first_col($pdo,'loan_schedules',['paid_total','paid_amount','paid'],'paid_total') : '';

$payHasAmount      = fin_table_exists($pdo, 'loan_payments') && fin_col_exists($pdo,'loan_payments','amount');
$payHasPayDate     = fin_table_exists($pdo, 'loan_payments') && fin_col_exists($pdo,'loan_payments','pay_date');
$payHasMethod      = fin_table_exists($pdo, 'loan_payments') && fin_col_exists($pdo,'loan_payments','method');
$payHasNote        = fin_table_exists($pdo, 'loan_payments') && fin_col_exists($pdo,'loan_payments','note');

// Currency normalize helper
function fin_ccy_norm(string $ccy): string {
  $c = strtoupper(trim($ccy));
  if (in_array($c, ['$', 'USD', 'US$'], true)) return 'USD';
  if (in_array($c, ['KHR', 'RIEL', '៛'], true)) return 'KHR';
  return $c ?: 'USD';
}

// Money format helper
function fin_money($n, string $ccy): string {
  $v = (float)$n;
  $sym = ($ccy === 'KHR') ? '៛' : '$';
  if (abs($v - round($v)) < 0.00001) {
    return $sym . number_format($v, 0);
  }
  return $sym . number_format($v, 2);
}

// Helper to get customer photo url
if (!function_exists('fin_cust_photo')) {
  function fin_cust_photo($path, $gender) {
    $p = trim((string)$path);
    if ($p !== '') {
      if (preg_match('#^https?://#i', $p)) return $p;
      if (str_starts_with($p, '/')) return $p;
      return '../' . ltrim($p, '/');
    }
    $g = strtolower(trim((string)$gender));
    if (in_array($g, ['male','m','ប្រុស'], true))   return '../uploads/avatars/male.png';
    if (in_array($g, ['female','f','ស្រី'], true)) return '../uploads/avatars/female.png';
    return '../uploads/avatars/default.png';
  }
}

// Read current active tab
$tab = trim((string)($_GET['tab'] ?? 'portfolio'));
if (!in_array($tab, ['portfolio', 'collections', 'overdue', 'followups'], true)) {
  $tab = 'portfolio';
}

// Read Filter values
$f_currency = strtoupper(trim((string)($_GET['currency'] ?? 'USD')));
$f_from     = trim((string)($_GET['from'] ?? date('Y-m-01')));
$f_to       = trim((string)($_GET['to'] ?? date('Y-m-d')));
$f_type     = trim((string)($_GET['loan_type'] ?? ''));

// Get all loan types for filters
$typeOptions = [];
if ($loanTypeCol) {
  $st = $pdo->prepare("
    SELECT DISTINCT `$loanTypeCol` AS type_name 
    FROM loans 
    WHERE `$loansBizCol` = ? AND `$loanTypeCol` IS NOT NULL AND `$loanTypeCol` != ''
    ORDER BY type_name ASC
  ");
  $st->execute([$business_id]);
  $typeOptions = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

// ---------------------------------------------------------
// 1. Portfolio Summary Calculations (USD and KHR)
// ---------------------------------------------------------
// Total Principal Disbursed
$disbursed_sql_all = "
  SELECT 
    UPPER(COALESCE(l.`$loanCurrencyCol`, 'USD')) AS ccy,
    COALESCE(SUM(l.`$loanPrincipalCol`), 0) AS amt
  FROM loans l
  WHERE l.`$loansBizCol` = ?
";
$disbursed_params_all = [$business_id];
if ($f_from !== '') { $disbursed_sql_all .= " AND DATE(l.`$loanStartCol`) >= ?"; $disbursed_params_all[] = $f_from; }
if ($f_to !== '') { $disbursed_sql_all .= " AND DATE(l.`$loanStartCol`) <= ?"; $disbursed_params_all[] = $f_to; }
if ($f_type !== '') { $disbursed_sql_all .= " AND l.`$loanTypeCol` = ?"; $disbursed_params_all[] = $f_type; }
$disbursed_sql_all .= " GROUP BY ccy";

$st = $pdo->prepare($disbursed_sql_all);
$st->execute($disbursed_params_all);
$disbursed_rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
$disbursed_usd = 0.0;
$disbursed_khr = 0.0;
foreach ($disbursed_rows as $r) {
  $c = fin_ccy_norm($r['ccy']);
  if ($c === 'USD') $disbursed_usd += (float)$r['amt'];
  elseif ($c === 'KHR') $disbursed_khr += (float)$r['amt'];
}

// Total Collected
$collected_sql_all = "
  SELECT 
    UPPER(COALESCE(l.`$loanCurrencyCol`, 'USD')) AS ccy,
    COALESCE(SUM(lp.amount), 0) AS amt
  FROM loan_payments lp
  JOIN loans l ON l.id = lp.loan_id
  WHERE lp.`$payBizCol` = ?
";
$collected_params_all = [$business_id];
if ($f_from !== '') { $collected_sql_all .= " AND DATE(lp.pay_date) >= ?"; $collected_params_all[] = $f_from; }
if ($f_to !== '') { $collected_sql_all .= " AND DATE(lp.pay_date) <= ?"; $collected_params_all[] = $f_to; }
if ($f_type !== '') { $collected_sql_all .= " AND l.`$loanTypeCol` = ?"; $collected_params_all[] = $f_type; }
$collected_sql_all .= " GROUP BY ccy";

$st = $pdo->prepare($collected_sql_all);
$st->execute($collected_params_all);
$collected_rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
$collected_usd = 0.0;
$collected_khr = 0.0;
foreach ($collected_rows as $r) {
  $c = fin_ccy_norm($r['ccy']);
  if ($c === 'USD') $collected_usd += (float)$r['amt'];
  elseif ($c === 'KHR') $collected_khr += (float)$r['amt'];
}

// Outstanding Portfolio (Remaining unpaid total due)
$outstanding_sql_all = "
  SELECT 
    UPPER(COALESCE(l.`$loanCurrencyCol`, 'USD')) AS ccy,
    COALESCE(SUM(ls.`$schedTotalCol` - ls.`$schedPaidCol`), 0) AS amt
  FROM loan_schedules ls
  JOIN loans l ON l.id = ls.loan_id
  WHERE ls.`$schedBizCol` = ?
    AND l.status = 'ACTIVE'
";
$outstanding_params_all = [$business_id];
if ($f_type !== '') { $outstanding_sql_all .= " AND l.`$loanTypeCol` = ?"; $outstanding_params_all[] = $f_type; }
$outstanding_sql_all .= " GROUP BY ccy";

$st = $pdo->prepare($outstanding_sql_all);
$st->execute($outstanding_params_all);
$outstanding_rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
$outstanding_usd = 0.0;
$outstanding_khr = 0.0;
foreach ($outstanding_rows as $r) {
  $c = fin_ccy_norm($r['ccy']);
  if ($c === 'USD') $outstanding_usd += (float)$r['amt'];
  elseif ($c === 'KHR') $outstanding_khr += (float)$r['amt'];
}

// Active Loans & Customers Counts
$st = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE `$loansBizCol` = ? AND status = 'ACTIVE'");
$st->execute([$business_id]);
$active_loans_count = (int)$st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE `$customersBizCol` = ? AND is_active = 1");
$st->execute([$business_id]);
$active_customers_count = (int)$st->fetchColumn();

// ---------------------------------------------------------
// 2. Fetch Charts Data (Monthly Disbursement vs Collection - Last 6 Months)
// ---------------------------------------------------------
$months = [];
for ($i = 5; $i >= 0; $i--) {
  $months[] = date('Y-m', strtotime("-$i months"));
}

$chart_disbursed = array_fill(0, 6, 0.0);
$chart_collected = array_fill(0, 6, 0.0);

foreach ($months as $idx => $m) {
  // Disbursed in month
  $disb_sql = "
    SELECT COALESCE(SUM(`$loanPrincipalCol`), 0)
    FROM loans
    WHERE `$loansBizCol` = ?
  ";
  $disb_params = [$business_id];
  if ($loanCurrencyCol !== '') {
    $disb_sql .= " AND UPPER(COALESCE(`$loanCurrencyCol`, 'USD')) = ?";
    $disb_params[] = $f_currency;
  }
  $disb_sql .= " AND DATE_FORMAT(`$loanStartCol`, '%Y-%m') = ?";
  $disb_params[] = $m;

  $st = $pdo->prepare($disb_sql);
  $st->execute($disb_params);
  $chart_disbursed[$idx] = (float)$st->fetchColumn();

  // Collected in month
  $coll_sql = "
    SELECT COALESCE(SUM(lp.amount), 0)
    FROM loan_payments lp
    JOIN loans l ON l.id = lp.loan_id
    WHERE lp.`$payBizCol` = ?
  ";
  $coll_params = [$business_id];
  if ($loanCurrencyCol !== '') {
    $coll_sql .= " AND UPPER(COALESCE(l.`$loanCurrencyCol`, 'USD')) = ?";
    $coll_params[] = $f_currency;
  }
  $coll_sql .= " AND DATE_FORMAT(lp.pay_date, '%Y-%m') = ?";
  $coll_params[] = $m;

  $st = $pdo->prepare($coll_sql);
  $st->execute($coll_params);
  $chart_collected[$idx] = (float)$st->fetchColumn();
}

// ---------------------------------------------------------
// 3. Detailed Data Querying by Tab
// ---------------------------------------------------------
$report_rows = [];

if ($tab === 'portfolio') {
  // List of active/closed loans
  $sql = "
    SELECT 
      l.id AS loan_id, l.loan_code, l.status, l.`$loanStartCol` AS start_date, l.`$loanPrincipalCol` AS principal,
      c.full_name AS customer_name, c.phone AS customer_phone, c.photo AS customer_photo, c.gender AS customer_gender
    FROM loans l
    JOIN customers c ON c.id = l.customer_id
    WHERE l.`$loansBizCol` = ?
  ";
  $params = [$business_id];
  if ($loanCurrencyCol !== '') {
    $sql .= " AND UPPER(COALESCE(l.`$loanCurrencyCol`, 'USD')) = ?";
    $params[] = $f_currency;
  }
  if ($f_from !== '') { $sql .= " AND DATE(l.`$loanStartCol`) >= ?"; $params[] = $f_from; }
  if ($f_to !== '') { $sql .= " AND DATE(l.`$loanStartCol`) <= ?"; $params[] = $f_to; }
  if ($f_type !== '') { $sql .= " AND l.`$loanTypeCol` = ?"; $params[] = $f_type; }
  $sql .= " ORDER BY l.id DESC LIMIT 150";
  
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $report_rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

} elseif ($tab === 'collections') {
  // List of recent collections
  $sql = "
    SELECT 
      lp.id AS payment_id, lp.pay_date, lp.amount, lp.method, lp.note,
      l.loan_code, c.full_name AS customer_name, c.phone AS customer_phone, c.photo AS customer_photo, c.gender AS customer_gender
    FROM loan_payments lp
    JOIN loans l ON l.id = lp.loan_id
    JOIN customers c ON c.id = l.customer_id
    WHERE lp.`$payBizCol` = ?
  ";
  $params = [$business_id];
  if ($loanCurrencyCol !== '') {
    $sql .= " AND UPPER(COALESCE(l.`$loanCurrencyCol`, 'USD')) = ?";
    $params[] = $f_currency;
  }
  if ($f_from !== '') { $sql .= " AND DATE(lp.pay_date) >= ?"; $params[] = $f_from; }
  if ($f_to !== '') { $sql .= " AND DATE(lp.pay_date) <= ?"; $params[] = $f_to; }
  if ($f_type !== '') { $sql .= " AND l.`$loanTypeCol` = ?"; $params[] = $f_type; }
  $sql .= " ORDER BY lp.pay_date DESC, lp.id DESC LIMIT 150";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $report_rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

} elseif ($tab === 'overdue') {
  // Aging analysis buckets (unpaid schedules overdue)
  $aging = [
    '1_30'  => ['label' => '1 - 30 ថ្ងៃ', 'count' => 0, 'amount' => 0.0],
    '31_60' => ['label' => '31 - 60 ថ្ងៃ', 'count' => 0, 'amount' => 0.0],
    '61_90' => ['label' => '61 - 90 ថ្ងៃ', 'count' => 0, 'amount' => 0.0],
    '90plus'=> ['label' => 'លើសពី 90 ថ្ងៃ', 'count' => 0, 'amount' => 0.0],
  ];

  $sqlAging = "
    SELECT 
      ls.id, ls.due_date, (ls.total_due - ls.paid_total) AS amount_due,
      DATEDIFF(CURRENT_DATE(), ls.due_date) AS days_overdue
    FROM loan_schedules ls
    JOIN loans l ON l.id = ls.loan_id
    WHERE ls.`$schedBizCol` = ?
      AND (ls.total_due - ls.paid_total) > 0.009
      AND ls.due_date < CURRENT_DATE()
      AND l.status = 'ACTIVE'
  ";
  $params = [$business_id];
  if ($loanCurrencyCol !== '') {
    $sqlAging .= " AND UPPER(COALESCE(l.`$loanCurrencyCol`, 'USD')) = ?";
    $params[] = $f_currency;
  }
  if ($f_type !== '') {
    $sqlAging .= " AND l.`$loanTypeCol` = ?";
    $params[] = $f_type;
  }
  $st = $pdo->prepare($sqlAging);
  $st->execute($params);
  $overdueSchedules = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($overdueSchedules as $os) {
    $days = (int)$os['days_overdue'];
    $amt = (float)$os['amount_due'];

    if ($days >= 1 && $days <= 30) {
      $aging['1_30']['count']++;
      $aging['1_30']['amount'] += $amt;
    } elseif ($days >= 31 && $days <= 60) {
      $aging['31_60']['count']++;
      $aging['31_60']['amount'] += $amt;
    } elseif ($days >= 61 && $days <= 90) {
      $aging['61_90']['count']++;
      $aging['61_90']['amount'] += $amt;
    } elseif ($days > 90) {
      $aging['90plus']['count']++;
      $aging['90plus']['amount'] += $amt;
    }
  }

  // Also query individual list of overdue clients
  $sql = "
    SELECT 
      ls.id AS schedule_id, ls.due_date, (ls.total_due - ls.paid_total) AS amount_due,
      DATEDIFF(CURRENT_DATE(), ls.due_date) AS days_overdue,
      l.loan_code, c.full_name AS customer_name, c.phone AS customer_phone, c.photo AS customer_photo, c.gender AS customer_gender
    FROM loan_schedules ls
    JOIN loans l ON l.id = ls.loan_id
    JOIN customers c ON c.id = l.customer_id
    WHERE ls.`$schedBizCol` = ?
      AND (ls.total_due - ls.paid_total) > 0.009
      AND ls.due_date < CURRENT_DATE()
      AND l.status = 'ACTIVE'
  ";
  $params = [$business_id];
  if ($loanCurrencyCol !== '') {
    $sql .= " AND UPPER(COALESCE(l.`$loanCurrencyCol`, 'USD')) = ?";
    $params[] = $f_currency;
  }
  if ($f_type !== '') { $sql .= " AND l.`$loanTypeCol` = ?"; $params[] = $f_type; }
  $sql .= " ORDER BY days_overdue DESC, ls.due_date ASC LIMIT 150";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $report_rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

} elseif ($tab === 'followups') {
  // Telegram followup logs
  $sql = "
    SELECT 
      s.id AS schedule_id, s.installment_no, s.due_date, s.last_followup_date, (s.total_due - s.paid_total) AS amount_due,
      l.loan_code, c.full_name AS customer_name, c.telegram_chat_id, c.phone AS customer_phone, c.photo AS customer_photo, c.gender AS customer_gender
    FROM loan_schedules s
    JOIN loans l ON l.id = s.loan_id
    JOIN customers c ON c.id = l.customer_id
    WHERE s.`$schedBizCol` = ?
      AND s.last_followup_date IS NOT NULL
  ";
  $params = [$business_id];
  if ($loanCurrencyCol !== '') {
    $sql .= " AND UPPER(COALESCE(l.`$loanCurrencyCol`, 'USD')) = ?";
    $params[] = $f_currency;
  }
  if ($f_from !== '') { $sql .= " AND DATE(s.last_followup_date) >= ?"; $params[] = $f_from; }
  if ($f_to !== '') { $sql .= " AND DATE(s.last_followup_date) <= ?"; $params[] = $f_to; }
  if ($f_type !== '') { $sql .= " AND l.`$loanTypeCol` = ?"; $params[] = $f_type; }
  $sql .= " ORDER BY s.last_followup_date DESC LIMIT 150";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $report_rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

?>
<!doctype html>
<html lang="km">
<head>
  <meta charset="utf-8">
  <title>របាយការណ៍ហិរញ្ញវត្ថុ | Finance</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Battambang:wght@300;400;700;900&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

  <style>
    :root{
      --bg:#f3f4f6; --card:#ffffff; --ink:#0f172a; --muted:#64748b; --line:#e5e7eb; --brand:#3b82f6;
    }
    body{font-family:'Battambang',sans-serif; background:var(--bg); color:var(--ink); font-weight: 300;}
    .page-title{font-weight:400;}
    .sub{color:var(--muted); font-size:.92rem;}
    .cardx{ background:var(--card); border:1px solid rgba(15,23,42,.08); border-radius:18px; box-shadow:none; padding:20px; }
    
    .navp{ background:var(--card); border:1px solid rgba(15,23,42,.08); border-radius:14px; padding:.4rem; }
    .navp .nav-link{ border-radius:12px; font-weight:400; color:#0f172a; }
    .navp .nav-link.active{ background:#0d6efd; color:#fff; }

    .btn{ border-radius:12px; font-weight:400; padding:.5rem .85rem; }
    .btn-soft{ border:1px solid var(--line); background:#fff; color:var(--ink); }
    .btn-soft:hover{ background:#f8fafc; }

    .form-control, .form-select { border-radius: 12px; }

    /* KPI widgets */
    .kpi-grid{
      display:grid; grid-template-columns: repeat(4, 1fr); gap:14px; margin-bottom:18px;
    }
    @media(max-width:992px){ .kpi-grid{ grid-template-columns: repeat(2, 1fr); } }
    @media(max-width:576px){ .kpi-grid{ grid-template-columns: 1fr; } }
    .kpi{
      border:1px solid var(--line); background:linear-gradient(180deg, #fff, #fbfdff);
      border-radius:16px; padding:16px; display:flex; justify-content:space-between; align-items:center;
    }
    .kpi .lbl{ color:var(--muted); font-size:.85rem; font-weight:400; }
    .kpi .val{ font-size:1.35rem; font-weight:400; margin-top:2px; font-variant-numeric:tabular-nums; }
    .kpi .ico{
      width:42px; height:42px; border-radius:12px; display:flex; align-items:center; justify-content:center;
      background:#eef2ff; color:#1d4ed8; font-size:1.3rem;
    }

    .table thead th{ font-weight:400; background:#f8fafc; border-bottom:1px solid var(--line); white-space:nowrap; }
    .table tbody td{ vertical-align:middle; }
    .mono{ font-variant-numeric: tabular-nums; }

    /* Enforce light weights globally to keep layout clean */
    .fw-bold, b, strong, th, .form-label, .btn, .page-title, .kpi .lbl, .kpi .val, .navp .nav-link {
      font-weight: 400 !important;
    }

    .rowcard{
      background:#fff;
      border:1px solid rgba(15,23,42,.08);
      border-radius:14px;
      box-shadow:0 4px 10px rgba(22,34,51,.04);
      padding:12px;
      margin:8px 0;
    }

    /* Print styling */
    @media print {
      body { background: #fff; color: #000; }
      .ez-navbar, .navp, .btn, form, .no-print { display: none !important; }
      .cardx { border: none; padding: 0; }
      .table { width: 100% !important; border: 1px solid #000; }
      .table th, .table td { border: 1px solid #000 !important; }
    }
  </style>
</head>
<body>

<?php include __DIR__ . '/../includes/navbar.php'; ?>

<div class="container py-4">

  <!-- Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3 no-print">
    <div>
      <h4 class="page-title mb-0"><i class="bi bi-file-earmark-bar-graph text-primary me-1"></i> របាយការណ៍ហិរញ្ញវត្ថុ</h4>
      <div class="sub">ពិនិត្យលម្អិតអំពីផលប័ត្រកម្ចី ការប្រមូលប្រាក់ និងទិន្នន័យហួសកំណត់</div>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-soft" onclick="window.print()"><i class="bi bi-printer me-1"></i> បោះពុម្ព (Print)</button>
      <button class="btn btn-soft btn-export" onclick="exportCSV()"><i class="bi bi-download me-1"></i> ទាញយក CSV</button>
    </div>
  </div>

  <!-- Filters form -->
  <div class="cardx p-3 mb-3 no-print">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="tab" value="<?= h2($tab) ?>">

      <div class="col-6 col-lg-3">
        <label class="form-label small fw-bold">ចាប់ពីថ្ងៃ</label>
        <input type="date" name="from" class="form-control" value="<?= h2($f_from) ?>">
      </div>

      <div class="col-6 col-lg-3">
        <label class="form-label small fw-bold">ដល់ថ្ងៃ</label>
        <input type="date" name="to" class="form-control" value="<?= h2($f_to) ?>">
      </div>
      
      <div class="col-6 col-lg-2">
        <label class="form-label small fw-bold">រូបិយប័ណ្ណ</label>
        <select name="currency" class="form-select" onchange="this.form.submit()">
          <option value="USD" <?= $f_currency === 'USD' ? 'selected' : '' ?>>USD ($)</option>
          <option value="KHR" <?= $f_currency === 'KHR' ? 'selected' : '' ?>>KHR (៛)</option>
        </select>
      </div>

      <div class="col-6 col-lg-2">
        <label class="form-label small fw-bold">ប្រភេទកម្ចី</label>
        <select name="loan_type" class="form-select">
          <option value="">ទាំងអស់</option>
          <?php foreach ($typeOptions as $opt): ?>
            <option value="<?= h2($opt) ?>" <?= $f_type === $opt ? 'selected' : '' ?>><?= h2($opt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-lg-2">
        <button class="btn btn-primary w-100" type="submit"><i class="bi bi-funnel me-1"></i> Filter</button>
      </div>
    </form>
  </div>

  <!-- KPI Widgets -->
  <div class="kpi-grid">
    <div class="kpi">
      <div>
        <div class="lbl">ទឹកប្រាក់បើកផ្តល់សរុប</div>
        <div class="val text-primary" style="font-size: 1.15rem; line-height: 1.3;">
          <div><?= fin_money($disbursed_usd, 'USD') ?></div>
          <div class="text-muted" style="font-size: 0.85rem; font-weight: normal; margin-top: 2px;"><?= fin_money($disbursed_khr, 'KHR') ?></div>
        </div>
      </div>
      <div class="ico"><i class="bi bi-cash-stack"></i></div>
    </div>
    <div class="kpi">
      <div>
        <div class="lbl">ប្រាក់ប្រមូលបានសរុប</div>
        <div class="val text-success" style="font-size: 1.15rem; line-height: 1.3;">
          <div><?= fin_money($collected_usd, 'USD') ?></div>
          <div class="text-muted" style="font-size: 0.85rem; font-weight: normal; margin-top: 2px;"><?= fin_money($collected_khr, 'KHR') ?></div>
        </div>
      </div>
      <div class="ico"><i class="bi bi-calendar-check"></i></div>
    </div>
    <div class="kpi">
      <div>
        <div class="lbl">សមតុល្យឥណទានសកម្ម</div>
        <div class="val text-warning" style="font-size: 1.15rem; line-height: 1.3;">
          <div><?= fin_money($outstanding_usd, 'USD') ?></div>
          <div class="text-muted" style="font-size: 0.85rem; font-weight: normal; margin-top: 2px;"><?= fin_money($outstanding_khr, 'KHR') ?></div>
        </div>
      </div>
      <div class="ico"><i class="bi bi-wallet2"></i></div>
    </div>
    <div class="kpi">
      <div>
        <div class="lbl">កម្ចីសកម្ម / អតិថិជនសរុប</div>
        <div class="val text-dark font-sans"><?= $active_loans_count ?> / <?= $active_customers_count ?></div>
      </div>
      <div class="ico"><i class="bi bi-people"></i></div>
    </div>
  </div>

  <!-- Tab Bar -->
  <ul class="nav nav-pills navp mb-3 no-print">
    <li class="nav-item">
      <a class="nav-link <?= $tab === 'portfolio' ? 'active' : '' ?>" href="reports.php?tab=portfolio&currency=<?= h2($f_currency) ?>&from=<?= h2($f_from) ?>&to=<?= h2($f_to) ?>&loan_type=<?= h2($f_type) ?>">
        <i class="bi bi-briefcase me-1"></i> បញ្ជីបើកផ្តល់ (Disbursed)
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $tab === 'collections' ? 'active' : '' ?>" href="reports.php?tab=collections&currency=<?= h2($f_currency) ?>&from=<?= h2($f_from) ?>&to=<?= h2($f_to) ?>&loan_type=<?= h2($f_type) ?>">
        <i class="bi bi-wallet me-1"></i> ប្រវត្តិប្រមូលប្រាក់ (Collections)
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $tab === 'overdue' ? 'active' : '' ?>" href="reports.php?tab=overdue&currency=<?= h2($f_currency) ?>&from=<?= h2($f_from) ?>&to=<?= h2($f_to) ?>&loan_type=<?= h2($f_type) ?>">
        <i class="bi bi-exclamation-octagon me-1"></i> វិភាគហួសកំណត់ (Aging Overdue)
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $tab === 'followups' ? 'active' : '' ?>" href="reports.php?tab=followups&currency=<?= h2($f_currency) ?>&from=<?= h2($f_from) ?>&to=<?= h2($f_to) ?>&loan_type=<?= h2($f_type) ?>">
        <i class="bi bi-telegram me-1"></i> ប្រវត្តិរំលឹកសង (Followups)
      </a>
    </li>
  </ul>

  <!-- Main Analytics Board -->
  <div class="row g-3">
    <!-- Chart / Visuals (Left - only for portfolio/collections) -->
    <?php if (in_array($tab, ['portfolio', 'collections'], true)): ?>
      <div class="col-12 col-lg-4 no-print">
        <div class="cardx h-100">
          <h6 class="fw-bold mb-3"><i class="bi bi-graph-up"></i> និន្នាការ ៦ ខែចុងក្រោយ (<?= h2($f_currency) ?>)</h6>
          <div style="position: relative; height: 300px; width: 100%;">
            <canvas id="trendChart"></canvas>
          </div>
        </div>
      </div>
    <?php elseif ($tab === 'overdue'): ?>
      <!-- Overdue Aging Brackets KPI block -->
      <div class="col-12 col-lg-4">
        <div class="cardx h-100">
          <h6 class="fw-bold mb-3"><i class="bi bi-hourglass-split"></i> ចំណាត់ថ្នាក់ឥណទានហួសកំណត់</h6>
          <div class="list-group list-group-flush">
            <?php foreach ($aging as $key => $val): ?>
              <div class="list-group-item d-flex justify-content-between align-items-center py-3">
                <div>
                  <div class="fw-bold"><?= h2($val['label']) ?></div>
                  <span class="badge bg-secondary-subtle text-secondary"><?= $val['count'] ?> ឥណទាន</span>
                </div>
                <div class="fw-bold text-danger fs-5"><?= fin_money($val['amount'], $f_currency) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Data Table (Right/Center) -->
    <div class="<?= ($tab === 'followups') ? 'col-12' : 'col-12 col-lg-8' ?>">
      <div class="cardx p-0 overflow-hidden">
        <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
          <h6 class="fw-bold m-0">
            <i class="bi bi-list-columns-reverse me-1"></i> 
            <?php
              if ($tab === 'portfolio') echo 'បញ្ជីបើកផ្តល់កម្ចី';
              elseif ($tab === 'collections') echo 'ប្រវត្តិប្រមូលសាច់ប្រាក់';
              elseif ($tab === 'overdue') echo 'បញ្ជីអតិថិជនហួសកំណត់ការសង';
              elseif ($tab === 'followups') echo 'ប្រវត្តិនៃការផ្ញើសាររំលឹកសងតាម Telegram';
            ?>
          </h6>
          <span class="badge bg-primary-subtle text-primary fw-bold font-sans"><?= count($report_rows) ?> records</span>
        </div>

        <div class="table-responsive d-none d-md-block">
          <table class="table table-hover mb-0" id="reportTable">
            <!-- PORTFOLIO TAB -->
            <?php if ($tab === 'portfolio'): ?>
              <thead>
                <tr>
                  <th>កូដឥណទាន</th>
                  <th>អតិថិជន</th>
                  <th>ថ្ងៃបើកផ្តល់</th>
                  <th class="text-end">ប្រាក់ដើម</th>
                  <th>ស្ថានភាព</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($report_rows)): ?>
                  <tr><td colspan="5" class="text-center py-4 text-muted">គ្មានទិន្នន័យ</td></tr>
                <?php else: ?>
                  <?php foreach ($report_rows as $row): ?>
                    <tr>
                      <td class="fw-bold text-primary"><?= h2($row['loan_code']) ?></td>
                      <td>
                        <div class="fw-bold"><?= h2($row['customer_name']) ?></div>
                        <div class="text-muted small"><?= h2($row['customer_phone']) ?></div>
                      </td>
                      <td class="mono"><?= h2($row['start_date']) ?></td>
                      <td class="text-end fw-bold mono"><?= fin_money($row['principal'], $f_currency) ?></td>
                      <td>
                        <span class="badge <?= $row['status'] === 'ACTIVE' ? 'bg-success' : 'bg-secondary' ?>"><?= h2($row['status']) ?></span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>

            <!-- COLLECTIONS TAB -->
            <?php elseif ($tab === 'collections'): ?>
              <thead>
                <tr>
                  <th>ថ្ងៃបង់ប្រាក់</th>
                  <th>អតិថិជន</th>
                  <th>លេខកូដកម្ចី</th>
                  <th class="text-end">ចំនួនប្រាក់</th>
                  <th>វិធីបង់ប្រាក់</th>
                  <th>សម្គាល់</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($report_rows)): ?>
                  <tr><td colspan="6" class="text-center py-4 text-muted">គ្មានទិន្នន័យ</td></tr>
                <?php else: ?>
                  <?php foreach ($report_rows as $row): ?>
                    <tr>
                      <td class="mono"><?= h2($row['pay_date']) ?></td>
                      <td class="fw-bold"><?= h2($row['customer_name']) ?></td>
                      <td class="fw-bold text-primary"><?= h2($row['loan_code']) ?></td>
                      <td class="text-end fw-bold text-success mono"><?= fin_money($row['amount'], $f_currency) ?></td>
                      <td><span class="badge bg-light text-dark border"><?= h2($row['method']) ?></span></td>
                      <td class="text-muted small"><?= h2($row['note']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>

            <!-- OVERDUE TAB -->
            <?php elseif ($tab === 'overdue'): ?>
              <thead>
                <tr>
                  <th>អតិថិជន</th>
                  <th>លេខកូដកម្ចី</th>
                  <th>ថ្ងៃត្រូវបង់</th>
                  <th>ចំនួនថ្ងៃហួសកំណត់</th>
                  <th class="text-end">ទឹកប្រាក់ជំពាក់</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($report_rows)): ?>
                  <tr><td colspan="5" class="text-center py-4 text-muted">គ្មានទិន្នន័យ</td></tr>
                <?php else: ?>
                  <?php foreach ($report_rows as $row): ?>
                    <tr>
                      <td>
                        <div class="fw-bold"><?= h2($row['customer_name']) ?></div>
                        <div class="text-muted small"><?= h2($row['customer_phone']) ?></div>
                      </td>
                      <td class="fw-bold text-primary"><?= h2($row['loan_code']) ?></td>
                      <td class="mono"><?= h2($row['due_date']) ?></td>
                      <td class="text-danger fw-bold mono"><?= (int)$row['days_overdue'] ?> ថ្ងៃ</td>
                      <td class="text-end fw-bold text-danger mono"><?= fin_money($row['amount_due'], $f_currency) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>

            <!-- FOLLOWUPS TAB -->
            <?php elseif ($tab === 'followups'): ?>
              <thead>
                <tr>
                  <th>ថ្ងៃផ្ញើ Followup</th>
                  <th>អតិថិជន</th>
                  <th>លេខកូដកម្ចី</th>
                  <th>ថ្ងៃកំណត់ត្រូវបង់</th>
                  <th class="text-end">ទឹកប្រាក់ត្រូវបង់</th>
                  <th>Telegram Chat ID</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($report_rows)): ?>
                  <tr><td colspan="6" class="text-center py-4 text-muted">គ្មានទិន្នន័យ</td></tr>
                <?php else: ?>
                  <?php foreach ($report_rows as $row): ?>
                    <tr>
                      <td class="mono fw-bold text-primary"><?= h2($row['last_followup_date']) ?></td>
                      <td class="fw-bold"><?= h2($row['customer_name']) ?></td>
                      <td class="fw-bold text-primary"><?= h2($row['loan_code']) ?></td>
                      <td class="mono"><?= h2($row['due_date']) ?></td>
                      <td class="text-end fw-bold text-danger mono"><?= fin_money($row['amount_due'], $f_currency) ?></td>
                      <td class="mono text-muted"><?= h2($row['telegram_chat_id']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            <?php endif; ?>
          </table>
        </div>

        <!-- Mobile view card list -->
        <div class="d-md-none no-print p-3 bg-light">
          <?php if (empty($report_rows)): ?>
            <div class="text-center py-4 text-muted">គ្មានទិន្នន័យ</div>
          <?php else: ?>
            <?php foreach ($report_rows as $row): ?>
              <?php if ($tab === 'portfolio'): ?>
                <div class="rowcard mb-3">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="d-flex align-items-center">
                      <img src="<?= fin_cust_photo($row['customer_photo'] ?? '', $row['customer_gender'] ?? '') ?>" 
                           alt="Profile" 
                           class="rounded-circle me-2" 
                           style="width: 40px; height: 40px; object-fit: cover; border: 1px solid var(--line);">
                      <div>
                        <div class="fw-bold" style="font-size:0.95rem;"><?= h2($row['customer_name']) ?></div>
                        <div class="text-muted small" style="font-size:0.8rem;"><i class="bi bi-telephone me-1"></i><?= h2($row['customer_phone']) ?></div>
                      </div>
                    </div>
                    <div class="text-end">
                      <span class="badge <?= $row['status'] === 'ACTIVE' ? 'bg-success' : 'bg-secondary' ?>"><?= h2($row['status']) ?></span>
                      <div class="text-muted mt-1 mono" style="font-size: 0.75rem;"><?= h2($row['loan_code']) ?></div>
                    </div>
                  </div>
                  <div class="d-flex justify-content-between text-muted small border-top pt-2">
                    <div><i class="bi bi-calendar3 me-1"></i><?= h2($row['start_date']) ?></div>
                    <div class="fw-bold text-dark mono"><?= fin_money($row['principal'], $f_currency) ?></div>
                  </div>
                </div>

              <?php elseif ($tab === 'collections'): ?>
                <div class="rowcard mb-3">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="d-flex align-items-center">
                      <img src="<?= fin_cust_photo($row['customer_photo'] ?? '', $row['customer_gender'] ?? '') ?>" 
                           alt="Profile" 
                           class="rounded-circle me-2" 
                           style="width: 40px; height: 40px; object-fit: cover; border: 1px solid var(--line);">
                      <div>
                        <div class="fw-bold" style="font-size:0.95rem;"><?= h2($row['customer_name']) ?></div>
                        <div class="text-muted small" style="font-size:0.8rem;"><i class="bi bi-telephone me-1"></i><?= h2($row['customer_phone']) ?></div>
                      </div>
                    </div>
                    <div class="text-end">
                      <span class="badge bg-light text-dark border"><?= h2($row['method']) ?></span>
                      <div class="text-muted mt-1 mono" style="font-size: 0.75rem;"><?= h2($row['loan_code']) ?></div>
                    </div>
                  </div>
                  <?php if (trim((string)$row['note']) !== ''): ?>
                    <div class="mb-2 text-muted small"><i class="bi bi-info-circle me-1"></i><?= h2($row['note']) ?></div>
                  <?php endif; ?>
                  <div class="d-flex justify-content-between text-muted small border-top pt-2">
                    <div><i class="bi bi-calendar3 me-1"></i><?= h2($row['pay_date']) ?></div>
                    <div class="fw-bold text-success mono"><?= fin_money($row['amount'], $f_currency) ?></div>
                  </div>
                </div>

              <?php elseif ($tab === 'overdue'): ?>
                <div class="rowcard mb-3">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="d-flex align-items-center">
                      <img src="<?= fin_cust_photo($row['customer_photo'] ?? '', $row['customer_gender'] ?? '') ?>" 
                           alt="Profile" 
                           class="rounded-circle me-2" 
                           style="width: 40px; height: 40px; object-fit: cover; border: 1px solid var(--line);">
                      <div>
                        <div class="fw-bold" style="font-size:0.95rem;"><?= h2($row['customer_name']) ?></div>
                        <div class="text-muted small" style="font-size:0.8rem;"><i class="bi bi-telephone me-1"></i><?= h2($row['customer_phone']) ?></div>
                      </div>
                    </div>
                    <div class="text-end">
                      <span class="badge bg-danger-subtle text-danger fw-bold mono"><?= (int)$row['days_overdue'] ?> ថ្ងៃ</span>
                      <div class="text-muted mt-1 mono" style="font-size: 0.75rem;"><?= h2($row['loan_code']) ?></div>
                    </div>
                  </div>
                  <div class="d-flex justify-content-between text-muted small border-top pt-2">
                    <div><i class="bi bi-calendar3 me-1"></i><?= h2($row['due_date']) ?></div>
                    <div class="fw-bold text-danger mono"><?= fin_money($row['amount_due'], $f_currency) ?></div>
                  </div>
                </div>

              <?php elseif ($tab === 'followups'): ?>
                <div class="rowcard mb-3">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="d-flex align-items-center">
                      <img src="<?= fin_cust_photo($row['customer_photo'] ?? '', $row['customer_gender'] ?? '') ?>" 
                           alt="Profile" 
                           class="rounded-circle me-2" 
                           style="width: 40px; height: 40px; object-fit: cover; border: 1px solid var(--line);">
                      <div>
                        <div class="fw-bold" style="font-size:0.95rem;"><?= h2($row['customer_name']) ?></div>
                        <div class="text-muted small" style="font-size:0.8rem;"><i class="bi bi-telephone me-1"></i><?= h2($row['customer_phone'] ?? '') ?></div>
                      </div>
                    </div>
                    <div class="text-end">
                      <span class="badge bg-info-subtle text-info-emphasis">Followup</span>
                      <div class="text-muted mt-1 mono" style="font-size: 0.75rem;"><?= h2($row['loan_code']) ?></div>
                    </div>
                  </div>
                  <div class="mb-2 text-muted small"><i class="bi bi-bell me-1"></i>Followup: <?= h2($row['last_followup_date']) ?></div>
                  <div class="d-flex justify-content-between text-muted small border-top pt-2">
                    <div><i class="bi bi-calendar3 me-1"></i>Due: <?= h2($row['due_date']) ?></div>
                    <div class="fw-bold text-danger mono"><?= fin_money($row['amount_due'], $f_currency) ?></div>
                  </div>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- Chart script for trends -->
<?php if (in_array($tab, ['portfolio', 'collections'], true)): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const ctx = document.getElementById('trendChart').getContext('2d');
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: <?= json_encode($months) ?>,
      datasets: [
        {
          label: 'បើកផ្តល់ (Disbursed)',
          data: <?= json_encode($chart_disbursed) ?>,
          backgroundColor: '#3b82f6',
          borderRadius: 6,
        },
        {
          label: 'ប្រមូលប្រាក់ (Collected)',
          data: <?= json_encode($chart_collected) ?>,
          backgroundColor: '#10b981',
          borderRadius: 6,
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {
          beginAtZero: true,
          ticks: { font: { family: 'Battambang' } }
        },
        x: {
          ticks: { font: { family: 'Battambang' } }
        }
      },
      plugins: {
        legend: { labels: { font: { family: 'Battambang', weight: 'bold' } } }
      }
    }
  });
});
</script>
<?php endif; ?>

<!-- CSV Export Functionality -->
<script>
function exportCSV() {
  const table = document.getElementById('reportTable');
  if (!table) return;

  let csvContent = "\uFEFF"; // Khmer UTF-8 BOM
  const rows = table.querySelectorAll('tr');

  rows.forEach(row => {
    const cols = row.querySelectorAll('th, td');
    const rowData = [];
    cols.forEach(col => {
      // Clean content and escape quotes
      let text = col.innerText.replace(/"/g, '""').trim();
      rowData.push('"' + text + '"');
    });
    csvContent += rowData.join(',') + "\n";
  });

  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.setAttribute("href", url);
  link.setAttribute("download", "report_<?= h2($tab) ?>_" + new Date().toISOString().slice(0,10) + ".csv");
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
