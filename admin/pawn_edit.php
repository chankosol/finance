<?php
// /finance/admin/pawn_edit.php
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

fin_require_login();
fin_require_permission('edit_pawn');
$business_id = fin_require_business();
global $pdo;

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: pawns.php');
    exit;
}

// load pawn
$st = $pdo->prepare("
    SELECT * FROM pawns
    WHERE id = ? AND business_id = ?
");
$st->execute([$id, $business_id]);
$pawn = $st->fetch();
if (!$pawn) {
    echo 'បណ្ណបញ្ចាំមិនមានទេ។';
    exit;
}

// customers list
$st = $pdo->prepare("
    SELECT id, full_name
    FROM customers
    WHERE business_id = ?
    ORDER BY full_name
");
$st->execute([$business_id]);
$customers = $st->fetchAll();

$errors = [];
$customer_id      = $pawn['customer_id'];
$item_name        = $pawn['item_name'];
$item_description = $pawn['item_description'];
$pawn_amount      = $pawn['pawn_amount'];
$start_date       = substr((string)$pawn['start_date'],0,10);
$due_date         = substr((string)$pawn['due_date'],0,10);
$status           = $pawn['status'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id      = (int)($_POST['customer_id'] ?? 0);
    $item_name        = trim($_POST['item_name'] ?? '');
    $item_description = trim($_POST['item_description'] ?? '');
    $pawn_amount      = trim($_POST['pawn_amount'] ?? '');
    $start_date       = trim($_POST['start_date'] ?? '');
    $due_date         = trim($_POST['due_date'] ?? '');
    $status           = trim($_POST['status'] ?? 'active');

    if ($customer_id <= 0)                      $errors[] = 'សូមជ្រើសរើសអតិថិជន';
    if ($item_name === '')                      $errors[] = 'សូមបញ្ចូលវត្ថុបញ្ចាំ';
    if ($pawn_amount === '' || !is_numeric($pawn_amount)) $errors[] = 'សូមបញ្ចូលចំនួនប្រាក់បញ្ចាំត្រឹមត្រូវ';
    if ($start_date === '')                     $errors[] = 'សូមបញ្ចូលថ្ងៃចាប់ផ្តើម';
    if ($due_date === '')                       $errors[] = 'សូមបញ្ចូលថ្ងៃដល់កំណត់';

    if (!$errors) {
        $st = $pdo->prepare("
            UPDATE pawns
               SET customer_id = ?,
                   item_name   = ?,
                   item_description = ?,
                   pawn_amount = ?,
                   start_date  = ?,
                   due_date    = ?,
                   status      = ?
             WHERE id = ? AND business_id = ?
        ");
        $st->execute([
            $customer_id,
            $item_name,
            $item_description ?: null,
            (float)$pawn_amount,
            $start_date,
            $due_date,
            $status ?: 'active',
            $id,
            $business_id
        ]);

        header('Location: pawns.php');
        exit;
    }
}
?>
<!doctype html>
<html lang="km">
<head>
    <meta charset="utf-8">
    <title>កែប្រែបណ្ណបញ្ចាំ</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Battambang&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body{font-family:'Battambang',sans-serif;}</style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="container py-4">
    <h4 class="mb-3">កែប្រែបណ្ណបញ្ចាំ</h4>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $e): ?>
                <div>• <?= h($e) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" class="row g-3">
        <div class="col-12 col-md-6">
            <label class="form-label">អតិថិជន *</label>
            <select name="customer_id" class="form-select" required>
                <option value="">-- ជ្រើសរើស --</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $customer_id == $c['id'] ? 'selected':''; ?>>
                        <?= h($c['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-12 col-md-6">
            <label class="form-label">ចំនួនប្រាក់បញ្ចាំ *</label>
            <input type="number" step="0.01" name="pawn_amount"
                   class="form-control" value="<?= h($pawn_amount) ?>" required>
        </div>

        <div class="col-12">
            <label class="form-label">វត្ថុបញ្ចាំ *</label>
            <input type="text" name="item_name" class="form-control"
                   value="<?= h($item_name) ?>" required>
        </div>

        <div class="col-12">
            <label class="form-label">ពិពណ៌នាបន្ថែម</label>
            <textarea name="item_description" rows="2"
                      class="form-control"><?= h($item_description) ?></textarea>
        </div>

        <div class="col-12 col-md-6">
            <label class="form-label">ថ្ងៃចាប់ផ្តើម *</label>
            <input type="date" name="start_date" class="form-control"
                   value="<?= h($start_date) ?>" required>
        </div>

        <div class="col-12 col-md-6">
            <label class="form-label">ថ្ងៃដល់កំណត់ *</label>
            <input type="date" name="due_date" class="form-control"
                   value="<?= h($due_date) ?>" required>
        </div>

        <div class="col-12 col-md-4">
            <label class="form-label">ស្ថានភាព</label>
            <select name="status" class="form-select">
                <option value="active"  <?= $status==='active'?'selected':''; ?>>កំពុងដំណើរការ</option>
                <option value="overdue" <?= $status==='overdue'?'selected':''; ?>>ហួសកំណត់</option>
                <option value="closed"  <?= $status==='closed'?'selected':''; ?>>បិទ</option>
            </select>
        </div>

        <div class="col-12">
            <button class="btn btn-primary">រក្សាទុក</button>
            <a href="pawns.php" class="btn btn-secondary">ត្រឡប់ក្រោយ</a>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
