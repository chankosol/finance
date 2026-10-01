<?php
// /finance/admin/auto_followup.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/followup_helpers.php';

fin_require_login();
fin_require_permission('view_auto_followup');
$business_id = fin_require_business();
global $pdo;

function h2($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fin_format_amount($amt, $ccy): string {
  $ccy = strtoupper(trim($ccy));
  if ($ccy === 'KHR' || $ccy === '៛') {
    return number_format((float)$amt, 0) . ' ៛';
  }
  return '$' . number_format((float)$amt, 2);
}

// Dynamic helper functions for DB compatibility
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
if (!function_exists('fin_ccy_norm')) {
    function fin_ccy_norm(string $ccy): string {
        $c = strtoupper(trim($ccy));
        if (in_array($c, ['$', 'USD', 'US$'], true)) return 'USD';
        if (in_array($c, ['KHR', 'RIEL', '៛'], true)) return 'KHR';
        return $c ?: 'USD';
    }
}
if (!function_exists('fin_format_phone_tg')) {
    function fin_format_phone_tg($phone) {
        $clean = preg_replace('/[^0-9]/', '', (string)$phone);
        if ($clean === '') return $phone;
        
        $domain = 'findinyou.com';
        if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '') {
            $domain = $_SERVER['HTTP_HOST'];
        }
        
        $url = "https://" . $domain . "/finance/call.php?num=" . $clean;
        return "<a href=\"" . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . "\">" . htmlspecialchars((string)$phone, ENT_QUOTES, 'UTF-8') . "</a>";
    }
}

// Ensure business_settings columns exist (safety check)
if (!fin_col_exists($pdo, 'business_settings', 'telegram_owner_chat_id')) {
    $pdo->exec("ALTER TABLE business_settings ADD telegram_owner_chat_id VARCHAR(255) NULL;");
}
if (!fin_col_exists($pdo, 'business_settings', 'telegram_enable_daily_report')) {
    $pdo->exec("ALTER TABLE business_settings ADD telegram_enable_daily_report TINYINT DEFAULT 0;");
}

// Load Telegram Bot Token, Template and Owner Settings
$stSettings = $pdo->prepare("SELECT telegram_bot_token, telegram_template, telegram_owner_chat_id, telegram_enable_daily_report FROM business_settings WHERE business_id = ? LIMIT 1");
$stSettings->execute([$business_id]);
$bizSettings = $stSettings->fetch(PDO::FETCH_ASSOC) ?: [];

$bot_token = trim($bizSettings['telegram_bot_token'] ?? '');
$template  = trim($bizSettings['telegram_template'] ?? '');
$owner_chat_id = trim($bizSettings['telegram_owner_chat_id'] ?? '');
$enable_daily_report = (int)($bizSettings['telegram_enable_daily_report'] ?? 0);

$is_configured = ($bot_token !== '' && $template !== '');

$success_msg = '';
$error_msg   = '';

