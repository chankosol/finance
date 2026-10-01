<?php
// /finance/admin/customer_view.php
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

fin_require_login();
fin_require_permission('view_customers');
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
    foreach ($candidates as $c) if (fin_col_exists($pdo, $table, $c)) return $c;
    return $fallback;
}
function fin_money($n): string { return number_format((float)$n, 2); }

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

/* ---------------- validate id ---------------- */
$customer_id = (int)($_GET['id'] ?? 0);
if ($customer_id <= 0) {
    header('Location: customers.php');
    exit;
}

/* ---------------- ensure table exists ---------------- */
if (!fin_table_exists($pdo, 'customers')) {
    http_response_code(500);
    echo "<h3 style='font-family:Battambang,sans-serif'>Error</h3><p style='font-family:Battambang,sans-serif'>តារាង customers មិនមានក្នុង DB ទេ។</p>";
    exit;
}

/* ---------------- detect columns ---------------- */
$customersBizCol = fin_col_exists($pdo,'customers','business_id') ? 'business_id' : (fin_col_exists($pdo,'customers','biz_id') ? 'biz_id' : 'business_id');

$colName   = fin_first_col($pdo,'customers',['full_name','name','customer_name'],'full_name');
$colPhone  = fin_first_col($pdo,'customers',['phone','phone_number','tel'],'phone');
$colPhone2 = fin_first_col($pdo,'customers',['phone2','second_phone','alt_phone'],'');
$colAddr   = fin_first_col($pdo,'customers',['address','addr','home_address'],'address');
$colGender = fin_first_col($pdo,'customers',['gender','sex'],'gender');
$colDob    = fin_first_col($pdo,'customers',['dob','date_of_birth','birth_date'],'');
$colIdCard = fin_first_col($pdo,'customers',['id_card','national_id','id_number'],'');
$colNote   = fin_first_col($pdo,'customers',['note','remark','description'],'note');
$colPhoto  = fin_first_col($pdo,'customers',['photo','photo_path','avatar'],'');
$colActive = fin_col_exists($pdo,'customers','is_active') ? 'is_active' : '';

/* ---------------- fetch customer (secure by business) ---------------- */
$selectCustomer = "c.id,
                   c.`{$colName}` AS full_name,
                   c.`{$colPhone}` AS phone,
                   " . ($colPhone2 ? "c.`{$colPhone2}` AS phone2," : "'' AS phone2,") . "
                   " . ($colGender ? "c.`{$colGender}` AS gender," : "'' AS gender,") . "
                   " . ($colAddr ? "c.`{$colAddr}` AS address," : "'' AS address,") . "
                   " . ($colDob ? "c.`{$colDob}` AS dob," : "NULL AS dob,") . "
                   " . ($colIdCard ? "c.`{$colIdCard}` AS id_card," : "'' AS id_card,") . "
                   " . ($colNote ? "c.`{$colNote}` AS note," : "'' AS note,") . "
                   " . ($colPhoto ? "c.`{$colPhoto}` AS photo," : "'' AS photo,") . "
                   " . ($colActive ? "c.`{$colActive}` AS is_active" : "1 AS is_active") . "
                  ";

$st = $pdo->prepare("
    SELECT {$selectCustomer}
    FROM customers c
    WHERE c.id = ?
      AND c.{$customersBizCol} = ?
    LIMIT 1
");
$st->execute([$customer_id, $business_id]);
$customer = $st->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    http_response_code(404);
    echo "<h3 style='font-family:Battambang,sans-serif'>404</h3><p style='font-family:Battambang,sans-serif'>រកមិនឃើញអតិថិជននេះទេ។</p>";
    exit;
}

/* ---------------- loans + schedules aggregates (optional) ---------------- */
$loansExists = fin_table_exists($pdo,'loans');
$schedulesExists = fin_table_exists($pdo,'loan_schedules');

$loanRows = [];
$total_loans = 0;
$sum_principal = 0.0;
$sum_total_due = 0.0;
$sum_total_paid = 0.0;
$sum_outstanding = 0.0;

