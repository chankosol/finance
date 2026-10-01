<?php
// /finance/cron/send_followups.php
// Automated background CLI script to send due loan followups via Telegram.
// Can be scheduled using Windows Task Scheduler or crontab.

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/followup_helpers.php';

// Set correct timezone
date_default_timezone_set('Asia/Phnom_Penh');

echo "==================================================\n";
echo "STARTING TELEGRAM REPAYMENT FOLLOWUPS - " . date('Y-m-d H:i:s') . "\n";
echo "==================================================\n";

global $pdo;

try {
    // Make sure new columns exist before querying
    $stColCheck = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'business_settings'
          AND COLUMN_NAME = 'telegram_owner_chat_id'
    ");
    $stColCheck->execute();
    if ((int)$stColCheck->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE business_settings ADD telegram_owner_chat_id VARCHAR(255) NULL;");
        $pdo->exec("ALTER TABLE business_settings ADD telegram_enable_daily_report TINYINT DEFAULT 0;");
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

    // 1. Fetch all active businesses with Telegram Bot Token configured
    $tokenCond = "AND bs.telegram_bot_token IS NOT NULL AND bs.telegram_bot_token != ''";
    if (defined('FIN_TELEGRAM_BOT_TOKEN') && FIN_TELEGRAM_BOT_TOKEN !== '') {
        $tokenCond = ""; // Skip check in SQL since it's defined in config.php
    }

    $sql = "
        SELECT b.id AS business_id, b.name AS business_name, bs.telegram_bot_token, bs.telegram_template,
               bs.telegram_owner_chat_id, bs.telegram_enable_daily_report
        FROM businesses b
        JOIN business_settings bs ON bs.business_id = b.id
        WHERE b.is_active = 1
          $tokenCond
          AND bs.telegram_template IS NOT NULL AND bs.telegram_template != ''
    ";
    $st = $pdo->query($sql);
    $businesses = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo "Found " . count($businesses) . " active business(es) with Telegram Bot configured.\n\n";

    foreach ($businesses as $biz) {
        $bizId = (int)$biz['business_id'];
        $bizName = $biz['business_name'];
        $botToken = trim($biz['telegram_bot_token'] ?? '');
        if (defined('FIN_TELEGRAM_BOT_TOKEN') && FIN_TELEGRAM_BOT_TOKEN !== '') {
            $botToken = FIN_TELEGRAM_BOT_TOKEN;
        }
        $template = trim($biz['telegram_template']);

        echo "Processing Business: $bizName (ID: $bizId)...\n";

        // 2. Fetch pending schedules for this business
        $pending = fin_get_pending_followups($pdo, $bizId);
        
        $total = count($pending);
        $sent_count = 0;
        $skip_count = 0;
        $fail_count = 0;

        echo " - Found $total pending/overdue installment schedule(s).\n";

        foreach ($pending as $sched) {
            $customerName = $sched['customer_name'];
            $loanCode = $sched['loan_code'];
            $instNo = $sched['installment_no'];
            $chatId = trim($sched['telegram_chat_id'] ?? '');

            // Skip if customer does not have Telegram Chat ID
            if ($chatId === '') {
                $skip_count++;
                echo "   [SKIP] Customer '$customerName' does not have a Telegram Chat ID.\n";
                continue;
            }

            // Skip if already sent today
            if ($sched['last_followup_date'] === date('Y-m-d')) {
                $skip_count++;
                echo "   [SKIP] Already followed up today for '$customerName' (Loan: $loanCode, Inst: $instNo).\n";
                continue;
            }

            try {
                // Fetch outstanding balance
                $outstanding = fin_get_loan_outstanding($pdo, (int)$sched['loan_id']);
                
                // Parse template
                $messageData = [
                    'customer_name' => $customerName,
                    'loan_code' => $loanCode,
                    'installment_no' => $instNo,
                    'due_date' => $sched['due_date'],
                    'amount_due' => $sched['amount_due'],
                    'remaining_balance' => $outstanding
                ];
                $messageText = fin_parse_template($template, $messageData);

                // Send message
                $res = fin_send_telegram($botToken, $chatId, $messageText);

                if ($res['ok']) {
                    // Update schedule's last followup date
                    $stUp = $pdo->prepare("UPDATE loan_schedules SET last_followup_date = CURRENT_DATE() WHERE id = ?");
                    $stUp->execute([$sched['schedule_id']]);
                    
                    $sent_count++;
                    echo "   [SENT] Successfully sent reminder to '$customerName' (Loan: $loanCode, Inst: $instNo).\n";
                } else {
                    $fail_count++;
                    echo "   [ERROR] Failed to send to '$customerName': " . $res['error'] . "\n";
                }
            } catch (Throwable $e) {
                $fail_count++;
                echo "   [EXCEPT] Exception for '$customerName': " . $e->getMessage() . "\n";
            }
        }

        // 3. Compile and Send Daily Repayment Summary Report to Owner if enabled
        $enableDailyReport = (int)($biz['telegram_enable_daily_report'] ?? 0) === 1;
        $ownerChatId = trim($biz['telegram_owner_chat_id'] ?? '');

        if ($enableDailyReport && $ownerChatId !== '') {
            echo " - Compiling daily report summary for owner...\n";
            try {
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
                $execParams = [$bizId, $todayDate];
                if ($colRepayDay) {
                    $execParams[] = $dayNum;
                    $execParams[] = $todayDate;
                }
                $stToday->execute($execParams);
                $dueTodayList = $stToday->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $dateStr = date('Y-m-d');
                $summaryMsg = "📊 <b>របាយការណ៍បំណុលត្រូវប្រមូលថ្ងៃនេះ - $dateStr</b>\n";
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

                        $phoneClean = preg_replace('/[^0-9+]/', '', $row['phone']);
                        $details .= "   $i. <b>{$row['customer_name']}</b> (<a href=\"tel:{$phoneClean}\">{$row['phone']}</a>)\n";
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

                $resOwner = fin_send_telegram($botToken, $ownerChatId, $summaryMsg);
                if ($resOwner['ok']) {
                    echo "   [SUCCESS] Sent daily summary report to Owner ($ownerChatId).\n";
                } else {
                    echo "   [ERROR] Failed to send report to owner: " . $resOwner['error'] . "\n";
                }
            } catch (Throwable $e) {
                echo "   [EXCEPT] Owner report error: " . $e->getMessage() . "\n";
            }
        }

        echo "Summary for '$bizName': Sent: $sent_count, Skipped: $skip_count, Failed: $fail_count.\n\n";
    }

} catch (Throwable $e) {
    echo "Fatal Cron Error: " . $e->getMessage() . "\n";
}

echo "==================================================\n";
echo "FINISHED REPAYMENT FOLLOWUPS - " . date('Y-m-d H:i:s') . "\n";
echo "==================================================\n";
