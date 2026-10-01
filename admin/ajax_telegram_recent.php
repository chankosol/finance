<?php
// /finance/admin/ajax_telegram_recent.php
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return;
    }
    // Clean any partial output
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => false,
        'error' => "PHP Error ($severity): $message in " . basename($file) . " on line $line"
    ]);
    exit;
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && ($error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => "PHP Fatal Error: {$error['message']} in " . basename($error['file']) . " on line {$error['line']}"
        ]);
        exit;
    }
});

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json');

fin_require_login();
$business_id = fin_require_business();
global $pdo;

// Load Telegram Bot Token
$bot_token = '';
if (defined('FIN_TELEGRAM_BOT_TOKEN') && FIN_TELEGRAM_BOT_TOKEN !== '') {
    $bot_token = FIN_TELEGRAM_BOT_TOKEN;
} else {
    $stSettings = $pdo->prepare("SELECT telegram_bot_token FROM business_settings WHERE business_id = ? LIMIT 1");
    $stSettings->execute([$business_id]);
    $bot_token = trim($stSettings->fetchColumn() ?: '');
}

if ($bot_token === '') {
    echo json_encode(['ok' => false, 'error' => 'Telegram Bot Token is not configured.']);
    exit;
}

$telegram_api_url = defined('FIN_TELEGRAM_API_URL') ? FIN_TELEGRAM_API_URL : 'https://api.telegram.org';
$telegram_api_url = rtrim($telegram_api_url, '/');

// Action to confirm/acknowledge and clear specific Telegram updates
$action = $_GET['action'] ?? '';
if ($action === 'confirm') {
    $update_id = (int)($_GET['update_id'] ?? 0);
    if ($update_id > 0) {
        $confirmUrl = $telegram_api_url . "/bot" . $bot_token . "/getUpdates?offset=" . ($update_id + 1) . "&limit=1";
        fin_http_request($confirmUrl, 'GET', [], null, 5);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// getUpdates cannot work while a Telegram webhook is active, so report that clearly.
$webhookUrl = $telegram_api_url . "/bot" . $bot_token . "/getWebhookInfo";
$webhookResponse = fin_http_request($webhookUrl, 'GET', [], null, 10);
if ($webhookResponse['ok']) {
    $webhookDecoded = json_decode($webhookResponse['body'], true);
    $activeWebhook = trim((string)($webhookDecoded['result']['url'] ?? ''));
    if (($webhookDecoded['ok'] ?? false) === true && $activeWebhook !== '') {
        echo json_encode([
            'ok' => false,
            'error' => 'Telegram webhook is active. Please click Delete Webhook, then ask the customer to send /start or a message to the bot, then refresh again.'
        ]);
        exit;
    }
}

// Call Telegram getUpdates API
$url = $telegram_api_url . "/bot" . $bot_token . "/getUpdates?limit=100";
$httpResponse = fin_http_request($url, 'GET', [], null, 10);

if (!$httpResponse['ok']) {
    echo json_encode(['ok' => false, 'error' => 'Failed to reach Telegram API: ' . $httpResponse['error']]);
    exit;
}

$resDecoded = json_decode($httpResponse['body'], true);
if (!isset($resDecoded['ok']) || $resDecoded['ok'] !== true) {
    $desc = $resDecoded['description'] ?? 'Unknown Telegram API error';
    echo json_encode(['ok' => false, 'error' => 'Telegram API Error: ' . $desc]);
    exit;
}

$updates = $resDecoded['result'] ?? [];
$recent_users = [];

foreach ($updates as $up) {
    $msg = $up['message'] ?? $up['edited_message'] ?? null;
    if (!$msg) continue;

    $chat = $msg['chat'] ?? null;
    $from = $msg['from'] ?? null;
    if (!$chat || !$from) continue;

    $chat_id = $chat['id'] ?? null;
    if (!$chat_id) continue;

    $first_name = $from['first_name'] ?? '';
    $last_name = $from['last_name'] ?? '';
    $full_name = trim($first_name . ' ' . $last_name);
    if ($full_name === '') {
        $full_name = $chat['title'] ?? 'Unknown User';
    }

    $username = $from['username'] ?? '';
    $text = $msg['text'] ?? '';
    $date = $msg['date'] ?? time();

    // Store or update in recent users array (keyed by chat_id to keep latest message)
    $recent_users[$chat_id] = [
        'update_id' => (int)($up['update_id'] ?? 0),
        'chat_id' => $chat_id,
        'full_name' => $full_name,
        'username' => $username,
        'message' => $text,
        'date' => date('Y-m-d H:i:s', $date)
    ];
}

// Convert to indexed array and sort by date descending (latest first)
$users_list = array_values($recent_users);
usort($users_list, function($a, $b) {
    return strcmp($b['date'], $a['date']);
});

echo json_encode([
    'ok' => true,
    'users' => $users_list
]);
exit;
