<?php
// /finance/admin/customers.php
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

fin_require_login();
fin_require_permission('view_customers');
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
    foreach ($candidates as $c) if ($c && fin_col_exists($pdo, $table, $c)) return $c;
    return $fallback;
}
function fin_money($n): string { return number_format((float)$n, 2); }
function fin_money_khr($n): string { return number_format((float)$n, 0) . ' ៛'; }
function fin_format_outstanding($usd, $khr): string {
    $usd = (float)$usd;
    $khr = (float)$khr;
    if ($usd > 0 && $khr > 0) {
        return '$ ' . fin_money($usd) . '<div class="text-muted" style="font-size: 0.8rem; font-weight: normal; margin-top: 1px;">' . fin_money_khr($khr) . '</div>';
    } elseif ($usd > 0) {
        return '$ ' . fin_money($usd);
    } else {
        return fin_money_khr($khr);
    }
}
function fin_valid_date(string $value): string {
    if ($value === '') return '';
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : '';
}
function fin_initials(string $name): string {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($name === '') return 'NA';
    $parts = explode(' ', $name);
    $first = mb_substr($parts[0] ?? 'N', 0, 1);
    $last  = mb_substr($parts[count($parts)-1] ?? 'A', 0, 1);
    return strtoupper($first.$last);
}
function fin_build_url(array $overrides = []): string {
    $q = $_GET;
    foreach ($overrides as $k=>$v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    $qs = http_build_query($q);
    return basename($_SERVER['PHP_SELF']) . ($qs ? "?{$qs}" : "");
}

/* ✅ convert DB path to correct public URL from /finance/admin/ */
function fin_public_url(?string $path): string {
    $p = trim((string)$path);
    if ($p === '') return '';
    if (preg_match('#^https?://#i', $p)) return $p;
    if (str_starts_with($p, '/')) return $p;
    return '../' . ltrim($p, '/'); // because we are in /finance/admin/
}


/* ✅ default avatar by gender (DB may contain male/female/ប្រុស/ស្រី) */
function fin_avatar_by_gender(string $gender): string {
    $g = strtolower(trim($gender));
    if (in_array($g, ['male','m','ប្រុស'], true))   return '../uploads/avatars/male.png';
    if (in_array($g, ['female','f','ស្រី'], true)) return '../uploads/avatars/female.png';
    return '../uploads/avatars/default.png';
}

/* ---------------- safety: ensure customers table exists ---------------- */
if (!fin_table_exists($pdo, 'customers')) {
    http_response_code(500);
    echo "<h3 style='font-family:Battambang,sans-serif'>Error</h3>
          <p style='font-family:Battambang,sans-serif'>តារាង customers មិនមានក្នុង DB ទេ។</p>";
    exit;
}

/* ---------------- detect columns ---------------- */
$customersBizCol = fin_col_exists($pdo,'customers','business_id') ? 'business_id' : (fin_col_exists($pdo,'customers','biz_id') ? 'biz_id' : 'business_id');

$colName   = fin_first_col($pdo,'customers',['full_name','name','customer_name'],'full_name');
$colPhone  = fin_first_col($pdo,'customers',['phone','phone_number','tel'],'phone');
$colPhone2 = fin_col_exists($pdo,'customers','phone2') ? 'phone2' : '';
$colAddr   = fin_first_col($pdo,'customers',['address','addr','home_address'],'address');
function fin_customer_rating(array $r): array {
    global $bulk_ratings;
    $cid = (int)($r['id'] ?? 0);
    return $bulk_ratings[$cid] ?? [
        'rating' => 'none',
        'text' => 'គ្មានប្រវត្តិ',
        'badge' => 'bg-secondary-subtle text-secondary',
        'desc' => 'មិនទាន់មានប្រវត្តិកម្ចី'
    ];
}


/* ---------------- detect columns ---------------- */
$colGender = fin_first_col($pdo,'customers',['gender','sex'],'gender');
$colActive = fin_col_exists($pdo,'customers','is_active') ? 'is_active' : '';
$colCreated= fin_first_col($pdo,'customers',['created_at','created_on','date_created'],'created_at');

/* ✅ photo column (you said: NOT profile_photo) */
$colPhoto  = fin_first_col($pdo,'customers',['photo','photo_url','avatar','avatar_url','profile_photo','profile_image','image','img'],'');
$colPhotoSelect = $colPhoto ? "c.`{$colPhoto}` AS photo," : "'' AS photo,";

/* ---------------- search / filters / pagination ---------------- */
$q = trim($_GET['q'] ?? '');
$gender = trim($_GET['gender'] ?? '');
$statusDetail = trim($_GET['status_detail'] ?? '');
$period = trim((string)($_GET['period'] ?? 'all'));
if ($period === 'this_month') {
    $dateFrom = date('Y-m-01');
    $dateTo   = date('Y-m-t');
} elseif ($period === 'last_month') {
    $dateFrom = date('Y-m-d', strtotime('first day of last month'));
    $dateTo   = date('Y-m-d', strtotime('last day of last month'));
} elseif ($period === '3_months') {
    $dateFrom = date('Y-m-d', strtotime('-3 months'));
    $dateTo   = date('Y-m-d');
} elseif ($period === '6_months') {
    $dateFrom = date('Y-m-d', strtotime('-6 months'));
    $dateTo   = date('Y-m-d');
} elseif ($period === '1_year') {
    $dateFrom = date('Y-m-d', strtotime('-1 year'));
    $dateTo   = date('Y-m-d');
} elseif ($period === 'last_year') {
    $dateFrom = date('Y-01-01', strtotime('-1 year'));
    $dateTo   = date('Y-12-31', strtotime('-1 year'));
} elseif ($period === 'custom') {
    $dateFrom = fin_valid_date(trim($_GET['date_from'] ?? ''));
    $dateTo   = fin_valid_date(trim($_GET['date_to'] ?? ''));
} else {
    $dateFrom = '';
    $dateTo   = '';
}

$statusOptions = [
    'បង់ផ្តាច់រួច' => 'បង់ផ្តាច់រួច',
    'បន្តបង់ធម្មតា' => 'បន្តបង់ធម្មតា',
    'បន្តបង់ខ្លះៗ' => 'បន្តបង់ខ្លះៗ',
    'អត់បង់សោះ' => 'អត់បង់សោះ',
    'ទាក់ទងលេងបាន' => 'ទាក់ទងលេងបាន',
    'កាត់ចោល' => 'កាត់ចោល',
    'បន្តបង់តាមលទ្ធភាព' => 'បន្តបង់តាមលទ្ធភាព',
    'សុំបង់់តែការសិន' => 'សុំបង់់តែការសិន'
];

$where = " WHERE c.{$customersBizCol} = ? ";
$params = [$business_id];

if ($q !== '') {
    $where .= " AND (c.`{$colName}` LIKE ? OR c.`{$colPhone}` LIKE ? " . ($colPhone2 ? " OR c.`{$colPhone2}` LIKE ? " : "") . " OR c.`{$colAddr}` LIKE ?) ";
    $params[] = "%$q%";
    $params[] = "%$q%";
    if ($colPhone2) $params[] = "%$q%";
    $params[] = "%$q%";
}

if ($gender !== '') {
    $where .= " AND c.`{$colGender}` = ? ";
    $params[] = $gender;
}

if ($statusDetail !== '') {
    if ($statusDetail === 'បង់ផ្តាច់រួច') {
        $where .= " AND EXISTS (SELECT 1 FROM loans l WHERE l.customer_id = c.id AND (l.status_detail = 'បង់ផ្តាច់រួច' OR UPPER(l.status) = 'CLOSED')) ";
    } else {
        $where .= " AND EXISTS (SELECT 1 FROM loans l WHERE l.customer_id = c.id AND l.status_detail = ?) ";
        $params[] = $statusDetail;
    }
}

if ($dateFrom !== '') {
    $where .= " AND c.`{$colCreated}` >= ? ";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where .= " AND c.`{$colCreated}` <= ? ";
    $params[] = $dateTo . ' 23:59:59';
}

/* ---------------- counts ---------------- */
$st = $pdo->prepare("SELECT COUNT(*) FROM customers c {$where}");
$st->execute($params);
$totalRows = (int)$st->fetchColumn();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;
$totalPages = max(1, (int)ceil($totalRows / $perPage));

/* ---------------- loan aggregates by customer (optional) ---------------- */
$loansExists = fin_table_exists($pdo, 'loans');
$loanSchedulesExists = fin_table_exists($pdo, 'loan_schedules');

$loanBizCol = $loansExists ? (fin_col_exists($pdo,'loans','business_id') ? 'business_id' : 'biz_id') : 'business_id';
$schedBizCol= $loanSchedulesExists ? (fin_col_exists($pdo,'loan_schedules','business_id') ? 'business_id' : 'biz_id') : 'business_id';

$loanCustomerCol = $loansExists ? fin_first_col($pdo,'loans',['customer_id','client_id'],'customer_id') : 'customer_id';
$loanIdCol = 'id';

$schedLoanIdCol = $loanSchedulesExists ? fin_first_col($pdo,'loan_schedules',['loan_id'],'loan_id') : 'loan_id';
$schedTotalCol  = $loanSchedulesExists ? fin_first_col($pdo,'loan_schedules',['total_due','amount_due','total'],'total_due') : 'total_due';
$schedPaidCol   = $loanSchedulesExists ? fin_first_col($pdo,'loan_schedules',['paid_total','paid','paid_amount'],'paid_total') : 'paid_total';

$joinAgg = "";
$selectAgg = "";
$paramsAgg = [];

if ($loansExists) {
    $selectAgg .= ",
      COALESCE(lc.loan_count,0) AS loan_count,
      lc.status_details AS loan_status_details,
      lc.statuses AS loan_statuses
    ";
    $joinAgg .= "
      LEFT JOIN (
        SELECT
          {$loanCustomerCol} AS customer_id,
          COUNT(*) AS loan_count,
          GROUP_CONCAT(DISTINCT COALESCE(status_detail, '')) AS status_details,
          GROUP_CONCAT(DISTINCT COALESCE(status, '')) AS statuses
        FROM loans
        WHERE {$loanBizCol} = ?
        GROUP BY {$loanCustomerCol}
      ) lc ON lc.customer_id = c.id
    ";
    $paramsAgg[] = $business_id;
}

if ($loansExists) {
    $selectAgg .= ",
      COALESCE(ls.outstanding_usd,0) AS outstanding_usd,
      COALESCE(ls.outstanding_khr,0) AS outstanding_khr,
      COALESCE(ls.closed_usd,0) AS closed_usd,
      COALESCE(ls.closed_khr,0) AS closed_khr
    ";
    $joinAgg .= "
      LEFT JOIN (
        SELECT customer_id,
               COALESCE(SUM(CASE WHEN currency_code = 'USD' AND COALESCE(status_detail,'') != 'កាត់ចោល' AND COALESCE(status_detail,'') != 'បង់ផ្តាច់រួច' AND UPPER(COALESCE(status,'')) != 'CLOSED' THEN principal_amount ELSE 0 END),0) AS outstanding_usd,
               COALESCE(SUM(CASE WHEN (currency_code != 'USD' OR currency_code IS NULL OR currency_code = '') AND COALESCE(status_detail,'') != 'កាត់ចោល' AND COALESCE(status_detail,'') != 'បង់ផ្តាច់រួច' AND UPPER(COALESCE(status,'')) != 'CLOSED' THEN principal_amount ELSE 0 END),0) AS outstanding_khr,
               COALESCE(SUM(CASE WHEN currency_code = 'USD' AND (COALESCE(status_detail,'') = 'បង់ផ្តាច់រួច' OR UPPER(COALESCE(status,'')) = 'CLOSED') THEN principal_amount ELSE 0 END),0) AS closed_usd,
               COALESCE(SUM(CASE WHEN (currency_code != 'USD' OR currency_code IS NULL OR currency_code = '') AND (COALESCE(status_detail,'') = 'បង់ផ្តាច់រួច' OR UPPER(COALESCE(status,'')) = 'CLOSED') THEN principal_amount ELSE 0 END),0) AS closed_khr,
               COUNT(DISTINCT CASE WHEN currency_code = 'USD' AND COALESCE(status_detail,'') != 'កាត់ចោល' AND COALESCE(status_detail,'') != 'បង់ផ្តាច់រួច' AND UPPER(COALESCE(status,'')) != 'CLOSED' THEN id END) AS active_usd_loans,
               COUNT(DISTINCT CASE WHEN (currency_code != 'USD' OR currency_code IS NULL OR currency_code = '') AND COALESCE(status_detail,'') != 'កាត់ចោល' AND COALESCE(status_detail,'') != 'បង់ផ្តាច់រួច' AND UPPER(COALESCE(status,'')) != 'CLOSED' THEN id END) AS active_khr_loans
        FROM loans
        WHERE business_id = ?
        GROUP BY customer_id
      ) ls ON ls.customer_id = c.id
    ";
    $paramsAgg[] = $business_id;
}

/* ---------------- fetch rows ---------------- */
$selectCols = "
  c.id,
  {$colPhotoSelect}
  c.`{$colName}` AS full_name,
  c.`{$colPhone}` AS phone,
  " . ($colGender ? "c.`{$colGender}` AS gender," : "'' AS gender,") . "
  " . ($colAddr ? "c.`{$colAddr}` AS address," : "'' AS address,") . "
  " . ($colActive ? "c.`{$colActive}` AS is_active," : "1 AS is_active,") . "
  " . ($colCreated ? "c.`{$colCreated}` AS created_at" : "NULL AS created_at") . "
  {$selectAgg}
";

$sql = "
  SELECT {$selectCols}
  FROM customers c
  {$joinAgg}
  {$where}
  ORDER BY c.id DESC
  LIMIT {$perPage} OFFSET {$offset}
";

$st = $pdo->prepare($sql);
$st->execute(array_merge($paramsAgg, $params));
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Pre-calculate ratings in bulk
$customer_ids = array_column($rows, 'id');
$bulk_ratings = fin_bulk_calculate_ratings($pdo, $customer_ids);

/* ---------------- summary cards (all filtered rows) ---------------- */
$sumOutstandingUSD = 0.0;
$sumOutstandingKHR = 0.0;
$sumLoans = 0;
$sumActiveUSDCount = 0;
$sumActiveKHRCount = 0;
if ($loansExists) {
    $summarySql = "
      SELECT COALESCE(SUM(COALESCE(lc.loan_count,0)),0) AS loan_count,
             COALESCE(SUM(COALESCE(ls.outstanding_usd,0)),0) AS outstanding_usd,
             COALESCE(SUM(COALESCE(ls.outstanding_khr,0)),0) AS outstanding_khr,
             COALESCE(SUM(COALESCE(ls.active_usd_loans,0)),0) AS active_loans_usd,
             COALESCE(SUM(COALESCE(ls.active_khr_loans,0)),0) AS active_loans_khr
      FROM customers c
      {$joinAgg}
      {$where}
    ";
    $summarySt = $pdo->prepare($summarySql);
    $summarySt->execute(array_merge($paramsAgg, $params));
    $summary = $summarySt->fetch(PDO::FETCH_ASSOC) ?: [];
    $sumLoans = (int)($summary['loan_count'] ?? 0);
    $sumOutstandingUSD = max(0, (float)($summary['outstanding_usd'] ?? 0));
    $sumOutstandingKHR = max(0, (float)($summary['outstanding_khr'] ?? 0));
    $sumActiveUSDCount = (int)($summary['active_loans_usd'] ?? 0);
    $sumActiveKHRCount = (int)($summary['active_loans_khr'] ?? 0);
}

$writeOffCount = 0;
$writeOffUSDAmt = 0.0;
$writeOffKHRAmt = 0.0;

$closedCount = 0;
$closedUSDAmt = 0.0;
$closedKHRAmt = 0.0;

if ($loansExists) {
    // Write-off loans
    $st = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN currency_code = 'USD' THEN principal_amount ELSE 0 END),0) AS usd_amt,
            COALESCE(SUM(CASE WHEN currency_code = 'KHR' OR currency_code IS NULL OR currency_code = '' THEN principal_amount ELSE 0 END),0) AS khr_amt,
            COUNT(*) AS cnt
        FROM loans
        WHERE business_id = ? AND status_detail = 'កាត់ចោល'
    ");
    $st->execute([$business_id]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $writeOffCount = (int)($r['cnt'] ?? 0);
    $writeOffUSDAmt = (float)($r['usd_amt'] ?? 0);
    $writeOffKHRAmt = (float)($r['khr_amt'] ?? 0);

    // Closed loans
    $st = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN currency_code = 'USD' THEN principal_amount ELSE 0 END),0) AS usd_amt,
            COALESCE(SUM(CASE WHEN currency_code = 'KHR' OR currency_code IS NULL OR currency_code = '' THEN principal_amount ELSE 0 END),0) AS khr_amt,
            COUNT(*) AS cnt
        FROM loans
        WHERE business_id = ? AND (status_detail = 'បង់ផ្តាច់រួច' OR status = 'CLOSED')
    ");
    $st->execute([$business_id]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $closedCount = (int)($r['cnt'] ?? 0);
    $closedUSDAmt = (float)($r['usd_amt'] ?? 0);
    $closedKHRAmt = (float)($r['khr_amt'] ?? 0);
}

