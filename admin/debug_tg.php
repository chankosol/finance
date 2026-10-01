<?php
// /finance/admin/debug_tg.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json');

fin_require_login();
$business_id = fin_require_business();
global $pdo;

$stSettings = $pdo->prepare("SELECT telegram_bot_token, telegram_bot_username FROM business_settings WHERE business_id = ? LIMIT 1");
$stSettings->execute([$business_id]);
$row = $stSettings->fetch(PDO::FETCH_ASSOC);

$bot_token = '';
if (defined('FIN_TELEGRAM_BOT_TOKEN') && FIN_TELEGRAM_BOT_TOKEN !== '') {
    $bot_token = FIN_TELEGRAM_BOT_TOKEN;
} else {
    $bot_token = trim($row['telegram_bot_token'] ?? '');
}

$bot_username = trim($row['telegram_bot_username'] ?? '');

$telegram_api_url = defined('FIN_TELEGRAM_API_URL') ? FIN_TELEGRAM_API_URL : 'https://api.telegram.org';
$telegram_api_url = rtrim($telegram_api_url, '/');

$diagnostics = [];

if ($bot_token !== '') {
    // 1. Test curl directly if available
    $curl_enabled = function_exists('curl_init');
    $curl_result = 'disabled';
    $curl_error_msg = '';
    
    if ($curl_enabled) {
        $ch = curl_init($telegram_api_url . "/bot" . $bot_token . "/getMe");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        
        if ($err) {
            $curl_result = 'failed';
            $curl_error_msg = $err;
        } else {
            $curl_result = 'success';
            $curl_error_msg = json_decode($res, true);
        }
    }
    
    // 2. Test file_get_contents directly
    $fgc_result = 'failed';
    $fgc_error_msg = '';
    $options = [
        'http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ];
    $context = stream_context_create($options);
    
    $webhookInfo = [];
    $getMe = [];
    $getUpdates = [];
    $network_check = [];

    // Network connection checks
    $api_host = parse_url($telegram_api_url, PHP_URL_HOST) ?: 'api.telegram.org';
    $dns_ip = gethostbyname($api_host);
    $tcp_status = 'failed';
    $tcp_err = '';
    $tcp_time = '0ms';

    $t1 = microtime(true);
    $fp = @fsockopen($api_host, 443, $errno, $errstr, 5);
    $t2 = microtime(true);

    if ($fp) {
        $tcp_status = 'connected';
        fclose($fp);
    } else {
        $tcp_err = "$errstr ($errno)";
    }
    $tcp_time = round(($t2 - $t1) * 1000, 2) . 'ms';

    $network_check = [
        'api_host' => $api_host,
        'dns_resolution' => $dns_ip,
        'tcp_connection' => [
            'status' => $tcp_status,
            'error' => $tcp_err,
            'time' => $tcp_time
        ]
    ];
    $http_response_header = [];
    $res = @file_get_contents($telegram_api_url . "/bot" . $bot_token . "/getMe", false, $context);
    $fgc_headers = isset($http_response_header) ? $http_response_header : [];
    
    if ($res === false) {
        $fgc_result = 'failed';
        $error = error_get_last();
        $fgc_error_msg = $error ? $error['message'] : 'unknown stream error';
    } else {
        $fgc_result = 'success';
        $fgc_error_msg = json_decode($res, true) ?: $res;
    }
    
    // 3. Test fin_http_request (which now has double fallback!)
    $helper_res = fin_http_request("https://api.telegram.org/bot" . $bot_token . "/getMe", 'GET', [], null, 5);
    
    $diagnostics = [
        'curl_test' => [
            'enabled' => $curl_enabled,
            'status' => $curl_result,
            'detail' => $curl_error_msg
        ],
        'file_get_contents_test' => [
            'status' => $fgc_result,
            'headers' => $fgc_headers,
            'detail' => $fgc_error_msg
        ],
        'helper_test' => [
            'ok' => $helper_res['ok'],
            'body' => json_decode($helper_res['body'] ?? '{}', true) ?: ($helper_res['error'] ?? '')
        ]
    ];
}

echo json_encode([
    'business_id' => $business_id,
    'db_token_masked' => $bot_token !== '' ? (substr($bot_token, 0, 15) . '...' . substr($bot_token, -5)) : 'empty',
    'db_token_len' => strlen($bot_token),
    'db_bot_username' => $bot_username,
    'network_check' => isset($network_check) ? $network_check : 'not run',
    'diagnostics' => $diagnostics
], JSON_PRETTY_PRINT);
exit;
