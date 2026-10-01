<?php
// /finance/admin/loan_payment_add.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

fin_require_login();
fin_require_permission('add_loan_payment');
$business_id = fin_require_business();
global $pdo;

function h2($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (!function_exists('fin_ccy_norm')) {
    function fin_ccy_norm(string $ccy): string {
        $c = strtoupper(trim($ccy));
        if (in_array($c, ['$', 'USD', 'US$'], true)) return 'USD';
        if (in_array($c, ['KHR', 'RIEL', '៛'], true)) return 'KHR';
        return $c ?: 'USD';
    }
}

if (!function_exists('fin_format_amount')) {
    function fin_format_amount($amt, $ccy): string {
        $ccy = strtoupper(trim($ccy));
        if ($ccy === 'KHR' || $ccy === '៛') {
            return number_format((float)$amt, 0) . ' ៛';
        }
        return '$' . number_format((float)$amt, 2);
    }
}

if (!function_exists('fin_format_date_kh')) {
    function fin_format_date_kh($dateStr): string {
        $trimmed = trim((string)$dateStr);
        if ($trimmed === '' || $trimmed === '0000-00-00' || $trimmed === '—' || $trimmed === '-') return '—';
        $time = strtotime($trimmed);
        if (!$time) return $trimmed;

        $d = date('d', $time);
        $m = (int)date('m', $time);
        $y = date('Y', $time);

        $khMonths = [
            1 => 'មករា',
            2 => 'កុម្ភៈ',
            3 => 'មីនា',
            4 => 'មេសា',
            5 => 'ឧសភា',
            6 => 'មិថុនា',
            7 => 'កក្កដា',
            8 => 'សីហា',
            9 => 'កញ្ញា',
            10 => 'តុលា',
            11 => 'វិច្ឆិកា',
            12 => 'ធ្នូ'
        ];

        $monthKh = $khMonths[$m] ?? '';
        return "{$d}-{$monthKh}-{$y}";
    }
}

if (!function_exists('fin_status_label')) {
    function fin_status_label(?string $status, $total_due = null, $paid_total = null, string $ccy = 'USD'): string {
        $c = strtoupper(trim($ccy));
        $isKhr = ($c === 'KHR' || $c === '៛');
        $threshold = $isKhr ? 1.0 : 0.01;

        if ($total_due !== null && $paid_total !== null) {
            $remaining = (float)$total_due - (float)$paid_total;
            if ($remaining < $threshold) {
                return '<span class="badge bg-success">បង់រួច</span>';
            }
            if ((float)$paid_total > $threshold) {
                return '<span class="badge bg-warning text-dark">បង់បានខ្លះ</span>';
            }
            $s = strtoupper(trim((string)$status));
            if ($s === 'PAID') {
                return '<span class="badge bg-success">បង់រួច</span>';
            }
            if ($s === 'MISSED') {
                return '<span class="badge bg-danger">មិនបានបង់</span>';
            }
            return '<span class="badge bg-secondary">មិនទាន់បង់</span>';
        }

        $s = strtoupper(trim((string)$status));
        switch ($s) {
            case 'PAID':
                return '<span class="badge bg-success">បង់រួច</span>';
            case 'PARTIAL':
                return '<span class="badge bg-warning text-dark">បង់បានខ្លះ</span>';
            case 'MISSED':
                return '<span class="badge bg-danger">មិនបានបង់</span>';
            default:
                return '<span class="badge bg-secondary">មិនទាន់បង់</span>';
        }
    }
}

/* ==========================================================
   Schema helper
========================================================== */
function fin_col_exists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
}

/* ==========================================================
   Detect columns / biz columns
========================================================== */
$loansBizCol  = fin_col_exists($pdo, 'loans', 'business_id') ? 'business_id' : (fin_col_exists($pdo,'loans','biz_id') ? 'biz_id' : 'business_id');
$schedBizCol  = fin_col_exists($pdo, 'loan_schedules', 'business_id') ? 'business_id' : (fin_col_exists($pdo,'loan_schedules','biz_id') ? 'biz_id' : 'business_id');
$payBizCol    = fin_col_exists($pdo, 'loan_payments', 'business_id') ? 'business_id' : (fin_col_exists($pdo,'loan_payments','biz_id') ? 'biz_id' : 'business_id');
$ledgerBizCol = fin_col_exists($pdo, 'cash_ledger', 'business_id') ? 'business_id' : (fin_col_exists($pdo,'cash_ledger','biz_id') ? 'biz_id' : 'business_id');

$loanHasCode    = fin_col_exists($pdo, 'loans', 'loan_code');
$loanHasStatus  = fin_col_exists($pdo, 'loans', 'status');

$schedHasPaid   = fin_col_exists($pdo, 'loan_schedules', 'paid_total');
$schedHasStatus = fin_col_exists($pdo, 'loan_schedules', 'status');

$payHasAmount    = fin_col_exists($pdo, 'loan_payments', 'amount');
$payHasPayDate   = fin_col_exists($pdo, 'loan_payments', 'pay_date');
$payHasMethod    = fin_col_exists($pdo, 'loan_payments', 'method');
$payHasNote      = fin_col_exists($pdo, 'loan_payments', 'note');
$payHasCreatedBy = fin_col_exists($pdo, 'loan_payments', 'created_by');
$payHasCreatedAt = fin_col_exists($pdo, 'loan_payments', 'created_at');

$ledgerHasType      = fin_col_exists($pdo, 'cash_ledger', 'type');       // IN/OUT
$ledgerHasAmt       = fin_col_exists($pdo, 'cash_ledger', 'amount');
$ledgerHasDate      = fin_col_exists($pdo, 'cash_ledger', 'txn_date');
$ledgerHasRef       = fin_col_exists($pdo, 'cash_ledger', 'ref_type');   // e.g. LOAN_PAYMENT
$ledgerHasRefId     = fin_col_exists($pdo, 'cash_ledger', 'ref_id');
$ledgerHasDesc      = fin_col_exists($pdo, 'cash_ledger', 'description');
$ledgerHasCreatedBy = fin_col_exists($pdo, 'cash_ledger', 'created_by');
$ledgerHasCreatedAt = fin_col_exists($pdo, 'cash_ledger', 'created_at');

$loan_id = (int)($_GET['loan_id'] ?? ($_POST['loan_id'] ?? 0));

/* ==========================================================
   Config
========================================================== */
$DISALLOW_OVERPAY = true; // set false if you want allow overpay

