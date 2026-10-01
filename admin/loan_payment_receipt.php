<?php
// /finance/admin/loan_payment_receipt.php
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

fin_require_login();
fin_require_permission('view_payment_receipt');
$business_id = fin_require_business();
global $pdo;

/* ---------------- helpers ---------------- */
function h2($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

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

function fin_first_existing_col(PDO $pdo, string $table, array $candidates, string $fallback=''): string {
    foreach ($candidates as $c) {
        if (fin_col_exists($pdo, $table, $c)) return $c;
    }
    return $fallback;
}

/* ---------------- detect columns ---------------- */
$payBizCol       = fin_col_exists($pdo,'loan_payments','business_id') ? 'business_id' : (fin_col_exists($pdo,'loan_payments','biz_id') ? 'biz_id' : 'business_id');
$loansBizCol     = fin_col_exists($pdo,'loans','business_id') ? 'business_id' : 'biz_id';
$customersBizCol = fin_col_exists($pdo,'customers','business_id') ? 'business_id' : 'biz_id';
$schedBizCol     = fin_col_exists($pdo,'loan_schedules','business_id') ? 'business_id' : 'biz_id';

$loanHasCode       = fin_col_exists($pdo,'loans','loan_code');
$loanHasStartDate  = fin_col_exists($pdo,'loans','start_date');
$loanHasDueDate    = fin_col_exists($pdo,'loans','due_date');
$loanHasEndDateOld = fin_col_exists($pdo,'loans','end_date');

$loanHasPrincipalAmount = fin_col_exists($pdo,'loans','principal_amount');
$loanHasPrincipalOld    = fin_col_exists($pdo,'loans','principal');
$loanHasMethod          = fin_col_exists($pdo,'loans','interest_method');
$loanHasTypeOld         = fin_col_exists($pdo,'loans','interest_type');
$loanHasStatus          = fin_col_exists($pdo,'loans','status');
$loanHasTermMonths      = fin_col_exists($pdo,'loans','term_months');
$loanHasPurpose         = fin_col_exists($pdo,'loans','purpose');
$loanHasCollateral      = fin_col_exists($pdo,'loans','collateral');
$loanHasNote            = fin_col_exists($pdo,'loans','note');

$payAmountCol = fin_first_existing_col($pdo,'loan_payments',['amount','paid_amount','pay_amount'],'amount');
$payDateCol   = fin_first_existing_col($pdo,'loan_payments',['pay_date','payment_date','paid_date','created_at'],'pay_date');
$payMethodCol = fin_first_existing_col($pdo,'loan_payments',['method','payment_method','channel'],'method');
$payNoteCol   = fin_first_existing_col($pdo,'loan_payments',['note','remark','description'],'note');

$bizNameCol   = fin_first_existing_col($pdo,'businesses',['name','business_name'],'name');

/* ---------------- read payment id ---------------- */
$payment_id = (int)($_GET['id'] ?? 0);
if ($payment_id <= 0) {
    http_response_code(400);
    echo "<h3 style='font-family:Battambang,sans-serif'>400</h3><p style='font-family:Battambang,sans-serif'>បាត់លេខបង្កាន់ដៃ។</p>";
    exit;
}

/* ---------------- fetch payment + loan + customer + business (secure by business) ---------------- */
$codeExpr      = $loanHasCode ? "l.loan_code" : "''";
$principalExpr = $loanHasPrincipalAmount ? "l.principal_amount" : ($loanHasPrincipalOld ? "l.principal" : "0");
$startExpr     = $loanHasStartDate ? "l.start_date" : "NULL";
$dueExpr       = $loanHasDueDate ? "l.due_date" : ($loanHasEndDateOld ? "l.end_date" : "NULL");
$methodExpr    = $loanHasMethod ? "l.interest_method" : ($loanHasTypeOld ? "l.interest_type" : "''");
$statusExpr    = $loanHasStatus ? "l.status" : "''";
$loanHasCcy    = fin_first_existing_col($pdo, 'loans', ['currency_code','currency','ccy'], '');
$ccyExpr       = $loanHasCcy ? "l.`{$loanHasCcy}`" : "'USD'";

$cashierColExists = fin_col_exists($pdo, 'loan_payments', 'created_by');

$sql = "
    SELECT
      p.id                         AS payment_id,
      p.loan_id                    AS loan_id,
      p.`{$payDateCol}`            AS pay_date,
      p.`{$payAmountCol}`          AS amount,
      " . (fin_col_exists($pdo,'loan_payments',$payMethodCol) ? "p.`{$payMethodCol}`" : "''") . " AS method,
      " . (fin_col_exists($pdo,'loan_payments',$payNoteCol) ? "p.`{$payNoteCol}`" : "''") . "     AS note,

      b.`{$bizNameCol}`            AS business_name,
      b.phone                      AS business_phone,
      b.address                    AS business_address,

      c.full_name                  AS customer_name,
      c.phone                      AS customer_phone,
      c.address                    AS customer_address,

      {$codeExpr}                  AS loan_code,
      {$ccyExpr}                   AS currency_code,
      {$principalExpr}             AS principal,
      {$startExpr}                 AS start_date,
      {$dueExpr}                   AS due_date,
      {$methodExpr}                AS interest_method,
      {$statusExpr}                AS loan_status,
      " . ($loanHasTermMonths ? "l.term_months" : "NULL") . " AS term_months,
      " . ($loanHasPurpose ? "l.purpose" : "NULL") . "      AS purpose,
      " . ($loanHasCollateral ? "l.collateral" : "NULL") . " AS collateral,
      " . ($cashierColExists ? "u.full_name" : "''") . " AS cashier_name
    FROM loan_payments p
    JOIN loans l      ON l.id = p.loan_id
    JOIN customers c  ON c.id = l.customer_id
    JOIN businesses b ON b.id = l.{$loansBizCol}
    LEFT JOIN users u ON " . ($cashierColExists ? "u.id = p.created_by" : "1=0") . "
    WHERE p.id = ?
      AND p.{$payBizCol} = ?
      AND l.{$loansBizCol} = ?
      AND c.{$customersBizCol} = ?
    LIMIT 1
";
$st = $pdo->prepare($sql);
$st->execute([$payment_id, $business_id, $business_id, $business_id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo "<h3 style='font-family:Kantumruy Pro,sans-serif'>404</h3><p style='font-family:Kantumruy Pro,sans-serif'>រកមិនឃើញបង្កាន់ដៃនេះទេ។</p>";
    exit;
}

/* ---------------- totals from schedules (for that loan) ---------------- */
$loan_id = (int)$row['loan_id'];

$stT = $pdo->prepare("
    SELECT
      COALESCE(SUM(total_due),0) AS total_due,
      COALESCE(SUM(COALESCE(paid_total,0)),0) AS total_paid
    FROM loan_schedules
    WHERE loan_id = ?
      AND {$schedBizCol} = ?
");
$stT->execute([$loan_id, $business_id]);
$t = $stT->fetch(PDO::FETCH_ASSOC) ?: ['total_due'=>0,'total_paid'=>0];

$total_due  = (float)($t['total_due'] ?? 0);
$total_paid = (float)($t['total_paid'] ?? 0);
$outstanding = max(0, $total_due - $total_paid);

$business_name = (string)($row['business_name'] ?? 'Business');
$customer_name = (string)($row['customer_name'] ?? '');
$loan_code     = (string)($row['loan_code'] ?? '');
$pay_date      = (string)($row['pay_date'] ?? '');
$amount        = (float)($row['amount'] ?? 0);
$method        = (string)($row['method'] ?? '');
$note          = (string)($row['note'] ?? '');
$principal     = (float)($row['principal'] ?? 0);
$start_date    = (string)($row['start_date'] ?? '');
$due_date      = (string)($row['due_date'] ?? '');
$cashier_name  = (string)($row['cashier_name'] ?? 'Administrator');

/* ---------------- fetch business logo ---------------- */
$logo_path = '';
try {
    $stLogo = $pdo->prepare("SELECT logo_path FROM business_settings WHERE business_id = ? LIMIT 1");
    $stLogo->execute([$business_id]);
    $bs_logo = $stLogo->fetchColumn();
    if (!empty($bs_logo)) {
        $logo_path = '../' . ltrim($bs_logo, '/');
    }
} catch (Throwable $e) { /* ignore */ }
?>
<?php
/* ---------------- Currency Code Helper Functions ---------------- */
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

/* ---------------- Normalize & Get Currency ---------------- */
$ccyCol = fin_col_exists($pdo,'loans','currency_code') ? 'currency_code' : (fin_col_exists($pdo,'loans','currency') ? 'currency' : 'ccy');
$ccy = fin_ccy_norm($row[$ccyCol] ?? 'USD');

/* ---------------- Detect If This Is A Payoff Receipt ---------------- */
$loan_status = strtoupper(trim((string)($row['loan_status'] ?? '')));
$note_text   = (string)($row['note'] ?? '');
$isPayoff    = ($outstanding <= 0.00001) || ($loan_status === 'CLOSED') || (mb_strpos($note_text, 'បង់ផ្តាច់') !== false);
?>
<!doctype html>
<html lang="km">
<head>
  <meta charset="utf-8">
  <title><?= $isPayoff ? 'វិក័យបត្របង់ផ្តាច់ឥណទាន' : 'បង្កាន់ដៃទទួលប្រាក់' ?> #<?= str_pad((string)$payment_id, 6, '0', STR_PAD_LEFT) ?> | Finance</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script>
    window.onerror = function(message, source, lineno, colno, error) {
      alert("JS Error: " + message + " at " + source + ":" + lineno + ":" + colno);
      return false;
    };
    window.addEventListener('unhandledrejection', function(event) {
      alert("Unhandled Promise Rejection: " + event.reason);
    });
  </script>
  <script>
  async function copyToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      await navigator.clipboard.writeText(text);
      alert('តំណភ្ជាប់ត្រូវបានចម្លងទុក! (Link copied to clipboard!)');
    } else {
      const dummy = document.createElement('input');
      document.body.appendChild(dummy);
      dummy.value = text;
      dummy.select();
      const success = document.execCommand('copy');
      document.body.removeChild(dummy);
      if (success) {
        alert('តំណភ្ជាប់ត្រូវបានចម្លងទុក! (Link copied to clipboard!)');
      } else {
        throw new Error('execCommand copy failed');
      }
    }
  }

  function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    alert('រូបភាពត្រូវបានទាញយក! (Receipt image downloaded!)');
  }

  async function shareLinkOnly() {
    const shareData = {
      title: <?= json_encode('បង្កាន់ដៃទទួលប្រាក់ #' . str_pad((string)$payment_id, 6, "0", STR_PAD_LEFT)) ?>,
      text: <?= json_encode('បង្កាន់ដៃទូទាត់ប្រាក់ពី ' . $business_name) ?>,
      url: window.location.href
    };
    try {
      if (navigator.share) {
        await navigator.share(shareData);
      } else {
        await copyToClipboard(window.location.href);
      }
    } catch (err) {
      console.log('Share link error:', err);
      if (err.name !== 'AbortError') {
        try {
          await copyToClipboard(window.location.href);
        } catch (copyErr) {
          alert('មិនអាចចែករំលែកបានទេ! (Sharing failed!)');
        }
      }
    }
  }

  async function shareReceipt() {
    if (typeof html2canvas === 'undefined') {
      alert('បណ្ណាល័យបង្កើតរូបភាពមិនទាន់ទាញយកបានទេ។ សូមពិនិត្យការតភ្ជាប់អ៊ីនធឺណិតរបស់អ្នក! (Image library is not loaded yet. Please check your internet connection.)');
      return;
    }

    const element = document.querySelector('.receipt-card');
    const actions = document.querySelector('.actions');
    const wrap = document.querySelector('.receipt-wrap');

    // Hide actions panel during capture
    if (actions) actions.style.setProperty('display', 'none', 'important');
    
    // Force desktop mode for capture
    document.body.classList.add('pdf-generating');
    const originalWidth = wrap.style.width;
    const originalMaxWidth = wrap.style.maxWidth;
    wrap.style.setProperty('width', '840px', 'important');
    wrap.style.setProperty('max-width', '840px', 'important');

    // Save scroll position and scroll to top
    const scrollY = window.scrollY;
    const scrollX = window.scrollX;
    window.scrollTo(0, 0);

    // Wait for styling to apply and paint (600ms for mobile devices)
    await new Promise(resolve => setTimeout(resolve, 600));

    try {
      const html2canvasOpts = { 
        scale: 2.2, 
        useCORS: true, 
        logging: false,
        windowWidth: 840,
        windowHeight: element.offsetHeight + 200,
        scrollX: 0,
        scrollY: 0
      };

      const canvas = await html2canvas(element, html2canvasOpts);

      // Restore layout & scroll
      if (actions) actions.style.removeProperty('display');
      document.body.classList.remove('pdf-generating');
      wrap.style.width = originalWidth;
      wrap.style.maxWidth = originalMaxWidth;
      window.scrollTo(scrollX, scrollY);

      canvas.toBlob(async (blob) => {
        if (!blob) {
          alert('មិនអាចបង្កើតរូបភាពបានទេ! (Failed to generate image!)');
          return;
        }

        const filename = <?= json_encode(($isPayoff ? 'payoff_receipt_' : 'receipt_') . str_pad((string)$payment_id, 6, "0", STR_PAD_LEFT) . '.jpg') ?>;
        
        // Tier 1: Try Native sharing (ideal for mobile chat apps)
        const file = new File([blob], filename, { type: 'image/jpeg' });
        if (navigator.share && navigator.canShare && navigator.canShare({ files: [file] })) {
          try {
            await navigator.share({
              files: [file],
              title: <?= json_encode(($isPayoff ? 'វិក័យបត្របង់ផ្តាច់ឥណទាន #' : 'បង្កាន់ដៃទទួលប្រាក់ #') . str_pad((string)$payment_id, 6, "0", STR_PAD_LEFT)) ?>,
              text: <?= json_encode(($isPayoff ? 'វិក័យបត្របង់ផ្តាច់ឥណទានពី ' : 'បង្កាន់ដៃទូទាត់ប្រាក់ពី ') . $business_name) ?>
            });
            return;
          } catch (shareErr) {
            console.error('Share file error:', shareErr);
            if (shareErr.name === 'AbortError') {
              return; // User cancelled
            }
          }
        }

        // Tier 2: Try copying image to Clipboard (ideal for PC chat apps)
        if (navigator.clipboard && window.ClipboardItem) {
          try {
            canvas.toBlob(async (pngBlob) => {
              if (pngBlob) {
                try {
                  await navigator.clipboard.write([
                    new ClipboardItem({
                      [pngBlob.type]: pngBlob
                    })
                  ]);
                  alert('ចម្លងរូបភាពបង្កាន់ដៃទៅ Clipboard រួចរាល់! អ្នកអាចបិទភ្ជាប់ (Paste/Ctrl+V) ដើម្បីផ្ញើបាន។ (Receipt image copied to clipboard! You can paste to send it.)');
                } catch (clipErr) {
                  console.error('Clipboard write error:', clipErr);
                  downloadBlob(blob, filename);
                }
              } else {
                downloadBlob(blob, filename);
              }
            }, 'image/png');
            return;
          } catch (clipApiErr) {
            console.error('Clipboard API error:', clipApiErr);
          }
        }

        // Tier 3: Ultimate Fallback to Direct Download
        downloadBlob(blob, filename);
      }, 'image/jpeg', 0.95);

    } catch (err) {
      console.error('Capture error:', err);
      // Restore layout
      if (actions) actions.style.removeProperty('display');
      document.body.classList.remove('pdf-generating');
      wrap.style.width = originalWidth;
      wrap.style.maxWidth = originalMaxWidth;
      window.scrollTo(scrollX, scrollY);
      await shareLinkOnly();
    }
  }

  function getJsPDFCtor() {
    if (window.jspdf && window.jspdf.jsPDF) {
      return window.jspdf.jsPDF;
    }
    if (typeof window.jsPDF !== 'undefined') {
      return window.jsPDF;
    }
    return null;
  }

  function downloadPDF() {
    if (typeof html2canvas === 'undefined') {
      alert('បណ្ណាល័យបង្កើតរូបភាពមិនទាន់ទាញយកបានទេ។ សូមពិនិត្យការតភ្ជាប់អ៊ីនធឺណិតរបស់អ្នក! (Image library is not loaded yet. Please check your internet connection.)');
      return;
    }

    const element = document.querySelector('.receipt-card');
    const actions = document.querySelector('.actions');
    const wrap = document.querySelector('.receipt-wrap');
    
    // Hide actions panel during PDF creation
    if (actions) actions.style.setProperty('display', 'none', 'important');
    
    // Force desktop mode for PDF generation
    document.body.classList.add('pdf-generating');
    const originalWidth = wrap.style.width;
    const originalMaxWidth = wrap.style.maxWidth;
    wrap.style.setProperty('width', '840px', 'important');
    wrap.style.setProperty('max-width', '840px', 'important');

    // Save scroll position and scroll to top
    const scrollY = window.scrollY;
    const scrollX = window.scrollX;
    window.scrollTo(0, 0);

    // Wait 600ms for layout reflow and paint (especially on mobile)
    setTimeout(() => {
      const html2canvasOpts = { 
        scale: 2.2, 
        useCORS: true, 
        logging: false,
        windowWidth: 840,
        windowHeight: element.offsetHeight + 200,
        scrollX: 0,
        scrollY: 0
      };

      const filename = <?= json_encode(($isPayoff ? 'payoff_receipt_' : 'receipt_') . str_pad((string)$payment_id, 6, "0", STR_PAD_LEFT) . '.pdf') ?>;

      try {
        html2canvas(element, html2canvasOpts).then(canvas => {
          const jsPDFCtor = getJsPDFCtor();
          if (!jsPDFCtor) {
            throw new Error('jsPDF is not defined');
          }

          const imgData = canvas.toDataURL('image/jpeg', 0.96);
          const pdf = new jsPDFCtor('p', 'mm', 'a4');
          
          const pageW = pdf.internal.pageSize.getWidth();
          const pageH = pdf.internal.pageSize.getHeight();
          const margin = 6;
          const usableW = pageW - (margin * 2);
          const usableH = pageH - (margin * 2);
          
          let renderW = usableW;
          let renderH = (canvas.height * usableW) / canvas.width;
          
          if (renderH > usableH) {
            renderH = usableH;
            renderW = (canvas.width * usableH) / canvas.height;
          }
          
          const xOffset = margin + (usableW - renderW) / 2;
          const yOffset = margin + (usableH - renderH) / 2;
          
          pdf.addImage(imgData, 'JPEG', xOffset, yOffset, renderW, renderH, undefined, 'FAST');
          const pdfBlob = pdf.output('blob');
          const file = new File([pdfBlob], filename, { type: 'application/pdf' });

          // Restore UI
          if (actions) actions.style.removeProperty('display');
          document.body.classList.remove('pdf-generating');
          wrap.style.width = originalWidth;
          wrap.style.maxWidth = originalMaxWidth;
          window.scrollTo(scrollX, scrollY); // restore scroll

          // Check if native sharing supports PDF files (great for mobile)
          if (navigator.share && navigator.canShare && navigator.canShare({ files: [file] })) {
            try {
              navigator.share({
                files: [file],
                title: <?= json_encode(($isPayoff ? 'វិក័យបត្របង់ផ្តាច់ឥណទាន #' : 'បង្កាន់ដៃទទួលប្រាក់ #') . str_pad((string)$payment_id, 6, "0", STR_PAD_LEFT)) ?>,
                text: <?= json_encode(($isPayoff ? 'វិក័យបត្របង់ផ្តាច់ឥណទានពី ' : 'បង្កាន់ដៃទូទាត់ប្រាក់ពី ') . $business_name) ?>
              });
              return;
            } catch (shareErr) {
              console.error('Share PDF error:', shareErr);
              if (shareErr.name === 'AbortError') {
                return; // User cancelled
              }
            }
          }

          // Fallback: download directly
          pdf.save(filename);
        }).catch((err) => {
          console.error('PDF generation error:', err);
          if (actions) actions.style.removeProperty('display');
          document.body.classList.remove('pdf-generating');
          wrap.style.width = originalWidth;
          wrap.style.maxWidth = originalMaxWidth;
          window.scrollTo(scrollX, scrollY); // restore scroll
          alert('មានបញ្ហាក្នុងការបង្កើត PDF! (Failed to generate PDF!)');
        });
      } catch (err) {
        console.error('PDF sync error:', err);
        if (actions) actions.style.removeProperty('display');
        document.body.classList.remove('pdf-generating');
        wrap.style.width = originalWidth;
        wrap.style.maxWidth = originalMaxWidth;
        window.scrollTo(scrollX, scrollY); // restore scroll
        alert('មានបញ្ហាក្នុងការបង្កើត PDF! (Failed to generate PDF!)');
      }
    }, 600);
  }
  </script>

  <!-- Google Fonts: Battambang, Kantumruy Pro & Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Battambang:wght@300;400;700;900&family=Inter:wght@300;400;500;600;700;800&family=Kantumruy+Pro:ital,wght@0,300..700;1,300..700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

  <style>
    :root {
      --bg: #f1f5f9;
      --card: #ffffff;
      --ink: #0f172a;
      --muted: #64748b;
      --primary: #1e3a8a;
      --primary-subtle: #eff6ff;
      --line: #cbd5e1;
      --success: #15803d;
      --success-subtle: #dcfce7;
      --danger: #b91c1c;
      --font-family: 'Battambang', 'Kantumruy Pro', 'Inter', 'Noto Sans Khmer', sans-serif;
    }
    body {
      background: radial-gradient(circle at top, #f8fafc 0%, #e2e8f0 100%);
      font-family: var(--font-family);
      color: var(--ink);
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
      min-height: 100vh;
      display: flex;
      align-items: center;
      padding: 40px 0;
    }
    .receipt-wrap {
      max-width: 840px;
      width: 100%;
      margin: 0 auto;
      padding: 0 24px;
    }
    .receipt-card {
      background: var(--card);
      border-radius: 20px;
      box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.08);
      border: 1px solid rgba(15, 23, 42, 0.05);
      padding: 40px;
      position: relative;
      overflow: hidden;
    }
    .receipt-card::before {
      content: "";
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 6px;
      background: linear-gradient(90deg, #1e3a8a, #3b82f6, #10b981);
    }
    
    .biz-logo-container {
      width: 72px;
      height: 72px;
      border-radius: 16px;
      background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 2rem;
      box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
    }
    
    .biz-title {
      font-weight: 700;
      font-size: 1.55rem;
      margin: 0;
      color: var(--primary);
    }
    .biz-info {
      font-size: 0.8rem;
      color: var(--muted);
      margin-top: 5px;
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .biz-info span {
      display: flex;
      align-items: center;
      gap: 5px;
    }
    .biz-info i {
      color: var(--primary);
    }
    
    .receipt-badge {
      display: inline-block;
      padding: 6px 14px;
      border-radius: 30px;
      background: var(--primary-subtle);
      color: var(--primary);
      font-weight: 700;
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .receipt-no {
      font-size: 1.8rem;
      font-weight: 800;
      color: var(--ink);
      margin-top: 4px;
      font-family: 'Inter', sans-serif;
    }
    
    .receipt-divider {
      position: relative;
      height: 0;
      border-top: 2px dashed var(--line);
      margin: 35px -40px;
    }
    .receipt-divider::before,
    .receipt-divider::after {
      content: "";
      position: absolute;
      top: -12px;
      width: 24px;
      height: 24px;
      background-color: var(--bg);
      border-radius: 50%;
    }
    .receipt-divider::before { left: -12px; }
    .receipt-divider::after { right: -12px; }
    
    .amount-highlight {
      background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
      border: 1px solid #bbf7d0;
      border-radius: 16px;
      padding: 30px 24px;
      text-align: center;
      height: 100%;
      display: flex;
      flex-direction: column;
      justify-content: center;
      box-shadow: 0 4px 10px rgba(22, 101, 52, 0.03);
    }
    .amount-title {
      font-size: 0.8rem;
      font-weight: 700;
      color: var(--success);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 6px;
    }
    .amount-num {
      font-size: 2.8rem;
      font-weight: 800;
      color: #166534;
      font-family: 'Inter', sans-serif;
      line-height: 1.1;
      font-variant-numeric: tabular-nums;
    }
    .amount-status {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      margin-top: 12px;
      background-color: #bbf7d0;
      color: #166534;
      padding: 6px 16px;
      border-radius: 9999px;
      font-size: 0.8rem;
      font-weight: 700;
      width: fit-content;
      margin-left: auto;
      margin-right: auto;
    }

    .info-card {
      border-radius: 12px;
      border: 1px solid #f1f5f9;
      background-color: #f8fafc;
      padding: 20px;
    }

    .font-mono {
      font-family: 'Inter', sans-serif;
    }
    
    .signature-section {
      margin-top: 50px;
    }
    .signature-box {
      text-align: center;
    }
    .signature-title {
      font-size: 0.8rem;
      font-weight: 700;
      color: var(--muted);
      margin-bottom: 70px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .signature-line {
      width: 75%;
      margin: 0 auto 8px auto;
      border-bottom: 1px dashed var(--muted);
      opacity: 0.5;
    }

    .footer-note {
      text-align: center;
      font-size: 0.75rem;
      color: var(--muted);
      margin-top: 40px;
      padding-top: 20px;
      border-top: 1px solid #e2e8f0;
    }
    
    .actions {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 12px;
      justify-content: flex-end;
      padding: 20px 40px;
      background: #f8fafc;
      border-top: 1px solid #e2e8f0;
      margin: 40px -40px -40px -40px;
    }

    @media (max-width: 576px) {
      body:not(.pdf-generating) {
        padding: 10px 0;
      }
      body:not(.pdf-generating) .receipt-wrap {
        padding: 0 8px;
      }
      body:not(.pdf-generating) .receipt-card {
        padding: 20px;
      }
      body:not(.pdf-generating) .receipt-divider {
        margin: 25px -20px;
      }
      body:not(.pdf-generating) .amount-num {
        font-size: 2rem;
      }
      body:not(.pdf-generating) .actions {
        margin: 25px -20px -20px -20px;
        padding: 15px 20px;
        display: flex !important;
        flex-direction: row !important;
        justify-content: space-between !important;
        gap: 8px !important;
      }
      body:not(.pdf-generating) .actions a, body:not(.pdf-generating) .actions button {
        flex: 1 1 0px !important;
        width: auto !important;
        justify-content: center !important;
        padding: 10px 0 !important;
        font-size: 1.25rem !important;
      }
      body:not(.pdf-generating) .signature-title {
        font-size: 0.72rem !important;
        margin-bottom: 50px !important;
      }
    }

    /* PDF Generation Helper overrides to force desktop layout */
    html.pdf-generating, body.pdf-generating {
      scroll-behavior: auto !important;
    }
    body.pdf-generating {
      display: block !important;
      min-height: auto !important;
      height: auto !important;
      padding: 0 !important;
      margin: 0 !important;
      overflow: visible !important;
      width: 840px !important;
      min-width: 840px !important;
    }
    body.pdf-generating .receipt-wrap {
      margin: 0 !important;
      padding: 0 !important;
      width: 840px !important;
      max-width: 840px !important;
    }
    body.pdf-generating .d-none.d-md-block {
      display: block !important;
    }
    body.pdf-generating .table-responsive.d-none.d-md-block {
      display: block !important;
    }
    body.pdf-generating .d-md-none {
      display: none !important;
    }
    body.pdf-generating .col-12.col-md-6 {
      flex: 0 0 auto !important;
      width: 50% !important;
    }
    body.pdf-generating .col-12.col-md-7 {
      flex: 0 0 auto !important;
      width: 58.33333333% !important;
    }
    body.pdf-generating .col-12.col-md-5 {
      flex: 0 0 auto !important;
      width: 41.66666667% !important;
    }
    body.pdf-generating .col-12.col-md-5.text-md-end {
      text-align: right !important;
    }
    body.pdf-generating .receipt-card {
      padding: 25px !important;
    }
    body.pdf-generating .receipt-divider {
      margin: 20px -25px !important;
    }
    body.pdf-generating .amount-highlight {
      padding: 20px 24px !important;
    }
    body.pdf-generating .signature-section {
      margin-top: 25px !important;
      margin-bottom: 25px !important;
    }
    body.pdf-generating .signature-title {
      margin-bottom: 45px !important;
    }
    
    /* PDF Generation layout & typography fixes */
    body.pdf-generating * {
      letter-spacing: normal !important;
    }
    body.pdf-generating table {
      border-collapse: separate !important;
      border-spacing: 0 !important;
      border: 1px solid #cbd5e1 !important;
    }
    body.pdf-generating th, body.pdf-generating td {
      border-top: none !important;
      border-left: none !important;
      border-bottom: 1px solid #cbd5e1 !important;
      border-right: 1px solid #cbd5e1 !important;
    }
    body.pdf-generating th:last-child, body.pdf-generating td:last-child {
      border-right: none !important;
    }
    body.pdf-generating tr:last-child td {
      border-bottom: none !important;
    }

    @media print {
      @page {
        size: A4 portrait;
        margin: 5mm 6mm;
      }
      html, body {
        background: #ffffff !important;
        padding: 0 !important;
        margin: 0 !important;
        display: block !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }
      .actions {
        display: none !important;
      }
      .table-responsive.d-none.d-md-block {
        display: block !important;
      }
      .d-md-none {
        display: none !important;
      }
      .col-12.col-md-6 {
        flex: 0 0 auto !important;
        width: 50% !important;
      }
      .col-12.col-md-7 {
        flex: 0 0 auto !important;
        width: 58.33333333% !important;
      }
      .col-12.col-md-5 {
        flex: 0 0 auto !important;
        width: 41.66666667% !important;
      }
      .col-12.col-md-5.text-md-end {
        text-align: right !important;
      }
      .receipt-wrap {
        max-width: 100% !important;
        width: 100% !important;
        padding: 0 !important;
        margin: 0 auto !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
        page-break-after: avoid !important;
        break-after: avoid !important;
      }
      .receipt-card {
        padding: 22px 28px !important;
        box-shadow: none !important;
        border: 1px solid #cbd5e1 !important;
        border-radius: 12px !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
      }
      .receipt-divider {
        margin: 12px -28px !important;
      }
      .payoff-banner {
        padding: 8px 14px !important;
        margin-bottom: 12px !important;
      }
      .payoff-banner i {
        font-size: 1.4rem !important;
      }
      .amount-highlight {
        padding: 12px 18px !important;
      }
      .amount-num {
        font-size: 1.95rem !important;
      }
      .amount-title {
        font-size: 0.8rem !important;
        margin-bottom: 3px !important;
      }
      .info-card {
        padding: 12px 18px !important;
      }
      .info-card h6 {
        margin-bottom: 8px !important;
        font-size: 0.78rem !important;
      }
      .info-card .mb-2 {
        margin-bottom: 6px !important;
      }
      .signature-section {
        margin-top: 28px !important;
        margin-bottom: 14px !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
      }
      .signature-title {
        margin-bottom: 52px !important;
        font-size: 0.78rem !important;
      }
      .footer-note {
        margin-top: 14px !important;
        padding-top: 8px !important;
        font-size: 0.73rem !important;
        line-height: 1.35 !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
        page-break-after: avoid !important;
        break-after: avoid !important;
      }
      
      /* Typography & Table border overrides to match PDF & Share image */
      * {
        letter-spacing: normal !important;
      }
      table {
        border-collapse: separate !important;
        border-spacing: 0 !important;
        border: 1px solid #cbd5e1 !important;
        margin-bottom: 10px !important;
      }
      th, td {
        padding: 7px 10px !important;
        border-top: none !important;
        border-left: none !important;
        border-bottom: 1px solid #cbd5e1 !important;
        border-right: 1px solid #cbd5e1 !important;
      }
      th:last-child, td:last-child {
        border-right: none !important;
      }
      tr:last-child td {
        border-bottom: none !important;
      }
      .row.g-4 {
        --bs-gutter-y: 0.85rem !important;
        --bs-gutter-x: 1.15rem !important;
      }
      .mb-4 {
        margin-bottom: 0.9rem !important;
      }
      .mb-3 {
        margin-bottom: 0.65rem !important;
      }
      .p-3 {
        padding: 0.75rem 1rem !important;
      }
    }
  </style>
  <!-- html2pdf.js (Local with CDN Fallback), html2canvas CDN and jsPDF UMD CDN -->
  <script src="js/html2pdf.bundle.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script>
    if (typeof html2pdf === 'undefined') {
      var script = document.createElement('script');
      script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
      document.head.appendChild(script);
    }
  </script>
</head>
<body>

<div class="receipt-wrap">
  <div class="receipt-card">

    <!-- Header Section -->
    <div class="row g-4 align-items-start">
      <div class="col-12 col-md-7">
        <div class="d-flex align-items-center gap-3">
          <div class="biz-logo-container" style="<?= !empty($logo_path) ? 'background: none; box-shadow: none;' : '' ?>">
            <?php if (!empty($logo_path)): ?>
              <img src="<?= h2($logo_path) ?>" alt="Logo" style="width: 100%; height: 100%; object-fit: cover; border-radius: 16px;">
            <?php else: ?>
              <i class="bi bi-shield-fill-check"></i>
            <?php endif; ?>
          </div>
          <div>
            <h1 class="biz-title"><?= h2($business_name) ?></h1>
            <div class="biz-info">
              <?php if (trim($row['business_address'] ?? '') !== ''): ?>
                <span><i class="bi bi-geo-alt-fill"></i><?= h2($row['business_address']) ?></span>
              <?php endif; ?>
              <?php if (trim($row['business_phone'] ?? '') !== ''): ?>
                <span><i class="bi bi-telephone-fill"></i><?= h2($row['business_phone']) ?></span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-5 text-md-end text-start">
        <?php if ($isPayoff): ?>
          <div class="receipt-badge d-inline-flex align-items-center gap-2 text-start" style="background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%); color: #14532d; border: 1.5px solid #86efac; box-shadow: 0 3px 8px rgba(22, 163, 74, 0.15); padding: 7px 16px; border-radius: 14px; text-transform: none;">
            <i class="bi bi-patch-check-fill text-success" style="font-size: 1.7rem; line-height: 1; flex-shrink: 0;"></i>
            <div class="d-flex flex-column justify-content-center">
              <div style="font-weight: 700; font-size: 0.95rem; line-height: 1.25; color: #14532d;">វិក័យបត្របង់ផ្តាច់ឥណទាន</div>
              <div style="font-size: 0.75rem; letter-spacing: 0.8px; opacity: 0.85; font-weight: 800; font-family: 'Inter', sans-serif; line-height: 1.2; margin-top: 2px;">PAYOFF RECEIPT</div>
            </div>
          </div>
        <?php else: ?>
          <div class="receipt-badge d-inline-block text-center" style="background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); color: #1e40af; border: 1.5px solid #93c5fd; box-shadow: 0 3px 8px rgba(37, 99, 235, 0.12); padding: 8px 22px; border-radius: 14px; text-transform: none; line-height: 1.35;">
            <div style="font-weight: 700; font-size: 0.98rem; line-height: 1.3; color: #1e3a8a;">បង្កាន់ដៃទទួលប្រាក់</div>
            <div style="font-size: 0.76rem; letter-spacing: 0.8px; opacity: 0.9; font-weight: 800; font-family: 'Inter', sans-serif; line-height: 1.25; margin-top: 2px;">PAYMENT RECEIPT</div>
          </div>
        <?php endif; ?>
        <div class="receipt-no">#<?= str_pad((string)$payment_id, 6, '0', STR_PAD_LEFT) ?></div>
        <div class="text-muted" style="font-size: 0.8rem; margin-top: 4px;">
          <i class="bi bi-calendar-event me-1"></i>កាលបរិច្ឆេទ / Date: <strong class="text-dark" style="font-family: var(--font-family); font-weight: 700;"><?= h2(fin_format_date_kh($pay_date)) ?></strong>
        </div>
      </div>
    </div>

    <!-- Perforated Line Separator -->
    <div class="receipt-divider"></div>

    <?php if ($isPayoff): ?>
      <!-- Official Payoff Settlement Certificate Banner -->
      <div class="p-3 mb-4 rounded-3 d-flex align-items-center gap-3 payoff-banner" style="background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border: 1.5px solid #86efac; color: #166534; box-shadow: 0 4px 12px rgba(22, 163, 74, 0.08);">
        <i class="bi bi-shield-fill-check fs-2 text-success flex-shrink-0"></i>
        <div style="font-size: 0.88rem; line-height: 1.45;">
          <strong class="d-block" style="font-size: 0.96rem; color: #14532d;"><i class="bi bi-check-circle-fill me-1 text-success"></i> ការបញ្ជាក់ការបង់ផ្តាច់ឥណទាន (Official Payoff Settlement Certificate)</strong>
          ឥណទានលេខ <strong><?= h2($loan_code ?: ('#'.$loan_id)) ?></strong> ត្រូវបានអតិថិជនទូទាត់បង់ផ្តាច់ប្រាក់ដើម និងការប្រាក់រួចរាល់ជាស្ថាពរ។ អតិថិជនបានរួចផុតពីកាតព្វកិច្ចបំណុលនៃកម្ចីនេះទាំងស្រុង។
        </div>
      </div>
    <?php endif; ?>

    <!-- Body Content Grid -->
    <div class="row g-4 mb-4">
      <!-- Left Column: Customer Details -->
      <div class="col-12 col-md-6">
        <div class="info-card h-100">
          <h6 class="text-uppercase text-muted fw-bold mb-3" style="font-size: 0.75rem; letter-spacing: 0.5px;">
            <i class="bi bi-person-fill text-primary me-1"></i> ព័ត៌មានអតិថិជន / Customer Details
          </h6>
          <div class="mb-2 d-flex justify-content-between align-items-center">
            <span class="text-secondary" style="font-size: 0.85rem;">អតិថិជន / Customer:</span>
            <strong class="text-dark" style="font-size: 0.9rem;"><?= h2($customer_name) ?></strong>
          </div>
          <div class="mb-2 d-flex justify-content-between align-items-center">
            <span class="text-secondary" style="font-size: 0.85rem;">លេខទូរស័ព្ទ / Phone:</span>
            <strong class="text-dark font-mono" style="font-size: 0.9rem;"><?= h2($row['customer_phone'] ?: '—') ?></strong>
          </div>
          <div class="mb-2 d-flex justify-content-between align-items-start">
            <span class="text-secondary" style="font-size: 0.85rem;">អាសយដ្ឋាន / Address:</span>
            <strong class="text-dark text-end ms-2" style="font-size: 0.9rem; max-width: 65%;"><?= h2($row['customer_address'] ?: '—') ?></strong>
          </div>
          <div class="pt-2 mt-2 border-top d-flex justify-content-between align-items-center">
            <span class="text-secondary" style="font-size: 0.85rem;">លេខឥណទាន / Loan Code:</span>
            <strong class="text-primary font-mono" style="font-size: 0.9rem;"><?= h2($loan_code ?: '—') ?></strong>
          </div>
        </div>
      </div>

      <!-- Right Column: Amount highlight & status -->
      <div class="col-12 col-md-6">
        <div class="amount-highlight"<?= $isPayoff ? ' style="background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); border-color: #6ee7b7;"' : '' ?>>
          <div class="amount-title"<?= $isPayoff ? ' style="color: #047857;"' : '' ?>><?= $isPayoff ? 'ទឹកប្រាក់បង់ផ្តាច់សរុប / Total Payoff Amount' : 'ទឹកប្រាក់បានទូទាត់ / Amount Paid' ?></div>
          <div class="amount-num"<?= $isPayoff ? ' style="color: #065f46;"' : '' ?>><?= fin_format_amount($amount, $ccy) ?></div>
          <div>
            <?php if ($isPayoff): ?>
              <span class="amount-status" style="background-color: #10b981; color: #ffffff; box-shadow: 0 2px 6px rgba(16, 185, 129, 0.3);">
                <i class="bi bi-patch-check-fill"></i> បង់ផ្តាច់រួចរាល់ / FULLY SETTLED
              </span>
            <?php else: ?>
              <span class="amount-status">
                <i class="bi bi-patch-check-fill"></i> ទូទាត់រួចរាល់ / PAID
              </span>
            <?php endif; ?>
          </div>
          <?php if (trim($note) !== ''): ?>
            <div class="mt-3 pt-2 border-top text-center" style="font-size: 0.8rem; line-height: 1.35; border-color: rgba(22, 101, 52, 0.15) !important;">
              <strong style="font-size: 0.75rem; color: #15803d;">សម្គាល់ / Remarks:</strong>
              <span class="ms-1" style="word-break: break-all; color: #166534; opacity: 0.9; font-weight: 500;"><?= h2($note) ?></span>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Transaction info grid (Method, Cashier) -->
    <div class="row g-4 mb-4">
      <div class="col-12 col-md-6">
        <div class="d-flex justify-content-between align-items-center p-3 border rounded-3 bg-white">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-wallet2 text-primary"></i>
            <span class="text-secondary" style="font-size: 0.85rem;">វិធីសាស្ត្រទូទាត់ / Method</span>
          </div>
          <strong class="text-dark font-mono"><?= h2($method ?: 'Cash') ?></strong>
        </div>
      </div>
      <div class="col-12 col-md-6">
        <div class="d-flex justify-content-between align-items-center p-3 border rounded-3 bg-white">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-person-badge text-primary"></i>
            <span class="text-secondary" style="font-size: 0.85rem;">បេឡាករ / Cashier</span>
          </div>
          <strong class="text-dark"><?= h2($cashier_name) ?></strong>
        </div>
      </div>
    </div>

    <!-- Account Summary Table -->
    <h6 class="fw-bold mb-3 text-primary d-flex align-items-center gap-2" style="font-size: 0.9rem; letter-spacing: 0.5px;">
      <i class="bi bi-file-earmark-bar-graph-fill"></i> សេចក្តីសង្ខេបគណនីឥណទាន / Loan Account Summary
    </h6>
    <!-- Desktop View Summary Table -->
    <div class="table-responsive d-none d-md-block mb-4">
      <table class="table table-bordered align-middle" style="border-color: #cbd5e1;">
        <thead class="table-light text-secondary" style="font-size: 0.8rem;">
          <tr>
            <th class="ps-3 py-3">ការពិពណ៌នា /<br>Description</th>
            <th class="text-end py-3">ប្រាក់ដើមឥណទាន /<br>Principal</th>
            <th class="text-end py-3">ត្រូវទូទាត់សរុប /<br>Total Due</th>
            <th class="text-end pe-3 py-3">សមតុល្យនៅសល់ /<br>Outstanding</th>
          </tr>
        </thead>
        <tbody style="font-size: 0.9rem;">
          <tr class="fw-semibold text-dark">
            <td class="ps-3 py-3 text-secondary">សមតុល្យឥណទាន /<br>Loan Balance</td>
            <td class="text-end py-3 font-mono"><?= fin_format_amount($principal, $ccy) ?></td>
            <td class="text-end py-3 font-mono text-primary"><?= fin_format_amount($total_due, $ccy) ?></td>
            <td class="text-end pe-3 py-3 <?= $outstanding > 0 ? 'text-danger' : 'text-success' ?>">
              <span class="font-mono fw-semibold"><?= fin_format_amount($outstanding, $ccy) ?></span>
              <?php if ($outstanding <= 0): ?>
                <div style="font-family: var(--font-family); font-size: 0.75rem; font-weight: normal; margin-top: 2px;">(ទូទាត់រួចរាល់ / Settle)</div>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <td colspan="4" class="p-0">
              <div class="p-3 bg-light d-flex flex-wrap gap-4 justify-content-between text-muted" style="font-size: 0.8rem;">
                <div><span class="text-secondary">ថ្ងៃចាប់ផ្តើម / Start:</span> <strong class="text-dark ms-1" style="font-family: var(--font-family); font-weight: 700;"><?= h2(fin_format_date_kh($start_date)) ?></strong></div>
                <div><span class="text-secondary">ថ្ងៃដល់កំណត់ / Due:</span> <strong class="text-dark ms-1" style="font-family: var(--font-family); font-weight: 700;"><?= h2(fin_format_date_kh($due_date)) ?></strong></div>
                <?php if ((int)($row['term_months'] ?? 0) > 0): ?>
                  <div><span class="text-secondary">រយៈពេលខ្ចី / Term:</span> <strong class="text-dark ms-1"><?= (int)$row['term_months'] ?> ខែ / Months</strong></div>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Mobile View Summary Card (Cart Style) -->
    <div class="d-md-none mb-4">
      <div class="card p-3" style="border: 1px solid rgba(15,23,42,.08); border-radius: 16px; background-color: #f8fafc;">
        <div class="fw-bold text-dark mb-2" style="font-size: 0.95rem;">
          សមតុល្យឥណទាន / Loan Balance
        </div>
        
        <!-- Row 1: Labels -->
        <div class="row g-2">
          <div class="col-6">
            <div class="text-muted" style="font-size: 0.78rem;">ប្រាក់ដើមឥណទាន /<br>Principal</div>
          </div>
          <div class="col-6 text-end">
            <div class="text-muted" style="font-size: 0.78rem;">ត្រូវទូទាត់សរុប /<br>Total Due</div>
          </div>
        </div>
        
        <!-- Row 2: Values -->
        <div class="row g-2 mb-2">
          <div class="col-6">
            <div class="mono fw-semibold" style="font-size: 0.88rem;"><?= fin_format_amount($principal, $ccy) ?></div>
          </div>
          <div class="col-6 text-end">
            <div class="mono fw-semibold text-primary" style="font-size: 0.88rem;"><?= fin_format_amount($total_due, $ccy) ?></div>
          </div>
        </div>

        <!-- Row 3: Labels -->
        <div class="row g-2 border-top pt-2">
          <div class="col-6">
            <div class="text-muted" style="font-size: 0.78rem;">សមតុល្យនៅសល់ /<br>Outstanding</div>
          </div>
          <div class="col-6 text-end">
            <?php if ((int)($row['term_months'] ?? 0) > 0): ?>
              <div class="text-muted" style="font-size: 0.78rem;">រយៈពេលខ្ចី /<br>Term</div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Row 4: Values -->
        <div class="row g-2 mb-2">
          <div class="col-6">
            <div class="mono fw-bold <?= $outstanding > 0 ? 'text-danger' : 'text-success' ?>" style="font-size: 0.88rem;">
              <?= fin_format_amount($outstanding, $ccy) ?>
              <?php if ($outstanding <= 0): ?>
                <span style="font-family: var(--font-family); font-size: 0.7rem; font-weight: normal; display: block;">(ទូទាត់រួចរាល់ / Settle)</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="col-6 text-end">
            <?php if ((int)($row['term_months'] ?? 0) > 0): ?>
              <div class="fw-semibold font-mono" style="font-size: 0.88rem;"><?= (int)$row['term_months'] ?> ខែ / Months</div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Row 5: Dates -->
        <div class="col-12 mt-2 pt-2 border-top">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 text-muted" style="font-size: 0.78rem;">
            <div>ថ្ងៃចាប់ផ្តើម / Start: <strong class="text-dark" style="font-family: var(--font-family); font-weight: 700;"><?= h2(fin_format_date_kh($start_date)) ?></strong></div>
            <div>ថ្ងៃដល់កំណត់ / Due: <strong class="text-dark" style="font-family: var(--font-family); font-weight: 700;"><?= h2(fin_format_date_kh($due_date)) ?></strong></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Signatures -->
    <div class="signature-section my-5">
      <div class="row g-4">
        <div class="col-6">
          <div class="signature-box">
            <div class="signature-title">ហត្ថលេខាអ្នកទទួលប្រាក់ /<br>Cashier Signature</div>
            <div class="signature-line"></div>
            <strong class="text-dark" style="font-size: 0.85rem;"><?= h2($cashier_name) ?></strong>
          </div>
        </div>
        <div class="col-6">
          <div class="signature-box">
            <div class="signature-title">ហត្ថលេខាអតិថិជន /<br>Customer Signature</div>
            <div class="signature-line"></div>
            <strong class="text-dark" style="font-size: 0.85rem;"><?= h2($customer_name) ?></strong>
          </div>
        </div>
      </div>
    </div>

    <!-- Footer Note -->
    <div class="footer-note">
      <div class="mb-1"><i class="bi bi-shield-fill-check text-success me-1"></i>សូមរក្សាទុកបង្កាន់ដៃនេះសម្រាប់ជាភស្តុតាងទៅថ្ងៃក្រោយ។ / Please keep this receipt for future reference.</div>
      <div style="font-size: 0.7rem; opacity: 0.7;">រក្សាសិទ្ធិដោយ <?= h2($business_name) ?> • System Verified Receipt</div>
    </div>

    <!-- Actions Section -->
    <div class="actions">
      <a href="loan_view.php?id=<?= (int)$loan_id ?>" class="btn btn-outline-secondary me-auto d-inline-flex align-items-center justify-content-center gap-2" style="border-radius: 8px; font-weight: 500; padding: 8px 16px; gap: 8px; white-space: nowrap;">
        <i class="bi bi-arrow-left" style="font-size: 1.05rem;"></i><span class="d-none d-md-inline">ត្រឡប់ក្រោយ</span>
      </a>
      <button class="btn btn-info text-white d-inline-flex align-items-center justify-content-center gap-2" onclick="shareReceipt()" style="border-radius: 8px; font-weight: 600; padding: 8px 18px; gap: 8px; background-color: #0ea5e9; border-color: #0ea5e9; white-space: nowrap;">
        <i class="bi bi-image-fill" style="font-size: 1.05rem;"></i><span class="d-none d-md-inline">ចែករំលែក</span>
      </button>
      <button class="btn btn-success d-inline-flex align-items-center justify-content-center gap-2" onclick="downloadPDF()" style="border-radius: 8px; font-weight: 600; padding: 8px 18px; gap: 8px; white-space: nowrap;">
        <i class="bi bi-file-earmark-pdf-fill" style="font-size: 1.05rem;"></i><span class="d-none d-md-inline">ទាញយក PDF</span>
      </button>
      <button class="btn btn-primary d-inline-flex align-items-center justify-content-center gap-2" onclick="window.print()" style="border-radius: 8px; font-weight: 600; padding: 8px 18px; gap: 8px; background-color: var(--primary); border-color: var(--primary); white-space: nowrap;">
        <i class="bi bi-printer-fill" style="font-size: 1.05rem;"></i><span class="d-none d-md-inline">បោះពុម្ព</span>
      </button>
    </div>

  </div>
</div>

</body>
</html>