// ----------------- Handle Actions -----------------
$action = $_GET['action'] ?? '';
$schedule_id = (int)($_GET['schedule_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' || $action) {
    if (!$is_configured) {
        $error_msg = 'សូមកំណត់ Bot Token និង Template ជាមុនសិន នៅក្នុង Settings។';
        if (isset($_GET['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $error_msg]);
            exit;
        }
    } else {
        // --- 1. Send Single Followup ---
        if ($action === 'send_single' && $schedule_id > 0) {
            try {
                // Fetch schedule details
                $sql = "
                    SELECT 
                        s.id AS schedule_id, s.installment_no, s.due_date, (s.total_due - s.paid_total) AS amount_due,
                        c.full_name AS customer_name, c.telegram_chat_id, l.id AS loan_id, l.loan_code
                    FROM loan_schedules s
                    JOIN loans l ON l.id = s.loan_id
                    JOIN customers c ON c.id = l.customer_id
                    WHERE s.id = ? AND s.business_id = ? AND l.status = 'ACTIVE'
                    LIMIT 1
                ";
                $st = $pdo->prepare($sql);
                $st->execute([$schedule_id, $business_id]);
                $sched = $st->fetch(PDO::FETCH_ASSOC);

                if (!$sched) {
                    throw new Exception('រកមិនឃើញកាលវិភាគ ឬឥណទានមិនសកម្ម។');
                }
                if (empty($sched['telegram_chat_id'])) {
                    throw new Exception('អតិថិជននេះមិនមាន Telegram Chat ID ទេ។');
                }

                // Get outstanding balance
                $outstanding = fin_get_loan_outstanding($pdo, (int)$sched['loan_id']);
                
                // Parse message
                $messageData = [
                    'customer_name' => $sched['customer_name'],
                    'loan_code' => $sched['loan_code'],
                    'installment_no' => $sched['installment_no'],
                    'due_date' => $sched['due_date'],
                    'amount_due' => $sched['amount_due'],
                    'remaining_balance' => $outstanding
                ];
                $messageText = fin_parse_template($template, $messageData);

                // Send Telegram
                $res = fin_send_telegram($bot_token, $sched['telegram_chat_id'], $messageText);

                if ($res['ok']) {
                    // Update schedule
                    $stUp = $pdo->prepare("UPDATE loan_schedules SET last_followup_date = CURRENT_DATE() WHERE id = ?");
                    $stUp->execute([$schedule_id]);
                    $success_msg = "បានផ្ញើ Followup ទៅកាន់ " . h2($sched['customer_name']) . " រួចរាល់! ✅";
                } else {
                    throw new Exception("Telegram Error: " . $res['error']);
                }
            } catch (Throwable $e) {
                $error_msg = "ផ្ញើមិនបានសម្រេច៖ " . $e->getMessage();
            }

            // If AJAX, return JSON response
            if (isset($_GET['ajax'])) {
                header('Content-Type: application/json');
                if ($success_msg) {
                    echo json_encode(['ok' => true, 'message' => $success_msg]);
                } else {
                    echo json_encode(['ok' => false, 'error' => $error_msg]);
                }
                exit;
            }
        }

        // --- 2. Send All Automated Followups ---
        if ($action === 'send_all') {
            $pending = fin_get_pending_followups($pdo, $business_id);
            $sent_count = 0;
            $fail_count = 0;
            $errors_list = [];

            foreach ($pending as $sched) {
                if (empty($sched['telegram_chat_id'])) continue;
                
                // Skip if already followed up today
                if ($sched['last_followup_date'] === date('Y-m-d')) continue;

                try {
                    $outstanding = fin_get_loan_outstanding($pdo, (int)$sched['loan_id']);
                    $messageData = [
                        'customer_name' => $sched['customer_name'],
                        'loan_code' => $sched['loan_code'],
                        'installment_no' => $sched['installment_no'],
                        'due_date' => $sched['due_date'],
                        'amount_due' => $sched['amount_due'],
                        'remaining_balance' => $outstanding
                    ];
                    $messageText = fin_parse_template($template, $messageData);

                    $res = fin_send_telegram($bot_token, $sched['telegram_chat_id'], $messageText);

                    if ($res['ok']) {
                        $stUp = $pdo->prepare("UPDATE loan_schedules SET last_followup_date = CURRENT_DATE() WHERE id = ?");
                        $stUp->execute([$sched['schedule_id']]);
                        $sent_count++;
                    } else {
                        $fail_count++;
                        $errors_list[] = $sched['customer_name'] . ": " . $res['error'];
                    }
                } catch (Throwable $e) {
                    $fail_count++;
                    $errors_list[] = $sched['customer_name'] . ": " . $e->getMessage();
                }
            }

            if ($sent_count > 0) {
                $success_msg = "បានផ្ញើជោគជ័យ ចំនួន " . $sent_count . " នាក់! 🎉";
            }
            if ($fail_count > 0) {
                $error_msg = "បរាជ័យ ចំនួន " . $fail_count . " នាក់។ Details:\n" . implode("\n", $errors_list);
            }
            if ($sent_count === 0 && $fail_count === 0) {
                $success_msg = "មិនមានអតិថិជនណាដែលត្រូវផ្ញើនៅថ្ងៃនេះទេ (ឬបានផ្ញើអស់ហើយ)។";
            }
        }

        // --- 3. Test Borrower Reminder ---
            if ($action === 'test_borrower_reminder') {
                try {
                    if (empty($owner_chat_id)) {
                        throw new Exception('សូមកំណត់ Telegram Owner Chat ID នៅក្នុង Settings ជាមុនសិន។');
                    }
                    
                    $dummyData = [
                        'customer_name' => 'អតិថិជនសាកល្បង (Test Customer)',
                        'loan_code' => 'LN-TEST-9999',
                        'installment_no' => 1,
                        'due_date' => date('Y-m-d'),
                        'amount_due' => 125.50,
                        'remaining_balance' => 1500.00
                    ];
                    $messageText = "⚠️ <b>[សារសាកល្បង / Test Borrower Reminder]</b>\n\n" . fin_parse_template($template, $dummyData);
                    
                    $res = fin_send_telegram($bot_token, $owner_chat_id, $messageText);
                    if ($res['ok']) {
                        $success_msg = "បានផ្ញើសាររំលឹកសាកល្បង (Test Borrower Reminder) ទៅកាន់ម្ចាស់ហាងរួចរាល់! ✅";
                    } else {
                        throw new Exception("Telegram Error: " . $res['error']);
                    }
                } catch (Throwable $e) {
                    $error_msg = "ផ្ញើសារសាកល្បងមិនបានសម្រេច៖ " . $e->getMessage();
                }

                if (isset($_GET['ajax'])) {
                    header('Content-Type: application/json');
                    if ($success_msg) {
                        echo json_encode(['ok' => true, 'message' => $success_msg]);
                    } else {
                        echo json_encode(['ok' => false, 'error' => $error_msg]);
                    }
                    exit;
                }
            }

            // --- 4. Test Owner Daily Report ---
            if ($action === 'test_owner_report') {
                try {
                    if (empty($owner_chat_id)) {
                        throw new Exception('សូមកំណត់ Telegram Owner Chat ID នៅក្នុង Settings ជាមុនសិន។');
                    }

                    // Fetch business name
                    $stBiz = $pdo->prepare("SELECT name FROM businesses WHERE id = ? LIMIT 1");
                    $stBiz->execute([$business_id]);
                    $bizName = $stBiz->fetchColumn() ?: 'Finance System';

                    // Fetch schedules due today
                    $colCcy = fin_first_col($pdo, 'loans', ['currency_code', 'currency', 'ccy'], '');
                    $colType = fin_first_col($pdo, 'loans', ['loan_type', 'type', 'repayment_type'], '');
                    $colRepayDay = fin_col_exists($pdo, 'loans', 'repayment_day') ? 'repayment_day' : '';

                    $ccySelect = $colCcy ? "l.`$colCcy`" : "'USD'";
                    $typeSelect = $colType ? "l.`$colType`" : "'—'";

                    $todayDate = date('Y-m-d');
                    $dayNum = (int)date('j', strtotime($todayDate));
                    
                    $repayDayCond = $colRepayDay ? "OR (l.`$colRepayDay` = ? AND s.due_date <= ?)" : "";

                    $sqlDueToday = "
                        SELECT 
                            s.installment_no,
                            (s.total_due - s.paid_total) AS amount_due,
                            c.full_name AS customer_name,
                            c.phone,
                            l.loan_code,
                            UPPER(TRIM(COALESCE($ccySelect, 'USD'))) AS ccy,
                            COALESCE($typeSelect, '—') AS loan_type
                        FROM loan_schedules s
                        JOIN loans l ON l.id = s.loan_id
                        JOIN customers c ON c.id = l.customer_id
                        WHERE s.business_id = ?
                          AND (s.total_due - s.paid_total) > 0.009
                          AND (s.due_date = ? $repayDayCond)
                          AND l.status = 'ACTIVE'
                        ORDER BY c.full_name ASC
                    ";
                    $stToday = $pdo->prepare($sqlDueToday);
                    
                    $execParams = [$business_id, $todayDate];
                    if ($colRepayDay) {
                        $execParams[] = $dayNum;
                        $execParams[] = $todayDate;
                    }
                    
                    $stToday->execute($execParams);
                    $dueTodayList = $stToday->fetchAll(PDO::FETCH_ASSOC) ?: [];

                    $dateStr = date('Y-m-d');
                    $summaryMsg = "📊 <b>[របាយការណ៍សាកល្បង / Test Owner Report] របាយការណ៍បំណុលត្រូវប្រមូលថ្ងៃនេះ - $dateStr</b>\n";
                    $summaryMsg .= "ហាង៖ <b>$bizName</b>\n\n";

                    if (empty($dueTodayList)) {
                        $summaryMsg .= "🎉 មិនមានអតិថិជនត្រូវសងប្រាក់នៅថ្ងៃនេះទេ។\n";
                    } else {
                        $sums = [];
                        $details = "";
                        $i = 0;

                        foreach ($dueTodayList as $row) {
                            $i++;
                            $ccy = fin_ccy_norm($row['ccy']);
                            $amt = (float)$row['amount_due'];

                            if (!isset($sums[$ccy])) {
                                $sums[$ccy] = ['count' => 0, 'amount' => 0.0];
                            }
                            $sums[$ccy]['count']++;
                            $sums[$ccy]['amount'] += $amt;

                            $sym = ($ccy === 'KHR') ? '៛' : '$';
                            $fmtAmt = $sym . number_format($amt, ($ccy === 'KHR' ? 0 : 2));

                            $tgPhone = fin_format_phone_tg($row['phone']);
                            $details .= "   $i. <b>{$row['customer_name']}</b> ($tgPhone)\n";
                            $details .= "      • ទឹកប្រាក់៖ <b>$fmtAmt</b> | លេខកម្ចី៖ {$row['loan_code']}\n";
                            $details .= "      • ប្រភេទ៖ {$row['loan_type']} (លើកទី {$row['installment_no']})\n\n";
                        }

                        $summaryMsg .= "<b>📈 សរុបត្រូវប្រមូល៖</b>\n";
                        foreach ($sums as $ccy => $data) {
                            $sym = ($ccy === 'KHR') ? '៛' : '$';
                            $fmtSum = $sym . number_format($data['amount'], ($ccy === 'KHR' ? 0 : 2));
                            $summaryMsg .= "   • $ccy: <b>$fmtSum</b> ({$data['count']} នាក់)\n";
                        }
                        $summaryMsg .= "\n<b>📋 បញ្ជីលម្អិតអតិថិជន៖</b>\n\n" . $details;
                    }

                    $res = fin_send_telegram($bot_token, $owner_chat_id, $summaryMsg);
                    if ($res['ok']) {
                        $success_msg = "បានផ្ញើរបាយការណ៍សាកល្បង (Test Owner Report) ទៅកាន់ម្ចាស់ហាងរួចរាល់! ✅";
                    } else {
                        throw new Exception("Telegram Error: " . $res['error']);
                    }
                } catch (Throwable $e) {
                    $error_msg = "ផ្ញើរបាយការណ៍សាកល្បងមិនបានសម្រេច៖ " . $e->getMessage();
                }

                if (isset($_GET['ajax'])) {
                    header('Content-Type: application/json');
                    if ($success_msg) {
                        echo json_encode(['ok' => true, 'message' => $success_msg]);
                    } else {
                        echo json_encode(['ok' => false, 'error' => $error_msg]);
                    }
                    exit;
                }
            }
        }
    }

// Fetch pending schedules
$pendingSchedules = fin_get_pending_followups($pdo, $business_id);

// Stats
$total_due_count = count($pendingSchedules);
$telegram_ready_count = 0;
$already_sent_today = 0;
$totals = ['USD' => 0.0, 'KHR' => 0.0];

foreach ($pendingSchedules as $p) {
    if (!empty($p['telegram_chat_id'])) {
        $telegram_ready_count++;
    }
    if ($p['last_followup_date'] === date('Y-m-d')) {
        $already_sent_today++;
    }
    $ccy = strtoupper(trim($p['currency_code'] ?? 'USD'));
    if (!isset($totals[$ccy])) {
        $totals[$ccy] = 0.0;
    }
    $totals[$ccy] += (float)$p['amount_due'];
}

$parts = [];
if ($totals['USD'] > 0 || ($totals['USD'] == 0 && $totals['KHR'] == 0)) {
  $parts[] = '$' . number_format($totals['USD'], 2);
}
if ($totals['KHR'] > 0) {
  $parts[] = number_format($totals['KHR'], 0) . ' ៛';
}
$total_pending_text = implode(' / ', $parts);

// Pagination
$limit = 50;
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$total_items = count($pendingSchedules);
$total_pages = max(1, ceil($total_items / $limit));
$current_page = min($current_page, $total_pages);
$offset = ($current_page - 1) * $limit;
$pagedSchedules = array_slice($pendingSchedules, $offset, $limit);

?>
<!doctype html>
<html lang="km">
<head>
  <meta charset="utf-8">
  <title>តាមដានការសងប្រាក់ (Auto Followup) | Finance</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Battambang:wght@300;400;700;900&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

  <style>
    :root{
      --bg:#f3f4f6; --card:#fff; --ink:#0f172a; --muted:#64748b; --line:#e5e7eb; --brand:#2563eb;
    }
    body{font-family:'Battambang',sans-serif;background:var(--bg); color:var(--ink);}
    .page-title{font-weight:900;}
    .sub{color:var(--muted); font-size:.92rem;}
    .cardx{ background:var(--card); border:1px solid rgba(15,23,42,.08); border-radius:18px; box-shadow:none; padding:16px !important; }
    
    /* Stats grid */
    .kpi-grid{
      display:grid;
      grid-template-columns: repeat(4, 1fr);
      gap:12px;
      margin-bottom:18px;
    }
    @media(max-width:992px){
      .kpi-grid{ grid-template-columns: repeat(2, 1fr); }
    }
    @media(max-width:576px){
      .kpi-grid{ grid-template-columns: 1fr; }
    }
    .kpi{
      border:1px solid var(--line);
      background:linear-gradient(180deg, #fff, #fbfdff);
      border-radius:16px; padding:14px 16px; height:100%;
      display:flex; justify-content:space-between; align-items:center;
    }
    .kpi .lbl{color:var(--muted); font-size:.88rem; font-weight:800;}
    .kpi .val{font-size:1.25rem; font-weight:900; margin-top:2px; font-variant-numeric:tabular-nums;}
    .kpi .ico{
      width:40px;height:40px;border-radius:12px;
      display:flex;align-items:center;justify-content:center;
      background:#eef2ff;color:#1d4ed8; font-size:1.2rem;
    }

    .btn{ border-radius:12px; padding:.5rem .85rem; font-weight:800; }
    .btn-soft{ border:1px solid var(--line); background:#fff; color:var(--ink); }
    .btn-soft:hover{ background:#f8fafc; }

    .table thead th{ font-weight:900; background:#f8fafc; border-bottom:1px solid var(--line); white-space:nowrap; }
    .table tbody td{ vertical-align:middle; }
    
    .status-badge{
      display:inline-flex; align-items:center; gap:4px;
      padding:.25rem .55rem; border-radius:999px; font-weight:900; font-size:.82rem;
    }
    .badge-no-tg{ background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
    .badge-tg-ready{ background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
    .badge-sent-today{ background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }

    @media (max-width: 576px) {
      .scroll-x-mobile {
        display: flex;
        overflow-x: auto;
        white-space: nowrap;
        width: 100%;
        padding-bottom: 8px;
        -webkit-overflow-scrolling: touch;
      }
      .scroll-x-mobile > * {
        flex: 0 0 auto;
      }
    }
  </style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="container py-4">

  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
      <h4 class="page-title mb-0"><i class="bi bi-send-fill text-primary me-1"></i> តាមដានការសងប្រាក់ (Auto Followup)</h4>
      <div class="sub">ផ្ញើសាររំលឹកការសងប្រាក់ទៅកាន់ Telegram អតិថិជនស្វ័យប្រវត្តិតាមកាលវិភាគ</div>
    </div>
    <div class="d-flex gap-2 align-items-center scroll-x-mobile">
      <?php if ($is_configured): ?>
        <button class="btn btn-sm btn-outline-warning" onclick="sendTest('test_borrower_reminder', this)">
          <i class="bi bi-chat-text-fill me-1"></i> សាកល្បងសារ
        </button>
        <button class="btn btn-sm btn-outline-info" onclick="sendTest('test_owner_report', this)">
          <i class="bi bi-file-earmark-bar-graph-fill me-1"></i> សាកល្បងរបាយការណ៍
        </button>
      <?php endif; ?>
      <?php if ($is_configured && $telegram_ready_count > 0): ?>
        <form method="post" action="auto_followup.php?action=send_all" onsubmit="return confirm('ផ្ញើ followup ទៅកាន់អតិថិជនទាំងអស់ដែលមិនទាន់បានផ្ញើថ្ងៃនេះ?');" class="m-0">
          <button class="btn btn-sm btn-success"><i class="bi bi-send-check-fill me-1"></i> ផ្ញើទាំងអស់</button>
        </form>
      <?php endif; ?>
      <a class="btn btn-sm btn-soft" href="settings.php?tab=company"><i class="bi bi-gear-fill me-1"></i> ការកំណត់</a>
      <button type="button" class="btn btn-sm btn-outline-warning" 
              data-bs-toggle="popover" 
              data-bs-trigger="hover focus" 
              data-bs-html="true" 
              data-bs-placement="bottom" 
              title="💡 របៀបដោះស្រាយកំហុស Telegram &quot;chat not found&quot;" 
              data-bs-content="<div class='small text-muted mb-2'>ប្រសិនបើអ្នកឃើញកំហុស &quot;chat not found&quot; ឬផ្ញើសារមិនចេញទៅកាន់អតិថិជន សូមអនុវត្តតាមជំហានខាងក្រោម៖</div><ol class='small text-muted ps-3 mb-0' style='font-family: inherit;'><li class='mb-1'><b>ជំហានទី ១:</b> អតិថិជនត្រូវតែស្វែងរក Bot Username របស់អ្នកនៅលើ Telegram រួចចុចប៊ូតុង <b>Start</b> ឬផ្ញើសារណាមួយទៅកាន់ Bot ជាមុនសិន។</li><li class='mb-1'><b>ជំហានទី ២:</b> អតិថិជនត្រូវស្វែងរកលេខ <b>Chat ID</b> របស់ខ្លួន (ដោយប្រើ Bot ដូចជា @userinfobot ឬ @RawDataBot)។</li><li><b>ជំហានទី ៣:</b> បំពេញលេខ Chat ID នោះ ក្នុងព័ត៌មានអតិថិជន។</li></ol>">
        <i class="bi bi-info-circle-fill"></i> ដោះស្រាយកំហុស
      </button>
    </div>
  </div>

  <?php if (!$is_configured): ?>
    <div class="alert alert-warning p-3 mb-3 d-flex align-items-center gap-3" style="border-radius:16px;">
      <i class="bi bi-exclamation-triangle-fill display-6 text-warning"></i>
      <div>
        <h6 class="fw-bold mb-1">មិនទាន់បានកំណត់ Telegram Bot កែប្រែ</h6>
        សូមបំពេញ <b>Telegram Bot Token</b> និង <b>Template</b> នៅក្នុង <a href="settings.php?tab=company" class="fw-bold text-decoration-none">Settings → Company</a> ដើម្បីដំណើរការមុខងាររំលឹកប្រាក់សង។
      </div>
    </div>
  <?php endif; ?>

  <?php if ($success_msg): ?>
    <div class="alert alert-success py-2 mb-3" style="border-radius:12px;">
      <i class="bi bi-check-circle-fill me-1"></i> <?= nl2br($success_msg) ?>
    </div>
  <?php endif; ?>

  <?php if ($error_msg): ?>
    <div class="alert alert-danger py-2 mb-3" style="border-radius:12px;">
      <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= nl2br($error_msg) ?>
    </div>
  <?php endif; ?>

  <!-- KPI Widgets -->
  <div class="kpi-grid">
    <div class="kpi">
      <div>
        <div class="lbl">ត្រូវបង់ថ្ងៃនេះ & ហួសកំណត់</div>
        <div class="val"><?= (int)$total_due_count ?> នាក់</div>
      </div>
      <div class="ico"><i class="bi bi-calendar-x"></i></div>
    </div>
    <div class="kpi">
      <div>
        <div class="lbl">មាន Telegram (Ready)</div>
        <div class="val text-success"><?= (int)$telegram_ready_count ?> នាក់</div>
      </div>
      <div class="ico" style="background:#e8f5e9;color:#2e7d32;"><i class="bi bi-telegram"></i></div>
    </div>
    <div class="kpi">
      <div>
        <div class="lbl">បានផ្ញើថ្ងៃនេះរួច</div>
        <div class="val text-primary"><?= (int)$already_sent_today ?> នាក់</div>
      </div>
      <div class="ico" style="background:#e3f2fd;color:#1565c0;"><i class="bi bi-check2-all"></i></div>
    </div>
    <div class="kpi">
      <div>
        <div class="lbl">ទឹកប្រាក់ត្រូវបង់សរុប</div>
        <div class="val text-danger"><?= $total_pending_text ?></div>
      </div>
      <div class="ico" style="background:#ffebee;color:#c62828;"><i class="bi bi-cash-stack"></i></div>
    </div>
  </div>

  <!-- Table -->
  <div class="cardx p-0 overflow-hidden">
    <div class="p-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
      <h6 class="fw-bold m-0"><i class="bi bi-list-stars me-1"></i> បញ្ជីរំលឹកការសងប្រាក់</h6>
      <span class="text-muted small">បង្ហាញតែកម្ចីមិនទាន់បង់ចប់ និងសកម្មប៉ុណ្ណោះ</span>
    </div>

    <!-- Desktop Table -->
    <div class="table-responsive d-none d-lg-block">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th style="width:52px;">#</th>
            <th>អតិថិជន</th>
            <th>លេខកម្ចី</th>
            <th>ថ្ងៃកំណត់</th>
            <th>លើកទី</th>
            <th class="text-end">ត្រូវបង់</th>
            <th>ស្ថានភាព Telegram</th>
            <th class="text-end" style="width:200px;">សកម្មភាព</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($pagedSchedules)): ?>
            <tr>
              <td colspan="8" class="text-center text-muted py-4">មិនមានការសងប្រាក់ណាដែលហួសកំណត់ ឬ ត្រូវបង់នៅថ្ងៃនេះទេ។</td>
            </tr>
          <?php else: ?>
            <?php $i=$offset; foreach ($pagedSchedules as $p): $i++; ?>
              <?php
                $has_tg = !empty($p['telegram_chat_id']);
                $sent_today = ($p['last_followup_date'] === date('Y-m-d'));
                
                // Get remaining balance for preview
                $rem_bal = fin_get_loan_outstanding($pdo, (int)$p['loan_id']);
                
                // Generate message preview
                $previewText = '';
                if ($is_configured) {
                  $previewText = fin_parse_template($template, [
                    'customer_name' => $p['customer_name'],
                    'loan_code' => $p['loan_code'],
                    'installment_no' => $p['installment_no'],
                    'due_date' => $p['due_date'],
                    'amount_due' => $p['amount_due'],
                    'remaining_balance' => $rem_bal
                  ]);
                }
              ?>
              <tr>
                <td class="fw-bold"><?= $i ?></td>
                <td>
                  <div class="fw-bold"><?= h2($p['customer_name']) ?></div>
                  <div class="text-muted small"><i class="bi bi-telephone"></i> <?= h2($p['phone']) ?></div>
                </td>
                <td class="fw-bold text-primary"><?= h2($p['loan_code']) ?></td>
                <td class="fw-bold"><?= h2($p['due_date']) ?></td>
                <td><?= (int)$p['installment_no'] ?></td>
                <td class="text-end fw-bold text-danger mono"><?= fin_format_amount($p['amount_due'], $p['currency_code'] ?? 'USD') ?></td>
                <td>
                  <?php if (!$has_tg): ?>
                    <span class="status-badge badge-no-tg"><i class="bi bi-x-circle"></i> គ្មាន Chat ID</span>
                  <?php elseif ($sent_today): ?>
                    <span class="status-badge badge-sent-today" title="ផ្ញើចុងក្រោយ៖ <?= h2($p['last_followup_date']) ?>"><i class="bi bi-check2-all"></i> បានផ្ញើរួចថ្ងៃនេះ</span>
                  <?php else: ?>
                    <span class="status-badge badge-tg-ready"><i class="bi bi-telegram text-primary"></i> រួចរាល់</span>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <div class="d-flex justify-content-end gap-1">
                    <?php if ($is_configured): ?>
                      <button type="button" class="btn btn-sm btn-outline-secondary" 
                              onclick="showPreview(<?= $i ?>, '<?= h2($p['customer_name']) ?>', `<?= h2($previewText) ?>`)" 
                              title="មើលសារគំរូ">
                        <i class="bi bi-eye"></i>
                      </button>
                    <?php endif; ?>

                    <?php if ($has_tg && $is_configured): ?>
                      <button class="btn btn-sm btn-primary" onclick="sendFollowup(<?= (int)$p['schedule_id'] ?>, this)">
                        <i class="bi bi-send-fill me-1"></i> ផ្ញើ
                      </button>
                    <?php else: ?>
                      <button class="btn btn-sm btn-outline-secondary disabled" title="មិនអាចផ្ញើបាន"><i class="bi bi-send"></i> ផ្ញើ</button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Mobile Cards List -->
    <div class="d-lg-none p-3 bg-light-subtle">
      <?php if (empty($pagedSchedules)): ?>
        <div class="text-center py-4 text-muted">មិនមានការសងប្រាក់ណាដែលហួសកំណត់ ឬ ត្រូវបង់នៅថ្ងៃនេះទេ។</div>
      <?php else: ?>
        <?php $i=$offset; foreach ($pagedSchedules as $p): $i++; ?>
          <?php
            $has_tg = !empty($p['telegram_chat_id']);
            $sent_today = ($p['last_followup_date'] === date('Y-m-d'));
            $rem_bal = fin_get_loan_outstanding($pdo, (int)$p['loan_id']);
            $previewText = '';
            if ($is_configured) {
              $previewText = fin_parse_template($template, [
                'customer_name' => $p['customer_name'],
                'loan_code' => $p['loan_code'],
                'installment_no' => $p['installment_no'],
                'due_date' => $p['due_date'],
                'amount_due' => $p['amount_due'],
                'remaining_balance' => $rem_bal
              ]);
            }
          ?>
          <div class="card card-shadow mb-3 p-3 bg-white" style="border: 1px solid rgba(15,23,42,.10);">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div class="d-flex align-items-center">
                <!-- Customer Photo / Letter Badge -->
                <?php if (!empty($p['customer_photo'])): ?>
                  <img src="../<?= h2($p['customer_photo']) ?>" class="rounded-circle me-2" style="width: 40px; height: 40px; object-fit: cover;">
                <?php else: ?>
                  <div class="rounded-circle bg-secondary-subtle text-secondary fw-bold d-flex align-items-center justify-content-center me-2" style="width: 40px; height: 40px; font-size: 1.1rem;">
                    <?= mb_substr(h2($p['customer_name']), 0, 1) ?>
                  </div>
                <?php endif; ?>
                <div>
                  <h6 class="fw-bold mb-0 text-dark" style="font-size: 1rem;">#<?= $i ?> <?= h2($p['customer_name']) ?></h6>
                  <div class="text-muted small mt-1"><i class="bi bi-telephone"></i> <?= h2($p['phone']) ?></div>
                </div>
              </div>
              <div class="text-end">
                <span class="badge bg-primary-subtle text-primary fw-bold" style="font-size: 0.75rem; border-radius: 6px;"><?= h2($p['loan_code']) ?></span>
                <div class="fw-bold text-danger mt-1" style="font-size: 0.95rem;"><?= fin_format_amount($p['amount_due'], $p['currency_code'] ?? 'USD') ?></div>
              </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-2 py-1.5" style="border-bottom: 1px solid rgba(15,23,42,.05); font-size: 0.82rem;">
              <div>
                <span class="text-muted">ថ្ងៃកំណត់:</span> <span class="fw-bold text-dark"><?= h2($p['due_date']) ?></span>
              </div>
              <div>
                <span class="text-muted">លើកទី:</span> <span class="fw-bold text-dark"><?= (int)$p['installment_no'] ?></span>
              </div>
            </div>

            <div class="d-flex justify-content-between align-items-center">
              <div>
                <?php if (!$has_tg): ?>
                  <span class="badge bg-danger-subtle text-danger px-2 py-1" style="font-size: 0.75rem; border-radius: 6px;"><i class="bi bi-x-circle me-1"></i> គ្មាន Chat ID</span>
                <?php elseif ($sent_today): ?>
                  <span class="badge bg-primary-subtle text-primary px-2 py-1" style="font-size: 0.75rem; border-radius: 6px;" title="ផ្ញើចុងក្រោយ៖ <?= h2($p['last_followup_date']) ?>"><i class="bi bi-check2-all me-1"></i> បានផ្ញើថ្ងៃនេះ</span>
                <?php else: ?>
                  <span class="badge bg-success-subtle text-success px-2 py-1" style="font-size: 0.75rem; border-radius: 6px;"><i class="bi bi-telegram me-1"></i> រួចរាល់</span>
                <?php endif; ?>
              </div>
              <div class="d-flex gap-2">
                <?php if ($is_configured): ?>
                  <button type="button" class="btn btn-sm btn-outline-secondary px-2.5 py-1.5 d-flex align-items-center gap-1" style="border-radius: 8px;" 
                          onclick="showPreview(<?= $i ?>, '<?= h2($p['customer_name']) ?>', `<?= h2($previewText) ?>`)" 
                          title="មើលសារគំរូ">
                    <i class="bi bi-eye"></i> មើល
                  </button>
                <?php endif; ?>

                <?php if ($has_tg && $is_configured): ?>
                  <button class="btn btn-sm btn-primary px-3 py-1.5 d-flex align-items-center gap-1" style="border-radius: 8px;" onclick="sendFollowup(<?= (int)$p['schedule_id'] ?>, this)">
                    <i class="bi bi-send-fill"></i> ផ្ញើ
                  </button>
                <?php else: ?>
                  <button class="btn btn-sm btn-outline-secondary disabled px-3 py-1.5 d-flex align-items-center gap-1" style="border-radius: 8px;" title="មិនអាចផ្ញើបាន"><i class="bi bi-send"></i> ផ្ញើ</button>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="d-flex justify-content-between align-items-center p-3 border-top bg-light-subtle flex-wrap gap-2">
      <div class="text-muted small">
        បង្ហាញពី <?= $offset + 1 ?> ដល់ <?= min($offset + $limit, $total_items) ?> នៃ <?= $total_items ?> ជួរ
      </div>
      <nav aria-label="Page navigation">
        <ul class="pagination pagination-sm m-0">
          <li class="page-item <?= ($current_page <= 1) ? 'disabled' : '' ?>">
            <a class="page-link" href="?page=<?= $current_page - 1 ?>" aria-label="Previous">
              <span aria-hidden="true">&laquo;</span>
            </a>
          </li>
          <?php for ($p_idx = 1; $p_idx <= $total_pages; $p_idx++): ?>
            <li class="page-item <?= ($p_idx === $current_page) ? 'active' : '' ?>">
              <a class="page-link" href="?page=<?= $p_idx ?>"><?= $p_idx ?></a>
            </li>
          <?php endfor; ?>
          <li class="page-item <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">
            <a class="page-link" href="?page=<?= $current_page + 1 ?>" aria-label="Next">
              <span aria-hidden="true">&raquo;</span>
            </a>
          </li>
        </ul>
      </nav>
    </div>
    <?php endif; ?>
  </div>

</div>

<!-- Message Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:18px; border:none; box-shadow:0 15px 30px rgba(0,0,0,0.15);">
      <div class="modal-header bg-dark text-white py-3">
        <h5 class="modal-title fw-bold" id="previewModalLabel"><i class="bi bi-eye me-2"></i> គំរូសាររំលឹកសងប្រាក់</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4 bg-light">
        <div class="mb-2"><strong>ផ្ញើទៅ៖ </strong><span id="previewName">Sok</span></div>
        <div class="border rounded-4 p-3 bg-white" style="white-space: pre-wrap; font-family: sans-serif; font-size:.95rem; border:1px solid #e2e8f0; line-height: 1.5;" id="previewText">
          Message text...
        </div>
      </div>
      <div class="modal-footer border-0 bg-light py-2 justify-content-end">
        <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">បិទ</button>
      </div>
    </div>
  </div>
</div>

<script>
let previewModal = null;
document.addEventListener('DOMContentLoaded', () => {
  previewModal = new bootstrap.Modal(document.getElementById('previewModal'));
});

function showPreview(index, name, text) {
  document.getElementById('previewName').textContent = name;
  document.getElementById('previewText').textContent = text;
  previewModal.show();
}

async function sendFollowup(scheduleId, btn) {
  if (!confirm('ផ្ញើ followup ទៅកាន់អតិថិជននេះ?')) return;

  const originalContent = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> ផ្ញើ...';

  try {
    const res = await fetch(`auto_followup.php?action=send_single&ajax=1&schedule_id=${scheduleId}`, {
      method: 'POST',
      credentials: 'same-origin'
    });
    const js = await res.json();
    if (js.ok) {
      alert(js.message);
      location.reload();
    } else {
      alert(js.error || 'ផ្ញើមិនបានសម្រេច។');
      btn.disabled = false;
      btn.innerHTML = originalContent;
    }
  } catch (err) {
    console.error(err);
    alert('កំហុសប្រព័ន្ធ៖ ' + err.message);
    btn.disabled = false;
    btn.innerHTML = originalContent;
  }
}

async function sendTest(action, btn) {
  const isReport = action === 'test_owner_report';
  const confirmMsg = isReport 
    ? 'តើអ្នកចង់សាកល្បងផ្ញើរបាយការណ៍សង្ខេបថ្ងៃនេះ (Test Owner Report) ទៅកាន់ Telegram របស់ម្ចាស់ហាងដែរឬទេ?' 
    : 'តើអ្នកចង់សាកល្បងផ្ញើសារគំរូអតិថិជន (Test Borrower Reminder) ទៅកាន់ Telegram របស់ម្ចាស់ហាងដែរឬទេ?';
    
  if (!confirm(confirmMsg)) return;

  const originalContent = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> កំពុងផ្ញើ...';

  try {
    const res = await fetch(`auto_followup.php?action=${action}&ajax=1`, {
      method: 'POST',
      credentials: 'same-origin'
    });
    const js = await res.json();
    if (js.ok) {
      alert(js.message);
      location.reload();
    } else {
      alert(js.error || 'ផ្ញើសាកល្បងមិនបានសម្រេច។');
      btn.disabled = false;
      btn.innerHTML = originalContent;
    }
  } catch (err) {
    console.error(err);
    alert('កំហុសប្រព័ន្ធ៖ ' + err.message);
    btn.disabled = false;
    btn.innerHTML = originalContent;
  }
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // ✅ Initialize Bootstrap Popovers
  document.addEventListener("DOMContentLoaded", function(){
    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'))
    var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
      return new bootstrap.Popover(popoverTriggerEl)
    })
  });
</script>
</body>
</html>
