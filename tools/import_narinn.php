<?php
declare(strict_types=1);

ini_set('session.save_path', sys_get_temp_dir());
require_once __DIR__ . '/../includes/db.php';

$jsonPath = $argv[1] ?? (__DIR__ . '/../tmp/narinn_data.json');
$dryRun = in_array('--dry-run', $argv, true);
$businessId = 1;

if (!is_file($jsonPath)) {
    fwrite(STDERR, "Prepared JSON not found: {$jsonPath}\n");
    exit(1);
}

$data = json_decode((string)file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
$customers = $data['customers'] ?? [];
$loans = $data['loans'] ?? [];
$repayments = $data['repayments'] ?? [];

if (count($customers) !== 768 || count($loans) !== 937 || count($repayments) !== 20) {
    throw new RuntimeException('Prepared data counts do not match the verified source totals.');
}

function gender_value(string $value): ?string {
    $value = trim($value);
    if ($value === 'ស្រី' || strtolower($value) === 'female') return 'female';
    if ($value === 'ប្រុស' || strtolower($value) === 'male') return 'male';
    return $value !== '' ? $value : null;
}

function loan_status(string $detail): string {
    return (str_contains($detail, 'បង់ផ្តាច់រួចរាល់') || str_contains($detail, 'កាត់ចោល'))
        ? 'CLOSED'
        : 'ACTIVE';
}

function end_date(string $startDate, int $months): ?string {
    if ($startDate === '') return null;
    $date = new DateTimeImmutable($startDate);
    return $date->modify('+' . max(1, $months) . ' months')->format('Y-m-d');
}

function marker(string $key): string {
    return '[Narinn import ' . $key . ']';
}

$stats = [
    'customers_inserted' => 0,
    'customers_existing' => 0,
    'loans_inserted' => 0,
    'loans_existing' => 0,
    'schedules_inserted' => 0,
    'payments_inserted' => 0,
    'payments_existing' => 0,
    'payments_skipped' => 0,
];

$pdo->beginTransaction();
try {
    $findCustomer = $pdo->prepare('SELECT id FROM customers WHERE business_id=? AND note LIKE ? LIMIT 1');
    $insertCustomer = $pdo->prepare(
        'INSERT INTO customers
         (business_id, full_name, gender, phone, nid, id_card, address, note, is_active, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())'
    );

    $customerIds = [];
    foreach ($customers as $customer) {
        $key = (string)$customer['import_customer_key'];
        $tag = marker($key);
        $findCustomer->execute([$businessId, $tag . '%']);
        $id = $findCustomer->fetchColumn();
        if ($id) {
            $stats['customers_existing']++;
        } else {
            $noteParts = [$tag];
            if (!empty($customer['note'])) $noteParts[] = trim((string)$customer['note']);
            $noteParts[] = 'Source customer rows: ' . (string)$customer['source_customer_ids'];
            $insertCustomer->execute([
                $businessId,
                trim((string)$customer['full_name']),
                gender_value((string)$customer['gender']),
                ($customer['phone'] ?? '') !== '' ? (string)$customer['phone'] : null,
                ($customer['nid'] ?? '') !== '' ? (string)$customer['nid'] : null,
                ($customer['nid'] ?? '') !== '' ? (string)$customer['nid'] : null,
                ($customer['address'] ?? '') !== '' ? (string)$customer['address'] : null,
                implode(' | ', $noteParts),
            ]);
            $id = (int)$pdo->lastInsertId();
            $stats['customers_inserted']++;
        }
        $customerIds[$key] = (int)$id;
    }

    $findLoan = $pdo->prepare('SELECT id FROM loans WHERE business_id=? AND loan_code=? LIMIT 1');
    $insertLoan = $pdo->prepare(
        'INSERT INTO loans
         (business_id, customer_id, loan_code, principal, interest_rate, interest_method,
          start_date, end_date, status, repayment_day, loan_type, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $findSchedule = $pdo->prepare('SELECT COUNT(*) FROM loan_schedules WHERE business_id=? AND loan_id=?');
    $insertSchedule = $pdo->prepare(
        'INSERT INTO loan_schedules
         (business_id, loan_id, installment_no, due_date, total_due, paid_total, status)
         VALUES (?, ?, 1, ?, ?, 0, ?)'
    );

    $loanIds = [];
    foreach ($loans as $loan) {
        $importKey = (string)$loan['import_loan_key'];
        $loanCode = 'NAR-' . (string)$loan['source_row_id'];
        $findLoan->execute([$businessId, $loanCode]);
        $loanId = $findLoan->fetchColumn();
        if ($loanId) {
            $stats['loans_existing']++;
        } else {
            $customerId = $customerIds[(string)$loan['import_customer_key']] ?? 0;
            if ($customerId <= 0) throw new RuntimeException("Missing customer mapping for {$importKey}");
            $startDate = (string)$loan['start_date'];
            $firstDue = (string)$loan['first_due_date'];
            $term = (int)$loan['term_months'];
            $detail = (string)$loan['status_detail'];
            $insertLoan->execute([
                $businessId,
                $customerId,
                $loanCode,
                (float)$loan['principal'],
                (float)$loan['interest_rate'],
                'FLAT',
                $startDate !== '' ? $startDate : null,
                end_date($startDate, $term),
                loan_status($detail),
                $firstDue !== '' ? (int)substr($firstDue, 8, 2) : null,
                ($loan['loan_type'] ?? '') !== '' ? (string)$loan['loan_type'] : 'General',
            ]);
            $loanId = (int)$pdo->lastInsertId();
            $stats['loans_inserted']++;
        }
        $loanId = (int)$loanId;
        $loanIds[$importKey] = $loanId;

        $findSchedule->execute([$businessId, $loanId]);
        if ((int)$findSchedule->fetchColumn() === 0 && (string)$loan['first_due_date'] !== '') {
            $due = (float)$loan['scheduled_repayment'];
            if ($due <= 0) $due = (float)$loan['principal'];
            $insertSchedule->execute([
                $businessId,
                $loanId,
                (string)$loan['first_due_date'],
                $due,
                loan_status((string)$loan['status_detail']) === 'CLOSED' ? 'PAID' : 'PENDING',
            ]);
            $stats['schedules_inserted']++;
        }
    }

    $findPayment = $pdo->prepare('SELECT id FROM loan_payments WHERE business_id=? AND note LIKE ? LIMIT 1');
    $insertPayment = $pdo->prepare(
        'INSERT INTO loan_payments
         (business_id, loan_id, amount, pay_date, method, note, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $getSchedule = $pdo->prepare(
        'SELECT id, total_due, paid_total FROM loan_schedules
         WHERE business_id=? AND loan_id=? ORDER BY due_date, installment_no LIMIT 1'
    );
    $updateSchedule = $pdo->prepare(
        'UPDATE loan_schedules SET paid_total=?, status=? WHERE id=? AND business_id=?'
    );

    foreach ($repayments as $payment) {
        if (($payment['import_as_payment'] ?? 'NO') !== 'YES' || empty($payment['import_loan_key'])) {
            $stats['payments_skipped']++;
            continue;
        }
        $followupId = (string)$payment['source_followup_id'];
        $paymentTag = '[Narinn repayment ' . $followupId . ']';
        $findPayment->execute([$businessId, $paymentTag . '%']);
        if ($findPayment->fetchColumn()) {
            $stats['payments_existing']++;
            continue;
        }
        $loanId = $loanIds[(string)$payment['import_loan_key']] ?? 0;
        if ($loanId <= 0) {
            $stats['payments_skipped']++;
            continue;
        }
        $note = $paymentTag . ' ' . trim((string)($payment['followup_reason'] ?? ''));
        $insertPayment->execute([
            $businessId,
            $loanId,
            (float)$payment['amount'],
            (string)$payment['pay_date'],
            'Cash',
            trim($note),
            ((int)($payment['staff_id'] ?? 0)) ?: null,
        ]);
        $stats['payments_inserted']++;

        $getSchedule->execute([$businessId, $loanId]);
        $schedule = $getSchedule->fetch(PDO::FETCH_ASSOC);
        if ($schedule) {
            $paid = min((float)$schedule['total_due'], (float)$schedule['paid_total'] + (float)$payment['amount']);
            $status = $paid + 0.00001 >= (float)$schedule['total_due'] ? 'PAID' : 'PARTIAL';
            $updateSchedule->execute([$paid, $status, (int)$schedule['id'], $businessId]);
        }
    }

    if ($dryRun) {
        $pdo->rollBack();
    } else {
        $pdo->commit();
    }
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'IMPORT FAILED: ' . $error->getMessage() . "\n");
    exit(1);
}

echo ($dryRun ? 'DRY RUN OK' : 'IMPORT OK') . "\n";
foreach ($stats as $key => $value) echo $key . '=' . $value . "\n";