if ($loansExists) {
    $loanBizCol = fin_col_exists($pdo,'loans','business_id') ? 'business_id' : (fin_col_exists($pdo,'loans','biz_id') ? 'biz_id' : 'business_id');
    $loanCustomerCol = fin_first_col($pdo,'loans',['customer_id','client_id'],'customer_id');

    $loanCodeCol = fin_first_col($pdo,'loans',['loan_code','code','ref_no'],'');
    $principalCol = fin_first_col($pdo,'loans',['principal_amount','principal','amount'],'principal');
    $startCol = fin_first_col($pdo,'loans',['start_date','created_at','date_start'],'start_date');
    $dueCol = fin_first_col($pdo,'loans',['due_date','end_date','maturity_date'],'end_date');
    $rateCol = fin_first_col($pdo,'loans',['interest_rate','rate'],'interest_rate');
    $methodCol = fin_first_col($pdo,'loans',['interest_method','interest_type'],'interest_type');
    $statusCol = fin_first_col($pdo,'loans',['status','loan_status'],'status');

    // schedule cols
    $schedBizCol = $schedulesExists ? (fin_col_exists($pdo,'loan_schedules','business_id') ? 'business_id' : (fin_col_exists($pdo,'loan_schedules','biz_id') ? 'biz_id' : 'business_id')) : 'business_id';
    $schedLoanCol = $schedulesExists ? fin_first_col($pdo,'loan_schedules',['loan_id'],'loan_id') : 'loan_id';
    $schedTotalCol= $schedulesExists ? fin_first_col($pdo,'loan_schedules',['total_due','amount_due','total'],'total_due') : 'total_due';
    $schedPaidCol = $schedulesExists ? fin_first_col($pdo,'loan_schedules',['paid_total','paid','paid_amount'],'paid_total') : 'paid_total';

    $fields = [];
    $fields[] = "l.id";
    $fields[] = $loanCodeCol ? "l.`{$loanCodeCol}` AS loan_code" : "'' AS loan_code";
    $fields[] = "l.`{$principalCol}` AS principal";
    $fields[] = $rateCol ? "l.`{$rateCol}` AS interest_rate" : "NULL AS interest_rate";
    $fields[] = $methodCol ? "l.`{$methodCol}` AS interest_method" : "'' AS interest_method";
    $fields[] = $startCol ? "l.`{$startCol}` AS start_date" : "NULL AS start_date";
    $fields[] = $dueCol ? "l.`{$dueCol}` AS due_date" : "NULL AS due_date";
    $fields[] = $statusCol ? "l.`{$statusCol}` AS status" : "'' AS status";
    $fields[] = fin_col_exists($pdo, 'loans', 'status_detail') ? "l.status_detail" : "'' AS status_detail";
    $fields[] = fin_col_exists($pdo, 'loans', 'currency_code') ? "l.currency_code" : "'USD' AS currency_code";

    $joinAgg = "";
    if ($schedulesExists) {
        $fields[] = "COALESCE(ag.total_due,0) AS total_due";
        $fields[] = "COALESCE(ag.total_paid,0) AS total_paid";
        $fields[] = "GREATEST(0, COALESCE(ag.total_due,0) - COALESCE(ag.total_paid,0)) AS outstanding";

        $joinAgg = "
            LEFT JOIN (
                SELECT s.`{$schedLoanCol}` AS loan_id,
                       COALESCE(SUM(COALESCE(s.`{$schedTotalCol}`,0)),0) AS total_due,
                       COALESCE(SUM(COALESCE(s.`{$schedPaidCol}`,0)),0) AS total_paid
                FROM loan_schedules s
                WHERE s.{$schedBizCol} = ?
                GROUP BY s.`{$schedLoanCol}`
            ) ag ON ag.loan_id = l.id
        ";
    }

    $selectClause = implode(",\n            ", $fields);

    $sql = "
        SELECT
            {$selectClause}
        FROM loans l
        {$joinAgg}
        WHERE l.{$loanBizCol} = ?
          AND l.{$loanCustomerCol} = ?
        ORDER BY l.id DESC
        LIMIT 500
    ";

    // careful order: if schedulesExists, params = [biz_for_schedulesAgg, biz_for_loansWhere, customer]
    if ($schedulesExists) {
        $exec = [$business_id, $business_id, $customer_id];
    } else {
        $exec = [$business_id, $customer_id];
    }

    $stL = $pdo->prepare($sql);
    $stL->execute($exec);
    $loanRows = $stL->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $total_loans = count($loanRows);
    foreach ($loanRows as $lr) {
        $sum_principal += (float)($lr['principal'] ?? 0);
        if (isset($lr['total_due']))  $sum_total_due += (float)$lr['total_due'];
        if (isset($lr['total_paid'])) $sum_total_paid += (float)$lr['total_paid'];
        if (isset($lr['outstanding'])) $sum_outstanding += (float)$lr['outstanding'];
    }
}
$customer_currency = 'USD';
if (!empty($loanRows)) {
    $customer_currency = $loanRows[0]['currency_code'] ?? 'USD';
}

