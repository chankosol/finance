<?php
// /finance/admin/payment_collection.php
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

fin_require_login();
fin_require_permission('view_payment_collection');
$business_id = fin_require_business();
global $pdo;

/* ============================
   Helpers
============================ */
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

function fin_build_url(array $overrides = []): string {
  $q = $_GET;
  foreach ($overrides as $k => $v) {
    if ($v === null || $v === '') {
      unset($q[$k]);
    } else {
      $q[$k] = $v;
    }
  }
  $qs = http_build_query($q);
  return basename($_SERVER['PHP_SELF']) . ($qs ? "?{$qs}" : "");
}

function fin_format_amount($amt, $ccy): string {
  $ccy = strtoupper(trim($ccy));
  if ($ccy === 'KHR' || $ccy === '៛') {
    return number_format((float)$amt, 0) . ' ៛';
  }
  return '$ ' . number_format((float)$amt, 2);
}

function fin_format_summary_totals(array $totals, string $key, string $selected_ccy): string {
  $selected_ccy = strtoupper(trim($selected_ccy));
  if ($selected_ccy === 'USD') {
    return '$ ' . number_format($totals['USD'][$key] ?? 0, 2);
  }
  if ($selected_ccy === 'KHR') {
    return number_format($totals['KHR'][$key] ?? 0, 0) . ' ៛';
  }
  
  $usd_val = $totals['USD'][$key] ?? 0;
  $khr_val = $totals['KHR'][$key] ?? 0;
  
  $parts = [];
  if ($usd_val > 0 || ($usd_val == 0 && $khr_val == 0)) {
    $parts[] = '$ ' . number_format($usd_val, 2);
  }
  if ($khr_val > 0) {
    $parts[] = number_format($khr_val, 0) . ' ៛';
  }
  return implode(' / ', $parts);
}

