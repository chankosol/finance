<?php
// /finance/admin/loan_agreement_print.php
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

$loan_id = (int)($_GET['id'] ?? 0);
if ($loan_id <= 0) {
    http_response_code(400);
    echo "<h3>400 Bad Request</h3><p>បាត់លេខសម្គាល់កម្ចី។</p>";
    exit;
}

/* Detect Columns */
$loansBizCol     = fin_col_exists($pdo,'loans','business_id') ? 'business_id' : 'biz_id';
$customersBizCol = fin_col_exists($pdo,'customers','business_id') ? 'business_id' : 'biz_id';
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
$custGenderCol          = fin_col_exists($pdo,'customers','gender') ? 'gender' : (fin_col_exists($pdo,'customers','sex') ? 'sex' : '');

$bizNameCol   = fin_first_existing_col($pdo,'businesses',['name','business_name'],'name');

/* Query Loan & Customer & Business info */
$codeExpr      = $loanHasCode ? "l.loan_code" : "''";
$principalExpr = $loanHasPrincipalAmount ? "l.principal_amount" : ($loanHasPrincipalOld ? "l.principal" : "0");
$startExpr     = $loanHasStartDate ? "l.start_date" : "NULL";
$dueExpr       = $loanHasDueDate ? "l.due_date" : ($loanHasEndDateOld ? "l.end_date" : "NULL");
$methodExpr    = $loanHasMethod ? "l.interest_method" : ($loanHasTypeOld ? "l.interest_type" : "''");
$statusExpr    = $loanHasStatus ? "l.status" : "''";
$genderSelect  = $custGenderCol ? "c.`{$custGenderCol}`" : "''";

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
      {$genderSelect}              AS customer_gender,
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
$termMonths = (int)($loan['term_months'] ?? 0);
if ($termMonths <= 0) {
    // Fallback: count schedules
    $stCount = $pdo->prepare("SELECT COUNT(*) FROM loan_schedules WHERE loan_id = ?");
    $stCount->execute([$loan_id]);
    $termMonths = (int)$stCount->fetchColumn() ?: 1;
}

// Format Gender to Khmer
$genderKh = '—';
$genderRaw = strtoupper(trim((string)($loan['customer_gender'] ?? '')));
if ($genderRaw === 'MALE' || $genderRaw === 'M' || $genderRaw === 'ប្រុស') {
    $genderKh = 'ប្រុស';
} elseif ($genderRaw === 'FEMALE' || $genderRaw === 'F' || $genderRaw === 'ស្រី') {
    $genderKh = 'ស្រី';
}
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
  <title>កិច្ចសន្យាខ្ចីប្រាក់ - <?= h2($loan['customer_name']) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Battambang:wght@300;400;700;900&family=Moul&family=Roboto:wght@300;400;700;900&display=swap" rel="stylesheet">
  <style>
    :root {
      --font-family: 'Battambang', sans-serif;
      --font-family-title: 'Moul', serif;
      --ink: #0f172a;
      --primary: #0f172a;
      --muted: #475569;
      --line: rgba(15,23,42,.15);
      --bg: #fff;
    }
    body {
      font-family: var(--font-family);
      color: var(--ink);
      background: var(--bg);
      font-size: 0.88rem;
      line-height: 1.6;
      padding: 30px;
    }
    .agreement-container {
      max-width: 800px;
      margin: 0 auto;
      border: 1px solid var(--line);
      padding: 30px 40px;
      border-radius: 12px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.05);
      background: #fff;
    }
    .doc-header {
      text-align: center;
      margin-bottom: 25px;
    }
    .nations-title {
      font-family: var(--font-family-title);
      font-size: 1rem;
      margin-bottom: 2px;
      letter-spacing: 0.5px;
    }
    .nations-sub {
      font-family: var(--font-family-title);
      font-size: 0.85rem;
      margin-bottom: 12px;
    }
    .doc-title {
      font-family: var(--font-family-title);
      font-size: 1.25rem;
      color: var(--primary);
      text-transform: uppercase;
      margin-top: 15px;
      margin-bottom: 25px;
      letter-spacing: 0.5px;
      text-decoration: underline;
    }
    .agreement-meta {
      font-size: 0.82rem;
      color: var(--muted);
      margin-bottom: 20px;
      text-align: right;
    }
    .section-title {
      font-family: var(--font-family-title);
      font-size: 0.92rem;
      color: var(--primary);
      margin-top: 15px;
      margin-bottom: 6px;
      border-bottom: 1px solid var(--line);
      padding-bottom: 4px;
    }
    .party-info {
      margin-bottom: 15px;
      padding-left: 10px;
    }
    .party-title {
      font-weight: 700;
      color: var(--primary);
      text-decoration: underline;
      margin-bottom: 5px;
    }
    .article-text {
      margin-bottom: 8px;
      text-align: justify;
      padding-left: 10px;
    }
    .article-num {
      font-weight: 700;
      color: var(--primary);
    }
    .font-mono {
      font-family: 'Roboto', sans-serif;
    }
    .signature-section {
      margin-top: 35px;
      border-top: 1px solid var(--line);
      padding-top: 20px;
    }
    .sig-col {
      text-align: center;
      min-height: 110px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }
    .sig-title {
      font-weight: 700;
      font-size: 0.85rem;
      color: var(--primary);
    }
    .sig-space {
      margin-top: 50px;
      border-bottom: 1.5px dotted var(--muted);
      width: 70%;
      margin-left: auto;
      margin-right: auto;
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
        background: transparent;
      }
      .agreement-container {
        border: none;
        padding: 0;
        box-shadow: none;
        max-width: 100%;
      }
      .no-print {
        display: none !important;
      }
    }
  </style>
