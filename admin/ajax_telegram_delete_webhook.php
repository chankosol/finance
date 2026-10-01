<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return;
    }
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

// Call Telegram deleteWebhook API
$url = $telegram_api_url . "/bot" . $bot_token . "/deleteWebhook";
$httpResponse = fin_http_request($url, 'GET', [], null, 10);

if (!$httpResponse['ok']) {
    echo json_encode(['ok' => false, 'error' => 'Failed to reach Telegram API: ' . $httpResponse['error']]);
    exit;
}

$resDecoded = json_decode($httpResponse['body'], true);
if (isset($resDecoded['ok']) && $resDecoded['ok'] === true) {
    echo json_encode(['ok' => true, 'message' => 'បានលុប Webhook ជោគជ័យ! លោកអ្នកអាចទាញយកបញ្ជីសារផ្ញើចូលជាថ្មីម្តងទៀតបានហើយ។']);
    exit;
}

$desc = $resDecoded['description'] ?? 'Unknown Telegram error';
echo json_encode(['ok' => false, 'error' => 'លុប Webhook មិនបានសម្រេច៖ ' . $desc]);
exit;
