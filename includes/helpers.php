<?php
// helpers.php

// Protect output (anti-XSS)
if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

/* ---------------------------
   URL helper
---------------------------- */
if (!function_exists('fin_url')) {
    function fin_url(string $path = ''): string {
        if (!defined('FIN_BASE_URL')) return $path;
        $base = rtrim(FIN_BASE_URL, '/');
        $path = ltrim($path, '/');
        return $path ? ($base . '/' . $path) : $base;
    }
}

/* ---------------------------
   Flash messages (session)
---------------------------- */
if (!function_exists('set_flash')) {
    function set_flash(string $type, string $message): void {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }
}

if (!function_exists('get_flash')) {
    function get_flash(): ?array {
        if (!isset($_SESSION['flash'])) return null;
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
}

/* ---------------------------
   Formatting helpers
---------------------------- */
if (!function_exists('money')) {
    function money($amount): string {
        return number_format((float)$amount, 2);
    }
}
if (!function_exists('today')) {
    function today(): string {
        return date('Y-m-d');
    }
}

/* ---------------------------
   Khmer + Bootstrap UI Layout
---------------------------- */
if (!function_exists('fin_head')) {
    function fin_head(string $title = ''): void {
        $app = defined('FIN_APP_NAME') ? FIN_APP_NAME : 'ប្រព័ន្ធគ្រប់គ្រងបញ្ចាំ និងកម្ចី';
        $pageTitle = $title ? ($title . ' | ' . $app) : $app;
        ?>
        <!doctype html>
        <html lang="km">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <title><?= h($pageTitle) ?></title>

          <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
          <link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">

          <style>
            :root{ --primary:#4338ca; --text:#111827; --muted:#6b7280; --bg:#f3f4f6; }
            body{ font-family:"Kantumruy Pro", system-ui, -apple-system, "Segoe UI", Arial; background:var(--bg); color:var(--text); }
            .card{ border:0; border-radius:16px; box-shadow:0 10px 25px rgba(0,0,0,.06); }
            .btn-primary{ background:var(--primary); border-color:var(--primary); }
            .page-title{ font-weight:800; letter-spacing:.2px; }
            .help-text{ color:var(--muted); font-size:.92rem; }
            .required:after{ content:" *"; color:#dc2626; }
            .form-label{ font-weight:600; }
            .badge-soft{ background:#eef2ff; color:#3730a3; }
            .table thead th{ color:var(--muted); font-weight:700; }
          </style>
        </head>
        <body>
        <?php
    }
}

if (!function_exists('fin_foot')) {
    function fin_foot(): void {
        ?>
          <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        </body>
        </html>
        <?php
    }
}

if (!function_exists('flash_block')) {
    function flash_block(): void {
        $f = get_flash();
        if (!$f) return;

        $type = $f['type'] ?? 'info';
        $msg  = $f['message'] ?? '';

        // Map to Bootstrap alert types
        $bs = 'info';
        if ($type === 'success') $bs = 'success';
        if ($type === 'error')   $bs = 'danger';
        if ($type === 'warning') $bs = 'warning';

        echo '<div class="alert alert-' . h($bs) . ' mb-3" role="alert">' . h($msg) . '</div>';
    }
}

if (!function_exists('fin_bulk_calculate_ratings')) {
    function fin_bulk_calculate_ratings(PDO $pdo, array $customer_ids): array {
        if (empty($customer_ids)) return [];

        $ratings = [];
        foreach ($customer_ids as $id) {
            $ratings[$id] = [
                'rating' => 'none',
                'text' => 'គ្មានប្រវត្តិ',
                'badge' => 'bg-secondary-subtle text-secondary',
                'desc' => 'មិនទាន់មានប្រវត្តិកម្ចី'
            ];
        }

        $placeholders = implode(',', array_fill(0, count($customer_ids), '?'));

        // 1. Fetch Loans
        $sqlLoans = "SELECT id, customer_id, status, status_detail FROM loans WHERE customer_id IN ($placeholders)";
        $st = $pdo->prepare($sqlLoans);
        $st->execute($customer_ids);
        $loans = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Group loans by customer
        $loansByCustomer = [];
        foreach ($loans as $l) {
            $loansByCustomer[$l['customer_id']][] = $l;
        }

        // 2. Fetch Schedules
        $sqlSched = "SELECT id, loan_id, due_date, total_due, paid_total, status 
                     FROM loan_schedules 
                     WHERE loan_id IN (
                         SELECT id FROM loans WHERE customer_id IN ($placeholders)
                     )
                     ORDER BY due_date ASC, id ASC";
        $st = $pdo->prepare($sqlSched);
        $st->execute($customer_ids);
        $schedules = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Group schedules by loan
        $schedByLoan = [];
        foreach ($schedules as $s) {
            $schedByLoan[$s['loan_id']][] = $s;
        }

        // 3. Fetch Payments
        $sqlPayments = "SELECT id, loan_id, pay_date, amount 
                        FROM loan_payments 
                        WHERE loan_id IN (
                            SELECT id FROM loans WHERE customer_id IN ($placeholders)
                        )
                        ORDER BY pay_date ASC, id ASC";
        $st = $pdo->prepare($sqlPayments);
        $st->execute($customer_ids);
        $payments = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Group payments by loan
        $payByLoan = [];
        foreach ($payments as $p) {
            $payByLoan[$p['loan_id']][] = $p;
        }

        $today = date('Y-m-d');

        foreach ($customer_ids as $cid) {
            $custLoans = $loansByCustomer[$cid] ?? [];
            if (empty($custLoans)) {
                continue; // Keep 'none'
            }

            $isBad = false;
            $isMedium = false;
            $badReason = '';
            $mediumReason = '';

            // Check Loan Status Detail
            foreach ($custLoans as $l) {
                $sd = trim((string)$l['status_detail']);
                if (in_array($sd, ['អត់បង់សោះ', 'កាត់ចោល', 'ទាក់ទងមិនបាន'], true)) {
                    $isBad = true;
                    $badReason = "មានកម្ចីដែលមានស្ថានភាព '$sd'";
                    break;
                }
                if (in_array($sd, ['បន្តបង់ខ្លះៗ', 'បន្តបង់តាមលទ្ធភាព'], true)) {
                    $isMedium = true;
                    $mediumReason = "មានកម្ចីដែលមានស្ថានភាព '$sd'";
                }
            }

            if (!$isBad) {
                // Check Schedules & Payments
                foreach ($custLoans as $l) {
                    $lid = $l['id'];
                    $lScheds = $schedByLoan[$lid] ?? [];
                    $lPays = $payByLoan[$lid] ?? [];

                    // A. Check for currently overdue schedules
                    foreach ($lScheds as $s) {
                        $rem = (float)$s['total_due'] - (float)$s['paid_total'];
                        if ($rem > 0.005) {
                            if ($s['due_date'] < $today) {
                                $daysLate = round((strtotime($today) - strtotime($s['due_date'])) / 86400);
                                if ($daysLate > 15) {
                                    $isBad = true;
                                    $badReason = "មានការយឺតយ៉ាវជាង ១៥ថ្ងៃ (ហួសកំណត់ {$daysLate} ថ្ងៃ)";
                                    break 2;
                                } else {
                                    $isMedium = true;
                                    $mediumReason = "កំពុងយឺតយ៉ាវក្រោម ១៥ថ្ងៃ (ហួសកំណត់ {$daysLate} ថ្ងៃ)";
                                }
                            }
                        }
                    }

                    // B. Run FIFO simulation to detect historical late payments
                    // Sort schedules by due_date
                    usort($lScheds, function($a, $b) {
                        return strcmp($a['due_date'], $b['due_date']);
                    });

                    // Sort payments by pay_date
                    usort($lPays, function($a, $b) {
                        return strcmp($a['pay_date'], $b['pay_date']);
                    });

                    // Apply payments FIFO
                    $schedBalances = [];
                    foreach ($lScheds as $s) {
                        $schedBalances[$s['id']] = [
                            'due_date' => $s['due_date'],
                            'total_due' => (float)$s['total_due'],
                            'paid' => 0.0
                        ];
                    }

                    foreach ($lPays as $p) {
                        $payAmt = (float)$p['amount'];
                        $payDate = $p['pay_date'];

                        foreach ($schedBalances as $sid => &$sb) {
                            $rem = $sb['total_due'] - $sb['paid'];
                            if ($rem <= 0.005) continue;

                            $allocated = min($rem, $payAmt);
                            $sb['paid'] += $allocated;
                            $payAmt -= $allocated;

                            // Check if this payment fully paid the schedule
                            if ($sb['total_due'] - $sb['paid'] <= 0.005) {
                                if ($payDate > $sb['due_date']) {
                                    $isMedium = true;
                                    $mediumReason = "ធ្លាប់បង់យឺតយ៉ាវ (បង់យឺតនៅថ្ងៃ {$payDate} លើកាលកំណត់ {$sb['due_date']})";
                                }
                            }

                            if ($payAmt <= 0.005) break;
                        }
                        unset($sb);
                    }
                }
            }

            // Check if all loans of this customer are paid off / closed
            $allPaidOff = !empty($custLoans);
            foreach ($custLoans as $l) {
                $stU = strtoupper(trim((string)($l['status'] ?? '')));
                $sdU = trim((string)($l['status_detail'] ?? ''));
                $isLoanPaid = ($stU === 'CLOSED' || $sdU === 'បង់ផ្តាច់រួច' || $sdU === 'paid_off');
                if (!$isLoanPaid) {
                    $lScheds = $schedByLoan[$l['id']] ?? [];
                    if (!empty($lScheds)) {
                        $remTotal = 0.0;
                        foreach ($lScheds as $s) {
                            $remTotal += max(0, (float)$s['total_due'] - (float)$s['paid_total']);
                        }
                        if ($remTotal <= 0.005) {
                            $isLoanPaid = true;
                        }
                    }
                }
                if (!$isLoanPaid) {
                    $allPaidOff = false;
                    break;
                }
            }

            if ($isBad) {
                $ratings[$cid] = [
                    'rating' => 'bad',
                    'text' => 'មិនល្អ',
                    'badge' => 'bg-danger-subtle text-danger',
                    'desc' => $badReason
                ];
            } elseif ($allPaidOff) {
                $ratings[$cid] = [
                    'rating' => 'paid_off',
                    'text' => 'បង់ផ្តាច់រួច',
                    'badge' => 'badge-paid-off',
                    'desc' => 'បានបង់ផ្តាច់កម្ចីរួចរាល់ទាំងអស់'
                ];
            } elseif ($isMedium) {
                $ratings[$cid] = [
                    'rating' => 'medium',
                    'text' => 'មធ្យម',
                    'badge' => 'bg-warning-subtle text-warning-emphasis',
                    'desc' => $mediumReason
                ];
            } else {
                $ratings[$cid] = [
                    'rating' => 'good',
                    'text' => 'ល្អណាស់',
                    'badge' => 'bg-success-subtle text-success',
                    'desc' => 'បង់ប្រាក់ទៀងទាត់ និងគ្មានប្រវត្តិយឺតយ៉ាវ'
                ];
            }
        }

        return $ratings;
    }
}

if (!function_exists('fin_calculate_customer_rating')) {
    function fin_calculate_customer_rating(PDO $pdo, int $customer_id): array {
        $ratings = fin_bulk_calculate_ratings($pdo, [$customer_id]);
        return $ratings[$customer_id] ?? [
            'rating' => 'none',
            'text' => 'គ្មានប្រវត្តិ',
            'badge' => 'bg-secondary-subtle text-secondary',
            'desc' => 'មិនទាន់មានប្រវត្តិកម្ចី'
        ];
    }
}

/**
 * A robust helper to make HTTP requests (GET/POST), falling back to file_get_contents if curl is not installed.
 */
if (!function_exists('fin_http_request')) {
    function fin_http_request(string $url, string $method = 'GET', array $headers = [], ?string $payload = null, int $timeout = 10): array {
        $method = strtoupper($method);
        $curlErr = null;
        
        // 1. Try cURL first if available
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            
            if ($method === 'POST') {
                if ($payload !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                }
            }
            
            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }
            
            $result = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            
            if (!$err) {
                return ['ok' => true, 'body' => $result];
            }
            $curlErr = $err;
        }
        
        // 2. Fallback to file_get_contents with stream context
        $headerStr = '';
        foreach ($headers as $h) {
            $headerStr .= $h . "\r\n";
        }
        
        $options = [
            'http' => [
                'method'  => $method,
                'header'  => $headerStr,
                'timeout' => $timeout,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ];
        
        if ($method === 'POST' && $payload !== null) {
            $options['http']['content'] = $payload;
        }
        
        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
        
        if ($result === false) {
            $errMsg = 'Connection failed or timed out (stream error).';
            if ($curlErr !== null) {
                $errMsg .= " (cURL error: $curlErr)";
            }
            return ['ok' => false, 'error' => $errMsg];
        }
        
        return ['ok' => true, 'body' => $result];
    }
}


