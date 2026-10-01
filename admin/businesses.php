<?php
// /finance/admin/businesses.php
// Page for managing businesses/companies/shops in the system (Super Admin only)

require_once '../includes/auth.php';
require_once '../includes/helpers.php';

fin_require_login();

// Restrict access to Super Admin only
if (!fin_is_super_admin()) {
    http_response_code(403);
    echo '<div style="font-family:sans-serif; text-align:center; padding: 50px;">';
    echo '<h2>403 Forbidden</h2>';
    echo '<p>អ្នកមិនមានសិទ្ធិចូលទំព័រនេះទេ។ (Only Super Admins can manage businesses)</p>';
    echo '<p><a href="' . FIN_BASE_URL . '/admin/dashboard.php">ត្រឡប់ទៅទំព័រដើម / Return to Dashboard</a></p>';
    echo '</div>';
    exit;
}

global $pdo;

$messages = [];
$errors   = [];

// Load non-super-admin roles for initial user creation dropdown
$roles = [];
try {
    $st = $pdo->query("SELECT id, name, label FROM roles WHERE name != 'super_admin' ORDER BY id ASC");
    $roles = $st->fetchAll();
} catch (Throwable $e) {}

// ----------------- Handle Form Submission -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Add or Edit Business
    if ($action === 'save_business') {
        $business_id = (int)($_POST['business_id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $type        = trim($_POST['type'] ?? 'both');
        $phone       = trim($_POST['phone'] ?? '');
        $address     = trim($_POST['address'] ?? '');
        $is_active   = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            $errors[] = 'សូមបញ្ចូលឈ្មោះក្រុមហ៊ុន / ហាង (Business Name)';
        }
        if (!in_array($type, ['both', 'loan', 'pawn'], true)) {
            $type = 'both';
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                if ($business_id > 0) {
                    // Update Business
                    $st = $pdo->prepare("
                        UPDATE businesses 
                        SET name = ?, type = ?, phone = ?, address = ?, is_active = ? 
                        WHERE id = ?
                    ");
                    $st->execute([$name, $type, $phone, $address, $is_active, $business_id]);

                    // Update business_settings (if exists, update; else insert)
                    $stCheck = $pdo->prepare("SELECT COUNT(*) FROM business_settings WHERE business_id = ?");
                    $stCheck->execute([$business_id]);
                    if ((int)$stCheck->fetchColumn() > 0) {
                        $stSet = $pdo->prepare("
                            UPDATE business_settings 
                            SET company_name = ?, phone = ?, address = ? 
                            WHERE business_id = ?
                        ");
                        $stSet->execute([$name, $phone, $address, $business_id]);
                    } else {
                        $stSet = $pdo->prepare("
                            INSERT INTO business_settings (business_id, company_name, phone, address) 
                            VALUES (?, ?, ?, ?)
                        ");
                        $stSet->execute([$business_id, $name, $phone, $address]);
                    }

                    $messages[] = 'បានកែសម្រួលព័ត៌មានក្រុមហ៊ុនដោយជោគជ័យ!';
                } else {
                    // Create Business
                    $st = $pdo->prepare("
                        INSERT INTO businesses (name, type, phone, address, is_active) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $st->execute([$name, $type, $phone, $address, $is_active]);
                    $new_biz_id = (int)$pdo->lastInsertId();

                    // 1. Create default business settings
                    $stSet = $pdo->prepare("
                        INSERT INTO business_settings (business_id, company_name, phone, address) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $stSet->execute([$new_biz_id, $name, $phone, $address]);

                    // 2. Link to the current creator user in business_user_roles
                    $uid = fin_current_user_id();
                    if ($uid) {
                        $stLink = $pdo->prepare("
                            INSERT IGNORE INTO business_user_roles (business_id, user_id) 
                            VALUES (?, ?)
                        ");
                        $stLink->execute([$new_biz_id, $uid]);
                    }

                    // 3. Initialize default dropdown items
                    $stDropCount = $pdo->prepare("SELECT COUNT(*) FROM dropdown_items WHERE business_id = 1");
                    $stDropCount->execute();
                    $hasDropSrc = (int)$stDropCount->fetchColumn() > 0;

                    if ($hasDropSrc) {
                        $stCopyDrop = $pdo->prepare("
                            INSERT INTO dropdown_items (business_id, category, code, label_km, label_en, sort_order, is_active)
                            SELECT ?, category, code, label_km, label_en, sort_order, is_active
                            FROM dropdown_items
                            WHERE business_id = 1
                        ");
                        $stCopyDrop->execute([$new_biz_id]);
                    } else {
                        $defaults = [
                            ['loan_type', 'general', 'កម្ចីទូទៅ', 'General Loan', 10],
                            ['loan_type', 'motor', 'កម្ចីម៉ូតូ', 'Motorcycle Loan', 20],
                            ['payment_method', 'cash', 'សាច់ប្រាក់', 'Cash', 10],
                            ['payment_method', 'aba', 'ABA Bank', 'ABA Bank', 20]
                        ];
                        $stInsertDrop = $pdo->prepare("
                            INSERT INTO dropdown_items (business_id, category, code, label_km, label_en, sort_order, is_active)
                            VALUES (?, ?, ?, ?, ?, ?, 1)
                        ");
                        foreach ($defaults as $row) {
                            $stInsertDrop->execute([$new_biz_id, $row[0], $row[1], $row[2], $row[3], $row[4]]);
                        }
                    }

                    // 4. Create initial user if requested
                    if (isset($_POST['create_initial_user'])) {
                        $u_name  = trim($_POST['user_name'] ?? '');
                        $u_email = trim($_POST['user_email'] ?? '');
                        $u_pass  = $_POST['user_password'] ?? '';
                        $u_role  = (int)($_POST['user_role'] ?? 0);

                        if ($u_name === '' || $u_email === '' || $u_pass === '' || $u_role <= 0) {
                            throw new Exception('សូមបំពេញព័ត៌មានបុគ្គលិកដំបូងឱ្យបានគ្រប់គ្រាន់');
                        }
                        if (!filter_var($u_email, FILTER_VALIDATE_EMAIL)) {
                            throw new Exception('សូមបញ្ចូលអ៊ីមែលបុគ្គលិកឱ្យបានត្រឹមត្រូវ');
                        }

                        // Email uniqueness check
                        $stEmail = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
                        $stEmail->execute([$u_email]);
                        if ((int)$stEmail->fetchColumn() > 0) {
                            throw new Exception('អ៊ីមែលបុគ្គលិកនេះមានរួចហើយនៅក្នុងប្រព័ន្ធ');
                        }

                        // Create User
                        $hash = password_hash($u_pass, PASSWORD_DEFAULT);
                        $stInsertUser = $pdo->prepare("
                            INSERT INTO users (full_name, email, password_hash, is_active)
                            VALUES (?, ?, ?, 1)
                        ");
                        $stInsertUser->execute([$u_name, $u_email, $hash]);
                        $new_uid = (int)$pdo->lastInsertId();

                        // Assign role
                        $stInsertRole = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                        $stInsertRole->execute([$new_uid, $u_role]);

                        // Link user to new business
                        $stLinkUser = $pdo->prepare("INSERT INTO business_user_roles (business_id, user_id) VALUES (?, ?)");
                        $stLinkUser->execute([$new_biz_id, $new_uid]);
                    }

                    $messages[] = 'បានបង្កើតក្រុមហ៊ុនថ្មីដោយជោគជ័យ!';
                }

                $pdo->commit();

                // Clear fields if added
                if ($business_id <= 0) {
                    $_POST = [];
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'កំហុសក្នុងការព្យាយាមរក្សាទុក៖ ' . $e->getMessage();
            }
        }
    }
}

// ----------------- Load Businesses -----------------
$businesses_list = [];
try {
    $sql = "
        SELECT b.id, b.name, b.type, b.is_active, b.phone, b.address, b.created_at,
               bs.logo_path,
               (SELECT COUNT(DISTINCT user_id) FROM business_user_roles bur WHERE bur.business_id = b.id) AS user_count,
               (SELECT COUNT(*) FROM loans l WHERE l.business_id = b.id AND l.status = 'ACTIVE') AS loan_count,
               (SELECT COUNT(*) FROM pawns p WHERE p.business_id = b.id AND p.status = 'ACTIVE') AS pawn_count
        FROM businesses b
        LEFT JOIN business_settings bs ON bs.business_id = b.id
        ORDER BY b.id DESC
    ";
    $st = $pdo->query($sql);
    $businesses_list = $st->fetchAll();
} catch (Throwable $e) {
    $errors[] = 'មិនអាចទាញយកបញ្ជីក្រុមហ៊ុន៖ ' . $e->getMessage();
}

// ----------------- Load Editing Business if requested -----------------
$edit_biz = null;
$edit_id = (int)($_GET['edit_id'] ?? 0);
if ($edit_id > 0) {
    try {
        $st = $pdo->prepare("SELECT * FROM businesses WHERE id = ? LIMIT 1");
        $st->execute([$edit_id]);
        $edit_biz = $st->fetch();
    } catch (Throwable $e) {}
}

if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
?>
<!doctype html>
<html lang="km">
<head>
    <meta charset="utf-8">
    <title>គ្រប់គ្រងក្រុមហ៊ុន | Finance System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://fonts.googleapis.com/css2?family=Battambang&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body{font-family:'Battambang',sans-serif;background:#f3f4f6;}
        .card-shadow{border:none;border-radius:16px;box-shadow:0 10px 30px rgba(15,23,42,0.08);}
        .alert-success {
            transition: opacity 0.5s ease;
        }
        .aligned-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 35px;
            font-size: 0.82rem;
            font-weight: 600;
            border-radius: 6px;
            padding: 0 14px;
            white-space: nowrap;
        }
    </style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0"><i class="bi bi-building-fill me-2 text-primary"></i>គ្រប់គ្រងក្រុមហ៊ុន</h4>
            <div class="text-muted small mt-1">
                គ្រប់គ្រងក្រុមហ៊ុន/ហាង បង្កើតថ្មី និងកំណត់ប្រភេទសេវាកម្ម។
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="users.php" class="btn btn-outline-secondary px-3">
                <i class="bi bi-people-fill me-1"></i> គ្រប់គ្រងបុគ្គលិក
            </a>
            <a href="roles_permissions.php" class="btn btn-outline-secondary px-3">
                <i class="bi bi-shield-lock-fill me-1"></i> តួនាទី / សិទ្ធិ
            </a>
            <button class="btn btn-primary px-3" data-bs-toggle="modal" data-bs-target="#businessModal" onclick="clearEditForm()">
                <i class="bi bi-plus-circle me-1"></i> បង្កើតក្រុមហ៊ុនថ្មី
            </button>
        </div>
    </div>

    <?php if ($messages): ?>
        <div class="alert alert-success py-2 alert-dismissible fade show" role="alert">
            <?php foreach ($messages as $m): ?>
                <div><i class="bi bi-check-circle-fill me-1"></i> <?= h($m) ?></div>
            <?php endforeach; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2 alert-dismissible fade show" role="alert">
            <?php foreach ($errors as $e): ?>
                <div><i class="bi bi-exclamation-triangle-fill me-1"></i> <?= h($e) ?></div>
            <?php endforeach; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Businesses List (Full Width) -->
    <div class="card card-shadow">
        <div class="card-header bg-white fw-bold py-3 d-flex align-items-center">
            <i class="bi bi-grid-3x3-gap-fill me-2 text-secondary"></i> បញ្ជីក្រុមហ៊ុន / ហាងទាំងអស់
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ក្រុមហ៊ុន</th>
                            <th>ប្រភេទ</th>
                            <th>ទំនាក់ទំនង & អាសយដ្ឋាន</th>
                            <th class="text-center">ស្ថិតិ</th>
                            <th class="text-end px-3">សកម្មភាព</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($businesses_list)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">មិនទាន់មានក្រុមហ៊ុនក្នុងប្រព័ន្ធទេ។</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($businesses_list as $b): ?>
                                <tr>
                                    <td class="px-3">
                                        <div class="d-flex align-items-center">
                                            <div class="me-3">
                                                <?php
                                                $logo_url = '';
                                                if (!empty($b['logo_path'])) {
                                                    $logo_url = (strpos($b['logo_path'], 'http') === 0 || strpos($b['logo_path'], '/') === 0)
                                                        ? $b['logo_path']
                                                        : FIN_BASE_URL . '/' . $b['logo_path'];
                                                }
                                                ?>
                                                <?php if ($logo_url !== ''): ?>
                                                    <img src="<?= h($logo_url) ?>" class="rounded-circle border border-2 border-primary-subtle" style="width: 44px; height: 44px; object-fit: cover;">
                                                <?php else: ?>
                                                    <div class="bg-primary-subtle text-primary rounded-circle d-flex align-items-center justify-content-center fw-bold border border-2 border-primary-subtle" style="width: 44px; height: 44px; font-size: 1.2rem;">
                                                        <i class="bi bi-building"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <div class="fw-bold text-primary">
                                                    <?= h($b['name']) ?>
                                                    <?php if ((int)$b['is_active'] === 1): ?>
                                                        <small class="text-success ms-1" style="font-size: 0.85rem;" title="សកម្ម"><i class="bi bi-check-circle-fill"></i></small>
                                                    <?php else: ?>
                                                        <small class="text-danger ms-1" style="font-size: 0.85rem;" title="អសកម្ម"><i class="bi bi-x-circle-fill"></i></small>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-muted small">ID: <?= (int)$b['id'] ?> | បង្កើត៖ <?= date('d/m/Y', strtotime($b['created_at'])) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($b['type'] === 'loan'): ?>
                                            <span class="aligned-badge bg-primary text-white font-monospace"><i class="bi bi-cash-stack me-2"></i>Loan Only</span>
                                        <?php elseif ($b['type'] === 'pawn'): ?>
                                            <span class="aligned-badge bg-warning text-dark font-monospace"><i class="bi bi-tag-fill me-2"></i>Pawn Only</span>
                                        <?php else: ?>
                                            <span class="aligned-badge bg-secondary text-white font-monospace"><i class="bi bi-grid-fill me-2"></i>Both</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small">
                                        <div><i class="bi bi-telephone me-1 text-muted"></i> <strong>ទូរស័ព្ទ៖</strong> <?= h($b['phone'] ?: 'គ្មាន') ?></div>
                                        <div class="text-muted text-wrap mt-1" style="max-width: 240px;"><i class="bi bi-geo-alt me-1 text-muted"></i><?= h($b['address'] ?: 'គ្មាន') ?></div>
                                    </td>
                                    <td class="text-center small">
                                        <a href="users.php?biz_id=<?= (int)$b['id'] ?>" class="btn btn-sm btn-outline-success d-inline-flex align-items-center justify-content-center" style="height: 35px; border-radius: 6px; padding: 0 14px;" title="បន្ថែមបុគ្គលិក">
                                            <i class="bi bi-person-plus-fill me-2"></i> <?= (int)$b['user_count'] ?> បុគ្គលិក
                                        </a>
                                    </td>
                                    <td class="text-end px-3">
                                        <a href="?edit_id=<?= (int)$b['id'] ?>" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center justify-content-center" style="height: 35px; border-radius: 6px; padding: 0 16px;">
                                            <i class="bi bi-pencil-square me-1.5"></i> កែសម្រួល
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- Bootstrap Popup Modal (Add/Edit Form) -->
<div class="modal fade" id="businessModal" tabindex="-1" aria-labelledby="businessModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 18px; border: none; box-shadow: 0 15px 40px rgba(0,0,0,0.12); max-height: 90vh; overflow-y: auto;">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="businessModalLabel">
                    <i class="bi bi-building me-1 text-primary"></i> <?= $edit_biz ? 'កែសម្រួលព័ត៌មានក្រុមហ៊ុន' : 'បង្កើតក្រុមហ៊ុនថ្មី' ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" onclick="closeModalRedirect()"></button>
            </div>
            <form method="post" id="businessForm" class="row g-3 m-0 p-3">
                <input type="hidden" name="action" value="save_business">
                <input type="hidden" name="business_id" id="form_business_id" value="<?= $edit_biz ? (int)$edit_biz['id'] : 0 ?>">

                <div class="col-12 mt-2">
                    <label class="form-label small mb-1 fw-bold">ឈ្មោះក្រុមហ៊ុន / ហាង</label>
                    <input type="text" name="name" id="form_name" class="form-control py-2" placeholder="ឧ. ណារិន ហិរញ្ញវត្ថុ" required
                           value="<?= h($_POST['name'] ?? ($edit_biz ? $edit_biz['name'] : '')) ?>">
                </div>

                <div class="col-12 mt-3">
                    <label class="form-label small mb-1 fw-bold">ប្រភេទអាជីវកម្ម (Type)</label>
                    <select name="type" id="form_type" class="form-select py-2" required>
                        <?php
                        $selType = $_POST['type'] ?? ($edit_biz ? $edit_biz['type'] : 'both');
                        ?>
                        <option value="both" <?= $selType === 'both' ? 'selected' : '' ?>>ទាំងអស់ (Both Loan & Pawn)</option>
                        <option value="loan" <?= $selType === 'loan' ? 'selected' : '' ?>>កម្ចី/ឥណទាន (Loan Only)</option>
                        <option value="pawn" <?= $selType === 'pawn' ? 'selected' : '' ?>>បញ្ចាំ (Pawn Only)</option>
                    </select>
                </div>

                <div class="col-12 mt-3">
                    <label class="form-label small mb-1 fw-bold">លេខទូរស័ព្ទ</label>
                    <input type="text" name="phone" id="form_phone" class="form-control py-2" placeholder="ឧ. 012 345 678"
                           value="<?= h($_POST['phone'] ?? ($edit_biz ? $edit_biz['phone'] : '')) ?>">
                </div>

                <div class="col-12 mt-3">
                    <label class="form-label small mb-1 fw-bold">អាសយដ្ឋាន</label>
                    <textarea name="address" id="form_address" class="form-control" rows="3" placeholder="ព័ត៌មានអាសយដ្ឋានលម្អិត..."><?= h($_POST['address'] ?? ($edit_biz ? $edit_biz['address'] : '')) ?></textarea>
                </div>

                <div class="col-12 mt-3">
                    <div class="form-check form-switch">
                        <?php
                        $act_checked = 'checked';
                        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                            $act_checked = isset($_POST['is_active']) ? 'checked' : '';
                        } elseif ($edit_biz) {
                            $act_checked = (int)$edit_biz['is_active'] === 1 ? 'checked' : '';
                        }
                        ?>
                        <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="form_is_active" <?= $act_checked ?>>
                        <label class="form-check-label small fw-bold" for="form_is_active">សកម្ម (Active)</label>
                    </div>
                </div>

                <!-- Initial User creation section (Show only when creating new business) -->
                <div id="initial_user_section" class="border-top pt-3 mt-3 <?= $edit_biz ? 'd-none' : '' ?>">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="create_initial_user" id="create_initial_user" onchange="toggleInitialUserFields(this)">
                        <label class="form-check-label small fw-bold text-success" for="create_initial_user">
                            <i class="bi bi-person-plus-fill me-1"></i> បង្កើតគណនីបុគ្គលិកដំបូងសម្រាប់ក្រុមហ៊ុននេះ
                        </label>
                    </div>
                    
                    <div id="initial_user_fields" style="display: none;">
                        <div class="row g-2 bg-light p-2.5 rounded border border-light-subtle">
                            <div class="col-12 col-sm-6">
                                <label class="form-label small mb-0.5 fw-semibold">ឈ្មោះពេញ</label>
                                <input type="text" name="user_name" id="user_name" class="form-control form-control-sm" placeholder="ឧ. ចាន់ ធារី">
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label small mb-0.5 fw-semibold">តួនាទី (Role)</label>
                                <select name="user_role" id="user_role" class="form-select form-select-sm">
                                    <option value="">-- ជ្រើសរើសតួនាទី --</option>
                                    <?php foreach ($roles as $r): ?>
                                        <option value="<?= (int)$r['id'] ?>"><?= h($r['label'] ?: $r['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6 mt-2">
                                <label class="form-label small mb-0.5 fw-semibold">អ៊ីមែល (ឡុកអ៊ីន)</label>
                                <input type="email" name="user_email" id="user_email" class="form-control form-control-sm" placeholder="example@mail.com">
                            </div>
                            <div class="col-12 col-sm-6 mt-2">
                                <label class="form-label small mb-0.5 fw-semibold">ពាក្យសម្ងាត់</label>
                                <input type="password" name="user_password" id="user_password" class="form-control form-control-sm" placeholder="••••••••">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 d-flex gap-2 mt-4">
                    <button type="submit" class="btn btn-primary py-2 flex-fill fw-bold">
                        <i class="bi bi-floppy me-1"></i> រក្សាទុក
                    </button>
                    <button type="button" class="btn btn-secondary py-2" data-bs-dismiss="modal" onclick="closeModalRedirect()">
                        <i class="bi bi-x-circle me-1"></i> បោះបង់
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Show modal automatically on page load if editing
    <?php if ($edit_biz): ?>
    document.addEventListener("DOMContentLoaded", function() {
        var myModal = new bootstrap.Modal(document.getElementById('businessModal'));
        myModal.show();
    });
    <?php endif; ?>

    // Toggle fields for creating initial user
    function toggleInitialUserFields(checkbox) {
        const fields = document.getElementById('initial_user_fields');
        if (checkbox.checked) {
            fields.style.display = 'block';
            document.getElementById('user_name').required = true;
            document.getElementById('user_email').required = true;
            document.getElementById('user_password').required = true;
            document.getElementById('user_role').required = true;
        } else {
            fields.style.display = 'none';
            document.getElementById('user_name').required = false;
            document.getElementById('user_email').required = false;
            document.getElementById('user_password').required = false;
            document.getElementById('user_role').required = false;
        }
    }

    // Redirect when closing the modal during editing to clear edit_id from the URL
    function closeModalRedirect() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('edit_id')) {
            window.location.href = 'businesses.php';
        }
    }

    // Clear form inputs when opening for a new business
    function clearEditForm() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('edit_id')) {
            window.location.href = 'businesses.php?open_modal=1';
        } else {
            document.getElementById('form_business_id').value = '0';
            document.getElementById('form_name').value = '';
            document.getElementById('form_type').value = 'both';
            document.getElementById('form_phone').value = '';
            document.getElementById('form_address').value = '';
            document.getElementById('form_is_active').checked = true;
            document.getElementById('businessModalLabel').innerHTML = '<i class="bi bi-building me-1 text-primary"></i> បង្កើតក្រុមហ៊ុនថ្មី';
            
            // Clear user creation fields
            const checkbox = document.getElementById('create_initial_user');
            if (checkbox) {
                checkbox.checked = false;
                toggleInitialUserFields(checkbox);
            }
            const section = document.getElementById('initial_user_section');
            if (section) {
                section.classList.remove('d-none');
            }
        }
    }

    // Open empty modal if open_modal parameter is present (after redirecting)
    document.addEventListener("DOMContentLoaded", function() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('open_modal')) {
            var myModal = new bootstrap.Modal(document.getElementById('businessModal'));
            myModal.show();
            // clean up url query without reloading page
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    });

    // Auto-close success alerts after 3 seconds with a smooth fade
    document.addEventListener("DOMContentLoaded", function() {
        setTimeout(function() {
            const successAlerts = document.querySelectorAll('.alert-success');
            successAlerts.forEach(function(alert) {
                alert.style.opacity = '0';
                setTimeout(function() {
                    alert.remove();
                }, 500);
            });
        }, 3000);
    });
</script>
</body>
</html>
