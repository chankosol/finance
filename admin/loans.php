<?php
// /finance/admin/loans.php
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

fin_require_login();
fin_require_permission('view_loans');
$business_id = fin_require_business();
global $pdo;

if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

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

/* ===================== DB helpers ===================== */
function fin_table_exists(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
  ");
  $st->execute([$table]);
  return (int)$st->fetchColumn() > 0;
}
function fin_col_exists(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
  ");
  $st->execute([$table, $col]);
  return (int)$st->fetchColumn() > 0;
}
function fin_first_col(PDO $pdo, string $table, array $candidates, string $fallback=''): string {
  foreach ($candidates as $c) if ($c && fin_col_exists($pdo, $table, $c)) return $c;
  return $fallback;
}
function fin_money($n): string { return number_format((float)$n, 2); }
function fin_int($n): string { return number_format((int)$n); }
function fin_initials(string $name): string {
  $name = trim(preg_replace('/\s+/', ' ', $name));
  if ($name === '') return 'NA';
  $parts = explode(' ', $name);
  $first = mb_substr($parts[0] ?? 'N', 0, 1);
  $last  = mb_substr($parts[count($parts)-1] ?? 'A', 0, 1);
  return strtoupper($first.$last);
}
function fin_ccy_symbol(string $ccy): string {
  $ccy = strtoupper(trim($ccy));
  if ($ccy === '$' || $ccy === 'USD') return '$';
  if ($ccy === '៛' || $ccy === 'KHR') return '៛';
  return '';
}
function fin_format_amount($amt, $ccy): string {
  $ccy = strtoupper(trim($ccy));
  if ($ccy === 'KHR' || $ccy === '៛') {
    return number_format((float)$amt, 0) . ' ៛';
  }
  return '$ ' . number_format((float)$amt, 2);
}

/* ===================== IMAGE FIX (IMPORTANT) ===================== */
function fin_img_url(?string $path): string {
  $p = trim((string)$path);
  if ($p === '') return '';

  if (preg_match('#^https?://#i', $p)) return $p;

  $p = str_replace('\\','/',$p);
  $p = preg_replace('#^\./#','',$p);
  $p = preg_replace('#^/?finance/#i','',$p);

  if (str_starts_with($p, '/')) return $p;
  if (str_starts_with($p, 'uploads/')) return '../' . $p;
  if (str_starts_with($p, 'customers/')) return '../uploads/' . $p;

  return '../uploads/customers/' . ltrim($p,'/');
}
function fin_default_avatar(?string $gender): string {
  $g = mb_strtolower(trim((string)$gender));
  if (in_array($g, ['male','m','ប្រុស','បុរស'], true)) return '../uploads/avatars/male.png';
  if (in_array($g, ['female','f','ស្រី','នារី'], true)) return '../uploads/avatars/female.png';
  return '../uploads/avatars/default.png';
}

/* -------------------- safety -------------------- */
if (!fin_table_exists($pdo, 'loans')) { http_response_code(500); exit('Missing table: loans'); }
if (!fin_table_exists($pdo, 'customers')) { http_response_code(500); exit('Missing table: customers'); }

/* -------------------- detect columns -------------------- */
$loansBizCol = fin_col_exists($pdo,'loans','business_id') ? 'business_id' : (fin_col_exists($pdo,'loans','biz_id') ? 'biz_id' : 'business_id');

$col_id              = 'id';
$col_customer_id     = fin_first_col($pdo,'loans',['customer_id'],'customer_id');
$col_loan_code       = fin_col_exists($pdo,'loans','loan_code') ? 'loan_code' : '';
$col_currency        = fin_col_exists($pdo,'loans','currency_code') ? 'currency_code' : (fin_col_exists($pdo,'loans','currency') ? 'currency' : '');
$col_principal_amt   = fin_col_exists($pdo,'loans','principal_amount') ? 'principal_amount' : (fin_col_exists($pdo,'loans','principal') ? 'principal' : '');
$col_interest_rate   = fin_col_exists($pdo,'loans','interest_rate') ? 'interest_rate' : '';
$col_loan_type       = fin_col_exists($pdo,'loans','loan_type') ? 'loan_type' : '';
$col_status          = fin_col_exists($pdo,'loans','status') ? 'status' : '';
$col_status_detail   = fin_col_exists($pdo,'loans','status_detail') ? 'status_detail' : '';
$col_start_date      = fin_first_col($pdo,'loans',['start_date','created_at'],'start_date');
$col_due_date        = fin_col_exists($pdo,'loans','due_date') ? 'due_date' : '';
$col_end_date        = fin_col_exists($pdo,'loans','end_date') ? 'end_date' : '';

/* customers columns */
$customersBizCol = fin_col_exists($pdo,'customers','business_id') ? 'business_id' : (fin_col_exists($pdo,'customers','biz_id') ? 'biz_id' : 'business_id');
$cust_name_col   = fin_first_col($pdo,'customers',['full_name','name'],'full_name');
$cust_phone_col  = fin_first_col($pdo,'customers',['phone','phone1'],'phone');

/* optional customer gender/photo columns */
$cust_gender_col = fin_first_col($pdo,'customers',['gender','sex'],'');
$cust_photo_col  = fin_first_col($pdo,'customers',['photo','photo_url','avatar','avatar_url','profile_photo','profile_image','image','img'],'');
$cust_photo_select  = $cust_photo_col ? "c.`$cust_photo_col` AS customer_photo" : "'' AS customer_photo";
$cust_gender_select = $cust_gender_col ? "c.`$cust_gender_col` AS customer_gender" : "'' AS customer_gender";

/* -------------------- filters -------------------- */
$q = trim((string)($_GET['q'] ?? ''));
$f_status = trim((string)($_GET['status'] ?? ''));
$f_status_detail = trim((string)($_GET['status_detail'] ?? ''));
$f_type = trim((string)($_GET['loan_type'] ?? ''));
$f_currency = trim((string)($_GET['currency'] ?? ''));
$f_from = trim((string)($_GET['from'] ?? ''));
$f_to   = trim((string)($_GET['to'] ?? ''));
$f_customer_id = (int)($_GET['customer_id'] ?? 0);

$sort = trim((string)($_GET['sort'] ?? 'newest')); // newest|oldest|due|amount_desc|amount_asc

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 20);
if ($perPage < 10) $perPage = 10;
if ($perPage > 100) $perPage = 100;
$offset = ($page - 1) * $perPage;

/* -------------------- build WHERE -------------------- */
$where = [];
$params = [];

$where[] = "l.`$loansBizCol` = ?";
$params[] = $business_id;

if ($f_customer_id > 0) {
  $where[] = "l.`$col_customer_id` = ?";
  $params[] = $f_customer_id;
}

if ($q !== '') {
  $like = "%$q%";
  $where[] = "("
    . ($col_loan_code ? "l.`$col_loan_code` LIKE ? OR " : "")
    . "CAST(l.`$col_id` AS CHAR) LIKE ? OR "
    . "c.`$cust_name_col` LIKE ? OR "
    . "c.`$cust_phone_col` LIKE ?"
    . ")";
  if ($col_loan_code) $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
}

if ($col_status && $f_status !== '') { $where[] = "l.`$col_status` = ?"; $params[] = $f_status; }
if ($col_status_detail && $f_status_detail !== '') {
  if ($f_status_detail === 'បង់ផ្តាច់រួច') {
    $where[] = "(l.`$col_status_detail` = 'បង់ផ្តាច់រួច' OR UPPER(COALESCE(l.`$col_status`,'')) = 'CLOSED')";
  } else {
    $where[] = "l.`$col_status_detail` = ?";
    $params[] = $f_status_detail;
  }
}
if ($col_loan_type && $f_type !== '') { $where[] = "l.`$col_loan_type` = ?"; $params[] = $f_type; }

if ($col_currency && $f_currency !== '') {
  $where[] = "UPPER(COALESCE(l.`$col_currency`,'')) = UPPER(?)";
  $params[] = $f_currency;
}
if ($f_from !== '' && $col_start_date) { $where[] = "DATE(l.`$col_start_date`) >= ?"; $params[] = $f_from; }
if ($f_to !== '' && $col_start_date) { $where[] = "DATE(l.`$col_start_date`) <= ?"; $params[] = $f_to; }

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

/* -------------------- ORDER BY -------------------- */
$orderBy = "ORDER BY l.`$col_id` DESC";
if ($sort === 'oldest') $orderBy = "ORDER BY l.`$col_id` ASC";
if ($sort === 'due') $orderBy = "ORDER BY (SELECT due_date FROM loan_schedules WHERE loan_id = l.id ORDER BY installment_no ASC LIMIT 1) ASC, l.`$col_id` DESC";
if ($sort === 'amount_desc' && $col_principal_amt) $orderBy = "ORDER BY l.`$col_principal_amt` DESC, l.`$col_id` DESC";
if ($sort === 'amount_asc' && $col_principal_amt) $orderBy = "ORDER BY l.`$col_principal_amt` ASC, l.`$col_id` DESC";

/* -------------------- dropdown lists -------------------- */
$typeOptions = [];
if ($col_loan_type) {
  // Get active dropdown settings
  $st = $pdo->prepare("SELECT DISTINCT TRIM(label_km) FROM dropdown_items WHERE business_id = ? AND category = 'loan_type' AND is_active = 1 ORDER BY sort_order ASC, id ASC");
  $st->execute([$business_id]);
  $typeOptions = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

  // Get active values in loans (for legacy/backwards compatibility)
  $st = $pdo->prepare("SELECT DISTINCT TRIM(`$col_loan_type`) FROM loans WHERE `$loansBizCol` = ?");
  $st->execute([$business_id]);
  $loansTypes = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

  foreach ($loansTypes as $t) {
    if ($t !== '' && !in_array($t, $typeOptions, true)) {
      $typeOptions[] = $t;
    }
  }
}

