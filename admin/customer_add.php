<?php
/* ============================================================
   /finance/admin/customer_add.php  (FULL PAGE - corrected)
============================================================ */

require_once '../includes/auth.php';
require_once '../includes/helpers.php';

fin_require_login();
fin_require_permission('add_customer');
$business_id = fin_require_business();
global $pdo;

function h2($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

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

function fin_public_url(?string $path): string {
  $p = trim((string)$path);
  if ($p === '') return '';
  if (preg_match('#^https?://#i', $p)) return $p;
  if (str_starts_with($p, '/')) return $p;
  return '../' . ltrim($p, '/');
}

function fin_customer_avatar_path(?string $photo, ?string $gender): string {
  $p = trim((string)$photo);
  if ($p !== '') return $p;
  $g = strtolower(trim((string)$gender));
  if (in_array($g, ['male','m','ប្រុស'], true))   return 'uploads/avatars/male.png';
  if (in_array($g, ['female','f','ស្រី'], true)) return 'uploads/avatars/female.png';
  return 'uploads/avatars/default.png';
}

function fin_only_digits(string $s): string {
  return preg_replace('/\D+/', '', $s) ?? '';
}

function fin_normalize_phone(?string $phone): string {
  $p = trim((string)$phone);
  if ($p === '') return '';
  $d = fin_only_digits($p);
  if (str_starts_with($d, '855') && strlen($d) >= 11) {
    $d = '0' . substr($d, 3);
  }
  return $d;
}

function fin_find_duplicate_customer(PDO $pdo, string $bizCol, int $bizId, array $data): ?array {
  $phone = fin_normalize_phone($data['phone'] ?? '');
  $nid   = trim((string)($data['nid'] ?? ''));
  $name  = trim((string)($data['full_name'] ?? ''));

  if ($phone !== '') {
    $st = $pdo->prepare("SELECT id, full_name, phone, nid FROM customers WHERE {$bizCol} = ? AND phone = ? LIMIT 1");
    $st->execute([$bizId, $phone]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
  }
  if ($nid !== '') {
    $st = $pdo->prepare("SELECT id, full_name, phone, nid FROM customers WHERE {$bizCol} = ? AND nid = ? LIMIT 1");
    $st->execute([$bizId, $nid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
  }
  if ($phone === '' && $nid === '' && $name !== '') {
    $st = $pdo->prepare("SELECT id, full_name, phone, nid FROM customers WHERE {$bizCol} = ? AND full_name = ? LIMIT 1");
    $st->execute([$bizId, $name]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
  }
  return null;
}

function fin_upload_customer_photo(array $file, int $customerId): array {
  if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return ['ok'=>true, 'path'=>'', 'error'=>''];
  if ($file['error'] !== UPLOAD_ERR_OK) return ['ok'=>false, 'path'=>'', 'error'=>'Upload error code: '.$file['error']];
  if ((int)$file['size'] > 2 * 1024 * 1024) return ['ok'=>false, 'path'=>'', 'error'=>'File too large (max 2MB).'];

  $tmp = $file['tmp_name'];
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime = $finfo ? finfo_file($finfo, $tmp) : '';
  if ($finfo) finfo_close($finfo);

  $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
  if (!isset($allowed[$mime])) return ['ok'=>false, 'path'=>'', 'error'=>'Invalid image type. Allowed: JPG, PNG, WEBP'];

  $ext = $allowed[$mime];
  $relDir = 'uploads/customers';
  $fsDir  = dirname(__DIR__) . '/' . $relDir;
  if (!is_dir($fsDir)) @mkdir($fsDir, 0775, true);

  $filename = 'customer_' . $customerId . '.' . $ext;
  $fsPath   = $fsDir . '/' . $filename;
  $relPath  = $relDir . '/' . $filename;

  if (!move_uploaded_file($tmp, $fsPath)) return ['ok'=>false, 'path'=>'', 'error'=>'Failed to save uploaded file.'];
  return ['ok'=>true, 'path'=>$relPath, 'error'=>''];
}


function fin_save_customer_base64_photo(?string $base64, int $customerId): array {
  $base64 = trim((string)$base64);
  if ($base64 === '') return ['ok'=>true, 'path'=>'', 'error'=>''];

  if (!preg_match('#^data:image/(png|jpe?g|webp);base64,#i', $base64, $m)) {
    return ['ok'=>false, 'path'=>'', 'error'=>'Invalid cropped NID photo format.'];
  }

  $extRaw = strtolower($m[1]);
  $ext = ($extRaw === 'jpeg' || $extRaw === 'jpg') ? 'jpg' : ($extRaw === 'png' ? 'png' : 'webp');

  $data = substr($base64, strpos($base64, ',') + 1);
  $data = preg_replace('/\s+/', '', $data);
  $bin = base64_decode($data, true);

  if ($bin === false || strlen($bin) < 500) {
    return ['ok'=>false, 'path'=>'', 'error'=>'Invalid cropped NID photo data.'];
  }

  if (strlen($bin) > 2 * 1024 * 1024) {
    return ['ok'=>false, 'path'=>'', 'error'=>'Cropped NID photo too large.'];
  }

  $relDir = 'uploads/customers';
  $fsDir  = dirname(__DIR__) . '/' . $relDir;
  if (!is_dir($fsDir)) @mkdir($fsDir, 0775, true);

  $filename = 'customer_' . $customerId . '_nid_face.' . $ext;
  $fsPath   = $fsDir . '/' . $filename;
  $relPath  = $relDir . '/' . $filename;

  if (file_put_contents($fsPath, $bin) === false) {
    return ['ok'=>false, 'path'=>'', 'error'=>'Failed to save cropped NID photo.'];
  }

  return ['ok'=>true, 'path'=>$relPath, 'error'=>''];
}

$customersBizCol = fin_col_exists($pdo,'customers','business_id') ? 'business_id' : (fin_col_exists($pdo,'customers','biz_id') ? 'biz_id' : 'business_id');

$err = '';
$dupId = 0;
$data = ['full_name' => '', 'gender' => '', 'phone' => '', 'phone2' => '', 'nid' => '', 'id_card' => '', 'dob' => '', 'address' => '', 'note' => '', 'telegram_username' => '', 'telegram_chat_id' => '', 'is_active' => 1, 'photo' => '', 'nid_face_photo_base64' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $data['full_name'] = trim((string)($_POST['full_name'] ?? ''));
  $data['gender']    = trim((string)($_POST['gender'] ?? ''));
  $data['phone']     = fin_normalize_phone($_POST['phone'] ?? '');
  $data['phone2']    = fin_normalize_phone($_POST['phone2'] ?? '');
  $data['nid']       = trim((string)($_POST['nid'] ?? ''));
  $data['id_card']   = ''; // ID Card field removed from UI; keep DB insert compatible.
  $data['dob']       = trim((string)($_POST['dob'] ?? ''));
  $data['address']   = trim((string)($_POST['address'] ?? ''));
  $data['note']      = trim((string)($_POST['note'] ?? ''));
  $data['telegram_username'] = trim((string)($_POST['telegram_username'] ?? ''));
  $data['telegram_chat_id']  = trim((string)($_POST['telegram_chat_id'] ?? ''));
  $data['is_active'] = isset($_POST['is_active']) ? 1 : 0;
  $data['nid_face_photo_base64'] = trim((string)($_POST['nid_face_photo_base64'] ?? ''));

  if ($data['full_name'] === '') {
    $err = 'សូមបញ្ចូលឈ្មោះអតិថិជន។';
  } else {
    $dup = fin_find_duplicate_customer($pdo, $customersBizCol, (int)$business_id, $data);
    if ($dup) {
      $dupId    = (int)($dup['id'] ?? 0);
      $dupName  = (string)($dup['full_name'] ?? '');
      $dupPhone = (string)($dup['phone'] ?? '');
      $err = "អតិថិជននេះមានរួចហើយ៖ {$dupName}" . ($dupPhone ? " ({$dupPhone})" : "");
    } else {
      try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("INSERT INTO customers ($customersBizCol, full_name, gender, phone, phone2, nid, id_card, dob, address, note, telegram_username, telegram_chat_id, is_active, photo, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $st->execute([$business_id, $data['full_name'], $data['gender'] ?: null, $data['phone'] ?: null, $data['phone2'] ?: null, $data['nid'] ?: null, $data['id_card'] ?: null, $data['dob'] ?: null, $data['address'] ?: null, $data['note'] ?: null, $data['telegram_username'] ?: null, $data['telegram_chat_id'] ?: null, $data['is_active'], '']);
        $newId = (int)$pdo->lastInsertId();

        $upload = fin_upload_customer_photo($_FILES['photo'] ?? [], $newId);
        if (!$upload['ok']) {
          $pdo->rollBack();
          $err = $upload['error'];
        } else {
          $photoPath = $upload['path'] ?? '';

          // ✅ If no manual photo was uploaded, use the cropped face photo from NID scan.
          if ($photoPath === '' && !empty($data['nid_face_photo_base64'])) {
            $crop = fin_save_customer_base64_photo($data['nid_face_photo_base64'], $newId);
            if (!$crop['ok']) {
              $pdo->rollBack();
              $err = $crop['error'];
            } else {
              $photoPath = $crop['path'] ?? '';
            }
          }

          if ($err === '') {
            if ($photoPath !== '') {
              $pdo->prepare("UPDATE customers SET photo = ? WHERE id = ? AND $customersBizCol = ?")->execute([$photoPath, $newId, $business_id]);
            }
            $pdo->commit();
            header('Location: loan_add.php?customer_id=' . $newId);
            exit;
          }
        }
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = $e->getMessage();
        if (($e instanceof PDOException) && $e->getCode() === '23000' && strpos($msg,'Duplicate entry') !== false) {
          $err = 'លេខទូរស័ព្ទ ឬអត្តសញ្ញាណប័ណ្ណនេះមានរួចហើយក្នុងប្រព័ន្ធ។';
          if (!empty($data['phone'])) {
            $stLook = $pdo->prepare("SELECT id FROM customers WHERE {$customersBizCol} = ? AND phone = ? LIMIT 1");
            $stLook->execute([$business_id, $data['phone']]);
            $dupId = (int)$stLook->fetchColumn();
          }
          if (!$dupId && !empty($data['nid'])) {
            $stLook = $pdo->prepare("SELECT id FROM customers WHERE {$customersBizCol} = ? AND nid = ? LIMIT 1");
            $stLook->execute([$business_id, $data['nid']]);
            $dupId = (int)$stLook->fetchColumn();
          }
        } else {
          $err = 'មានបញ្ហា៖ ' . $msg;
        }
      }
    }
  }
}

$avatarDbPath = fin_customer_avatar_path($data['photo'] ?? '', $data['gender'] ?? '');
$avatarUrl    = fin_public_url($avatarDbPath);

$stTg = $pdo->prepare("SELECT telegram_bot_token, telegram_bot_username FROM business_settings WHERE business_id = ? LIMIT 1");
$stTg->execute([$business_id]);
$tgSettings = $stTg->fetch(PDO::FETCH_ASSOC) ?: [];
$bot_token = trim($tgSettings['telegram_bot_token'] ?? '');
$bot_username = trim($tgSettings['telegram_bot_username'] ?? '');

if ($bot_token !== '' && $bot_username === '') {
    require_once __DIR__ . '/../includes/followup_helpers.php';
    $bot_username = fin_get_bot_username($bot_token) ?: '';
    if ($bot_username !== '') {
        $pdo->prepare("UPDATE business_settings SET telegram_bot_username = ? WHERE business_id = ?")->execute([$bot_username, $business_id]);
    }
}
?>
<!doctype html>
<html lang="km">
<head>
  <script>
  window.onerror = function(message, source, lineno, colno, error) {
    alert("GLOBAL ERROR: " + message + "\nLine: " + lineno + "\nSource: " + source);
    return false;
  };
  </script>
  <meta charset="utf-8">
  <title>បន្ថែមអតិថិជន | Finance</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Battambang:wght@300;400;700;900&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
  <style>
    :root{ --bg:#f3f4f6; --card:#fff; --ink:#0f172a; --muted:#64748b; --line:#e5e7eb; --brand:#2563eb; }
    body{font-family:'Battambang',sans-serif;background:var(--bg); color:var(--ink);}
    .page-title{font-weight:900;}
    .sub{color:var(--muted); font-size:.92rem;}
    .cardx{ background:var(--card); border:1px solid rgba(15,23,42,.08); border-radius:18px; box-shadow:none; padding:14px !important; }
    @media(min-width:992px){ .cardx{ padding:16px !important; } }
    .row.g-3{ --bs-gutter-y: .55rem; --bs-gutter-x: .75rem; }
    label.form-label{ margin-bottom:.25rem; font-weight:800; font-size:.92rem; }
    .form-control, .form-select{ border-radius:12px; border:1px solid var(--line); padding:.47rem .7rem; font-size:.92rem; }
    textarea.form-control{ padding:.55rem .7rem; }
    .input-group-text{ border-radius:12px 0 0 12px; background:#f8fafc; border:1px solid var(--line); color:#334155; padding:.47rem .65rem; min-width:42px; justify-content:center; }
    .input-group .form-control, .input-group .form-select{ border-radius:0 12px 12px 0; }
    .hero{ background: radial-gradient(1200px 260px at 10% 0%, rgba(37,99,235,.10), transparent 70%), radial-gradient(900px 220px at 90% 0%, rgba(16,185,129,.10), transparent 60%); border-radius:16px; border:1px solid rgba(15,23,42,.06); padding:10px 12px; margin-bottom:.65rem; }
    .avatar{ width:60px;height:60px;border-radius:999px;object-fit:cover;border:1px solid var(--line);background:#fff; }
    .avatar-ring{ width:66px;height:66px;border-radius:999px; display:flex;align-items:center;justify-content:center; background: linear-gradient(135deg, rgba(37,99,235,.22), rgba(16,185,129,.18)); border:1px solid rgba(15,23,42,.06); flex:0 0 auto; }
    .hint{font-size:.84rem; color:var(--muted); margin-top:.15rem;}
    .btn{ border-radius:12px; padding:.5rem .85rem; font-weight:800; }
    .btn-primary{ background:var(--brand); border-color:var(--brand); }
    .flatpickr-calendar, .flatpickr-calendar *{ font-family:'Battambang',sans-serif !important; font-size:.92rem; }
    .dup-list{margin:.35rem 0 0; padding-left:1.05rem;}
    .dup-list li{margin:.15rem 0;}
    ::placeholder {
      color: #cbd5e1 !important; /* lighter gray placeholder */
      opacity: 1; /* Firefox */
    }
    :-ms-input-placeholder {
      color: #cbd5e1 !important;
    }
    ::-ms-input-placeholder {
      color: #cbd5e1 !important;
    }

    /* =========================
       ID Scanner UI clean update
       - after capture: hide camera view, show captured image only
       - lighter text weight
       - mobile/desktop responsive layout
    ========================= */
    #scannerModal .modal-content{
      border-radius:20px !important;
      overflow:hidden;
      box-shadow:0 20px 55px rgba(15,23,42,.22);
    }
    #scannerModal .modal-header{
      padding:.82rem 1rem !important;
    }
    #scannerModal .modal-title{
      font-weight:700 !important;
      font-size:1.04rem;
      letter-spacing:0;
    }
    #scannerModal .modal-body{
      padding:18px !important;
      background:#fff;
    }
    #scannerModal .nav.nav-pills{
      max-width:740px;
      margin-left:auto;
      margin-right:auto;
      gap:4px;
    }
    #scannerModal .nav-link{
      font-weight:600 !important;
      border-radius:10px !important;
      padding:.58rem .7rem !important;
    }
    #scannerModal .btn{
      font-weight:600 !important;
    }
    #scannerModal .form-label{
      font-weight:600 !important;
    }
    #scannerModal .scan-stage{
      max-width:720px;
      margin:0 auto;
    }
    #scannerModal .scan-camera-box{
      width:100%;
      max-width:560px;
      aspect-ratio:4/3;
      border-radius:18px !important;
      border:1px solid rgba(15,23,42,.10) !important;
      box-shadow:0 10px 28px rgba(15,23,42,.08);
    }
    #scannerModal .scan-frame{
      width:84%;
      height:64%;
      border-radius:14px !important;
      border-color:#0d6efd !important;
    }
    #scannerModal .scan-frame-text{
      font-weight:500 !important;
      font-size:.88rem;
      line-height:1.45;
      color:rgba(255,255,255,.86) !important;
    }
    #scannerModal .scan-actions{
      display:flex;
      justify-content:center;
      gap:.55rem;
      flex-wrap:wrap;
      margin-top:.25rem;
    }
    #scannerModal #ocrPreviewPanel{
      max-width:720px;
      margin:0 auto 1rem;
    }
    #scannerModal .ocr-preview-card{
      background:#f8fafc;
      border:1px solid rgba(15,23,42,.10);
      border-radius:18px;
      padding:10px;
    }
    #scannerModal #ocrPreviewImg{
      width:100%;
      max-height:min(54vh, 430px);
      object-fit:contain;
      border-radius:14px;
      display:block;
    }
    #scannerModal #webcam-pane.has-captured #webcamLiveBox,
    #scannerModal #webcam-pane.has-captured #webcamControls{
      display:none !important;
    }
    #scannerModal .captured-note{
      display:none;
      max-width:720px;
      margin:0 auto .65rem;
      font-size:.86rem;
      color:#64748b;
      font-weight:400;
    }
    #scannerModal .captured-note i{
      color:#16a34a;
    }
    #scannerModal #webcam-pane.has-captured ~ .captured-note{
      display:flex;
    }
    #scannerModal #ocrResultPanel h6{
      font-weight:700 !important;
    }
    #scannerModal #ocrResultPanel{
      max-width:720px;
      margin-left:auto;
      margin-right:auto;
    }

    @media (max-width: 767.98px){
      #scannerModal .modal-dialog{
        margin:.5rem;
      }
      #scannerModal .modal-body{
        padding:12px !important;
      }
      #scannerModal .modal-header{
        padding:.72rem .85rem !important;
      }
      #scannerModal .modal-title{
        font-size:.96rem;
      }
      #scannerModal .nav-link{
        font-size:.9rem;
        padding:.5rem .35rem !important;
      }
      #scannerModal .scan-camera-box{
        max-width:100%;
        border-radius:16px !important;
      }
      #scannerModal .scan-actions .btn{
        flex:1 1 140px;
        padding:.48rem .55rem;
        font-size:.88rem;
      }
      #scannerModal #ocrPreviewImg{
        max-height:42vh;
      }
      #scannerModal .modal-footer{
        padding:.65rem .75rem !important;
      }
      #scannerModal .modal-footer > .d-flex{
        width:100%;
      }
      #scannerModal .modal-footer .btn{
        flex:1 1 auto;
        padding:.48rem .45rem;
        font-size:.86rem;
      }
    }

    @media (min-width: 768px){
      #scannerModal .modal-dialog{
        max-width:820px;
      }
    }


    /* Auto capture + NID portrait crop */
    #scannerModal .auto-capture-pill{
      display:inline-flex;
      align-items:center;
      gap:.38rem;
      border:1px solid rgba(37,99,235,.18);
      background:#eff6ff;
      color:#1d4ed8;
      border-radius:999px;
      padding:.38rem .68rem;
      font-size:.82rem;
      font-weight:500;
      margin:0 auto .65rem;
    }
    #scannerModal .auto-capture-pill.is-ready{
      background:#ecfdf5;
      color:#047857;
      border-color:rgba(16,185,129,.26);
    }
    #scannerModal .nid-face-preview{
      display:none;
      max-width:720px;
      margin:.65rem auto 0;
      align-items:center;
      gap:.65rem;
      background:#f8fafc;
      border:1px solid rgba(15,23,42,.09);
      border-radius:16px;
      padding:.6rem;
      text-align:left;
    }
    #scannerModal .nid-face-preview.show{
      display:flex;
    }
    #scannerModal .nid-face-preview img{
      width:58px;
      height:58px;
      border-radius:14px;
      object-fit:cover;
      border:1px solid rgba(15,23,42,.12);
      background:#fff;
      flex:0 0 auto;
    }
    #scannerModal .nid-face-preview .small-title{
      font-size:.86rem;
      font-weight:600;
      color:#0f172a;
    }
    #scannerModal .nid-face-preview .small-sub{
      font-size:.76rem;
      font-weight:400;
      color:#64748b;
    }
    @media(max-width: 767.98px){
      #scannerModal .auto-capture-pill{
        font-size:.78rem;
        padding:.34rem .58rem;
      }
      #scannerModal .nid-face-preview img{
        width:52px;
        height:52px;
      }
    }


    /* v12: cleaner captured view */
    #scannerModal #webcam-pane.has-captured #autoCapturePill{
      display:none !important;
    }
    #scannerModal .nid-face-preview{
      display:none !important;
    }
    #scannerModal .scanner-tabs-modern{
      background:#f1f5f9 !important;
      padding:5px !important;
      border-radius:14px !important;
      border:1px solid rgba(15,23,42,.06);
      box-shadow: inset 0 1px 0 rgba(255,255,255,.8);
    }
    #scannerModal .scanner-tabs-modern .nav-link{
      display:flex;
      align-items:center;
      justify-content:center;
      gap:.45rem;
      color:#2563eb;
      font-weight:600 !important;
      border-radius:11px !important;
      transition:all .18s ease;
    }
    #scannerModal .scanner-tabs-modern .nav-link i{
      font-size:1rem;
      line-height:1;
    }
    #scannerModal .scanner-tabs-modern .nav-link.active{
      color:#fff !important;
      background:linear-gradient(135deg,#0d6efd,#2563eb) !important;
      box-shadow:0 8px 18px rgba(37,99,235,.22);
    }
    #scannerModal .scanner-tabs-modern .nav-link:not(.active):hover{
      background:#eaf2ff;
    }


    /* Upload tab: camera or gallery */
    #scannerModal .upload-choice-wrap{
      max-width:720px;
      margin:0 auto;
    }
    #scannerModal .upload-choice-card{
      border:1px solid rgba(15,23,42,.08);
      background:#f8fafc;
      border-radius:18px;
      padding:14px;
    }
    #scannerModal .upload-choice-title{
      font-weight:600;
      font-size:.95rem;
      color:#0f172a;
      margin-bottom:.18rem;
    }
    #scannerModal .upload-choice-sub{
      font-size:.8rem;
      color:#64748b;
      font-weight:400;
      margin-bottom:.8rem;
    }
    #scannerModal .upload-choice-grid{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:.7rem;
    }
    #scannerModal .upload-choice-btn{
      border:1px solid rgba(37,99,235,.16);
      background:#fff;
      border-radius:16px;
      padding:.9rem .7rem;
      display:flex;
      align-items:center;
      justify-content:center;
      gap:.55rem;
      font-weight:600;
      color:#1d4ed8;
      transition:all .18s ease;
    }
    #scannerModal .upload-choice-btn:hover{
      background:#eff6ff;
      transform:translateY(-1px);
      box-shadow:0 10px 22px rgba(37,99,235,.10);
    }
    #scannerModal .upload-choice-btn.primary{
      background:linear-gradient(135deg,#0d6efd,#2563eb);
      color:#fff;
      border-color:rgba(37,99,235,.28);
      box-shadow:0 10px 22px rgba(37,99,235,.20);
    }
    #scannerModal .upload-choice-btn.primary:hover{
      filter:brightness(1.02);
    }
    #scannerModal .upload-choice-btn i{
      font-size:1.12rem;
    }
    @media(max-width: 575.98px){
      #scannerModal .upload-choice-grid{
        grid-template-columns:1fr;
      }
      #scannerModal .upload-choice-btn{
        padding:.82rem .7rem;
      }
    }


    /* Compact customer photo row */
    .customer-photo-compact{
      display:flex;
      align-items:center;
      gap:.75rem;
      padding:.55rem .65rem;
      border:1px solid rgba(37,99,235,.14);
      border-radius:18px;
      background:linear-gradient(135deg, rgba(37,99,235,.06), rgba(16,185,129,.06));
    }
    .customer-photo-compact .avatar-ring{
      width:54px;
      height:54px;
      flex:0 0 54px;
    }
    .customer-photo-compact .avatar{
      width:48px;
      height:48px;
    }
    .photo-main{
      min-width:0;
      flex:1 1 auto;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:.75rem;
    }
    .photo-title{
      font-weight:700;
      font-size:.92rem;
      line-height:1.2;
      color:#0f172a;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .photo-hint{
      color:#64748b;
      font-size:.76rem;
      line-height:1.25;
      margin-top:.12rem;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .photo-actions{
      display:flex;
      align-items:center;
      gap:.45rem;
      flex:0 0 auto;
    }
    .btn-photo-mini{
      border-radius:12px;
      padding:.42rem .68rem;
      font-weight:700;
      font-size:.84rem;
      line-height:1.1;
      border:1px solid rgba(37,99,235,.22);
      background:#fff;
      color:#2563eb;
    }
    .btn-photo-mini:hover{
      background:#eff6ff;
      color:#1d4ed8;
    }
    .active-mini{
      display:flex;
      align-items:center;
      gap:.35rem;
      margin:0;
      white-space:nowrap;
      font-size:.82rem;
      font-weight:700;
      color:#0f172a;
    }
    .active-mini .form-check-input{
      margin:0;
      width:2.15rem;
      height:1.1rem;
      cursor:pointer;
    }

    @media(max-width: 767.98px){
      .customer-photo-compact{
        align-items:flex-start;
        padding:.55rem;
        gap:.55rem;
      }
      .customer-photo-compact .avatar-ring{
        width:48px;
        height:48px;
        flex-basis:48px;
      }
      .customer-photo-compact .avatar{
        width:42px;
        height:42px;
      }
      .photo-main{
        flex-direction:column;
        align-items:stretch;
        gap:.45rem;
      }
      .photo-title{
        font-size:.86rem;
      }
      .photo-hint{
        font-size:.72rem;
      }
      .photo-actions{
        justify-content:space-between;
        gap:.35rem;
      }
      .btn-photo-mini{
        padding:.38rem .58rem;
        font-size:.78rem;
      }
      .active-mini{
        font-size:.78rem;
      }
    }


    /* Icon-only customer photo uploader */
    .customer-photo-compact.icon-only{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:.75rem;
      padding:.45rem .55rem;
      border:1px solid rgba(37,99,235,.12);
      border-radius:18px;
      background:linear-gradient(135deg, rgba(37,99,235,.045), rgba(16,185,129,.045));
      min-height:76px;
    }
    .photo-avatar-btn{
      position:relative;
      width:62px;
      height:62px;
      border-radius:999px;
      border:3px solid #fff;
      padding:0;
      overflow:hidden;
      background:#0f172a;
      box-shadow:0 6px 18px rgba(15,23,42,.16);
      flex:0 0 auto;
      cursor:pointer;
    }
    .photo-avatar-btn .avatar{
      width:100%;
      height:100%;
      border-radius:999px;
      object-fit:cover;
      border:0;
      background:#fff;
      display:block;
    }
    .photo-avatar-btn::before{
      content:"";
      position:absolute;
      inset:0;
      background:rgba(0,0,0,.34);
      opacity:.92;
      transition:.18s ease;
    }
    .photo-avatar-btn:hover::before{
      background:rgba(0,0,0,.42);
    }
    .photo-avatar-icon{
      position:absolute;
      inset:0;
      display:flex;
      align-items:center;
      justify-content:center;
      color:#fff;
      font-size:1.55rem;
      z-index:2;
      text-shadow:0 2px 5px rgba(0,0,0,.22);
      pointer-events:none;
    }
    .photo-right-tools{
      display:flex;
      align-items:center;
      gap:.65rem;
      flex:0 0 auto;
    }
    .active-mini.icon-only-active{
      background:#fff;
      border:1px solid rgba(15,23,42,.08);
      border-radius:999px;
      padding:.35rem .55rem;
      box-shadow:0 4px 12px rgba(15,23,42,.05);
    }
    @media(max-width: 767.98px){
      .customer-photo-compact.icon-only{
        min-height:66px;
        padding:.4rem .48rem;
      }
      .photo-avatar-btn{
        width:54px;
        height:54px;
      }
      .photo-avatar-icon{
        font-size:1.35rem;
      }
      .photo-right-tools{
        gap:.45rem;
      }
      .active-mini.icon-only-active{
        padding:.28rem .45rem;
      }
    }

    .page-header-bg{
      background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
      border-radius: 18px;
      padding: 16px 20px;
      margin-bottom: .85rem;
      color: #fff;
      box-shadow: 0 10px 25px rgba(29, 78, 216, 0.15);
    }
    .page-header-bg .page-title{
      color: #fff !important;
      font-weight: 700;
    }
    .page-header-bg .sub{
      color: rgba(255,255,255,0.85) !important;
    }
  </style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="container py-4">
  <div class="page-header-bg d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <div>
      <h4 class="page-title mb-0"><i class="bi bi-person-plus-fill me-1"></i> បន្ថែមអតិថិជន</h4>
      <div class="sub">បន្ថែមព័ត៌មានអតិថិជន</div>
    </div>
    <div class="d-flex gap-2 align-items-center">
      <button type="button" class="btn btn-light" id="btnScanIdCard" style="border-radius:12px; color:#1e40af; font-weight:700;">
        <i class="bi bi-camera me-1"></i> ស្កែនអត្តសញ្ញាណប័ណ្ណ
      </button>
      <a class="btn btn-outline-light" href="customers.php" style="border-radius:12px; font-weight:500;"><i class="bi bi-arrow-left me-1"></i> ត្រឡប់</a>
    </div>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-danger py-2 d-flex justify-content-between align-items-center flex-wrap gap-2" style="border-radius: 12px;">
      <div>
        <i class="bi bi-exclamation-triangle me-1"></i>
        <?= h2($err) ?>
      </div>
      <?php if (isset($dupId) && $dupId > 0): ?>
        <div class="d-flex gap-2">
          <a class="btn btn-sm btn-primary" href="loan_add.php?customer_id=<?= (int)$dupId ?>" style="border-radius: 8px;"><i class="bi bi-plus-circle me-1"></i> បង្កើតកម្ចី</a>
          <a class="btn btn-sm btn-light" href="customer_edit.php?id=<?= (int)$dupId ?>" style="border-radius: 8px;"><i class="bi bi-pencil-square me-1"></i> កែប្រែ</a>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="cardx">
    <div class="hero">
      <div class="customer-photo-compact icon-only">
        <button type="button" class="photo-avatar-btn" id="btnPickPhoto" title="Upload photo">
          <img id="avatarPreview" class="avatar" src="<?= h2($avatarUrl) ?>" alt="avatar">
          <span class="photo-avatar-icon"><i class="bi bi-camera-fill"></i></span>
        </button>

        <div class="photo-right-tools">
          <label class="form-check form-switch active-mini icon-only-active" title="Active customer">
            <input class="form-check-input" type="checkbox" name="is_active" form="custForm" <?= ($data['is_active']? 'checked':'') ?>>
            <span>សកម្ម</span>
          </label>
        </div>

        <input type="file" name="photo" id="photoInput" class="d-none" form="custForm" accept="image/*">
      </div>
    </div>

    <form id="custForm" method="post" enctype="multipart/form-data" class="row g-3">
      <input type="hidden" name="nid_face_photo_base64" id="nidFacePhotoBase64" value="">
      <div class="col-12 col-lg-6">
        <label class="form-label">ឈ្មោះអតិថិជន <span class="text-danger">*</span></label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-person"></i></span>
          <input type="text" id="fullNameInput" name="full_name" class="form-control" value="<?= h2($data['full_name']) ?>" required>
        </div>
      </div>
      <div class="col-12 col-lg-3">
        <label class="form-label">ភេទ</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-gender-ambiguous"></i></span>
          <select name="gender" id="genderSelect" class="form-select">
            <option value="">-- ជ្រើសរើស --</option>
            <option value="male"   <?= ($data['gender']==='male'?'selected':'') ?>>ប្រុស</option>
            <option value="female" <?= ($data['gender']==='female'?'selected':'') ?>>ស្រី</option>
            <option value="other"  <?= ($data['gender']==='other'?'selected':'') ?>>ផ្សេងៗ</option>
          </select>
        </div>
      </div>
      <div class="col-12 col-lg-3">
        <label class="form-label">ថ្ងៃកំណើត</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
          <input type="text" name="dob" id="dobPicker" class="form-control" placeholder="ថ្ងៃ-ខែ-ឆ្នាំ" value="<?= h2($data['dob']) ?>">
        </div>
      </div>
      <div class="col-12 col-md-6 col-lg-4">
        <label class="form-label">ទូរស័ព្ទ</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-telephone"></i></span>
          <input type="text" id="phoneInput" name="phone" class="form-control" value="<?= h2($data['phone']) ?>">
        </div>
      </div>
      <div class="col-12 col-md-6 col-lg-4">
        <label class="form-label">ទូរស័ព្ទ 2</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-telephone-plus"></i></span>
          <input type="text" name="phone2" class="form-control" value="<?= h2($data['phone2']) ?>">
        </div>
      </div>
      <div class="col-12 col-md-6 col-lg-4">
        <label class="form-label">NID (អត្តសញ្ញាណប័ណ្ណជាតិ)</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-credit-card-2-front"></i></span>
          <input type="text" id="nidInput" name="nid" class="form-control" value="<?= h2($data['nid']) ?>" maxlength="50">
        </div>
      </div>

      <div class="col-12">
        <div id="dupBox" class="alert alert-info py-2 d-none" style="border-radius:12px;">
          <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
            <div class="flex-grow-1">
              <i class="bi bi-info-circle me-1"></i>
              <span id="dupText">...</span>
              <ul id="dupList" class="dup-list d-none"></ul>
            </div>
            <div class="d-flex gap-2">
              <a id="dupLoanBtn" class="btn btn-sm btn-primary" href="#" target="_self"><i class="bi bi-plus-circle me-1"></i> បង្កើតកម្ចី</a>
              <a id="dupEditBtn" class="btn btn-sm btn-outline-secondary" href="#" target="_self"><i class="bi bi-pencil-square me-1"></i> កែប្រែ</a>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12 col-md-6 col-lg-4">
        <label class="form-label">Telegram Username</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-telegram"></i></span>
          <input type="text" name="telegram_username" id="telegram_username" class="form-control" value="<?= h2($data['telegram_username']) ?>" placeholder="ឧ: sok_telegram">
        </div>
      </div>
      <div class="col-12 col-md-6 col-lg-4">
        <label class="form-label">Telegram Chat ID</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-hash"></i></span>
          <input type="text" name="telegram_chat_id" id="telegram_chat_id" class="form-control" value="<?= h2($data['telegram_chat_id']) ?>" placeholder="ឧ: 123456789">
          <?php if ($bot_token !== '' && $bot_username !== ''): ?>
            <button class="btn btn-outline-primary" type="button" id="btnTgQr" onclick="openTgQrModal()" title="ស្កេន QR យក Chat ID"><i class="bi bi-qr-code"></i></button>
          <?php endif; ?>
        </div>
        <div class="help mt-1" style="font-size:0.78rem;"><i class="bi bi-info-circle me-1"></i>ចុចលើប៊ូតុងQR ដើម្បីស្កេន</div>
      </div>
      <div class="col-12">
        <label class="form-label">អាសយដ្ឋាន</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-geo-alt"></i></span>
          <textarea name="address" class="form-control" rows="2"><?= h2($data['address']) ?></textarea>
        </div>
      </div>
      <div class="col-12">
        <label class="form-label">ចំណាំ</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-journal-text"></i></span>
          <textarea name="note" class="form-control" rows="2"><?= h2($data['note']) ?></textarea>
        </div>
      </div>
      <div class="col-12 d-flex gap-2 justify-content-end pt-1">
        <a href="customers.php" class="btn btn-outline-secondary"><i class="bi bi-x-circle me-1"></i> បោះបង់</a>
        <button id="saveBtn" class="btn btn-primary"><i class="bi bi-save me-1"></i> រក្សាទុក</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrious/4.0.2/qrious.min.js"></script>
<script>
  const KhmerLocale = {
    weekdays: { shorthand: ["អា", "ច", "អ", "ព", "ព្រ", "សុ", "ស"], longhand: ["អាទិត្យ", "ចន្ទ", "អង្គារ", "ពុធ", "ព្រហស្បតិ៍", "សុក្រ", "សៅរ៍"] },
    months: { shorthand: ["មក", "កុ", "មី", "មេ", "ឧស", "មិថ", "កក្ក", "សី", "កញ", "តុ", "វិច", "ធ្ន"], longhand: ["មករា","កុម្ភៈ","មីនា","មេសា","ឧសភា","មិថុនា","កក្កដា","សីហា","កញ្ញា","តុលា","វិច្ឆិកា","ធ្នូ"] },
    firstDayOfWeek: 1, rangeSeparator: " ដល់ ", weekAbbreviation: "សប្ដា", scrollTitle: "Scroll ដើម្បីបន្ថែម", toggleTitle: "Click ដើម្បីប្តូរ", amPM: ["ព្រឹក", "ល្ងាច"], yearAriaLabel: "ឆ្នាំ", time_24hr: true, ordinal: () => ""
  };
  if (window.flatpickr) {
    flatpickr("#dobPicker", {
      dateFormat: "Y-m-d",
      allowInput: true,
      locale: KhmerLocale,
      disableMobile: true,
      altInput: true,
      altFormat: "d-F-Y"
    });
  }

  const photoInput = document.getElementById('photoInput');
  const previewImg = document.getElementById('avatarPreview');
  const genderSelect = document.getElementById('genderSelect');
  document.getElementById('btnPickPhoto')?.addEventListener('click', () => photoInput?.click());
  const AVA_DEFAULT = "../uploads/avatars/default.png";
  const AVA_MALE = "../uploads/avatars/male.png";
  const AVA_FEMALE = "../uploads/avatars/female.png";

  function setAvatarByGender(){
    if (!previewImg) return;
    if (photoInput && photoInput.files && photoInput.files.length > 0) return;
    const g = (genderSelect?.value || '').toLowerCase();
    if (g === 'male') previewImg.src = AVA_MALE;
    else if (g === 'female') previewImg.src = AVA_FEMALE;
    else previewImg.src = AVA_DEFAULT;
  }
  genderSelect?.addEventListener('change', setAvatarByGender);
  photoInput?.addEventListener('change', function(){
    const f = this.files && this.files[0];
    if (!previewImg) return;
    if (!f) { setAvatarByGender(); return; }
    const faceHidden = document.getElementById('nidFacePhotoBase64');
    if (faceHidden) faceHidden.value = '';
    document.getElementById('nidFacePreviewBox')?.classList.remove('show');
    previewImg.src = URL.createObjectURL(f);
  });
  previewImg?.addEventListener('error', () => { if (previewImg) previewImg.src = AVA_DEFAULT; });
  setAvatarByGender();

  const phoneEl = document.getElementById('phoneInput'), nameEl = document.getElementById('fullNameInput'), nidEl = document.getElementById('nidInput');
  const dupBox = document.getElementById('dupBox'), dupText = document.getElementById('dupText'), dupList = document.getElementById('dupList');
  const dupLoanBtn = document.getElementById('dupLoanBtn'), dupEditBtn = document.getElementById('dupEditBtn'), saveBtn = document.getElementById('saveBtn');
  let typingTimer = null, lockedFound = false, lastQuery = '';

  function showBox(){ dupBox?.classList.remove('d-none'); }
  function resetBox(){
    lockedFound = false; dupBox?.classList.add('d-none');
    if (dupText) dupText.textContent = '';
    if (dupList){ dupList.innerHTML=''; dupList.classList.add('d-none'); }
    if (saveBtn) saveBtn.disabled = false;
  }
  function escapeHtml(s){ return String(s ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'","&#039;"); }

  function showChecking(q){
    if (lockedFound) return;
    dupBox?.classList.add('d-none');
    dupText.textContent = `កំពុងពិនិត្យ... "${q}"`;
    if (dupList){ dupList.innerHTML=''; dupList.classList.add('d-none'); }
  }

  function renderMatches(matches){
    if (!matches || !matches.length) return;
    lockedFound = true; showBox(); dupBox.classList.remove('alert-warning'); dupBox.classList.add('alert-info');
    const first = matches[0];
    const firstPhone = first.phone || first.phone2 || '-';
    dupText.textContent = `មានរួចហើយ: ${first.full_name} (${firstPhone})`;
    const topBtnsWrap = dupLoanBtn?.closest('.d-flex');
    if (matches.length === 1) {
      if (dupLoanBtn) dupLoanBtn.href = `loan_add.php?customer_id=${first.id}`;
      if (dupEditBtn) dupEditBtn.href = `customer_edit.php?id=${first.id}`;
      if (topBtnsWrap) topBtnsWrap.classList.remove('d-none');
      if (dupList){ dupList.innerHTML = ''; dupList.classList.add('d-none'); }
    } else {
      if (topBtnsWrap) topBtnsWrap.classList.add('d-none');
      if (dupList) {
        dupList.innerHTML = ''; dupList.classList.remove('d-none');
        matches.forEach(m => {
          const p = m.phone || m.phone2 || '-';
          const nid = m.nid ? ` | NID: ${escapeHtml(m.nid)}` : '';
          const li = document.createElement('li');
          li.style.margin = ".25rem 0";
          li.innerHTML = `
            <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
              <div class="text-truncate" style="min-width:220px;">
                <a href="customer_edit.php?id=${m.id}" style="font-weight:900;text-decoration:none;">${escapeHtml(m.full_name)}</a>
                <span class="text-muted"> — ${escapeHtml(p)}${nid}</span>
              </div>
              <div class="d-flex gap-2">
                <a class="btn btn-sm btn-primary" href="loan_add.php?customer_id=${m.id}"><i class="bi bi-plus-circle me-1"></i> បង្កើតកម្ចី</a>
                <a class="btn btn-sm btn-outline-secondary" href="customer_edit.php?id=${m.id}"><i class="bi bi-pencil-square me-1"></i> កែប្រែ</a>
              </div>
            </div>`;
          dupList.appendChild(li);
        });
      }
    }
    if (saveBtn) saveBtn.disabled = true;
  }

  async function checkDup(){
    const phone = phoneEl?.value.trim() || '', name = nameEl?.value.trim() || '', nid = nidEl?.value.trim() || '';
    if (!phone && !name && !nid) { resetBox(); return; }
    const q = phone || nid || name;
    if (q.length < 2) { resetBox(); return; }
    if (lockedFound && q === lastQuery) return;
    lockedFound = false;
    lastQuery = q;
    showChecking(q);
    try{
      const res = await fetch(`customer_check.php?q=${encodeURIComponent(q)}`, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
      const js = await res.json();
      if (!js.ok) return;
      if (js.found && Array.isArray(js.matches) && js.matches.length) {
        renderMatches(js.matches);
      } else {
        resetBox();
      }
    }catch(e){ console.error(e); }
  }

  function schedule(){ clearTimeout(typingTimer); typingTimer = setTimeout(checkDup, 180); }
  phoneEl?.addEventListener('input', schedule); nameEl?.addEventListener('input', schedule); nidEl?.addEventListener('input', schedule);
  phoneEl?.addEventListener('blur', checkDup); nameEl?.addEventListener('blur', checkDup); nidEl?.addEventListener('blur', checkDup);

  // --- Telegram QR Modal Actions ---
  let tgQrModal = null;
  document.addEventListener('DOMContentLoaded', () => {
    try {
      tgQrModal = new bootstrap.Modal(document.getElementById('tgQrModal'));
    } catch (e) {
      console.error("tgQrModal initialization failed:", e);
    }

    const btnRefresh = document.getElementById('btnRefreshRecent');
    if (btnRefresh) {
      btnRefresh.addEventListener('click', loadRecentChats);
    }
  });

  function openTgQrModal() {
    try {
      if (!tgQrModal) {
        try {
          tgQrModal = new bootstrap.Modal(document.getElementById('tgQrModal'));
        } catch (e) {
          alert("មិនអាចបើកផ្ទាំងនេះបានទេ ព្រោះខ្វះឯកសារ Bootstrap JS (Bootstrap is not loaded yet)។");
          console.error(e);
          return;
        }
      }

      const botUsername = <?= json_encode($bot_username) ?>;
      
      if (!botUsername) {
        alert("សូមកំណត់ Telegram Bot Username នៅក្នុង settings ជាមុនសិន។");
        return;
      }
      
      const botLink = `https://t.me/${botUsername}`;
      
      const linkEl = document.getElementById('tgBotLink');
      if (linkEl) {
        linkEl.href = botLink;
        linkEl.textContent = `@${botUsername}`;
      }
      
      try {
        new QRious({
          element: document.getElementById('tgQrCanvas'),
          value: botLink,
          size: 200,
          background: '#ffffff',
          foreground: '#0f172a',
          level: 'H'
        });
      } catch (qrErr) {
        console.error("QRious failed to render QR:", qrErr);
        const canvas = document.getElementById('tgQrCanvas');
        if (canvas) {
          const ctx = canvas.getContext('2d');
          if (ctx) {
            ctx.fillStyle = '#f8fafc';
            ctx.fillRect(0, 0, 200, 200);
            ctx.fillStyle = '#94a3b8';
            ctx.font = '12px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('QR Code Unavailable', 100, 100);
          }
        }
      }
      
      const btnDownload = document.getElementById('btnDownloadQr');
      if (btnDownload) {
        btnDownload.onclick = (e) => {
          e.preventDefault();
          const canvas = document.getElementById('tgQrCanvas');
          if (canvas) {
            const image = canvas.toDataURL("image/png").replace("image/png", "image/octet-stream");
            const link = document.createElement('a');
            link.download = `tg_bot_qr_${botUsername}.png`;
            link.href = image;
            link.click();
          }
        };
      }
      
      if (tgQrModal) {
        tgQrModal.show();
      } else {
        alert("កំហុស៖ មិនអាចបង្កើត Modal បានទេ។");
      }
      loadRecentChats();
    } catch (err) {
      alert("Javascript Error: " + err.message);
      console.error(err);
    }
  }

  async function loadRecentChats() {
    const tableBody = document.getElementById('tgRecentTableBody');
    if (!tableBody) return;
    
    tableBody.innerHTML = '<tr><td colspan="4" class="text-center py-3 text-muted"><span class="spinner-border spinner-border-sm me-1" role="status"></span> កំពុងទាញយកទិន្នន័យ...</td></tr>';
    
    try {
      const res = await fetch('ajax_telegram_recent.php', {
        method: 'GET',
        credentials: 'same-origin'
      });
      const js = await res.json();
      if (js.ok) {
        const users = js.users || [];
        if (users.length === 0) {
          tableBody.innerHTML = '<tr><td colspan="4" class="text-center py-3 text-muted">មិនមានសារផ្ញើចូលថ្មីៗទេ។ សូមឱ្យអតិថិជនផ្ញើសារទៅកាន់ Bot រួចចុច "ទាញយកបញ្ជី" ម្តងទៀត។</td></tr>';
          return;
        }
        
        let html = '';
        users.forEach(u => {
          const usernameDisp = u.username ? `@${u.username}` : '—';
          const msgDisp = u.message ? escapeHtmlJs(u.message) : '<i>(Start/Other)</i>';
          
          const escapedName = escapeHtmlJs(u.full_name);
          const escapedUser = escapeHtmlJs(u.username);
          const escapedChat = escapeHtmlJs(u.chat_id);
          const updateId = u.update_id || 0;
          
          html += `
            <tr id="tg-row-${escapedChat}">
              <td>
                <div class="fw-bold">${escapedName}</div>
                <div class="text-muted" style="font-size:0.75rem;">ID: ${escapedChat}</div>
              </td>
              <td>${usernameDisp}</td>
              <td class="text-truncate text-muted" style="max-width: 120px;" title="${escapedName}">${msgDisp}</td>
              <td class="text-end">
                <button type="button" class="btn btn-sm btn-success py-1 fw-bold" onclick="selectTelegramUser('${escapedChat}', '${escapedUser}', ${updateId})" style="border-radius:6px; font-size:0.8rem;">
                  ជ្រើសរើស
                </button>
              </td>
            </tr>
          `;
        });
        tableBody.innerHTML = html;
      } else {
        let errHtml = `<i class="bi bi-exclamation-triangle-fill me-1"></i> ${escapeHtmlJs(js.error || 'បរាជ័យក្នុងការទាញយក')}`;
        if (js.error && (js.error.includes('webhook is active') || js.error.includes('deleteWebhook'))) {
          errHtml += `<br><button type="button" class="btn btn-sm btn-danger mt-2 fw-bold" onclick="deleteTelegramWebhook(this)"><i class="bi bi-trash-fill me-1"></i> លុប Webhook (Delete Webhook)</button>`;
        }
        tableBody.innerHTML = `<tr><td colspan="4" class="text-center py-3 text-danger">${errHtml}</td></tr>`;
      }
    } catch (err) {
      console.error(err);
      tableBody.innerHTML = `<tr><td colspan="4" class="text-center py-3 text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i> កំហុសប្រព័ន្ធ៖ ${err.message}</td></tr>`;
    }
  }

  function escapeHtmlJs(str) {
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  window.selectTelegramUser = function(chatId, username, updateId) {
    const chatInput = document.getElementById('telegram_chat_id');
    const userInput = document.getElementById('telegram_username');
    if (chatInput) chatInput.value = chatId;
    if (userInput && username && username !== 'undefined' && username !== '') userInput.value = username;
    
    // Remove the row from the modal table immediately!
    const row = document.getElementById(`tg-row-${chatId}`);
    if (row) {
      row.remove();
    }
    
    // Call background confirm action to clear it from Telegram API updates list
    if (updateId && updateId > 0) {
      fetch(`ajax_telegram_recent.php?action=confirm&update_id=${updateId}`, { credentials: 'same-origin' })
        .catch(e => console.error("Failed to confirm telegram update:", e));
    }
    
    if (tgQrModal) tgQrModal.hide();
  }

  window.deleteTelegramWebhook = async function(btn) {
    if (!confirm("តើអ្នកពិតជាចង់លុប Webhook នេះមែនទេ? (លុបដើម្បីទាញយក Chat ID ថ្មី)")) return;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> កំពុងលុប Webhook...';
    try {
      const res = await fetch('ajax_telegram_delete_webhook.php', { method: 'POST', credentials: 'same-origin' });
      const js = await res.json();
      if (js.ok) {
        alert("លុប Webhook បានជោគជ័យ! សូមអតិថិជនផ្ញើសារម្តងទៀត រួចចុច Refresh។");
        loadRecentChats();
      } else {
        alert("កំហុស៖ " + (js.error || 'បរាជ័យ'));
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-trash-fill me-1"></i> លុប Webhook (Delete Webhook)';
      }
    } catch (e) {
      console.error(e);
      alert("កំហុសប្រព័ន្ធ៖ " + e.message);
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-trash-fill me-1"></i> លុប Webhook (Delete Webhook)';
    }
  }
</script>

<!-- Telegram QR Modal -->
<div class="modal fade" id="tgQrModal" tabindex="-1" aria-labelledby="tgQrModalLabel" aria-hidden="true" style="z-index: 1070;">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:18px; border:none; box-shadow:0 15px 30px rgba(0,0,0,0.15);">
      <div class="modal-header bg-primary text-white py-3">
        <h5 class="modal-title fw-bold" id="tgQrModalLabel"><i class="bi bi-telegram me-2"></i> ស្កេន Telegram Bot ដើម្បីភ្ជាប់ Chat ID</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4 text-center bg-light">
        <div class="mb-3">
          <div class="d-inline-block bg-white p-3 rounded-4 shadow-sm border mb-2" id="tgQrContainer">
            <canvas id="tgQrCanvas" style="width: 200px; height: 200px;"></canvas>
          </div>
          <div>
            <a href="#" id="btnDownloadQr" class="btn btn-sm btn-outline-secondary mt-1 fw-bold" style="border-radius:10px;"><i class="bi bi-download me-1"></i> ទាញយក QR Code</a>
          </div>
        </div>
        
        <h6 class="fw-bold mb-2 text-start">របៀបភ្ជាប់ Chat ID ស្វ័យប្រវត្តិ៖</h6>
        <ol class="text-start small text-muted ps-3 mb-4">
          <li class="mb-1">ឱ្យអតិថិជនស្កេន QR Code ខាងលើ ឬចុចលើតំណភ្ជាប់៖ <a href="#" id="tgBotLink" target="_blank" class="fw-bold text-decoration-none">t.me/...</a></li>
          <li class="mb-1">បន្ទាប់មក អតិថិជនត្រូវចុច <b>Start</b> ឬផ្ញើសារណាមួយទៅកាន់ Bot (ឧ៖ ផ្ញើពាក្យ "សួស្តី")។</li>
          <li>ចុចប៊ូតុង <b>ទាញយកបញ្ជី (Refresh)</b> ខាងក្រោម រួចជ្រើសរើសឈ្មោះអតិថិជនរបស់គាត់។</li>
        </ol>

        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="fw-bold m-0 text-start"><i class="bi bi-chat-dots me-1"></i> សារផ្ញើចូលថ្មីៗ (Recent Messages)</h6>
          <button type="button" class="btn btn-sm btn-primary" id="btnRefreshRecent" style="border-radius:8px;"><i class="bi bi-arrow-clockwise"></i> ទាញយកបញ្ជី</button>
        </div>

        <div class="border rounded-4 bg-white overflow-hidden" style="max-height: 250px; overflow-y: auto;">
          <table class="table table-sm table-hover align-middle mb-0 text-start" style="font-size:0.85rem;">
            <thead class="table-light">
              <tr>
                <th>ឈ្មោះ Telegram</th>
                <th>Username</th>
                <th>សារ</th>
                <th class="text-end">សកម្មភាព</th>
              </tr>
            </thead>
            <tbody id="tgRecentTableBody">
              <tr>
                <td colspan="4" class="text-center py-3 text-muted">កំពុងទាញយកទិន្នន័យ...</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer border-0 bg-light py-2 justify-content-end">
        <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal" style="border-radius:12px;">បិទ</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="scannerModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content" style="border-radius: 18px; border: none;">
      <div class="modal-header bg-primary text-white py-3">
        <h5 class="modal-title fw-bold"><i class="bi bi-camera me-2"></i> ស្កែនអត្តសញ្ញាណប័ណ្ណ</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <ul class="nav nav-pills nav-justified mb-3 scanner-tabs-modern">
          <li class="nav-item">
            <button class="nav-link active py-2" id="webcam-tab" data-bs-toggle="tab" data-bs-target="#webcam-pane" type="button"><i class="bi bi-camera-video"></i><span>កាមេរ៉ា</span></button>
          </li>
          <li class="nav-item">
            <button class="nav-link py-2" id="upload-tab" data-bs-toggle="tab" data-bs-target="#upload-pane" type="button"><i class="bi bi-cloud-arrow-up"></i><span>ផ្ទុករូបភាព</span></button>
          </li>
        </ul>

        <div class="tab-content">
          <div class="tab-pane fade show active text-center" id="webcam-pane">
            <div id="cameraErrorAlert" class="alert alert-warning d-none text-start mb-3" style="border-radius: 12px; font-size: 0.88rem;">
              <i class="bi bi-exclamation-triangle-fill me-2"></i>
              <strong>មិនអាចបើកកាមេរ៉ាបានទេ!</strong>
              <div id="cameraErrorMsg" class="mt-1 text-muted small">
                សូមពិនិត្យ HTTPS និងអនុញ្ញាត Camera Permission។
              </div>
              <div class="mt-2">
                <button type="button" class="btn btn-sm btn-primary fw-bold" onclick="document.getElementById('upload-tab').click(); setTimeout(()=>document.getElementById('ocrFileInput')?.click(), 200);" style="border-radius:10px;">
                  <i class="bi bi-camera me-1"></i> ថតរូបតាម Upload
                </button>
              </div>
            </div>
            
            <div id="autoCapturePill" class="auto-capture-pill">
              <i class="bi bi-camera-video"></i>
              <span id="autoCaptureText">ដាក់អត្តសញ្ញាណប័ណ្ណឱ្យចំប្រអប់ — ប្រព័ន្ធនឹងថតស្វ័យប្រវត្តិ</span>
            </div>

            <div class="scan-stage">
              <div id="webcamLiveBox" class="position-relative bg-dark overflow-hidden mb-3 mx-auto scan-camera-box">
                <video id="scannerVideo" autoplay playsinline style="width: 100%; height: 100%; object-fit: cover;"></video>
                <canvas id="scannerCanvas" class="d-none" width="640" height="480"></canvas>
                <div class="position-absolute start-50 top-50 translate-middle border border-2 scan-frame" style="box-shadow: 0 0 0 9999px rgba(0,0,0,0.52); pointer-events: none;">
                  <div class="position-absolute top-50 start-50 translate-middle text-white text-center scan-frame-text" style="width: 90%;">ដាក់អត្តសញ្ញាណប័ណ្ណក្នុងប្រអប់នេះ</div>
                </div>
              </div>
              <div id="webcamControls" class="scan-actions">
                <button type="button" class="btn btn-outline-secondary" id="btnToggleCamera" style="border-radius:12px;"><i class="bi bi-arrow-repeat me-1"></i> ប្តូរកាមេរ៉ា</button>
                <button type="button" class="btn btn-primary px-4" id="btnCapturePhoto" style="border-radius:12px;"><i class="bi bi-camera-fill me-1"></i> ថតរូប</button>
              </div>
            </div>
          </div>

          <div class="tab-pane fade" id="upload-pane">
            <div class="upload-choice-wrap">
              <div class="upload-choice-card text-center">
                <div class="upload-choice-title"><i class="bi bi-image me-1"></i> ជ្រើសរើសរូបភាព ឬ ថតថ្មី</div>
                <div class="upload-choice-sub">លើទូរស័ព្ទ អ្នកអាចថតរូបអត្តសញ្ញាណប័ណ្ណដោយផ្ទាល់ ឬជ្រើសរើសពីរូបភាពដែលមានស្រាប់។</div>

                <div class="upload-choice-grid">
                  <button type="button" class="upload-choice-btn primary" id="btnUploadCamera">
                    <i class="bi bi-camera-fill"></i>
                    <span>ថតរូបផ្ទាល់</span>
                  </button>
                  <button type="button" class="upload-choice-btn" id="btnUploadGallery">
                    <i class="bi bi-images"></i>
                    <span>ជ្រើសរើសរូបភាព</span>
                  </button>
                </div>

                <input type="file" id="ocrCameraInput" class="d-none" accept="image/*" capture="environment">
                <input type="file" id="ocrFileInput" class="d-none" accept="image/*">
              </div>
            </div>
          </div>
        </div>

        <div class="captured-note align-items-center gap-2">
          <i class="bi bi-check-circle-fill"></i>
          <span>បានថត និងកាត់ត្រឹមកាតរួច។ សូមពិនិត្យ ហើយចុច “ស្រង់ទិន្នន័យ”។</span>
        </div>

        <div id="ocrPreviewPanel" class="d-none mb-3">
          <div class="text-center ocr-preview-card">
            <img id="ocrPreviewImg" src="" alt="Captured ID card preview">
          </div>
        </div>

        <div id="nidFacePreviewBox" class="nid-face-preview">
          <img id="nidFacePreviewImg" src="" alt="NID face crop">
          <div>
            <div class="small-title"><i class="bi bi-person-bounding-box me-1"></i> រូបមុខពីអត្តសញ្ញាណប័ណ្ណ</div>
            <div class="small-sub">ប្រព័ន្ធបាន crop មុខសម្រាប់រូបថតអតិថិជន។ អ្នកអាចប្តូរដោយ upload រូបផ្សេងបាន។</div>
          </div>
        </div>

        <div id="ocrProgressPanel" class="d-none mb-3">
          <div class="d-flex justify-content-between mb-1">
            <span id="ocrProgressStatus" class="fw-bold text-primary small">កំពុងស្កែន...</span>
          </div>
          <div class="progress" style="height: 10px; border-radius: 999px;">
            <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" id="ocrProgressBar" style="width: 0%;"></div>
          </div>
        </div>

        <div id="ocrResultPanel" class="d-none mt-3">
          <h6 class="fw-bold text-success border-bottom pb-1"><i class="bi bi-check2-circle me-1"></i> ព័ត៌មានដែលស្រង់បាន</h6>
          <div class="row g-2">
            <div class="col-12 col-md-6"><label class="form-label small mb-1">ឈ្មោះ</label><input type="text" id="ocrName" class="form-control form-control-sm" style="border-radius:8px;"></div>
            <div class="col-12 col-md-6"><label class="form-label small mb-1">NID</label><input type="text" id="ocrNid" class="form-control form-control-sm" style="border-radius:8px;"></div>
            <div class="col-6 col-md-6"><label class="form-label small mb-1">ថ្ងៃកំណើត</label><input type="text" id="ocrDob" class="form-control form-control-sm" style="border-radius:8px;"></div>
            <div class="col-6 col-md-6"><label class="form-label small mb-1">ភេទ</label>
              <select id="ocrGender" class="form-select form-select-sm" style="border-radius:8px;">
                <option value="">-</option><option value="male">ប្រុស</option><option value="female">ស្រី</option>
              </select>
            </div>
            <div class="col-12"><label class="form-label small mb-1">អាសយដ្ឋាន</label><textarea id="ocrAddress" class="form-control form-control-sm" rows="2" style="border-radius:8px;"></textarea></div>
          </div>
        </div>
      </div>
      <div class="modal-footer bg-light py-3 border-top d-flex justify-content-between">
        <button type="button" class="btn btn-outline-secondary" id="btnOcrReset" style="border-radius:12px;">ស្កែនឡើងវិញ</button>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius:12px;">បោះបង់</button>
          <button type="button" class="btn btn-primary d-none" id="btnOcrSubmit" style="border-radius:12px;">បំពេញព័ត៌មាន</button>
          <button type="button" class="btn btn-success w-50 w-sm-auto" id="btnOcrStart" disabled style="border-radius:12px;"><i class="bi bi-cpu me-1"></i> ស្រង់ទិន្នន័យ (Extract)</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const btnScan = document.getElementById('btnScanIdCard');
  const scannerModalEl = document.getElementById('scannerModal');
  let scannerModal = scannerModalEl ? new bootstrap.Modal(scannerModalEl) : null;
  let localStream = null, currentFacingMode = 'environment';
  let lastOcrFullImage = '';
  let autoCaptureTimer = null;
  let autoCaptureBusy = false;
  let autoStableCount = 0;
  let lastSharpness = 0;

  btnScan?.addEventListener('click', function() {
    document.getElementById('webcam-pane')?.classList.remove('has-captured');
    const webcamTab = document.getElementById('webcam-tab');
    if (webcamTab) bootstrap.Tab.getInstance(webcamTab)?.show() || new bootstrap.Tab(webcamTab).show();
    scannerModal?.show();
  });

  // On mobile, start camera only after modal is fully visible.
  scannerModalEl?.addEventListener('shown.bs.modal', function(){
    if (document.getElementById('webcam-pane')?.classList.contains('active')) {
      setTimeout(startCamera, 180);
    }
  });

  document.getElementById('webcam-tab')?.addEventListener('shown.bs.tab', () => setTimeout(startCamera, 120));
  document.getElementById('upload-tab')?.addEventListener('shown.bs.tab', stopCamera);
  scannerModalEl?.addEventListener('hidden.bs.modal', stopCamera);

  async function startCamera() {
    const errorAlert = document.getElementById('cameraErrorAlert');
    const errorMsg = document.getElementById('cameraErrorMsg');
    const video = document.getElementById('scannerVideo');

    if (errorAlert) errorAlert.classList.add('d-none');
    if (!video) return;

    // Mobile browsers require HTTPS, except localhost.
    const isLocalhost = ['localhost', '127.0.0.1', '::1'].includes(location.hostname);
    if (!window.isSecureContext && !isLocalhost) {
      if (errorAlert) errorAlert.classList.remove('d-none');
      if (errorMsg) errorMsg.innerHTML = 'ទូរស័ព្ទត្រូវការ <b>HTTPS</b> ដើម្បីបើកកាមេរ៉ា។ សូមបើកតាម https://findinyou.com/... មិនមែន http://។';
      return;
    }

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      if (errorAlert) errorAlert.classList.remove('d-none');
      if (errorMsg) errorMsg.innerHTML = 'Browser នេះមិនគាំទ្រ Camera API ទេ។ សូមប្រើ Chrome/Safari ថ្មី ឬចុច “ថតរូបតាម Upload”。';
      return;
    }

    stopCamera();

    const tries = [
      { video: { facingMode: { exact: currentFacingMode }, width: { ideal: 1920 }, height: { ideal: 1080 } }, audio: false },
      { video: { facingMode: currentFacingMode, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false },
      { video: { facingMode: 'environment' }, audio: false },
      { video: true, audio: false }
    ];

    let lastErr = null;

    for (const constraints of tries) {
      try {
        localStream = await navigator.mediaDevices.getUserMedia(constraints);
        video.srcObject = localStream;
        video.setAttribute('playsinline', '');
        video.setAttribute('autoplay', '');
        video.muted = true;

        // iOS Safari sometimes needs explicit play().
        try { await video.play(); } catch (playErr) { console.warn('video.play warning:', playErr); }

        if (video.videoWidth || video.readyState >= 2) { startAutoCaptureWatcher(); return; }

        await new Promise(resolve => {
          const done = () => resolve();
          video.onloadedmetadata = done;
          setTimeout(done, 800);
        });
        startAutoCaptureWatcher();
        return;
      } catch (err) {
        lastErr = err;
        console.warn('Camera try failed:', constraints, err);
        stopCamera();
      }
    }

    if (errorAlert) errorAlert.classList.remove('d-none');
    if (errorMsg) {
      let msg = lastErr && lastErr.name ? `${lastErr.name}: ${lastErr.message || ''}` : 'Camera failed';
      if (lastErr && lastErr.name === 'NotAllowedError') {
        msg = 'Camera Permission ត្រូវបានបដិសេធ។ សូមចុច Allow ឬទៅ Browser Settings > Site Settings > Camera > Allow។';
      } else if (lastErr && lastErr.name === 'NotFoundError') {
        msg = 'រកមិនឃើញ Camera លើឧបករណ៍នេះ។';
      } else if (lastErr && lastErr.name === 'NotReadableError') {
        msg = 'Camera កំពុងត្រូវបាន app ផ្សេងប្រើ។ សូមបិទ Camera app/Telegram/FB រួចសាកម្ដងទៀត។';
      } else if (lastErr && lastErr.name === 'OverconstrainedError') {
        msg = 'កាមេរ៉ាខាងក្រោយមិនអាចប្រើ constraint នេះបាន។ សូមចុច “ប្តូរកាមេរ៉ា” ឬប្រើ Upload។';
      }
      errorMsg.innerHTML = msg;
    }
  }

  function stopCamera() {
    stopAutoCaptureWatcher();
    if (localStream) {
      localStream.getTracks().forEach(track => track.stop());
      localStream = null;
    }
    const video = document.getElementById('scannerVideo');
    if (video) {
      video.pause();
      video.srcObject = null;
    }
  }

  document.getElementById('btnToggleCamera')?.addEventListener('click', function() {
    currentFacingMode = (currentFacingMode === 'user') ? 'environment' : 'user';
    startCamera();
  });

  document.getElementById('btnCapturePhoto')?.addEventListener('click', function() {
    captureNowFromCamera(false);
  });


  function setAutoCaptureStatus(text, ready = false) {
    const pill = document.getElementById('autoCapturePill');
    const label = document.getElementById('autoCaptureText');
    if (label) label.textContent = text;
    if (pill) pill.classList.toggle('is-ready', !!ready);
  }

  function startAutoCaptureWatcher() {
    stopAutoCaptureWatcher();

    const video = document.getElementById('scannerVideo');
    const canvas = document.getElementById('scannerCanvas');
    const pane = document.getElementById('webcam-pane');

    if (!video || !canvas || !pane || pane.classList.contains('has-captured')) return;

    autoStableCount = 0;
    lastSharpness = 0;
    autoCaptureBusy = false;
    setAutoCaptureStatus('ដាក់អត្តសញ្ញាណប័ណ្ណឱ្យចំប្រអប់ — ប្រព័ន្ធនឹងថតស្វ័យប្រវត្តិ', false);

    autoCaptureTimer = setInterval(() => {
      if (autoCaptureBusy || pane.classList.contains('has-captured') || !localStream) return;
      const quality = estimateCardViewQuality(video, canvas);

      // quality.ready means the center frame has enough light/detail and is stable.
      if (quality.ready) {
        autoStableCount++;
        setAutoCaptureStatus(`ស្គាល់កាតហើយ... កំពុងថត ${autoStableCount}/3`, true);
      } else {
        autoStableCount = 0;
        setAutoCaptureStatus('ដាក់អត្តសញ្ញាណប័ណ្ណឱ្យចំប្រអប់ — ប្រព័ន្ធនឹងថតស្វ័យប្រវត្តិ', false);
      }

      if (autoStableCount >= 3) {
        captureNowFromCamera(true);
      }
    }, 650);
  }

  function stopAutoCaptureWatcher() {
    if (autoCaptureTimer) {
      clearInterval(autoCaptureTimer);
      autoCaptureTimer = null;
    }
    autoStableCount = 0;
  }

  function estimateCardViewQuality(video, canvas) {
    if (!video || !video.videoWidth || !video.videoHeight) return { ready:false };

    const vw = video.videoWidth;
    const vh = video.videoHeight;

    // Sample inside the blue frame area only.
    const sw = Math.floor(vw * 0.62);
    const sh = Math.floor(vh * 0.42);
    const sx = Math.floor((vw - sw) / 2);
    const sy = Math.floor((vh - sh) / 2);

    const tw = 160;
    const th = 100;
    canvas.width = tw;
    canvas.height = th;

    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(video, sx, sy, sw, sh, 0, 0, tw, th);

    const img = ctx.getImageData(0, 0, tw, th).data;
    let sum = 0, edge = 0, count = 0;

    // Brightness + simple edge/sharpness estimation.
    for (let y = 1; y < th - 1; y += 2) {
      for (let x = 1; x < tw - 1; x += 2) {
        const i = (y * tw + x) * 4;
        const g = (img[i] * .299) + (img[i+1] * .587) + (img[i+2] * .114);
        const ir = (y * tw + (x + 1)) * 4;
        const id = ((y + 1) * tw + x) * 4;
        const gr = (img[ir] * .299) + (img[ir+1] * .587) + (img[ir+2] * .114);
        const gd = (img[id] * .299) + (img[id+1] * .587) + (img[id+2] * .114);
        sum += g;
        edge += Math.abs(g - gr) + Math.abs(g - gd);
        count++;
      }
    }

    const brightness = sum / Math.max(1, count);
    const sharpness = edge / Math.max(1, count);
    const stable = Math.abs(sharpness - lastSharpness) < 4.5;
    lastSharpness = sharpness;

    // Avoid auto-capturing black/empty view; require text/detail.
    const ready = brightness > 55 && brightness < 235 && sharpness > 9 && stable;
    return { ready, brightness, sharpness, stable };
  }

  function captureNowFromCamera(isAuto) {
    if (autoCaptureBusy) return;
    const video = document.getElementById('scannerVideo');
    const canvas = document.getElementById('scannerCanvas');
    if (!video || !canvas || !video.videoWidth) {
      if (!isAuto) alert('កាមេរ៉ាមិនទាន់រួចរាល់ទេ');
      return;
    }

    autoCaptureBusy = true;
    stopAutoCaptureWatcher();

    const captured = captureFullAndCardFromVideo(video, canvas);
    lastOcrFullImage = captured.full;
    const dataUrl = captured.card;

    document.getElementById('ocrPreviewImg').src = dataUrl;
    document.getElementById('ocrPreviewPanel').classList.remove('d-none');
    document.getElementById('webcam-pane')?.classList.add('has-captured');
    document.getElementById('btnOcrStart').disabled = false;

    cropNidFaceFromCard(dataUrl).then(faceData => {
      if (faceData) setNidFacePreview(faceData);
    });

    setTimeout(() => document.getElementById('ocrPreviewPanel')?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 80);
    stopCamera();
  }

  async function cropNidFaceFromCard(cardDataUrl) {
    return new Promise(resolve => {
      const img = new Image();
      img.onload = function() {
        const canvas = document.createElement('canvas');

        // Cambodian NID portrait is usually on the left side of the front card.
        // This fixed crop is safe and light; admin can still upload another photo.
        const sx = Math.floor(img.naturalWidth * 0.055);
        const sy = Math.floor(img.naturalHeight * 0.155);
        const sw = Math.floor(img.naturalWidth * 0.260);
        const sh = Math.floor(img.naturalHeight * 0.560);

        canvas.width = 420;
        canvas.height = 520;

        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(img, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);

        resolve(canvas.toDataURL('image/jpeg', 0.88));
      };
      img.onerror = () => resolve('');
      img.src = cardDataUrl;
    });
  }

  function setNidFacePreview(faceDataUrl) {
    const hidden = document.getElementById('nidFacePhotoBase64');
    const img = document.getElementById('nidFacePreviewImg');
    const avatar = document.getElementById('avatarPreview');

    // Keep cropped face silently for saving, but do not show extra text/card in scanner UI.
    if (hidden) hidden.value = faceDataUrl;
    if (img) img.src = faceDataUrl;

    // Show it only in the main customer avatar preview unless user uploaded manual photo.
    if (avatar && !(photoInput && photoInput.files && photoInput.files.length > 0)) {
      avatar.src = faceDataUrl;
    }
  }


  const fileInput = document.getElementById('ocrFileInput');
  const cameraInput = document.getElementById('ocrCameraInput');
  const btnUploadCamera = document.getElementById('btnUploadCamera');
  const btnUploadGallery = document.getElementById('btnUploadGallery');

  btnUploadCamera?.addEventListener('click', () => cameraInput?.click());
  btnUploadGallery?.addEventListener('click', () => fileInput?.click());

  function handleOcrImageFile(file) {
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(event) {
      lastOcrFullImage = event.target.result;
      document.getElementById('ocrPreviewImg').src = event.target.result;
      document.getElementById('ocrPreviewPanel').classList.remove('d-none');
      cropNidFaceFromCard(event.target.result).then(faceData => {
        if (faceData) setNidFacePreview(faceData);
      });
      document.getElementById('btnOcrStart').disabled = false;
      setTimeout(() => document.getElementById('ocrPreviewPanel')?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 80);
    };
    reader.readAsDataURL(file);
  }

  fileInput?.addEventListener('change', function(e) {
    handleOcrImageFile(e.target.files && e.target.files[0]);
  });

  cameraInput?.addEventListener('change', function(e) {
    handleOcrImageFile(e.target.files && e.target.files[0]);
  });

  document.getElementById('btnOcrReset')?.addEventListener('click', function() {
    lastOcrFullImage = '';
    document.getElementById('webcam-pane')?.classList.remove('has-captured');
    document.getElementById('nidFacePreviewBox')?.classList.remove('show');
    const faceHidden = document.getElementById('nidFacePhotoBase64');
    if (faceHidden) faceHidden.value = '';
    document.getElementById('ocrPreviewImg').src = '';
    ['ocrPreviewPanel', 'ocrProgressPanel', 'ocrResultPanel', 'btnOcrSubmit'].forEach(id => document.getElementById(id).classList.add('d-none'));
    const btnStart = document.getElementById('btnOcrStart');
    btnStart.classList.remove('d-none'); btnStart.disabled = true;
    if (document.getElementById('webcam-pane').classList.contains('active')) startCamera();
  });

  // ==========================================
  // ការបញ្ជូនរូបភាពជា FormData
  // ==========================================
  async function tryServerKhmerIdOcr(imageDataUrl) {
    try {
      const formData = new FormData();
      formData.append('image', imageDataUrl);
      formData.append('image_card', imageDataUrl);
      if (lastOcrFullImage) formData.append('image_full', lastOcrFullImage);

      const res = await fetch('customer_id_ocr.php', {
        method: 'POST',
        body: formData
      });
      if (!res.ok) return null;
      const js = await res.json();
      if (js && js.ok && js.data) return js.data; 
      if (js && js.error) { alert("បញ្ហា៖ " + js.error); }
      return null;
    } catch (e) {
      console.warn('AI Server Error:', e);
      return null;
    }
  }

  document.getElementById('btnOcrStart')?.addEventListener('click', async function() {
    const imgElement = document.getElementById('ocrPreviewImg');
    const progressPanel = document.getElementById('ocrProgressPanel'), progressBar = document.getElementById('ocrProgressBar'), progressStatus = document.getElementById('ocrProgressStatus');
    const btnStart = document.getElementById('btnOcrStart'), btnSubmit = document.getElementById('btnOcrSubmit'), resultPanel = document.getElementById('ocrResultPanel');
    
    if (!imgElement || !imgElement.src) return;

    progressPanel.classList.remove('d-none'); resultPanel.classList.add('d-none'); btnSubmit.classList.add('d-none'); btnStart.classList.add('d-none');

    const setProgress = (status, pct) => { if (progressStatus) progressStatus.textContent = status; if (progressBar) progressBar.style.width = pct + '%'; };
    
    try {
      setProgress('កំពុងបញ្ជូនរូបភាពទៅកាន់ AI Scanner...', 40);
      const aiData = await tryServerKhmerIdOcr(imgElement.src);
      
      if (aiData && (aiData.name || aiData.nid)) {
        fillOcrResultFields(aiData);
        resultPanel.classList.remove('d-none'); btnSubmit.classList.remove('d-none');
        setProgress('ទាញយកទិន្នន័យដោយ AI ជោគជ័យ!', 100);
        setTimeout(() => progressPanel.classList.add('d-none'), 800);
      } else {
        btnStart.classList.remove('d-none'); progressPanel.classList.add('d-none');
      }
    } catch (err) {
      alert('កំហុស៖ ' + err.message);
      btnStart.classList.remove('d-none'); progressPanel.classList.add('d-none');
    }
  });

  document.getElementById('btnOcrSubmit')?.addEventListener('click', function() {
    document.getElementById('fullNameInput').value = document.getElementById('ocrName').value.trim();
    document.getElementById('nidInput').value = document.getElementById('ocrNid').value.trim();
    const dob = document.getElementById('ocrDob').value;
    if(dob) document.getElementById('dobPicker')._flatpickr ? document.getElementById('dobPicker')._flatpickr.setDate(dob) : document.getElementById('dobPicker').value = dob;
    document.getElementById('genderSelect').value = document.getElementById('ocrGender').value;
    document.querySelector('textarea[name="address"]').value = document.getElementById('ocrAddress').value.trim();
    scannerModal?.hide();
  });

  function fillOcrResultFields(p) {
    document.getElementById('ocrName').value = p.name || '';
    document.getElementById('ocrNid').value = p.nid || '';
    document.getElementById('ocrDob').value = p.dob || '';
    document.getElementById('ocrGender').value = p.gender || '';
    document.getElementById('ocrAddress').value = p.address || '';
  }

  function captureFullAndCardFromVideo(video, canvas) {
    const sourceW = video.videoWidth || 1280;
    const sourceH = video.videoHeight || 720;

    // 1) Keep full frame for Gemini cross-check.
    canvas.width = 1600;
    canvas.height = Math.round(1600 * (sourceH / sourceW));
    let ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(video, 0, 0, sourceW, sourceH, 0, 0, canvas.width, canvas.height);
    const full = canvas.toDataURL('image/jpeg', 0.90);

    // 2) Auto-detect the real ID card area and crop only the card.
    // This removes black table/background after capture.
    const crop = detectNidCardBox(video);
    const targetW = 1400;
    const targetH = Math.round(targetW * (crop.h / crop.w));

    canvas.width = targetW;
    canvas.height = targetH;
    ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, targetW, targetH);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(video, crop.x, crop.y, crop.w, crop.h, 0, 0, targetW, targetH);

    const card = canvas.toDataURL('image/jpeg', 0.96);
    return { full, card };
  }

  function detectNidCardBox(video) {
    const vw = video.videoWidth || 1280;
    const vh = video.videoHeight || 720;

    // Downscale for fast detection on phone.
    const smallW = 320;
    const smallH = Math.round(smallW * (vh / vw));
    const tmp = document.createElement('canvas');
    tmp.width = smallW;
    tmp.height = smallH;
    const ctx = tmp.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(video, 0, 0, smallW, smallH);

    const img = ctx.getImageData(0, 0, smallW, smallH).data;

    // Search around the visual scanner area, not the whole camera view.
    const minX0 = Math.floor(smallW * 0.04);
    const maxX0 = Math.floor(smallW * 0.96);
    const minY0 = Math.floor(smallH * 0.08);
    const maxY0 = Math.floor(smallH * 0.92);

    let minX = smallW, minY = smallH, maxX = 0, maxY = 0, count = 0;

    // Estimate average brightness to adapt to different lighting.
    let total = 0, n = 0;
    for (let y = minY0; y < maxY0; y += 4) {
      for (let x = minX0; x < maxX0; x += 4) {
        const i = (y * smallW + x) * 4;
        const b = (img[i] * 0.299) + (img[i + 1] * 0.587) + (img[i + 2] * 0.114);
        total += b;
        n++;
      }
    }
    const avg = total / Math.max(1, n);

    // Card is normally much brighter than black/dark background.
    // Use adaptive threshold but keep it within safe range.
    const threshold = Math.max(72, Math.min(145, avg + 34));

    for (let y = minY0; y < maxY0; y++) {
      for (let x = minX0; x < maxX0; x++) {
        const i = (y * smallW + x) * 4;
        const r = img[i], g = img[i + 1], b = img[i + 2];
        const bright = (r * 0.299) + (g * 0.587) + (b * 0.114);
        const maxc = Math.max(r, g, b), minc = Math.min(r, g, b);
        const contrast = maxc - minc;

        // Bright card surface, including green/white card and portrait area.
        // Ignore very saturated colored UI/background.
        if (bright > threshold && contrast < 95) {
          minX = Math.min(minX, x);
          minY = Math.min(minY, y);
          maxX = Math.max(maxX, x);
          maxY = Math.max(maxY, y);
          count++;
        }
      }
    }

    // If detection is weak, use centered fallback crop.
    const minPixels = smallW * smallH * 0.012;
    if (count < minPixels || maxX <= minX || maxY <= minY) {
      return fallbackNidBox(vw, vh);
    }

    // Convert to original video coordinates.
    let x = Math.floor(minX / smallW * vw);
    let y = Math.floor(minY / smallH * vh);
    let w = Math.ceil((maxX - minX) / smallW * vw);
    let h = Math.ceil((maxY - minY) / smallH * vh);

    // Add margin around the detected card.
    const marginX = Math.floor(w * 0.045);
    const marginY = Math.floor(h * 0.075);
    x -= marginX;
    y -= marginY;
    w += marginX * 2;
    h += marginY * 2;

    // Cambodian NID is landscape around 1.58 ratio. Adjust bbox to avoid tight/odd crop.
    const targetRatio = 1.58;
    const ratio = w / Math.max(1, h);
    if (ratio > targetRatio * 1.18) {
      const newH = Math.round(w / targetRatio);
      y -= Math.round((newH - h) / 2);
      h = newH;
    } else if (ratio < targetRatio * 0.82) {
      const newW = Math.round(h * targetRatio);
      x -= Math.round((newW - w) / 2);
      w = newW;
    }

    // Clamp to video boundary.
    x = Math.max(0, x);
    y = Math.max(0, y);
    if (x + w > vw) w = vw - x;
    if (y + h > vh) h = vh - y;

    // If crop is too large, fallback to scanner frame crop.
    if (w > vw * 0.95 || h > vh * 0.85 || w < vw * 0.12 || h < vh * 0.08) {
      return fallbackNidBox(vw, vh);
    }

    return { x, y, w, h };
  }

  function fallbackNidBox(vw, vh) {
    // Fallback is smaller than the old crop, so it still removes most background.
    const w = Math.floor(vw * 0.72);
    const h = Math.floor(w / 1.58);
    const x = Math.floor((vw - w) / 2);
    const y = Math.floor((vh - h) / 2);
    return { x, y, w, h };
  }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>