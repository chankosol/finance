<?php
// /finance/admin/settings.php
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

fin_require_login();
$business_id = fin_require_business();
global $pdo;

function h2($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$tab = $_GET['tab'] ?? 'company';
$tab = in_array($tab, ['company','dropdowns'], true) ? $tab : 'company';

$errors = [];
$success = '';

/* ===================== Load business settings ===================== */
$st = $pdo->prepare("SELECT * FROM business_settings WHERE business_id=? LIMIT 1");
$st->execute([$business_id]);
$bs = $st->fetch() ?: null;

$company_name = $bs['company_name'] ?? '';
$phone        = $bs['phone'] ?? '';
$address      = $bs['address'] ?? '';
$receipt_note = $bs['receipt_note'] ?? '';
$logo_path    = $bs['logo_path'] ?? '';

/* ===================== Save Company Settings ===================== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'save_company') {

  $company_name = trim($_POST['company_name'] ?? '');
  $phone        = trim($_POST['phone'] ?? '');
  $address      = trim($_POST['address'] ?? '');
  $receipt_note = trim($_POST['receipt_note'] ?? '');

  // upload logo
  if (!empty($_FILES['logo']['name'])) {
    $f = $_FILES['logo'];
    if ($f['error'] === UPLOAD_ERR_OK) {
      $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
      if (!in_array($ext, ['png','jpg','jpeg','webp'], true)) {
        $errors[] = 'Logo អនុញ្ញាតតែ PNG/JPG/WEBP';
      } else {
        $dir = __DIR__ . '/../uploads/logos';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $newName = 'biz_'.$business_id.'_'.time().'.'.$ext;
        $dest = $dir . '/' . $newName;

        if (move_uploaded_file($f['tmp_name'], $dest)) {
          $logo_path = FIN_BASE_URL . '/uploads/logos/' . $newName;
        } else {
          $errors[] = 'Upload logo បរាជ័យ';
        }
      }
    } else {
      $errors[] = 'Upload logo មាន error';
    }
  }

  if (!$errors) {
    if ($bs) {
      $st = $pdo->prepare("
        UPDATE business_settings
        SET company_name=?, phone=?, address=?, receipt_note=?, logo_path=?
        WHERE business_id=?
      ");
      $st->execute([$company_name, $phone, $address, $receipt_note, $logo_path, $business_id]);
    } else {
      $st = $pdo->prepare("
        INSERT INTO business_settings (business_id, company_name, phone, address, receipt_note, logo_path)
        VALUES (?,?,?,?,?,?)
      ");
      $st->execute([$business_id, $company_name, $phone, $address, $receipt_note, $logo_path]);
    }
    $success = 'បានរក្សាទុក Settings រួចរាល់ ✅';
    $tab = 'company';
  }
}

/* ===================== Dropdown Manager ===================== */
$category = $_GET['cat'] ?? 'loan_type';
$allowedCats = ['loan_type','payment_method','status_detail','pawn_type'];
if (!in_array($category, $allowedCats, true)) $category = 'loan_type';

// Add dropdown item
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'add_item') {
  $category = trim($_POST['category'] ?? 'loan_type');
  if (!in_array($category, $allowedCats, true)) $category = 'loan_type';

  $code = trim($_POST['code'] ?? '');
  $label_km = trim($_POST['label_km'] ?? '');
  $sort_order = (int)($_POST['sort_order'] ?? 0);

  if ($code === '' || $label_km === '') $errors[] = 'សូមបំពេញ Code និង Label Khmer';

  if (!$errors) {
    $st = $pdo->prepare("
      INSERT INTO dropdown_items (business_id, category, code, label_km, sort_order, is_active)
      VALUES (?,?,?,?,?,1)
      ON DUPLICATE KEY UPDATE label_km=VALUES(label_km), sort_order=VALUES(sort_order), is_active=1
    ");
    $st->execute([$business_id, $category, $code, $label_km, $sort_order]);
    $success = 'បានបន្ថែម/កែ Item ✅';
    $tab = 'dropdowns';
  }
}

// Toggle active
if (isset($_GET['toggle_id'])) {
  $id = (int)$_GET['toggle_id'];
  $st = $pdo->prepare("
    UPDATE dropdown_items
    SET is_active = IF(is_active=1,0,1)
    WHERE id=? AND business_id=?
  ");
  $st->execute([$id, $business_id]);
  header("Location: settings.php?tab=dropdowns&cat=".urlencode($category));
  exit;
}

// Load dropdown items
$st = $pdo->prepare("
  SELECT *
  FROM dropdown_items
  WHERE business_id=? AND category=?
  ORDER BY sort_order ASC, id ASC
");
$st->execute([$business_id, $category]);
$items = $st->fetchAll() ?: [];

?>
<!doctype html>
<html lang="km">
<head>
<meta charset="utf-8">
<title>Settings | Finance</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Battambang:wght@300;400;700;900&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{font-family:'Battambang',sans-serif;background:#f3f4f6}
  .cardx{background:#fff;border-radius:18px;box-shadow:0 10px 25px rgba(0,0,0,.06)}
  .page-title{font-weight:900}
  .tabbtn{border-radius:999px}
  .sub{color:#64748b;font-size:.9rem}
</style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h4 class="page-title mb-0">⚙️ Settings</h4>
      <div class="sub">គ្រប់គ្រង Logo, Company Info, Dropdown Items</div>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach($errors as $e): ?><div>• <?= h2($e) ?></div><?php endforeach; ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="alert alert-success"><?= h2($success) ?></div>
  <?php endif; ?>

  <div class="d-flex gap-2 mb-3">
    <a class="btn btn-outline-primary tabbtn <?= $tab==='company'?'active':'' ?>" href="settings.php?tab=company">🏢 Company</a>
    <a class="btn btn-outline-primary tabbtn <?= $tab==='dropdowns'?'active':'' ?>" href="settings.php?tab=dropdowns">📌 Dropdowns</a>
  </div>

  <?php if ($tab === 'company'): ?>
    <div class="cardx p-3 p-lg-4">
      <h5 class="mb-3">🏢 Company Profile</h5>

      <form method="post" enctype="multipart/form-data" class="row g-3">
        <input type="hidden" name="action" value="save_company">

        <div class="col-12 col-md-6">
          <label class="form-label">ឈ្មោះក្រុមហ៊ុន</label>
          <input type="text" name="company_name" class="form-control" value="<?= h2($company_name) ?>">
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Logo</label>
          <input type="file" name="logo" class="form-control" accept=".png,.jpg,.jpeg,.webp">
          <?php if ($logo_path): ?>
            <div class="mt-2">
              <img src="<?= h2($logo_path) ?>" alt="logo" style="height:48px;border-radius:10px;">
            </div>
          <?php endif; ?>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">លេខទូរស័ព្ទ</label>
          <input type="text" name="phone" class="form-control" value="<?= h2($phone) ?>">
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">អាសយដ្ឋាន</label>
          <input type="text" name="address" class="form-control" value="<?= h2($address) ?>">
        </div>

        <div class="col-12">
          <label class="form-label">សារលើបង្កាន់ដៃ (Receipt Note)</label>
          <input type="text" name="receipt_note" class="form-control" value="<?= h2($receipt_note) ?>">
        </div>

        <div class="col-12">
          <button class="btn btn-primary">រក្សាទុក</button>
        </div>
      </form>
    </div>
  <?php else: ?>

    <div class="cardx p-3 p-lg-4">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h5 class="mb-0">📌 Dropdown Manager</h5>

        <div class="d-flex gap-2 flex-wrap">
          <?php foreach(['loan_type'=>'ប្រភេទកម្ចី','payment_method'=>'វិធីបង់','status_detail'=>'ស្ថានភាពលម្អិត','pawn_type'=>'ប្រភេទបញ្ចាំ'] as $k=>$v): ?>
            <a class="btn btn-sm btn-outline-secondary <?= $category===$k?'active':'' ?>"
               href="settings.php?tab=dropdowns&cat=<?= h2($k) ?>">
              <?= h2($v) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <form method="post" class="row g-2 mb-3">
        <input type="hidden" name="action" value="add_item">
        <input type="hidden" name="category" value="<?= h2($category) ?>">

        <div class="col-12 col-md-3">
          <input type="text" name="code" class="form-control" placeholder="code (e.g. monthly)">
        </div>
        <div class="col-12 col-md-6">
          <input type="text" name="label_km" class="form-control" placeholder="Label Khmer">
        </div>
        <div class="col-12 col-md-2">
          <input type="number" name="sort_order" class="form-control" placeholder="Sort">
        </div>
        <div class="col-12 col-md-1 d-grid">
          <button class="btn btn-primary">បន្ថែម</button>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>Code</th>
              <th>Label (KM)</th>
              <th>Sort</th>
              <th>Active</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$items): ?>
              <tr><td colspan="5" class="text-muted text-center py-3">មិនទាន់មាន items</td></tr>
            <?php endif; ?>

            <?php foreach($items as $it): ?>
              <tr>
                <td class="fw-semibold"><?= h2($it['code']) ?></td>
                <td><?= h2($it['label_km']) ?></td>
                <td><?= (int)$it['sort_order'] ?></td>
                <td>
                  <span class="badge bg-<?= $it['is_active']? 'success':'secondary' ?>">
                    <?= $it['is_active']? 'ON':'OFF' ?>
                  </span>
                </td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-primary"
                     href="settings.php?tab=dropdowns&cat=<?= h2($category) ?>&toggle_id=<?= (int)$it['id'] ?>">
                    ប្ដូរ ON/OFF
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    </div>

  <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
