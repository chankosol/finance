<?php
// /finance/admin/customer_edit.php
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

fin_require_login();
fin_require_permission('edit_customer');
$business_id = fin_require_business();
global $pdo;

function h2($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ===================== DB helpers ===================== */
function fin_table_exists(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
  ");
  $st->execute([$table]);
  return (int)$st->fetchColumn() > 0;
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
function fin_first_col(PDO $pdo, string $table, array $candidates, string $fallback=''): string {
  foreach ($candidates as $c) if ($c && fin_col_exists($pdo, $table, $c)) return $c;
  return $fallback;
}
function fin_public_url(?string $path): string {
  $p = trim((string)$path);
  if ($p === '') return '';
  if (preg_match('#^https?://#i', $p)) return $p;
  if (str_starts_with($p, '/')) return $p;
  return '../' . ltrim($p, '/'); // because we are in /finance/admin/
}
function fin_avatar_by_gender(string $gender): string {
  $g = strtolower(trim($gender));
  if (in_array($g, ['male','m','ប្រុស'], true))   return '../uploads/avatars/male.png';
  if (in_array($g, ['female','f','ស្រី'], true)) return '../uploads/avatars/female.png';
  return '../uploads/avatars/default.png';
}
function fin_norm_gender(string $g): string {
  $g = strtolower(trim($g));
  if (in_array($g, ['male','m','ប្រុស'], true)) return 'male';
  if (in_array($g, ['female','f','ស្រី'], true)) return 'female';
  return '';
}

/* ===================== safety ===================== */
if (!fin_table_exists($pdo, 'customers')) {
  http_response_code(500);
  echo "<h3 style='font-family:Battambang,sans-serif'>Error</h3>
        <p style='font-family:Battambang,sans-serif'>តារាង customers មិនមានក្នុង DB ទេ។</p>";
  exit;
}

/* ===================== detect columns ===================== */
$customersBizCol = fin_col_exists($pdo,'customers','business_id') ? 'business_id' : (fin_col_exists($pdo,'customers','biz_id') ? 'biz_id' : 'business_id');

$colName   = fin_first_col($pdo,'customers',['full_name','name','customer_name'],'full_name');
$colGender = fin_first_col($pdo,'customers',['gender','sex'],'gender');
$colActive = fin_col_exists($pdo,'customers','is_active') ? 'is_active' : '';
$colPhone  = fin_first_col($pdo,'customers',['phone','phone_number','tel'],'phone');
$colPhone2 = fin_first_col($pdo,'customers',['phone2','tel2','phone_alt'],'phone2');
$colDob    = fin_first_col($pdo,'customers',['dob','birth_date','date_of_birth'],'dob');
$colAddr   = fin_first_col($pdo,'customers',['address','addr','home_address'],'address');
$colNote   = fin_first_col($pdo,'customers',['note','notes','remark'],'note');
$colIdCard = fin_first_col($pdo,'customers',['id_card','idcard','national_id'],'id_card');
$colNid    = fin_first_col($pdo,'customers',['nid','national_id_card','nation_id'],'nid');

$colTgChat = fin_first_col($pdo,'customers',['telegram_chat_id','tg_chat_id'],'telegram_chat_id');
$colTgUser = fin_first_col($pdo,'customers',['telegram_username','tg_username'],'telegram_username');

/* photo column (you said it is "photo") but keep detection */
$colPhoto  = fin_first_col($pdo,'customers',['photo','photo_url','avatar','avatar_url','profile_photo','profile_image','image','img'],'photo');

/* ===================== read id ===================== */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header("Location: customers.php"); exit; }

/* ===================== load customer ===================== */
$st = $pdo->prepare("SELECT * FROM customers WHERE id = ? AND {$customersBizCol} = ? LIMIT 1");
$st->execute([$id, $business_id]);
$cust = $st->fetch(PDO::FETCH_ASSOC);
if (!$cust) { header("Location: customers.php"); exit; }

$err = '';
$ok  = (int)($_GET['updated'] ?? 0);

/* ===================== upload config ===================== */
$UPLOAD_DIR_FS  = realpath(__DIR__ . '/../uploads'); // /finance/uploads
if ($UPLOAD_DIR_FS === false) $UPLOAD_DIR_FS = __DIR__ . '/../uploads';
$CUSTOMER_SUBDIR = 'customers';
$TARGET_DIR_FS = rtrim($UPLOAD_DIR_FS, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $CUSTOMER_SUBDIR;

if (!is_dir($TARGET_DIR_FS)) {
  @mkdir($TARGET_DIR_FS, 0775, true);
}

/* ===================== handle POST ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $full_name = trim($_POST['full_name'] ?? '');
  $gender    = fin_norm_gender($_POST['gender'] ?? '');
  $is_active = isset($_POST['is_active']) ? 1 : 0;

  $phone     = trim($_POST['phone'] ?? '');
  $phone2    = trim($_POST['phone2'] ?? '');
  $dob       = trim($_POST['dob'] ?? '');
  $address   = trim($_POST['address'] ?? '');
  $note      = trim($_POST['note'] ?? '');

  $nid       = trim($_POST['nid'] ?? '');
  $id_card   = trim($_POST['id_card'] ?? '');

  $tg_chat_id= trim($_POST['telegram_chat_id'] ?? '');
  $tg_user   = trim($_POST['telegram_username'] ?? '');

  $remove_photo = isset($_POST['remove_photo']) ? 1 : 0;

  if ($full_name === '') {
    $err = 'សូមបញ្ចូលឈ្មោះអតិថិជន។';
  }

  // Normalize DOB: allow empty, or YYYY-MM-DD
  if ($err === '' && $dob !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
      $err = 'ថ្ងៃកំណើត (DOB) ត្រូវជា YYYY-MM-DD។';
    }
  }

  // Upload new photo (optional)
  $newPhotoPath = null; // relative path like "uploads/customers/xxx.jpg"
  if ($err === '' && isset($_FILES['photo']) && is_array($_FILES['photo'])) {
    if ($_FILES['photo']['error'] === UPLOAD_ERR_OK && (int)$_FILES['photo']['size'] > 0) {

      $maxBytes = 2 * 1024 * 1024; // 2MB
      if ((int)$_FILES['photo']['size'] > $maxBytes) {
        $err = 'រូបភាពធំពេក (Max 2MB)។';
      } else {
        $tmp = $_FILES['photo']['tmp_name'];
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo ? (string)finfo_file($finfo, $tmp) : '';
        if ($finfo) finfo_close($finfo);

        $allowed = [
          'image/jpeg' => 'jpg',
          'image/png'  => 'png',
          'image/webp' => 'webp',
        ];
        if (!isset($allowed[$mime])) {
          $err = 'ប្រភេទរូបភាពមិនត្រឹមត្រូវ (Allowed: JPG/PNG/WEBP)។';
        } else {
          $ext = $allowed[$mime];
          $safeBase = 'cust_' . $business_id . '_' . $id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
          $filename = $safeBase . '.' . $ext;
          $destFs   = $TARGET_DIR_FS . DIRECTORY_SEPARATOR . $filename;

          if (!@move_uploaded_file($tmp, $destFs)) {
            $err = 'បរាជ័យក្នុងការផ្ទុករូបភាព។ សូមព្យាយាមម្តងទៀត។';
          } else {
            @chmod($destFs, 0644);
            $newPhotoPath = 'uploads/' . $CUSTOMER_SUBDIR . '/' . $filename; // store relative to /finance/
          }
        }
      }
    }
  }

  if ($err === '') {
    // Build update fields dynamically (only existing cols)
    $sets = [];
    $vals = [];

    if ($colName)   { $sets[] = "`{$colName}` = ?"; $vals[] = $full_name; }
    if ($colGender) { $sets[] = "`{$colGender}` = ?"; $vals[] = $gender; }
    if ($colActive) { $sets[] = "`{$colActive}` = ?"; $vals[] = $is_active; }
    if ($colPhone)  { $sets[] = "`{$colPhone}` = ?"; $vals[] = $phone; }
    if ($colPhone2 && fin_col_exists($pdo,'customers',$colPhone2)) { $sets[] = "`{$colPhone2}` = ?"; $vals[] = $phone2; }
    if ($colDob && fin_col_exists($pdo,'customers',$colDob)) { $sets[] = "`{$colDob}` = ?"; $vals[] = ($dob===''? null : $dob); }
    if ($colAddr)   { $sets[] = "`{$colAddr}` = ?"; $vals[] = $address; }
    if ($colNote)   { $sets[] = "`{$colNote}` = ?"; $vals[] = $note; }

    if ($colNid && fin_col_exists($pdo,'customers',$colNid)) { $sets[] = "`{$colNid}` = ?"; $vals[] = $nid; }
    if ($colIdCard && fin_col_exists($pdo,'customers',$colIdCard)) { $sets[] = "`{$colIdCard}` = ?"; $vals[] = $id_card; }

    if ($colTgChat && fin_col_exists($pdo,'customers',$colTgChat)) { $sets[] = "`{$colTgChat}` = ?"; $vals[] = $tg_chat_id; }
    if ($colTgUser && fin_col_exists($pdo,'customers',$colTgUser)) { $sets[] = "`{$colTgUser}` = ?"; $vals[] = $tg_user; }

    // Photo update logic
    if ($colPhoto && fin_col_exists($pdo,'customers',$colPhoto)) {
      if ($remove_photo) {
        $sets[] = "`{$colPhoto}` = ''";
      } elseif ($newPhotoPath !== null) {
        $sets[] = "`{$colPhoto}` = ?";
        $vals[] = $newPhotoPath;
      }
    }

    if (!$sets) {
      $err = 'មិនមាន field ណាមួយអាច update បានទេ។';
    } else {
      $vals[] = $id;
      $vals[] = $business_id;

      $sql = "UPDATE customers SET " . implode(', ', $sets) . " WHERE id = ? AND {$customersBizCol} = ? LIMIT 1";
      $st = $pdo->prepare($sql);
      $st->execute($vals);

      header("Location: customer_edit.php?id={$id}&updated=1");
      exit;
    }
  }
}

/* reload latest after update attempt (or first load) */
$st = $pdo->prepare("SELECT * FROM customers WHERE id = ? AND {$customersBizCol} = ? LIMIT 1");
$st->execute([$id, $business_id]);
$cust = $st->fetch(PDO::FETCH_ASSOC) ?: $cust;

$valName   = (string)($cust[$colName] ?? '');
$valGender = (string)($colGender ? ($cust[$colGender] ?? '') : '');
$valGender = fin_norm_gender($valGender);

$valActive = (int)($colActive ? ($cust[$colActive] ?? 1) : 1);
$valPhone  = (string)($colPhone ? ($cust[$colPhone] ?? '') : '');
$valPhone2 = (string)($colPhone2 && isset($cust[$colPhone2]) ? $cust[$colPhone2] : '');
$valDob    = (string)($colDob && isset($cust[$colDob]) ? $cust[$colDob] : '');
$valAddr   = (string)($colAddr ? ($cust[$colAddr] ?? '') : '');
$valNote   = (string)($colNote ? ($cust[$colNote] ?? '') : '');

$valNid    = (string)($colNid && isset($cust[$colNid]) ? $cust[$colNid] : '');
$valIdCard = (string)($colIdCard && isset($cust[$colIdCard]) ? $cust[$colIdCard] : '');

$valTgChat = (string)($colTgChat && isset($cust[$colTgChat]) ? $cust[$colTgChat] : '');
$valTgUser = (string)($colTgUser && isset($cust[$colTgUser]) ? $cust[$colTgUser] : '');

$photoDb   = (string)($colPhoto && isset($cust[$colPhoto]) ? $cust[$colPhoto] : '');
$photoUrl  = $photoDb ? fin_public_url($photoDb) : '';
$fallback  = fin_avatar_by_gender($valGender);

// Load Telegram Bot Settings
$stTg = $pdo->prepare("SELECT telegram_bot_token, telegram_bot_username FROM business_settings WHERE business_id = ? LIMIT 1");
$stTg->execute([$business_id]);
$tgSettings = $stTg->fetch(PDO::FETCH_ASSOC) ?: [];
$bot_token = trim($tgSettings['telegram_bot_token'] ?? '');
$bot_username = trim($tgSettings['telegram_bot_username'] ?? '');

if ($bot_token !== '' && $bot_username === '') {
    require_once __DIR__ . '/../includes/followup_helpers.php';
    $bot_username = fin_get_bot_username($bot_token) ?: '';
    if ($bot_username !== '') {
        $pdo->prepare("UPDATE business_settings SET telegram_bot_username = ? WHERE business_id = ?")
            ->execute([$bot_username, $business_id]);
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
  <title>កែអតិថិជន | Finance</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://fonts.googleapis.com/css2?family=Battambang:wght@300;400;700;900&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">

  <style>
    .flatpickr-calendar, .flatpickr-calendar * { font-family:'Battambang',sans-serif !important; font-size:.92rem; }
    :root{
      --bg:#f3f4f6; --card:#fff; --ink:#0f172a; --muted:#64748b;
      --line:#e5e7eb;
    }
    body{font-family:'Battambang',sans-serif;background:var(--bg); color:var(--ink);}
    .page-title{font-weight:900;}
    .sub{color:var(--muted); font-size:.92rem;}

    .cardx{
      background:var(--card);
      border:1px solid rgba(15,23,42,.08);
      border-radius:18px;
      box-shadow:none;
    }

    .headbar{
      display:flex; align-items:flex-start; justify-content:space-between; gap:12px;
      margin-bottom:12px;
    }
    .headbar .left .ttl{
      font-weight:900; font-size:1.2rem;
      display:flex; align-items:center; gap:.6rem;
    }
    .headbar .left .ttl i{ color:#0b5ed7; }
    .btn-soft{
      border:1px solid rgba(15,23,42,.12);
      background:#fff;
      border-radius:12px;
    }

    .avatar-wrap{
      display:flex; align-items:center; gap:12px;
      padding:12px;
      border:1px dashed rgba(15,23,42,.14);
      border-radius:16px;
      background:linear-gradient(180deg,#f8fafc,#fff);
    }
    .avatar{
      width:54px;height:54px;border-radius:999px;
      border:1px solid rgba(15,23,42,.12);
      background:#fff;
      overflow:hidden;
      display:flex;align-items:center;justify-content:center;
      flex:0 0 auto;
    }
    .avatar img{width:100%;height:100%;object-fit:cover;}
    .help{color:var(--muted); font-size:.88rem;}
    .req{color:#dc2626; font-weight:900;}

    /* compact spacing */
    .form-label{font-weight:900; margin-bottom:.25rem;}
    .form-control,.form-select{border-radius:12px;}
    textarea.form-control{min-height:92px;}

    /* icon inputs */
    .ig .input-group-text{
      border-radius:12px 0 0 12px;
      background:#f8fafc;
      border-color:rgba(15,23,42,.12);
      color:#0f172a;
    }
    .ig .form-control,.ig .form-select{
      border-left:0;
    }

    /* switches compact */
    .form-switch .form-check-input{ width:44px; height:22px; }
    .form-switch .form-check-label{ font-weight:900; }

    /* save bar */
    .savebar{
      display:flex; gap:10px; justify-content:flex-end; align-items:center;
      padding-top:10px; border-top:1px solid rgba(15,23,42,.10);
      margin-top:12px;
    }

    @media (max-width: 576px){
      .headbar{ flex-direction:column; align-items:stretch; }
      .savebar{ flex-direction:column; align-items:stretch; }
      .savebar .btn{ width:100%; }
    }
  </style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="container py-4">

  <div class="headbar">
    <div class="left">
      <div class="ttl"><i class="bi bi-person-gear"></i> កែអតិថិជន</div>
      <div class="sub">Edit customer (photo + default avatar by gender)</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <button type="button" class="btn btn-primary" id="btnScanIdCard" style="border-radius:12px;">
        <i class="bi bi-camera me-1"></i> ស្កែនអត្តសញ្ញាណប័ណ្ណ
      </button>
      <a class="btn btn-soft" href="customers.php" title="ត្រឡប់">
        <i class="bi bi-arrow-left"></i> ត្រឡប់
      </a>
    </div>
  </div>

  <?php if ($ok): ?>
    <div class="alert alert-success d-flex align-items-center gap-2" style="border-radius:14px;">
      <i class="bi bi-check-circle-fill"></i>
      បានកែប្រែរួចរាល់។
    </div>
  <?php endif; ?>

  <?php if ($err !== ''): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2" style="border-radius:14px;">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <?= h2($err) ?>
    </div>
  <?php endif; ?>

  <div class="cardx p-3 p-lg-4">

    <form method="post" enctype="multipart/form-data" autocomplete="off">
      <!-- avatar + upload -->
      <div class="avatar-wrap mb-3">
        <div class="avatar">
          <?php if ($photoUrl !== ''): ?>
            <img src="<?= h2($photoUrl) ?>" alt="photo" onerror="this.src='<?= h2($fallback) ?>'">
          <?php else: ?>
            <img src="<?= h2($fallback) ?>" alt="avatar">
          <?php endif; ?>
        </div>

        <div class="flex-grow-1">
          <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
            <div class="fw-black" style="font-weight:900;"><i class="bi bi-image me-1"></i> រូបថត (Photo)</div>
            <?php if ($photoDb !== '' && $colPhoto): ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="remove_photo" name="remove_photo" value="1">
                <label class="form-check-label" for="remove_photo">លុបរូបថត</label>
              </div>
            <?php endif; ?>
          </div>

          <input class="form-control mt-2" type="file" name="photo" accept="image/jpeg,image/png,image/webp">
          <div class="help mt-1">Allowed: JPG/PNG/WEBP • Max 2MB • If not upload → show avatar by gender</div>
        </div>
      </div>

      <!-- compact grid -->
      <div class="row g-3">
        <div class="col-12 col-lg-6">
          <label class="form-label">ឈ្មោះអតិថិជន <span class="req">*</span></label>
          <div class="input-group ig">
            <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
            <input type="text" class="form-control" name="full_name" value="<?= h2($valName) ?>" required>
          </div>
        </div>

        <div class="col-6 col-lg-3">
          <label class="form-label">ភេទ (Gender)</label>
          <div class="input-group ig">
            <span class="input-group-text"><i class="bi bi-gender-ambiguous"></i></span>
            <select class="form-select" name="gender" <?= $colGender ? '' : 'disabled'; ?>>
              <option value="">-- មិនកំណត់ --</option>
              <option value="male"   <?= $valGender==='male'?'selected':''; ?>>ប្រុស</option>
              <option value="female" <?= $valGender==='female'?'selected':''; ?>>ស្រី</option>
            </select>
          </div>
        </div>

        <div class="col-6 col-lg-3">
          <label class="form-label">សកម្ម (Active)</label>
          <div class="d-flex align-items-center gap-2 mt-1">
            <div class="form-check form-switch m-0">
              <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= $valActive ? 'checked' : '' ?> <?= $colActive ? '' : 'disabled'; ?>>
              <label class="form-check-label" for="is_active"><?= $valActive ? 'បើក' : 'បិទ' ?></label>
            </div>
          </div>
        </div>

        <div class="col-12 col-md-6 col-lg-3">
          <label class="form-label">ទូរស័ព្ទ (Phone)</label>
          <div class="input-group ig">
            <span class="input-group-text"><i class="bi bi-telephone-fill"></i></span>
            <input type="text" class="form-control" name="phone" value="<?= h2($valPhone) ?>">
          </div>
        </div>

        <div class="col-12 col-md-6 col-lg-3">
          <label class="form-label">ទូរស័ព្ទ 2 (Phone2)</label>
          <div class="input-group ig">
            <span class="input-group-text"><i class="bi bi-telephone"></i></span>
            <input type="text" class="form-control" name="phone2" value="<?= h2($valPhone2) ?>">
          </div>
        </div>

        <div class="col-12 col-md-6 col-lg-3">
          <label class="form-label">ថ្ងៃកំណើត (DOB)</label>
          <div class="input-group ig">
            <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
            <input type="text" id="dobPicker" class="form-control" name="dob" value="<?= h2($valDob) ?>" placeholder="ថ្ងៃ-ខែ-ឆ្នាំ">
          </div>
          <div class="help mt-1">Format: YYYY-MM-DD</div>
        </div>

        <div class="col-12 col-md-6 col-lg-3">
          <label class="form-label">NID (កាតសញ្ជាតិ)</label>
          <div class="input-group ig">
            <span class="input-group-text"><i class="bi bi-person-vcard"></i></span>
            <input type="text" class="form-control" name="nid" value="<?= h2($valNid) ?>">
          </div>
        </div>

        <div class="col-12 col-md-6 col-lg-6">
          <label class="form-label">Telegram Username</label>
          <div class="input-group ig">
            <span class="input-group-text"><i class="bi bi-telegram"></i></span>
            <input type="text" class="form-control" name="telegram_username" id="telegram_username" value="<?= h2($valTgUser) ?>" placeholder="ឧ: sok_telegram">
          </div>
        </div>

        <div class="col-12 col-md-6 col-lg-6">
          <label class="form-label">Telegram Chat ID</label>
          <div class="input-group ig">
            <span class="input-group-text"><i class="bi bi-hash"></i></span>
            <input type="text" class="form-control" name="telegram_chat_id" id="telegram_chat_id" value="<?= h2($valTgChat) ?>" placeholder="ឧ: 123456789">
            <?php if ($bot_token !== '' && $bot_username !== ''): ?>
              <button class="btn btn-outline-primary" type="button" id="btnTgQr" onclick="openTgQrModal()" title="ស្កេន QR យក Chat ID"><i class="bi bi-qr-code"></i></button>
            <?php endif; ?>
          </div>
          <div class="help mt-1" style="font-size:0.78rem;"><i class="bi bi-info-circle me-1"></i>ចុចលើប៊ូតុងQR ដើម្បីស្កេន</div>
        </div>

        <div class="col-12">
          <label class="form-label">អាសយដ្ឋាន (Address)</label>
          <div class="input-group ig">
            <span class="input-group-text"><i class="bi bi-geo-alt-fill"></i></span>
            <textarea class="form-control" name="address" rows="2"><?= h2($valAddr) ?></textarea>
          </div>
        </div>

        <div class="col-12">
          <label class="form-label">កំណត់ចំណាំ (Note)</label>
          <div class="input-group ig">
            <span class="input-group-text"><i class="bi bi-journal-text"></i></span>
            <textarea class="form-control" name="note" rows="2"><?= h2($valNote) ?></textarea>
          </div>
        </div>
      </div>

      <div class="savebar">
        <a href="customers.php" class="btn btn-outline-secondary" style="border-radius:12px;">
          <i class="bi bi-x-circle me-1"></i> បោះបង់
        </a>
        <button type="submit" class="btn btn-primary" style="border-radius:12px;">
          <i class="bi bi-save2 me-1"></i> រក្សាទុក
        </button>
      </div>

    </form>

  </div>
</div>

<!-- ID Card Scanner Modal -->
<div class="modal fade" id="scannerModal" tabindex="-1" aria-labelledby="scannerModalLabel" aria-hidden="true" style="z-index: 1060;">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content" style="border-radius: 18px; overflow: hidden; border: none; box-shadow: 0 15px 30px rgba(0,0,0,0.18);">
      <div class="modal-header bg-primary text-white py-3">
        <h5 class="modal-title fw-bold" id="scannerModalLabel"><i class="bi bi-camera me-2"></i> ស្កែនអត្តសញ្ញាណប័ណ្ណ (National ID Scanner)</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" id="btnScannerClose"></button>
      </div>
      <div class="modal-body p-4">
        <!-- Selector Tabs -->
        <ul class="nav nav-pills nav-justified mb-3" id="scannerTabs" role="tablist" style="background: #f1f5f9; padding: 4px; border-radius: 12px;">
          <li class="nav-item" role="presentation">
            <button class="nav-link active fw-bold py-2" id="webcam-tab" data-bs-toggle="tab" data-bs-target="#webcam-pane" type="button" role="tab" aria-selected="true" style="border-radius: 8px;"><i class="bi bi-webcam me-1"></i> កាមេរ៉ា (Webcam)</button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold py-2" id="upload-tab" data-bs-toggle="tab" data-bs-target="#upload-pane" type="button" role="tab" aria-selected="false" style="border-radius: 8px;"><i class="bi bi-upload me-1"></i> ផ្ទុករូបភាព (Upload Image)</button>
          </li>
        </ul>

        <div class="tab-content" id="scannerTabsContent">
          <!-- Webcam Pane -->
          <div class="tab-pane fade show active text-center" id="webcam-pane" role="tabpanel">
            <div class="position-relative bg-dark rounded-4 overflow-hidden mb-3 mx-auto" style="max-width: 480px; aspect-ratio: 4/3; border: 2px solid #e2e8f0;">
              <video id="scannerVideo" autoplay playsinline style="width: 100%; height: 100%; object-fit: cover;"></video>
              <canvas id="scannerCanvas" class="d-none" width="640" height="480"></canvas>
              <!-- Card Frame Overlay -->
              <div class="position-absolute start-50 top-50 translate-middle border border-2 border-primary rounded-3" style="width: 85%; height: 65%; box-shadow: 0 0 0 9999px rgba(0,0,0,0.5); pointer-events: none;">
                <div class="position-absolute top-50 start-50 translate-middle text-white small opacity-75 fw-bold text-center" style="text-shadow: 1px 1px 2px rgba(0,0,0,0.8);">ដាក់អត្តសញ្ញាណប័ណ្ណក្នុងប្រអប់នេះ</div>
              </div>
            </div>
            <div class="d-flex justify-content-center gap-2">
              <button type="button" class="btn btn-outline-secondary" id="btnToggleCamera" style="border-radius:12px;"><i class="bi bi-arrow-repeat me-1"></i> ប្តូរកាមេរ៉ា</button>
              <button type="button" class="btn btn-primary px-4 fw-bold" id="btnCapturePhoto" style="border-radius:12px;"><i class="bi bi-camera-fill me-1"></i> ថតរូប (Capture)</button>
            </div>
          </div>

          <!-- Upload Pane -->
          <div class="tab-pane fade" id="upload-pane" role="tabpanel">
            <div class="border border-2 border-dashed rounded-4 p-4 text-center mb-3 bg-light" id="dragDropArea" style="cursor: pointer; border-radius:12px;">
              <i class="bi bi-image-fill text-muted display-4 mb-2"></i>
              <h6 class="fw-bold">អូសទម្លាក់រូបភាពនៅទីនេះ ឬ ចុចដើម្បីជ្រើសរើស</h6>
              <p class="text-muted small mb-0">គាំទ្រ JPG, PNG, WEBP</p>
              <input type="file" id="ocrFileInput" class="d-none" accept="image/*">
            </div>
          </div>
        </div>

        <!-- Preview Panel -->
        <div id="ocrPreviewPanel" class="d-none mb-3">
          <h6 class="fw-bold"><i class="bi bi-eye me-1"></i> រូបភាពដែលបានជ្រើសរើស/ថត</h6>
          <div class="text-center bg-light p-2 rounded-4 border">
            <img id="ocrPreviewImg" src="" style="max-height: 250px; max-width: 100%; object-fit: contain; border-radius: 8px;">
          </div>
        </div>

        <!-- Progress bar -->
        <div id="ocrProgressPanel" class="d-none mb-3">
          <div class="d-flex justify-content-between mb-1">
            <span id="ocrProgressStatus" class="fw-bold text-primary small">កំពុងស្កែន...</span>
            <span id="ocrProgressPercent" class="fw-bold text-primary small">0%</span>
          </div>
          <div class="progress" style="height: 10px; border-radius: 999px;">
            <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" id="ocrProgressBar" role="progressbar" style="width: 0%; border-radius: 999px;"></div>
          </div>
        </div>

        <!-- Extracted Fields -->
        <div id="ocrResultPanel" class="d-none mt-3">
          <h6 class="fw-bold text-success border-bottom pb-1"><i class="bi bi-check2-circle me-1"></i> ព័ត៌មានដែលស្រង់បាន (សូមត្រួតពិនិត្យ)</h6>
          <div class="row g-2">
            <div class="col-12 col-md-6">
              <label class="form-label small mb-1">ឈ្មោះអតិថិជន (Name)</label>
              <input type="text" id="ocrName" class="form-control form-control-sm" style="border-radius:8px;">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label small mb-1">លេខអត្តសញ្ញាណប័ណ្ណ (NID)</label>
              <input type="text" id="ocrNid" class="form-control form-control-sm" style="border-radius:8px;">
            </div>
            <div class="col-6 col-md-6">
              <label class="form-label small mb-1">ថ្ងៃខែឆ្នាំកំណើត (DOB)</label>
              <input type="date" id="ocrDob" class="form-control form-control-sm" style="border-radius:8px;">
            </div>
            <div class="col-6 col-md-6">
              <label class="form-label small mb-1">ភេទ (Gender)</label>
              <select id="ocrGender" class="form-select form-select-sm" style="border-radius:8px;">
                <option value="">-- មិនកំណត់ --</option>
                <option value="male">ប្រុស (Male)</option>
                <option value="female">ស្រី (Female)</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label small mb-1">អាសយដ្ឋាន (Address)</label>
              <textarea id="ocrAddress" class="form-control form-control-sm" rows="2" style="border-radius:8px;"></textarea>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer bg-light py-3 border-top d-flex justify-content-between">
        <button type="button" class="btn btn-outline-secondary" id="btnOcrReset" style="border-radius:12px;"><i class="bi bi-arrow-counterclockwise me-1"></i> ស្កែនឡើងវិញ</button>
        <div>
          <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal" style="border-radius:12px;">បោះបង់</button>
          <button type="button" class="btn btn-primary d-none" id="btnOcrSubmit" style="border-radius:12px;"><i class="bi bi-check-lg me-1"></i> បំពេញព័ត៌មាន (Confirm)</button>
          <button type="button" class="btn btn-success" id="btnOcrStart" disabled style="border-radius:12px;"><i class="bi bi-cpu me-1"></i> ស្រង់ទិន្នន័យ (Extract)</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const btnScan = document.getElementById('btnScanIdCard');
  const scannerModalEl = document.getElementById('scannerModal');
  let scannerModal = null;
  if (scannerModalEl) {
    scannerModal = new bootstrap.Modal(scannerModalEl);
  }

  let localStream = null;
  let currentFacingMode = 'environment';

  // Open Scanner Modal
  btnScan?.addEventListener('click', function() {
    scannerModal?.show();
    const webcamTab = document.getElementById('webcam-tab');
    if (webcamTab) {
      bootstrap.Tab.getInstance(webcamTab)?.show() || new bootstrap.Tab(webcamTab).show();
    }
    startCamera();
  });

  // Handle Tab Switch
  document.getElementById('webcam-tab')?.addEventListener('shown.bs.tab', startCamera);
  document.getElementById('upload-tab')?.addEventListener('shown.bs.tab', stopCamera);

  // Close Modal cleanup
  scannerModalEl?.addEventListener('hidden.bs.abstract.modal', stopCamera);
  scannerModalEl?.addEventListener('hidden.bs.modal', stopCamera);
  document.getElementById('btnScannerClose')?.addEventListener('click', stopCamera);

  async function startCamera() {
    if (localStream) {
      localStream.getTracks().forEach(track => track.stop());
    }
    const video = document.getElementById('scannerVideo');
    if (!video) return;

    try {
      const constraints = {
        video: {
          facingMode: currentFacingMode,
          width: { ideal: 1280 },
          height: { ideal: 720 }
        },
        audio: false
      };
      localStream = await navigator.mediaDevices.getUserMedia(constraints);
      video.srcObject = localStream;
    } catch (err) {
      console.error("Camera error: ", err);
      try {
        localStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
        video.srcObject = localStream;
      } catch (err2) {
        console.error("Camera fallback error: ", err2);
      }
    }
  }

  function stopCamera() {
    if (localStream) {
      localStream.getTracks().forEach(track => track.stop());
      localStream = null;
    }
  }

  // Toggle Camera
  document.getElementById('btnToggleCamera')?.addEventListener('click', function() {
    currentFacingMode = (currentFacingMode === 'user') ? 'environment' : 'user';
    startCamera();
  });

  // Capture Photo
  document.getElementById('btnCapturePhoto')?.addEventListener('click', function() {
    const video = document.getElementById('scannerVideo');
    const canvas = document.getElementById('scannerCanvas');
    if (!video || !canvas) return;

    const ctx = canvas.getContext('2d');
    canvas.width = video.videoWidth || 640;
    canvas.height = video.videoHeight || 480;
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    
    const dataUrl = canvas.toDataURL('image/jpeg');
    document.getElementById('ocrPreviewImg').src = dataUrl;
    document.getElementById('ocrPreviewPanel').classList.remove('d-none');
    document.getElementById('btnOcrStart').disabled = false;
    
    stopCamera();
  });

  // File Upload Upload Area
  const fileInput = document.getElementById('ocrFileInput');
  const dragDropArea = document.getElementById('dragDropArea');

  dragDropArea?.addEventListener('click', () => fileInput?.click());

  fileInput?.addEventListener('change', function(e) {
    const file = e.target.files && e.target.files[0];
    if (file) {
      const reader = new FileReader();
      reader.onload = function(event) {
        document.getElementById('ocrPreviewImg').src = event.target.result;
        document.getElementById('ocrPreviewPanel').classList.remove('d-none');
        document.getElementById('btnOcrStart').disabled = false;
      };
      reader.readAsDataURL(file);
    }
  });

  // Reset OCR Scan
  document.getElementById('btnOcrReset')?.addEventListener('click', function() {
    document.getElementById('ocrPreviewImg').src = '';
    document.getElementById('ocrPreviewPanel').classList.add('d-none');
    document.getElementById('ocrProgressPanel').classList.add('d-none');
    document.getElementById('ocrResultPanel').classList.add('d-none');
    document.getElementById('btnOcrSubmit').classList.add('d-none');
    
    const btnStart = document.getElementById('btnOcrStart');
    btnStart.classList.remove('d-none');
    btnStart.disabled = true;

    if (document.getElementById('webcam-pane').classList.contains('active')) {
      startCamera();
    }
  });

  // Start OCR Extraction
  document.getElementById('btnOcrStart')?.addEventListener('click', async function() {
    const imgElement = document.getElementById('ocrPreviewImg');
    const progressPanel = document.getElementById('ocrProgressPanel');
    const progressBar = document.getElementById('ocrProgressBar');
    const progressStatus = document.getElementById('ocrProgressStatus');
    const progressPercent = document.getElementById('ocrProgressPercent');
    const btnStart = document.getElementById('btnOcrStart');
    
    if (!imgElement || !imgElement.src) return;

    progressPanel.classList.remove('d-none');
    btnStart.classList.add('d-none');
    
    try {
      progressStatus.textContent = 'កំពុងដំណើរការម៉ាស៊ីនស្កែន (Initializing Engine)...';
      progressBar.style.width = '15%';
      progressPercent.textContent = '15%';
      
      const worker = await Tesseract.createWorker({
        logger: m => {
          if (m.status === 'recognizing text') {
            const progress = Math.round(m.progress * 100);
            progressBar.style.width = progress + '%';
            progressPercent.textContent = progress + '%';
            progressStatus.textContent = 'កំពុងវិភាគកាត (Scanning card)...';
          }
        }
      });
      
      await worker.loadLanguage('eng+khm');
      await worker.initialize('eng+khm');
      
      progressStatus.textContent = 'កំពុងស្រង់ទិន្នន័យ (Extracting text)...';
      const { data: { text } } = await worker.recognize(imgElement.src);
      await worker.terminate();
      
      const parsed = parseIDText(text);
      
      document.getElementById('ocrName').value = parsed.name;
      document.getElementById('ocrNid').value = parsed.nid;
      document.getElementById('ocrDob').value = parsed.dob;
      document.getElementById('ocrGender').value = parsed.gender;
      document.getElementById('ocrAddress').value = parsed.address;
      
      document.getElementById('ocrResultPanel').classList.remove('d-none');
      document.getElementById('btnOcrSubmit').classList.remove('d-none');
      progressPanel.classList.add('d-none');
      
    } catch (err) {
      console.error(err);
      alert("កំហុសក្នុងការស្កែន៖ " + err.message);
      btnStart.classList.remove('d-none');
      progressPanel.classList.add('d-none');
    }
  });

  // Confirm Fill Form
  document.getElementById('btnOcrSubmit')?.addEventListener('click', function() {
    const ocrName = document.getElementById('ocrName').value.trim();
    const ocrNid = document.getElementById('ocrNid').value.trim();
    const ocrDob = document.getElementById('ocrDob').value;
    const ocrGender = document.getElementById('ocrGender').value;
    const ocrAddress = document.getElementById('ocrAddress').value.trim();

    const nameInput = document.querySelector('input[name="full_name"]');
    const nidInput = document.querySelector('input[name="nid"]');
    const dobInput = document.querySelector('input[name="dob"]');
    const genderSelect = document.querySelector('select[name="gender"]');
    const addressArea = document.querySelector('textarea[name="address"]');

    if (nameInput && ocrName) nameInput.value = ocrName;
    if (nidInput && ocrNid) {
      nidInput.value = ocrNid;
      nidInput.dispatchEvent(new Event('input'));
    }
    if (dobInput && ocrDob) {
      if (dobInput._flatpickr) {
        dobInput._flatpickr.setDate(ocrDob);
      } else {
        dobInput.value = ocrDob;
      }
    }
    if (genderSelect && ocrGender) {
      genderSelect.value = ocrGender;
      genderSelect.dispatchEvent(new Event('change'));
    }
    if (addressArea && ocrAddress) addressArea.value = ocrAddress;

    scannerModal?.hide();
  });

  function parseIDText(text) {
    const lines = text.split('\n').map(line => line.trim()).filter(line => line.length > 0);
    let name = '';
    let nid = '';
    let dob = '';
    let gender = '';
    let address = '';
    
    // NID: 9-digit sequence
    for (let line of lines) {
      const digits = line.replace(/\D+/g, '');
      if (digits.length === 9) {
        nid = digits;
        break;
      }
    }
    
    // DOB: DD.MM.YYYY or similar
    const dateRegex = /\b(\d{2})[\.\/\-](\d{2})[\.\/\-](\d{4})\b/;
    for (let line of lines) {
      const match = line.match(dateRegex);
      if (match) {
        const day = match[1];
        const month = match[2];
        const year = match[3];
        dob = `${year}-${month}-${day}`;
        break;
      }
    }
    
    // Gender
    const sexMatch = text.match(/Sex\s*(?:[^\w\s]*)\s*([MF])/i);
    if (sexMatch) {
      const s = sexMatch[1].toUpperCase();
      if (s === 'M') gender = 'male';
      if (s === 'F') gender = 'female';
    } else {
      if (text.includes('ប្រុស') || text.includes('MALE') || text.includes('Male')) {
        gender = 'male';
      } else if (text.includes('ស្រី') || text.includes('FEMALE') || text.includes('Female')) {
        gender = 'female';
      }
    }
    
    // Name
    const noise = [
      'KINGDOM', 'CAMBODIA', 'IDENTITY', 'CARD', 'NATIONAL', 'SEX', 'DATE', 'BIRTH', 
      'RESIDENCE', 'SIGNATURE', 'AUTHORITY', 'HOLDER', 'MINISTRY', 'INTERIOR', 
      'OFFICIAL', 'KHMR', 'KHM', 'KHMER', 'SURNAME', 'GIVEN', 'NAME', 'ID', 'NO'
    ];
    
    const uppercaseNameRegex = /^[A-Z]{3,}\s+[A-Z]{3,}(?:\s+[A-Z]{3,})*$/;
    for (let line of lines) {
      const cleanLine = line.replace(/[,.]/g, '').trim();
      if (uppercaseNameRegex.test(cleanLine)) {
        const words = cleanLine.split(/\s+/);
        const containsNoise = words.some(w => noise.includes(w));
        if (!containsNoise) {
          name = cleanLine;
          break;
        }
      }
    }
    
    // Address
    const addrKeywords = ['ភូមិ', 'ឃុំ', 'សង្កាត់', 'ស្រុក', 'ខណ្ឌ', 'ខេត្ត', 'ក្រុង', 'ស្នាក់នៅ'];
    const addrLines = [];
    for (let line of lines) {
      const hasKeyword = addrKeywords.some(kw => line.includes(kw));
      if (hasKeyword) {
        addrLines.push(line);
      }
    }
    if (addrLines.length > 0) {
      address = addrLines.join(' ');
    }
    
    return { name, nid, dob, gender, address };
  }
});
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

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrious/4.0.2/qrious.min.js"></script>
<script>
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

function selectTelegramUser(chatId, username, updateId) {
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

async function deleteTelegramWebhook(btn) {
  if (!confirm('តើអ្នកប្រាកដជាចង់លុប Webhook របស់ Bot នេះមែនទេ?')) {
    return;
  }
  const originalHtml = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> កំពុងលុប...';
  
  try {
    const res = await fetch('ajax_telegram_delete_webhook.php', {
      method: 'POST',
      credentials: 'same-origin'
    });
    const js = await res.json();
    if (js.ok) {
      alert(js.message || 'បានលុប Webhook ជោគជ័យ!');
      loadRecentChats();
    } else {
      alert(js.error || 'បរាជ័យក្នុងការលុប Webhook');
      btn.disabled = false;
      btn.innerHTML = originalHtml;
    }
  } catch (err) {
    console.error(err);
    alert('កំហុសប្រព័ន្ធ៖ ' + err.message);
    btn.disabled = false;
    btn.innerHTML = originalHtml;
  }
}
</script>

<!-- Flatpickr JS -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script>
  (function() {
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
  })();
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