if (isset($_GET['ajax'])) {
    // Render KPI HTML
    ob_start();
    ?>
    <!-- Box 1: Customers + Loans -->
    <div class="col-12 col-sm-6 col-lg">
      <div class="kpi blue">
        <div class="lbl"><i class="bi bi-people-fill me-1"></i> អតិថិជនសរុប & ចំនួនកម្ចី</div>
        <span class="kpi-icon float-end"><i class="bi bi-person-badge"></i></span>
        <div class="val mono"><?= (int)$totalRows ?> នាក់</div>
        <div class="sub">សរុបកម្ចី: <?= (int)$sumLoans ?> កម្ចី</div>
      </div>
    </div>
    <!-- Box 2: Outstanding (USD) -->
    <div class="col-12 col-sm-6 col-lg">
      <div class="kpi violet">
        <div class="lbl"><i class="bi bi-hourglass-split me-1"></i> កំពុងជំពាក់ (ដុល្លារ)</div>
        <span class="kpi-icon float-end"><i class="bi bi-currency-dollar"></i></span>
        <div class="val mono">$ <?= fin_money($sumOutstandingUSD) ?></div>
        <div class="sub">សរុប: <?= (int)$sumActiveUSDCount ?> កម្ចី</div>
      </div>
    </div>
    <!-- Box 3: Outstanding (KHR) -->
    <div class="col-12 col-sm-6 col-lg">
      <div class="kpi violet">
        <div class="lbl"><i class="bi bi-hourglass-split me-1"></i> កំពុងជំពាក់ (រៀល)</div>
        <span class="kpi-icon float-end"><i class="bi bi-currency-exchange"></i></span>
        <div class="val mono"><?= fin_money_khr($sumOutstandingKHR) ?></div>
        <div class="sub">សរុប: <?= (int)$sumActiveKHRCount ?> កម្ចី</div>
      </div>
    </div>
    <!-- Box 4: កម្ចីកាត់ចោល -->
    <div class="col-12 col-sm-6 col-lg">
      <div class="kpi red">
        <div class="lbl"><i class="bi bi-x-circle me-1"></i> កម្ចីកាត់ចោល</div>
        <span class="kpi-icon float-end"><i class="bi bi-trash3"></i></span>
        <div class="val mono"><?= (int)$writeOffCount ?> កម្ចី</div>
        <div class="sub">សរុប: $<?= fin_money($writeOffUSDAmt) ?> • ៛<?= fin_money($writeOffKHRAmt) ?></div>
      </div>
    </div>
    <!-- Box 5: បង់ផ្តាច់រួច -->
    <div class="col-12 col-sm-6 col-lg">
      <div class="kpi green">
        <div class="lbl"><i class="bi bi-check-circle me-1"></i> បង់ផ្តាច់រួច</div>
        <span class="kpi-icon float-end"><i class="bi bi-shield-check"></i></span>
        <div class="val mono"><?= (int)$closedCount ?> កម្ចី</div>
        <div class="sub">សរុប: $<?= fin_money($closedUSDAmt) ?> • ៛<?= fin_money($closedKHRAmt) ?></div>
      </div>
    </div>
    <?php
    $kpiHtml = ob_get_clean();

    // Render Table Body
    ob_start();
    if (!$rows) {
        ?>
        <tr><td colspan="8" class="text-center text-muted py-4">មិនមានអតិថិជន។</td></tr>
        <?php
    } else {
        foreach ($rows as $i => $r) {
            $id = (int)($r['id'] ?? 0);
            $fullName = (string)($r['full_name'] ?? '');
            $phone = (string)($r['phone'] ?? '');
            $genderV = strtolower(trim((string)($r['gender'] ?? '')));
            $is_active = (int)($r['is_active'] ?? 1);

            $photoDb = trim((string)($r['photo'] ?? ''));
            $photoUrl = $photoDb ? fin_public_url($photoDb) : '';
            $fallbackAvatar = fin_avatar_by_gender($genderV);

            $genderBadge = '<span class="badge-soft"><i class="bi bi-question-circle"></i> —</span>';
            if (in_array($genderV, ['male','m','ប្រុស'], true)) {
                $genderBadge = '<span class="badge-soft badge-male"><i class="bi bi-gender-male"></i> ប្រុស</span>';
            } elseif (in_array($genderV, ['female','f','ស្រី'], true)) {
                $genderBadge = '<span class="badge-soft badge-female"><i class="bi bi-gender-female"></i> ស្រី</span>';
            }

            $outUsd = isset($r['outstanding_usd']) ? (float)$r['outstanding_usd'] : 0.0;
            $outKhr = isset($r['outstanding_khr']) ? (float)$r['outstanding_khr'] : 0.0;
            $closedUsd = isset($r['closed_usd']) ? (float)$r['closed_usd'] : 0.0;
            $closedKhr = isset($r['closed_khr']) ? (float)$r['closed_khr'] : 0.0;
            $loanCount = isset($r['loan_count']) ? (int)$r['loan_count'] : 0;
            $rating    = fin_customer_rating($r);
            $hasNoDebt = ($outUsd <= 0.0001 && $outKhr <= 0.0001);
            $hasPastLoans = ($closedUsd > 0.0001 || $closedKhr > 0.0001 || $loanCount > 0);
            $isPaidOff = ($rating['rating'] === 'paid_off') || ($hasNoDebt && $hasPastLoans && $rating['rating'] !== 'bad');

            $rowNo = ($offset + $i + 1);
            $isNewCustomer = false;
            if (!empty($r['created_at'])) {
                $createdAt = strtotime($r['created_at']);
                if ($createdAt !== false && (time() - $createdAt) <= (30 * 86400)) {
                    $isNewCustomer = true;
                }
            }

            $linkAddLoan   = "loan_add.php?customer_id={$id}";
            $linkViewLoans = "loans.php?customer_id={$id}";
            $linkSummary   = "loans.php?customer_id={$id}&view=summary";
            $linkEdit      = "customer_edit.php?id={$id}";
            $linkDelete    = "customer_delete.php?id={$id}";
            ?>
            <tr class="d-none d-md-table-row<?= $isPaidOff ? ' row-paid-off' : '' ?>">
              <td class="mono"><?= $rowNo ?></td>
              <td>
                <div class="namecell">
                  <div class="avatar">
                    <?php if ($photoUrl !== ''): ?>
                      <img src="<?= h2($photoUrl) ?>" alt="<?= h2($fullName) ?>" onerror="this.src='<?= h2($fallbackAvatar) ?>'">
                    <?php else: ?>
                      <img src="<?= h2($fallbackAvatar) ?>" alt="avatar">
                    <?php endif; ?>
                    <?php if ($isPaidOff): ?>
                      <span class="paid-check-badge" title="អតិថិជនបានបង់ផ្តាច់រួច"><i class="bi bi-check-lg"></i></span>
                    <?php elseif ($colActive): ?>
                      <span class="presence-dot <?= $is_active ? 'active' : 'inactive' ?>" title="<?= $is_active ? 'Active' : 'Inactive' ?>"></span>
                    <?php endif; ?>
                      <?php if ($isNewCustomer): ?>
                      <svg class="new-badge" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="42" height="42">
                        <polygon points="50,18 54.7,26.21 62.42,19.09 63.78,27.83 74,22.29 71.92,30.96 83.94,27.37 78.56,35.39 91.57,34 83.26,40.82 96.36,41.72 85.69,46.87 98,50 85.69,53.13 96.36,58.28 83.26,59.18 91.57,66 78.56,64.61 83.94,72.63 71.92,69.04 74,77.71 63.78,72.17 62.42,80.91 54.7,73.79 50,82 45.3,73.79 37.58,80.91 36.22,72.17 26,77.71 28.08,69.04 16.06,72.63 21.44,64.61 8.43,66 16.74,59.18 3.64,58.28 14.31,53.13 2,50 14.31,46.87 3.64,41.72 16.74,40.82 8.43,34 21.44,35.39 16.06,27.37 28.08,30.96 26,22.29 36.22,27.83 37.58,19.09 45.3,26.21" fill="#d10000" />
                        <text x="50" y="56" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif" font-weight="900" font-size="18" fill="#ffffff" text-anchor="middle" letter-spacing="0.5">NEW</text>
                      </svg>
                    <?php endif; ?>
                  </div>
                  <div class="nwrap">
                    <div class="n">
                      <a href="customer_view.php?id=<?= $id ?>" class="text-dark text-decoration-none fw-bold" title="មើលព័ត៌មាន និងប្រវត្តិកម្ចី"><?= h2($fullName) ?></a>
                      <?php if ($isPaidOff): ?>
                        <i class="bi bi-patch-check-fill text-success ms-1" style="font-size: 0.95rem; vertical-align: middle;" title="អតិថិជនបានបង់ផ្តាច់កម្ចីរួចរាល់"></i>
                      <?php endif; ?>
                    </div>
                    <div class="p">
                      <i class="bi bi-telephone-fill"></i>
                      <span class="mono"><?= h2($phone) ?></span>
                    </div>
                  </div>
                </div>
              </td>
              <td class="d-none d-md-table-cell"><?= h2($r['address'] ?? '') ?></td>
              <td class="d-none d-md-table-cell"><?= $genderBadge ?></td>
              <td class="d-none d-md-table-cell">
                <?php if ($isPaidOff && $rating['rating'] !== 'bad'): ?>
                  <span class="badge badge-paid-off" title="បានបង់ផ្តាច់កម្ចីរួចរាល់ទាំងអស់"><i class="bi bi-patch-check-fill me-1"></i>បង់ផ្តាច់រួច</span>
                <?php else: ?>
                  <span class="badge <?= $rating['badge'] ?>" title="<?= h2($rating['desc']) ?>"><?= h2($rating['text']) ?></span>
                <?php endif; ?>
              </td>
              <td class="text-end mono money text-nowrap">
                <?php if ($isPaidOff): ?>
                  <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.78rem; font-weight: 700; padding: 3px 8px; border-radius: 999px;">
                    <i class="bi bi-check-circle-fill me-1"></i>0 ៛ (បង់ផ្តាច់)
                  </span>
                  <?php if ($closedUsd > 0 || $closedKhr > 0): ?>
                    <div class="text-success small fw-normal mt-1" style="font-size: 0.74rem;" title="កម្ចីមុនដែលបានបង់ផ្តាច់រួច">
                      <i class="bi bi-check2-circle"></i> កម្ចីមុន: <?= fin_format_outstanding($closedUsd, $closedKhr) ?>
                    </div>
                  <?php endif; ?>
                <?php else: ?>
                  <?= fin_format_outstanding($outUsd, $outKhr) ?>
                  <?php if ($outUsd <= 0 && $outKhr <= 0 && ($closedUsd > 0 || $closedKhr > 0)): ?>
                    <div class="text-success small fw-normal" style="font-size: 0.75rem; margin-top: 1px;" title="កម្ចីមុនដែលបានបង់ផ្តាច់រួច">
                      <i class="bi bi-check2-circle"></i> កម្ចីមុន: <?= fin_format_outstanding($closedUsd, $closedKhr) ?>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <td class="text-end mono"><?= (int)$loanCount ?></td>
              <td class="text-end">
                <div class="d-inline-flex justify-content-end gap-2">
                  <a class="btn-icon pri text-nowrap" href="<?= h2($linkViewLoans) ?>" title="មើលបញ្ជីកម្ចី"><i class="bi bi-folder2-open"></i></a>
                  <a class="btn-icon <?= $isPaidOff ? 'btn-new-loan' : 'suc' ?> text-nowrap" href="<?= h2($linkAddLoan) ?>" title="<?= $isPaidOff ? 'ស្នើកម្ចីថ្មី' : 'បន្ថែមកម្ចី' ?>"><i class="bi bi-plus-circle"></i></a>
                  <div class="dropdown">
                    <button class="btn-icon text-nowrap" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="ផ្សេងៗ">
                      <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                      <?php if ($isPaidOff): ?>
                      <li>
                        <a class="dropdown-item text-primary fw-bold" href="<?= h2($linkAddLoan) ?>">
                          <i class="bi bi-plus-circle me-2 text-primary"></i> ស្នើកម្ចីថ្មី
                        </a>
                      </li>
                      <li><hr class="dropdown-divider"></li>
                      <?php endif; ?>
                      <li>
                        <a class="dropdown-item" href="customer_view.php?id=<?= $id ?>">
                          <i class="bi bi-person-vcard me-2 text-primary"></i> មើលព័ត៌មាន & ប្រវត្តិ
                        </a>
                      </li>
                      <li>
                        <a class="dropdown-item" href="<?= h2($linkEdit) ?>">
                          <i class="bi bi-pencil-square me-2 text-warning"></i> កែប្រែព័ត៌មាន
                        </a>
                      </li>
                      <li><hr class="dropdown-divider"></li>
                      <li>
                        <a class="dropdown-item text-danger" href="<?= h2($linkDelete) ?>"
                           onclick="return confirm('តើអ្នកប្រាកដថាចង់លុបអតិថិជននេះឬ?');">
                          <i class="bi bi-trash3 me-2 text-danger"></i> លុបអតិថិជន
                        </a>
                      </li>
                    </ul>
                  </div>
                </div>
              </td>
            </tr>

            <!-- ===================== MOBILE CARD ROW ===================== -->
            <tr class="d-md-none">
              <td colspan="8">
                <div class="m-card<?= $isPaidOff ? ' card-paid-off' : '' ?>">
                  <div class="m-top d-flex justify-content-between align-items-start gap-2">
                    <div class="m-left d-flex gap-2 align-items-start" style="min-width: 0; flex: 1;">
                      <div class="avatar">
                        <?php if ($photoUrl !== ''): ?>
                          <img src="<?= h2($photoUrl) ?>" alt="<?= h2($fullName) ?>" onerror="this.src='<?= h2($fallbackAvatar) ?>'">
                        <?php else: ?>
                          <img src="<?= h2($fallbackAvatar) ?>" alt="avatar">
                        <?php endif; ?>
                        <?php if ($isPaidOff): ?>
                          <span class="paid-check-badge" title="អតិថិជនបានបង់ផ្តាច់រួច"><i class="bi bi-check-lg"></i></span>
                        <?php elseif ($colActive): ?>
                          <span class="presence-dot <?= $is_active ? 'active' : 'inactive' ?>" title="<?= $is_active ? 'Active' : 'Inactive' ?>"></span>
                        <?php endif; ?>
                          <?php if ($isNewCustomer): ?>
                      <svg class="new-badge" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="42" height="42">
                        <polygon points="50,18 54.7,26.21 62.42,19.09 63.78,27.83 74,22.29 71.92,30.96 83.94,27.37 78.56,35.39 91.57,34 83.26,40.82 96.36,41.72 85.69,46.87 98,50 85.69,53.13 96.36,58.28 83.26,59.18 91.57,66 78.56,64.61 83.94,72.63 71.92,69.04 74,77.71 63.78,72.17 62.42,80.91 54.7,73.79 50,82 45.3,73.79 37.58,80.91 36.22,72.17 26,77.71 28.08,69.04 16.06,72.63 21.44,64.61 8.43,66 16.74,59.18 3.64,58.28 14.31,53.13 2,50 14.31,46.87 3.64,41.72 16.74,40.82 8.43,34 21.44,35.39 16.06,27.37 28.08,30.96 26,22.29 36.22,27.83 37.58,19.09 45.3,26.21" fill="#d10000" />
                        <text x="50" y="56" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif" font-weight="900" font-size="18" fill="#ffffff" text-anchor="middle" letter-spacing="0.5">NEW</text>
                      </svg>
                    <?php endif; ?>
                  </div>
                      <div class="m-title" style="min-width: 0; flex: 1;">
                        <p class="name mb-0" style="font-weight: 900; font-size: 1.05rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                          <a href="customer_view.php?id=<?= $id ?>" class="text-dark text-decoration-none" title="មើលព័ត៌មាន និងប្រវត្តិកម្ចី"><?= h2($fullName) ?></a>
                          <?php if ($isPaidOff): ?>
                            <i class="bi bi-patch-check-fill text-success ms-1" style="font-size: 0.95rem; vertical-align: middle;" title="អតិថិជនបានបង់ផ្តាច់កម្ចីរួចរាល់"></i>
                          <?php endif; ?>
                        </p>
                        <div class="m-sub mt-1">
                          <?= $genderBadge ?>
                        </div>
                      </div>
                    </div>
                    <div class="m-right text-end d-flex flex-column align-items-end" style="flex-shrink: 0; line-height: 1.2;">
                      <div class="mono" style="font-size: 0.88rem; font-weight: 700; color: #475569; display: inline-flex; align-items: center; gap: 4px;">
                        <i class="bi bi-telephone-fill" style="color: #3b82f6; font-size: 0.8rem;"></i>
                        <a href="tel:<?= h2($phone) ?>" style="text-decoration: none; color: inherit;"><?= h2($phone) ?></a>
                      </div>
                      <div class="mt-1">
                        <?php if ($isPaidOff && $rating['rating'] !== 'bad'): ?>
                          <span class="badge badge-paid-off" title="បានបង់ផ្តាច់កម្ចីរួចរាល់ទាំងអស់" style="font-size: 0.72rem;"><i class="bi bi-patch-check-fill me-1"></i>បង់ផ្តាច់រួច</span>
                        <?php else: ?>
                          <span class="badge <?= $rating['badge'] ?>" title="<?= h2($rating['desc']) ?>" style="font-size: 0.72rem;"><?= h2($rating['text']) ?></span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>

                  <div class="m-mini">
                    <div class="mini-box">
                      <div class="lbl"><i class="bi bi-hourglass-split"></i> កំពុងជំពាក់</div>
                      <div class="val mono money">
                        <?php if ($isPaidOff): ?>
                          <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.76rem; font-weight: 700; padding: 2px 7px; border-radius: 999px;">
                            <i class="bi bi-check-circle-fill me-1"></i>0 ៛ (បង់ផ្តាច់)
                          </span>
                          <?php if ($closedUsd > 0 || $closedKhr > 0): ?>
                            <div class="text-success small fw-normal mt-1" style="font-size: 0.72rem;" title="កម្ចីមុនដែលបានបង់ផ្តាច់រួច">
                              <i class="bi bi-check2-circle"></i> កម្ចីមុន: <?= fin_format_outstanding($closedUsd, $closedKhr) ?>
                            </div>
                          <?php endif; ?>
                        <?php else: ?>
                          <?= fin_format_outstanding($outUsd, $outKhr) ?>
                          <?php if ($outUsd <= 0 && $outKhr <= 0 && ($closedUsd > 0 || $closedKhr > 0)): ?>
                            <div class="text-success small fw-normal" style="font-size: 0.72rem; margin-top: 1px;" title="កម្ចីមុនដែលបានបង់ផ្តាច់រួច">
                              <i class="bi bi-check2-circle"></i> កម្ចីមុន: <?= fin_format_outstanding($closedUsd, $closedKhr) ?>
                            </div>
                          <?php endif; ?>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="mini-box">
                      <div class="lbl"><i class="bi bi-cash-coin"></i> ចំនួនកម្ចី</div>
                      <div class="val mono"><?= (int)$loanCount ?></div>
                    </div>
                  </div>

                  <div class="m-actionbar text-nowrap">
                    <span class="m-rowno mono"><?= $rowNo ?></span>
                    <div class="m-actions text-nowrap">
                      <a class="btn-icon pri text-nowrap px-2" style="width: auto; height: 32px; border-radius: 8px; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; text-decoration: none;" href="<?= h2($linkViewLoans) ?>"><i class="bi bi-folder2-open" style="font-size: 0.95rem;"></i> មើលកម្ចី</a>
                      <a class="btn-icon <?= $isPaidOff ? 'btn-new-loan' : 'suc' ?> text-nowrap px-2" style="width: auto; height: 32px; border-radius: 8px; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; text-decoration: none;" href="<?= h2($linkAddLoan) ?>"><i class="bi bi-plus-circle" style="font-size: 0.95rem;"></i> <?= $isPaidOff ? 'ស្នើកម្ចីថ្មី' : 'បន្ថែមកម្ចី' ?></a>
                      <div class="dropdown">
                        <button class="btn-icon text-nowrap" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="ផ្សេងៗ">
                          <i class="bi bi-three-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                          <?php if ($isPaidOff): ?>
                          <li>
                            <a class="dropdown-item text-primary fw-bold" href="<?= h2($linkAddLoan) ?>">
                              <i class="bi bi-plus-circle me-2 text-primary"></i> ស្នើកម្ចីថ្មី
                            </a>
                          </li>
                          <li><hr class="dropdown-divider"></li>
                          <?php endif; ?>
                          <li>
                            <a class="dropdown-item" href="customer_view.php?id=<?= $id ?>">
                              <i class="bi bi-person-vcard me-2 text-primary"></i> មើលព័ត៌មាន & ប្រវត្តិ
                            </a>
                          </li>
                          <li>
                            <a class="dropdown-item" href="<?= h2($linkEdit) ?>">
                              <i class="bi bi-pencil-square me-2 text-warning"></i> កែប្រែព័ត៌មាន
                            </a>
                          </li>
                          <li><hr class="dropdown-divider"></li>
                          <li>
                            <a class="dropdown-item text-danger" href="<?= h2($linkDelete) ?>"
                               onclick="return confirm('តើអ្នកប្រាកដថាចង់លុបអតិថិជននេះឬ?');">
                              <i class="bi bi-trash3 me-2 text-danger"></i> លុបអតិថិជន
                            </a>
                          </li>
                        </ul>
                      </div>
                    </div>
                  </div>
                </div>
              </td>
            </tr>
            <?php
        }
    }
    $tableHtml = ob_get_clean();

    // Render Pagination HTML
    ob_start();
    ?>
    <div class="sub text-center" id="record-count-label">
      បង្ហាញ <?= ($totalRows ? min($totalRows, $offset + 1) : 0) ?> - <?= min($totalRows, $offset + count($rows)) ?> នៃ <?= $totalRows ?> នាក់
    </div>
    <div class="d-flex gap-2 justify-content-center">
      <a class="btn btn-outline-secondary btn-sm pagination-link <?= $page<=1?'disabled':'' ?>" data-page="<?= max(1,$page-1) ?>" href="#">
        <i class="bi bi-arrow-left"></i> មុន
      </a>
      <span class="btn btn-outline-secondary btn-sm disabled">ទំព័រ <?= $page ?> / <?= $totalPages ?></span>
      <a class="btn btn-outline-secondary btn-sm pagination-link <?= $page>=$totalPages?'disabled':'' ?>" data-page="<?= min($totalPages,$page+1) ?>" href="#">
        បន្ទាប់ <i class="bi bi-arrow-right"></i>
      </a>
    </div>
    <?php
    $paginationHtml = ob_get_clean();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'summary' => $kpiHtml,
        'table' => $tableHtml,
        'pagination' => $paginationHtml
    ]);
    exit;
}
?>
<!doctype html>
<html lang="km">
<head>
  <meta charset="utf-8">
  <title>អតិថិជន | Finance</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://fonts.googleapis.com/css2?family=Battambang:wght@300;400;700;900&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">

  <style>
    :root{
      --bg:#f3f4f6; --card:#fff; --ink:#0f172a; --muted:#64748b;
      --line:#e5e7eb;
    }
    body{font-family:'Battambang',sans-serif;background:var(--bg); color:var(--ink);}
    .page-title{font-weight:900;}
    .sub{color:var(--muted); font-size:.92rem;}
    .cardx{background:var(--card); border:1px solid rgba(148,163,184,.16); border-radius:20px; box-shadow:0 14px 34px rgba(15,23,42,.07);}

    .kpi{
      position:relative; overflow:hidden;
      border:1px solid var(--line);
      background:linear-gradient(145deg, #fff, #f8fafc);
      border-radius:18px; padding:16px 18px; height:100%;
      box-shadow:0 10px 26px rgba(15,23,42,.05);
    }
    .kpi:before{content:"";position:absolute;inset:0 auto 0 0;width:4px;background:var(--accent,#2563eb);}
    .kpi.blue{--accent:#2563eb;background:linear-gradient(145deg,#fff,#eff6ff);}
    .kpi.violet{--accent:#7c3aed;background:linear-gradient(145deg,#fff,#f5f3ff);}
    .kpi.green{--accent:#059669;background:linear-gradient(145deg,#fff,#ecfdf5);}
    .kpi.red{--accent:#dc2626;background:linear-gradient(145deg,#fff,#fef2f2);}
    .kpi-icon {
      position: absolute !important;
      right: 12px !important;
      bottom: 6px !important;
      width: auto !important;
      height: auto !important;
      background: transparent !important;
      box-shadow: none !important;
      border: none !important;
      font-size: 2.2rem !important;
      color: var(--accent) !important;
      opacity: 0.10 !important;
      pointer-events: none;
    }
    .kpi .lbl{color:var(--muted); font-size:.9rem;}
    .kpi .val{font-size:1.25rem; font-weight:900; margin-top:4px; color:#0f172a; white-space:nowrap;}
    .kpi .sub{white-space:nowrap;}
    .mono{font-variant-numeric:tabular-nums;}

    /* Avatar */
    .avatar{
      width:40px;height:40px;border-radius:999px;
      display:inline-flex;align-items:center;justify-content:center;
      border:2px solid #fff;
      background:#fff;
      position:relative;
      overflow:visible;
      box-shadow:0 0 0 1px rgba(15,23,42,.10),0 4px 12px rgba(15,23,42,.12);
      flex:0 0 auto;
    }
    .new-badge {
      position: absolute;
      top: -18px;
      left: -18px;
      width: 42px;
      height: auto;
      z-index: 5;
      animation: pulse-glow 1.5s infinite;
      pointer-events: none;
    }
    @keyframes pulse-glow {
      0% {
        transform: scale(1);
        filter: drop-shadow(0 1px 2px rgba(209, 0, 0, 0.5));
      }
      70% {
        transform: scale(1.1);
        filter: drop-shadow(0 3px 6px rgba(209, 0, 0, 0.7));
      }
      100% {
        transform: scale(1);
        filter: drop-shadow(0 1px 2px rgba(209, 0, 0, 0.5));
      }
    }
    .avatar img{width:100%;height:100%;object-fit:cover;border-radius:999px;}
    .avatar span{font-weight:900;color:#0f172a;}
    .presence-dot{position:absolute;right:-1px;bottom:0;width:11px;height:11px;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 1px rgba(15,23,42,.08);}
    .presence-dot.active{background:#22c55e;}
    .presence-dot.inactive{background:#94a3b8;}

    /* Badges */
    .badge-soft{
      border:1px solid rgba(15,23,42,.12);
      border-radius:999px;
      padding:.18rem .45rem;
      font-weight:800;
      background:#fff;
      display:inline-flex;
      align-items:center;
      gap:.35rem;
      white-space:nowrap;
      font-size:.70rem;
      line-height:1.1;
    }
    .badge-male{background:#eff6ff; border-color:#bfdbfe; color:#1d4ed8;}
    .badge-female{background:#fdf2f8; border-color:#fbcfe8; color:#9d174d;}

    /* Paid off customer styles */
    .badge-paid-off {
      background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%) !important;
      color: #14532d !important;
      border: 1px solid #86efac !important;
      font-weight: 700 !important;
      border-radius: 999px !important;
      padding: .28rem .65rem !important;
      display: inline-flex !important;
      align-items: center !important;
      gap: .3rem !important;
      font-size: .74rem !important;
      box-shadow: 0 1px 3px rgba(22, 163, 74, 0.12);
    }
    .badge-paid-off i {
      color: #16a34a !important;
      font-size: 0.82rem !important;
    }

    .paid-check-badge {
      position: absolute;
      right: -3px;
      bottom: -3px;
      width: 17px;
      height: 17px;
      border-radius: 50%;
      background: #16a34a;
      color: #fff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 11px;
      font-weight: 900;
      border: 2px solid #fff;
      box-shadow: 0 2px 5px rgba(22, 163, 74, 0.45);
      z-index: 6;
    }
    .paid-check-badge i {
      -webkit-text-stroke: 0.5px #fff;
      line-height: 1;
    }

    .table tbody tr.row-paid-off td {
      background-color: #eafbf0 !important;
    }
    .table tbody tr.row-paid-off:hover td {
      background-color: #d7f7e3 !important;
    }
    .table tbody tr.row-paid-off > td:first-child {
      border-left: 5px solid #16a34a !important;
      position: relative;
    }

    .m-card.card-paid-off {
      border-left: 5px solid #16a34a !important;
      background: #eafbf0 !important;
      box-shadow: 0 4px 15px rgba(22, 163, 74, 0.14), 0 10px 24px rgba(22, 34, 51, .06) !important;
    }

    /* Icon buttons */
    .btn-icon{
      width:38px;height:38px;
      display:inline-flex;align-items:center;justify-content:center;
      border-radius:12px;
      border:1px solid rgba(15,23,42,.12);
      background:#fff;
      color:#0f172a;
      text-decoration:none;
      transition:transform .16s ease,box-shadow .16s ease,background .16s ease;
    }
    .btn-icon:hover{transform:translateY(-2px);box-shadow:0 7px 16px rgba(15,23,42,.12);}
    .btn-icon.suc{background:#ecfdf5; border-color:#bbf7d0; color:#166534;}
    .btn-icon.pri{background:#eff6ff; border-color:#bfdbfe; color:#1d4ed8;}
    .btn-icon.war{background:#fffbeb; border-color:#fde68a; color:#a16207;}
    .btn-new-loan {
      background: #eff6ff !important;
      border: 1px solid #93c5fd !important;
      color: #1d4ed8 !important;
      font-weight: 700 !important;
      border-radius: 8px !important;
      transition: all 0.2s ease;
    }
    .btn-new-loan:hover {
      background: #2563eb !important;
      border-color: #2563eb !important;
      color: #ffffff !important;
      box-shadow: 0 4px 10px rgba(37, 99, 235, 0.25);
    }

    .namecell{display:flex; align-items:center; gap:.65rem;}
    .nwrap{line-height:1.3;}
    .nwrap .n{font-weight:900; margin-bottom:4px;}
    .nwrap .p{color:var(--muted); font-size:.9rem; display:flex; gap:.35rem; align-items:center;}
    .money{color:#047857;font-weight:900;white-space:nowrap;}
    .filter-title{display:flex;align-items:center;gap:.55rem;font-weight:900;margin-bottom:.8rem;color:#334155;}
    .filter-title i{color:#2563eb;}
    .form-control,.form-select,.input-group-text{border-color:#dbe3ee;min-height:44px;}
    .form-control:focus,.form-select:focus{border-color:#60a5fa;box-shadow:0 0 0 .22rem rgba(37,99,235,.10);}

    .table{border-collapse:separate; border-spacing:0;}
    .table tr{transition:background .15s ease;}
    .table td{border-bottom:1px solid rgba(148,163,184,.12);padding-top:.85rem;padding-bottom:.85rem;}
    .table tr:last-child td{border-bottom:none;}
    .table thead th{background:#0f5fe6 !important;color:#ffffff !important;font-size:.88rem;font-weight:700;border-bottom:none;padding-top:.95rem;padding-bottom:.95rem;}
    .table thead th:first-child { border-top-left-radius: 18px !important; }
    .table thead th:last-child { border-top-right-radius: 18px !important; }
    .table tbody tr:last-child td:first-child { border-bottom-left-radius: 18px !important; }
    .table tbody tr:last-child td:last-child { border-bottom-right-radius: 18px !important; }
    .table tbody tr{transition:background .15s ease;}
    .table tbody tr:hover{background:#f8fbff;}
    .table tbody td{border-color:#e8edf4;padding-top:.72rem;padding-bottom:.72rem;}
    
    .flatpickr-calendar, .flatpickr-calendar * { font-family:'Battambang',sans-serif !important; font-size:.92rem; }

    /* Mobile card row */
    .m-card{
      border:1px solid rgba(15,23,42,.10);
      border-radius:18px;
      background:#fff;
      padding:.9rem .9rem;
      box-shadow:0 10px 24px rgba(22,34,51,.06);
    }
    .m-top{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:.75rem;
    }
    .m-left{
      display:flex;
      gap:.65rem;
      align-items:flex-start;
      min-width:0;
      flex:1;
    }
    .m-title{min-width:0; flex:1;}
    .m-title .name{
      font-weight:900;
      font-size:1.05rem;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
      margin:0;
    }
    .m-sub{
      margin-top:.2rem;
      color:var(--muted);
      font-size:.92rem;
      display:flex;
      align-items:center;
      gap:.45rem;
      flex-wrap:wrap;
    }
    .m-mini{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap:.6rem;
      margin-top:.7rem;
    }
    .mini-box{
      border:1px solid rgba(15,23,42,.10);
      border-radius:14px;
      padding:.55rem .6rem;
      background:linear-gradient(180deg,#fff,#fbfdff);
    }
    .mini-box .lbl{color:var(--muted); font-size:.85rem; display:flex; align-items:center; gap:.35rem;}
    .mini-box .val{font-weight:normal; font-size:1.05rem;}

    .m-actionbar{
      border-top:1px solid rgba(15,23,42,.10);
      margin-top:.65rem;
      padding-top:.6rem;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:.6rem;
    }
    .m-rowno{
      color:#94a3b8;
      font-weight:900;
      font-size:.8rem;
      padding-left:.5rem;
    }
    .m-actions{
      display:flex;
      gap:.45rem;
      align-items:center;
    }

    @media (max-width: 767.98px){
      .table-responsive{padding: .4rem;}
      .table{margin-bottom:0;}
      .table thead{display:none;}
      .table tbody tr{border:none;}
      .table tbody td{border:none !important;}
    }

    @media (min-width: 992px) {
      .filter-flex .form-control,
      .filter-flex .form-select,
      .filter-flex .input-group-text,
      .filter-flex .btn {
        font-size: 0.85rem !important;
        padding-left: 6px !important;
        padding-right: 6px !important;
      }
    }

    @media (min-width: 992px) and (max-width: 1200px) {
      .kpi .val { font-size: 1.05rem !important; }
      .kpi { padding: 12px 10px !important; }
    }
  </style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="container py-4">

  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
      <h4 class="page-title mb-1"><i class="bi bi-people-fill me-2"></i>អតិថិជន</h4>
      <div class="sub">ស្វែងរក / គ្រប់គ្រងអតិថិជន</div>
    </div>

    <div class="d-flex gap-2 flex-wrap">
      <a href="customer_add.php" class="btn btn-primary" style="border-radius:12px;">
        <i class="bi bi-plus-lg me-1"></i> បន្ថែមអតិថិជន
      </a>
    </div>
  </div>

  <!-- Summary -->
  <div class="row g-3 mb-3" id="summary-kpi-container">
    <div class="col-12 col-sm-6 col-lg">
      <div class="kpi blue">
        <div class="lbl"><i class="bi bi-people-fill me-1"></i> អតិថិជនសរុប & ចំនួនកម្ចី</div>
        <span class="kpi-icon float-end"><i class="bi bi-person-badge"></i></span>
        <div class="val mono"><?= (int)$totalRows ?> នាក់</div>
        <div class="sub">សរុបកម្ចី: <?= (int)$sumLoans ?> កម្ចី</div>
      </div>
    </div>
    <div class="col-12 col-sm-6 col-lg">
      <div class="kpi violet">
        <div class="lbl"><i class="bi bi-hourglass-split me-1"></i> កំពុងជំពាក់ (ដុល្លារ)</div>
        <span class="kpi-icon float-end"><i class="bi bi-currency-dollar"></i></span>
        <div class="val mono">$ <?= fin_money($sumOutstandingUSD) ?></div>
        <div class="sub">សរុប: <?= (int)$sumActiveUSDCount ?> កម្ចី</div>
      </div>
    </div>
    <div class="col-12 col-sm-6 col-lg">
      <div class="kpi violet">
        <div class="lbl"><i class="bi bi-hourglass-split me-1"></i> កំពុងជំពាក់ (រៀល)</div>
        <span class="kpi-icon float-end"><i class="bi bi-currency-exchange"></i></span>
        <div class="val mono"><?= fin_money_khr($sumOutstandingKHR) ?></div>
        <div class="sub">សរុប: <?= (int)$sumActiveKHRCount ?> កម្ចី</div>
      </div>
    </div>
    <div class="col-12 col-sm-6 col-lg">
      <div class="kpi red">
        <div class="lbl"><i class="bi bi-x-circle me-1"></i> កម្ចីកាត់ចោល</div>
        <span class="kpi-icon float-end"><i class="bi bi-trash3"></i></span>
        <div class="val mono"><?= (int)$writeOffCount ?> កម្ចី</div>
        <div class="sub">សរុប: $<?= fin_money($writeOffUSDAmt) ?> • ៛<?= fin_money($writeOffKHRAmt) ?></div>
      </div>
    </div>
    <div class="col-12 col-sm-6 col-lg">
      <div class="kpi green">
        <div class="lbl"><i class="bi bi-check-circle me-1"></i> បង់ផ្តាច់រួច</div>
        <span class="kpi-icon float-end"><i class="bi bi-shield-check"></i></span>
        <div class="val mono"><?= (int)$closedCount ?> កម្ចី</div>
        <div class="sub">សរុប: $<?= fin_money($closedUSDAmt) ?> • ៛<?= fin_money($closedKHRAmt) ?></div>
      </div>
    </div>
  </div>

  <!-- Filters -->
  <div class="cardx p-3 mb-3">
    <div class="filter-title mb-2"><i class="bi bi-sliders2"></i> តម្រងស្វែងរកអតិថិជន</div>
    <form id="filterForm" onsubmit="return false;" class="filter-flex">
      <div class="row g-2 align-items-end">
        <div class="col-6 col-md-3 col-lg-2 f-status">
          <label class="form-label mb-1">ស្ថានភាព</label>
          <select class="form-select" name="status_detail" id="filterStatus">
            <option value="">ទាំងអស់</option>
            <?php foreach ($statusOptions as $val => $lbl): ?>
              <option value="<?= h2($val) ?>" <?= ($statusDetail===$val?'selected':'') ?>><?= h2($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-3 col-lg-2 f-period">
          <label class="form-label mb-1">រយៈពេល</label>
          <select class="form-select" name="period" id="filterPeriod">
            <option value="all" <?= $period==='all'?'selected':''; ?>>ទាំងអស់</option>
            <option value="this_month" <?= $period==='this_month'?'selected':''; ?>>ខែនេះ</option>
            <option value="last_month" <?= $period==='last_month'?'selected':''; ?>>ខែមុន</option>
            <option value="3_months" <?= $period==='3_months'?'selected':''; ?>>៣ខែ</option>
            <option value="6_months" <?= $period==='6_months'?'selected':''; ?>>៦ខែ</option>
            <option value="1_year" <?= $period==='1_year'?'selected':''; ?>>១ឆ្នាំ</option>
            <option value="last_year" <?= $period==='last_year'?'selected':''; ?>>ឆ្នាំមុន</option>
            <option value="custom" <?= $period==='custom'?'selected':''; ?>>Custom</option>
          </select>
        </div>
        <div class="col-6 col-md-3 col-lg-2 f-date <?= $period === 'custom' ? '' : 'd-none' ?>" id="fromPickerWrapper">
          <label class="form-label mb-1"><i class="bi bi-calendar-event me-1 text-primary"></i>ចាប់ពីថ្ងៃ</label>
          <input type="text" id="fromPicker" class="form-control bg-white" name="date_from" value="<?= h2($dateFrom) ?>" placeholder="ថ្ងៃ-ខែ-ឆ្នាំ" <?= $colCreated ? '' : 'disabled'; ?>>
        </div>
        <div class="col-6 col-md-3 col-lg-2 f-date <?= $period === 'custom' ? '' : 'd-none' ?>" id="toPickerWrapper">
          <label class="form-label mb-1"><i class="bi bi-calendar-check me-1 text-primary"></i>ដល់ថ្ងៃ</label>
          <input type="text" id="toPicker" class="form-control bg-white" name="date_to" value="<?= h2($dateTo) ?>" placeholder="ថ្ងៃ-ខែ-ឆ្នាំ" <?= $colCreated ? '' : 'disabled'; ?>>
        </div>
        <div class="col-6 col-md-6 col-lg-5 f-search">
          <label class="form-label mb-1">ស្វែងរក (ឈ្មោះ/លេខទូរស័ព្ទ)</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control" name="q" id="searchQuery" value="<?= h2($q) ?>" placeholder="ឧ: Dara / 012...">
          </div>
        </div>
        <div class="col-4 col-md-4 col-lg-2 f-search-btn">
          <label class="form-label mb-1">&nbsp;</label>
          <button class="btn btn-primary w-100" id="btnSearch" style="border-radius:12px; height:44px;">
            <i class="bi bi-funnel-fill me-1"></i> ស្វែងរក
          </button>
        </div>
        <div class="col-2 col-md-2 col-lg-1 f-clear">
          <label class="form-label mb-1">&nbsp;</label>
          <button class="btn btn-outline-secondary w-100 px-0" id="btnClear" style="border-radius:12px; height:44px;" title="សំអាត">
            <i class="bi bi-arrow-counterclockwise"></i>
          </button>
        </div>
      </div>
    </form>
  </div>

  <!-- Table -->
  <div class="cardx p-0">
    <div class="table-responsive" style="overflow: visible;">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:70px;" class="text-nowrap">#</th>
            <th class="text-nowrap">អតិថិជន</th>
            <th class="d-none d-md-table-cell text-nowrap">អាសយដ្ឋាន</th>
            <th class="d-none d-md-table-cell text-nowrap">ភេទ</th>
            <th class="d-none d-md-table-cell text-nowrap">វាយតម្លៃ</th>
            <th class="text-end text-nowrap">កំពុងជំពាក់</th>
            <th class="text-end text-nowrap">ចំនួនកម្ចី</th>
            <th class="text-end text-nowrap">សកម្មភាព</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">មិនមានអតិថិជន។</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $i => $r): ?>
            <?php
              $id = (int)($r['id'] ?? 0);
              $fullName = (string)($r['full_name'] ?? '');
              $phone = (string)($r['phone'] ?? '');
              $genderV = strtolower(trim((string)($r['gender'] ?? '')));
              $is_active = (int)($r['is_active'] ?? 1);

              $photoDb = trim((string)($r['photo'] ?? ''));
              $photoUrl = $photoDb ? fin_public_url($photoDb) : '';
              $fallbackAvatar = fin_avatar_by_gender($genderV);

              $genderBadge = '<span class="badge-soft"><i class="bi bi-question-circle"></i> —</span>';
              if (in_array($genderV, ['male','m','ប្រុស'], true)) {
                $genderBadge = '<span class="badge-soft badge-male"><i class="bi bi-gender-male"></i> ប្រុស</span>';
              } elseif (in_array($genderV, ['female','f','ស្រី'], true)) {
                $genderBadge = '<span class="badge-soft badge-female"><i class="bi bi-gender-female"></i> ស្រី</span>';
              }

              $outUsd = isset($r['outstanding_usd']) ? (float)$r['outstanding_usd'] : 0.0;
              $outKhr = isset($r['outstanding_khr']) ? (float)$r['outstanding_khr'] : 0.0;
              $closedUsd = isset($r['closed_usd']) ? (float)$r['closed_usd'] : 0.0;
              $closedKhr = isset($r['closed_khr']) ? (float)$r['closed_khr'] : 0.0;
              $loanCount = isset($r['loan_count']) ? (int)$r['loan_count'] : 0;
              $rating    = fin_customer_rating($r);
              $hasNoDebt = ($outUsd <= 0.0001 && $outKhr <= 0.0001);
              $hasPastLoans = ($closedUsd > 0.0001 || $closedKhr > 0.0001 || $loanCount > 0);
              $isPaidOff = ($rating['rating'] === 'paid_off') || ($hasNoDebt && $hasPastLoans && $rating['rating'] !== 'bad');

              $rowNo = ($offset + $i + 1);
            $isNewCustomer = false;
            if (!empty($r['created_at'])) {
                $createdAt = strtotime($r['created_at']);
                if ($createdAt !== false && (time() - $createdAt) <= (30 * 86400)) {
                    $isNewCustomer = true;
                }
            }

              $linkAddLoan   = "loan_add.php?customer_id={$id}";
              $linkViewLoans = "loans.php?customer_id={$id}";
              $linkSummary   = "loans.php?customer_id={$id}&view=summary";
              $linkEdit      = "customer_edit.php?id={$id}";
              $linkDelete    = "customer_delete.php?id={$id}";
            ?>

            <!-- ===================== DESKTOP ROW ===================== -->
            <tr class="d-none d-md-table-row<?= $isPaidOff ? ' row-paid-off' : '' ?>">
              <td class="mono"><?= $rowNo ?></td>

              <td>
                <div class="namecell">
                  <div class="avatar">
                    <?php if ($photoUrl !== ''): ?>
                      <img src="<?= h2($photoUrl) ?>" alt="<?= h2($fullName) ?>" onerror="this.src='<?= h2($fallbackAvatar) ?>'">
                    <?php else: ?>
                      <img src="<?= h2($fallbackAvatar) ?>" alt="avatar">
                    <?php endif; ?>
                    <?php if ($isPaidOff): ?>
                      <span class="paid-check-badge" title="អតិថិជនបានបង់ផ្តាច់រួច"><i class="bi bi-check-lg"></i></span>
                    <?php elseif ($colActive): ?>
                      <span class="presence-dot <?= $is_active ? 'active' : 'inactive' ?>" title="<?= $is_active ? 'Active' : 'Inactive' ?>"></span>
                    <?php endif; ?>
                      <?php if ($isNewCustomer): ?>
                      <svg class="new-badge" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="42" height="42">
                        <polygon points="50,18 54.7,26.21 62.42,19.09 63.78,27.83 74,22.29 71.92,30.96 83.94,27.37 78.56,35.39 91.57,34 83.26,40.82 96.36,41.72 85.69,46.87 98,50 85.69,53.13 96.36,58.28 83.26,59.18 91.57,66 78.56,64.61 83.94,72.63 71.92,69.04 74,77.71 63.78,72.17 62.42,80.91 54.7,73.79 50,82 45.3,73.79 37.58,80.91 36.22,72.17 26,77.71 28.08,69.04 16.06,72.63 21.44,64.61 8.43,66 16.74,59.18 3.64,58.28 14.31,53.13 2,50 14.31,46.87 3.64,41.72 16.74,40.82 8.43,34 21.44,35.39 16.06,27.37 28.08,30.96 26,22.29 36.22,27.83 37.58,19.09 45.3,26.21" fill="#d10000" />
                        <text x="50" y="56" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif" font-weight="900" font-size="18" fill="#ffffff" text-anchor="middle" letter-spacing="0.5">NEW</text>
                      </svg>
                    <?php endif; ?>
                  </div>

                  <div class="nwrap text-nowrap">
                    <div class="n text-nowrap">
                      <a href="customer_view.php?id=<?= $id ?>" class="text-dark text-decoration-none fw-bold" title="មើលព័ត៌មាន និងប្រវត្តិកម្ចី"><?= h2($fullName) ?></a>
                      <?php if ($isPaidOff): ?>
                        <i class="bi bi-patch-check-fill text-success ms-1" style="font-size: 0.95rem; vertical-align: middle;" title="អតិថិជនបានបង់ផ្តាច់កម្ចីរួចរាល់"></i>
                      <?php endif; ?>
                    </div>

                    <div class="p text-nowrap">
                      <i class="bi bi-telephone-fill"></i>
                      <span class="mono"><?= h2($phone) ?></span>
                    </div>
                  </div>
                </div>
              </td>

              <td class="d-none d-md-table-cell text-nowrap"><?= h2($r['address'] ?? '') ?></td>
              <td class="d-none d-md-table-cell text-nowrap"><?= $genderBadge ?></td>
              <td class="d-none d-md-table-cell text-nowrap">
                <?php if ($isPaidOff && $rating['rating'] !== 'bad'): ?>
                  <span class="badge badge-paid-off" title="បានបង់ផ្តាច់កម្ចីរួចរាល់ទាំងអស់"><i class="bi bi-patch-check-fill me-1"></i>បង់ផ្តាច់រួច</span>
                <?php else: ?>
                  <span class="badge <?= $rating['badge'] ?>" title="<?= h2($rating['desc']) ?>"><?= h2($rating['text']) ?></span>
                <?php endif; ?>
              </td>

              <td class="text-end mono money text-nowrap">
                <?php if ($isPaidOff): ?>
                  <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.78rem; font-weight: 700; padding: 3px 8px; border-radius: 999px;">
                    <i class="bi bi-check-circle-fill me-1"></i>0 ៛ (បង់ផ្តាច់)
                  </span>
                  <?php if ($closedUsd > 0 || $closedKhr > 0): ?>
                    <div class="text-success small fw-normal mt-1" style="font-size: 0.74rem;" title="កម្ចីមុនដែលបានបង់ផ្តាច់រួច">
                      <i class="bi bi-check2-circle"></i> កម្ចីមុន: <?= fin_format_outstanding($closedUsd, $closedKhr) ?>
                    </div>
                  <?php endif; ?>
                <?php else: ?>
                  <?= fin_format_outstanding($outUsd, $outKhr) ?>
                  <?php if ($outUsd <= 0 && $outKhr <= 0 && ($closedUsd > 0 || $closedKhr > 0)): ?>
                    <div class="text-success small fw-normal" style="font-size: 0.75rem; margin-top: 1px;" title="កម្ចីមុនដែលបានបង់ផ្តាច់រួច">
                      <i class="bi bi-check2-circle"></i> កម្ចីមុន: <?= fin_format_outstanding($closedUsd, $closedKhr) ?>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <td class="text-end mono text-nowrap"><?= (int)$loanCount ?></td>

              <td class="text-end text-nowrap">
                <div class="d-inline-flex justify-content-end gap-2 text-nowrap">
                  <a class="btn-icon pri text-nowrap" href="<?= h2($linkViewLoans) ?>" title="មើលបញ្ជីកម្ចី"><i class="bi bi-folder2-open"></i></a>
                  <a class="btn-icon <?= $isPaidOff ? 'btn-new-loan' : 'suc' ?> text-nowrap" href="<?= h2($linkAddLoan) ?>" title="<?= $isPaidOff ? 'ស្នើកម្ចីថ្មី' : 'បន្ថែមកម្ចី' ?>"><i class="bi bi-plus-circle"></i></a>

                  <div class="dropdown">
                    <button class="btn-icon text-nowrap" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="ផ្សេងៗ">
                      <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                      <?php if ($isPaidOff): ?>
                      <li>
                        <a class="dropdown-item text-primary fw-bold" href="<?= h2($linkAddLoan) ?>">
                          <i class="bi bi-plus-circle me-2 text-primary"></i> ស្នើកម្ចីថ្មី
                        </a>
                      </li>
                      <li><hr class="dropdown-divider"></li>
                      <?php endif; ?>
                      <li>
                        <a class="dropdown-item" href="customer_view.php?id=<?= $id ?>">
                          <i class="bi bi-person-vcard me-2 text-primary"></i> មើលព័ត៌មាន & ប្រវត្តិ
                        </a>
                      </li>
                      <li>
                        <a class="dropdown-item" href="<?= h2($linkEdit) ?>">
                          <i class="bi bi-pencil-square me-2 text-warning"></i> កែប្រែព័ត៌មាន
                        </a>
                      </li>
                      <li><hr class="dropdown-divider"></li>
                      <li>
                        <a class="dropdown-item text-danger" href="<?= h2($linkDelete) ?>"
                           onclick="return confirm('តើអ្នកប្រាកដថាចង់លុបអតិថិជននេះឬ?');">
                          <i class="bi bi-trash3 me-2 text-danger"></i> លុបអតិថិជន
                        </a>
                      </li>
                    </ul>
                  </div>
                </div>
              </td>
            </tr>

            <!-- ===================== MOBILE CARD ROW ===================== -->
            <tr class="d-md-none">
              <td colspan="8">
                <div class="m-card<?= $isPaidOff ? ' card-paid-off' : '' ?>">

                                    <div class="m-top d-flex justify-content-between align-items-start gap-2">
                    <div class="m-left d-flex gap-2 align-items-start" style="min-width: 0; flex: 1;">
                      <div class="avatar">
                        <?php if ($photoUrl !== ''): ?>
                          <img src="<?= h2($photoUrl) ?>" alt="<?= h2($fullName) ?>" onerror="this.src='<?= h2($fallbackAvatar) ?>'">
                        <?php else: ?>
                          <img src="<?= h2($fallbackAvatar) ?>" alt="avatar">
                        <?php endif; ?>
                        <?php if ($isPaidOff): ?>
                          <span class="paid-check-badge" title="អតិថិជនបានបង់ផ្តាច់រួច"><i class="bi bi-check-lg"></i></span>
                        <?php elseif ($colActive): ?>
                          <span class="presence-dot <?= $is_active ? 'active' : 'inactive' ?>" title="<?= $is_active ? 'Active' : 'Inactive' ?>"></span>
                        <?php endif; ?>
                          <?php if ($isNewCustomer): ?>
                      <svg class="new-badge" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="42" height="42">
                        <polygon points="50,18 54.7,26.21 62.42,19.09 63.78,27.83 74,22.29 71.92,30.96 83.94,27.37 78.56,35.39 91.57,34 83.26,40.82 96.36,41.72 85.69,46.87 98,50 85.69,53.13 96.36,58.28 83.26,59.18 91.57,66 78.56,64.61 83.94,72.63 71.92,69.04 74,77.71 63.78,72.17 62.42,80.91 54.7,73.79 50,82 45.3,73.79 37.58,80.91 36.22,72.17 26,77.71 28.08,69.04 16.06,72.63 21.44,64.61 8.43,66 16.74,59.18 3.64,58.28 14.31,53.13 2,50 14.31,46.87 3.64,41.72 16.74,40.82 8.43,34 21.44,35.39 16.06,27.37 28.08,30.96 26,22.29 36.22,27.83 37.58,19.09 45.3,26.21" fill="#d10000" />
                        <text x="50" y="56" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif" font-weight="900" font-size="18" fill="#ffffff" text-anchor="middle" letter-spacing="0.5">NEW</text>
                      </svg>
                    <?php endif; ?>
                  </div>
                      <div class="m-title" style="min-width: 0; flex: 1;">
                        <p class="name mb-0" style="font-weight: 900; font-size: 1.05rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                          <a href="customer_view.php?id=<?= $id ?>" class="text-dark text-decoration-none" title="មើលព័ត៌មាន និងប្រវត្តិកម្ចី"><?= h2($fullName) ?></a>
                          <?php if ($isPaidOff): ?>
                            <i class="bi bi-patch-check-fill text-success ms-1" style="font-size: 0.95rem; vertical-align: middle;" title="អតិថិជនបានបង់ផ្តាច់កម្ចីរួចរាល់"></i>
                          <?php endif; ?>
                        </p>
                        <div class="m-sub mt-1">
                          <?= $genderBadge ?>
                        </div>
                      </div>
                    </div>
                    <div class="m-right text-end d-flex flex-column align-items-end" style="flex-shrink: 0; line-height: 1.2;">
                      <div class="mono" style="font-size: 0.88rem; font-weight: 700; color: #475569; display: inline-flex; align-items: center; gap: 4px;">
                        <i class="bi bi-telephone-fill" style="color: #3b82f6; font-size: 0.8rem;"></i>
                        <a href="tel:<?= h2($phone) ?>" style="text-decoration: none; color: inherit;"><?= h2($phone) ?></a>
                      </div>
                      <div class="mt-1">
                        <?php if ($isPaidOff && $rating['rating'] !== 'bad'): ?>
                          <span class="badge badge-paid-off" title="បានបង់ផ្តាច់កម្ចីរួចរាល់ទាំងអស់" style="font-size: 0.72rem;"><i class="bi bi-patch-check-fill me-1"></i>បង់ផ្តាច់រួច</span>
                        <?php else: ?>
                          <span class="badge <?= $rating['badge'] ?>" title="<?= h2($rating['desc']) ?>" style="font-size: 0.72rem;"><?= h2($rating['text']) ?></span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>

                  <div class="m-mini">
                    <div class="mini-box">
                      <div class="lbl"><i class="bi bi-hourglass-split"></i> កំពុងជំពាក់</div>
                      <div class="val mono money">
                        <?php if ($isPaidOff): ?>
                          <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.76rem; font-weight: 700; padding: 2px 7px; border-radius: 999px;">
                            <i class="bi bi-check-circle-fill me-1"></i>0 ៛ (បង់ផ្តាច់)
                          </span>
                          <?php if ($closedUsd > 0 || $closedKhr > 0): ?>
                            <div class="text-success small fw-normal mt-1" style="font-size: 0.72rem;" title="កម្ចីមុនដែលបានបង់ផ្តាច់រួច">
                              <i class="bi bi-check2-circle"></i> កម្ចីមុន: <?= fin_format_outstanding($closedUsd, $closedKhr) ?>
                            </div>
                          <?php endif; ?>
                        <?php else: ?>
                          <?= fin_format_outstanding($outUsd, $outKhr) ?>
                          <?php if ($outUsd <= 0 && $outKhr <= 0 && ($closedUsd > 0 || $closedKhr > 0)): ?>
                            <div class="text-success small fw-normal" style="font-size: 0.72rem; margin-top: 1px;" title="កម្ចីមុនដែលបានបង់ផ្តាច់រួច">
                              <i class="bi bi-check2-circle"></i> កម្ចីមុន: <?= fin_format_outstanding($closedUsd, $closedKhr) ?>
                            </div>
                          <?php endif; ?>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="mini-box">
                      <div class="lbl"><i class="bi bi-cash-coin"></i> ចំនួនកម្ចី</div>
                      <div class="val mono"><?= (int)$loanCount ?></div>
                    </div>
                  </div>

                                    <div class="m-actionbar text-nowrap">
                    <span class="m-rowno mono"><?= $rowNo ?></span>
                    <div class="m-actions text-nowrap">
                      <a class="btn-icon pri text-nowrap px-2" style="width: auto; height: 32px; border-radius: 8px; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; text-decoration: none;" href="<?= h2($linkViewLoans) ?>"><i class="bi bi-folder2-open" style="font-size: 0.95rem;"></i> មើលកម្ចី</a>
                      <a class="btn-icon <?= $isPaidOff ? 'btn-new-loan' : 'suc' ?> text-nowrap px-2" style="width: auto; height: 32px; border-radius: 8px; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; text-decoration: none;" href="<?= h2($linkAddLoan) ?>"><i class="bi bi-plus-circle" style="font-size: 0.95rem;"></i> <?= $isPaidOff ? 'ស្នើកម្ចីថ្មី' : 'បន្ថែមកម្ចី' ?></a>
                      <div class="dropdown">
                        <button class="btn-icon" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="ផ្សេងៗ">
                          <i class="bi bi-three-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                          <?php if ($isPaidOff): ?>
                          <li>
                            <a class="dropdown-item text-primary fw-bold" href="<?= h2($linkAddLoan) ?>">
                              <i class="bi bi-plus-circle me-2 text-primary"></i> ស្នើកម្ចីថ្មី
                            </a>
                          </li>
                          <li><hr class="dropdown-divider"></li>
                          <?php endif; ?>
                          <li>
                            <a class="dropdown-item" href="customer_view.php?id=<?= $id ?>">
                              <i class="bi bi-person-vcard me-2 text-primary"></i> មើលព័ត៌មាន & ប្រវត្តិ
                            </a>
                          </li>
                          <li>
                            <a class="dropdown-item" href="<?= h2($linkEdit) ?>">
                              <i class="bi bi-pencil-square me-2 text-warning"></i> កែប្រែព័ត៌មាន
                            </a>
                          </li>
                          <li><hr class="dropdown-divider"></li>
                          <li>
                            <a class="dropdown-item text-danger" href="<?= h2($linkDelete) ?>"
                               onclick="return confirm('តើអ្នកប្រាកដថាចង់លុបអតិថិជននេះឬ?');">
                              <i class="bi bi-trash3 me-2 text-danger"></i> លុបអតិថិជន
                            </a>
                          </li>
                        </ul>
                      </div>
                    </div>
                  </div>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Pagination -->
  <div id="pagination-container" class="d-flex flex-column align-items-center justify-content-center gap-2 mt-3">
      <div class="sub text-center" id="record-count-label">
        បង្ហាញ <?= ($totalRows ? min($totalRows, $offset + 1) : 0) ?> - <?= min($totalRows, $offset + count($rows)) ?> នៃ <?= $totalRows ?> នាក់
      </div>
      <div class="d-flex gap-2 justify-content-center">
        <a class="btn btn-outline-secondary btn-sm pagination-link <?= $page<=1?'disabled':'' ?>" data-page="<?= max(1,$page-1) ?>" href="#">
          <i class="bi bi-arrow-left"></i> មុន
        </a>
        <span class="btn btn-outline-secondary btn-sm disabled">ទំព័រ <?= $page ?> / <?= $totalPages ?></span>
        <a class="btn btn-outline-secondary btn-sm pagination-link <?= $page>=$totalPages?'disabled':'' ?>" data-page="<?= min($totalPages,$page+1) ?>" href="#">
          បន្ទាប់ <i class="bi bi-arrow-right"></i>
        </a>
      </div>
    </div>
  </div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script>
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

  // AJAX Live Search and Real-Time KPI Aggregation
  document.addEventListener('DOMContentLoaded', function() {
    const queryInput = document.getElementById('searchQuery');
    const genderSelect = document.getElementById('filterGender');
    const statusSelect = document.getElementById('filterStatus');
    const periodSelect = document.getElementById('filterPeriod');
    const fromInput = document.getElementById('fromPicker');
    const toInput = document.getElementById('toPicker');
    const fromWrapper = document.getElementById('fromPickerWrapper');
    const toWrapper = document.getElementById('toPickerWrapper');
    const btnSearch = document.getElementById('btnSearch');
    const btnClear = document.getElementById('btnClear');

    let debounceTimeout = null;

    function applyFilters(page = 1) {
      const q = queryInput ? queryInput.value.trim() : '';
      const gender = genderSelect ? genderSelect.value : '';
      const status_detail = statusSelect ? statusSelect.value : '';
      const period = periodSelect ? periodSelect.value : 'all';
      const date_from = fromInput ? fromInput.value : '';
      const date_to = toInput ? toInput.value : '';

      const url = 'customers.php?ajax=1&page=' + page +
                  '&q=' + encodeURIComponent(q) +
                  '&gender=' + encodeURIComponent(gender) +
                  '&status_detail=' + encodeURIComponent(status_detail) +
                  '&period=' + encodeURIComponent(period) +
                  '&date_from=' + encodeURIComponent(date_from) +
                  '&date_to=' + encodeURIComponent(date_to);

      fetch(url)
        .then(res => res.json())
        .then(data => {
          const summaryContainer = document.getElementById('summary-kpi-container');
          const tbody = document.querySelector('tbody');
          const paginationContainer = document.getElementById('pagination-container');

          if (summaryContainer && data.summary) summaryContainer.innerHTML = data.summary;
          if (tbody && data.table) tbody.innerHTML = data.table;
          if (paginationContainer && data.pagination) paginationContainer.innerHTML = data.pagination;
        })
        .catch(err => console.error('Error fetching filtered customers:', err));
    }

    // Attach listeners for live changes
    if (queryInput) {
      queryInput.addEventListener('input', function() {
        clearTimeout(debounceTimeout);
        debounceTimeout = setTimeout(() => applyFilters(1), 250);
      });
    }

    if (btnSearch) {
      btnSearch.addEventListener('click', function(e) {
        e.preventDefault();
        applyFilters(1);
      });
    }

    if (genderSelect) {
      genderSelect.addEventListener('change', () => applyFilters(1));
    }

    if (statusSelect) {
      statusSelect.addEventListener('change', () => applyFilters(1));
    }

    if (periodSelect) {
      periodSelect.addEventListener('change', function() {
        if (this.value === 'custom') {
          if (fromWrapper) fromWrapper.classList.remove('d-none');
          if (toWrapper) toWrapper.classList.remove('d-none');
        } else {
          if (fromWrapper) fromWrapper.classList.add('d-none');
          if (toWrapper) toWrapper.classList.add('d-none');
          if (fromInput) {
            if (fromInput._flatpickr) fromInput._flatpickr.clear();
            else fromInput.value = '';
          }
          if (toInput) {
            if (toInput._flatpickr) toInput._flatpickr.clear();
            else toInput.value = '';
          }
        }
        applyFilters(1);
      });
    }

    if (btnClear) {
      btnClear.addEventListener('click', function() {
        if (queryInput) queryInput.value = '';
        if (genderSelect) genderSelect.value = '';
        if (statusSelect) statusSelect.value = '';
        if (periodSelect) periodSelect.value = 'all';
        if (fromInput) {
          if (fromInput._flatpickr) fromInput._flatpickr.clear();
          else fromInput.value = '';
        }
        if (toInput) {
          if (toInput._flatpickr) toInput._flatpickr.clear();
          else toInput.value = '';
        }
        if (fromWrapper) fromWrapper.classList.add('d-none');
        if (toWrapper) toWrapper.classList.add('d-none');
        applyFilters(1);
      });
    }

    // Hook Flatpickr onChange callbacks
    setTimeout(function() {
      if (fromInput && fromInput._flatpickr) {
        fromInput._flatpickr.set('onChange', () => applyFilters(1));
      }
      if (toInput && toInput._flatpickr) {
        toInput._flatpickr.set('onChange', () => applyFilters(1));
      }
    }, 100);

    // Event delegation for AJAX pagination clicks
    document.addEventListener('click', function(e) {
      const link = e.target.closest('.pagination-link');
      if (link) {
        e.preventDefault();
        if (link.classList.contains('disabled')) return;
        const page = link.getAttribute('data-page');
        if (page) {
          applyFilters(page);
        }
      }
    });
  });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
