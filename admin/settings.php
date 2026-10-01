<?php
// /finance/admin/settings.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

fin_require_login();
fin_require_permission('view_settings');
$business_id = fin_require_business();
global $pdo;

/* ✅ Cambodia timezone */
if (function_exists('date_default_timezone_set')) {
  date_default_timezone_set('Asia/Phnom_Penh');
}

/* ✅ safe html */
if (!function_exists('h2')) {
  function h2($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
/* some of your pages use h() in helpers.php, keep fallback */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* ===================== DB helpers ===================== */
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

/* ===================== page state ===================== */
$errors  = [];
$success = '';

/* ------------------ Dynamic Migrations ------------------ */
if (fin_table_exists($pdo, 'business_settings')) {
  if (!fin_col_exists($pdo, 'business_settings', 'telegram_bot_token')) {
    $pdo->exec("ALTER TABLE business_settings ADD telegram_bot_token VARCHAR(255) NULL;");
  }
  if (!fin_col_exists($pdo, 'business_settings', 'telegram_template')) {
    $pdo->exec("ALTER TABLE business_settings ADD telegram_template TEXT NULL;");
  }
  if (!fin_col_exists($pdo, 'business_settings', 'telegram_followup_days')) {
    $pdo->exec("ALTER TABLE business_settings ADD telegram_followup_days INT DEFAULT 0;");
  }
  if (!fin_col_exists($pdo, 'business_settings', 'telegram_owner_chat_id')) {
    $pdo->exec("ALTER TABLE business_settings ADD telegram_owner_chat_id VARCHAR(255) NULL;");
  }
  if (!fin_col_exists($pdo, 'business_settings', 'telegram_enable_daily_report')) {
    $pdo->exec("ALTER TABLE business_settings ADD telegram_enable_daily_report TINYINT DEFAULT 0;");
  }
  if (!fin_col_exists($pdo, 'business_settings', 'telegram_bot_username')) {
    $pdo->exec("ALTER TABLE business_settings ADD telegram_bot_username VARCHAR(255) NULL;");
  }
}

/* ------------------ Validate tables ------------------ */
if (!fin_table_exists($pdo, 'business_settings')) {
  $errors[] = "តារាង business_settings មិនមានទេ។ សូមបង្កើតតារាងនេះជាមុន។";
}
if (!fin_table_exists($pdo, 'dropdown_items')) {
  $errors[] = "តារាង dropdown_items មិនមានទេ។ សូមបង្កើតតារាងនេះជាមុន។";
}

/* ------------------ Columns detection ------------------ */
$bsHasCompany = fin_col_exists($pdo,'business_settings','company_name');
$bsHasPhone   = fin_col_exists($pdo,'business_settings','phone');
$bsHasAddr    = fin_col_exists($pdo,'business_settings','address');
$bsHasLogo    = fin_col_exists($pdo,'business_settings','logo_path');
$bsHasNote    = fin_col_exists($pdo,'business_settings','receipt_note');
$bsHasCreated = fin_col_exists($pdo,'business_settings','created_at');
$bsHasUpdated = fin_col_exists($pdo,'business_settings','updated_at');

$ddHasBiz     = fin_col_exists($pdo,'dropdown_items','business_id');
$ddHasCat     = fin_col_exists($pdo,'dropdown_items','category');
$ddHasCode    = fin_col_exists($pdo,'dropdown_items','code');
$ddHasAct     = fin_col_exists($pdo,'dropdown_items','is_active');
$ddHasEn      = fin_col_exists($pdo,'dropdown_items','label_en');
$ddHasKm      = fin_col_exists($pdo,'dropdown_items','label_km');
$ddHasSort    = fin_col_exists($pdo,'dropdown_items','sort_order');
$ddHasCreated = fin_col_exists($pdo,'dropdown_items','created_at');
$ddHasUpdated = fin_col_exists($pdo,'dropdown_items','updated_at');

if (!$errors && (!$ddHasBiz || !$ddHasCat || !$ddHasCode)) {
  $errors[] = "dropdown_items ត្រូវមាន columns: business_id, category, code។";
}

/* ------------------ Businesses table (optional sync) ------------------ */
$hasBusinesses = fin_table_exists($pdo,'businesses');
$bizHasName    = $hasBusinesses ? fin_col_exists($pdo,'businesses','name') : false;
$bizHasPhone   = $hasBusinesses ? fin_col_exists($pdo,'businesses','phone') : false;
$bizHasAddr    = $hasBusinesses ? fin_col_exists($pdo,'businesses','address') : false;

/* ✅ Set true if you want to sync company_name/phone/address into businesses table */
$SYNC_TO_BUSINESSES = true;

/* ------------------ Load business settings ------------------ */
$company_name = '';
$phone = '';
$address = '';
$logo_path = '';
$receipt_note = '';

/* also load fallback from businesses if settings empty */
$biz_name_fallback = '';
$biz_phone_fallback = '';
$biz_addr_fallback = '';

if (!$errors && $hasBusinesses) {
  try {
    $st = $pdo->prepare("SELECT * FROM businesses WHERE id=? LIMIT 1");
    $st->execute([$business_id]);
    $bz = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $biz_name_fallback  = $bizHasName  ? (string)($bz['name'] ?? '') : '';
    $biz_phone_fallback = $bizHasPhone ? (string)($bz['phone'] ?? '') : '';
    $biz_addr_fallback  = $bizHasAddr  ? (string)($bz['address'] ?? '') : '';
  } catch (Throwable $e) { /* ignore */ }
}

if (!$errors) {
  try {
    $st = $pdo->prepare("SELECT * FROM business_settings WHERE business_id=? LIMIT 1");
    $st->execute([$business_id]);
    $bs = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $company_name = $bsHasCompany ? (string)($bs['company_name'] ?? '') : '';
    $phone        = $bsHasPhone   ? (string)($bs['phone'] ?? '')        : '';
    $address      = $bsHasAddr    ? (string)($bs['address'] ?? '')      : '';
    $logo_path    = $bsHasLogo    ? (string)($bs['logo_path'] ?? '')    : '';
    $receipt_note = $bsHasNote    ? (string)($bs['receipt_note'] ?? '') : '';
    $telegram_bot_token = fin_col_exists($pdo,'business_settings','telegram_bot_token') ? (string)($bs['telegram_bot_token'] ?? '') : '';
    $telegram_template  = fin_col_exists($pdo,'business_settings','telegram_template')  ? (string)($bs['telegram_template'] ?? '') : '';
    $telegram_followup_days = fin_col_exists($pdo,'business_settings','telegram_followup_days') ? (int)($bs['telegram_followup_days'] ?? 0) : 0;
    $telegram_owner_chat_id = fin_col_exists($pdo,'business_settings','telegram_owner_chat_id') ? (string)($bs['telegram_owner_chat_id'] ?? '') : '';
    $telegram_enable_daily_report = fin_col_exists($pdo,'business_settings','telegram_enable_daily_report') ? (int)($bs['telegram_enable_daily_report'] ?? 0) : 0;
    $telegram_bot_username = fin_col_exists($pdo,'business_settings','telegram_bot_username') ? (string)($bs['telegram_bot_username'] ?? '') : '';

    if (defined('FIN_TELEGRAM_BOT_TOKEN') && FIN_TELEGRAM_BOT_TOKEN !== '') {
        $telegram_bot_token = FIN_TELEGRAM_BOT_TOKEN;
        require_once __DIR__ . '/../includes/followup_helpers.php';
        $telegram_bot_username = (string)fin_get_bot_username($telegram_bot_token);
    }

    // fallback if empty
    if ($company_name === '' && $biz_name_fallback !== '')  $company_name = $biz_name_fallback;
    if ($phone === '' && $biz_phone_fallback !== '')        $phone = $biz_phone_fallback;
    if ($address === '' && $biz_addr_fallback !== '')       $address = $biz_addr_fallback;

  } catch (Throwable $e) {
    $errors[] = "Error loading settings: " . $e->getMessage();
  }
}

/* ------------------ Dropdown categories ------------------ */
$CATEGORIES = [
  'loan_type'          => 'ប្រភេទកម្ចី',
  'pawn_type'          => 'ប្រភេទបញ្ចាំ',
  'payment_method'     => 'វិធីបង់ប្រាក់',
  'loan_status_detail' => 'ស្ថានភាពលម្អិត',
  'pawn_status'        => 'ស្ថានភាពបញ្ចាំ',
];

/* ------------------ Current tab/category ------------------ */
$tab = trim((string)($_GET['tab'] ?? 'company'));
if (!in_array($tab, ['company','dropdowns'], true)) $tab = 'company';

$category = trim((string)($_GET['cat'] ?? 'loan_type'));
if (!isset($CATEGORIES[$category])) $category = 'loan_type';

/* ------------------ Actions: toggle/delete dropdown item ------------------ */
$action  = trim((string)($_GET['action'] ?? ''));
$item_id = (int)($_GET['id'] ?? 0);

if (!$errors && $action && $item_id > 0 && $tab === 'dropdowns') {
  try {
    if ($action === 'toggle' && $ddHasAct) {
      $pdo->prepare("
        UPDATE dropdown_items
        SET is_active = IF(COALESCE(is_active,1)=1,0,1)
        WHERE id=? AND business_id=?
      ")->execute([$item_id, $business_id]);

      if ($ddHasUpdated) {
        $pdo->prepare("UPDATE dropdown_items SET updated_at=NOW() WHERE id=? AND business_id=?")
            ->execute([$item_id, $business_id]);
      }
      header("Location: settings.php?tab=dropdowns&cat=".urlencode($category)."&ok=1");
      exit;
    }

    if ($action === 'delete') {
      $pdo->prepare("DELETE FROM dropdown_items WHERE id=? AND business_id=?")
          ->execute([$item_id, $business_id]);
      header("Location: settings.php?tab=dropdowns&cat=".urlencode($category)."&ok=1");
      exit;
    }
  } catch (Throwable $e) {
    $errors[] = "Action error: ".$e->getMessage();
  }
}

/* ------------------ POST handlers ------------------ */
if (!$errors && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $post_type = trim((string)($_POST['post_type'] ?? ''));

  /* =====================
     SAVE COMPANY SETTINGS
  ===================== */
  if ($post_type === 'company') {
    $company_name = trim((string)($_POST['company_name'] ?? ''));
    $phone        = trim((string)($_POST['phone'] ?? ''));
    $address      = trim((string)($_POST['address'] ?? ''));
    $receipt_note = trim((string)($_POST['receipt_note'] ?? ''));
    $telegram_bot_token = trim((string)($_POST['telegram_bot_token'] ?? ''));
    $telegram_bot_username = trim((string)($_POST['telegram_bot_username'] ?? ''));
    $telegram_bot_username = ltrim($telegram_bot_username, '@');
    $telegram_template  = trim((string)($_POST['telegram_template'] ?? ''));
    $telegram_followup_days = (int)($_POST['telegram_followup_days'] ?? 0);
    $telegram_owner_chat_id = trim((string)($_POST['telegram_owner_chat_id'] ?? ''));
    $telegram_enable_daily_report = isset($_POST['telegram_enable_daily_report']) ? 1 : 0;

    if ($telegram_bot_username === '' && $telegram_bot_token !== '') {
      require_once __DIR__ . '/../includes/followup_helpers.php';
      $telegram_bot_username = (string)fin_get_bot_username($telegram_bot_token);
      if ($telegram_bot_username === '') {
        $stOld = $pdo->prepare("SELECT telegram_bot_username FROM business_settings WHERE business_id = ? LIMIT 1");
        $stOld->execute([$business_id]);
        $telegram_bot_username = (string)($stOld->fetchColumn() ?: '');
      }
    }


    // Logo upload (optional)
    $new_logo_path = $logo_path;

    if (!empty($_FILES['logo']['name']) && isset($_FILES['logo']['tmp_name'])) {
      if ($_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $tmp  = $_FILES['logo']['tmp_name'];
        $size = (int)($_FILES['logo']['size'] ?? 0);

        if ($size > 3 * 1024 * 1024) {
          $errors[] = "Logo ធំពេក (max 3MB)";
        } else {
          $finfo = finfo_open(FILEINFO_MIME_TYPE);
          $mime  = finfo_file($finfo, $tmp);
          finfo_close($finfo);

          $allowed = [
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
          ];
          if (!isset($allowed[$mime])) {
            $errors[] = "Logo ត្រូវជា PNG/JPG/WEBP ប៉ុណ្ណោះ";
          } else {
            $ext = $allowed[$mime];

            // store to /finance/uploads/logos
            $dir = realpath(__DIR__ . '/..') . '/uploads/logos';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);

            $fname = "logo_".date('Ymd_His')."_".bin2hex(random_bytes(4)).".".$ext;
            $dest  = $dir . '/' . $fname;

            if (!move_uploaded_file($tmp, $dest)) {
              $errors[] = "Upload logo មិនជោគជ័យ";
            } else {
              // path stored relative to /finance/
              $new_logo_path = "uploads/logos/".$fname;
            }
          }
        }
      } else {
        $errors[] = "Upload logo មានបញ្ហា (error code: ".$_FILES['logo']['error'].")";
      }
    }

    if (!$errors) {
      try {
        // upsert business_settings
        $st = $pdo->prepare("SELECT id FROM business_settings WHERE business_id=? LIMIT 1");
        $st->execute([$business_id]);
        $exists = (int)($st->fetchColumn() ?: 0) > 0;

        if ($exists) {
          $sets = [];
          $vals = [];

          if ($bsHasCompany) { $sets[]="company_name=?"; $vals[]=$company_name; }
          if ($bsHasPhone)   { $sets[]="phone=?";        $vals[]=$phone; }
          if ($bsHasAddr)    { $sets[]="address=?";      $vals[]=$address; }
          if ($bsHasLogo)    { $sets[]="logo_path=?";    $vals[]=$new_logo_path; }
          if ($bsHasNote)    { $sets[]="receipt_note=?"; $vals[]=$receipt_note; }
          if (fin_col_exists($pdo,'business_settings','telegram_bot_token')) { $sets[]="telegram_bot_token=?"; $vals[]=$telegram_bot_token; }
          if (fin_col_exists($pdo,'business_settings','telegram_template'))  { $sets[]="telegram_template=?";  $vals[]=$telegram_template; }
          if (fin_col_exists($pdo,'business_settings','telegram_followup_days')) { $sets[]="telegram_followup_days=?"; $vals[]=$telegram_followup_days; }
          if (fin_col_exists($pdo,'business_settings','telegram_owner_chat_id')) { $sets[]="telegram_owner_chat_id=?"; $vals[]=$telegram_owner_chat_id; }
          if (fin_col_exists($pdo,'business_settings','telegram_enable_daily_report')) { $sets[]="telegram_enable_daily_report=?"; $vals[]=$telegram_enable_daily_report; }
          if (fin_col_exists($pdo,'business_settings','telegram_bot_username')) { $sets[]="telegram_bot_username=?"; $vals[]=$telegram_bot_username; }
          if ($bsHasUpdated) { $sets[]="updated_at=NOW()"; }

          $vals[] = $business_id;

          // If business_settings has almost no cols, still avoid empty SET
          if (!$sets) {
            $pdo->prepare("UPDATE business_settings SET business_id=business_id WHERE business_id=?")->execute([$business_id]);
          } else {
            $pdo->prepare("UPDATE business_settings SET ".implode(', ', $sets)." WHERE business_id=?")
                ->execute($vals);
          }
        } else {
          $cols = ["business_id"];
          $ph   = ["?"];
          $vals = [$business_id];

          if ($bsHasCompany) { $cols[]="company_name"; $ph[]="?"; $vals[]=$company_name; }
          if ($bsHasPhone)   { $cols[]="phone";        $ph[]="?"; $vals[]=$phone; }
          if ($bsHasAddr)    { $cols[]="address";      $ph[]="?"; $vals[]=$address; }
          if ($bsHasLogo)    { $cols[]="logo_path";    $ph[]="?"; $vals[]=$new_logo_path; }
          if ($bsHasNote)    { $cols[]="receipt_note"; $ph[]="?"; $vals[]=$receipt_note; }
          if (fin_col_exists($pdo,'business_settings','telegram_bot_token')) { $cols[]="telegram_bot_token"; $ph[]="?"; $vals[]=$telegram_bot_token; }
          if (fin_col_exists($pdo,'business_settings','telegram_template'))  { $cols[]="telegram_template";  $ph[]="?"; $vals[]=$telegram_template; }
          if (fin_col_exists($pdo,'business_settings','telegram_followup_days')) { $cols[]="telegram_followup_days"; $ph[]="?"; $vals[]=$telegram_followup_days; }
          if (fin_col_exists($pdo,'business_settings','telegram_owner_chat_id')) { $cols[]="telegram_owner_chat_id"; $ph[]="?"; $vals[]=$telegram_owner_chat_id; }
          if (fin_col_exists($pdo,'business_settings','telegram_enable_daily_report')) { $cols[]="telegram_enable_daily_report"; $ph[]="?"; $vals[]=$telegram_enable_daily_report; }
          if (fin_col_exists($pdo,'business_settings','telegram_bot_username')) { $cols[]="telegram_bot_username"; $ph[]="?"; $vals[]=$telegram_bot_username; }
          if ($bsHasCreated) { $cols[]="created_at";   $ph[]="NOW()"; }
          if ($bsHasUpdated) { $cols[]="updated_at";   $ph[]="NOW()"; }

          $pdo->prepare("INSERT INTO business_settings (".implode(',',$cols).") VALUES (".implode(',',$ph).")")
              ->execute($vals);
        }

        // ✅ Sync to businesses (optional)
        if ($SYNC_TO_BUSINESSES && $hasBusinesses) {
          $bizSets = [];
          $bizVals = [];

          if ($bizHasName && $company_name !== '') { $bizSets[] = "name=?";    $bizVals[] = $company_name; }
          if ($bizHasPhone) { $bizSets[] = "phone=?";  $bizVals[] = $phone; }
          if ($bizHasAddr)  { $bizSets[] = "address=?";$bizVals[] = $address; }

          if ($bizSets) {
            $bizVals[] = $business_id;
            $pdo->prepare("UPDATE businesses SET ".implode(', ', $bizSets)." WHERE id=?")
                ->execute($bizVals);
          }
        }

        $logo_path = $new_logo_path;

        // ✅ PRG redirect (avoid resubmit / fix blank page from headers later)
        header("Location: settings.php?tab=company&ok=1");
        exit;

      } catch (Throwable $e) {
        $errors[] = "Save error: " . $e->getMessage();
      }
    }
  }


  /* =====================
     ADD / UPDATE DROPDOWN
  ===================== */
  if ($post_type === 'dropdown_save') {
    $tab = 'dropdowns';

    $category = trim((string)($_POST['category'] ?? $category));
    if (!isset($CATEGORIES[$category])) $category = 'loan_type';

    $edit_id   = (int)($_POST['edit_id'] ?? 0);
    $label_km  = trim((string)($_POST['label_km'] ?? ''));
    $label_en  = trim((string)($_POST['label_en'] ?? ''));
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($label_km === '' && $label_en === '') {
      $errors[] = "សូមបញ្ចូលឈ្មោះជាភាសាខ្មែរ ឬភាសាអង់គ្លេស";
    }

    if (!$errors) {
      try {
        if ($edit_id > 0) {
          // Keep existing code and sort order
          $stExist = $pdo->prepare("SELECT code, sort_order FROM dropdown_items WHERE id=? AND business_id=? LIMIT 1");
          $stExist->execute([$edit_id, $business_id]);
          $existItem = $stExist->fetch(PDO::FETCH_ASSOC);
          $code = $existItem['code'] ?? '';
          $sort_order = (int)($existItem['sort_order'] ?? 0);

          $sets = ["category=?","code=?"];
          $vals = [$category, $code];

          if ($ddHasKm)   { $sets[]="label_km=?";   $vals[] = ($label_km !== '' ? $label_km : null); }
          if ($ddHasEn)   { $sets[]="label_en=?";   $vals[] = ($label_en !== '' ? $label_en : null); }
          if ($ddHasSort) { $sets[]="sort_order=?"; $vals[] = $sort_order; }
          if ($ddHasAct)  { $sets[]="is_active=?";  $vals[] = $is_active; }
          if ($ddHasUpdated) { $sets[]="updated_at=NOW()"; }

          $vals[] = $edit_id;
          $vals[] = $business_id;

          $pdo->prepare("UPDATE dropdown_items SET ".implode(', ', $sets)." WHERE id=? AND business_id=?")
              ->execute($vals);

        } else {
          // Generate code
          if ($label_en !== '') {
            $code = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', $label_en));
            $code = trim($code, '_');
          } else {
            $code = 'opt_' . substr(md5(uniqid(mt_rand(), true)), 0, 8);
          }

          // Check if generated code already exists
          $stCheck = $pdo->prepare("SELECT COUNT(*) FROM dropdown_items WHERE business_id = ? AND category = ? AND code = ?");
          $stCheck->execute([$business_id, $category, $code]);
          if ((int)$stCheck->fetchColumn() > 0) {
            $code .= '_' . substr(md5(uniqid(mt_rand(), true)), 0, 4);
          }

          // Generate sort_order
          $stSort = $pdo->prepare("SELECT MAX(sort_order) FROM dropdown_items WHERE business_id = ? AND category = ?");
          $stSort->execute([$business_id, $category]);
          $max_sort = (int)($stSort->fetchColumn() ?: 0);
          $sort_order = $max_sort + 10;

          $cols = ["business_id","category","code"];
          $ph   = ["?","?","?"];
          $vals = [$business_id, $category, $code];

          if ($ddHasKm)   { $cols[]="label_km";   $ph[]="?"; $vals[] = ($label_km !== '' ? $label_km : null); }
          if ($ddHasEn)   { $cols[]="label_en";   $ph[]="?"; $vals[] = ($label_en !== '' ? $label_en : null); }
          if ($ddHasSort) { $cols[]="sort_order"; $ph[]="?"; $vals[] = $sort_order; }
          if ($ddHasAct)  { $cols[]="is_active";  $ph[]="?"; $vals[] = $is_active; }
          if ($ddHasCreated) { $cols[]="created_at"; $ph[]="NOW()"; }
          if ($ddHasUpdated) { $cols[]="updated_at"; $ph[]="NOW()"; }

          $pdo->prepare("INSERT INTO dropdown_items (".implode(',',$cols).") VALUES (".implode(',',$ph).")")
              ->execute($vals);
        }

        header("Location: settings.php?tab=dropdowns&cat=".urlencode($category)."&ok=1");
        exit;

      } catch (Throwable $e) {
        $errors[] = "Dropdown save error: ".$e->getMessage();
      }
    }
  }

  /* =====================
     QUICK SAVE SORTING
  ===================== */
  if ($post_type === 'dropdown_sort' && $ddHasSort) {
    $tab = 'dropdowns';

    $category = trim((string)($_POST['category'] ?? $category));
    if (!isset($CATEGORIES[$category])) $category = 'loan_type';

    $ids   = $_POST['id'] ?? [];
    $sorts = $_POST['sort'] ?? [];

    if (is_array($ids) && is_array($sorts)) {
      try {
        $stU = $pdo->prepare("UPDATE dropdown_items SET sort_order=?, updated_at=NOW() WHERE id=? AND business_id=?");
        foreach ($ids as $i => $idv) {
          $idv = (int)$idv;
          $sv  = (int)($sorts[$i] ?? 0);
          if ($idv > 0) $stU->execute([$sv, $idv, $business_id]);
        }
        header("Location: settings.php?tab=dropdowns&cat=".urlencode($category)."&ok=1");
        exit;
      } catch (Throwable $e) {
        $errors[] = "Sort save error: ".$e->getMessage();
      }
    }
  }
}

/* ------------------ Load dropdown items ------------------ */
$dropdown_items = [];
$edit_item = null;
$edit_id = (int)($_GET['edit_id'] ?? 0);

if (!$errors) {
  try {
    $orderBy = $ddHasSort ? "ORDER BY sort_order ASC, id ASC" : "ORDER BY id ASC";
    $st = $pdo->prepare("
      SELECT *
      FROM dropdown_items
      WHERE business_id=? AND category=?
      {$orderBy}
    ");
    $st->execute([$business_id, $category]);
    $dropdown_items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($tab === 'dropdowns' && $edit_id > 0) {
      $st = $pdo->prepare("SELECT * FROM dropdown_items WHERE id=? AND business_id=? LIMIT 1");
      $st->execute([$edit_id, $business_id]);
      $edit_item = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      if (!$edit_item) $edit_id = 0;
    }
  } catch (Throwable $e) {
    $errors[] = "Load dropdown error: ".$e->getMessage();
  }
}

/* ------------------ Show success from redirect ---- */
if (isset($_GET['ok']) && !$errors) $success = "បានរក្សាទុក ✅";

/* ------------------ prepare edit form defaults ---- */
$edit_code = $edit_item ? (string)($edit_item['code'] ?? '') : '';
$edit_km   = $edit_item ? (string)($edit_item['label_km'] ?? '') : '';
$edit_en   = $edit_item ? (string)($edit_item['label_en'] ?? '') : '';
$edit_sort = $edit_item ? (int)($edit_item['sort_order'] ?? 0) : (count($dropdown_items) * 10 + 10);
$edit_act  = $edit_item ? (int)($edit_item['is_active'] ?? 1) : 1;

/* logo preview src */
$logo_src = '';
if ($logo_path) {
  // stored path like "uploads/logos/xxx.png" (relative to /finance/)
  $logo_src = "../" . ltrim($logo_path, '/');
}
?>
<!doctype html>
<html lang="km">
<head>
  <meta charset="utf-8">
  <title>Settings | Finance</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://fonts.googleapis.com/css2?family=Battambang:wght@300;400;700;900&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

  <style>
    :root{
      --bg:#f3f4f6;
      --card:#ffffff;
      --ink:#0f172a;
      --muted:#64748b;
      --line:#e5e7eb;
      --soft:#f8fafc;
      --radius:18px;
    }
    body{font-family:'Battambang',sans-serif;background:var(--bg);color:var(--ink);}
    .page-title{font-weight:900; letter-spacing:.2px;}
    .sub{color:var(--muted); font-size:.92rem;}
    .cardx{background:var(--card); border:1px solid rgba(15,23,42,.08); border-radius:var(--radius); box-shadow:none;}
    .btn-soft{border:1px solid var(--line); background:#fff; border-radius:12px;}
    .navp{background:var(--card); border:1px solid rgba(15,23,42,.08); border-radius:14px; padding:.4rem;}
    .navp .nav-link{border-radius:12px; font-weight:800; color:#0f172a;}
    .navp .nav-link.active{background:#0d6efd; color:#fff;}
    .form-control,.form-select{border-radius:12px;}
    .input-group-text{border-radius:12px;}
    .section-title{font-weight:900; font-size:1.02rem; display:flex; align-items:center; gap:.55rem; margin-bottom:.3rem;}
    .help{color:var(--muted); font-size:.88rem;}
    .req{color:#dc2626; font-weight:900;}
    .table td,.table th{vertical-align:middle;}
    .badge-soft{
      background:#eef2ff; color:#3730a3; border-radius:999px;
      padding:.3rem .55rem; font-weight:900;
    }
    .pill{
      border:1px dashed rgba(15,23,42,.18);
      background:linear-gradient(180deg,#fff,#fbfdff);
      border-radius:16px;
      padding:.75rem .85rem;
    }
    .logo-preview{
      width:64px;height:64px;border-radius:16px;
      border:1px solid rgba(15,23,42,.12);
      background:#fff; display:flex; align-items:center; justify-content:center;
      overflow:hidden;
    }
    .logo-preview img{width:100%;height:100%;object-fit:cover;display:block;}
    .mono{font-variant-numeric:tabular-nums;}
    
    /* Premium Settings Sections Styling */
    .section-card {
      background: var(--card);
      border: 1px solid rgba(15,23,42,.08);
      border-radius: var(--radius);
      padding: 1.5rem;
      margin-bottom: 1.5rem;
      box-shadow: 0 4px 6px -1px rgba(0,0,0,0.01), 0 2px 4px -1px rgba(0,0,0,0.01);
    }
    .section-card-title {
      font-weight: 900;
      font-size: 1.1rem;
      color: var(--ink);
      display: flex;
      align-items: center;
      gap: .65rem;
      border-bottom: 1px solid var(--line);
      padding-bottom: .8rem;
      margin-bottom: 1.35rem;
    }
    .section-card-title i {
      color: #0d6efd;
      font-size: 1.25rem;
    }
    .logo-upload-wrapper {
      display: flex;
      align-items: center;
      gap: 1.25rem;
    }
    .logo-preview-large {
      width: 80px;
      height: 80px;
      border-radius: 16px;
      border: 2px dashed rgba(15,23,42,.15);
      background: var(--soft);
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      flex-shrink: 0;
    }
    .logo-preview-large img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .placeholder-badge {
      background: #eef2ff;
      color: #3730a3;
      border-radius: 8px;
      padding: .28rem .6rem;
      font-size: .8rem;
      font-weight: 700;
      cursor: pointer;
      border: 1px solid #e0e7ff;
      transition: all 0.2s ease;
      user-select: none;
      display: inline-block;
    }
    .placeholder-badge:hover {
      background: #3730a3;
      color: #ffffff;
      border-color: #3730a3;
      transform: translateY(-1px);
    }
    .cursor-pointer { cursor: pointer; }
    
    @media (max-width:576px){ .hide-sm{display:none;} }
  </style>
</head>
<body>

<?php include __DIR__ . '/../includes/navbar.php'; ?>

<div class="container py-4">

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h4 class="page-title mb-0 text-primary"><i class="bi bi-gear-wide-connected me-1"></i> ការកំណត់ (Settings)</h4>
    </div>

    <a href="dashboard.php" class="btn btn-soft">
      <i class="bi bi-arrow-left me-1"></i> ត្រឡប់
    </a>
  </div>

  <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show mb-3" role="alert" id="success-alert">
      <i class="bi bi-check-circle-fill me-1"></i> <?= h2($success) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <script>
      setTimeout(function() {
        var alertEl = document.getElementById('success-alert');
        if (alertEl) {
          if (window.bootstrap && bootstrap.Alert) {
            var bsAlert = new bootstrap.Alert(alertEl);
            bsAlert.close();
          } else {
            alertEl.style.transition = 'opacity 0.5s ease';
            alertEl.style.opacity = '0';
            setTimeout(function() { alertEl.remove(); }, 500);
          }
        }
      }, 3000);
    </script>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert alert-danger mb-3">
      <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i> សូមពិនិត្យម្តងទៀត</div>
      <?php foreach($errors as $e): ?><div>• <?= h2($e) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <ul class="nav nav-pills navp mb-3">
    <li class="nav-item">
      <a class="nav-link <?= $tab==='company'?'active':'' ?>" href="settings.php?tab=company">
        <i class="bi bi-building me-1"></i> ក្រុមហ៊ុន (Company)
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $tab==='dropdowns'?'active':'' ?>" href="settings.php?tab=dropdowns&cat=<?= h2($category) ?>">
        <i class="bi bi-list-check me-1"></i> Dropdowns
      </a>
    </li>
  </ul>

  <?php if ($tab === 'company'): ?>
    <form method="post" enctype="multipart/form-data" class="w-100">
      <input type="hidden" name="post_type" value="company">

      <div class="row">
        <!-- Left Column: Company Profile & General Info (col-12 col-xl-6) -->
        <div class="col-12 col-xl-6 d-flex flex-column gap-3 mb-3">
          <div class="section-card">
            <div class="section-card-title">
              <i class="bi bi-building"></i> ព័ត៌មានក្រុមហ៊ុន (Company Profile)
            </div>
            
            <div class="row g-3">
              <div class="col-12 col-md-6">
                <label class="form-label fw-bold">ឈ្មោះក្រុមហ៊ុន</label>
                <div class="input-group">
                  <span class="input-group-text bg-light"><i class="bi bi-building"></i></span>
                  <input type="text" name="company_name" class="form-control" value="<?= h2($company_name) ?>" placeholder="ឧ: BONG NY Pawn Shop">
                </div>
                <?php if ($SYNC_TO_BUSINESSES && $hasBusinesses): ?>
                  <div class="small text-muted mt-1" style="font-size: 0.78rem;"><i class="bi bi-link-45deg"></i> Sync ទៅតារាង businesses</div>
                <?php endif; ?>
              </div>

              <div class="col-12 col-md-6">
                <label class="form-label fw-bold">លេខទូរស័ព្ទ</label>
                <div class="input-group">
                  <span class="input-group-text bg-light"><i class="bi bi-telephone"></i></span>
                  <input type="text" name="phone" class="form-control" value="<?= h2($phone) ?>" placeholder="ឧ: 012 345 678">
                </div>
              </div>

              <div class="col-12">
                <label class="form-label fw-bold">អាសយដ្ឋាន</label>
                <div class="input-group">
                  <span class="input-group-text bg-light"><i class="bi bi-geo-alt"></i></span>
                  <input type="text" name="address" class="form-control" value="<?= h2($address) ?>" placeholder="ឧ: ភ្នំពេញ (Phnom Penh...)">
                </div>
              </div>

              <div class="col-12">
                <label class="form-label fw-bold">ឡូហ្គោក្រុមហ៊ុន (Logo)</label>
                <div class="logo-upload-wrapper pill">
                  <div class="logo-preview-large">
                    <?php if ($logo_src): ?>
                      <img src="<?= h2($logo_src) ?>" alt="logo">
                    <?php else: ?>
                      <i class="bi bi-image text-muted" style="font-size: 2rem;"></i>
                    <?php endif; ?>
                  </div>
                  <div class="flex-grow-1">
                    <div class="help mb-2"><i class="bi bi-info-circle me-1"></i> PNG / JPG / WEBP (ទំហំអតិបរមា 3MB)</div>
                    <input type="file" name="logo" class="form-control mb-1" accept="image/png,image/jpeg,image/webp">
                    <?php if ($logo_path): ?>
                      <div class="help text-truncate" style="max-width: 250px;">ឯកសារបច្ចុប្បន្ន: <span class="mono text-primary fw-bold"><?= h2(basename($logo_path)) ?></span></div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <div class="col-12">
                <label class="form-label fw-bold">ចំណាំលើបង្កាន់ដៃ (Receipt Note)</label>
                <div class="pill">
                  <div class="help mb-2"><i class="bi bi-receipt me-1"></i> អត្ថបទបង្ហាញផ្នែកខាងក្រោមនៃបង្កាន់ដៃបង់ប្រាក់</div>
                  <textarea name="receipt_note" class="form-control" rows="5" placeholder="ឧ: អរគុណសម្រាប់ការគាំទ្រ..."><?= h2($receipt_note) ?></textarea>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Right Column: Telegram Settings & Automation (col-12 col-xl-6) -->
        <div class="col-12 col-xl-6 d-flex flex-column gap-3 mb-3">
          <!-- Card 2: Telegram Bot Credentials -->
          <div class="section-card">
            <div class="section-card-title">
              <i class="bi bi-robot"></i> ការកំណត់ Telegram Bot (Bot Credentials)
            </div>
            
            <div class="row g-3">
              <div class="col-12 col-md-6">
                <label class="form-label fw-bold">Telegram Bot Token</label>
                <div class="pill bg-white">
                  <div class="help mb-2"><i class="bi bi-key me-1"></i> Bot Token ពី @BotFather</div>
                  <?php if (defined('FIN_TELEGRAM_BOT_TOKEN') && FIN_TELEGRAM_BOT_TOKEN !== ''): ?>
                    <input type="text" class="form-control bg-light" value="<?= h2($telegram_bot_token) ?>" readonly>
                    <div class="small text-success mt-1"><i class="bi bi-shield-lock-fill me-1"></i> កំណត់តាមរយៈ config.php</div>
                  <?php else: ?>
                    <input type="text" name="telegram_bot_token" class="form-control" value="<?= h2($telegram_bot_token) ?>" placeholder="ឧ: 8032499356:AA...">
                  <?php endif; ?>
                </div>
              </div>

              <div class="col-12 col-md-6">
                <label class="form-label fw-bold">Telegram Bot Username</label>
                <div class="pill bg-white">
                  <div class="help mb-2"><i class="bi bi-person-badge me-1"></i> ឈ្មោះគណនី Bot (គ្មានសញ្ញា @)</div>
                  <input type="text" name="telegram_bot_username" class="form-control" value="<?= h2($telegram_bot_username) ?>" placeholder="ឧ: ezuse_reporter_bot">
                </div>
              </div>
            </div>
          </div>

          <!-- Card 3: Auto Followup Settings -->
          <div class="section-card">
            <div class="section-card-title">
              <i class="bi bi-chat-left-dots-fill"></i> សាររំលឹកការសងប្រាក់ស្វ័យប្រវត្ត (Auto Followup)
            </div>
            
            <div class="row g-3">
              <div class="col-12">
                <label class="form-label fw-bold">ថ្ងៃផ្ញើសាររំលឹកការសងប្រាក់</label>
                <div class="pill bg-white">
                  <div class="help mb-2"><i class="bi bi-calendar-check me-1"></i> កំណត់ថ្ងៃផ្ញើសាររំលឹក ធៀបនឹងថ្ងៃត្រូវបង់ប្រាក់</div>
                  <select name="telegram_followup_days" class="form-select">
                    <option value="-2" <?= $telegram_followup_days === -2 ? 'selected' : '' ?>>2 ថ្ងៃមុនថ្ងៃត្រូវបង់ (2 Days Before)</option>
                    <option value="-1" <?= $telegram_followup_days === -1 ? 'selected' : '' ?>>1 ថ្ងៃមុនថ្ងៃត្រូវបង់ (1 Day Before)</option>
                    <option value="0"  <?= $telegram_followup_days === 0  ? 'selected' : '' ?>>ចំថ្ងៃកំណត់ត្រូវបង់ (On Due Date)</option>
                    <option value="1"  <?= $telegram_followup_days === 1  ? 'selected' : '' ?>>ហួសកំណត់ 1 ថ្ងៃ (1 Day Overdue)</option>
                    <option value="2"  <?= $telegram_followup_days === 2  ? 'selected' : '' ?>>ហួសកំណត់ 2 ថ្ងៃ (2 Days Overdue)</option>
                    <option value="3"  <?= $telegram_followup_days === 3  ? 'selected' : '' ?>>ហួសកំណត់ 3 ថ្ងៃ (3 Days Overdue)</option>
                    <option value="5"  <?= $telegram_followup_days === 5  ? 'selected' : '' ?>>ហួសកំណត់ 5 ថ្ងៃ (5 Days Overdue)</option>
                    <option value="7"  <?= $telegram_followup_days === 7  ? 'selected' : '' ?>>ហួសកំណត់ 7 ថ្ងៃ (7 Days Overdue)</option>
                  </select>
                </div>
              </div>

              <div class="col-12">
                <label class="form-label fw-bold">អត្ថបទសារគំរូ (Followup Template)</label>
                <div class="pill bg-white">
                  <div class="help mb-2"><i class="bi bi-file-earmark-text me-1"></i> ចុចលើពាក្យខាងក្រោមដើម្បីបញ្ចូលទៅក្នុងអត្ថបទសារ៖</div>
                  <div class="mb-2 d-flex flex-wrap gap-1">
                    <span class="placeholder-badge" onclick="insertPlaceholder('{customer_name}')">{customer_name}</span>
                    <span class="placeholder-badge" onclick="insertPlaceholder('{loan_code}')">{loan_code}</span>
                    <span class="placeholder-badge" onclick="insertPlaceholder('{installment_no}')">{installment_no}</span>
                    <span class="placeholder-badge" onclick="insertPlaceholder('{due_date}')">{due_date}</span>
                    <span class="placeholder-badge" onclick="insertPlaceholder('{amount_due}')">{amount_due}</span>
                    <span class="placeholder-badge" onclick="insertPlaceholder('{remaining_balance}')">{remaining_balance}</span>
                  </div>
                  <textarea name="telegram_template" class="form-control" rows="5" placeholder="ឧ: សួស្តី {customer_name}..."><?= h2($telegram_template) ?></textarea>
                </div>
              </div>
            </div>
          </div>

          <!-- Card 4: Owner Reports Settings -->
          <div class="section-card">
            <div class="section-card-title">
              <i class="bi bi-envelope-paper-fill"></i> របាយការណ៍ផ្ញើជូនម្ចាស់ហាង (Owner Reports)
            </div>
            
            <div class="row g-3">
              <div class="col-12">
                <label class="form-label fw-bold">Telegram Chat/Group ID របស់ម្ចាស់</label>
                <div class="pill bg-white">
                  <div class="help mb-2"><i class="bi bi-hash me-1"></i> ID ម្ចាស់ ឬ Group ID (ចាប់ផ្តើមដោយសញ្ញា -) ដើម្បីទទួលបានរបាយការណ៍សង្ខេប</div>
                  <div class="input-group">
                    <input type="text" id="telegram_owner_chat_id" name="telegram_owner_chat_id" class="form-control" value="<?= h2($telegram_owner_chat_id) ?>" placeholder="ឧ: 987654321 ឬ -100123456789">
                    <button class="btn btn-primary fw-bold" type="button" onclick="openTgQrModalSettings()">
                      <i class="bi bi-qr-code me-1"></i> ស្កេនរក Chat ID
                    </button>
                  </div>
                </div>
              </div>

              <div class="col-12">
                <label class="form-label fw-bold">របាយការណ៍សង្ខេបប្រចាំថ្ងៃ (Daily Summary Report)</label>
                <div class="pill bg-white">
                  <div class="help mb-2"><i class="bi bi-envelope-check me-1"></i> ផ្ញើបញ្ជីអតិថិជនត្រូវសងប្រាក់ថ្ងៃនេះ ទៅកាន់ម្ចាស់ហាងរៀងរាល់ព្រឹក</div>
                  <div class="form-check form-switch pt-1">
                    <input class="form-check-input" type="checkbox" name="telegram_enable_daily_report" id="telegram_enable_daily_report" value="1" <?= $telegram_enable_daily_report ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold cursor-pointer" for="telegram_enable_daily_report">បើកផ្ញើរបាយការណ៍ប្រចាំថ្ងៃ</label>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="d-flex gap-2 flex-wrap mt-2 justify-content-end">
        <button class="btn btn-primary px-4 py-2 fw-bold" type="submit" style="border-radius: 12px;">
          <i class="bi bi-save2 me-1"></i> រក្សាទុកការកំណត់
        </button>
        <a class="btn btn-soft px-4 py-2 fw-bold" href="settings.php?tab=company" style="border-radius: 12px;">
          <i class="bi bi-arrow-clockwise me-1"></i> ផ្ទុកឡើងវិញ
        </a>
      </div>
    </form>
  <?php endif; ?>

  <?php if ($tab === 'dropdowns'): ?>
    <div class="cardx p-3 p-lg-4 mb-3">
      <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
        <div>
          <div class="section-title"><i class="bi bi-list-check"></i> គ្រប់គ្រង Dropdowns</div>
        </div>

        <form method="get" class="d-flex gap-2 align-items-center">
          <input type="hidden" name="tab" value="dropdowns">
          <label class="help mb-0 hide-sm">ប្រភេទ (Category):</label>
          <select name="cat" class="form-select" onchange="this.form.submit()">
            <?php foreach ($CATEGORIES as $k => $label): ?>
              <option value="<?= h2($k) ?>" <?= $category===$k?'selected':'' ?>><?= h2($label) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>

      <hr class="my-3">


      <form method="post" class="row g-3">
        <input type="hidden" name="post_type" value="dropdown_save">
        <input type="hidden" name="category" value="<?= h2($category) ?>">
        <input type="hidden" name="edit_id" value="<?= (int)($edit_item['id'] ?? 0) ?>">

        <div class="col-12 col-md-5">
          <label class="form-label fw-bold">ឈ្មោះជាភាសាខ្មែរ</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-translate"></i></span>
            <input type="text" name="label_km" class="form-control" value="<?= h2($edit_km) ?>" placeholder="ឧ: សាច់ប្រាក់">
          </div>
        </div>

        <div class="col-12 col-md-5">
          <label class="form-label fw-bold">ឈ្មោះជាភាសាអង់គ្លេស</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-fonts"></i></span>
            <input type="text" name="label_en" class="form-control" value="<?= h2($edit_en) ?>" placeholder="ឧ: Cash">
          </div>
        </div>

        <div class="col-12 col-md-2 d-flex align-items-end">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= $edit_act? 'checked':'' ?>>
            <label class="form-check-label fw-bold cursor-pointer" for="is_active">សកម្ម</label>
          </div>
        </div>

        <div class="col-12 d-flex justify-content-end gap-2 flex-wrap">
          <button class="btn btn-primary px-4 py-2" type="submit" style="border-radius: 12px;">
            <i class="bi bi-save2 me-1"></i> <?= $edit_item ? 'កែប្រែ' : 'បន្ថែម' ?>
          </button>

          <?php if ($edit_item): ?>
            <a class="btn btn-soft px-4 py-2" href="settings.php?tab=dropdowns&cat=<?= h2($category) ?>" style="border-radius: 12px;">
              <i class="bi bi-x-circle me-1"></i> បោះបង់
            </a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <div class="cardx p-3 p-lg-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="section-title"><i class="bi bi-list-stars text-primary"></i> បញ្ជីទិន្នន័យ៖ <?= h2($CATEGORIES[$category]) ?></div>
        <span class="badge bg-primary-subtle text-primary fw-bold px-3 py-1.5" style="border-radius: 8px;">សរុប <?= count($dropdown_items) ?> ទិន្នន័យ</span>
      </div>

      <?php if (!$dropdown_items): ?>
        <div class="text-muted py-3 text-center">មិនទាន់មានទិន្នន័យទេ។ សូមបន្ថែមខាងលើ។</div>
      <?php else: ?>

        <div class="row g-3">
          <?php foreach ($dropdown_items as $i => $it): ?>
            <?php $active = (int)($it['is_active'] ?? 1) === 1; ?>
            <div class="col-12 col-md-6 col-lg-4">
              <div class="card p-3 shadow-sm bg-white h-100" style="border: 1px solid rgba(15,23,42,.10); border-radius: 14px;">
                <div class="d-flex justify-content-between align-items-start mb-3 gap-2">
                  <div class="flex-grow-1">
                    <div class="fw-bold text-dark mb-1" style="font-size: 0.95rem;">ខ្មែរ: <?= h2($it['label_km'] ?? '—') ?></div>
                    <div class="text-muted small">អង់គ្លេស: <?= h2($it['label_en'] ?? '—') ?></div>
                  </div>
                  <div class="d-flex align-items-center gap-2 flex-shrink-0">
                    <span class="badge bg-secondary-subtle text-secondary fw-bold" style="font-size: 0.72rem; padding: 0.2rem 0.35rem;">#<?= $i+1 ?></span>
                    <span class="badge <?= $active ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?> fw-bold" style="font-size: 0.65rem; padding: 0.2rem 0.4rem; border-radius: 5px;">
                      <?= $active ? 'ACTIVE' : 'OFF' ?>
                    </span>
                  </div>
                </div>

                <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                  <div class="d-flex gap-1">
                    <a class="btn btn-sm btn-soft px-2.5 py-1.5 d-flex align-items-center gap-1" style="border-radius: 8px;"
                       href="settings.php?tab=dropdowns&cat=<?= h2($category) ?>&edit_id=<?= (int)$it['id'] ?>">
                      <i class="bi bi-pencil-square"></i> កែប្រែ
                    </a>
                    
                    <?php if ($ddHasAct): ?>
                      <a class="btn btn-sm btn-soft px-2.5 py-1.5 d-flex align-items-center gap-1" style="border-radius: 8px;"
                         href="settings.php?tab=dropdowns&cat=<?= h2($category) ?>&action=toggle&id=<?= (int)$it['id'] ?>">
                        <i class="bi bi-toggle-<?= $active ? 'on' : 'off' ?>"></i> <?= $active ? 'បិទ' : 'បើក' ?>
                      </a>
                    <?php endif; ?>
                  </div>

                  <a class="btn btn-sm btn-outline-danger px-2.5 py-1.5 d-flex align-items-center gap-1" style="border-radius: 8px;"
                     onclick="return confirm('Delete item នេះមែនទេ?');"
                     href="settings.php?tab=dropdowns&cat=<?= h2($category) ?>&action=delete&id=<?= (int)$it['id'] ?>">
                    <i class="bi bi-trash3"></i> លុប
                  </a>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="help mt-3">
          <i class="bi bi-info-circle me-1"></i>
          ចំណាំ៖ ឈ្មោះអាចកែប្រែបានគ្រប់ពេល។
        </div>

      <?php endif; ?>
    </div>
  <?php endif; ?>

<!-- Telegram QR Modal -->
<div class="modal fade" id="tgQrModal" tabindex="-1" aria-labelledby="tgQrModalLabel" aria-hidden="true" style="z-index: 1070;">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:18px; border:none; box-shadow:0 15px 30px rgba(0,0,0,0.15);">
      <div class="modal-header bg-primary text-white py-3">
        <h5 class="modal-title fw-bold" id="tgQrModalLabel"><i class="bi bi-telegram me-2"></i> ស្កេន Telegram Bot ដើម្បីភ្ជាប់ Chat ID របស់ម្ចាស់</h5>
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
          <li class="mb-1">ស្កេន QR Code ខាងលើ ឬចុចលើតំណភ្ជាប់៖ <a href="#" id="tgBotLink" target="_blank" class="fw-bold text-decoration-none">t.me/...</a></li>
          <li class="mb-1">បន្ទាប់មក ចុច <b>Start</b> ឬផ្ញើសារណាមួយទៅកាន់ Bot (ឧ៖ ផ្ញើពាក្យ "សួស្តី")។</li>
          <li>ចុចប៊ូតុង <b>ទាញយកបញ្ជី (Refresh)</b> ខាងក្រោម រួចជ្រើសរើសគណនីរបស់អ្នក។</li>
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
function insertPlaceholder(placeholder) {
  const textarea = document.querySelector('textarea[name="telegram_template"]');
  if (!textarea) return;
  const start = textarea.selectionStart;
  const end = textarea.selectionEnd;
  const text = textarea.value;
  textarea.value = text.substring(0, start) + placeholder + text.substring(end);
  textarea.focus();
  textarea.selectionStart = textarea.selectionEnd = start + placeholder.length;
}
let tgQrModal = null;

document.addEventListener('DOMContentLoaded', () => {
  const modalEl = document.getElementById('tgQrModal');
  if (modalEl) {
    tgQrModal = new bootstrap.Modal(modalEl);
  }
  
  const btnRefresh = document.getElementById('btnRefreshRecent');
  if (btnRefresh) {
    btnRefresh.addEventListener('click', loadRecentChats);
  }
});

function openTgQrModalSettings() {
  const botUsername = <?= json_encode($telegram_bot_username) ?>;
  if (!botUsername || botUsername.trim() === '') {
    alert('សូមបំពេញ Telegram Bot Token រួចចុច "រក្សាទុក" ជាមុនសិន ដើម្បីបង្កើត QR Code!');
    return;
  }
  
  const botLink = `https://t.me/${botUsername}`;
  document.getElementById('tgBotLink').href = botLink;
  document.getElementById('tgBotLink').textContent = `@${botUsername}`;
  
  // Render QR Code
  const canvas = document.getElementById('tgQrCanvas');
  const qr = new QRious({
    element: canvas,
    value: botLink,
    size: 250,
    level: 'H'
  });
  
  // Set up download button
  document.getElementById('btnDownloadQr').onclick = function(e) {
    e.preventDefault();
    const image = canvas.toDataURL("image/png").replace("image/png", "image/octet-stream");
    const link = document.createElement('a');
    link.download = `tg_bot_qr_${botUsername}.png`;
    link.href = image;
    link.click();
  };
  
  tgQrModal.show();
  loadRecentChats();
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
        tableBody.innerHTML = '<tr><td colspan="4" class="text-center py-3 text-muted">មិនមានសារផ្ញើចូលថ្មីៗទេ។ សូមផ្ញើសារទៅកាន់ Bot រួចចុច "ទាញយកបញ្ជី" ម្តងទៀត។</td></tr>';
        return;
      }
      
      let html = '';
      users.forEach(u => {
        const usernameDisp = u.username ? `@${u.username}` : '—';
        const msgDisp = u.message ? escapeHtmlJs(u.message) : '<i>(Start/Other)</i>';
        
        const escapedName = escapeHtmlJs(u.full_name);
        const escapedChat = escapeHtmlJs(u.chat_id);
        
        html += `
          <tr>
            <td>
              <div class="fw-bold">${escapedName}</div>
              <div class="text-muted" style="font-size:0.75rem;">ID: ${escapedChat}</div>
            </td>
            <td>${usernameDisp}</td>
            <td class="text-truncate text-muted" style="max-width: 120px;" title="${escapedName}">${msgDisp}</td>
            <td class="text-end">
              <button type="button" class="btn btn-sm btn-success py-1 fw-bold" onclick="selectTelegramUser('${escapedChat}')" style="border-radius:6px; font-size:0.8rem;">
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

function selectTelegramUser(chatId) {
  const chatInput = document.getElementById('telegram_owner_chat_id');
  if (chatInput) chatInput.value = chatId;
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