</head>
<body>

  <!-- Print Actions bar -->
  <div class="d-flex justify-content-between align-items-center mb-4 no-print bg-light p-3 rounded border max-width-800 mx-auto print-actions-bar" style="max-width: 800px;">
    <div class="print-instructions">
      <i class="bi bi-file-earmark-text me-1 text-primary"></i> <strong>របៀបបោះពុម្ភ៖</strong> លិខិតកិច្ចសន្យានេះត្រូវបានរៀបចំឡើងយ៉ាងខ្លីត្រឹម ១ទំព័រ A4 សម្រាប់ឱ្យអតិថិជនផ្តិតមេដៃ និងចុះហត្ថលេខា។
    </div>
    <div class="d-flex gap-2 print-buttons-group">
      <button onclick="window.print()" class="btn btn-primary px-4"><i class="bi bi-printer me-1"></i> បោះពុម្ភ (Print)</button>
      <button onclick="window.close()" class="btn btn-outline-secondary">បិទ (Close)</button>
    </div>
  </div>

  <!-- Agreement Document Container -->
  <div class="agreement-container">
    <div class="doc-header">
      <div class="nations-title">ព្រះរាជាណាចក្រកម្ពុជា</div>
      <div class="nations-sub">ជាតិ សាសនា ព្រះមហាក្សត្រ</div>
      <br><br>
      <div class="doc-title">កិច្ចសន្យាខ្ចីប្រាក់</div>
    </div>

    <div class="agreement-meta">
      ធ្វើនៅ៖ <strong><?= h2($loan['business_address'] ?: 'ភ្នំពេញ') ?></strong>, ថ្ងៃទី <strong><?= h2(fin_format_date_kh($loan['start_date'])) ?></strong>
    </div>

    <div class="section-title">ភាគីនៃកិច្ចសន្យា (Parties)</div>
    
    <!-- Lender -->
    <div class="party-info">
      <div class="party-title">ភាគី ឃ "អ្នកឱ្យខ្ចី" (Lender)៖</div>
      <div>
        ឈ្មោះអាជីវកម្ម៖ <strong><?= h2($loan['business_name']) ?></strong>, 
        លេខទូរស័ព្ទ៖ <strong class="font-mono"><?= h2($loan['business_phone'] ?: '—') ?></strong>, 
        អាសយដ្ឋាន៖ <strong><?= h2($loan['business_address'] ?: '—') ?></strong>។
      </div>
    </div>

    <!-- Borrower -->
    <div class="party-info">
      <div class="party-title">ភាគី ខ "អ្នកខ្ចី" (Borrower)៖</div>
      <div>
        ឈ្មោះអតិថិជន៖ <strong><?= h2($loan['customer_name']) ?></strong>, 
        ភេទ៖ <strong><?= $genderKh ?></strong>, 
        លេខទូរស័ព្ទ៖ <strong class="font-mono"><?= h2($loan['customer_phone'] ?: '—') ?></strong>, 
        អាសយដ្ឋាន៖ <strong><?= h2($loan['customer_address'] ?: '—') ?></strong>។
      </div>
    </div>

    <div class="section-title">ប្រការព្រមព្រៀង (Terms & Conditions)</div>

    <div class="article-text">
      <span class="article-num">ប្រការ ១ (ទឹកប្រាក់ខ្ចី)៖</span> 
      ភាគីអ្នកឱ្យខ្ចីបានព្រមព្រៀងប្រគល់ទឹកប្រាក់ខ្ចីចំនួន <strong class="font-mono"><?= fin_format_money_with_symbol((float)$loan['principal'], $ccy) ?></strong> ជូនទៅភាគីអ្នកខ្ចី ហើយភាគីអ្នកខ្ចីបានទទួលគ្រប់ចំនួននៅថ្ងៃចុះកិច្ចសន្យានេះ។
    </div>

    <div class="article-text">
      <span class="article-num">ប្រការ ២ (ការប្រាក់ និងរយៈពេល)៖</span> 
      ភាគីទាំងពីរបានព្រមព្រៀងកំណត់អត្រាការប្រាក់ចំនួន <strong><?= number_format((float)($loan['interest_rate'] ?? 0), 2) ?>%</strong> ក្នុងមួយខែ (គណនាតាមវិធី៖ <?= h2(fin_method_kh((string)$loan['interest_method'])) ?>)។ រយៈពេលខ្ចីគឺចំនួន <strong><?= $termMonths ?> ខែ</strong> ដោយចាប់ផ្តើមគិតពីថ្ងៃទី <strong class="font-mono"><?= h2(fin_format_date_kh($loan['start_date'])) ?></strong> រហូតដល់ថ្ងៃដល់កំណត់ថ្ងៃទី <strong class="font-mono"><?= h2(fin_format_date_kh($loan['due_date'])) ?></strong>។
    </div>

    <div class="article-text">
      <span class="article-num">ប្រការ ៣ (ការទូទាត់សង)៖</span> 
      ភាគីអ្នកខ្ចីសន្យានិងធានាថានឹងបង់ប្រាក់សងទាំងប្រាក់ដើម និងការប្រាក់តាមការកំណត់ជាធរមានក្នុងកាលវិភាគបង់ប្រាក់របស់អាជីវកម្មជាទៀងទាត់រៀងរាល់ខែមិនឱ្យយឺតយ៉ាវឡើយ។
    </div>

    <?php if (trim((string)($loan['collateral'] ?? '')) !== ''): ?>
      <div class="article-text">
        <span class="article-num">ប្រការ ៤ (ទ្រព្យធានា)៖</span> 
        ដើម្បីធានាការបង់សង ភាគីអ្នកខ្ចីបានយល់ព្រមដាក់ប្រគល់ទ្រព្យសម្បត្តិធានា (វត្ថុបញ្ចាំ/ធានា) ដូចជា៖ <strong><?= h2($loan['collateral']) ?></strong> ជូនភាគីអ្នកឱ្យខ្ចីរក្សាទុក។
      </div>
    <?php endif; ?>

    <div class="article-text">
      <span class="article-num">ប្រការ ៥ (ការខកខាន ឬរំលោភកិច្ចសន្យា)៖</span> 
      ក្នុងករណីភាគីអ្នកខ្ចីខកខានមិនបង់ប្រាក់សងតាមការព្រមព្រៀង ឬគេចវេសមិនទទួលខុសត្រូវ ភាគីអ្នកឱ្យខ្ចីមានសិទ្ធិពេញលេញក្នុងការគ្រប់គ្រងចាត់ចែង ឬលក់ឡាយឡុងទ្រព្យធានាខាងលើដើម្បីទូទាត់បំណុល ដោយមិនចាំបាច់មានការអនុញ្ញាតពីតុលាការឡើយ ហើយភាគីអ្នកខ្ចីសន្យាមិនតវ៉ាឡើយ។
    </div>

    <div class="article-text" style="font-style: italic;">
      កិច្ចសន្យានេះ ត្រូវបានធ្វើឡើងជាពីរច្បាប់ដែលមានតម្លៃគតិយុត្តស្មើគ្នា ហើយភាគីទាំងពីរបានអាន យល់ច្បាស់ និងផ្តិតមេដៃ/ចុះហត្ថលេខាទុកជាភស្តុតាង។
    </div>

    <div class="row signature-section">
      <div class="col-4 sig-col">
        <div class="sig-title">ភាគី "អ្នកឱ្យខ្ចី" (Lender)</div>
        <div class="sig-space"></div>
      </div>
      <div class="col-4 sig-col">
        <div class="sig-title">ភាគី "អ្នកខ្ចី" (Borrower)</div>
        <div class="sig-space"></div>
      </div>
      <div class="col-4 sig-col">
        <div class="sig-title">សាក្សី (Witness)</div>
        <div class="sig-space"></div>
      </div>
    </div>
  </div>

</body>
</html>
