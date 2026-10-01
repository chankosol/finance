<?php
// /finance/admin/dashboard.php
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

fin_require_login();
fin_require_permission('view_dashboard');
$business_id = fin_require_business();
global $pdo;

function h2($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ===================== DB helpers ===================== */
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
function fin_first_col(PDO $pdo, string $table, array $candidates, string $fallback=''): string {
  foreach ($candidates as $c) {
    if ($c && fin_col_exists($pdo, $table, $c)) return $c;
  }
  return $fallback;
}

/* ✅ Money: hide .00 when decimals are zero */
function fin_money($n): string {
  $v = (float)$n;
  if (abs($v - round($v)) < 0.0000001) return number_format($v, 0);
  return number_format($v, 2);
}
function fin_int($n): string { return number_format((int)$n); }
function fin_pct($part, $total): float {
  $t = (float)$total;
  if ($t <= 0) return 0.0;
  return round(((float)$part / $t) * 100, 2);
}
function fin_ccy_norm(string $ccy): string {
  $c = strtoupper(trim($ccy));
  if (in_array($c, ['$', 'USD', 'US$'], true)) return 'USD';
  if (in_array($c, ['KHR', 'RIEL', '៛'], true)) return 'KHR';
  return $c ?: 'UNK';
}
/** ✅ 5-level color mapping */
function fin_pct_style(float $pct): array {
  if ($pct >= 50) return ['bar'=>'bg-success', 'badge'=>'bg-success-subtle text-success'];
  if ($pct >= 35) return ['bar'=>'bg-primary', 'badge'=>'bg-primary-subtle text-primary'];
  if ($pct >= 20) return ['bar'=>'bg-warning', 'badge'=>'bg-warning-subtle text-warning-emphasis'];
  if ($pct >= 10) return ['bar'=>'bg-info', 'badge'=>'bg-info-subtle text-info'];
  return ['bar'=>'bg-danger', 'badge'=>'bg-danger-subtle text-danger'];
}

/* ===================== Detect tables ===================== */
$hasLoans      = fin_table_exists($pdo,'loans');
$hasCustomers  = fin_table_exists($pdo,'customers');
$hasSchedules  = fin_table_exists($pdo,'loan_schedules');
$hasPayments   = fin_table_exists($pdo,'loan_payments');

if (!$hasLoans || !$hasCustomers) {
  http_response_code(500);
  echo "<h3 style='font-family:Battambang,sans-serif'>Error</h3>
        <p style='font-family:Battambang,sans-serif'>តារាង loans ឬ customers មិនមានក្នុង DB ទេ។</p>";
  exit;
}

/* ===================== Detect columns ===================== */
$loansBizCol     = fin_col_exists($pdo,'loans','business_id') ? 'business_id' : (fin_col_exists($pdo,'loans','biz_id') ? 'biz_id' : 'business_id');
$customersBizCol = fin_col_exists($pdo,'customers','business_id') ? 'business_id' : (fin_col_exists($pdo,'customers','biz_id') ? 'biz_id' : 'business_id');
$schedBizCol     = $hasSchedules ? (fin_col_exists($pdo,'loan_schedules','business_id') ? 'business_id' : (fin_col_exists($pdo,'loan_schedules','biz_id') ? 'biz_id' : 'business_id')) : '';
$payBizCol       = $hasPayments ? (fin_col_exists($pdo,'loan_payments','business_id') ? 'business_id' : (fin_col_exists($pdo,'loan_payments','biz_id') ? 'biz_id' : 'business_id')) : '';

$loanPrincipalCol = fin_first_col($pdo,'loans',['principal_amount','principal','amount'],'principal');
$loanCurrencyCol  = fin_first_col($pdo,'loans',['currency_code','currency','ccy'],'');

$loanTypeCol      = fin_first_col($pdo,'loans',['loan_type','type','repayment_type','product_type'],'');

$loanStatusCol    = fin_first_col($pdo,'loans',['status','loan_status'],'status');
$loanStatusDetailCol = fin_first_col($pdo,'loans',['status_detail','collection_status','followup_status'],'');
$statusSourceCol = $loanStatusDetailCol ?: $loanStatusCol;

$loanStartCol     = fin_first_col($pdo,'loans',['start_date','created_at','date_start'],'start_date');

$custGenderCol    = fin_first_col($pdo,'customers',['gender','sex'],'');
$custActiveCol    = fin_col_exists($pdo,'customers','is_active') ? 'is_active' : '';

$schedDueCol      = $hasSchedules ? fin_first_col($pdo,'loan_schedules',['due_date','date_due'],'due_date') : '';
$schedTotalCol    = $hasSchedules ? fin_first_col($pdo,'loan_schedules',['total_due','amount_due','total'],'total_due') : '';
$schedPaidCol     = $hasSchedules ? fin_first_col($pdo,'loan_schedules',['paid_total','paid_amount','paid'],'paid_total') : '';
$loanRepayDayCol  = fin_col_exists($pdo,'loans','repayment_day') ? 'repayment_day' : '';

/* ===================== Date ranges ===================== */
$today      = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-d', strtotime($monthStart.' +1 month')); // exclusive

$f_from   = trim((string)($_GET['from'] ?? ''));
$f_to     = trim((string)($_GET['to'] ?? ''));
$f_type   = trim((string)($_GET['loan_type'] ?? ''));
$f_status = trim((string)($_GET['status'] ?? ''));

// Dynamic dropdown filter options
$typeOptions = [];
if ($loanTypeCol) {
  $st = $pdo->prepare("SELECT DISTINCT TRIM(COALESCE($loanTypeCol,'')) AS v FROM loans WHERE $loansBizCol = ? ORDER BY v ASC");
  $st->execute([$business_id]);
  $typeOptions = array_filter($st->fetchAll(PDO::FETCH_COLUMN));
}

$statusOptions = [];
if ($statusSourceCol) {
  $st = $pdo->prepare("SELECT DISTINCT TRIM(COALESCE($statusSourceCol,'')) AS v FROM loans WHERE $loansBizCol = ? ORDER BY v ASC");
  $st->execute([$business_id]);
  $statusOptions = array_filter($st->fetchAll(PDO::FETCH_COLUMN));
}

// Build dynamic WHERE clause for loans
$where = ["$loansBizCol = ?"];
$params = [$business_id];
if ($f_from !== '' && $loanStartCol) {
  $where[] = "$loanStartCol >= ?";
  $params[] = $f_from;
}
if ($f_to !== '' && $loanStartCol) {
  $where[] = "$loanStartCol <= ?";
  $params[] = $f_to;
}
if ($f_type !== '' && $loanTypeCol) {
  $where[] = "$loanTypeCol = ?";
  $params[] = $f_type;
}
if ($f_status !== '' && $statusSourceCol) {
  $where[] = "$statusSourceCol = ?";
  $params[] = $f_status;
}
$whereSql = implode(" AND ", $where);

/* =========================================================
   ✅ Today Collection Count (Schedules due today, remaining > 0)
========================================================= */
$dueTodayCount = 0;
$dueTodaySumRemaining = 0.0;

if ($hasSchedules && $schedBizCol && $schedDueCol && $schedTotalCol && $schedPaidCol) {
  if ($loanRepayDayCol) {
    // repayment_day mode
    $dayNum = (int)date('j', strtotime($today));
    $tomorrowStart = date('Y-m-d', strtotime($today.' +1 day')) . " 00:00:00";
    $st = $pdo->prepare("
      SELECT
        COUNT(*) AS cnt,
        COALESCE(SUM(GREATEST(COALESCE(s.$schedTotalCol,0) - COALESCE(s.$schedPaidCol,0), 0)),0) AS rem_sum
      FROM loan_schedules s
      JOIN loans l ON l.id = s.loan_id
      WHERE s.$schedBizCol = ?
        AND l.$loansBizCol = ?
        AND COALESCE(l.$loanRepayDayCol,0) = ?
        AND (COALESCE(s.$schedTotalCol,0) - COALESCE(s.$schedPaidCol,0)) > 0
        AND s.$schedDueCol < ?
        " . ($loanStatusCol ? "AND l.$loanStatusCol = 'ACTIVE'" : "") . "
    ");
    $st->execute([$business_id, $business_id, $dayNum, $tomorrowStart]);
  } else {
    // schedule mode
    $st = $pdo->prepare("
      SELECT
        COUNT(*) AS cnt,
        COALESCE(SUM(GREATEST(COALESCE(s.$schedTotalCol,0) - COALESCE(s.$schedPaidCol,0), 0)),0) AS rem_sum
      FROM loan_schedules s
      JOIN loans l ON l.id = s.loan_id
      WHERE s.$schedBizCol = ?
        AND l.$loansBizCol = ?
        AND s.$schedDueCol = ?
        AND (COALESCE(s.$schedTotalCol,0) - COALESCE(s.$schedPaidCol,0)) > 0
        " . ($loanStatusCol ? "AND l.$loanStatusCol = 'ACTIVE'" : "") . "
    ");
    $st->execute([$business_id, $business_id, $today]);
  }
  $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  $dueTodayCount = (int)($r['cnt'] ?? 0);
  $dueTodaySumRemaining = (float)($r['rem_sum'] ?? 0);
}

/* =========================================================
   KPI 1: Total Loans USD / KHR + counts by currency
 ========================================================= */
$totalUSD = 0.0; $totalKHR = 0.0; $loanCount = 0;
$loanCountUSD = 0; $loanCountKHR = 0;

if ($loanCurrencyCol) {
  $st = $pdo->prepare("
    SELECT
      UPPER(TRIM(COALESCE($loanCurrencyCol,''))) AS ccy,
      COALESCE(SUM(COALESCE($loanPrincipalCol,0)),0) AS total_amount,
      COUNT(*) AS cnt
    FROM loans
    WHERE $whereSql
    GROUP BY UPPER(TRIM(COALESCE($loanCurrencyCol,'')))
  ");
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($rows as $r) {
    $ccy = fin_ccy_norm((string)$r['ccy']);
    $amt = (float)$r['total_amount'];
    $cnt = (int)$r['cnt'];

    $loanCount += $cnt;

    if ($ccy === 'USD') { $totalUSD += $amt; $loanCountUSD += $cnt; }
    else if ($ccy === 'KHR') { $totalKHR += $amt; $loanCountKHR += $cnt; }
  }
} else {
  $st = $pdo->prepare("
    SELECT COALESCE(SUM(COALESCE($loanPrincipalCol,0)),0) AS total_amount, COUNT(*) AS cnt
    FROM loans WHERE $whereSql
  ");
  $st->execute($params);
  $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  $totalUSD = (float)($r['total_amount'] ?? 0);
  $loanCount = (int)($r['cnt'] ?? 0);
  $loanCountUSD = $loanCount;
}

/* =========================================================
   KPI 2: New loans (USD + KHR separated)
 ========================================================= */
$newLoansTitle = ($f_from !== '' || $f_to !== '') ? 'កម្ចីថ្មីក្នុងគ្រានេះ' : 'កម្ចីថ្មីខែនេះ';

$newLoansCount = 0;
$newLoansUSDAmt = 0.0;
$newLoansKHRAmt = 0.0;

$whereNew = ["$loansBizCol = ?"];
$paramsNew = [$business_id];

if ($f_from !== '') {
  $whereNew[] = "$loanStartCol >= ?";
  $paramsNew[] = $f_from;
} else {
  $whereNew[] = "$loanStartCol >= ?";
  $paramsNew[] = $monthStart;
}

if ($f_to !== '') {
  $whereNew[] = "$loanStartCol <= ?";
  $paramsNew[] = $f_to;
} else {
  $whereNew[] = "$loanStartCol < ?";
  $paramsNew[] = $monthEnd;
}

if ($f_type !== '' && $loanTypeCol) {
  $whereNew[] = "$loanTypeCol = ?";
  $paramsNew[] = $f_type;
}
if ($f_status !== '' && $statusSourceCol) {
  $whereNew[] = "$statusSourceCol = ?";
  $paramsNew[] = $f_status;
}

$whereNewSql = implode(" AND ", $whereNew);

if ($loanCurrencyCol) {
  $st = $pdo->prepare("
    SELECT
      UPPER(TRIM(COALESCE($loanCurrencyCol,''))) AS ccy,
      COUNT(*) AS cnt,
      COALESCE(SUM(COALESCE($loanPrincipalCol,0)),0) AS amt
    FROM loans
    WHERE $whereNewSql
    GROUP BY UPPER(TRIM(COALESCE($loanCurrencyCol,'')))
  ");
  $st->execute($paramsNew);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($rows as $r) {
    $ccy = fin_ccy_norm((string)$r['ccy']);
    $cnt = (int)($r['cnt'] ?? 0);
    $amt = (float)($r['amt'] ?? 0);

    $newLoansCount += $cnt;
    if ($ccy === 'USD') $newLoansUSDAmt += $amt;
    else if ($ccy === 'KHR') $newLoansKHRAmt += $amt;
  }
} else {
  // fallback: no currency column -> treat as USD
  $st = $pdo->prepare("
    SELECT COUNT(*) AS cnt, COALESCE(SUM(COALESCE($loanPrincipalCol,0)),0) AS amt
    FROM loans
    WHERE $whereNewSql
  ");
  $st->execute($paramsNew);
  $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  $newLoansCount = (int)($r['cnt'] ?? 0);
  $newLoansUSDAmt = (float)($r['amt'] ?? 0);
}
/* =========================================================
   KPI 3: Customers gender
 ========================================================= */
$totalCustomers = 0; $male=0; $female=0;

$whereCust = ["c.$customersBizCol = ?"];
$paramsCust = [$business_id];
$joinCust = "";

if ($custActiveCol) {
  $whereCust[] = "COALESCE(c.$custActiveCol,1)=1";
}
if ($f_from !== '') {
  $whereCust[] = "DATE(c.created_at) >= ?";
  $paramsCust[] = $f_from;
}
if ($f_to !== '') {
  $whereCust[] = "DATE(c.created_at) <= ?";
  $paramsCust[] = $f_to;
}
if ($f_type !== '' && $loanTypeCol) {
  $joinCust = " INNER JOIN loans l ON l.customer_id = c.id ";
  $whereCust[] = "l.$loanTypeCol = ?";
  $paramsCust[] = $f_type;
}
if ($f_status !== '' && $statusSourceCol) {
  if (empty($joinCust)) {
    $joinCust = " INNER JOIN loans l ON l.customer_id = c.id ";
  }
  $whereCust[] = "l.$statusSourceCol = ?";
  $paramsCust[] = $f_status;
}

$whereCustSql = implode(" AND ", $whereCust);

if ($custGenderCol) {
  $sqlCust = "
    SELECT LOWER(TRIM(COALESCE(c.$custGenderCol,''))) AS g, COUNT(DISTINCT c.id) AS cnt
    FROM customers c
    $joinCust
    WHERE $whereCustSql
    GROUP BY LOWER(TRIM(COALESCE(c.$custGenderCol,'')))
  ";
  $st = $pdo->prepare($sqlCust);
  $st->execute($paramsCust);
  $gr = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($gr as $r) {
    $g = (string)$r['g'];
    $cnt = (int)$r['cnt'];
    $totalCustomers += $cnt;
    if (in_array($g, ['male','m','ប្រុស'], true)) $male += $cnt;
    else if (in_array($g, ['female','f','ស្រី'], true)) $female += $cnt;
  }
  if ($totalCustomers < 1) {
    $st = $pdo->prepare("SELECT COUNT(DISTINCT c.id) FROM customers c $joinCust WHERE $whereCustSql");
    $st->execute($paramsCust);
    $totalCustomers = (int)$st->fetchColumn();
  }
} else {
  $st = $pdo->prepare("SELECT COUNT(DISTINCT c.id) FROM customers c $joinCust WHERE $whereCustSql");
  $st->execute($paramsCust);
  $totalCustomers = (int)$st->fetchColumn();
}

/* =========================================================
   Loan type aggregation (Top 12)
   ✅ counts separated by currency (USD vs KHR)
========================================================= */
$typeAgg = []; // [type => ['usd_cnt'=>..,'khr_cnt'=>..,'usd_amt'=>..,'khr_amt'=>..,'cnt'=>..]]
$typeTotalCount = 0;

$typeUSD = []; // pie data: usd amount
$typeKHR = []; // pie data: khr amount

if ($loanTypeCol) {
  $currencyForType = $loanCurrencyCol ?: "''";

  $st = $pdo->prepare("
    SELECT
      TRIM(COALESCE($loanTypeCol,'(មិនបានកំណត់)')) AS t,
      UPPER(TRIM(COALESCE($currencyForType,''))) AS ccy,
      COUNT(*) AS cnt,
      COALESCE(SUM(COALESCE($loanPrincipalCol,0)),0) AS amt
    FROM loans
    WHERE $whereSql
    GROUP BY t, ccy
  ");
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($rows as $r) {
    $t   = (string)$r['t'];
    $ccy = fin_ccy_norm((string)$r['ccy']);
    $cnt = (int)$r['cnt'];
    $amt = (float)$r['amt'];

    if (!isset($typeAgg[$t])) {
      $typeAgg[$t] = [
        'usd_cnt'=>0,'khr_cnt'=>0,
        'usd_amt'=>0.0,'khr_amt'=>0.0,
        'cnt'=>0
      ];
    }

    $typeAgg[$t]['cnt'] += $cnt;
    $typeTotalCount += $cnt;

    if ($ccy === 'USD') { $typeAgg[$t]['usd_cnt'] += $cnt; $typeAgg[$t]['usd_amt'] += $amt; }
    else if ($ccy === 'KHR') { $typeAgg[$t]['khr_cnt'] += $cnt; $typeAgg[$t]['khr_amt'] += $amt; }
  }

  uasort($typeAgg, fn($a,$b)=>($b['cnt']??0)<=>($a['cnt']??0));
  $typeAgg = array_slice($typeAgg, 0, 12, true);

  foreach ($typeAgg as $t => $row) {
    $u = (float)($row['usd_amt'] ?? 0);
    $k = (float)($row['khr_amt'] ?? 0);
    if ($u > 0) $typeUSD[$t] = $u;
    if ($k > 0) $typeKHR[$t] = $k;
  }
  arsort($typeUSD);
  arsort($typeKHR);
}

/* =========================================================
   Status aggregation (Top 12)
   ✅ counts + amounts separated by currency (USD vs KHR)
========================================================= */
$statusAgg = []; // [status => ['usd_cnt'=>..,'khr_cnt'=>..,'usd_amt'=>..,'khr_amt'=>..,'cnt'=>..]]
$statusTotalCnt = 0;

if ($statusSourceCol) {
  $currencyForStatus = $loanCurrencyCol ?: "''";

  $st = $pdo->prepare("
    SELECT
      TRIM(COALESCE($statusSourceCol,'(មិនបានកំណត់)')) AS s,
      UPPER(TRIM(COALESCE($currencyForStatus,''))) AS ccy,
      COUNT(*) AS cnt,
      COALESCE(SUM(COALESCE($loanPrincipalCol,0)),0) AS amt
    FROM loans
    WHERE $whereSql
    GROUP BY s, ccy
  ");
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($rows as $r) {
    $s   = (string)$r['s'];
    $ccy = fin_ccy_norm((string)$r['ccy']);
    $cnt = (int)$r['cnt'];
    $amt = (float)$r['amt'];

    if (!isset($statusAgg[$s])) {
      $statusAgg[$s] = [
        'usd_cnt'=>0,'khr_cnt'=>0,
        'usd_amt'=>0.0,'khr_amt'=>0.0,
        'cnt'=>0
      ];
    }

    $statusAgg[$s]['cnt'] += $cnt;
    $statusTotalCnt += $cnt;

    if ($ccy === 'USD') { $statusAgg[$s]['usd_cnt'] += $cnt; $statusAgg[$s]['usd_amt'] += $amt; }
    else if ($ccy === 'KHR') { $statusAgg[$s]['khr_cnt'] += $cnt; $statusAgg[$s]['khr_amt'] += $amt; }
  }

  uasort($statusAgg, fn($a,$b)=>($b['cnt']??0)<=>($a['cnt']??0));
  $statusAgg = array_slice($statusAgg, 0, 12, true);
}

$statusRowCount = count($statusAgg);

/* derive counters (keep same as before) */
$activeCnt=0; $closedCnt=0; $overdueCnt=0;
$st = $pdo->prepare("
  SELECT LOWER(TRIM(COALESCE($loanStatusCol,''))) AS s, COUNT(*) AS cnt
  FROM loans
  WHERE $whereSql
  GROUP BY LOWER(TRIM(COALESCE($loanStatusCol,'')))
");
$st->execute($params);
$tmp = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($tmp as $r) {
  $s = (string)$r['s']; $c=(int)$r['cnt'];
  if ($s==='active' || $s==='កំពុងដំណើរការ') $activeCnt += $c;
  if ($s==='closed' || $s==='បិទ') $closedCnt += $c;
  if ($s==='overdue' || $s==='default' || $s==='ហួសកំណត់') $overdueCnt += $c;
}

/* =========================================================
   Active vs Closed Loans & Borrowers Calculations
========================================================= */
$activeLoansUSDCount = 0; $activeLoansUSDAmount = 0.0;
$closedLoansUSDCount = 0; $closedLoansUSDAmount = 0.0;
$activeLoansKHRCount = 0; $activeLoansKHRAmount = 0.0;
$closedLoansKHRCount = 0; $closedLoansKHRAmount = 0.0;

if ($loanCurrencyCol) {
  $st = $pdo->prepare("
    SELECT
      UPPER(TRIM(COALESCE($loanCurrencyCol,''))) AS ccy,
      LOWER(TRIM(COALESCE($loanStatusCol,''))) AS stat,
      COUNT(*) AS cnt,
      COALESCE(SUM(COALESCE($loanPrincipalCol,0)),0) AS total_principal
    FROM loans
    WHERE $whereSql
    GROUP BY UPPER(TRIM(COALESCE($loanCurrencyCol,''))), LOWER(TRIM(COALESCE($loanStatusCol,'')))
  ");
  $st->execute($params);
  $statusRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($statusRows as $r) {
    $ccy = fin_ccy_norm((string)$r['ccy']);
    $stat = (string)$r['stat'];
    $cnt = (int)$r['cnt'];
    $amt = (float)$r['total_principal'];
    
    $isActive = ($stat === 'active' || $stat === 'កំពុងដំណើរការ' || $stat === 'overdue' || $stat === 'default' || $stat === 'ហួសកំណត់' || $stat === '');
    
    if ($ccy === 'USD') {
      if ($isActive) {
        $activeLoansUSDCount += $cnt;
        $activeLoansUSDAmount += $amt;
      } else {
        $closedLoansUSDCount += $cnt;
        $closedLoansUSDAmount += $amt;
      }
    } else {
      if ($isActive) {
        $activeLoansKHRCount += $cnt;
        $activeLoansKHRAmount += $amt;
      } else {
        $closedLoansKHRCount += $cnt;
        $closedLoansKHRAmount += $amt;
      }
    }
  }
} else {
  $st = $pdo->prepare("
    SELECT
      LOWER(TRIM(COALESCE($loanStatusCol,''))) AS stat,
      COUNT(*) AS cnt,
      COALESCE(SUM(COALESCE($loanPrincipalCol,0)),0) AS total_principal
    FROM loans
    WHERE $whereSql
    GROUP BY LOWER(TRIM(COALESCE($loanStatusCol,'')))
  ");
  $st->execute($params);
  $statusRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($statusRows as $r) {
    $stat = (string)$r['stat'];
    $cnt = (int)$r['cnt'];
    $amt = (float)$r['total_principal'];
    
    $isActive = ($stat === 'active' || $stat === 'កំពុងដំណើរការ' || $stat === 'overdue' || $stat === 'default' || $stat === 'ហួសកំណត់' || $stat === '');
    
    if ($isActive) {
      $activeLoansUSDCount += $cnt;
      $activeLoansUSDAmount += $amt;
    } else {
      $closedLoansUSDCount += $cnt;
      $closedLoansUSDAmount += $amt;
    }
  }
}

// Customers active/closed borrower engagement status
$stActiveCusts = $pdo->prepare("
  SELECT DISTINCT customer_id
  FROM loans
  WHERE $whereSql
    AND LOWER(TRIM(COALESCE($loanStatusCol,''))) IN ('active', 'កំពុងដំណើរការ', 'overdue', 'default', 'ហួសកំណត់', '')
");
$stActiveCusts->execute($params);
$activeCustIds = $stActiveCusts->fetchAll(PDO::FETCH_COLUMN) ?: [];

$stClosedCusts = $pdo->prepare("
  SELECT DISTINCT customer_id
  FROM loans
  WHERE $whereSql
    AND LOWER(TRIM(COALESCE($loanStatusCol,''))) IN ('closed', 'បិទ')
");
$stClosedCusts->execute($params);
$closedCustIds = $stClosedCusts->fetchAll(PDO::FETCH_COLUMN) ?: [];

$activeBorrowers = count($activeCustIds);
$completedBorrowers = 0;
foreach ($closedCustIds as $cid) {
  if (!in_array($cid, $activeCustIds)) {
    $completedBorrowers++;
  }
}

/* ===================== Chart datasets ===================== */
$typeUsdLabels = array_keys($typeUSD);
$typeUsdAmounts= array_values($typeUSD);

$typeKhrLabels = array_keys($typeKHR);
$typeKhrAmounts= array_values($typeKHR);

$top12TotalUSD = 0.0;
$top12TotalKHR = 0.0;
foreach ($typeAgg as $row) {
  $top12TotalUSD += (float)($row['usd_amt'] ?? 0);
  $top12TotalKHR += (float)($row['khr_amt'] ?? 0);
}
?>
<!doctype html>
<html lang="km">
<head>
  <meta charset="utf-8">
  <title>ផ្ទាំងគ្រប់គ្រង | Finance</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://fonts.googleapis.com/css2?family=Battambang:wght@300;400;700;900&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

  <style>
    :root{
      --bg:#f3f4f6; --card:#fff; --ink:#0f172a; --muted:#64748b;
      --line:#e5e7eb;
    }
    body{font-family:'Battambang',sans-serif;background:var(--bg); color:var(--ink);}
    .page-title{font-weight:900;}
    .sub{color:var(--muted); font-size:.92rem;}

    .flatpickr-calendar, .flatpickr-calendar * { font-family:'Battambang',sans-serif !important; font-size:.92rem; }

    /* ✅ no shadow (your preference) */
    .cardx{
      background:var(--card);
      border:1px solid rgba(15,23,42,.08);
      border-radius:18px;
      box-shadow:none;
    }

    .kpi{
      border:1px solid var(--line);
      background:#fff;
      border-radius:16px; padding:14px 16px; height:100%;
      display:flex; gap:12px; align-items:flex-start; justify-content:space-between;
    }
    .kpi .left .lbl{color:var(--muted); font-size:.9rem; line-height:1.2;}
    .kpi .left .val{font-size:1.35rem; font-weight:900; margin-top:2px;}
    .kpi .left .mini{color:var(--muted); font-size:.88rem;}
    .kpi .ico{
      width:38px;height:38px;border-radius:12px;
      display:flex;align-items:center;justify-content:center;
      background:#eef2ff;color:#1d4ed8;
      flex:0 0 auto;
    }
    .kpi.kpi-usd {
      border-left: 4px solid #10b981;
      background: linear-gradient(135deg, #fff 0%, #f0fdf4 100%);
    }
    .kpi.kpi-usd .ico {
      background: #d1fae5; color: #047857;
    }
    .kpi.kpi-khr {
      border-left: 4px solid #3b82f6;
      background: linear-gradient(135deg, #fff 0%, #eff6ff 100%);
    }
    .kpi.kpi-khr .ico {
      background: #dbeafe; color: #1d4ed8;
    }
    .kpi.kpi-new {
      border-left: 4px solid #8b5cf6;
      background: linear-gradient(135deg, #fff 0%, #f5f3ff 100%);
    }
    .kpi.kpi-new .ico {
      background: #ede9fe; color: #6d28d9;
    }
    .kpi.kpi-cust {
      border-left: 4px solid #f59e0b;
      background: linear-gradient(135deg, #fff 0%, #fffbeb 100%);
    }
    .kpi.kpi-cust .ico {
      background: #fef3c7; color: #b45309;
    }
    .mono{font-variant-numeric:tabular-nums;}

    .pill{
      display:inline-flex; align-items:center; gap:.4rem;
      border:1px solid var(--line);
      background:#fff;
      padding:.38rem .65rem;
      border-radius:999px;
      font-weight:800;
      color:#0f172a;
      line-height:1.1;
      text-decoration:none;
    }
    .pill-action{ cursor:pointer; transition:.15s ease; }
    .pill-action:hover{ transform: translateY(-1px); }
    .pill-count{
      display:inline-flex; align-items:center; justify-content:center;
      min-width:22px; height:22px; padding:0 .42rem;
      border-radius:999px; background:#dc2626; color:#fff;
      font-weight:900; font-size:.78rem; line-height:22px;
    }

    .card-head{display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:.6rem;}
    .card-head .ttl{font-weight:900;}

    .canvas-wrap-sm{height:240px;}
    .scroll-box{overflow:visible; max-height:none;}
    .scroll-box.limit{max-height:520px; overflow:auto;}

    .table thead th{
      font-weight:900;
      color:#0f172a;
      border-bottom:1px solid #e5e7eb;
      white-space:nowrap;
    }

    .badge-pct{
      font-weight:400;
      border-radius:999px;
      padding:.35rem .35rem;
      border:1px solid rgba(15,23,42,.08);
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width:55px;
    }

    .progress{ height:10px; border-radius:999px; background:#eef2ff; }
    .progress-bar{ border-radius:999px; }
    .row-progress{ padding-top:.25rem !important; padding-bottom:.65rem !important; }
    .mini-ttl{ font-weight:900; color:#0f172a; margin-bottom:.35rem; text-align:center; }

    /* ===================== TYPES TABLE (responsive) ===================== */
    .types-mobile-head{
      display:none;
      padding:.45rem .85rem .55rem;
      border-bottom:1px solid rgba(15,23,42,.12);
      color:#0f172a;
    }
    .types-mobile-head .grid{
      display:grid;
      grid-template-columns: 1.5fr 1.8fr 0.7fr; /* type | amount (count) | % */
      gap:.6rem; font-weight: 900; color: #0f172a;
      align-items:end;
    }
    .types-mobile-head span{ white-space:nowrap; }

    /* Two-line block (USD top, KHR bottom) */
    .v2{
      display:flex;
      flex-direction:column;
      gap:.25rem;
      line-height:1.15;
    }
    .subhead{
      font-size:.9rem;
      font-weight:400;
      white-space:nowrap;
      color:#dc2626;
    }
    .subnum{
      font-size:.9rem;
      font-weight:400;
      white-space:nowrap;
      color:#1d4ed8;
      opacity:.90;
    }

    @media (max-width:576px){
      .canvas-wrap-sm{height:220px;}
      .scroll-box.limit{max-height:260px;}

      .types-mobile-head{ display:block; }
      .types-table thead{ display:none; }

      .types-table tbody tr.type-row{
        display:grid;
        grid-template-columns: 1.5fr 1.8fr 0.7fr;
        gap:.6rem;
        align-items:center;
        padding:.35rem .25rem;
        border-bottom:1px solid rgba(15,23,42,.10);
      }
      .types-table tbody tr.type-row td{
        border:0 !important;
        padding:0 !important;
        background:transparent !important;
        vertical-align:middle;
      }
      .types-table .td-type .type-name{
        display:block;
        font-weight:450;
        font-size:.99rem;
        white-space:nowrap;
        overflow:hidden;
        text-overflow:ellipsis;
      }
      .types-table .td-amt{ text-align:right; }
      .types-table .td-pct{
        display:flex;
        justify-content:flex-end;
        align-items:center;
      }
      .badge-pct{ min-width:50px; }

      .types-table .col-cnt-khr,
      .types-table .col-amt-khr{ display:none !important; }
    }

    /* ===================== STATUS TABLE (responsive) ===================== */
    .status-mobile-head{
      display:none;
      padding:.45rem .85rem .55rem;
      border-bottom:1px solid rgba(15,23,42,.12);
      color:#0f172a;
    }
    .status-mobile-head .grid{
      display:grid;
      grid-template-columns: 1.5fr 1.8fr 0.7fr; /* status | amount (count) | % */
      gap:.6rem;
      font-weight:900;
      align-items:end;
    }
    .status-mobile-head span{ white-space:nowrap; }

    @media (max-width:576px){
      .status-mobile-head{ display:block; }
      .status-table thead{ display:none; }

      .status-table tbody tr.status-row{
        display:grid;
        grid-template-columns: 1.5fr 1.8fr 0.7fr;
        gap:.6rem;
        align-items:center;
        padding:.35rem .25rem;
        border-bottom:1px solid rgba(15,23,42,.10);
        border-bottom: 0 !important;
      }
      .status-table tbody tr.status-row td{
        border:0 !important;
        padding:0 !important;
        background:transparent !important;
        vertical-align:middle;
      }

      .status-table .td-status .status-name{
        display:block;
        font-weight:450;
        font-size:.99rem;
        white-space:nowrap;
        overflow:hidden;
        text-overflow:ellipsis;
      }

      .status-table .td-amt{ text-align:right; }
      .status-table .td-pct{
        display:flex;
        justify-content:flex-end;
        align-items:center;
      }

      .status-table .col-cnt-khr,
      .status-table .col-amt-khr{ display:none !important; }
    }

    /* ✅ Remove ONLY the horizontal line above progress bars */
    .table tbody tr:has(+ tr .row-progress) td { border-bottom: 0 !important; }

    /* ✅ Force amount not wrap (Types + Status) */
    .types-table .td-amt, .types-table .td-amt * ,
    .status-table .td-amt, .status-table .td-amt * ,
    .types-table .col-amt-khr, .status-table .col-amt-khr{
      white-space: nowrap !important;
    }
  </style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="container py-4">

  <!-- header -->
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
      <h4 class="page-title mb-1"><i class="bi bi-bar-chart-fill me-1"></i> ផ្ទាំងគ្រប់គ្រង</h4>
      <div class="sub">សង្ខេបស្ថានភាពកម្ចី និងអតិថិជន</div>
    </div>

    <div class="d-flex gap-2 flex-wrap align-items-center">
      <a class="btn btn-outline-secondary" style="border-radius:12px;" href="customers.php">
        <i class="bi bi-people me-1"></i> បញ្ជីអតិថិជន
      </a>
      <a class="btn btn-outline-secondary" style="border-radius:12px;" href="loans.php">
        <i class="bi bi-cash-coin me-1"></i> បញ្ជីកម្ចី
      </a>
      <a class="btn btn-primary" style="border-radius:12px;" href="loan_add.php">
        <i class="bi bi-plus-lg me-1"></i> បន្ថែមកម្ចី
      </a>
      <a class="pill pill-action"
         href="payment_collection.php?quick=today&only_remaining=1"
         title="ត្រូវទូទាត់ថ្ងៃនេះ (<?= (int)$dueTodayCount ?>)">
        <i class="bi bi-calendar-check me-1"></i>
        ត្រូវទូទាត់ថ្ងៃនេះ
        <span class="pill-count"><?= (int)$dueTodayCount ?></span>
      </a>
    </div>
  </div>

  <!-- Date & Category Filters -->
  <div class="cardx p-3 mb-3">
    <form class="row g-2 align-items-end" method="get" action="">
      <div class="col-6 col-md-3 col-lg-2">
        <label class="form-label mb-1"><i class="bi bi-calendar-event me-1 text-primary"></i>ចាប់ពីថ្ងៃ</label>
        <input type="text" id="fromPicker" class="form-control bg-white text-dark" name="from" value="<?= h2($f_from) ?>" placeholder="ថ្ងៃ-ខែ-ឆ្នាំ" readonly style="cursor: pointer;">
      </div>
      <div class="col-6 col-md-3 col-lg-2">
        <label class="form-label mb-1"><i class="bi bi-calendar-check me-1 text-primary"></i>ដល់ថ្ងៃ</label>
        <input type="text" id="toPicker" class="form-control bg-white text-dark" name="to" value="<?= h2($f_to) ?>" placeholder="ថ្ងៃ-ខែ-ឆ្នាំ" readonly style="cursor: pointer;">
      </div>
      <div class="col-6 col-md-3 col-lg-2">
        <label class="form-label mb-1"><i class="bi bi-sliders me-1 text-primary"></i>ប្រភេទកម្ចី</label>
        <select name="loan_type" class="form-select" style="border-radius:12px;">
          <option value="">ទាំងអស់</option>
          <?php foreach ($typeOptions as $opt): ?>
            <option value="<?= h2($opt) ?>" <?= ($f_type===$opt ? 'selected' : '') ?>><?= h2($opt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-3 col-lg-2">
        <label class="form-label mb-1"><i class="bi bi-info-circle me-1 text-primary"></i>ស្ថានភាពកម្ចី</label>
        <select name="status" class="form-select" style="border-radius:12px;">
          <option value="">ទាំងអស់</option>
          <?php foreach ($statusOptions as $opt): ?>
            <option value="<?= h2($opt) ?>" <?= ($f_status===$opt ? 'selected' : '') ?>><?= h2($opt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-lg-4 d-flex gap-2">
        <button type="submit" class="btn btn-primary px-4 w-100" style="border-radius:12px;">
          <i class="bi bi-funnel-fill me-1"></i> តម្រង
        </button>
        <a class="btn btn-outline-secondary px-4 w-100" style="border-radius:12px;" href="dashboard.php">
          <i class="bi bi-arrow-counterclockwise me-1"></i> សំអាត
        </a>
      </div>
    </form>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-3">
    <div class="col-12 col-md-6 col-lg-3">
      <div class="kpi kpi-usd">
        <div class="left">
          <div class="lbl"><i class="bi bi-wallet2 me-1"></i> សរុបកម្ចី (ដុល្លារ)</div>
          <div class="val mono">$&nbsp;<?= fin_money($totalUSD) ?></div>
          <div class="mini">សរុបកម្ចី: <?= fin_int($loanCountUSD) ?></div>
        </div>
        <div class="ico"><i class="bi bi-currency-dollar"></i></div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-lg-3">
      <div class="kpi kpi-khr">
        <div class="left">
          <div class="lbl"><i class="bi bi-wallet2 me-1"></i> សរុបកម្ចី (រៀល)</div>
          <div class="val mono">៛&nbsp;<?= fin_money($totalKHR) ?></div>
          <div class="mini">សរុបកម្ចី: <?= fin_int($loanCountKHR) ?></div>
        </div>
        <div class="ico"><i class="bi bi-cash"></i></div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-lg-3">
      <div class="kpi kpi-new">
        <div class="left">
          <div class="lbl"><i class="bi bi-graph-up-arrow me-1"></i> <?= h2($newLoansTitle) ?></div>
          <div class="val mono"><?= fin_int($newLoansCount) ?> កម្ចី</div>
          <div class="mini">
            សរុប:
            <span class="mono fw-bold">$&nbsp;<?= fin_money($newLoansUSDAmt) ?></span>
            &nbsp;•&nbsp;
            <span class="mono fw-bold">៛&nbsp;<?= fin_money($newLoansKHRAmt) ?></span>
          </div>
        </div>
        <div class="ico"><i class="bi bi-plus-square"></i></div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-lg-3">
      <div class="kpi kpi-cust">
        <div class="left">
          <div class="lbl"><i class="bi bi-person-badge me-1"></i> អតិថិជនសរុប</div>
          <div class="val mono"><?= fin_int($totalCustomers) ?> នាក់</div>
          <div class="mini">
            <?php if ($custGenderCol): ?>
              ប្រុស <?= fin_int($male) ?> • ស្រី <?= fin_int($female) ?>
            <?php else: ?>
              (gender column មិនមាន)
            <?php endif; ?>
          </div>
        </div>
        <div class="ico"><i class="bi bi-people"></i></div>
      </div>
    </div>
  </div>

  <!-- Active vs Closed Loans Summary -->
  <?php
    $totalLoansCount = $activeLoansUSDCount + $activeLoansKHRCount + $closedLoansUSDCount + $closedLoansKHRCount;
    $activeLoansPct = $totalLoansCount > 0 ? ($activeLoansUSDCount + $activeLoansKHRCount) / $totalLoansCount * 100 : 0;
    $closedLoansPct = $totalLoansCount > 0 ? ($closedLoansUSDCount + $closedLoansKHRCount) / $totalLoansCount * 100 : 0;

    // Calculate currency-specific percentages
    $sumUSD = $activeLoansUSDAmount + $closedLoansUSDAmount;
    $sumKHR = $activeLoansKHRAmount + $closedLoansKHRAmount;
    $activeLoansPctUSD = $sumUSD > 0 ? ($activeLoansUSDAmount / $sumUSD * 100) : 0;
    $closedLoansPctUSD = $sumUSD > 0 ? ($closedLoansUSDAmount / $sumUSD * 100) : 0;
    $activeLoansPctKHR = $sumKHR > 0 ? ($activeLoansKHRAmount / $sumKHR * 100) : 0;
    $closedLoansPctKHR = $sumKHR > 0 ? ($closedLoansKHRAmount / $sumKHR * 100) : 0;
  ?>
  <div class="row g-3 mb-3">
    <!-- Active Loans Block -->
    <div class="col-12 col-lg-6">
      <div class="cardx p-3 p-lg-4 h-100" style="background: linear-gradient(135deg, #fff 0%, #f0fdf4 100%); border-left: 5px solid #16a34a;">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div>
            <span class="badge bg-success-subtle text-success px-2.5 py-1.5" style="font-size: 0.85rem; border-radius: 6px; font-weight: 700;">
              <i class="bi bi-activity"></i> កម្ចីកំពុងដំណើរការ (Active Loans)
            </span>
          </div>
          <div class="d-flex align-items-center gap-2">
            <span class="badge bg-success text-white px-2.5 py-1.5 fs-6 fw-bold" style="border-radius: 8px;">
              <?= number_format($activeLoansPct, 1) ?>%
            </span>
            <div class="rounded-3 d-flex align-items-center justify-content-center" 
                 style="width: 32px; height: 32px; background: #dcfce7; color: #15803d;">
              <i class="bi bi-wallet2 fs-6"></i>
            </div>
          </div>
        </div>

        <div class="my-3">
          <div class="d-flex align-items-baseline gap-2">
            <span class="mono" style="font-size: 1.35rem; font-weight: 900; line-height: 1.1; color: #16a34a;">
              $<?= fin_money($activeLoansUSDAmount) ?>
            </span>
            <span style="font-size: 0.8rem; font-weight: 700; color: #15803d; opacity: 0.8;">(<?= number_format($activeLoansPctUSD, 1) ?>%)</span>
          </div>
          <div class="d-flex align-items-baseline gap-2" style="margin-top: 6px;">
            <span class="mono" style="font-size: 1.35rem; font-weight: 900; line-height: 1.1; color: #16a34a;">
              ៛<?= fin_money($activeLoansKHRAmount) ?>
            </span>
            <span style="font-size: 0.8rem; font-weight: 700; color: #15803d; opacity: 0.8;">(<?= number_format($activeLoansPctKHR, 1) ?>%)</span>
          </div>
        </div>

        <hr style="opacity: 0.08; margin: 12px 0;">

        <div class="d-flex justify-content-between align-items-center" style="font-size: 0.92rem; font-weight: 600; color: #15803d;">
          <div><i class="bi bi-hash"></i> ចំនួនកម្ចីសរុប: <span class="mono" style="font-size: 1.15rem; font-weight: 800; color: #16a34a;"><?= fin_int($activeLoansUSDCount + $activeLoansKHRCount) ?></span> កម្ចី</div>
          <div><i class="bi bi-people-fill"></i> អតិថិជនសកម្ម: <span class="mono" style="font-size: 1.15rem; font-weight: 800; color: #16a34a;"><?= fin_int($activeBorrowers) ?></span> នាក់</div>
        </div>
      </div>
    </div>

    <!-- Closed Loans Block -->
    <div class="col-12 col-lg-6">
      <div class="cardx p-3 p-lg-4 h-100" style="background: linear-gradient(135deg, #fff 0%, #eff6ff 100%); border-left: 5px solid #2563eb;">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div>
            <span class="badge bg-primary-subtle text-primary px-2.5 py-1.5" style="font-size: 0.85rem; border-radius: 6px; font-weight: 700;">
              <i class="bi bi-check2-circle"></i> កម្ចីបានទូទាត់រួច (Closed Loans)
            </span>
          </div>
          <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary text-white px-2.5 py-1.5 fs-6 fw-bold" style="border-radius: 8px;">
              <?= number_format($closedLoansPct, 1) ?>%
            </span>
            <div class="rounded-3 d-flex align-items-center justify-content-center" 
                 style="width: 32px; height: 32px; background: #dbeafe; color: #1d4ed8;">
              <i class="bi bi-check-circle fs-6"></i>
            </div>
          </div>
        </div>

        <div class="my-3">
          <div class="d-flex align-items-baseline gap-2">
            <span class="mono" style="font-size: 1.35rem; font-weight: 900; line-height: 1.1; color: #2563eb;">
              $<?= fin_money($closedLoansUSDAmount) ?>
            </span>
            <span style="font-size: 0.8rem; font-weight: 700; color: #1d4ed8; opacity: 0.8;">(<?= number_format($closedLoansPctUSD, 1) ?>%)</span>
          </div>
          <div class="d-flex align-items-baseline gap-2" style="margin-top: 6px;">
            <span class="mono" style="font-size: 1.35rem; font-weight: 900; line-height: 1.1; color: #2563eb;">
              ៛<?= fin_money($closedLoansKHRAmount) ?>
            </span>
            <span style="font-size: 0.8rem; font-weight: 700; color: #1d4ed8; opacity: 0.8;">(<?= number_format($closedLoansPctKHR, 1) ?>%)</span>
          </div>
        </div>

        <hr style="opacity: 0.08; margin: 12px 0;">

        <div class="d-flex justify-content-between align-items-center" style="font-size: 0.92rem; font-weight: 600; color: #1d4ed8;">
          <div><i class="bi bi-hash"></i> ចំនួនកម្ចីសរុប: <span class="mono" style="font-size: 1.15rem; font-weight: 800; color: #2563eb;"><?= fin_int($closedLoansUSDCount + $closedLoansKHRCount) ?></span> កម្ចី</div>
          <div><i class="bi bi-people-fill"></i> អតិថិជនរួចរាល់: <span class="mono" style="font-size: 1.15rem; font-weight: 800; color: #2563eb;"><?= fin_int($completedBorrowers) ?></span> នាក់</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Loan Types + Status -->
  <div class="row g-3 mb-3">
    <div class="col-12 col-lg-6">
      <div class="cardx p-3 p-lg-4 h-100">
        <div class="card-head">
          <div class="ttl text-primary"><i class="bi bi-pin-angle-fill me-1 text-primary"></i> សរុបតាមប្រភេទកម្ចី</div>
          <div class="sub">ក្រាហ្វិកលុយដុល្លារ + លុយរៀល</div>
        </div>

        <?php if (!$loanTypeCol): ?>
          <div class="text-muted">មិនអាចបង្ហាញបាន ព្រោះ DB មិនមាន column ប្រភេទកម្ចី។</div>
        <?php else: ?>
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <div class="mini-ttl">ក្រាហ្វិកលុយដុល្លារ (USD)</div>
              <div class="canvas-wrap-sm"><canvas id="chartTypesUSD"></canvas></div>
              <div class="sub mt-2 text-center">សរុបលុយដុល្លារ (Top 12): <span class="mono fw-bold">$&nbsp;<?= fin_money($top12TotalUSD) ?></span></div>
            </div>
            <div class="col-12 col-md-6">
              <div class="mini-ttl">ក្រាហ្វិកលុយរៀល (៛)</div>
              <div class="canvas-wrap-sm"><canvas id="chartTypesKHR"></canvas></div>
              <div class="sub mt-2 text-center">សរុបលុយរៀល (Top 12): <span class="mono fw-bold">៛&nbsp;<?= fin_money($top12TotalKHR) ?></span></div>
            </div>
          </div>

          <hr class="my-3">

          <!-- ✅ Mobile header -->
          <div class="types-mobile-head d-sm-none">
            <div class="grid">
              <span>ប្រភេទ</span>
              <span class="text-end">សរុបប្រាក់ (ចំនួន)</span>
              <span class="text-end">%</span>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 types-table">
              <thead>
                <tr>
                  <th>ប្រភេទ</th>
                  <th class="text-end">ដុល្លារ</th>
                  <th class="text-end col-amt-khr">រៀល</th>
                  <th class="text-end">%</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$typeAgg): ?>
                  <tr><td colspan="4" class="text-center text-muted py-3">មិនមានទិន្នន័យ</td></tr>
                <?php else: ?>
                  <?php foreach ($typeAgg as $t => $row): ?>
                    <?php
                      $usdCnt = (int)($row['usd_cnt'] ?? 0);
                      $khrCnt = (int)($row['khr_cnt'] ?? 0);
                      $usdAmt = (float)($row['usd_amt'] ?? 0);
                      $khrAmt = (float)($row['khr_amt'] ?? 0);
                      $cntAll = (int)($row['cnt'] ?? ($usdCnt + $khrCnt));
                      $pct = fin_pct($cntAll, $typeTotalCount ?: 0);
                      $styType = fin_pct_style((float)$pct);
                    ?>
                    <tr class="type-row">
                      <td class="td-type">
                        <span class="type-name"><?= h2($t) ?></span>
                      </td>

                      <td class="td-amt text-end mono">
                        <!-- Desktop: USD amount & USD count underneath -->
                        <div class="d-none d-sm-block">
                          <div>$&nbsp;<?= fin_money($usdAmt) ?></div>
                          <div class="text-muted" style="font-size: 0.8rem; font-weight: normal; margin-top: 1px;"><?= fin_int($usdCnt) ?> នាក់</div>
                        </div>
                        <!-- Mobile: USD amount & count, KHR amount & count stacked -->
                        <div class="d-sm-none v2">
                          <div class="subhead text-nowrap">$&nbsp;<?= fin_money($usdAmt) ?> <span class="text-muted" style="font-size:0.75rem;">(<?= fin_int($usdCnt) ?> នាក់)</span></div>
                          <div class="subnum text-nowrap">៛&nbsp;<?= fin_money($khrAmt) ?> <span class="text-muted" style="font-size:0.75rem;">(<?= fin_int($khrCnt) ?> នាក់)</span></div>
                        </div>
                      </td>

                      <td class="text-end mono col-amt-khr text-nowrap">
                        <div>៛&nbsp;<?= fin_money($khrAmt) ?></div>
                        <div class="text-muted" style="font-size: 0.8rem; font-weight: normal; margin-top: 1px;"><?= fin_int($khrCnt) ?> នាក់</div>
                      </td>

                      <td class="td-pct text-end">
                        <span class="badge badge-pct <?= $styType['badge'] ?>"><?= fin_money($pct) ?>%</span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="cardx p-3 p-lg-4 h-100">
        <div class="card-head">
          <div class="ttl text-primary"><i class="bi bi-geo-alt-fill me-1 text-primary"></i> ស្ថានភាពកម្ចី</div>
          <div class="sub">Summary + Table</div>
        </div>

        <?php $scrollClass = ($statusRowCount > 10) ? 'limit' : ''; ?>
        <div class="table-responsive scroll-box <?= $scrollClass ?>">

          <!-- ✅ Mobile header -->
          <div class="status-mobile-head d-sm-none">
            <div class="grid">
              <span>ស្ថានភាព</span>
              <span class="text-end">សរុបប្រាក់ (ចំនួន)</span>
              <span class="text-end">%</span>
            </div>
          </div>

          <table class="table table-sm align-middle mb-0 status-table">
            <thead>
              <tr>
                <th>ស្ថានភាព</th>
                <th class="text-end">ដុល្លារ</th>
                <th class="text-end col-amt-khr">រៀល</th>
                <th class="text-end">%</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$statusAgg): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">មិនមានទិន្នន័យ</td></tr>
              <?php else: ?>
                <?php foreach ($statusAgg as $s => $row): ?>
                  <?php
                    $usdCnt = (int)($row['usd_cnt'] ?? 0);
                    $khrCnt = (int)($row['khr_cnt'] ?? 0);
                    $usdAmt = (float)($row['usd_amt'] ?? 0);
                    $khrAmt = (float)($row['khr_amt'] ?? 0);
                    $cntAll = (int)($row['cnt'] ?? ($usdCnt + $khrCnt));

                    $pct = fin_pct($cntAll, $statusTotalCnt ?: 0);
                    $sty = fin_pct_style((float)$pct);
                  ?>
                  <tr class="status-row">
                    <td class="td-status"><span class="status-name"><?= h2($s) ?></span></td>

                    <td class="td-amt text-end mono">
                      <!-- Desktop: USD amount & USD count underneath -->
                      <div class="d-none d-sm-block">
                        <div>$&nbsp;<?= fin_money($usdAmt) ?></div>
                        <div class="text-muted" style="font-size: 0.8rem; font-weight: normal; margin-top: 1px;"><?= fin_int($usdCnt) ?> នាក់</div>
                      </div>
                      <!-- Mobile: USD amount & count, KHR amount & count stacked -->
                      <div class="d-sm-none v2">
                        <div class="subhead text-nowrap">$&nbsp;<?= fin_money($usdAmt) ?> <span class="text-muted" style="font-size:0.75rem;">(<?= fin_int($usdCnt) ?> នាក់)</span></div>
                        <div class="subnum text-nowrap">៛&nbsp;<?= fin_money($khrAmt) ?> <span class="text-muted" style="font-size:0.75rem;">(<?= fin_int($khrCnt) ?> នាក់)</span></div>
                      </div>
                    </td>

                    <td class="text-end mono col-amt-khr text-nowrap">
                      <div>៛&nbsp;<?= fin_money($khrAmt) ?></div>
                      <div class="text-muted" style="font-size: 0.8rem; font-weight: normal; margin-top: 1px;"><?= fin_int($khrCnt) ?> នាក់</div>
                    </td>

                    <td class="td-pct text-end">
                      <span class="badge badge-pct <?= $sty['badge'] ?>"><?= fin_money($pct) ?>%</span>
                    </td>
                  </tr>

                  <tr>
                    <td colspan="4" class="row-progress">
                      <div class="progress">
                        <div class="progress-bar <?= $sty['bar'] ?>" role="progressbar"
                             style="width: <?= (float)$pct ?>%"
                             aria-valuenow="<?= (float)$pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

      </div>
    </div>
  </div>

</div>

<script>
  // USD Pie
  const usdLabels  = <?= json_encode($typeUsdLabels, JSON_UNESCAPED_UNICODE) ?>;
  const usdAmounts = <?= json_encode($typeUsdAmounts, JSON_UNESCAPED_UNICODE) ?>;

  if (document.getElementById('chartTypesUSD') && usdLabels.length) {
    new Chart(document.getElementById('chartTypesUSD'), {
      type: 'pie',
      data: { labels: usdLabels, datasets: [{ data: usdAmounts }] },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { font: { family: 'Battambang' } } },
          tooltip: {
            callbacks: {
              label: function(ctx){
                const v = Number(ctx.raw || 0);
                const total = (ctx.dataset.data || []).reduce((a,b)=>Number(a)+Number(b),0);
                const pct = total > 0 ? ((v/total)*100).toFixed(1) : '0.0';
                return ` ${ctx.label}: $${v.toLocaleString(undefined,{minimumFractionDigits:0,maximumFractionDigits:2})} (${pct}%)`;
              }
            }
          }
        }
      }
    });
  }

  // KHR Pie
  const khrLabels  = <?= json_encode($typeKhrLabels, JSON_UNESCAPED_UNICODE) ?>;
  const khrAmounts = <?= json_encode($typeKhrAmounts, JSON_UNESCAPED_UNICODE) ?>;

  if (document.getElementById('chartTypesKHR') && khrLabels.length) {
    new Chart(document.getElementById('chartTypesKHR'), {
      type: 'pie',
      data: { labels: khrLabels, datasets: [{ data: khrAmounts }] },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { font: { family: 'Battambang' } } },
          tooltip: {
            callbacks: {
              label: function(ctx){
                const v = Number(ctx.raw || 0);
                const total = (ctx.dataset.data || []).reduce((a,b)=>Number(a)+Number(b),0);
                const pct = total > 0 ? ((v/total)*100).toFixed(1) : '0.0';
                return ` ${ctx.label}: ៛${v.toLocaleString(undefined,{minimumFractionDigits:0,maximumFractionDigits:2})} (${pct}%)`;
              }
            }
          }
        }
      }
    });
  }

  // Flatpickr Khmer
  const KhmerLocale = {
    weekdays: {
      shorthand: ["អា", "ច", "អ", "ព", "ព្រ", "សុ", "ស"],
      longhand: ["អាទិត្យ", "ចន្ទ", "អង្គារ", "ពុធ", "ព្រហស្បតិ៍", "សុក្រ", "សៅរ៍"],
    },
    months: {
      shorthand: ["មក", "កុ", "មី", "មេ", "ឧស", "មិថ", "កក្ក", "សី", "កញ", "តុ", "វិច", "ធ្ន"],
      longhand: ["មករា", "កុម្ភៈ", "មីនា", "មេសា", "ឧសភា", "មិថុនា", "កក្កដា", "សីហា", "កញ្ញា", "តុលា", "វិច្ឆិកា", "ធ្នូ"],
    },
    firstDayOfWeek: 1,
    rangeSeparator: " ដល់ ",
    weekAbbreviation: "សប្ដា",
    scrollTitle: "Scroll ដើម្បីបន្ថែម",
    toggleTitle: "Click ដើម្បីប្តូរ",
    amPM: ["ព្រឹក", "ល្ងាច"],
    yearAriaLabel: "ឆ្នាំ",
    time_24hr: true,
    ordinal: () => "",
  };

  function setupDatePicker(selector) {
    const el = document.querySelector(selector);
    if (!el || el.disabled) return;

    flatpickr(el, {
      locale: KhmerLocale,
      allowInput: true,
      disableMobile: true,
      dateFormat: "Y-m-d",
      altInput: true,
      altFormat: "d-F-Y",
    });
  }

  setupDatePicker("#fromPicker");
  setupDatePicker("#toPicker");
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