function fin_type_badge(?string $type): string {
  $t = trim((string)$type);
  if ($t === '') {
    return '<span class="badge bg-secondary-subtle text-secondary px-2.5 py-1.5 fw-bold" style="font-size:0.82rem; border-radius:6px;">គ្មាន</span>';
  }
  
  $lower = mb_strtolower($t);
  if ($lower === 'general') {
    $badgeClass = 'bg-secondary-subtle text-secondary';
  } elseif ($lower === 'កម្ចីប្រាក់ប្រចាំថ្ងៃ') {
    $badgeClass = 'bg-warning-subtle text-warning-emphasis';
  } elseif ($lower === 'កម្ចីប្រាក់ប្រចាំខែ') {
    $badgeClass = 'bg-primary-subtle text-primary-emphasis';
  } elseif ($lower === 'បង់រំលស់ម៉ូតូ' || $lower === 'រំលស់ម៉ូតូ') {
    $badgeClass = 'bg-info-subtle text-info-emphasis';
  } else {
    $badgeClass = 'bg-dark-subtle text-dark-emphasis';
  }
  
  return '<span class="badge ' . $badgeClass . ' px-2.5 py-1.5 fw-bold" style="font-size:0.82rem; border-radius:6px;">' . h2($t) . '</span>';
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
function fin_initials(string $name): string {
  $name = trim(preg_replace('/\s+/', ' ', $name));
  if ($name === '') return 'U';
  $parts = explode(' ', $name);
  $a = mb_substr($parts[0], 0, 1);
  $b = (count($parts) > 1) ? mb_substr($parts[count($parts)-1], 0, 1) : '';
  $ini = mb_strtoupper($a . $b);
  if ($ini === '') $ini = 'U';
  return mb_substr($ini, 0, 2);
}

/* ✅ same as customer_edit.php (IMPORTANT) */
function fin_public_url(?string $path): string {
  $p = trim((string)$path);
  if ($p === '') return '';
  if (preg_match('#^https?://#i', $p)) return $p;
  if (str_starts_with($p, '/')) return $p;
  return '../' . ltrim($p, '/'); // because we are in /finance/admin/
}
function fin_avatar_by_gender(string $gender): string {
  $g = strtolower(trim($gender));
  if (in_array($g, ['male','m','ប្រុស'], true))   return '../uploads/avatars/male.png';
  if (in_array($g, ['female','f','ស្រី'], true)) return '../uploads/avatars/female.png';
  return '../uploads/avatars/default.png';
}
function fin_tel_digits(string $phone): string {
  return preg_replace('/\D+/', '', $phone);
}

/* ============================
   Handle missed action
============================ */
$action = trim((string)($_GET['action'] ?? ''));
if ($action === 'missed') {
  $schedBizCol = fin_col_exists($pdo,'loan_schedules','business_id') ? 'business_id' : (fin_col_exists($pdo,'loan_schedules','biz_id') ? 'biz_id' : 'business_id');
  $schedule_id = (int)($_GET['schedule_id'] ?? 0);
  if ($schedule_id > 0) {
    $st = $pdo->prepare("UPDATE loan_schedules SET status = 'MISSED' WHERE id = ? AND $schedBizCol = ?");
    $st->execute([$schedule_id, $business_id]);
    
    $redirectUrl = fin_build_url(['action' => null, 'schedule_id' => null]);
    header("Location: " . $redirectUrl);
    exit;
  }
}

/* ============================
   Detect columns
============================ */
$loansBizCol     = fin_col_exists($pdo,'loans','business_id') ? 'business_id' : (fin_col_exists($pdo,'loans','biz_id') ? 'biz_id' : 'business_id');
$customersBizCol = fin_col_exists($pdo,'customers','business_id') ? 'business_id' : (fin_col_exists($pdo,'customers','biz_id') ? 'biz_id' : 'business_id');
$schedBizCol     = fin_col_exists($pdo,'loan_schedules','business_id') ? 'business_id' : (fin_col_exists($pdo,'loan_schedules','biz_id') ? 'biz_id' : 'business_id');

$loanCodeCol     = fin_first_col($pdo,'loans',['loan_code','code','loan_no'],'');
$loanTypeCol     = fin_first_col($pdo,'loans',['loan_type','type','repayment_type','product_type'],'');
$loanRepayDayCol = fin_col_exists($pdo,'loans','repayment_day') ? 'repayment_day' : '';
$loanCcyCol      = fin_col_exists($pdo,'loans','currency_code') ? 'currency_code' : (fin_col_exists($pdo,'loans','currency') ? 'currency' : '');
$loanStatusCol   = fin_first_col($pdo,'loans',['status','loan_status'],'status');

$custNameCol     = fin_first_col($pdo,'customers',['full_name','name','customer_name'],'full_name');
$custPhoneCol    = fin_first_col($pdo,'customers',['phone','tel','mobile'],'phone');
$custGenderCol   = fin_first_col($pdo,'customers',['gender','sex'],'gender');
$custPhotoCol    = fin_first_col($pdo,'customers',['photo','photo_url','avatar','avatar_url','profile_photo','profile_image','image','img'],'photo');

$schedDueCol     = fin_first_col($pdo,'loan_schedules',['due_date','date_due'],'due_date');
$schedTotalCol   = fin_first_col($pdo,'loan_schedules',['total_due','amount_due','total'],'total_due');
$schedPaidCol    = fin_first_col($pdo,'loan_schedules',['paid_total','paid_amount','paid'],'paid_total');
$schedInstCol    = fin_first_col($pdo,'loan_schedules',['installment_no','inst_no','no'],'installment_no');

/* ============================
   Filters
============================ */
$today = date('Y-m-d');
$quick = trim((string)($_GET['quick'] ?? ''));

$date = trim((string)($_GET['date'] ?? ''));
if ($date !== '') {
  $quickDate = '';
  if ($quick === 'today') $quickDate = $today;
  elseif ($quick === 'tomorrow') $quickDate = date('Y-m-d', strtotime($today.' +1 day'));
  elseif ($quick === 'next')     $quickDate = date('Y-m-d', strtotime($today.' +2 day'));
  
  if ($quickDate !== '' && $date !== $quickDate) {
    $quick = '';
  }
}

if ($quick === 'today')    $date = $today;
if ($quick === 'tomorrow') $date = date('Y-m-d', strtotime($today.' +1 day'));
if ($quick === 'next')     $date = date('Y-m-d', strtotime($today.' +2 day'));
if ($date === '') $date = $today;

$dateStart = $date . " 00:00:00";
$dateEnd   = date('Y-m-d', strtotime($date.' +1 day')) . " 00:00:00";

$loan_type = trim((string)($_GET['loan_type'] ?? 'ALL'));
$f_currency = trim((string)($_GET['currency'] ?? 'ALL'));
$q = trim((string)($_GET['q'] ?? ''));

$only_remaining = (int)($_GET['only_remaining'] ?? 1);
$only_remaining = ($only_remaining === 0) ? 0 : 1;

$mode = trim((string)($_GET['mode'] ?? ''));
if ($mode === '') {
  $mode = $loanRepayDayCol ? 'repayment_day' : 'schedule';
}
if ($mode !== 'repayment_day') $mode = 'schedule';
if ($mode === 'repayment_day' && !$loanRepayDayCol) $mode = 'schedule';

$modeKh = ($mode === 'repayment_day') ? 'តាមថ្ងៃបង់ប្រចាំខែ' : 'តាមថ្ងៃកំណត់ (Schedule)';

/* ============================
   Loan types list
============================ */
$loanTypes = [];
if ($loanTypeCol) {
  $st = $pdo->prepare("
    SELECT DISTINCT TRIM(COALESCE($loanTypeCol,'')) AS t
    FROM loans
    WHERE $loansBizCol = ?
    ORDER BY t ASC
  ");
  $st->execute([$business_id]);
  $loanTypes = array_values(array_filter(array_map(
    fn($r)=>trim((string)($r['t'] ?? '')),
    $st->fetchAll(PDO::FETCH_ASSOC) ?: []
  )));
}

/* ============================
   Currencies list
============================ */
$currencyOptions = [];
if ($loanCcyCol) {
  $st = $pdo->prepare("
    SELECT UPPER(TRIM(COALESCE($loanCcyCol,''))) AS v
    FROM loans
    WHERE $loansBizCol = ?
    GROUP BY UPPER(TRIM(COALESCE($loanCcyCol,''))) HAVING v <> ''
    ORDER BY v ASC
  ");
  $st->execute([$business_id]);
  $currencyOptions = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/* ============================
   Query building
============================ */
$where = [];
$params = [];

$where[] = "s.$schedBizCol = ?";
$params[] = $business_id;

$where[] = "l.$loansBizCol = ?";
$params[] = $business_id;

$where[] = "c.$customersBizCol = ?";
$params[] = $business_id;

if ($loanStatusCol) {
  $where[] = "l.$loanStatusCol = 'ACTIVE'";
}

$remainingExpr = "GREATEST(COALESCE(s.$schedTotalCol,0) - COALESCE(s.$schedPaidCol,0), 0)";

if ($mode === 'repayment_day' && $loanRepayDayCol) {
  $dayNum = (int)date('j', strtotime($date));
  $where[] = "COALESCE(l.$loanRepayDayCol,0) = ?";
  $params[] = $dayNum;
  // Prevent showing future installments (only show current due and past overdue)
  $where[] = "s.$schedDueCol < ?";
  $params[] = $dateEnd;
} else {
  $where[] = "s.$schedDueCol >= ? AND s.$schedDueCol < ?";
  $params[] = $dateStart;
  $params[] = $dateEnd;
}

if ($loanTypeCol && $loan_type !== '' && $loan_type !== 'ALL') {
  $where[] = "TRIM(COALESCE(l.$loanTypeCol,'')) = ?";
  $params[] = $loan_type;
}

if ($loanCcyCol && $f_currency !== '' && $f_currency !== 'ALL') {
  $where[] = "UPPER(COALESCE(l.$loanCcyCol,'')) = UPPER(?)";
  $params[] = $f_currency;
}

if ($q !== '') {
  $like = '%'.$q.'%';
  $parts = [];
  $parts[] = "c.$custNameCol LIKE ?";  $params[] = $like;
  $parts[] = "c.$custPhoneCol LIKE ?"; $params[] = $like;
  if ($loanCodeCol) { $parts[] = "l.$loanCodeCol LIKE ?"; $params[] = $like; }
  $where[] = "(" . implode(" OR ", $parts) . ")";
}

if ($only_remaining === 1) {
  $where[] = "($remainingExpr) > 0";
}

$sql = "
  SELECT
    s.id AS schedule_id,
    s.loan_id,
    COALESCE(s.$schedInstCol,0) AS installment_no,
    s.$schedDueCol AS due_date,
    COALESCE(s.$schedTotalCol,0) AS total_due,
    COALESCE(s.$schedPaidCol,0) AS paid_total,
    s.status AS schedule_status,
    $remainingExpr AS remaining,

    c.id AS customer_id,
    c.$custNameCol AS customer_name,
    c.$custPhoneCol AS customer_phone,
    ".($custPhotoCol ? "c.$custPhotoCol AS customer_photo," : "'' AS customer_photo,")."
    ".($custGenderCol ? "c.$custGenderCol AS customer_gender," : "'' AS customer_gender,")."

    ".($loanCodeCol ? "l.$loanCodeCol AS loan_code," : "'' AS loan_code,")."
    ".($loanTypeCol ? "TRIM(COALESCE(l.$loanTypeCol,'')) AS loan_type," : "'' AS loan_type,")."
    ".($loanCcyCol ? "UPPER(TRIM(COALESCE(l.$loanCcyCol,'USD'))) AS currency_code," : "'USD' AS currency_code,")."
    ".($loanStatusCol ? "l.$loanStatusCol AS loan_status" : "'' AS loan_status")."
  FROM loan_schedules s
  JOIN loans l ON l.id = s.loan_id
  JOIN customers c ON c.id = l.customer_id
  WHERE ".implode(" AND ", $where)."
  ORDER BY s.$schedDueCol ASC, c.$custNameCol ASC, installment_no ASC, s.id ASC
  LIMIT 700
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ============================
   Totals
============================ */
$totals = [
  'USD' => ['due' => 0.0, 'paid' => 0.0, 'remaining' => 0.0],
  'KHR' => ['due' => 0.0, 'paid' => 0.0, 'remaining' => 0.0]
];
foreach ($rows as $r) {
  $ccy = strtoupper(trim($r['currency_code'] ?? 'USD'));
  if (!isset($totals[$ccy])) {
    $totals[$ccy] = ['due' => 0.0, 'paid' => 0.0, 'remaining' => 0.0];
  }
  $totals[$ccy]['due'] += (float)($r['total_due'] ?? 0);
  $totals[$ccy]['paid'] += (float)($r['paid_total'] ?? 0);
  $totals[$ccy]['remaining'] += (float)($r['remaining'] ?? 0);
}
$countRows = count($rows);

$due_custs = [];
$paid_custs = [];
$rem_custs = [];
foreach ($rows as $r) {
  $cid = $r['customer_id'];
  $due = (float)($r['total_due'] ?? 0);
  $paid = (float)($r['paid_total'] ?? 0);
  $rem = (float)($r['remaining'] ?? 0);
  if ($due > 0.009) {
    $due_custs[$cid] = true;
  }
  if ($paid > 0.009) {
    $paid_custs[$cid] = true;
  }
  if ($rem > 0.009) {
    $rem_custs[$cid] = true;
  }
}
$due_count = count($due_custs);
$paid_count = count($paid_custs);
$rem_count = count($rem_custs);

$labelToday    = $today;
$labelTomorrow = date('Y-m-d', strtotime($today.' +1 day'));
$labelNext     = date('Y-m-d', strtotime($today.' +2 day'));
?>
<!doctype html>
<html lang="km">
<head>
  <meta charset="utf-8">
  <title>ការប្រមូលប្រាក់ | Finance</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://fonts.googleapis.com/css2?family=Battambang:wght@300;400;700;900&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

  <!-- ✅ Flatpickr Khmer Calendar -->
  <link href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" rel="stylesheet">

  <style>
    :root{
      --bg:#f3f4f6; --card:#fff; --ink:#0f172a; --muted:#64748b; --line:#e5e7eb;
    }
    body{font-family:'Battambang',sans-serif;background:var(--bg); color:var(--ink); font-weight:300;}
    .flatpickr-calendar, .flatpickr-calendar * { font-family:'Battambang',sans-serif !important; font-size:.92rem; }
    .mono{font-variant-numeric:tabular-nums;}
    .cardx{background:var(--card);border:0;border-radius:18px;box-shadow:0 10px 25px rgba(0,0,0,.06);}

    .hero{
      background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
      border: none;
      border-radius: 18px;
      padding: 16px;
      box-shadow: 0 10px 25px rgba(29, 78, 216, 0.15);
      color: #fff;
    }
    .page-title{font-weight:400;margin:0;font-size:1.25rem;color:#fff;}
    .sub{color:rgba(255,255,255,0.85);font-size:.88rem;}

    .pill{
      display:inline-flex;align-items:center;gap:.35rem;
      border:1px solid var(--line);background:#fff;
      padding:.30rem .60rem;border-radius:999px;font-weight:400;
      text-decoration:none;color:var(--ink);
      font-size:.9rem;
      transition:.15s ease;
    }
    .pill.active{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;}
    .pill:hover{transform:translateY(-1px); box-shadow:0 10px 18px rgba(2,6,23,.08);}

    .form-label{font-weight:400;margin-bottom:.25rem;}
    .form-control,.form-select{
      border-radius:14px;border:1px solid rgba(15,23,42,.10);
      padding:.55rem .70rem;
    }
    .btn{border-radius:14px;font-weight:400;}
    .btn-sm{border-radius:12px;font-weight:400;}

    .summary{
      display:grid;
      grid-template-columns: repeat(3, 1fr);
      gap:8px;
      margin-top:8px;
    }
    @media (max-width:992px){ .summary{grid-template-columns: 1fr;} }
    .sum-box{
      border:1px solid rgba(15,23,42,.06);
      background:#fff;
      border-radius:14px;
      padding:10px 12px;
      display:flex;justify-content:space-between;align-items:center;
    }
    .sum-box .k{color:var(--muted);font-weight:400;font-size:.88rem;}
    .sum-box .v{font-weight:400;font-size:1.05rem;}
    .sum-box .ico{
      width:34px;height:34px;border-radius:12px;
      display:flex;align-items:center;justify-content:center;
      background:#eef2ff;color:#1d4ed8;
    }
    .sum-paid .ico{background:#dcfce7;color:#166534;}
    .sum-rem  .ico{background:#fee2e2;color:#991b1b;}

    .hint{color:var(--muted);font-size:.88rem;display:flex;gap:8px;align-items:flex-start;margin-top:6px;}

    .table thead th{font-weight:400;border-bottom:1px solid var(--line);white-space:nowrap;}
    .table tbody td{vertical-align:middle;}

    /* ✅ Customer cell */
    .cust-cell{display:flex; align-items:center; gap:10px; min-width:260px;}
    .cust-subline{font-size:.82rem;color:var(--muted);display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
    .cust-subline a{color:#0b5ed7;font-weight:400;text-decoration:none;}
    .cust-subline .sep{color:#cbd5e1;}

    /* ===== Desktop avatar ===== */
    .avatar{
      width:38px;height:38px;border-radius:999px;
      border:1px solid rgba(15,23,42,.12);
      background:#fff;
      display:grid;place-items:center;
      overflow:hidden; position:relative; flex:0 0 auto;
    }
    .avatar img{width:100%;height:100%;object-fit:cover;display:block;}
    .avatar .ini{
      position:absolute; inset:0;
      display:none;
      align-items:center; justify-content:center;
      font-weight:400;
    }
    .avatar.noimg img{display:none !important;}
    .avatar.noimg .ini{display:flex !important;}

    /* ✅ Toggle: table desktop, cards mobile */
    .table-wrap{display:block;}
    .cards{display:none;}
    @media (max-width: 992px){
      .table-wrap{display:none !important;}
      .cards{display:block !important;}
    }

    /* ===============================
       ✅ Row Card (mobile/tablet)
    =============================== */
    .rowcard{
      background:#fff;
      border:1px solid rgba(15,23,42,.10);
      border-radius:18px;
      box-shadow:0 10px 22px rgba(22,34,51,.06);
      padding:12px;
      margin:12px 0;
    }
    .rc-top{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;}
    .rc-left{display:flex;gap:10px;align-items:flex-start;min-width:0;flex:1 1 auto;}

    /* ===== Mobile avatar ===== */
    .rc-avatar{
      width:42px;height:42px;border-radius:999px;
      border:1px solid rgba(15,23,42,.12);
      background:#f8fafc;
      display:flex;align-items:center;justify-content:center;
      font-weight:400;
      position:relative; overflow:hidden; flex:0 0 auto;
    }
    .rc-avatar img{width:100%;height:100%;object-fit:cover;display:block;}
    .rc-avatar .ini{
      position:absolute; inset:0;
      display:none;
      align-items:center; justify-content:center;
      font-weight:400;
    }
    .rc-avatar.noimg img{display:none !important;}
    .rc-avatar.noimg .ini{display:flex !important;}

    .rc-title{min-width:0; flex:1 1 auto;}
    .rc-name{
      font-weight:400;
      font-size:1.08rem;
      line-height:1.15;
      display:-webkit-box;
      -webkit-line-clamp:2;
      -webkit-box-orient:vertical;
      overflow:hidden;
      word-break:break-word;
      margin-top:1px;
    }
    .rc-meta{
      margin-top:6px;
      display:flex;
      flex-wrap:wrap;
      gap:8px;
      align-items:center;
      color:var(--muted);
      font-size:.88rem;
    }
    .rc-chip{
      display:inline-flex;
      align-items:center;
      gap:6px;
      border:1px solid rgba(15,23,42,.10);
      background:#fff;
      border-radius:999px;
      padding:.18rem .55rem;
      font-weight:400;
      color:#0f172a;
      font-size:.82rem;
      max-width:100%;
    }
    .rc-chip a{color:#0b5ed7; font-weight:400;}
    .rc-chip .bi{font-size:.95rem;}

    .rc-status{
      display:inline-flex;
      align-items:center;
      gap:6px;
      padding:.22rem .65rem;
      border-radius:999px;
      border:1px solid rgba(34,197,94,.25);
      background:#dcfce7;
      color:#166534;
      font-weight:400;
      font-size:.82rem;
      white-space:nowrap;
      flex:0 0 auto;
    }

    .rc-grid{
      margin-top:10px;
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap:10px;
    }
    .rc-box{
      border:1px solid rgba(15,23,42,.10);
      border-radius:14px;
      padding:10px 10px;
      background:#fbfdff;
    }
    .rc-box .k{
      color:var(--muted);
      font-weight:400;
      font-size:.82rem;
      display:flex;
      align-items:center;
      gap:6px;
    }
    .rc-box .v{
      font-weight:400;
      font-size:1.05rem;
      margin-top:2px;
    }
    .rc-box .v.rem{color:#dc2626;}
    .rc-box .v.paid{color:#16a34a;}

    /* Enforce light weights globally to keep layout clean */
    .fw-bold, b, strong {
      font-weight: 400 !important;
    }

    .rc-footer{
      margin-top:10px;
      padding-top:10px;
      border-top:1px solid rgba(15,23,42,.08);
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:10px;
    }
    .rc-no {
      color: var(--muted);
      font-weight: 900;
      font-size: .82rem;
      width: 24px;
      height: 24px;
      border-radius: 999px;
      border: 1px solid rgba(15, 23, 42, 0.08);
      background: #f8fafc;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    /* pulsing dot */
    .status-pulse-dot {
      position: absolute;
      bottom: 0px;
      right: 0px;
      width: 12px;
      height: 12px;
      background-color: #22c55e;
      border: 2px solid #fff;
      border-radius: 999px;
      display: inline-block;
      box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
      animation: pulse-green 2s infinite;
      z-index: 2;
    }
    @keyframes pulse-green {
      0% {
        transform: scale(0.9);
        box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
      }
      70% {
        transform: scale(1);
        box-shadow: 0 0 0 6px rgba(34, 197, 94, 0);
      }
      100% {
        transform: scale(0.9);
        box-shadow: 0 0 0 0 rgba(34, 197, 94, 0);
      }
    }
    .rc-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end;}
    .rc-actions .btn{border-radius:12px;font-weight:400;padding:.50rem .70rem;line-height:1;}
    .rc-actions .btn .bi{font-size:1.05rem;}
    @media (max-width:420px){ .rc-actions .btn{padding:.52rem .62rem;} }
  </style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="container py-3">

  <div class="hero mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div class="d-flex align-items-center gap-3">
        <div class="rounded-3 d-inline-flex align-items-center justify-content-center"
             style="width:48px;height:48px;background:rgba(255,255,255,0.20);color:#fff;">
          <i class="bi bi-cash-coin fs-3"></i>
        </div>
        <div>
          <h4 class="page-title mb-0">ការប្រមូលប្រាក់</h4>
          <div class="mt-1">
            <span class="badge fs-6 px-2.5 py-1" style="background-color: rgba(255,255,255,0.20); color: #fff; border-radius: 8px; font-weight: bold; border: 1px solid rgba(255,255,255,0.25);">
              <span class="count-val"><?= (int)$countRows ?></span> នាក់
            </span>
          </div>
        </div>
      </div>

      <a href="dashboard.php" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left"></i> ត្រឡប់</a>
    </div>
  </div>

  <div class="cardx p-3 mb-3">
    <form method="get" action="payment_collection.php">
      <div class="row g-2 align-items-end">
        <div class="col-12 col-lg-auto">
          <label class="form-label d-none d-lg-block">&nbsp;</label>
          <div class="d-flex gap-1 flex-nowrap">
            <a class="pill <?= ($date === $labelToday) ? 'active' : '' ?>" href="<?= fin_build_url(['quick' => 'today', 'date' => null]) ?>" style="padding: .55rem .70rem; border-radius: 14px; white-space: nowrap;">ថ្ងៃនេះ</a>
            <a class="pill <?= ($date === $labelTomorrow) ? 'active' : '' ?>" href="<?= fin_build_url(['quick' => 'tomorrow', 'date' => null]) ?>" style="padding: .55rem .70rem; border-radius: 14px; white-space: nowrap;">ស្អែក</a>
            <a class="pill <?= ($date === $labelNext) ? 'active' : '' ?>" href="<?= fin_build_url(['quick' => 'next', 'date' => null]) ?>" style="padding: .55rem .70rem; border-radius: 14px; white-space: nowrap;">ថ្ងៃបន្ទាប់</a>
          </div>
        </div>

        <div class="col-6 col-lg-auto">
          <label class="form-label">ជ្រើសថ្ងៃ</label>
          <input type="text" name="date" id="date_pick" class="form-control js-kh-date"
                 value="<?= h2($date) ?>" placeholder="ថ្ងៃ-ខែ-ឆ្នាំ" inputmode="numeric">
        </div>

        <div class="col-6 col-lg-auto">
          <label class="form-label">ម៉ូដបង្ហាញ</label>
          <select class="form-select" name="mode" onchange="this.form.submit()">
            <option value="schedule" <?= $mode==='schedule'?'selected':'' ?>>តាមថ្ងៃកំណត់ (Schedule)</option>
            <?php if ($loanRepayDayCol): ?>
              <option value="repayment_day" <?= $mode==='repayment_day'?'selected':'' ?>>តាមថ្ងៃបង់ប្រចាំខែ</option>
            <?php endif; ?>
          </select>
        </div>

        <div class="col-6 col-lg-auto">
          <label class="form-label">ប្រភេទកម្ចី</label>
          <select class="form-select" name="loan_type" onchange="this.form.submit()">
            <option value="ALL">ប្រភេទទាំងអស់</option>
            <?php foreach ($loanTypes as $t): ?>
              <option value="<?= h2($t) ?>" <?= ($loan_type === $t ? 'selected' : '') ?>><?= h2($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-lg-auto">
          <label class="form-label d-none d-lg-block">&nbsp;</label>
          <button class="btn btn-primary w-100" type="submit"><i class="bi bi-funnel"></i> តម្រង</button>
        </div>

        <div class="col-12 col-lg">
          <label class="form-label">ស្វែងរក</label>
          <input type="text" name="q" class="form-control" placeholder="ស្វែងរក ឈ្មោះ / ទូរស័ព្ទ / លេខកម្ចី" value="<?= h2($q) ?>">
        </div>

        <?php if ($quick !== ''): ?>
          <input type="hidden" name="quick" value="<?= h2($quick) ?>">
        <?php endif; ?>
        <?php if ($f_currency !== 'ALL'): ?>
          <input type="hidden" name="currency" value="<?= h2($f_currency) ?>">
        <?php endif; ?>
        <?php if ($only_remaining !== 1): ?>
          <input type="hidden" name="only_remaining" value="<?= h2($only_remaining) ?>">
        <?php endif; ?>
      </div>
    </form>

    <div class="summary">
      <div class="sum-box">
        <div>
          <div class="k">សរុបត្រូវបង់</div>
          <div class="v mono sum-due-val"><?= fin_format_summary_totals($totals, 'due', $f_currency) ?> / <?= $due_count ?> នាក់</div>
        </div>
        <div class="ico"><i class="bi bi-receipt"></i></div>
      </div>
      <div class="sum-box sum-paid">
        <div>
          <div class="k">បានបង់</div>
          <div class="v mono text-success sum-paid-val"><?= fin_format_summary_totals($totals, 'paid', $f_currency) ?> / <?= $paid_count ?> នាក់</div>
        </div>
        <div class="ico"><i class="bi bi-check2-circle"></i></div>
      </div>
      <div class="sum-box sum-rem">
        <div>
          <div class="k">នៅសល់</div>
          <div class="v mono text-danger sum-rem-val"><?= fin_format_summary_totals($totals, 'remaining', $f_currency) ?> / <?= $rem_count ?> នាក់</div>
        </div>
        <div class="ico"><i class="bi bi-exclamation-circle"></i></div>
      </div>
    </div>
  </div>

  <!-- Desktop Table -->
  <div class="cardx p-0 overflow-hidden table-wrap">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:52px">#</th>
            <th>អតិថិជន</th>
            <!-- ✅ removed phone column -->
            <th>លេខកម្ចី</th>
            <th>ប្រភេទកម្ចី</th>
            <th>ថ្ងៃកំណត់</th>
            <th class="text-end">ត្រូវបង់</th>
            <th class="text-end">បានបង់</th>
            <th class="text-end">នៅសល់</th>
            <th class="text-end" style="width:260px">សកម្មភាព</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">មិនមានទិន្នន័យសម្រាប់ថ្ងៃនេះទេ។</td></tr>
        <?php else: ?>
          <?php $i=0; foreach ($rows as $r): $i++; ?>
            <?php
              $remaining = (float)($r['remaining'] ?? 0);
              $loanId = (int)($r['loan_id'] ?? 0);
              $custId = (int)($r['customer_id'] ?? 0);

              $custName  = (string)($r['customer_name'] ?? '');
              $custPhone = (string)($r['customer_phone'] ?? '');
              $telDigits = fin_tel_digits($custPhone);
              $telLink   = ($telDigits !== '') ? "tel:".$telDigits : "";

              $ini = fin_initials($custName);

              $photoDb = (string)($r['customer_photo'] ?? '');
              $photoUrl = $photoDb ? fin_public_url($photoDb) : '';
              $gender = (string)($r['customer_gender'] ?? '');
              $fallbackAvatar = fin_avatar_by_gender($gender);

              // ✅ always show an image (photo or gender default). initials only if even default fails
              $imgSrc = ($photoUrl !== '') ? $photoUrl : $fallbackAvatar;
              $hardDefault = '../uploads/avatars/default.png';

              $payUrl = "loan_payment_add.php?loan_id=".(int)($r['loan_id'] ?? 0)
                      ."&schedule_id=".(int)($r['schedule_id'] ?? 0)
                      ."&installment_no=".(int)($r['installment_no'] ?? $i)
                      ."&amount=".urlencode(number_format($remaining,2,'.',''))
                      ."&pay_date=".urlencode(date('Y-m-d'));
            ?>
            <tr class="payment-row"
                data-customer-id="<?= (int)$custId ?>"
                data-name="<?= h2(mb_strtolower($custName)) ?>"
                data-phone="<?= h2(mb_strtolower($custPhone)) ?>"
                data-code="<?= h2(mb_strtolower($r['loan_code'] ?? '')) ?>"
                data-due="<?= (float)($r['total_due'] ?? 0) ?>"
                data-paid="<?= (float)($r['paid_total'] ?? 0) ?>"
                data-rem="<?= $remaining ?>"
                data-ccy="<?= h2($r['currency_code'] ?? 'USD') ?>">
              <td class="fw-bold"><?= $i ?></td>

              <td>
                <div class="cust-cell">
                  <div class="position-relative flex-shrink-0">
                    <div class="avatar" title="<?= h2($custName) ?>">
                      <span class="ini"><?= h2($ini) ?></span>
                      <img src="<?= h2($imgSrc) ?>" alt="<?= h2($custName) ?>"
                           data-fallback="<?= h2($hardDefault) ?>"
                           onerror="finAvatarSwap(this)">
                    </div>
                    <?php if (strtoupper(trim((string)($r['schedule_status'] ?? ''))) !== 'MISSED'): ?>
                      <span class="status-pulse-dot"></span>
                    <?php endif; ?>
                  </div>

                  <div class="min-w-0">
                    <div class="fw-bold">
                      <a class="text-decoration-none" href="customer_view.php?id=<?= (int)$custId ?>">
                        <?= h2($custName) ?>
                      </a>
                    </div>

                    <!-- ✅ Phone moved here (replacing Profile position) -->
                    <div class="cust-subline">
                      <span>
                        <i class="bi bi-telephone"></i>
                        <?php if ($telLink): ?>
                          <a href="<?= h2($telLink) ?>"><?= h2($custPhone) ?></a>
                        <?php else: ?>
                          <?= h2($custPhone) ?>
                        <?php endif; ?>
                      </span>

                    </div>
                  </div>
                </div>
              </td>

              <!-- ✅ removed phone td -->
              <td class="mono"><?= h2($r['loan_code'] ?? '') ?></td>
              <td><?= fin_type_badge($r['loan_type'] ?? '') ?></td>
              <td class="mono"><?= h2(fin_format_date_kh(substr((string)($r['due_date'] ?? ''),0,10))) ?></td>
              <td class="text-end mono fw-bold"><?= fin_format_amount($r['total_due'] ?? 0, $r['currency_code'] ?? 'USD') ?></td>
              <td class="text-end mono text-success"><?= fin_format_amount($r['paid_total'] ?? 0, $r['currency_code'] ?? 'USD') ?></td>
              <td class="text-end mono fw-bold text-danger">
                <?= fin_format_amount($remaining, $r['currency_code'] ?? 'USD') ?>
                <?php if (strtoupper(trim((string)($r['schedule_status'] ?? ''))) === 'MISSED'): ?>
                  <div class="text-danger" style="font-size: 0.75rem; font-weight: normal; margin-top: 2px;">
                    <i class="bi bi-exclamation-circle"></i> មិនបានបង់
                  </div>
                <?php endif; ?>
              </td>

              <td class="text-end">
                <?php if ($remaining > 0.00001): ?>
                  <a class="btn btn-sm btn-success" href="<?= h2($payUrl) ?>">
                    <i class="bi bi-cash-coin me-1"></i> បង់ប្រាក់
                  </a>
                <?php else: ?>
                  <span class="btn btn-sm btn-outline-secondary disabled"><i class="bi bi-check2"></i> បង់រួច</span>
                <?php endif; ?>

                <div class="dropdown d-inline-block ms-1">
                  <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-three-dots-vertical"></i>
                  </button>
                  <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                      <a class="dropdown-item" href="loan_view.php?id=<?= (int)$loanId ?>">
                        <i class="bi bi-eye me-2"></i> មើលឥណទាន
                      </a>
                    </li>
                    <li>
                      <a class="dropdown-item" href="customer_view.php?id=<?= (int)$custId ?>">
                        <i class="bi bi-person me-2"></i> Customer Profile
                      </a>
                    </li>
                    <?php if ($remaining > 0.00001 && strtoupper(trim((string)($r['schedule_status'] ?? ''))) !== 'MISSED'): ?>
                      <li><hr class="dropdown-divider"></li>
                      <li>
                        <a class="dropdown-item text-danger" href="<?= h2(fin_build_url(['action' => 'missed', 'schedule_id' => $r['schedule_id']])) ?>" onclick="return confirm('តើអ្នកប្រាកដជាចង់កំណត់កាលវិភាគនេះជាមិនបានបង់មែនទេ?')">
                          <i class="bi bi-x-circle me-2"></i> មិនបានបង់
                        </a>
                      </li>
                    <?php endif; ?>
                  </ul>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ✅ Mobile/Tablet Row Cards (already has phone inside) -->
  <div class="cards">
    <?php if (!$rows): ?>
      <div class="cardx p-3 text-center text-muted">មិនមានទិន្នន័យសម្រាប់ថ្ងៃនេះទេ។</div>
    <?php else: ?>
      <?php $i=0; foreach ($rows as $r): $i++; ?>
        <?php
          $remaining = (float)($r['remaining'] ?? 0);

          $loanId = (int)($r['loan_id'] ?? 0);
          $custId = (int)($r['customer_id'] ?? 0);

          $scheduleId = (int)($r['schedule_id'] ?? 0);
          $instNo = (int)($r['installment_no'] ?? $i);
          $dueShort = substr((string)($r['due_date'] ?? ''),0,10);

          $custName = (string)($r['customer_name'] ?? '');
          $custPhone= (string)($r['customer_phone'] ?? '');
          $loanCode = (string)($r['loan_code'] ?? '');
          $loanType = (string)($r['loan_type'] ?? '');

          $ini = fin_initials($custName);

          $photoDb = (string)($r['customer_photo'] ?? '');
          $photoUrl = $photoDb ? fin_public_url($photoDb) : '';
          $gender = (string)($r['customer_gender'] ?? '');
          $fallbackAvatar = fin_avatar_by_gender($gender);

          $imgSrc = ($photoUrl !== '') ? $photoUrl : $fallbackAvatar;
          $hardDefault = '../uploads/avatars/default.png';

          $payUrl = "loan_payment_add.php?loan_id=".$loanId
                  ."&schedule_id=".$scheduleId
                  ."&installment_no=".$instNo
                  ."&amount=".urlencode(number_format($remaining,2,'.',''))
                  ."&pay_date=".urlencode(date('Y-m-d'));

          $telDigits = fin_tel_digits($custPhone);
          $telLink = ($telDigits !== '') ? "tel:".$telDigits : "";
        ?>
        <div class="rowcard payment-card"
             data-customer-id="<?= (int)$custId ?>"
             data-name="<?= h2(mb_strtolower($custName)) ?>"
             data-phone="<?= h2(mb_strtolower($custPhone)) ?>"
             data-code="<?= h2(mb_strtolower($r['loan_code'] ?? '')) ?>"
             data-due="<?= (float)($r['total_due'] ?? 0) ?>"
             data-paid="<?= (float)($r['paid_total'] ?? 0) ?>"
             data-rem="<?= $remaining ?>"
             data-ccy="<?= h2($r['currency_code'] ?? 'USD') ?>">
          <div class="rc-top">
            <div class="rc-left">
              <div class="position-relative flex-shrink-0">
                <div class="rc-avatar" title="<?= h2($custName) ?>">
                  <span class="ini"><?= h2($ini) ?></span>
                  <img src="<?= h2($imgSrc) ?>" alt="<?= h2($custName) ?>"
                       data-fallback="<?= h2($hardDefault) ?>"
                       onerror="finAvatarSwap(this)">
                </div>
                <?php
                  $schedStatus = strtoupper(trim((string)($r['schedule_status'] ?? '')));
                ?>
                <?php if ($schedStatus !== 'MISSED'): ?>
                  <span class="status-pulse-dot"></span>
                <?php endif; ?>
              </div>

              <div class="rc-title">
                <div class="rc-name">
                  <a class="text-decoration-none" href="customer_view.php?id=<?= (int)$custId ?>"><?= h2($custName) ?></a>
                </div>

                <div class="rc-meta">
                  <span class="rc-chip">
                    <i class="bi bi-telephone"></i>
                    <?php if ($telLink): ?>
                      <a href="<?= h2($telLink) ?>" class="text-decoration-none"><?= h2($custPhone) ?></a>
                    <?php else: ?>
                      <?= h2($custPhone) ?>
                    <?php endif; ?>
                  </span>
                </div>
              </div>
            </div>

            <div class="text-end flex-shrink-0">
              <?php if ($schedStatus === 'MISSED'): ?>
                <div class="rc-status mb-1" style="border-color: rgba(220,38,38,0.25); background: #fee2e2; color: #991b1b; display: inline-flex;">
                  <i class="bi bi-x-circle"></i> មិនបានបង់
                </div>
              <?php else: ?>
                <?php if ($loanType !== ''): ?>
                  <div style="display: inline-flex;"><?= fin_type_badge($loanType) ?></div>
                <?php endif; ?>
              <?php endif; ?>
              
              <?php if ($loanCode !== ''): ?>
                <div class="mono text-muted mt-1" style="font-size: 0.72rem; font-weight: 800;">
                  #<?= h2($loanCode) ?>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="rc-grid">
            <div class="rc-box">
              <div class="k"><i class="bi bi-hourglass-split"></i> ត្រូវបង់</div>
              <div class="v mono"><?= fin_format_amount($r['total_due'] ?? 0, $r['currency_code'] ?? 'USD') ?></div>
            </div>
            <div class="rc-box">
              <div class="k"><i class="bi bi-cash-stack"></i> លើកទី</div>
              <div class="v mono"><?= (int)$instNo ?></div>
            </div>

            <div class="rc-box">
              <div class="k"><i class="bi bi-check2-circle"></i> បានបង់</div>
              <div class="v mono paid"><?= fin_format_amount($r['paid_total'] ?? 0, $r['currency_code'] ?? 'USD') ?></div>
            </div>
            <div class="rc-box">
              <div class="k"><i class="bi bi-exclamation-circle"></i> នៅសល់</div>
              <div class="v mono rem"><?= fin_format_amount($remaining, $r['currency_code'] ?? 'USD') ?></div>
            </div>
          </div>

          <div class="rc-footer">
            <div class="rc-no"><?= (int)$i ?></div>

            <div class="rc-actions">
              <?php if ($remaining > 0.00001): ?>
                <a class="btn btn-success" href="<?= h2($payUrl) ?>" title="បង់ប្រាក់">
                  <i class="bi bi-cash-coin me-1"></i> បង់ប្រាក់
                </a>
              <?php else: ?>
                <span class="btn btn-outline-secondary disabled" title="បង់រួច">
                  <i class="bi bi-check2 me-1"></i> បង់រួច
                </span>
              <?php endif; ?>

              <div class="dropdown d-inline-block">
                <button class="btn btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                  <i class="bi bi-three-dots-vertical"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <li>
                    <a class="dropdown-item" href="loan_view.php?id=<?= (int)$loanId ?>">
                      <i class="bi bi-eye me-2"></i> មើលឥណទាន
                    </a>
                  </li>
                  <li>
                    <a class="dropdown-item" href="customer_view.php?id=<?= (int)$custId ?>">
                      <i class="bi bi-person me-2"></i> Customer Profile
                    </a>
                  </li>
                  <?php if ($remaining > 0.00001 && $schedStatus !== 'MISSED'): ?>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                      <a class="dropdown-item text-danger" href="<?= h2(fin_build_url(['action' => 'missed', 'schedule_id' => $scheduleId])) ?>" onclick="return confirm('តើអ្នកប្រាកដជាចង់កំណត់កាលវិភាគនេះជាមិនបានបង់មែនទេ?')">
                        <i class="bi bi-x-circle me-2"></i> មិនបានបង់
                      </a>
                    </li>
                  <?php endif; ?>
                </ul>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- ✅ Flatpickr Khmer -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/km.js"></script>

<script>
  // ✅ Khmer date picker
  (function(){
    var el = document.getElementById('date_pick');
    if(!el) return;
    flatpickr(el,{
      dateFormat:"Y-m-d",
      allowInput:true,
      locale:"km",
      disableMobile:true,
      altInput: true,
      altFormat: "d-F-Y",
      onChange: function(selectedDates, dateStr, instance) {
        if(dateStr) {
          var form = el.form;
          if (form) {
            var qInput = form.querySelector('input[name="quick"]');
            if (qInput) qInput.value = '';
            form.submit();
          }
        }
      }
    });
  })();

  // ✅ Avatar fallback (photo -> gender default -> default.png -> initials)
  function finAvatarSwap(img){
    try{
      if(!img.dataset.try2){
        img.dataset.try2 = "1";
        img.src = img.dataset.fallback || "../uploads/avatars/default.png";
        return;
      }
      var box = img.closest('.avatar, .rc-avatar');
      if(box) box.classList.add('noimg');
    }catch(e){}
  }

  // ✅ Live Search
  (function(){
    var qInput = document.querySelector('input[name="q"]');
    if (!qInput) return;

    qInput.addEventListener('input', function(e) {
      var query = e.target.value.toLowerCase().trim();

      // filter table rows
      var rows = document.querySelectorAll('.payment-row');
      rows.forEach(function(row) {
        var name = row.dataset.name || '';
        var phone = row.dataset.phone || '';
        var code = row.dataset.code || '';
        var matches = name.includes(query) || phone.includes(query) || code.includes(query);
        row.style.display = matches ? '' : 'none';
      });

      // filter mobile cards
      var cards = document.querySelectorAll('.payment-card');
      cards.forEach(function(card) {
        var name = card.dataset.name || '';
        var phone = card.dataset.phone || '';
        var code = card.dataset.code || '';
        var matches = name.includes(query) || phone.includes(query) || code.includes(query);
        card.style.display = matches ? '' : 'none';
      });

      // Recalculate summary and count based on visible elements
      recalculateTotals();
    });

    function recalculateTotals() {
      var visibleRows = Array.from(document.querySelectorAll('.payment-row')).filter(function(row) {
        return row.style.display !== 'none';
      });

      // Update count
      var countVal = document.querySelector('.count-val');
      if (countVal) {
        countVal.textContent = visibleRows.length;
      }

      // Sum by currency and count unique customers
      var usdDue = 0, usdPaid = 0, usdRem = 0;
      var khrDue = 0, khrPaid = 0, khrRem = 0;
      var dueCusts = new Set();
      var paidCusts = new Set();
      var remCusts = new Set();

      visibleRows.forEach(function(row) {
        var cid = row.dataset.customerId;
        var ccy = row.dataset.ccy || 'USD';
        var due = parseFloat(row.dataset.due || 0);
        var paid = parseFloat(row.dataset.paid || 0);
        var rem = parseFloat(row.dataset.rem || 0);

        if (due > 0.009) dueCusts.add(cid);
        if (paid > 0.009) paidCusts.add(cid);
        if (rem > 0.009) remCusts.add(cid);

        if (ccy === 'KHR') {
          khrDue += due;
          khrPaid += paid;
          khrRem += rem;
        } else {
          usdDue += due;
          usdPaid += paid;
          usdRem += rem;
        }
      });

      // Format helper inside JS matching PHP
      function formatCcy(usd, khr) {
        var selectedCcy = '<?= h2($f_currency) ?>'.toUpperCase();
        if (selectedCcy === 'USD') {
          return '$ ' + usd.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        if (selectedCcy === 'KHR') {
          return khr.toLocaleString('en-US', { maximumFractionDigits: 0 }) + ' ៛';
        }

        var parts = [];
        if (usd > 0 || (usd === 0 && khr === 0)) {
          parts.push('$ ' + usd.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
        }
        if (khr > 0) {
          parts.push(khr.toLocaleString('en-US', { maximumFractionDigits: 0 }) + ' ៛');
        }
        return parts.join(' / ');
      }

      // Update summary values
      var sumDue = document.querySelector('.sum-due-val');
      var sumPaid = document.querySelector('.sum-paid-val');
      var sumRem = document.querySelector('.sum-rem-val');

      if (sumDue) sumDue.textContent = formatCcy(usdDue, khrDue) + ' / ' + dueCusts.size + ' នាក់';
      if (sumPaid) sumPaid.textContent = formatCcy(usdPaid, khrPaid) + ' / ' + paidCusts.size + ' នាក់';
      if (sumRem) sumRem.textContent = formatCcy(usdRem, khrRem) + ' / ' + remCusts.size + ' នាក់';
    }
  })();
</script>

</body>
</html>
