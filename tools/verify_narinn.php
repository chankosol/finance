<?php
declare(strict_types=1);

ini_set('session.save_path', sys_get_temp_dir());
require_once __DIR__ . '/../includes/db.php';

$checks = [
    'customers_total' => 'SELECT COUNT(*) FROM customers WHERE business_id=1',
    'customers_imported' => "SELECT COUNT(*) FROM customers WHERE business_id=1 AND note LIKE '[Narinn import CUST-%'",
    'loans_total' => 'SELECT COUNT(*) FROM loans WHERE business_id=1',
    'loans_imported' => "SELECT COUNT(*) FROM loans WHERE business_id=1 AND loan_code LIKE 'NAR-%'",
    'schedules_imported' => "SELECT COUNT(*) FROM loan_schedules s JOIN loans l ON l.id=s.loan_id WHERE s.business_id=1 AND l.loan_code LIKE 'NAR-%'",
    'payments_imported' => "SELECT COUNT(*) FROM loan_payments WHERE business_id=1 AND note LIKE '[Narinn repayment %'",
    'orphan_loans' => 'SELECT COUNT(*) FROM loans l LEFT JOIN customers c ON c.id=l.customer_id WHERE l.business_id=1 AND c.id IS NULL',
    'orphan_schedules' => 'SELECT COUNT(*) FROM loan_schedules s LEFT JOIN loans l ON l.id=s.loan_id WHERE s.business_id=1 AND l.id IS NULL',
    'multi_loan_customers' => "SELECT COUNT(*) FROM (SELECT customer_id FROM loans WHERE business_id=1 AND loan_code LIKE 'NAR-%' GROUP BY customer_id HAVING COUNT(*)>1) grouped",
];

foreach ($checks as $name => $sql) {
    echo $name . '=' . $pdo->query($sql)->fetchColumn() . PHP_EOL;
}

echo 'top_multi_loan_customers' . PHP_EOL;
$sql = "SELECT c.id, c.full_name, COUNT(*) AS loan_count
        FROM loans l JOIN customers c ON c.id=l.customer_id
        WHERE l.business_id=1 AND l.loan_code LIKE 'NAR-%'
        GROUP BY c.id, c.full_name HAVING COUNT(*)>1
        ORDER BY loan_count DESC, c.id LIMIT 5";
foreach ($pdo->query($sql) as $row) {
    echo $row['id'] . '|' . $row['full_name'] . '|' . $row['loan_count'] . PHP_EOL;
}