/* ---------------- UI helpers ---------------- */
function fin_badge_gender($g): string {
    $v = strtolower(trim((string)$g));
    if ($v === 'male' || $v === 'm' || $v === 'ប្រុស') return '<span class="badge-soft badge-male"><i class="bi bi-gender-male"></i> ប្រុស</span>';
    if ($v === 'female' || $v === 'f' || $v === 'ស្រី') return '<span class="badge-soft badge-female"><i class="bi bi-gender-female"></i> ស្រី</span>';
    return '<span class="badge-soft">—</span>';
}
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
function fin_badge_status($status, $status_detail = ''): string {
    $txt = trim($status_detail) !== '' ? $status_detail : (trim($status) !== '' ? $status : '—');
    $color = fin_status_color($txt);
    return '<span class="badge-soft" style="background: '.$color['bg'].'; border-color: '.$color['bd'].'; color: '.$color['tx'].'; padding: 0.25rem 0.6rem; border-radius: 999px;">'.h2($txt).'</span>';
}
function fin_format_money_with_symbol($amount, $currency): string {
    $amount_str = number_format((float)$amount, ($currency === 'KHR' ? 0 : 2));
    if ($currency === 'KHR') {
        return $amount_str . ' ៛';
    } else {
        return '$' . $amount_str;
    }
}
function fin_method_kh($m): string {
    $v = strtoupper(trim((string)$m));
    if ($v === 'REDUCING' || $v === 'DECLINING') return 'ថយចុះ (Reducing)';
    if ($v === 'FLAT') return 'ផ្ទាត់ (Flat)';
    if ($v === 'FIXED') return 'ថេរ (Fixed)';
    return $m ?: '—';
}
?>
<!doctype html>
<html lang="km">
<head>
  <meta charset="utf-8">
  <title>លម្អិតអតិថិជន | Finance</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://fonts.googleapis.com/css2?family=Battambang:wght@300;400;700;900&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

  <style>
    :root{
      --bg:#f3f4f6; --card:#fff; --ink:#0f172a; --muted:#64748b;
      --line:#e5e7eb; --primary:#2563eb;
    }
    body{font-family:'Battambang',sans-serif;background:var(--bg); color:var(--ink); font-weight:300;}
    .page-title{font-weight:400;}
    .sub{color:var(--muted); font-size:.92rem;}
    .cardx{background:var(--card); border:1px solid rgba(15,23,42,.08); border-radius:18px; box-shadow:none;}
    .kpi{
      border:1px solid var(--line);
      background:linear-gradient(180deg, #fff, #fbfdff);
      border-radius:16px; padding:12px 14px; height:100%;
      transition: all 0.2s ease;
    }
    .kpi:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
    }
    .kpi .lbl{color:var(--muted); font-size:.85rem;}
    .kpi .val{font-size:1.2rem; font-weight:400; margin-top:2px;}
    .kpi-blue {
      border-color: rgba(37, 99, 235, 0.15) !important;
      background: linear-gradient(180deg, #ffffff, #eff6ff) !important;
    }
    .kpi-blue .lbl { color: #1e40af !important; }
    .kpi-blue .kpi-icon { color: #2563eb !important; }
    .kpi-green {
      border-color: rgba(22, 163, 74, 0.15) !important;
      background: linear-gradient(180deg, #ffffff, #f0fdf4) !important;
    }
    .kpi-green .lbl { color: #166534 !important; }
    .kpi-green .kpi-icon { color: #16a34a !important; }
    .kpi-orange {
      border-color: rgba(217, 119, 6, 0.15) !important;
      background: linear-gradient(180deg, #ffffff, #fffbeb) !important;
    }
    .kpi-orange .lbl { color: #92400e !important; }
    .kpi-orange .kpi-icon { color: #d97706 !important; }
    .kpi-red {
      border-color: rgba(220, 38, 38, 0.15) !important;
      background: linear-gradient(180deg, #ffffff, #fef2f2) !important;
    }
    .kpi-red .lbl { color: #991b1b !important; }
    .kpi-red .kpi-icon { color: #dc2626 !important; }
    .mono{font-variant-numeric:tabular-nums;}
    .btn-soft{background:#fff; border:1px solid var(--line); border-radius:12px;}
    
    .badge-soft{
      border:1px solid var(--line);
      border-radius:999px;
      padding:.25rem .55rem;
      font-weight:400;
      font-size:.72rem;
      background:#fff;
      display:inline-block;
    }
    .badge{
      font-size:.72rem;
      font-weight:400 !important;
    }
    .badge-male{background:#eff6ff; border-color:#bfdbfe; color:#1d4ed8;}
    .badge-female{background:#fdf2f8; border-color:#fbcfe8; color:#9d174d;}
    .badge-active{background:#dcfce7; border-color:#bbf7d0; color:#166534;}
    .badge-inactive{background:#fee2e2; border-color:#fecaca; color:#991b1b;}
    .badge-id{background:#eff6ff; border-color:#bfdbfe; color:#1e40af;}
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
      font-size: 0.82rem !important;
    }
    .paid-check-badge {
      position: absolute;
      bottom: 0px;
      right: 0px;
      width: 22px;
      height: 22px;
      border-radius: 50%;
      background: #16a34a;
      color: #fff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 13px;
      font-weight: 900;
      border: 2px solid #fff;
      box-shadow: 0 2px 6px rgba(22, 163, 74, 0.45);
      z-index: 6;
    }
    .paid-check-badge i {
      -webkit-text-stroke: 0.5px #fff;
      line-height: 1;
    }
    
    .table th{white-space:nowrap; font-weight:400;}
    .table td{font-weight:400;}

    /* Enforce light weights globally to keep layout clean */
    .fw-bold, b, strong, th, .form-label, .btn, .page-title, .kpi .lbl, .kpi .val, .badge-soft, .fw-semibold {
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

    /* Page Header Gradient Style */
    .page-header-bg{
      background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
      border-radius: 18px;
      padding: 16px 20px;
      margin-bottom: .85rem;
      color: #fff;
      box-shadow: 0 10px 25px rgba(29, 78, 216, 0.15);
    }
    .page-header-bg .page-title{
      color: #fff !important;
      font-weight: 700;
      margin-bottom: 0 !important;
    }
    .page-header-bg .sub{
      color: rgba(255,255,255,0.85) !important;
    }
    .page-header-bg .btn {
      font-weight: 500 !important;
    }

    /* Avatar ring style */
    .avatar-ring{
      width:46px;height:46px;
      display:flex;align-items:center;justify-content:center;
      background:#eef2ff;
      border:1px solid rgba(15,23,42,.10);
      flex:0 0 auto;
      overflow:hidden;
      border-radius:100%;
    }
    .avatar-ring img{
      width:100%;height:100%;
      object-fit:cover;
      display:block;
    }
    
    /* Pulsing Green Indicator */
    .pulse-green {
      display: block;
      width: 11px;
      height: 11px;
      background-color: #22c55e;
      border-radius: 50%;
      box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
      animation: pulse-green-anim 1.6s infinite;
      position: absolute;
      bottom: 3px;
      right: 3px;
      border: 2px solid #fff;
    }
    @keyframes pulse-green-anim {
      0% {
        transform: scale(0.95);
        box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
      }
      70% {
        transform: scale(1);
        box-shadow: 0 0 0 5px rgba(34, 197, 94, 0);
      }
      100% {
        transform: scale(0.95);
        box-shadow: 0 0 0 0 rgba(34, 197, 94, 0);
      }
    }
  </style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="container py-4">

  <div class="page-header-bg d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h4 class="page-title mb-1">👤 លម្អិតអតិថិជន</h4>
      <div class="sub">ព័ត៌មានអតិថិជន + បញ្ជីឥណទាន</div>
    </div>

    <div class="d-flex gap-2 flex-wrap">
      <a class="btn btn-outline-light" href="customers.php"><i class="bi bi-arrow-left me-1"></i> ត្រឡប់</a>
    </div>
  </div>

  <?php 
    $rating = fin_calculate_customer_rating($pdo, (int)$customer_id);
    $isPaidOff = (($rating['rating'] ?? '') === 'paid_off') || ($sum_outstanding <= 0.0001 && !empty($loans) && ($rating['rating'] ?? '') !== 'bad');
  ?>
  <!-- Customer card -->
  <div class="cardx p-3 mb-3" style="padding: 16px !important;<?= $isPaidOff ? ' border-left: 4.5px solid #16a34a !important;' : '' ?>">
    <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
      <div class="d-flex align-items-start gap-3">
        <div class="d-flex flex-column align-items-center" style="flex-shrink: 0; min-width: 68px;">
          <div class="position-relative">
            <div class="avatar-ring" style="width: 65px; height: 65px; border-radius: 50%; border: 2px solid var(--line); overflow: hidden; display: flex; align-items: center; justify-content: center; background: #f3f4f6;">
              <img src="<?= fin_cust_photo($customer['photo'] ?? '', $customer['gender'] ?? '') ?>" 
                   alt="Avatar" 
                   style="width: 100%; height: 100%; object-fit: cover;"
                   onerror="this.onerror=null;this.src='../uploads/avatars/default.png';">
            </div>
            <?php if ($isPaidOff): ?>
              <span class="paid-check-badge" title="អតិថិជនបានបង់ផ្តាច់កម្ចីរួចរាល់"><i class="bi bi-check-lg"></i></span>
            <?php elseif ((int)($customer['is_active'] ?? 1) === 1): ?>
              <!-- Pulsing Green status dot on bottom right of profile photo -->
              <span class="pulse-green"></span>
            <?php endif; ?>
          </div>
          <!-- ID under profile image -->
          <span class="badge-soft badge-id mt-1" style="font-size: 0.68rem; padding: 0.15rem 0.4rem; white-space: nowrap; line-height: 1;">ID: <?= (int)$customer_id ?></span>
        </div>
        <div class="flex-grow-1">
          <h4 class="fs-5 fw-bold text-dark mb-1 d-flex align-items-center flex-wrap gap-1" style="font-weight: 700 !important; margin: 0;">
            <span><?= h2($customer['full_name'] ?? '') ?></span>
            <?php if ($isPaidOff): ?>
              <i class="bi bi-patch-check-fill text-success" style="font-size: 1.15rem;" title="អតិថិជនបានបង់ផ្តាច់កម្ចីរួចរាល់"></i>
            <?php endif; ?>
          </h4>
          
          <!-- Phone number direct under name -->
          <div class="text-muted mb-1 mono" style="font-size: 0.88rem;">
            <i class="bi bi-telephone me-1"></i><?= h2($customer['phone'] ?? '') ?>
            <?php if (!empty($customer['phone2'])): ?>
              <span class="text-muted mx-1">|</span> <?= h2($customer['phone2']) ?>
            <?php endif; ?>
          </div>

          <div class="d-flex flex-wrap gap-2 align-items-center mt-1">
            <?= fin_badge_gender($customer['gender'] ?? '') ?>
            <span class="badge <?= $rating['badge'] ?>" title="<?= h2($rating['desc']) ?>" style="font-size: 0.72rem;"><?php if ($isPaidOff): ?><i class="bi bi-check-circle-fill me-1"></i><?php endif; ?><?= h2($rating['text']) ?></span>
            <span class="text-muted ms-1 small d-none d-md-inline" style="font-size: 0.72rem;">(<?= h2($rating['desc']) ?>)</span>
          </div>
        </div>
      </div>

      <!-- Action buttons at the top right of the card -->
      <div class="d-flex gap-2 align-items-center">
        <?php if ($isPaidOff): ?>
          <a href="loan_add.php?customer_id=<?= (int)$customer_id ?>" class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1 px-3 py-1 fw-bold" style="border-radius: 20px; font-size: 0.85rem;" title="ស្នើកម្ចីថ្មីសម្រាប់អតិថិជននេះ"><i class="bi bi-plus-circle"></i> ស្នើកម្ចីថ្មី</a>
        <?php else: ?>
          <a href="loan_add.php?customer_id=<?= (int)$customer_id ?>" class="btn btn-primary btn-sm d-flex align-items-center justify-content-center" style="width:36px; height:36px; border-radius:50%; padding:0;" title="បន្ថែមឥណទាន"><i class="bi bi-plus-lg"></i></a>
        <?php endif; ?>
        <a href="customer_edit.php?id=<?= (int)$customer_id ?>" class="btn btn-warning btn-sm d-flex align-items-center justify-content-center" style="width:36px; height:36px; border-radius:50%; padding:0;" title="កែព័ត៌មាន"><i class="bi bi-pencil-fill"></i></a>
      </div>
    </div>

    <!-- 4 KPI Boxes inside the profile card -->
    <div class="border-top pt-3 mt-3">
      <div class="row g-2">
        <div class="col-6 col-md-3">
          <div class="kpi kpi-blue py-2 px-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <span class="lbl" style="font-size: 0.8rem;">ចំនួនឥណទាន</span>
              <i class="bi bi-file-earmark-text kpi-icon" style="font-size: 1.1rem;"></i>
            </div>
            <div class="val mono mt-1" style="font-size: 1.15rem;"><?= (int)$total_loans ?></div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="kpi kpi-green py-2 px-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <span class="lbl" style="font-size: 0.8rem;">ប្រាក់ខ្ចីសរុប</span>
              <i class="bi bi-cash-stack kpi-icon" style="font-size: 1.1rem;"></i>
            </div>
            <div class="val mono mt-1" style="font-size: 1.15rem;"><?= fin_format_money_with_symbol($sum_principal, $customer_currency) ?></div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="kpi kpi-orange py-2 px-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <span class="lbl" style="font-size: 0.8rem;">ត្រូវបង់សរុប</span>
              <i class="bi bi-calendar-check kpi-icon" style="font-size: 1.1rem;"></i>
            </div>
            <div class="val mono mt-1" style="font-size: 1.15rem;"><?= fin_format_money_with_symbol($sum_total_due, $customer_currency) ?></div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="kpi kpi-red py-2 px-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <span class="lbl" style="font-size: 0.8rem;">កំពុងជំពាក់</span>
              <i class="bi bi-hourglass-split kpi-icon" style="font-size: 1.1rem;"></i>
            </div>
            <div class="val mono mt-1" style="font-size: 1.15rem;"><?= fin_format_money_with_symbol($sum_outstanding, $customer_currency) ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Loans list -->
  <div class="cardx p-0 overflow-hidden">
    <div class="p-3 p-lg-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div>
        <div class="fw-bold">📄 បញ្ជីឥណទានរបស់អតិថិជន</div>
      </div>
      <div class="sub">ចំនួនសរុប: <?= (int)$total_loans ?></div>
    </div>

    <div class="table-responsive d-none d-md-block">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:70px;">#</th>
            <th>លេខកូដ</th>
            <th class="text-end">ប្រាក់ខ្ចី</th>
            <th class="text-end d-none d-md-table-cell">ការប្រាក់</th>
            <th class="d-none d-md-table-cell">វិធីគិត</th>
            <th class="d-none d-md-table-cell">ថ្ងៃចាប់ផ្តើម</th>
            <th class="d-none d-md-table-cell">ថ្ងៃដល់កំណត់</th>
            <th class="text-end">កំពុងជំពាក់</th>
            <th>ស្ថានភាព</th>
            <th class="text-end">សកម្មភាព</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$loanRows): ?>
            <tr>
              <td colspan="10" class="text-center text-muted py-4">មិនទាន់មានឥណទានទេ។</td>
            </tr>
          <?php else: ?>
            <?php foreach ($loanRows as $i => $l): ?>
              <?php
                $lid = (int)($l['id'] ?? 0);
                $loan_code = (string)($l['loan_code'] ?? '');
                $principal = (float)($l['principal'] ?? 0);
                $rate = isset($l['interest_rate']) ? (float)$l['interest_rate'] : null;
                $method = (string)($l['interest_method'] ?? '');
                $start = fin_format_date_kh($l['start_date'] ?? '');
                $due   = fin_format_date_kh($l['due_date'] ?? '');
                $out   = isset($l['outstanding']) ? (float)$l['outstanding'] : 0.0;
                $status = (string)($l['status'] ?? '');
                $status_detail = (string)($l['status_detail'] ?? '');
              ?>
              <tr>
                <td class="mono"><?= ($i + 1) ?></td>
                <td class="fw-semibold"><?= h2($loan_code ?: ('LN#'.$lid)) ?></td>
                <td class="text-end mono fw-bold"><?= fin_format_money_with_symbol($principal, $l['currency_code'] ?? 'USD') ?></td>
                <td class="text-end mono d-none d-md-table-cell"><?= $rate === null ? '—' : fin_money($rate).'%'; ?></td>
                <td class="d-none d-md-table-cell"><?= h2(fin_method_kh($method)) ?></td>
                <td class="mono d-none d-md-table-cell"><?= h2($start) ?></td>
                <td class="mono d-none d-md-table-cell"><?= h2($due) ?></td>
                <td class="text-end mono fw-bold"><?= fin_format_money_with_symbol($out, $l['currency_code'] ?? 'USD') ?></td>
                <td><?= fin_badge_status($status, $status_detail) ?></td>
                <td class="text-end">
                  <div class="d-flex justify-content-end gap-2 flex-wrap">
                    <a class="btn btn-soft btn-sm" href="loan_view.php?id=<?= $lid ?>">👁️ មើល</a>
                    <a class="btn btn-warning btn-sm" href="loan_edit.php?id=<?= $lid ?>">✏️ កែ</a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Mobile view card list -->
    <div class="d-md-none p-3 bg-light">
      <?php if (!$loanRows): ?>
        <div class="text-center text-muted py-4">មិនទាន់មានឥណទានទេ។</div>
      <?php else: ?>
        <?php foreach ($loanRows as $i => $l): ?>
          <?php
            $lid = (int)($l['id'] ?? 0);
            $loan_code = (string)($l['loan_code'] ?? '');
            $principal = (float)($l['principal'] ?? 0);
            $rate = isset($l['interest_rate']) ? (float)$l['interest_rate'] : null;
            $method = (string)($l['interest_method'] ?? '');
            $start = fin_format_date_kh($l['start_date'] ?? '');
            $due   = fin_format_date_kh($l['due_date'] ?? '');
            $out   = isset($l['outstanding']) ? (float)$l['outstanding'] : 0.0;
            $status = (string)($l['status'] ?? '');
            $status_detail = (string)($l['status_detail'] ?? '');
          ?>
          <div class="rowcard">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <div>
                <div class="fw-bold" style="font-size:0.95rem;"><?= h2($loan_code ?: ('LN#'.$lid)) ?></div>
                <div class="text-muted small" style="font-size:0.8rem;"><?= h2(fin_method_kh($method)) ?> (<?= $rate === null ? '—' : fin_money($rate).'%'; ?>)</div>
              </div>
              <div class="text-end">
                <?= fin_badge_status($status, $status_detail) ?>
              </div>
            </div>
            
            <div class="row g-2 border-top pt-2 mb-2">
              <div class="col-6">
                <div class="sub" style="font-size: 0.75rem;">ប្រាក់ខ្ចី</div>
                <div class="fw-bold mono text-dark" style="font-size: 0.9rem;"><?= fin_format_money_with_symbol($principal, $l['currency_code'] ?? 'USD') ?></div>
              </div>
              <div class="col-6 text-end">
                <div class="sub" style="font-size: 0.75rem;">កំពុងជំពាក់</div>
                <div class="fw-bold mono text-danger" style="font-size: 0.9rem;"><?= fin_format_money_with_symbol($out, $l['currency_code'] ?? 'USD') ?></div>
              </div>
            </div>
            
            <div class="d-flex justify-content-between text-muted small border-top pt-2 align-items-center">
              <div style="font-size: 0.75rem;">
                <span><?= h2($start) ?></span> → <span><?= h2($due) ?></span>
              </div>
              <div class="d-flex gap-1">
                <a class="btn btn-soft btn-sm py-1 px-2" style="font-size: 0.8rem; border-radius: 8px;" href="loan_view.php?id=<?= $lid ?>">👁️ មើល</a>
                <a class="btn btn-warning btn-sm py-1 px-2" style="font-size: 0.8rem; border-radius: 8px;" href="loan_edit.php?id=<?= $lid ?>">✏️ កែ</a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="p-3 p-lg-4 d-flex flex-wrap gap-2 justify-content-end">
      <a class="btn btn-soft" href="customers.php"><i class="bi bi-arrow-left me-1"></i> ត្រឡប់</a>
      <a class="btn btn-primary" style="border-radius:12px;" href="loan_add.php?customer_id=<?= (int)$customer_id ?>"><?= $isPaidOff ? '➕ ស្នើកម្ចីថ្មី' : '➕ បន្ថែមឥណទាន' ?></a>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