/* ==========================================================
   Load active loans for dropdown
========================================================== */
$whereStatus = $loanHasStatus ? " AND l.status IN ('ACTIVE','DEFAULT','active','overdue')" : "";
$custNameCol = 'full_name';
try {
  $st = $pdo->query("SHOW COLUMNS FROM customers");
  $cols = $st->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
  if (!in_array('full_name', $cols, true) && in_array('name', $cols, true)) $custNameCol = 'name';
} catch(Throwable $e){}

$sqlLoans = "
  SELECT
    l.id,
    ".($loanHasCode ? "l.loan_code," : "'' AS loan_code,")."
    c.`$custNameCol` AS customer_name
  FROM loans l
  JOIN customers c ON c.id = l.customer_id
  WHERE l.{$loansBizCol} = ?
  {$whereStatus}
  ORDER BY l.id DESC
  LIMIT 500
";
$st = $pdo->prepare($sqlLoans);
$st->execute([$business_id]);
$loans = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Ensure selected loan is in the list (even if status is not ACTIVE/CLOSED etc.)
if ($loan_id > 0) {
    $found = false;
    foreach ($loans as $l) {
        if ((int)$l['id'] === $loan_id) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        $stSpec = $pdo->prepare("
            SELECT l.id, l.loan_code, c.`$custNameCol` AS customer_name
            FROM loans l
            JOIN customers c ON c.id = l.customer_id
            WHERE l.id = ? AND l.{$loansBizCol} = ?
            LIMIT 1
        ");
        $stSpec->execute([$loan_id, $business_id]);
        $specLoan = $stSpec->fetch(PDO::FETCH_ASSOC);
        if ($specLoan) {
            $loans[] = $specLoan;
        }
    }
}

/* ==========================================================
   Helper: fetch loan + schedules (with outstanding)
========================================================== */
function fin_fetch_loan_info(
    PDO $pdo,
    int $business_id,
    int $loan_id,
    string $loansBizCol,
    string $schedBizCol,
    bool $schedHasPaid,
    bool $schedHasStatus
): array {
    $st = $pdo->prepare("
      SELECT l.*, c.full_name AS customer_name
      FROM loans l
      JOIN customers c ON c.id = l.customer_id
      WHERE l.id=? AND l.{$loansBizCol}=?
      LIMIT 1
    ");
    $st->execute([$loan_id, $business_id]);
    $loan = $st->fetch(PDO::FETCH_ASSOC);
    if (!$loan) return [
        'loan'=>null,'schedules'=>[],'outstanding'=>0.0,
        'settle_principal'=>0.0,'settle_current_interest'=>0.0,
        'settle_waived_interest'=>0.0,'settle_total'=>0.0,
        'first_unpaid_installment_no'=>0
    ];

    // schedules with principal, interest, and fee
    $st2 = $pdo->prepare("
      SELECT
        id,
        COALESCE(installment_no,0) AS installment_no,
        due_date,
        COALESCE(principal_due,0) AS principal_due,
        COALESCE(interest_due,0) AS interest_due,
        COALESCE(fee_due,0) AS fee_due,
        COALESCE(total_due,0) AS total_due,
        ".($schedHasPaid ? "COALESCE(paid_total,0) AS paid_total," : "0 AS paid_total,")."
        (COALESCE(total_due,0) - ".($schedHasPaid ? "COALESCE(paid_total,0)" : "0").") AS remaining,
        ".($schedHasStatus ? "status" : "'' AS status")."
      FROM loan_schedules
      WHERE loan_id=? AND {$schedBizCol}=?
      ORDER BY installment_no ASC, due_date ASC
    ");
    $st2->execute([$loan_id, $business_id]);
    $schedules = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $loanCcy = fin_ccy_norm($loan['currency_code'] ?? ($loan['currency'] ?? ($loan['ccy'] ?? 'USD')));
    $isKhrLoan = ($loanCcy === 'KHR' || $loanCcy === '៛');
    $threshold = $isKhrLoan ? 1.0 : 0.009;

    $outstanding = 0.0;
    $settle_principal = 0.0;
    $settle_current_interest = 0.0;
    $settle_waived_interest = 0.0;
    $settle_total = 0.0;
    $firstUnpaidFound = false;
    $firstUnpaidInstallmentNo = 0;

    foreach ($schedules as &$sc) {
        $rem = (float)($sc['remaining'] ?? 0);
        $princ = (float)($sc['principal_due'] ?? 0);
        $inte = (float)($sc['interest_due'] ?? 0);
        $fee = (float)($sc['fee_due'] ?? 0);
        $paid = (float)($sc['paid_total'] ?? 0);
        $total = (float)($sc['total_due'] ?? 0);

        if ($rem < $threshold) {
            $rem = 0.0;
            $sc['remaining'] = 0.0;
            $sc['paid_total'] = $total;
            if (isset($sc['status']) && $sc['status'] !== 'PAID' && $paid > 0) {
                $sc['status'] = 'PAID';
            }
        }

        if ($rem > 0.00001) {
            $outstanding += $rem;
            if (!$firstUnpaidFound) {
                // First unpaid installment (current period)
                $firstUnpaidFound = true;
                $firstUnpaidInstallmentNo = (int)$sc['installment_no'];
                $sc['is_current_due'] = true;
                $settle_total += $rem;
                $unpaidInterestFee = max(0, ($inte + $fee) - min($paid, $inte + $fee));
                $unpaidPrinc = max(0, $rem - $unpaidInterestFee);
                $settle_principal += $unpaidPrinc;
                $settle_current_interest += $unpaidInterestFee;
            } else {
                // Future unpaid installments
                $sc['is_future_unpaid'] = true;
                $settle_principal += $princ;
                $settle_waived_interest += $inte;
                $settle_total += ($princ + $fee); // future interest waived
            }
        }
    }
    unset($sc);

    return [
        'loan' => $loan,
        'schedules' => $schedules,
        'outstanding' => $outstanding,
        'settle_principal' => $settle_principal,
        'settle_current_interest' => $settle_current_interest,
        'settle_waived_interest' => $settle_waived_interest,
        'settle_total' => $settle_total,
        'first_unpaid_installment_no' => $firstUnpaidInstallmentNo
    ];
}

/* ==========================================================
   Defaults / Prefill from GET (loan_view row pay button)
========================================================== */
$errors = [];
$success = '';
$last_payment_id = 0;

$loan_id = (int)($_GET['loan_id'] ?? 0);
$isSettle = (isset($_GET['settle']) && $_GET['settle'] == '1') || (isset($_POST['is_settle']) && $_POST['is_settle'] == '1');
$waiveFutureInterest = isset($_POST['waive_future_interest']) ? ($_POST['waive_future_interest'] == '1') : true;

// Optional display only (from loan_view row button)
$pref_schedule_id  = (int)($_GET['schedule_id'] ?? 0);
$pref_installment  = (int)($_GET['installment_no'] ?? 0);

// Prefill pay_date/amount
$pay_date = trim($_GET['pay_date'] ?? date('Y-m-d'));
$amount   = trim($_GET['amount'] ?? '');
$method   = 'Cash';
$note     = $isSettle ? 'បង់ផ្តាច់កម្ចីមុនកាលកំណត់' : '';

/* ==========================================================
   Load loan info if selected
========================================================== */
$loanInfo = null;
$ccy = 'USD';
if ($loan_id > 0) {
    $loanInfo = fin_fetch_loan_info($pdo, $business_id, $loan_id, $loansBizCol, $schedBizCol, $schedHasPaid, $schedHasStatus);
    if (!$loanInfo['loan']) {
        $errors[] = 'រកមិនឃើញឥណទាននេះក្នុងក្រុមហ៊ុន។';
        $loan_id = 0;
        $loanInfo = null;
    } else {
        $ccyCol = fin_col_exists($pdo,'loans','currency_code') ? 'currency_code' : (fin_col_exists($pdo,'loans','currency') ? 'currency' : 'ccy');
        $ccy = fin_ccy_norm($loanInfo['loan'][$ccyCol] ?? 'USD');
        $isKhr = ($ccy === 'KHR' || $ccy === '៛');
        $decimals = $isKhr ? 0 : 2;

        if ($isSettle && $amount === '') {
            $amount = number_format((float)$loanInfo['settle_total'], $decimals, '.', ',');
        } elseif ($amount !== '') {
            $rawVal = (float)str_replace([',', ' '], '', $amount);
            $amount = number_format($rawVal, $decimals, '.', ',');
        }
    }
}
$isKhr = ($ccy === 'KHR' || $ccy === '៛');
$decimals = $isKhr ? 0 : 2;

/* ==========================================================
   POST: Save payment (FIFO)
========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loan_id  = (int)($_POST['loan_id'] ?? 0);
    $pay_date = trim($_POST['pay_date'] ?? date('Y-m-d'));
    $amountInput = trim($_POST['amount'] ?? '');
    $rawAmount = str_replace([',', ' '], '', $amountInput);
    $amount   = $amountInput;
    $method   = trim($_POST['method'] ?? 'Cash');
    $note     = trim($_POST['note'] ?? '');
    $isSettle = (isset($_POST['is_settle']) && $_POST['is_settle'] == '1');
    $waiveFutureInterest = isset($_POST['waive_future_interest']) && $_POST['waive_future_interest'] == '1';

    // display-only hidden fields (optional)
    $pref_schedule_id = (int)($_POST['schedule_id'] ?? 0);
    $pref_installment = (int)($_POST['installment_no'] ?? 0);

    if ($loan_id <= 0) $errors[] = 'សូមជ្រើសរើសឥណទាន។';
    if ($pay_date === '') $errors[] = 'សូមជ្រើសរើសថ្ងៃបង់ប្រាក់។';
    if ($rawAmount === '' || !is_numeric($rawAmount) || (float)$rawAmount <= 0) {
        $errors[] = 'សូមបញ្ចូលចំនួនប្រាក់បង់ (ចំនួន > 0)។';
    }

    $loanInfo = fin_fetch_loan_info($pdo, $business_id, $loan_id, $loansBizCol, $schedBizCol, $schedHasPaid, $schedHasStatus);
    if (!$loanInfo['loan']) $errors[] = 'ឥណទាននេះមិនត្រឹមត្រូវ។';

    if (!$errors) {
        $amt = (float)$rawAmount;
        $outstanding = (float)$loanInfo['outstanding'];

        $ccyCol = fin_col_exists($pdo,'loans','currency_code') ? 'currency_code' : (fin_col_exists($pdo,'loans','currency') ? 'currency' : 'ccy');
        $ccy = fin_ccy_norm($loanInfo['loan'][$ccyCol] ?? 'USD');
        $isKhr = ($ccy === 'KHR' || $ccy === '៛');
        $decimals = $isKhr ? 0 : 2;

        if ($outstanding <= 0.00001) {
            $errors[] = 'ឥណទាននេះបានបង់ចប់រួចហើយ ✅';
        } else if ($DISALLOW_OVERPAY && $amt > $outstanding + 0.00001) {
            $errors[] = 'ចំនួនប្រាក់បង់លើសពីប្រាក់នៅសល់។ សល់: ' . fin_format_amount($outstanding, $ccy);
        }
    }

    if (!$errors) {
        $uid = function_exists('fin_current_user_id') ? fin_current_user_id() : null;

        $pdo->beginTransaction();
        try {
            $amt = (float)$rawAmount;

            /* 1) Insert loan_payments */
            $fields = [];
            $ph = [];
            $vals = [];

            $fields[] = $payBizCol; $ph[]='?'; $vals[]=$business_id;
            $fields[] = 'loan_id';  $ph[]='?'; $vals[]=$loan_id;

            if ($payHasAmount)  { $fields[]='amount';   $ph[]='?'; $vals[]=$amt; }
            if ($payHasPayDate) { $fields[]='pay_date'; $ph[]='?'; $vals[]=$pay_date; }
            if ($payHasNote) {
                $finalNote = $note !== '' ? $note : ($isSettle ? 'បង់ផ្តាច់កម្ចីមុនកាលកំណត់' : null);
                $fields[]='note'; $ph[]='?'; $vals[]=$finalNote;
            }
            if ($payHasCreatedBy && $uid) { $fields[]='created_by'; $ph[]='?'; $vals[]=(int)$uid; }
            if ($payHasCreatedAt) { $fields[]='created_at'; $ph[]='NOW()'; }

            $sqlPay = "INSERT INTO loan_payments (".implode(',',$fields).") VALUES (".implode(',',$ph).")";
            $pdo->prepare($sqlPay)->execute($vals);
            $payment_id = (int)$pdo->lastInsertId();

            if ($isSettle) {
                /* ==========================================================
                   2a) Early Settlement: Update schedules
                ========================================================== */
                $firstInstNo = (int)($loanInfo['first_unpaid_installment_no'] ?? 0);

                foreach ($loanInfo['schedules'] as $sc) {
                    $scId   = (int)$sc['id'];
                    $instNo = (int)$sc['installment_no'];
                    $rem    = (float)$sc['remaining'];
                    $pDue   = (float)$sc['principal_due'];
                    $iDue   = (float)$sc['interest_due'];
                    $fDue   = (float)$sc['fee_due'];
                    $tDue   = (float)$sc['total_due'];

                    if ($rem <= 0.00001) {
                        // Already paid previously
                        continue;
                    }

                    if ($waiveFutureInterest && $instNo > $firstInstNo) {
                        // Future months: waive interest to 0.00
                        $newTotal = $pDue + $fDue;
                        $pdo->prepare("
                          UPDATE loan_schedules
                          SET interest_due = 0.00,
                              total_due = ?,
                              paid_total = ?,
                              status = 'PAID'
                          WHERE id=? AND {$schedBizCol}=? AND loan_id=?
                        ")->execute([$newTotal, $newTotal, $scId, $business_id, $loan_id]);
                    } else {
                        // Current month (and previous unpaid): mark fully paid
                        $pdo->prepare("
                          UPDATE loan_schedules
                          SET paid_total = total_due,
                              status = 'PAID'
                          WHERE id=? AND {$schedBizCol}=? AND loan_id=?
                        ")->execute([$scId, $business_id, $loan_id]);
                    }
                }

                /* 2b) Close loan completely */
                $setClauses = ["status='CLOSED'"];
                $paramsLoan = [];
                if (fin_col_exists($pdo, 'loans', 'status_detail')) {
                    $setClauses[] = "status_detail='បង់ផ្តាច់រួច'";
                }
                if (fin_col_exists($pdo, 'loans', 'end_date')) {
                    $setClauses[] = "end_date=?";
                    $paramsLoan[] = $pay_date;
                }
                $paramsLoan[] = $loan_id;
                $paramsLoan[] = $business_id;

                $pdo->prepare("
                  UPDATE loans
                  SET " . implode(', ', $setClauses) . "
                  WHERE id=? AND {$loansBizCol}=?
                ")->execute($paramsLoan);

            } else {
                /* ==========================================================
                   2) Standard FIFO payment to schedules
                ========================================================== */
                $remainingPay = $amt;

                foreach ($loanInfo['schedules'] as $sc) {
                    if ($remainingPay <= 0.00001) break;

                    $scId   = (int)$sc['id'];
                    $paid   = (float)$sc['paid_total'];
                    $total  = (float)$sc['total_due'];
                    $rem    = $total - $paid;

                    if ($rem <= 0.00001) continue;

                    $payThreshold = ($isKhr ? 1.0 : 0.01);
                    $use = min($rem, $remainingPay);
                    $newPaid = $paid + $use;
                    $remainingPay -= $use;

                    $newStatus = 'PARTIAL';
                    if (($total - $newPaid) < $payThreshold) {
                        $newPaid = $total;
                        $newStatus = 'PAID';
                    }

                    if ($schedHasPaid) {
                        if ($schedHasStatus) {
                            $pdo->prepare("
                              UPDATE loan_schedules
                              SET paid_total = ?, status = ?
                              WHERE id=? AND {$schedBizCol}=? AND loan_id=?
                            ")->execute([$newPaid, $newStatus, $scId, $business_id, $loan_id]);
                        } else {
                            $pdo->prepare("
                              UPDATE loan_schedules
                              SET paid_total = ?
                              WHERE id=? AND {$schedBizCol}=? AND loan_id=?
                            ")->execute([$newPaid, $scId, $business_id, $loan_id]);
                        }
                    }
                }

                /* Close loan if fully paid */
                if ($loanHasStatus) {
                    $stRemain = $pdo->prepare("
                      SELECT SUM(COALESCE(total_due,0) - ".($schedHasPaid ? "COALESCE(paid_total,0)" : "0").") AS remaining_sum
                      FROM loan_schedules
                      WHERE loan_id=? AND {$schedBizCol}=?
                    ");
                    $stRemain->execute([$loan_id, $business_id]);
                    $remainSum = (float)$stRemain->fetchColumn();

                    if ($remainSum < ($isKhr ? 1.0 : 0.01)) {
                        $setClauses = ["status='CLOSED'"];
                        $paramsLoan = [];
                        if (fin_col_exists($pdo, 'loans', 'status_detail')) {
                            $setClauses[] = "status_detail='បង់ផ្តាច់រួច'";
                        }
                        if (fin_col_exists($pdo, 'loans', 'end_date')) {
                            $setClauses[] = "end_date=?";
                            $paramsLoan[] = $pay_date;
                        }
                        $paramsLoan[] = $loan_id;
                        $paramsLoan[] = $business_id;

                        $pdo->prepare("
                          UPDATE loans
                          SET " . implode(', ', $setClauses) . "
                          WHERE id=? AND {$loansBizCol}=?
                        ")->execute($paramsLoan);
                    }
                }
            }

            /* 3) Insert cash_ledger IN */
            if ($ledgerHasAmt) {
                $lf=[]; $lp=[]; $lv=[];

                $lf[] = $ledgerBizCol; $lp[]='?'; $lv[]=$business_id;

                if ($ledgerHasType) { $lf[]='type'; $lp[]='?'; $lv[]='IN'; }

                $lf[]='amount'; $lp[]='?'; $lv[]=$amt;

                if ($ledgerHasDate) { $lf[]='txn_date'; $lp[]='?'; $lv[]=$pay_date; }

                if ($ledgerHasRef)   { $lf[]='ref_type'; $lp[]='?'; $lv[]='LOAN_PAYMENT'; }
                if ($ledgerHasRefId) { $lf[]='ref_id';   $lp[]='?'; $lv[]=$payment_id; }

                if ($ledgerHasDesc) {
                    if ($isSettle) {
                        $desc = 'ទទួលប្រាក់បង់ផ្តាច់ឥណទាន #' . $loan_id . ' (បង់ផ្តាច់)';
                    } else {
                        $desc = 'ទទួលប្រាក់បង់ឥណទាន #' . $loan_id;
                        if ($pref_installment > 0) $desc .= ' (Installment #' . $pref_installment . ')';
                    }
                    $lf[]='description'; $lp[]='?'; $lv[]=$desc;
                }

                if ($ledgerHasCreatedBy && $uid) { $lf[]='created_by'; $lp[]='?'; $lv[]=(int)$uid; }
                if ($ledgerHasCreatedAt) { $lf[]='created_at'; $lp[]='NOW()'; }

                $sqlL = "INSERT INTO cash_ledger (".implode(',',$lf).") VALUES (".implode(',',$lp).")";
                $pdo->prepare($sqlL)->execute($lv);
            }

            $pdo->commit();
            $last_payment_id = $payment_id;

            // Reload
            $loanInfo = fin_fetch_loan_info($pdo, $business_id, $loan_id, $loansBizCol, $schedBizCol, $schedHasPaid, $schedHasStatus);
            if ($isSettle) {
                $success = 'បានកត់ត្រាការបង់ផ្តាច់ឥណទានជោគជ័យ! ឥណទានត្រូវបានបញ្ចប់ជាស្ថាពរ ✅';
            } else {
                $success = 'បានរក្សាទុកការបង់ប្រាក់ជោគជ័យ ✅';
            }

            // Reset
            $amount = '';
            $note = '';

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'មានបញ្ហា៖ ' . $e->getMessage();
        }
    }
}


/* ==========================================================
   Get currency code of selected loan
========================================================== */
$ccy = 'USD';
if ($loanInfo && isset($loanInfo['loan'])) {
    $ccyCol = fin_col_exists($pdo,'loans','currency_code') ? 'currency_code' : (fin_col_exists($pdo,'loans','currency') ? 'currency' : 'ccy');
    $ccy = fin_ccy_norm($loanInfo['loan'][$ccyCol] ?? 'USD');
}

/* ==========================================================
   UI: helpful schedule preview
========================================================== */
$selectedInfoText = '';
if ($pref_schedule_id > 0 || $pref_installment > 0) {
    $selectedInfoText = 'អ្នកបានចុចបង់លើការបង់លើកទី ';
    if ($pref_installment > 0) $selectedInfoText .= (int)$pref_installment . ' ';
    if ($pref_schedule_id > 0) $selectedInfoText .= '(កូដកាលវិភាគ: ' . (int)$pref_schedule_id . ')';
    $selectedInfoText .= '។ ប្រព័ន្ធនឹងទូទាត់តាមលំដាប់លំដោយ (កាលវិភាគចាស់ជាងគេមុន) ជានិច្ច ✅';
}
?>
<!doctype html>
<html lang="km">
<head>
  <meta charset="utf-8">
  <title>បង់ប្រាក់ឥណទាន</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Battambang:wght@300;400;700;900&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

  <!-- Flatpickr (Calendar) -->
  <link href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" rel="stylesheet">

  <style>
    :root{ --bg:#f3f4f6; --card:#fff; --ink:#0f172a; --muted:#64748b; --line:#e5e7eb; }
    /* Make flatpickr look consistent */
    .flatpickr-calendar, .flatpickr-calendar *{ font-family:'Battambang',sans-serif !important; }
    body{font-family:'Battambang',sans-serif;background:var(--bg); color:var(--ink); font-weight: 300;}
    .cardx{background:var(--card);border-radius:18px;box-shadow:0 10px 25px rgba(0,0,0,.06); border:1px solid rgba(15,23,42,.06)}
    .page-title{font-weight:400}
    .help{color:var(--muted);font-size:.92rem;}
    .required:after{content:" *";color:#dc2626;}
    .kpi {
      border: 1px solid var(--line);
      background: linear-gradient(180deg, #fff, #fbfdff);
      border-radius: 16px; padding: 12px 14px; height: 100%;
      transition: all 0.2s ease;
    }
    .kpi:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
    }
    .kpi .v { font-size: 1.25rem; font-weight: 700; margin-top: 4px; }
    .kpi-blue {
      border-color: rgba(37, 99, 235, 0.15) !important;
      background: linear-gradient(180deg, #ffffff, #eff6ff) !important;
    }
    .kpi-blue .help { color: #1e40af !important; }
    .kpi-blue .kpi-icon { color: #2563eb !important; }
    .kpi-red {
      border-color: rgba(220, 38, 38, 0.15) !important;
      background: linear-gradient(180deg, #ffffff, #fef2f2) !important;
    }
    .kpi-red .help { color: #991b1b !important; }
    .kpi-red .kpi-icon { color: #dc2626 !important; }
    .kpi-orange {
      border-color: rgba(217, 119, 6, 0.15) !important;
      background: linear-gradient(180deg, #ffffff, #fffbeb) !important;
    }
    .kpi-orange .help { color: #92400e !important; }
    .kpi-orange .kpi-icon { color: #d97706 !important; }
    
    .badge-soft{background:#eef2ff;color:#3730a3;border-radius:999px;padding:.15rem .45rem;font-weight:800;font-size:.78rem;vertical-align:middle;}
    .btn-soft{border:1px solid var(--line); background:#fff; border-radius:12px}
    .table td, .table th{vertical-align:middle;}
    .row-pending{outline:2px solid rgba(59,130,246,.25); outline-offset:-2px; background:rgba(59,130,246,.06) !important;}
  </style>
</head>
<body>

<?php include __DIR__ . '/../includes/navbar.php'; ?>

<div class="container py-4">
  <div class="d-flex flex-wrap gap-2 justify-content-between align-items-start mb-3">
    <div>
      <h4 class="page-title mb-1 <?= $isSettle ? 'text-success' : '' ?>">
        <?php if ($isSettle): ?>
          <i class="bi bi-check2-circle me-1"></i> បង់ផ្តាច់ឥណទានមុនកាលកំណត់
        <?php else: ?>
          <i class="bi bi-cash-coin me-1"></i> បង់ប្រាក់ឥណទាន
        <?php endif; ?>
      </h4>
    </div>

    <div class="d-flex gap-2 flex-wrap">
      <?php if ($loan_id > 0): ?>
        <a href="loan_view.php?id=<?= (int)$loan_id ?>" class="btn btn-soft">
          <i class="bi bi-receipt-cutoff me-1"></i> មើលឥណទាន
        </a>
      <?php endif; ?>
      <a href="loans.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> ត្រឡប់ទៅបញ្ជីឥណទាន
      </a>
    </div>
  </div>

  <?php if ($loan_id > 0): ?>
    <div class="d-flex gap-2 mb-3">
      <a href="loan_payment_add.php?loan_id=<?= (int)$loan_id ?>" class="btn <?= !$isSettle ? 'btn-primary' : 'btn-outline-secondary' ?> btn-sm px-3" style="border-radius: 999px;">
        <i class="bi bi-cash-coin me-1"></i> បង់ប្រាក់ធម្មតា
      </a>
      <a href="loan_payment_add.php?loan_id=<?= (int)$loan_id ?>&settle=1" class="btn <?= $isSettle ? 'btn-success text-white' : 'btn-outline-success' ?> btn-sm px-3" style="border-radius: 999px;">
        <i class="bi bi-check2-circle me-1"></i> បង់ផ្តាច់កម្ចី
      </a>
    </div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="alert alert-success d-flex align-items-center justify-content-between flex-wrap gap-2 shadow-sm" style="border-radius: 14px;">
      <div><i class="bi bi-check-circle-fill me-2 fs-5"></i><?= h2($success) ?></div>
      <div class="d-flex gap-2 flex-wrap">
        <?php if (!empty($last_payment_id)): ?>
          <a href="loan_payment_receipt.php?id=<?= (int)$last_payment_id ?>" target="_blank" class="btn btn-sm btn-primary d-inline-flex align-items-center gap-1 shadow-sm">
            <i class="bi bi-printer"></i> <?= $isSettle ? 'បោះពុម្ពវិក័យបត្របង់ផ្តាច់' : 'បោះពុម្ពបង្កាន់ដៃ' ?>
          </a>
        <?php endif; ?>
        <?php if ($loan_id > 0): ?>
          <a href="loan_view.php?id=<?= (int)$loan_id ?>" class="btn btn-sm btn-outline-success">មើលកម្ចីនេះ</a>
          <a href="loans.php" class="btn btn-sm btn-success">បញ្ជីឥណទាន</a>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i> សូមពិនិត្យម្តងទៀត៖</div>
      <?php foreach ($errors as $e): ?>
        <div>• <?= h2($e) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($isSettle && $loanInfo && $loanInfo['loan']): ?>
    <!-- Early Settlement Breakdown Banner -->
    <div class="card mb-3 shadow-sm border-0" style="border-radius: 16px; background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border: 1px solid #86efac !important;">
      <div class="card-body p-3 p-lg-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
          <div class="d-flex align-items-center gap-2 text-success">
            <i class="bi bi-patch-check-fill fs-4 text-success"></i>
            <span class="fw-bold" style="font-size: 1.05rem;">របៀបគណនាបង់ផ្តាច់ឥណទានមុនកាលកំណត់</span>
          </div>
          <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill" style="font-size: 0.82rem;">
            <i class="bi bi-shield-check me-1"></i> បញ្ចប់កម្ចីជាស្ថាពរ
          </span>
        </div>

        <div class="row g-3">
          <div class="col-6 col-md-3">
            <div class="p-2 rounded-3 bg-white bg-opacity-75 border border-success-subtle">
              <div class="text-muted small mb-1"><i class="bi bi-cash me-1"></i> ប្រាក់ដើមនៅសល់</div>
              <div class="fw-bold fs-5 mono text-dark"><?= fin_format_amount($loanInfo['settle_principal'], $ccy) ?></div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="p-2 rounded-3 bg-white bg-opacity-75 border border-success-subtle">
              <div class="text-muted small mb-1"><i class="bi bi-percent me-1"></i> ការប្រាក់ខែបច្ចុប្បន្ន</div>
              <div class="fw-bold fs-5 mono text-dark"><?= fin_format_amount($loanInfo['settle_current_interest'], $ccy) ?></div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="p-2 rounded-3 bg-white bg-opacity-75 border border-success-subtle">
              <div class="text-muted small mb-1"><i class="bi bi-gift-fill text-success me-1"></i> ការប្រាក់លើកលែង</div>
              <div class="fw-bold fs-5 mono text-success">-<?= fin_format_amount($loanInfo['settle_waived_interest'], $ccy) ?></div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="p-2 rounded-3 bg-white border border-primary" style="box-shadow: 0 4px 12px rgba(37,99,235,0.08);">
              <div class="text-primary small fw-bold mb-1"><i class="bi bi-check2-all me-1"></i> ប្រាក់ត្រូវទូទាត់ផ្តាច់</div>
              <div class="fw-bold fs-5 mono text-primary"><?= fin_format_amount($loanInfo['settle_total'], $ccy) ?></div>
            </div>
          </div>
        </div>

        <div class="mt-3 pt-3 border-top d-flex align-items-center justify-content-between flex-wrap gap-2" style="border-color: rgba(34, 197, 94, 0.25) !important;">
          <div class="form-check form-switch m-0">
            <input class="form-check-input" type="checkbox" role="switch" name="toggle_waive" id="toggle_waive" value="1" <?= $waiveFutureInterest ? 'checked' : '' ?> onchange="toggleWaiveInterest(this)">
            <label class="form-check-label fw-bold text-dark" for="toggle_waive">
              លើកលែងការប្រាក់ខែបន្តបន្ទាប់
            </label>
          </div>
          <div class="text-muted small">
            <i class="bi bi-info-circle me-1"></i> អតិថិជនសន្សំបាន <strong class="text-success"><?= fin_format_amount($loanInfo['settle_waived_interest'], $ccy) ?></strong> លើការប្រាក់ខែអនាគត
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="cardx mb-3">
    <div class="card-body p-3 p-lg-4">
      <form method="post" class="row g-3">

        <!-- keep schedule info & settle flag -->
        <input type="hidden" name="schedule_id" value="<?= (int)$pref_schedule_id ?>">
        <input type="hidden" name="installment_no" value="<?= (int)$pref_installment ?>">
        <input type="hidden" name="is_settle" value="<?= $isSettle ? '1' : '0' ?>">
        <input type="hidden" name="waive_future_interest" id="hidden_waive" value="<?= $waiveFutureInterest ? '1' : '0' ?>">

        <div class="col-12 col-lg-6">
          <label class="form-label required">ជ្រើសរើសឥណទាន</label>
          <select class="form-select" name="loan_id" id="loan_id" required
                  onchange="location.href='loan_payment_add.php?loan_id='+this.value + '<?= $isSettle ? '&settle=1' : '' ?>';">
            <option value="">-- ជ្រើសរើស --</option>
            <?php foreach ($loans as $l): ?>
              <?php
                $code = trim((string)($l['loan_code'] ?? ''));
                $label = ($code !== '' ? $code . ' • ' : '') . ($l['customer_name'] ?? '');
              ?>
              <option value="<?= (int)$l['id'] ?>" <?= ($loan_id == (int)$l['id']) ? 'selected':''; ?>>
                <?= h2($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-lg-3">
          <label class="form-label required">ថ្ងៃបង់ប្រាក់</label>
          <input type="text" name="pay_date" id="pay_date" class="form-control" value="<?= h2($pay_date) ?>" required placeholder="ថ្ងៃ-ខែ-ឆ្នាំ">
        </div>

        <div class="col-12 col-lg-3">
          <label class="form-label required"><?= $isSettle ? 'ចំនួនប្រាក់ទូទាត់ផ្តាច់' : 'ចំនួនប្រាក់បង់' ?></label>
          <div class="input-group">
            <input type="text" inputmode="numeric" name="amount" id="amount"
                   class="form-control mono fw-bold <?= $isSettle ? 'border-success text-success fs-5' : 'text-primary' ?>"
                   value="<?= h2($amount) ?>" required placeholder="0" autocomplete="off"
                   oninput="this.value = formatNumberWithCommas(this.value, <?= $isKhr ? 'true' : 'false' ?>);">
            <span class="input-group-text fw-bold <?= $isSettle ? 'border-success bg-success-subtle text-success' : '' ?>">
              <?= ($ccy === 'KHR' || $ccy === '៛') ? '៛' : '$' ?>
            </span>
          </div>
        </div>

        <div class="col-12 col-lg-4">
          <label class="form-label">វិធីបង់ប្រាក់</label>
          <select name="method" class="form-select">
            <?php 
            $methods = [
              'Cash' => 'សាច់ប្រាក់',
              'Bank' => 'គណនីធនាគារ',
              'ABA' => 'ABA Bank',
              'Wing' => 'Wing',
              'Other' => 'ផ្សេងៗ'
            ];
            foreach ($methods as $val => $lbl): ?>
              <option value="<?= h2($val) ?>" <?= ($method===$val?'selected':'') ?>><?= h2($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-lg-8">
          <label class="form-label">សម្គាល់/ចំណាំ</label>
          <input type="text" name="note" class="form-control"
                 value="<?= h2($note) ?>"
                 placeholder="ឧ. បង់តាម ABA / បង់ផ្តាច់មុនកាលកំណត់...">
        </div>

        <div class="col-12 d-flex gap-2 flex-wrap justify-content-end align-items-center">
          <a class="btn btn-soft" href="loan_payment_add.php<?= $loan_id?('?loan_id='.$loan_id.($isSettle?'&settle=1':'')):'' ?>">
            <i class="bi bi-arrow-clockwise me-1"></i> ផ្ទុកឡើងវិញ
          </a>
          <button class="btn <?= $isSettle ? 'btn-success px-4' : 'btn-primary' ?>" type="submit">
            <i class="bi <?= $isSettle ? 'bi-check2-circle' : 'bi-save2' ?> me-1"></i>
            <?= $isSettle ? 'បញ្ជាក់ការបង់ផ្តាច់ឥណទាន' : 'រក្សាទុកការបង់ប្រាក់' ?>
          </button>
        </div>
      </form>
    </div>
  </div>


  <?php if ($loan_id && $loanInfo && $loanInfo['loan']): ?>
    <?php
      $customer_name = $loanInfo['loan']['customer_name'] ?? '';
      $outstanding = (float)$loanInfo['outstanding'];

      // Find pending schedule for highlight
      $pendingId = 0;
      foreach ($loanInfo['schedules'] as $sc) {
        if ((float)$sc['remaining'] > 0.00001) { $pendingId = (int)$sc['id']; break; }
      }
    ?>
    <div class="row g-3 mb-3">
      <div class="col-12 col-md-4">
        <div class="kpi kpi-blue">
          <div class="d-flex justify-content-between align-items-center">
            <span class="help"><i class="bi bi-person me-1"></i> អតិថិជន</span>
            <i class="bi bi-person-fill kpi-icon" style="font-size: 1.1rem;"></i>
          </div>
          <div class="v"><?= h2($customer_name) ?></div>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="kpi kpi-red">
          <div class="d-flex justify-content-between align-items-center">
            <span class="help"><i class="bi bi-hourglass-split me-1"></i> ប្រាក់នៅសល់សរុប</span>
            <i class="bi bi-cash-stack kpi-icon" style="font-size: 1.1rem;"></i>
          </div>
          <div class="v"><?= fin_format_amount($outstanding, $ccy) ?></div>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="kpi kpi-orange">
          <div class="d-flex justify-content-between align-items-center">
            <span class="help"><i class="bi bi-calendar2-week me-1"></i> ចំនួនកាលវិភាគ</span>
            <i class="bi bi-list-ol kpi-icon" style="font-size: 1.1rem;"></i>
          </div>
          <div class="v"><?= count($loanInfo['schedules']) ?> <span class="text-muted ms-1" style="font-size: 0.8rem; font-weight: 300;">លើក</span></div>
        </div>
      </div>
    </div>

    <div class="cardx">
      <div class="card-body p-3 p-lg-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
          <div class="fw-bold"><i class="bi bi-calendar-check me-1"></i> កាលវិភាគបង់ប្រាក់</div>
          <?php if ($isSettle): ?>
            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" style="font-size: 0.78rem;">
              <i class="bi bi-gift-fill me-1"></i> ការប្រាក់ខែអនាគតត្រូវបានលើកលែងក្នុងការបង់ផ្តាច់
            </span>
          <?php endif; ?>
        </div>

        <!-- Desktop View Table -->
        <div class="table-responsive d-none d-md-block">
          <table class="table table-sm table-striped align-middle">
            <thead>
              <tr>
                <th style="width:48px">#</th>
                <th style="width:130px">ថ្ងៃដល់កំណត់</th>
                <th class="text-end" style="width:120px">ប្រាក់ដើម</th>
                <th class="text-end" style="width:130px">ការប្រាក់</th>
                <th class="text-end" style="width:120px">ត្រូវបង់</th>
                <th class="text-end" style="width:120px">បានបង់</th>
                <th class="text-end" style="width:120px">នៅសល់</th>
                <th style="width:110px">ស្ថានភាព</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$loanInfo['schedules']): ?>
                <tr><td colspan="8" class="text-center text-muted py-3">មិនមានកាលវិភាគ។ សូមពិនិត្យ loan_schedules</td></tr>
              <?php else: ?>
                <?php foreach ($loanInfo['schedules'] as $sc): ?>
                  <?php
                    $rem = max(0, (float)$sc['remaining']);
                    $isFutureUnpaid = !empty($sc['is_future_unpaid']);
                    $rowClass = ((int)$sc['id'] === (int)$pendingId && $rem > 0.00001) ? 'row-pending' : '';
                    if ($rem <= 0.00001) $rowClass = 'bg-success-subtle bg-opacity-25';
                  ?>
                  <tr class="<?= $rowClass ?>">
                    <td class="fw-bold"><?= (int)$sc['installment_no'] ?></td>
                    <td class="text-nowrap" style="font-family: var(--font-family); font-weight: 600;"><?= h2(fin_format_date_kh($sc['due_date'])) ?></td>
                    <td class="text-end"><?= fin_format_amount((float)$sc['principal_due'], $ccy) ?></td>
                    <td class="text-end">
                      <?php if ($isSettle && $isFutureUnpaid): ?>
                        <del class="text-muted" style="font-size:0.82rem;"><?= fin_format_amount((float)$sc['interest_due'], $ccy) ?></del>
                        <span class="badge bg-success-subtle text-success ms-1" style="font-size: 0.68rem; padding: 2px 6px;">លើកលែង</span>
                      <?php else: ?>
                        <?= fin_format_amount((float)$sc['interest_due'], $ccy) ?>
                      <?php endif; ?>
                    </td>
                    <td class="text-end"><?= fin_format_amount((float)$sc['total_due'], $ccy) ?></td>
                    <td class="text-end"><?= fin_format_amount((float)$sc['paid_total'], $ccy) ?></td>
                    <td class="text-end fw-bold"><?= fin_format_amount($rem, $ccy) ?></td>
                    <td><?= fin_status_label($sc['status'] ?? '', $sc['total_due'], $sc['paid_total'], $ccy) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Mobile View Cards -->
        <div class="d-md-none">
          <?php if (!$loanInfo['schedules']): ?>
            <div class="text-center text-muted py-3">មិនមានកាលវិភាគ។</div>
          <?php else: ?>
            <?php foreach ($loanInfo['schedules'] as $sc): ?>
              <?php
                $rem = max(0, (float)$sc['remaining']);
                $isPending = ((int)$sc['id'] === (int)$pendingId && $rem > 0.00001);
                $isFutureUnpaid = !empty($sc['is_future_unpaid']);
                $cardStyle = $isPending ? 'border-color: rgba(59, 130, 246, 0.4); background: rgba(59, 130, 246, 0.04);' : '';
              ?>
              <div class="card p-3 mb-2" style="border: 1px solid var(--line); border-radius: 12px; <?= $cardStyle ?>">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <div class="fw-bold text-primary" style="font-size: 0.95rem;">
                    លើកទី #<?= (int)$sc['installment_no'] ?>
                  </div>
                  <div>
                    <?= fin_status_label($sc['status'] ?? '', $sc['total_due'], $sc['paid_total'], $ccy) ?>
                  </div>
                </div>
                <div class="row g-2 text-start">
                  <div class="col-6">
                    <div class="text-muted" style="font-size: 0.78rem;"><i class="bi bi-calendar-event me-1"></i> ថ្ងៃដល់កំណត់</div>
                    <div class="fw-semibold" style="font-size: 0.88rem; font-family: var(--font-family);"><?= h2(fin_format_date_kh($sc['due_date'])) ?></div>
                  </div>
                  <div class="col-6 text-end">
                    <div class="text-muted" style="font-size: 0.78rem;"><i class="bi bi-cash-stack me-1"></i> ត្រូវបង់</div>
                    <div class="mono fw-bold" style="font-size: 0.88rem;"><?= fin_format_amount((float)$sc['total_due'], $ccy) ?></div>
                  </div>
                  <div class="col-6">
                    <div class="text-muted" style="font-size: 0.78rem;"><i class="bi bi-percent me-1"></i> ការប្រាក់</div>
                    <div class="mono" style="font-size: 0.88rem;">
                      <?php if ($isSettle && $isFutureUnpaid): ?>
                        <del class="text-muted"><?= fin_format_amount((float)$sc['interest_due'], $ccy) ?></del>
                        <span class="badge bg-success-subtle text-success" style="font-size: 0.65rem;">លើកលែង</span>
                      <?php else: ?>
                        <?= fin_format_amount((float)$sc['interest_due'], $ccy) ?>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="col-6 text-end">
                    <div class="text-muted" style="font-size: 0.78rem;"><i class="bi bi-hourglass-split me-1"></i> នៅសល់</div>
                    <div class="mono fw-bold text-danger" style="font-size: 0.88rem;"><?= fin_format_amount($rem, $ccy) ?></div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

      </div>
    </div>
  <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Flatpickr JS -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/km.js"></script>
<script>
  if (window.flatpickr) {
    flatpickr("#pay_date", {
      dateFormat: "Y-m-d",
      allowInput: true,
      locale: "km",
      disableMobile: true,
      altInput: true,
      altFormat: "d-F-Y"
    });
  }

  function formatNumberWithCommas(val, isKhr) {
    if (!val) return '';
    let clean = val.replace(/[^0-9.]/g, '');
    if (clean === '') return '';
    let parts = clean.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    if (isKhr) {
      return parts[0];
    }
    return parts.length > 1 ? parts[0] + '.' + parts[1].slice(0, 2) : parts[0];
  }

  function toggleWaiveInterest(el) {
    const hiddenWaive = document.getElementById('hidden_waive');
    if (hiddenWaive) hiddenWaive.value = el.checked ? '1' : '0';
    const amtInput = document.getElementById('amount') || document.querySelector('input[name="amount"]');
    if (!amtInput) return;
    if (el.checked) {
      amtInput.value = '<?= isset($loanInfo['settle_total']) ? number_format((float)$loanInfo['settle_total'], $decimals, '.', ',') : '' ?>';
    } else {
      amtInput.value = '<?= isset($loanInfo['outstanding']) ? number_format((float)$loanInfo['outstanding'], $decimals, '.', ',') : '' ?>';
    }
  }
</script>
</body>
</html>
