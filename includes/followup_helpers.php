<?php
// /finance/includes/followup_helpers.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * Sends a message via the Telegram Bot API.
 */
function fin_send_telegram(string $token, string $chat_id, string $message): array {
    $telegram_api_url = defined('FIN_TELEGRAM_API_URL') ? FIN_TELEGRAM_API_URL : 'https://api.telegram.org';
    $telegram_api_url = rtrim($telegram_api_url, '/');
    $url = $telegram_api_url . "/bot" . $token . "/sendMessage";
    $payload = json_encode([
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'HTML'
    ]);

    $httpResponse = fin_http_request($url, 'POST', ['Content-Type:application/json'], $payload, 10);

    if (!$httpResponse['ok']) {
        return ['ok' => false, 'error' => $httpResponse['error']];
    }

    $resDecoded = json_decode($httpResponse['body'], true);
    if (isset($resDecoded['ok']) && $resDecoded['ok'] === true) {
        return ['ok' => true, 'response' => $resDecoded];
    }

    $msg = $resDecoded['description'] ?? 'Unknown Telegram error';
    return ['ok' => false, 'error' => $msg];
}

/**
 * Retrieves all pending followups (schedules that are due today or overdue and not fully paid).
 */
function fin_get_pending_followups(PDO $pdo, int $business_id): array {
    // Dynamic check for last_followup_date column
    $stCol = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'loan_schedules'
          AND COLUMN_NAME = 'last_followup_date'
    ");
    $stCol->execute();
    if ((int)$stCol->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE loan_schedules ADD last_followup_date DATE NULL;");
    }

    // Dynamic check for telegram_followup_days column in business_settings
    $stColBS = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'business_settings'
          AND COLUMN_NAME = 'telegram_followup_days'
    ");
    $stColBS->execute();
    if ((int)$stColBS->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE business_settings ADD telegram_followup_days INT DEFAULT 0;");
    }

    // Load offset days (default to 0 if not set)
    $stOffset = $pdo->prepare("SELECT telegram_followup_days FROM business_settings WHERE business_id = ? LIMIT 1");
    $stOffset->execute([$business_id]);
    $offsetDays = (int)($stOffset->fetchColumn() ?: 0);

    // Detect currency column dynamically
    $colCcy = 'currency_code';
    $stCol = $pdo->prepare("SHOW COLUMNS FROM loans LIKE 'currency_code'");
    $stCol->execute();
    if (!$stCol->fetch()) {
        $stCol = $pdo->prepare("SHOW COLUMNS FROM loans LIKE 'currency'");
        $stCol->execute();
        if ($stCol->fetch()) {
            $colCcy = 'currency';
        } else {
            $colCcy = 'ccy';
        }
    }

    $sql = "
        SELECT 
            s.id AS schedule_id,
            s.installment_no,
            s.due_date,
            s.total_due,
            s.paid_total,
            s.last_followup_date,
            (s.total_due - s.paid_total) AS amount_due,
            UPPER(TRIM(COALESCE(l.`$colCcy`, 'USD'))) AS currency_code,
            c.id AS customer_id,
            c.full_name AS customer_name,
            c.phone,
            c.telegram_username,
            c.telegram_chat_id,
            l.id AS loan_id,
            l.loan_code
        FROM loan_schedules s
        JOIN loans l ON l.id = s.loan_id
        JOIN customers c ON c.id = l.customer_id
        WHERE s.business_id = ?
          AND (s.total_due - s.paid_total) > 0.009
          AND DATE_ADD(s.due_date, INTERVAL " . (int)$offsetDays . " DAY) <= CURRENT_DATE()
          AND l.status = 'ACTIVE'
        ORDER BY s.due_date ASC, c.full_name ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$business_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Calculates the total outstanding balance of a loan.
 */
function fin_get_loan_outstanding(PDO $pdo, int $loan_id): float {
    $st = $pdo->prepare("
        SELECT SUM(total_due - paid_total)
        FROM loan_schedules
        WHERE loan_id = ?
    ");
    $st->execute([$loan_id]);
    return (float)($st->fetchColumn() ?: 0.0);
}

/**
 * Replaces placeholders in the message template with actual values.
 */
function fin_parse_template(string $template, array $data): string {
    $replace = [
        '{customer_name}' => $data['customer_name'] ?? '',
        '{loan_code}' => $data['loan_code'] ?? '',
        '{installment_no}' => $data['installment_no'] ?? '',
        '{due_date}' => $data['due_date'] ?? '',
        '{amount_due}' => number_format((float)($data['amount_due'] ?? 0), 2),
        '{remaining_balance}' => number_format((float)($data['remaining_balance'] ?? 0), 2),
    ];
    return strtr($template, $replace);
}

/**
 * Fetches the Telegram Bot username from getMe API.
 */
function fin_get_bot_username(string $token): ?string {
    $telegram_api_url = defined('FIN_TELEGRAM_API_URL') ? FIN_TELEGRAM_API_URL : 'https://api.telegram.org';
    $telegram_api_url = rtrim($telegram_api_url, '/');
    $url = $telegram_api_url . "/bot" . $token . "/getMe";
    $httpResponse = fin_http_request($url, 'GET', [], null, 5);
    
    if ($httpResponse['ok']) {
        $resDecoded = json_decode($httpResponse['body'], true);
        if (isset($resDecoded['ok']) && $resDecoded['ok'] === true && isset($resDecoded['result']['username'])) {
            return (string)$resDecoded['result']['username'];
        }
    }
    return null;
}
