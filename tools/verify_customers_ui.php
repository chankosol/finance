<?php
declare(strict_types=1);

chdir(__DIR__ . '/../admin');
ini_set('session.save_path', sys_get_temp_dir());
require_once '../includes/config.php';

$_SESSION['fin_user_id'] = 1;
$_SESSION['fin_business_id'] = 1;
$_GET = ['date_from' => '2026-06-01', 'date_to' => '2026-06-30'];
$_SERVER['PHP_SELF'] = '/finance/admin/customers.php';

ob_start();
include 'customers.php';
$html = (string)ob_get_clean();

$checks = [
    'date_controls' => str_contains($html, 'name="date_from"') && str_contains($html, 'name="date_to"'),
    'selected_date' => str_contains($html, 'value="2026-06-01"'),
    'presence_dot' => str_contains($html, 'presence-dot active'),
    'riel_symbol' => str_contains($html, '៛'),
    'filter_title' => str_contains($html, 'bi-sliders2'),
];

foreach ($checks as $name => $passed) {
    echo $name . '=' . ($passed ? 'yes' : 'no') . PHP_EOL;
    if (!$passed) exit(1);
}