$statusOptions = [];
if ($col_status) {
  $st = $pdo->prepare("
    SELECT TRIM(COALESCE(`$col_status`,'')) AS v
    FROM loans
    WHERE `$loansBizCol` = ?
    GROUP BY TRIM(COALESCE(`$col_status`,'')) HAVING v <> ''
    ORDER BY v ASC
  ");
  $st->execute([$business_id]);
  $statusOptions = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

$statusDetailOptions = [];
if ($col_status_detail) {
  // Get active dropdown settings
  $st = $pdo->prepare("SELECT DISTINCT TRIM(label_km) FROM dropdown_items WHERE business_id = ? AND category = 'status_detail' AND is_active = 1 ORDER BY sort_order ASC, id ASC");
  $st->execute([$business_id]);
  $statusDetailOptions = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

  // Get active values in loans (for legacy/backwards compatibility)
  $st = $pdo->prepare("SELECT DISTINCT TRIM(`$col_status_detail`) FROM loans WHERE `$loansBizCol` = ?");
  $st->execute([$business_id]);
  $loansSD = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

  foreach ($loansSD as $t) {
    if ($t !== '' && !in_array($t, $statusDetailOptions, true)) {
      $statusDetailOptions[] = $t;
    }
  }
}

$currencyOptions = [];
if ($col_currency) {
  $st = $pdo->prepare("
    SELECT UPPER(TRIM(COALESCE(`$col_currency`,''))) AS v
    FROM loans
    WHERE `$loansBizCol` = ?
    GROUP BY UPPER(TRIM(COALESCE(`$col_currency`,''))) HAVING v <> ''
    ORDER BY v ASC
  ");
  $st->execute([$business_id]);
  $currencyOptions = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/* -------------------- totals -------------------- */
$st = $pdo->prepare("SELECT COUNT(*) FROM loans l LEFT JOIN customers c ON c.id = l.`$col_customer_id` $whereSql");
$st->execute($params);
$totalRows = (int)$st->fetchColumn();

$totalPages = (int)ceil($totalRows / $perPage);
if ($page > max(1, $totalPages)) $page = max(1, $totalPages);

/* -------------------- data query -------------------- */
$selectCols = [
  "l.`$col_id` AS id",
  "l.`$col_customer_id` AS customer_id",
  ($col_loan_code ? "l.`$col_loan_code` AS loan_code" : "NULL AS loan_code"),
  ($col_currency ? "l.`$col_currency` AS currency_code" : "NULL AS currency_code"),
  ($col_principal_amt ? "l.`$col_principal_amt` AS principal_amount" : "0 AS principal_amount"),
  ($col_interest_rate ? "l.`$col_interest_rate` AS interest_rate" : "NULL AS interest_rate"),
  ($col_loan_type ? "l.`$col_loan_type` AS loan_type" : "NULL AS loan_type"),
  ($col_status ? "l.`$col_status` AS status" : "NULL AS status"),
  ($col_status_detail ? "l.`$col_status_detail` AS status_detail" : "NULL AS status_detail"),
  ($col_start_date ? "l.`$col_start_date` AS start_date" : "NULL AS start_date"),
  ($col_due_date ? "l.`$col_due_date` AS due_date" : "(SELECT due_date FROM loan_schedules WHERE loan_id = l.id ORDER BY installment_no ASC LIMIT 1) AS due_date"),
  ($col_end_date ? "l.`$col_end_date` AS end_date" : "NULL AS end_date"),
  "(SELECT COUNT(*) FROM loan_schedules ls WHERE ls.loan_id = l.id) AS total_schedules",
  "(SELECT COUNT(*) FROM loan_schedules ls WHERE ls.loan_id = l.id AND (ls.status = 'PAID' OR (ls.paid_total >= ls.total_due - 0.009 AND ls.total_due > 0))) AS paid_schedules",
  "c.`$cust_name_col` AS customer_name",
  "c.`$cust_phone_col` AS customer_phone",
  $cust_photo_select,
  $cust_gender_select,
];

$sql = "
  SELECT " . implode(", ", $selectCols) . "
  FROM loans l
  LEFT JOIN customers c ON c.id = l.`$col_customer_id`
  $whereSql
  $orderBy
  LIMIT $perPage OFFSET $offset
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* -------------------- build querystring helper -------------------- */
function qs(array $override = []): string {
  $base = $_GET;
  foreach ($override as $k=>$v) {
    if ($v === null) unset($base[$k]);
    else $base[$k] = $v;
  }
  return http_build_query($base);
}

/* -------------------- UI helpers -------------------- */
function fin_status_color(string $txt): array {
  $t = mb_strtolower(trim($txt));
  if ($t === '' || $t === '—') return ['bg'=>'#f8fafc','bd'=>'#e2e8f0','tx'=>'#334155','dot'=>'#94a3b8'];
  
  if (str_contains($t,'close') || str_contains($t,'paid') || str_contains($t,'complete') || str_contains($t,'done') || str_contains($t,'បង់ផ្តាច់') || str_contains($t,'រួច')) {
    return ['bg'=>'#dcfce7','bd'=>'#bbf7d0','tx'=>'#166534','dot'=>'#16a34a'];
  }
  if (str_contains($t,'កាត់ចោល') || str_contains($t,'over') || str_contains($t,'late') || str_contains($t,'due') || str_contains($t,'past')) {
    return ['bg'=>'#fee2e2','bd'=>'#fecaca','tx'=>'#991b1b','dot'=>'#ef4444'];
  }
  if (str_contains($t,'ធម្មតា') || str_contains($t,'active') || str_contains($t,'open') || str_contains($t,'running')) {
    return ['bg'=>'#e0f2fe','bd'=>'#bae6fd','tx'=>'#0369a1','dot'=>'#0284c7'];
  }
  return ['bg'=>'#fffbeb','bd'=>'#fde68a','tx'=>'#92400e','dot'=>'#f59e0b'];
}

/* -------------------- selected customer header (optional) -------------------- */
$selectedCustomerName = '';
$selectedCustomerPhone = '';
$selectedCustomerPhoto = '';
$selectedCustomerGender = '';

if ($f_customer_id > 0) {
  $selectFields = ["`$cust_name_col` AS n", "`$cust_phone_col` AS p"];
  if ($cust_gender_col) $selectFields[] = "`$cust_gender_col` AS g";
  if ($cust_photo_col) $selectFields[] = "`$cust_photo_col` AS ph";
  
  $st = $pdo->prepare("SELECT " . implode(", ", $selectFields) . " FROM customers WHERE id = ? AND `$customersBizCol` = ? LIMIT 1");
  $st->execute([$f_customer_id, $business_id]);
  if ($c = $st->fetch(PDO::FETCH_ASSOC)) {
    $selectedCustomerName = (string)($c['n'] ?? '');
    $selectedCustomerPhone = (string)($c['p'] ?? '');
    $selectedCustomerPhoto = (string)($c['ph'] ?? '');
    $selectedCustomerGender = (string)($c['g'] ?? '');
  }
}

$selectedDefaultAvatar = fin_default_avatar($selectedCustomerGender);
$selectedCustPhotoUrl = $selectedCustomerPhoto ? fin_img_url($selectedCustomerPhoto) : '';
$selectedAvatarSrc = $selectedCustPhotoUrl !== '' ? $selectedCustPhotoUrl : $selectedDefaultAvatar;

if (isset($_GET['ajax'])) {
    // Render Table Body
    ob_start();
    if (!$rows) {
        ?>
        <tr><td colspan="8" class="text-center text-muted py-4">មិនមានទិន្នន័យ</td></tr>
        <?php
    } else {
        foreach ($rows as $idx => $r) {
            $loanId = (int)$r['id'];
            $custId = (int)($r['customer_id'] ?? 0);
            $custName = (string)($r['customer_name'] ?? '');
            $custPhone = (string)($r['customer_phone'] ?? '');

            $gender = (string)($r['customer_gender'] ?? '');
            $defaultAvatar = fin_default_avatar($gender);

            $custPhotoRaw = trim((string)($r['customer_photo'] ?? ''));
            $custPhotoUrl = $custPhotoRaw ? fin_img_url($custPhotoRaw) : '';
            $avatarSrc = $custPhotoUrl !== '' ? $custPhotoUrl : $defaultAvatar;

            $code = (string)($r['loan_code'] ?? '');
            $ccy  = strtoupper(trim((string)($r['currency_code'] ?? '')));
            if ($ccy === '$') $ccy = 'USD';
            if ($ccy === '៛') $ccy = 'KHR';

            $amt  = (float)($r['principal_amount'] ?? 0);
            $type  = (string)($r['loan_type'] ?? '');
            $status = (string)($r['status'] ?? '');
            $statusDetail = (string)($r['status_detail'] ?? '');

            $start = fin_format_date_kh($r['start_date'] ?? '');
            $due   = fin_format_date_kh($r['due_date'] ?? '');

            $badgeText = trim($statusDetail) !== '' ? $statusDetail : (trim($status) !== '' ? $status : '—');
            if (strtoupper(trim($status)) === 'CLOSED') {
                $badgeText = 'បង់ផ្តាច់រួច';
            }
            $colr = fin_status_color($badgeText);

            $rowNo = $offset + $idx + 1;

            $amtPrefix = fin_ccy_symbol($ccy);

            $linkView  = "loan_view.php?id=".$loanId;
            $linkEdit  = "loan_edit.php?id=".$loanId;
            $linkCust  = $custId>0 ? ("customer_view.php?id=".$custId) : "";
            $totalScheds = (int)($r['total_schedules'] ?? 0);
            $paidScheds  = (int)($r['paid_schedules'] ?? 0);
            $isFullyPaid = ($totalScheds > 0 && $paidScheds >= $totalScheds);
            $isClosed = (
                in_array($statusDetail, ['បង់ផ្តាច់រួច', 'បង់ផ្តាច់រួចរាល់', 'paid_off'], true) ||
                in_array(strtoupper(trim($status)), ['CLOSED', 'PAID', 'COMPLETED'], true) ||
                $isFullyPaid
            );
            if ($isClosed) {
                $badgeText = 'បង់ផ្តាច់រួច';
                $colr = fin_status_color($badgeText);
            }
            $rowClass = "";
            if ($statusDetail === 'កាត់ចោល') {
              $rowClass = "row-bad-debt";
            } elseif ($isClosed) {
              $rowClass = "row-paid";
            }
            ?>
            <!-- ===================== DESKTOP ROW ===================== -->
            <tr class="d-none d-md-table-row <?= $rowClass ?>">
              <td class="mono fw-bold"><?php if ($isClosed): ?><i class="bi bi-check-circle-fill text-success me-1" style="font-size: 0.85rem;" title="បង់ផ្តាច់រួច"></i><?php endif; ?><?= $loanId ?></td>

              <td>
                <div class="namecell">
                  <div class="avatar-container position-relative">
                    <div class="avatar">
                      <img src="<?= h($avatarSrc) ?>"
                           alt="<?= h($custName ?: 'Customer') ?>"
                           onerror="this.src='<?= h($defaultAvatar) ?>'">
                    </div>
                    <?php if ($statusDetail === 'កាត់ចោល'): ?>
                      <span class="stamp-bad-debt">BAD DEPT</span>
                    <?php elseif ($isClosed): ?>
                      <svg class="stamp-paid" viewBox="0 0 100 100" width="38" height="38">
                        <circle cx="50" cy="50" r="40" fill="none" stroke="#22c55e" stroke-width="6" />
                        <circle cx="50" cy="50" r="34" fill="none" stroke="#22c55e" stroke-width="1.5" />
                        <text x="50" y="58" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif" font-weight="900" font-size="24" fill="#22c55e" text-anchor="middle" letter-spacing="1">PAID</text>
                      </svg>
                    <?php endif; ?>
                  </div>

                  <div class="nwrap">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                      <?php if ($custId>0): ?>
                        <a href="<?= h($linkCust) ?>" class="text-decoration-none fw-bold">
                          <?= h($custName ?: '—') ?>
                        </a>
                      <?php else: ?>
                        <span class="fw-bold"><?= h($custName ?: '—') ?></span>
                      <?php endif; ?>
                    </div>

                    <div class="p">
                      <?php if (trim($custPhone) !== ''): ?>
                        <i class="bi bi-telephone-fill"></i>
                        <span class="mono"><?= h($custPhone) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </td>

              <td class="mono fw-bold money">
                <?= fin_format_amount($amt, $ccy) ?>
              </td>

              <td class="d-none d-md-table-cell"><?= h($type ?: '—') ?></td>

              <td>
                <span class="sbadge"
                      style="background:<?= h($colr['bg']) ?>;border-color:<?= h($colr['bd']) ?>;color:<?= h($colr['tx']) ?>;">
                  <span class="dot" style="background:<?= h($colr['dot']) ?>;"></span>
                  <?= h($badgeText) ?>
                </span>
              </td>

              <td class="d-none d-md-table-cell mono">
                <div><?= h($start ?: '—') ?></div>
                <?php if ($totalScheds > 0): ?>
                  <div class="mt-1" style="font-size: 0.76rem;">
                    <?php if ($isClosed || $paidScheds >= $totalScheds): ?>
                      <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.72rem; padding: 2px 7px; border-radius: 999px;">
                        <i class="bi bi-check2-all me-1"></i>បង់ចប់ <?= $paidScheds ?>/<?= $totalScheds ?>
                      </span>
                    <?php else: ?>
                      <span class="text-primary fw-semibold"><i class="bi bi-clock-history me-1"></i>បង់បាន <?= $paidScheds ?>/<?= $totalScheds ?> លើក</span>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </td>
              <td class="d-none d-md-table-cell mono"><?= h($due ?: '—') ?></td>

              <td class="text-end text-nowrap">
                <div class="d-inline-flex gap-2 justify-content-end align-items-center">
                  <a class="btn-icon pri" href="<?= h($linkView) ?>" title="មើលលម្អិត"><i class="bi bi-eye"></i></a>
                  <?php if (!$isClosed): ?>
                    <a class="btn-action btn-pay text-nowrap" href="loan_payment_add.php?loan_id=<?= $loanId ?>" title="បង់ប្រាក់"><i class="bi bi-cash-coin me-1"></i>បង់ប្រាក់</a>
                    <a class="btn-action btn-settle text-nowrap" href="loan_payment_add.php?loan_id=<?= $loanId ?>&settle=1" title="បង់ផ្តាច់"><i class="bi bi-check2-circle me-1"></i>បង់ផ្តាច់</a>
                  <?php else: ?>
                    <?php if ($custId > 0): ?>
                      <a class="btn-action btn-new-loan text-nowrap" href="loan_add.php?customer_id=<?= $custId ?>" title="ស្នើកម្ចីថ្មី"><i class="bi bi-plus-circle me-1"></i>ស្នើកម្ចីថ្មី</a>
                    <?php endif; ?>
                    <span class="badge-paid-off text-nowrap" style="padding: 0.28rem 0.6rem; font-size: 0.75rem;" title="កម្ចីនេះបានបង់ផ្តាច់រួចរាល់"><i class="bi bi-patch-check-fill me-1"></i>បង់ផ្តាច់រួច</span>
                  <?php endif; ?>

                  <div class="dropdown d-inline-block">
                    <button class="btn-icon" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="ផ្សេងៗ">
                      <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="border-radius: 12px; font-size: 0.88rem;">
                      <?php if ($isClosed && $custId > 0): ?>
                        <li>
                          <a class="dropdown-item text-primary fw-semibold" href="loan_add.php?customer_id=<?= $custId ?>">
                            <i class="bi bi-plus-circle-fill me-2 text-primary"></i> ស្នើកម្ចីថ្មី
                          </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                      <?php endif; ?>
                      <li>
                        <a class="dropdown-item" href="<?= h($linkEdit) ?>">
                          <i class="bi bi-pencil-square me-2 text-warning"></i> កែប្រែកម្ចី
                        </a>
                      </li>
                      <?php if ($custId>0): ?>
                        <li>
                          <a class="dropdown-item" href="<?= h($linkCust) ?>">
                            <i class="bi bi-person-vcard me-2 text-primary"></i> ព័ត៌មានអតិថិជន
                          </a>
                        </li>
                      <?php endif; ?>
                      <li>
                        <a class="dropdown-item" href="loan_agreement_print.php?id=<?= $loanId ?>" target="_blank">
                          <i class="bi bi-file-earmark-text me-2 text-info"></i> កិច្ចសន្យាកម្ចី
                        </a>
                      </li>
                      <li>
                        <a class="dropdown-item" href="loan_schedule_print.php?id=<?= $loanId ?>" target="_blank">
                          <i class="bi bi-calendar3 me-2 text-secondary"></i> តារាងកាលវិភាគ
                        </a>
                      </li>
                      <li><hr class="dropdown-divider"></li>
                      <li>
                        <a class="dropdown-item text-danger" href="<?= h($linkDelete) ?>"
                           onclick="return confirm('តើអ្នកប្រាកដថាចង់លុបកម្ចីនេះឬ?');">
                          <i class="bi bi-trash3 me-2 text-danger"></i> លុបកម្ចី
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
                <div class="m-card <?= $rowClass ? $rowClass.'-card' : '' ?>">

                  <div class="m-top">
                    <div class="m-left">
                      <div class="avatar-container position-relative">
                        <div class="avatar">
                          <img src="<?= h($avatarSrc) ?>"
                               alt="<?= h($custName ?: 'Customer') ?>"
                               onerror="this.src='<?= h($defaultAvatar) ?>'">
                        </div>
                        <?php if ($statusDetail === 'កាត់ចោល'): ?>
                          <span class="stamp-bad-debt">BAD DEPT</span>
                        <?php elseif ($isClosed): ?>
                          <svg class="stamp-paid" viewBox="0 0 100 100" width="38" height="38">
                            <circle cx="50" cy="50" r="40" fill="none" stroke="#22c55e" stroke-width="6" />
                            <circle cx="50" cy="50" r="34" fill="none" stroke="#22c55e" stroke-width="1.5" />
                            <text x="50" y="58" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif" font-weight="900" font-size="24" fill="#22c55e" text-anchor="middle" letter-spacing="1">PAID</text>
                          </svg>
                        <?php endif; ?>
                      </div>

                      <div class="m-title">
                        <p class="name mb-0"><?= h($custName ?: '—') ?></p>
                        <div class="m-sub">
                          <?php if (trim($custPhone) !== ''): ?>
                            <i class="bi bi-telephone-fill"></i>
                            <span class="mono"><?= h($custPhone) ?></span>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>

                    <span class="sbadge"
                          style="background:<?= h($colr['bg']) ?>;border-color:<?= h($colr['bd']) ?>;color:<?= h($colr['tx']) ?>;">
                      <span class="dot" style="background:<?= h($colr['dot']) ?>;"></span>
                      <?= h($badgeText) ?>
                    </span>
                  </div>

                  <div class="m-kpi">
                    <div class="mini">
                      <div class="lbl"><i class="bi bi-cash-stack"></i> សាច់ប្រាក់</div>
                      <div class="val mono money"><?= fin_format_amount($amt, $ccy) ?></div>
                    </div>
                    <div class="mini">
                      <div class="lbl"><i class="bi bi-tag"></i> ប្រភេទ</div>
                      <div class="val"><?= h($type ?: '—') ?></div>
                    </div>
                    <div class="mini">
                      <div class="lbl"><i class="bi bi-calendar-event"></i> ថ្ងៃចាប់ផ្តើម</div>
                      <div class="val mono">
                        <div><?= h($start ?: '—') ?></div>
                        <?php if ($totalScheds > 0): ?>
                          <div class="mt-1" style="font-size: 0.75rem;">
                            <?php if ($isClosed || $paidScheds >= $totalScheds): ?>
                              <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.72rem; padding: 2px 7px; border-radius: 999px;">
                                <i class="bi bi-check2-all me-1"></i>បង់ចប់ <?= $paidScheds ?>/<?= $totalScheds ?>
                              </span>
                            <?php else: ?>
                              <span class="text-primary fw-semibold"><i class="bi bi-clock-history me-1"></i>បង់បាន <?= $paidScheds ?>/<?= $totalScheds ?> លើក</span>
                            <?php endif; ?>
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="mini">
                      <div class="lbl"><i class="bi bi-calendar2-week"></i> ថ្ងៃកំណត់</div>
                      <div class="val mono"><?= h($due ?: '—') ?></div>
                    </div>
                  </div>

                  <div class="m-actionbar">
                    <span class="m-rowno mono"><?= $rowNo ?></span>

                    <div class="m-actions">
                      <?php if ($isClosed && $custId > 0): ?>
                        <a class="btn-action btn-new-loan text-nowrap px-2" style="height: 32px; font-size: 0.82rem;" href="loan_add.php?customer_id=<?= $custId ?>" title="ស្នើកម្ចីថ្មី"><i class="bi bi-plus-circle me-1"></i> ស្នើកម្ចីថ្មី</a>
                      <?php endif; ?>
                      <a class="btn-icon pri text-nowrap px-2" style="width: auto; height: 32px; border-radius: 8px; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; text-decoration: none;" href="<?= h($linkView) ?>" title="View"><i class="bi bi-eye" style="font-size: 0.95rem;"></i> មើល</a>
                      <?php if (!$isClosed): ?>
                        <a class="btn-action btn-pay text-nowrap px-2" style="height: 32px; font-size: 0.82rem;" href="loan_payment_add.php?loan_id=<?= $loanId ?>" title="បង់ប្រាក់"><i class="bi bi-cash-coin me-1"></i> បង់ប្រាក់</a>
                        <a class="btn-action btn-settle text-nowrap px-2" style="height: 32px; font-size: 0.82rem;" href="loan_payment_add.php?loan_id=<?= $loanId ?>&settle=1" title="បង់ផ្តាច់"><i class="bi bi-check2-circle me-1"></i> បង់ផ្តាច់</a>
                      <?php else: ?>
                        <span class="badge-paid-off text-nowrap" style="padding: 0.25rem 0.55rem; font-size: 0.74rem;"><i class="bi bi-patch-check-fill me-1"></i> បង់ផ្តាច់រួច</span>
                      <?php endif; ?>

                      <div class="dropdown">
                        <button class="btn-icon" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="More">
                          <i class="bi bi-three-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="border-radius: 12px; font-size: 0.88rem;">
                          <?php if ($isClosed && $custId > 0): ?>
                            <li>
                              <a class="dropdown-item text-primary fw-semibold" href="loan_add.php?customer_id=<?= $custId ?>">
                                <i class="bi bi-plus-circle-fill me-2 text-primary"></i> ស្នើកម្ចីថ្មី
                              </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                          <?php endif; ?>
                          <?php if ($custId>0): ?>
                            <li>
                              <a class="dropdown-item" href="<?= h($linkCust) ?>">
                                <i class="bi bi-person-vcard me-2 text-primary"></i> ព័ត៌មានអតិថិជន
                              </a>
                            </li>
                          <?php endif; ?>
                          <li>
                            <a class="dropdown-item" href="loan_agreement_print.php?id=<?= $loanId ?>" target="_blank">
                              <i class="bi bi-file-earmark-text me-2 text-info"></i> កិច្ចសន្យាកម្ចី
                            </a>
                          </li>
                          <li>
                            <a class="dropdown-item" href="loan_schedule_print.php?id=<?= $loanId ?>" target="_blank">
                              <i class="bi bi-calendar3 me-2 text-secondary"></i> តារាងកាលវិភាគ
                            </a>
                          </li>
                          <li><hr class="dropdown-divider"></li>
                          <li>
                            <a class="dropdown-item text-danger" href="<?= h($linkDelete) ?>"
                               onclick="return confirm('តើអ្នកប្រាកដថាចង់លុបកម្ចីនេះឬ?');">
                              <i class="bi bi-trash3 me-2 text-danger"></i> លុបកម្ចី
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
    <div class="sub text-center">
      ទំព័រ <span class="fw-bold"><?= (int)$page ?></span> / <span class="fw-bold"><?= max(1,$totalPages) ?></span>
      • បង្ហាញ <span class="fw-bold"><?= fin_int(count($rows)) ?></span> នៃ <span class="fw-bold"><?= fin_int($totalRows) ?></span> កម្ចី
    </div>

    <nav class="d-flex justify-content-center">
      <ul class="pagination mb-0">
        <?php
          $prev = max(1, $page - 1);
          $next = min(max(1,$totalPages), $page + 1);

          $disablePrev = $page <= 1;
          $disableNext = $page >= max(1,$totalPages);
        ?>
        <li class="page-item <?= $disablePrev ? 'disabled' : '' ?>">
          <a class="page-link pagination-link" data-page="<?= $prev ?>" href="#">‹</a>
        </li>

        <?php
          $startP = max(1, $page - 2);
          $endP   = min(max(1,$totalPages), $page + 2);
          for ($p=$startP; $p<=$endP; $p++):
        ?>
          <li class="page-item <?= $p===$page ? 'active' : '' ?>">
            <a class="page-link pagination-link" data-page="<?= $p ?>" href="#"><?= $p ?></a>
          </li>
        <?php endfor; ?>

        <li class="page-item <?= $disableNext ? 'disabled' : '' ?>">
          <a class="page-link pagination-link" data-page="<?= $next ?>" href="#">›</a>
        </li>
      </ul>
    </nav>
    <?php
    $paginationHtml = ob_get_clean();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'table' => $tableHtml,
        'pagination' => $paginationHtml,
        'totalRowsFormatted' => fin_int($totalRows)
    ]);
    exit;
}

?>
<!doctype html>
<html lang="km">
<head>
  <meta charset="utf-8">
  <title>កម្ចី | Finance</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://fonts.googleapis.com/css2?family=Battambang:wght@300;400;700;900&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

  <!-- Flatpickr (Calendar) -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">

  <style>
    :root{ --bg:#f3f4f6; --card:#fff; --ink:#0f172a; --muted:#64748b; --line:#e5e7eb; }
    body{font-family:'Battambang',sans-serif;background:var(--bg); color:var(--ink);}
    .cardx{background:var(--card); border:0; border-radius:18px; box-shadow:0 10px 25px rgba(0,0,0,.06);}
    .page-title{font-weight:900;}
    .sub{color:var(--muted); font-size:.92rem;}
    .btn,.form-control,.form-select{border-radius:12px;}
    .form-control, .form-select, .input-group-text {
      border-color: #dbe3ee;
      min-height: 44px;
    }
    .mono{font-variant-numeric:tabular-nums;}
    .money{white-space:nowrap;}

    /* avatar */
    .avatar{
      width:50px;height:50px;border-radius:999px;
      border:1px solid rgba(15,23,42,.12);
      background:#fff;
      display:inline-flex;align-items:center;justify-content:center;
      overflow:hidden; flex:0 0 auto;
    }
    .avatar img{width:100%;height:100%;object-fit:cover;}
    .avatar span{font-weight:900;color:#0f172a;}

    /* status badge */
    .sbadge{
      border-radius:999px;
      padding:.22rem .55rem;
      font-weight:900;
      border:1px solid;
      display:inline-flex;
      align-items:center;
      gap:.4rem;
      font-size:.82rem;
      white-space:nowrap;
    }
    .sbadge .dot{width:7px;height:7px;border-radius:99px; display:inline-block;}

    /* icon buttons */
    .btn-icon{
      width:38px;height:38px;
      display:inline-flex;align-items:center;justify-content:center;
      border-radius:12px;
      border:1px solid rgba(15,23,42,.12);
      background:#fff;
      color:#0f172a;
      text-decoration:none;
    }
    .btn-icon.pri{background:#eff6ff; border-color:#bfdbfe; color:#1d4ed8;}
    .btn-icon.war{background:#fffbeb; border-color:#fde68a; color:#a16207;}
    .btn-icon.suc{background:#ecfdf5; border-color:#bbf7d0; color:#166534;}
    .btn-icon.dan{background:#fef2f2; border-color:#fecaca; color:#991b1b;}

    .btn-action {
      height: 38px;
      padding: 0 11px;
      border-radius: 12px;
      font-size: 0.82rem;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-decoration: none;
      transition: transform .16s ease, box-shadow .16s ease, background .16s ease;
      white-space: nowrap;
    }
    .btn-action:hover {
      transform: translateY(-2px);
      box-shadow: 0 7px 16px rgba(15,23,42,.12);
    }
    .btn-pay {
      background: #eff6ff;
      color: #1d4ed8;
      border: 1px solid #bfdbfe;
    }
    .btn-pay:hover {
      background: #dbeafe;
      color: #1e40af;
    }
    .btn-settle {
      background: #ecfdf5;
      color: #15803d;
      border: 1px solid #86efac;
    }
    .btn-settle:hover {
      background: #dcfce7;
      color: #14532d;
    }
    .btn-new-loan {
      background: #eff6ff;
      color: #1d4ed8;
      border: 1px solid #93c5fd;
      font-weight: 700;
    }
    .btn-new-loan:hover {
      background: #2563eb;
      color: #ffffff !important;
      border-color: #2563eb;
    }
    .btn-new-loan i {
      color: #2563eb;
    }
    .btn-new-loan:hover i {
      color: #ffffff;
    }
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
    }

    .table {
      border-collapse: separate !important;
      border-spacing: 0 !important;
    }
    .table thead th {
      white-space: nowrap;
    }
    .table thead th:first-child {
      border-top-left-radius: 18px !important;
    }
    .table thead th:last-child {
      border-top-right-radius: 18px !important;
    }
    .table tbody tr:last-child td:first-child {
      border-bottom-left-radius: 18px !important;
    }
    .table tbody tr:last-child td:last-child {
      border-bottom-right-radius: 18px !important;
    }
    .namecell{display:flex; gap:.7rem; align-items:center;}
    .nwrap{line-height:1.35; min-width:0;}
    .nwrap > div:first-child{margin-bottom:4px;}
    .nwrap .p{color:var(--muted); font-size:.9rem; display:flex; align-items:center; gap:.35rem; flex-wrap:wrap;}

    /* selected customer box */
    .selbox{
      border:1px dashed rgba(15,23,42,.18);
      background:linear-gradient(180deg,#ffffff,#fbfdff);
      border-radius:16px;
      padding:.8rem .9rem;
      display:flex;
      gap:.6rem;
      align-items:flex-start;
      justify-content:space-between;
    }
    .selbox .left{min-width:0;}
    .selbox .t{font-weight:900; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
    .selbox .b{color:var(--muted); font-size:.92rem; display:flex; gap:.45rem; align-items:center; flex-wrap:wrap;}
    .selbox .right{display:flex; gap:.4rem; flex-wrap:wrap; justify-content:flex-end;}

    /* mobile cards */
    .m-card{
      border:1px solid rgba(15,23,42,.10);
      border-radius:18px;
      background:#fff;
      padding:.9rem .9rem;
      box-shadow:0 10px 24px rgba(22,34,51,.06);
    }
    .m-top{display:flex; justify-content:space-between; gap:.75rem; align-items:flex-start;}
    .m-left{display:flex; gap:.65rem; min-width:0; flex:1;}
    .m-title{min-width:0; flex:1;}
    .m-title .name{
      font-weight:900; font-size:1.05rem;
      white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
      margin:0;
    }
    .m-sub{
      margin-top:.2rem; color:var(--muted); font-size:.92rem;
      display:flex; gap:.45rem; align-items:center; flex-wrap:wrap;
    }
    .m-kpi{
      margin-top:.7rem;
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap:.6rem;
    }
    .mini{
      border:1px solid rgba(15,23,42,.10);
      border-radius:14px;
      padding:.55rem .6rem;
      background:linear-gradient(180deg,#fff,#fbfdff);
    }
    .mini .lbl{color:var(--muted); font-size:.85rem; display:flex; align-items:center; gap:.35rem;}
    .mini .val{font-weight:normal; font-size:1.05rem; white-space:nowrap;}
    .m-actionbar{
      border-top:1px solid rgba(15,23,42,.10);
      margin-top:.65rem; padding-top:.6rem;
      display:flex; justify-content:space-between; align-items:center; gap:.6rem;
    }
    .m-rowno{color:#94a3b8; font-weight:900; font-size:.9rem;}
    .m-actions{display:flex; gap:.45rem; align-items:center;}

    @media (max-width: 767.98px){
      .table thead{display:none;}
      .table tbody td{border:none !important;}
    }

    /* Flatpickr Khmer font like your reference */
    .flatpickr-calendar, .flatpickr-calendar *{
      font-family:'Battambang',sans-serif !important;
      font-size:.92rem;
    }

    /* stamps */
    .stamp-paid {
      position: absolute;
      bottom: -8px;
      right: -8px;
      width: 38px;
      height: 38px;
      transform: rotate(-15deg);
      z-index: 3;
      pointer-events: none;
      filter: drop-shadow(0 2px 4px rgba(0,0,0,0.15));
    }
    .stamp-bad-debt {
      position: absolute;
      bottom: 0px;
      left: -8px;
      background: #ef4444;
      color: white;
      font-size: 7px;
      font-weight: 900;
      padding: 1px 3px;
      border-radius: 2px;
      border: 1px solid white;
      transform: rotate(-15deg);
      box-shadow: 0 2px 4px rgba(0,0,0,0.2);
      text-transform: uppercase;
      z-index: 3;
      white-space: nowrap;
      letter-spacing: 0.2px;
    }

    /* row and card backgrounds */
    .table tbody tr.row-paid td {
      background-color: #eafbf0 !important;
    }
    .table tbody tr.row-paid:hover td {
      background-color: #d7f7e3 !important;
    }
    .table tbody tr.row-paid > td:first-child {
      border-left: 5px solid #16a34a !important;
      position: relative;
    }
    .table tbody tr.row-bad-debt td {
      background-color: #fef2f2 !important;
    }
    .table tbody tr.row-bad-debt:hover td {
      background-color: #fee2e2 !important;
    }
    .table tbody tr.row-bad-debt > td:first-child {
      border-left: 5px solid #ef4444 !important;
      position: relative;
    }
    .m-card.row-paid-card {
      background-color: #eafbf0 !important;
      border-left: 5px solid #16a34a !important;
      box-shadow: 0 4px 14px rgba(22, 163, 74, 0.14), 0 2px 6px rgba(0, 0, 0, 0.04) !important;
    }
    .m-card.row-bad-debt-card {
      background-color: #fef2f2 !important;
      border-left: 5px solid #ef4444 !important;
    }
    .filter-flex .f-btn .btn {
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      padding: 0 !important;
    }

    @media (min-width: 992px) {
      .filter-flex {
        display: flex !important;
        flex-wrap: nowrap !important;
        align-items: flex-end !important;
        gap: 4px !important;
        width: 100% !important;
      }
      .filter-flex > div {
        width: auto !important;
        flex: 1 1 0px !important;
      }
      .filter-flex .f-search { flex: 2 1 150px !important; }
      .filter-flex .f-type { flex: 1 1 90px !important; }
      .filter-flex .f-status { flex: 1 1 80px !important; }
      .filter-flex .f-status-detail { flex: 1 1 95px !important; }
      .filter-flex .f-currency { flex: 1 1 70px !important; }
      .filter-flex .f-from { flex: 1.2 1 90px !important; }
      .filter-flex .f-to { flex: 1.2 1 90px !important; }
      .filter-flex .f-sort { flex: 1.1 1 85px !important; }
      .filter-flex .f-per-page { flex: 0.8 1 65px !important; }
      .filter-flex .f-btn { flex: 0 0 auto !important; width: 180px !important; }

      .filter-flex .form-control,
      .filter-flex .form-select,
      .filter-flex .input-group-text,
      .filter-flex .btn {
        font-size: 0.8rem !important;
        padding-left: 4px !important;
        padding-right: 4px !important;
      }
      .filter-flex .form-label {
        font-size: 0.8rem !important;
        margin-bottom: 2px !important;
      }
    }
  </style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="container py-4">

  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
      <h4 class="page-title mb-1"><i class="bi bi-cash-coin me-1"></i> បញ្ជីកម្ចី</h4>
      <?php if ($f_customer_id <= 0): ?>
        <div class="sub">សរុប: <span class="fw-bold mono" id="total-loans-count"><?= fin_int($totalRows) ?></span> កម្ចី</div>
      <?php endif; ?>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a class="btn btn-primary" href="loan_add.php<?= $f_customer_id>0 ? ('?customer_id='.$f_customer_id) : '' ?>">
        <i class="bi bi-plus-lg me-1"></i> បន្ថែមកម្ចី
      </a>
      <a class="btn btn-outline-secondary" href="customers.php"><i class="bi bi-people me-1"></i> អតិថិជន</a>
      <a class="btn btn-outline-secondary" href="dashboard.php"><i class="bi bi-speedometer2 me-1"></i> ទំព័រដើម</a>
    </div>
  </div>

  <?php if ($f_customer_id > 0): ?>
    <div class="cardx p-3 mb-3">
      <div class="selbox align-items-center">
        <div class="left d-flex align-items-center gap-3">
          <!-- Avatar -->
          <div class="avatar-container position-relative flex-shrink-0" style="width: 48px; height: 48px; border-radius: 50%; overflow: hidden; border: 1.5px solid rgba(15,23,42,.08);">
            <img src="<?= h($selectedAvatarSrc) ?>" alt="<?= h($selectedCustomerName) ?>" style="width: 100%; height: 100%; object-fit: cover;">
          </div>
          <!-- Details -->
          <div>
            <div class="fw-bold" style="font-size: 1.05rem; color: var(--ink); line-height: 1.2;"><?= h($selectedCustomerName ?: ('ID '.$f_customer_id)) ?></div>
            <div class="d-flex align-items-center gap-2 flex-wrap text-muted mt-1" style="font-size: 0.82rem;">
              <span>
                <i class="bi bi-telephone-fill me-1" style="color: var(--muted);"></i>
                <span class="mono"><?= h($selectedCustomerPhone ?: '—') ?></span>
              </span>
              <span>•</span>
              <span class="sub">ID: <span class="mono fw-bold"><?= (int)$f_customer_id ?></span></span>
              <span>•</span>
              <span class="sbadge" style="background: #e0f2fe; border-color: #bae6fd; color: #0369a1; font-size: 0.78rem; border-radius: 6px; padding: 0.15rem 0.45rem; display: inline-flex; align-items: center; gap: 4px;">
                សរុប៖ <span class="fw-bold mono" id="total-loans-count"><?= fin_int($totalRows) ?></span> កម្ចី
              </span>
            </div>
          </div>
        </div>
        <div class="right">
          <a class="btn btn-outline-secondary btn-sm" href="loans.php">
            <i class="bi bi-x-circle me-1"></i> បង្ហាញទាំងអស់
          </a>
          <a class="btn btn-outline-primary btn-sm" href="customers.php">
            <i class="bi bi-arrow-left me-1"></i> ត្រលប់ទៅអតិថិជន
          </a>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Filters -->
  <div class="cardx p-3 p-lg-4 mb-3">
    <form class="row g-1 align-items-end filter-flex" id="filterForm" onsubmit="return false;">
      <?php if ($f_customer_id > 0): ?>
        <input type="hidden" name="customer_id" value="<?= (int)$f_customer_id ?>">
      <?php endif; ?>

      <div class="col-12 col-lg-4 f-search">
        <label class="form-label">ស្វែងរក</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input class="form-control" name="q" id="searchQuery" value="<?= h($q) ?>" placeholder="ឈ្មោះ / ទូរស័ព្ទ / លេខសម្គាល់">
        </div>
      </div>

      <div class="col-6 col-lg-2 f-type">
        <label class="form-label">ប្រភេទកម្ចី</label>
        <select class="form-select" name="loan_type" id="filterType" <?= $col_loan_type ? '' : 'disabled' ?>>
          <option value="">ទាំងអស់</option>
          <?php foreach ($typeOptions as $opt): ?>
            <option value="<?= h($opt) ?>" <?= $f_type===$opt ? 'selected' : '' ?>><?= h($opt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-6 col-lg-2 f-status">
        <label class="form-label">Status</label>
        <select class="form-select" name="status" id="filterStatus" <?= $col_status ? '' : 'disabled' ?>>
          <option value="">ទាំងអស់</option>
          <?php foreach ($statusOptions as $opt): ?>
            <option value="<?= h($opt) ?>" <?= $f_status===$opt ? 'selected' : '' ?>><?= h($opt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-6 col-lg-2 f-status-detail">
        <label class="form-label">ស្ថានភាពលម្អិត</label>
        <select class="form-select" name="status_detail" id="filterStatusDetail" <?= $col_status_detail ? '' : 'disabled' ?>>
          <option value="">ទាំងអស់</option>
          <?php foreach ($statusDetailOptions as $opt): ?>
            <option value="<?= h($opt) ?>" <?= $f_status_detail===$opt ? 'selected' : '' ?>><?= h($opt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-6 col-lg-2 f-currency">
        <label class="form-label">រូបិយប័ណ្ណ</label>
        <select class="form-select" name="currency" id="filterCurrency" <?= $col_currency ? '' : 'disabled' ?>>
          <option value="">ទាំងអស់</option>
          <?php foreach ($currencyOptions as $opt): ?>
            <option value="<?= h($opt) ?>" <?= strtoupper($f_currency)===strtoupper($opt) ? 'selected' : '' ?>><?= h($opt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- ✅ Khmer Calendar (Flatpickr) -->
      <div class="col-6 col-lg-2 f-from">
        <label class="form-label">ពីថ្ងៃ</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
          <input
            type="text"
            class="form-control"
            id="fromPicker"
            name="from"
            value="<?= h($f_from) ?>"
            placeholder="ថ្ងៃ-ខែ-ឆ្នាំ"
            autocomplete="off"
            <?= $col_start_date ? '' : 'disabled' ?>
          >
        </div>
      </div>

      <div class="col-6 col-lg-2 f-to">
        <label class="form-label">ដល់ថ្ងៃ</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-calendar2-week"></i></span>
          <input
            type="text"
            class="form-control"
            id="toPicker"
            name="to"
            value="<?= h($f_to) ?>"
            placeholder="ថ្ងៃ-ខែ-ឆ្នាំ"
            autocomplete="off"
            <?= $col_start_date ? '' : 'disabled' ?>
          >
        </div>
      </div>

      <div class="col-6 col-lg-2 f-sort">
        <label class="form-label">រៀបតាម</label>
        <select class="form-select" name="sort" id="filterSort">
          <option value="newest" <?= $sort==='newest'?'selected':'' ?>>ថ្មីបំផុត</option>
          <option value="oldest" <?= $sort==='oldest'?'selected':'' ?>>ចាស់បំផុត</option>
          <option value="due" <?= $sort==='due'?'selected':'' ?>>ថ្ងៃកំណត់</option>
          <option value="amount_desc" <?= $sort==='amount_desc'?'selected':'' ?> <?= $col_principal_amt ? '' : 'disabled' ?>>សាច់ប្រាក់ ↓</option>
          <option value="amount_asc" <?= $sort==='amount_asc'?'selected':'' ?> <?= $col_principal_amt ? '' : 'disabled' ?>>សាច់ប្រាក់ ↑</option>
        </select>
      </div>

      <div class="col-6 col-lg-2 f-per-page">
        <label class="form-label">ចំនួនទំព័រ</label>
        <select class="form-select" name="per_page" id="filterPerPage">
          <?php foreach ([20,30,50,100] as $pp): ?>
            <option value="<?= $pp ?>" <?= $perPage===$pp?'selected':'' ?>><?= $pp ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-lg-4 d-flex gap-1 f-btn">
        <button class="btn btn-primary w-100" type="submit" id="btnSearch" style="height:44px;" title="ស្វែងរក"><i class="bi bi-funnel-fill me-1"></i> ស្វែងរក</button>
        <?php $resetUrl = "loans.php" . ($f_customer_id>0 ? ("?customer_id=".$f_customer_id) : ""); ?>
        <a class="btn btn-outline-secondary w-100" id="btnClear" href="<?= h($resetUrl) ?>" style="height:44px;" title="សំអាត"><i class="bi bi-arrow-counterclockwise me-1"></i> សំអាត</a>
      </div>
    </form>
  </div>

  <!-- Data -->
  <div class="cardx p-0">
    <div class="table-responsive" style="overflow: visible;">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th class="text-nowrap">ID</th>
            <th>អតិថិជន</th>
            <th class="text-nowrap">ទឹកប្រាក់</th>
            <th class="d-none d-md-table-cell text-nowrap">ប្រភេទ</th>
            <th class="text-nowrap">ស្ថានភាព</th>
            <th class="d-none d-md-table-cell text-nowrap">ចាប់ផ្តើម</th>
            <th class="d-none d-md-table-cell text-nowrap">ថ្ងៃកំណត់</th>
            <th class="text-nowrap text-end">សកម្មភាព</th>
          </tr>
        </thead>
        <tbody id="loans-tbody">

        <?php if (!$rows): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">មិនមានទិន្នន័យ</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $idx => $r): ?>
            <?php
              $loanId = (int)$r['id'];
              $custId = (int)($r['customer_id'] ?? 0);
              $custName = (string)($r['customer_name'] ?? '');
              $custPhone = (string)($r['customer_phone'] ?? '');

              $gender = (string)($r['customer_gender'] ?? '');
              $defaultAvatar = fin_default_avatar($gender);

              $custPhotoRaw = trim((string)($r['customer_photo'] ?? ''));
              $custPhotoUrl = $custPhotoRaw ? fin_img_url($custPhotoRaw) : '';
              $avatarSrc = $custPhotoUrl !== '' ? $custPhotoUrl : $defaultAvatar;

              $code = (string)($r['loan_code'] ?? '');
              $ccy  = strtoupper(trim((string)($r['currency_code'] ?? '')));
              if ($ccy === '$') $ccy = 'USD';
              if ($ccy === '៛') $ccy = 'KHR';

              $amt  = (float)($r['principal_amount'] ?? 0);
              $type  = (string)($r['loan_type'] ?? '');
              $status = (string)($r['status'] ?? '');
              $statusDetail = (string)($r['status_detail'] ?? '');

              $start = fin_format_date_kh($r['start_date'] ?? '');
              $due   = fin_format_date_kh($r['due_date'] ?? '');

              $badgeText = trim($statusDetail) !== '' ? $statusDetail : (trim($status) !== '' ? $status : '—');
              if (strtoupper(trim($status)) === 'CLOSED') {
                  $badgeText = 'បង់ផ្តាច់រួច';
              }
              $colr = fin_status_color($badgeText);

              $rowNo = $offset + $idx + 1;

              $amtPrefix = fin_ccy_symbol($ccy);

              $linkView  = "loan_view.php?id=".$loanId;
              $linkEdit  = "loan_edit.php?id=".$loanId;
              $linkCust  = $custId>0 ? ("customer_view.php?id=".$custId) : "";
              $linkDelete= "loan_delete.php?id=".$loanId;
            ?>

            <!-- ===================== DESKTOP ROW ===================== -->
            <?php
              $totalScheds = (int)($r['total_schedules'] ?? 0);
              $paidScheds  = (int)($r['paid_schedules'] ?? 0);
              $isFullyPaid = ($totalScheds > 0 && $paidScheds >= $totalScheds);
              $isClosed = (
                  in_array($statusDetail, ['បង់ផ្តាច់រួច', 'បង់ផ្តាច់រួចរាល់', 'paid_off'], true) ||
                  in_array(strtoupper(trim($status)), ['CLOSED', 'PAID', 'COMPLETED'], true) ||
                  $isFullyPaid
              );
              if ($isClosed) {
                  $badgeText = 'បង់ផ្តាច់រួច';
                  $colr = fin_status_color($badgeText);
              }
              $rowClass = "";
              if ($statusDetail === 'កាត់ចោល') {
                $rowClass = "row-bad-debt";
              } elseif ($isClosed) {
                $rowClass = "row-paid";
              }
            ?>
            <tr class="d-none d-md-table-row <?= $rowClass ?>">
              <td class="mono fw-bold"><?php if ($isClosed): ?><i class="bi bi-check-circle-fill text-success me-1" style="font-size: 0.85rem;" title="បង់ផ្តាច់រួច"></i><?php endif; ?><?= $loanId ?></td>

              <td>
                <div class="namecell">
                  <div class="avatar-container position-relative">
                    <div class="avatar">
                      <img src="<?= h($avatarSrc) ?>"
                           alt="<?= h($custName ?: 'Customer') ?>"
                           onerror="this.src='<?= h($defaultAvatar) ?>'">
                    </div>
                    <?php if ($statusDetail === 'កាត់ចោល'): ?>
                      <span class="stamp-bad-debt">BAD DEPT</span>
                    <?php elseif ($isClosed): ?>
                      <svg class="stamp-paid" viewBox="0 0 100 100" width="38" height="38">
                        <circle cx="50" cy="50" r="40" fill="none" stroke="#22c55e" stroke-width="6" />
                        <circle cx="50" cy="50" r="34" fill="none" stroke="#22c55e" stroke-width="1.5" />
                        <text x="50" y="58" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif" font-weight="900" font-size="24" fill="#22c55e" text-anchor="middle" letter-spacing="1">PAID</text>
                      </svg>
                    <?php endif; ?>
                  </div>

                  <div class="nwrap">
                    <!-- MAIN: Customer Name -->
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                      <?php if ($custId>0): ?>
                        <a href="<?= h($linkCust) ?>" class="text-decoration-none fw-bold">
                          <?= h($custName ?: '—') ?>
                        </a>
                      <?php else: ?>
                        <span class="fw-bold"><?= h($custName ?: '—') ?></span>
                      <?php endif; ?>
                    </div>

                    <!-- SUB: Phone only -->
                    <div class="p">
                      <?php if (trim($custPhone) !== ''): ?>
                        <i class="bi bi-telephone-fill"></i>
                        <span class="mono"><?= h($custPhone) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </td>

              <td class="mono fw-bold money">
                <?= fin_format_amount($amt, $ccy) ?>
              </td>

              <td class="d-none d-md-table-cell"><?= h($type ?: '—') ?></td>

              <td>
                <span class="sbadge"
                      style="background:<?= h($colr['bg']) ?>;border-color:<?= h($colr['bd']) ?>;color:<?= h($colr['tx']) ?>;">
                  <span class="dot" style="background:<?= h($colr['dot']) ?>;"></span>
                  <?= h($badgeText) ?>
                </span>
              </td>

              <td class="d-none d-md-table-cell mono">
                <div><?= h($start ?: '—') ?></div>
                <?php if ($totalScheds > 0): ?>
                  <div class="mt-1" style="font-size: 0.76rem;">
                    <?php if ($isClosed || $paidScheds >= $totalScheds): ?>
                      <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.72rem; padding: 2px 7px; border-radius: 999px;">
                        <i class="bi bi-check2-all me-1"></i>បង់ចប់ <?= $paidScheds ?>/<?= $totalScheds ?>
                      </span>
                    <?php else: ?>
                      <span class="text-primary fw-semibold"><i class="bi bi-clock-history me-1"></i>បង់បាន <?= $paidScheds ?>/<?= $totalScheds ?> លើក</span>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </td>
              <td class="d-none d-md-table-cell mono"><?= h($due ?: '—') ?></td>

              <td class="text-end text-nowrap">
                <div class="d-inline-flex gap-2 justify-content-end align-items-center">
                  <a class="btn-icon pri" href="<?= h($linkView) ?>" title="មើលលម្អិត"><i class="bi bi-eye"></i></a>
                  <?php if (!$isClosed): ?>
                    <a class="btn-action btn-pay text-nowrap" href="loan_payment_add.php?loan_id=<?= $loanId ?>" title="បង់ប្រាក់"><i class="bi bi-cash-coin me-1"></i>បង់ប្រាក់</a>
                    <a class="btn-action btn-settle text-nowrap" href="loan_payment_add.php?loan_id=<?= $loanId ?>&settle=1" title="បង់ផ្តាច់"><i class="bi bi-check2-circle me-1"></i>បង់ផ្តាច់</a>
                  <?php else: ?>
                    <?php if ($custId > 0): ?>
                      <a class="btn-action btn-new-loan text-nowrap" href="loan_add.php?customer_id=<?= $custId ?>" title="ស្នើកម្ចីថ្មី"><i class="bi bi-plus-circle me-1"></i>ស្នើកម្ចីថ្មី</a>
                    <?php endif; ?>
                    <span class="badge-paid-off text-nowrap" style="padding: 0.28rem 0.6rem; font-size: 0.75rem;" title="កម្ចីនេះបានបង់ផ្តាច់រួចរាល់"><i class="bi bi-patch-check-fill me-1"></i>បង់ផ្តាច់រួច</span>
                  <?php endif; ?>

                  <div class="dropdown d-inline-block">
                    <button class="btn-icon" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="ផ្សេងៗ">
                      <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="border-radius: 12px; font-size: 0.88rem;">
                      <?php if ($isClosed && $custId > 0): ?>
                        <li>
                          <a class="dropdown-item text-primary fw-semibold" href="loan_add.php?customer_id=<?= $custId ?>">
                            <i class="bi bi-plus-circle-fill me-2 text-primary"></i> ស្នើកម្ចីថ្មី
                          </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                      <?php endif; ?>
                      <li>
                        <a class="dropdown-item" href="<?= h($linkEdit) ?>">
                          <i class="bi bi-pencil-square me-2 text-warning"></i> កែប្រែកម្ចី
                        </a>
                      </li>
                      <?php if ($custId>0): ?>
                        <li>
                          <a class="dropdown-item" href="<?= h($linkCust) ?>">
                            <i class="bi bi-person-vcard me-2 text-primary"></i> ព័ត៌មានអតិថិជន
                          </a>
                        </li>
                      <?php endif; ?>
                      <li>
                        <a class="dropdown-item" href="loan_agreement_print.php?id=<?= $loanId ?>" target="_blank">
                          <i class="bi bi-file-earmark-text me-2 text-info"></i> កិច្ចសន្យាកម្ចី
                        </a>
                      </li>
                      <li>
                        <a class="dropdown-item" href="loan_schedule_print.php?id=<?= $loanId ?>" target="_blank">
                          <i class="bi bi-calendar3 me-2 text-secondary"></i> តារាងកាលវិភាគ
                        </a>
                      </li>
                      <li><hr class="dropdown-divider"></li>
                      <li>
                        <a class="dropdown-item text-danger" href="<?= h($linkDelete) ?>"
                           onclick="return confirm('តើអ្នកប្រាកដថាចង់លុបកម្ចីនេះឬ?');">
                          <i class="bi bi-trash3 me-2 text-danger"></i> លុបកម្ចី
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
                <div class="m-card <?= $rowClass ? $rowClass.'-card' : '' ?>">

                  <div class="m-top">
                    <div class="m-left">
                      <div class="avatar-container position-relative">
                        <div class="avatar">
                          <img src="<?= h($avatarSrc) ?>"
                               alt="<?= h($custName ?: 'Customer') ?>"
                               onerror="this.src='<?= h($defaultAvatar) ?>'">
                        </div>
                        <?php if ($statusDetail === 'កាត់ចោល'): ?>
                          <span class="stamp-bad-debt">BAD DEPT</span>
                        <?php elseif ($isClosed): ?>
                          <svg class="stamp-paid" viewBox="0 0 100 100" width="38" height="38">
                            <circle cx="50" cy="50" r="40" fill="none" stroke="#22c55e" stroke-width="6" />
                            <circle cx="50" cy="50" r="34" fill="none" stroke="#22c55e" stroke-width="1.5" />
                            <text x="50" y="58" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif" font-weight="900" font-size="24" fill="#22c55e" text-anchor="middle" letter-spacing="1">PAID</text>
                          </svg>
                        <?php endif; ?>
                      </div>

                      <div class="m-title">
                        <p class="name mb-0"><?= h($custName ?: '—') ?></p>
                        <div class="m-sub">
                          <?php if (trim($custPhone) !== ''): ?>
                            <i class="bi bi-telephone-fill"></i>
                            <span class="mono"><?= h($custPhone) ?></span>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>

                    <span class="sbadge"
                          style="background:<?= h($colr['bg']) ?>;border-color:<?= h($colr['bd']) ?>;color:<?= h($colr['tx']) ?>;">
                      <span class="dot" style="background:<?= h($colr['dot']) ?>;"></span>
                      <?= h($badgeText) ?>
                    </span>
                  </div>

                  <div class="m-kpi">
                    <div class="mini">
                      <div class="lbl"><i class="bi bi-cash-stack"></i> សាច់ប្រាក់</div>
                      <div class="val mono money"><?= fin_format_amount($amt, $ccy) ?></div>
                    </div>
                    <div class="mini">
                      <div class="lbl"><i class="bi bi-tag"></i> ប្រភេទ</div>
                      <div class="val"><?= h($type ?: '—') ?></div>
                    </div>
                    <div class="mini">
                      <div class="lbl"><i class="bi bi-calendar-event"></i> ថ្ងៃចាប់ផ្តើម</div>
                      <div class="val mono">
                        <div><?= h($start ?: '—') ?></div>
                        <?php if ($totalScheds > 0): ?>
                          <div class="mt-1" style="font-size: 0.75rem;">
                            <?php if ($isClosed || $paidScheds >= $totalScheds): ?>
                              <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.72rem; padding: 2px 7px; border-radius: 999px;">
                                <i class="bi bi-check2-all me-1"></i>បង់ចប់ <?= $paidScheds ?>/<?= $totalScheds ?>
                              </span>
                            <?php else: ?>
                              <span class="text-primary fw-semibold"><i class="bi bi-clock-history me-1"></i>បង់បាន <?= $paidScheds ?>/<?= $totalScheds ?> លើក</span>
                            <?php endif; ?>
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="mini">
                      <div class="lbl"><i class="bi bi-calendar2-week"></i> ថ្ងៃកំណត់</div>
                      <div class="val mono"><?= h($due ?: '—') ?></div>
                    </div>
                  </div>

                  <div class="m-actionbar">
                    <span class="m-rowno mono"><?= $rowNo ?></span>

                    <div class="m-actions">
                      <?php if ($isClosed && $custId > 0): ?>
                        <a class="btn-action btn-new-loan text-nowrap px-2" style="height: 32px; font-size: 0.82rem;" href="loan_add.php?customer_id=<?= $custId ?>" title="ស្នើកម្ចីថ្មី"><i class="bi bi-plus-circle me-1"></i> ស្នើកម្ចីថ្មី</a>
                      <?php endif; ?>
                      <a class="btn-icon pri text-nowrap px-2" style="width: auto; height: 32px; border-radius: 8px; font-size: 0.82rem; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; text-decoration: none;" href="<?= h($linkView) ?>" title="View"><i class="bi bi-eye" style="font-size: 0.95rem;"></i> មើល</a>
                      <?php if (!$isClosed): ?>
                        <a class="btn-action btn-pay text-nowrap px-2" style="height: 32px; font-size: 0.82rem;" href="loan_payment_add.php?loan_id=<?= $loanId ?>" title="បង់ប្រាក់"><i class="bi bi-cash-coin me-1"></i> បង់ប្រាក់</a>
                        <a class="btn-action btn-settle text-nowrap px-2" style="height: 32px; font-size: 0.82rem;" href="loan_payment_add.php?loan_id=<?= $loanId ?>&settle=1" title="បង់ផ្តាច់"><i class="bi bi-check2-circle me-1"></i> បង់ផ្តាច់</a>
                      <?php else: ?>
                        <span class="badge-paid-off text-nowrap" style="padding: 0.25rem 0.55rem; font-size: 0.74rem;"><i class="bi bi-patch-check-fill me-1"></i> បង់ផ្តាច់រួច</span>
                      <?php endif; ?>

                      <div class="dropdown">
                        <button class="btn-icon" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="More">
                          <i class="bi bi-three-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="border-radius: 12px; font-size: 0.88rem;">
                          <?php if ($isClosed && $custId > 0): ?>
                            <li>
                              <a class="dropdown-item text-primary fw-semibold" href="loan_add.php?customer_id=<?= $custId ?>">
                                <i class="bi bi-plus-circle-fill me-2 text-primary"></i> ស្នើកម្ចីថ្មី
                              </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                          <?php endif; ?>
                          <?php if ($custId>0): ?>
                            <li>
                              <a class="dropdown-item" href="<?= h($linkCust) ?>">
                                <i class="bi bi-person-vcard me-2 text-primary"></i> ព័ត៌មានអតិថិជន
                              </a>
                            </li>
                          <?php endif; ?>
                          <li>
                            <a class="dropdown-item" href="loan_agreement_print.php?id=<?= $loanId ?>" target="_blank">
                              <i class="bi bi-file-earmark-text me-2 text-info"></i> កិច្ចសន្យាកម្ចី
                            </a>
                          </li>
                          <li>
                            <a class="dropdown-item" href="loan_schedule_print.php?id=<?= $loanId ?>" target="_blank">
                              <i class="bi bi-calendar3 me-2 text-secondary"></i> តារាងកាលវិភាគ
                            </a>
                          </li>
                          <li><hr class="dropdown-divider"></li>
                          <li>
                            <a class="dropdown-item text-danger" href="<?= h($linkDelete) ?>"
                               onclick="return confirm('តើអ្នកប្រាកដថាចង់លុបកម្ចីនេះឬ?');">
                              <i class="bi bi-trash3 me-2 text-danger"></i> លុបកម្ចី
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
  <div class="d-flex flex-column align-items-center justify-content-center gap-2 mt-3" id="pagination-container">
    <div class="sub text-center">
      ទំព័រ <span class="fw-bold"><?= (int)$page ?></span> / <span class="fw-bold"><?= max(1,$totalPages) ?></span>
      • បង្ហាញ <span class="fw-bold"><?= fin_int(count($rows)) ?></span> នៃ <span class="fw-bold"><?= fin_int($totalRows) ?></span> កម្ចី
    </div>

    <nav class="d-flex justify-content-center">
      <ul class="pagination mb-0">
        <?php
          $prev = max(1, $page - 1);
          $next = min(max(1,$totalPages), $page + 1);

          $disablePrev = $page <= 1;
          $disableNext = $page >= max(1,$totalPages);

          $baseQsPrev = qs(['page'=>$prev]);
          $baseQsNext = qs(['page'=>$next]);
        ?>
        <li class="page-item <?= $disablePrev ? 'disabled' : '' ?>">
          <a class="page-link pagination-link" data-page="<?= $prev ?>" href="?<?= h($baseQsPrev) ?>">‹</a>
        </li>

        <?php
          $startP = max(1, $page - 2);
          $endP   = min(max(1,$totalPages), $page + 2);
          for ($p=$startP; $p<=$endP; $p++):
            $qsP = qs(['page'=>$p]);
        ?>
          <li class="page-item <?= $p===$page ? 'active' : '' ?>">
            <a class="page-link pagination-link" data-page="<?= $p ?>" href="?<?= h($qsP) ?>"><?= $p ?></a>
          </li>
        <?php endfor; ?>

        <li class="page-item <?= $disableNext ? 'disabled' : '' ?>">
          <a class="page-link pagination-link" data-page="<?= $next ?>" href="?<?= h($baseQsNext) ?>">›</a>
        </li>
      </ul>
    </nav>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Flatpickr -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script>
  // ✅ Khmer locale (same pattern as your reference)
  // Source reference: :contentReference[oaicite:0]{index=0} :contentReference[oaicite:1]{index=1} :contentReference[oaicite:2]{index=2}
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

  function setupDatePicker(selector){
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

  // AJAX Live Search & Pagination
  document.addEventListener('DOMContentLoaded', function() {
    const queryInput = document.getElementById('searchQuery');
    const typeSelect = document.getElementById('filterType');
    const statusSelect = document.getElementById('filterStatus');
    const statusDetailSelect = document.getElementById('filterStatusDetail');
    const currencySelect = document.getElementById('filterCurrency');
    const fromInput = document.getElementById('fromPicker');
    const toInput = document.getElementById('toPicker');
    const sortSelect = document.getElementById('filterSort');
    const perPageSelect = document.getElementById('filterPerPage');
    const btnSearch = document.getElementById('btnSearch');
    const btnClear = document.getElementById('btnClear');
    const customerIdInput = document.querySelector('input[name="customer_id"]');

    let debounceTimeout = null;

    function applyFilters(page = 1) {
      const q = queryInput ? queryInput.value.trim() : '';
      const loan_type = typeSelect ? typeSelect.value : '';
      const status = statusSelect ? statusSelect.value : '';
      const status_detail = statusDetailSelect ? statusDetailSelect.value : '';
      const currency = currencySelect ? currencySelect.value : '';
      const from = fromInput ? fromInput.value : '';
      const to = toInput ? toInput.value : '';
      const sort = sortSelect ? sortSelect.value : 'newest';
      const per_page = perPageSelect ? perPageSelect.value : '20';
      const customer_id = customerIdInput ? customerIdInput.value : '0';

      const url = 'loans.php?ajax=1&page=' + page +
                  '&q=' + encodeURIComponent(q) +
                  '&loan_type=' + encodeURIComponent(loan_type) +
                  '&status=' + encodeURIComponent(status) +
                  '&status_detail=' + encodeURIComponent(status_detail) +
                  '&currency=' + encodeURIComponent(currency) +
                  '&from=' + encodeURIComponent(from) +
                  '&to=' + encodeURIComponent(to) +
                  '&sort=' + encodeURIComponent(sort) +
                  '&per_page=' + encodeURIComponent(per_page) +
                  '&customer_id=' + encodeURIComponent(customer_id);

      fetch(url)
        .then(res => res.json())
        .then(data => {
          const tbody = document.getElementById('loans-tbody');
          const paginationContainer = document.getElementById('pagination-container');
          const totalLoansCount = document.getElementById('total-loans-count');

          if (tbody && data.table !== undefined) tbody.innerHTML = data.table;
          if (paginationContainer && data.pagination !== undefined) paginationContainer.innerHTML = data.pagination;
          if (totalLoansCount && data.totalRowsFormatted !== undefined) {
            totalLoansCount.innerText = data.totalRowsFormatted;
          }
        })
        .catch(err => console.error('Error fetching filtered loans:', err));
    }

    if (queryInput) {
      queryInput.addEventListener('input', function() {
        clearTimeout(debounceTimeout);
        debounceTimeout = setTimeout(() => applyFilters(1), 250);
      });
    }

    const selects = [typeSelect, statusSelect, statusDetailSelect, currencySelect, sortSelect, perPageSelect];
    selects.forEach(sel => {
      if (sel) sel.addEventListener('change', () => applyFilters(1));
    });

    if (btnSearch) {
      btnSearch.addEventListener('click', function(e) {
        e.preventDefault();
        applyFilters(1);
      });
    }

    if (btnClear) {
      btnClear.addEventListener('click', function(e) {
        e.preventDefault();
        if (queryInput) queryInput.value = '';
        if (typeSelect) typeSelect.value = '';
        if (statusSelect) statusSelect.value = '';
        if (statusDetailSelect) statusDetailSelect.value = '';
        if (currencySelect) currencySelect.value = '';
        if (fromInput) {
          if (fromInput._flatpickr) fromInput._flatpickr.clear();
          else fromInput.value = '';
        }
        if (toInput) {
          if (toInput._flatpickr) toInput._flatpickr.clear();
          else toInput.value = '';
        }
        if (sortSelect) sortSelect.value = 'newest';
        if (perPageSelect) perPageSelect.value = '20';
        applyFilters(1);
      });
    }

    setTimeout(function() {
      if (fromInput && fromInput._flatpickr) {
        fromInput._flatpickr.set('onChange', () => applyFilters(1));
      }
      if (toInput && toInput._flatpickr) {
        toInput._flatpickr.set('onChange', () => applyFilters(1));
      }
    }, 100);

    document.addEventListener('click', function(e) {
      const link = e.target.closest('.pagination-link');
      if (link) {
        e.preventDefault();
        if (link.parentNode.classList.contains('disabled')) return;
        const page = link.getAttribute('data-page');
        if (page) {
          applyFilters(page);
        }
      }
    });
  });
</script>
</body>
</html>
