<?php
// config.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ✅ Timezone (VERY important for due dates / schedules)
date_default_timezone_set('Asia/Phnom_Penh');

// ✅ App info (Khmer UI title)
define('FIN_APP_NAME', 'ប្រព័ន្ធគ្រប់គ្រងបញ្ចាំ និងកម្ចី');

// DB config  ⬇️  (ONLY change user & pass to your real ones)
define('FIN_DB_HOST', 'localhost');
define('FIN_DB_NAME', 'findppsz_finance');
define('FIN_DB_USER', 'findppsz_kosol');
define('FIN_DB_PASS', 'Kosol@2025');

// Base URL of this app (example: /finance)
define('FIN_BASE_URL', '/finance');

// ✅ Telegram Bot Config
define('FIN_TELEGRAM_API_URL', 'https://api.telegram.org');
// define('FIN_TELEGRAM_BOT_TOKEN', '8032499356:AAHOVMr8SQcO0yNSRdhszzgsJATH0-t3efE'); // Uncomment this to store token in config instead of database



// ✅ URL helper (avoid broken links)
function fin_url(string $path = ''): string {
    $base = rtrim(FIN_BASE_URL, '/');
    $path = ltrim($path, '/');
    return $path ? ($base . '/' . $path) : $base;
}

// ✅ Basic security headers (safe defaults)
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ✅ Gemini API Key (for NID OCR)
define('GOOGLE_GEMINI_API_KEY', 'AIzaSyCjp96KhySAl8iWTougxzhfQc9KRjaP_WU');