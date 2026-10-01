<?php
// /finance/admin/users.php
// Page for managing system users, their roles, and business access

require_once '../includes/auth.php';
require_once '../includes/helpers.php';

fin_require_login();
fin_require_permission('manage_users');

global $pdo;

$messages = [];
$errors   = [];

// ----------------- Load roles & businesses -----------------
$roles = [];
try {
    if (fin_is_super_admin()) {
        $st = $pdo->query("SELECT id, name, label FROM roles ORDER BY id ASC");
    } else {
        $st = $pdo->query("SELECT id, name, label FROM roles WHERE name != 'super_admin' ORDER BY id ASC");
    }
    $roles = $st->fetchAll();
} catch (Throwable $e) {}

$businesses = [];
try {
    if (fin_is_super_admin()) {
        $st = $pdo->query("SELECT id, name FROM businesses ORDER BY id ASC");
    } else {
        $st = $pdo->prepare("SELECT id, name FROM businesses WHERE id = ?");
        $st->execute([$current_biz_id]);
    }
    $businesses = $st->fetchAll();
} catch (Throwable $e) {}

// Current active business
$current_biz_id = fin_current_business_id();

// ----------------- Handle Form Submission -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Add or Edit User
    if ($action === 'save_user') {
        $user_id   = (int)($_POST['user_id'] ?? 0);
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $role_id   = (int)($_POST['role_id'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $sel_bizs  = $_POST['biz_ids'] ?? [];

        if ($full_name === '') {
            $errors[] = 'សូមបញ្ចូលឈ្មោះពេញរបស់អ្នកប្រើប្រាស់';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'សូមបញ្ចូលអ៊ីមែលឱ្យបានត្រឹមត្រូវ';
        }
        if ($role_id <= 0) {
            $errors[] = 'សូមជ្រើសរើសតួនាទី';
        }
        if (empty($sel_bizs)) {
            $errors[] = 'សូមជ្រើសរើសក្រុមហ៊ុនយ៉ាងហោចណាស់មួយ';
        }
        if ($user_id <= 0 && $password === '') {
            $errors[] = 'សូមបញ្ចូលពាក្យសម្ងាត់សម្រាប់អ្នកប្រើប្រាស់ថ្មី';
        }

        // Role & Target security checks
        if (!$errors) {
            if ($role_id > 0) {
                $stRoleName = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
                $stRoleName->execute([$role_id]);
                $rName = $stRoleName->fetchColumn();
                if ($rName === 'super_admin' && !fin_is_super_admin()) {
                    $errors[] = 'អ្នកមិនមានសិទ្ធិកំណត់តួនាទីជា Super Admin ទេ';
                }
            }
            if ($user_id > 0 && !fin_is_super_admin()) {
                $stCheckSuper = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM user_roles ur 
                    JOIN roles r ON r.id = ur.role_id 
                    WHERE ur.user_id = ? AND r.name = 'super_admin'
                ");
                $stCheckSuper->execute([$user_id]);
                if ((int)$stCheckSuper->fetchColumn() > 0) {
                    $errors[] = 'អ្នកមិនមានសិទ្ធិកែប្រែអ្នកប្រើប្រាស់ជាន់ខ្ពស់ (Super Admin) ទេ';
                }

                $stCheckBiz = $pdo->prepare("
                    SELECT COUNT(*) FROM business_user_roles WHERE user_id = ? AND business_id = ?
                ");
                $stCheckBiz->execute([$user_id, $current_biz_id]);
                if ((int)$stCheckBiz->fetchColumn() === 0) {
                    $errors[] = 'អ្នកមិនមានសិទ្ធិកែប្រែអ្នកប្រើប្រាស់ក្រៅពីក្រុមហ៊ុនរបស់អ្នកទេ';
                }
            }
        }

        // Handle Photo Upload
        $photo_path = null;
        if (!$errors && isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['photo']['tmp_name'];
            $size = $_FILES['photo']['size'];
            if ($size > 3 * 1024 * 1024) {
                $errors[] = 'រូបភាពធំពេក (ទំហំអតិបរមា 3MB)';
            } else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $tmp);
                finfo_close($finfo);
                $allowed = [
                    'image/png'  => 'png',
                    'image/jpeg' => 'jpg',
                    'image/webp' => 'webp',
                ];
                if (!isset($allowed[$mime])) {
                    $errors[] = 'រូបភាពត្រូវតែជា PNG, JPG ឬ WEBP';
                } else {
                    $ext = $allowed[$mime];
                    $dir = realpath(__DIR__ . '/..') . '/uploads/profiles';
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0775, true);
                    }
                    $fname = "profile_" . date('Ymd_His') . "_" . bin2hex(random_bytes(4)) . "." . $ext;
                    $dest = $dir . '/' . $fname;
                    if (move_uploaded_file($tmp, $dest)) {
                        $photo_path = "uploads/profiles/" . $fname;
                    } else {
                        $errors[] = 'មិនអាចរក្សាទុករូបភាពបានឡើយ';
                    }
                }
            }
        }

        // Email uniqueness check
        if (!$errors) {
            try {
                if ($user_id > 0) {
                    $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
                    $st->execute([$email, $user_id]);
                } else {
                    $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
                    $st->execute([$email]);
                }
                if ((int)$st->fetchColumn() > 0) {
                    $errors[] = 'អ៊ីមែលនេះមានរួចហើយនៅក្នុងប្រព័ន្ធ';
                }
            } catch (Throwable $e) {
                $errors[] = 'កំហុសពិនិត្យអ៊ីមែល៖ ' . $e->getMessage();
            }
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                if ($user_id > 0) {
                    // Fetch old photo path to delete if new photo is uploaded
                    $st = $pdo->prepare("SELECT photo_path FROM users WHERE id = ? LIMIT 1");
                    $st->execute([$user_id]);
                    $old_photo = $st->fetchColumn();

                    // Update user
                    if ($password !== '') {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        if ($photo_path !== null) {
                            $st = $pdo->prepare("
                                UPDATE users 
                                SET full_name = ?, email = ?, password_hash = ?, is_active = ?, photo_path = ?
                                WHERE id = ?
                            ");
                            $st->execute([$full_name, $email, $hash, $is_active, $photo_path, $user_id]);
                            if ($old_photo && is_file(realpath(__DIR__ . '/..') . '/' . $old_photo)) {
                                @unlink(realpath(__DIR__ . '/..') . '/' . $old_photo);
                            }
                        } else {
                            $st = $pdo->prepare("
                                UPDATE users 
                                SET full_name = ?, email = ?, password_hash = ?, is_active = ?
                                WHERE id = ?
                            ");
                            $st->execute([$full_name, $email, $hash, $is_active, $user_id]);
                        }
                    } else {
                        if ($photo_path !== null) {
                            $st = $pdo->prepare("
                                UPDATE users 
                                SET full_name = ?, email = ?, is_active = ?, photo_path = ?
                                WHERE id = ?
                            ");
                            $st->execute([$full_name, $email, $is_active, $photo_path, $user_id]);
                            if ($old_photo && is_file(realpath(__DIR__ . '/..') . '/' . $old_photo)) {
                                @unlink(realpath(__DIR__ . '/..') . '/' . $old_photo);
                            }
                        } else {
                            $st = $pdo->prepare("
                                UPDATE users 
                                SET full_name = ?, email = ?, is_active = ?
                                WHERE id = ?
                            ");
                            $st->execute([$full_name, $email, $is_active, $user_id]);
                        }
                    }

                    // Update user_roles
                    $st = $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?");
                    $st->execute([$user_id]);

                    $st = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                    $st->execute([$user_id, $role_id]);

                    // Update business_user_roles
                    if (fin_is_super_admin()) {
                        $st = $pdo->prepare("DELETE FROM business_user_roles WHERE user_id = ?");
                        $st->execute([$user_id]);

                        $st = $pdo->prepare("INSERT INTO business_user_roles (business_id, user_id) VALUES (?, ?)");
                        foreach ($sel_bizs as $bid) {
                            $st->execute([(int)$bid, $user_id]);
                        }
                    } else {
                        // Regular admin only controls access to current business
                        $has_access = in_array((int)$current_biz_id, array_map('intval', $sel_bizs), true);
                        if ($has_access) {
                            $st = $pdo->prepare("INSERT IGNORE INTO business_user_roles (business_id, user_id) VALUES (?, ?)");
                            $st->execute([$current_biz_id, $user_id]);
                        } else {
                            $st = $pdo->prepare("DELETE FROM business_user_roles WHERE business_id = ? AND user_id = ?");
                            $st->execute([$current_biz_id, $user_id]);
                        }
                    }

                    $messages[] = 'បានកែសម្រួលព័ត៌មានអ្នកប្រើប្រាស់ដោយជោគជ័យ!';
                } else {
                    // Create user
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    if ($photo_path !== null) {
                        $st = $pdo->prepare("
                            INSERT INTO users (full_name, email, password_hash, is_active, photo_path)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $st->execute([$full_name, $email, $hash, $is_active, $photo_path]);
                    } else {
                        $st = $pdo->prepare("
                            INSERT INTO users (full_name, email, password_hash, is_active)
                            VALUES (?, ?, ?, ?)
                        ");
                        $st->execute([$full_name, $email, $hash, $is_active]);
                    }
                    $new_uid = (int)$pdo->lastInsertId();

                    // Insert user_roles
                    $st = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                    $st->execute([$new_uid, $role_id]);

                    // Insert business_user_roles
                    $st = $pdo->prepare("INSERT INTO business_user_roles (business_id, user_id) VALUES (?, ?)");
                    if (fin_is_super_admin()) {
                        foreach ($sel_bizs as $bid) {
                            $st->execute([(int)$bid, $new_uid]);
                        }
                    } else {
                        $st->execute([$current_biz_id, $new_uid]);
                    }

                    $messages[] = 'បានបន្ថែមអ្នកប្រើប្រាស់ថ្មីដោយជោគជ័យ!';
                }

                $pdo->commit();

                // Clear input values on success if adding new
                if ($user_id <= 0) {
                    $_POST = [];
                }
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'កំហុសក្នុងការព្យាយាមរក្សាទុក៖ ' . $e->getMessage();
            }
        }
    }
}

// ----------------- Load users -----------------
$users_list = [];
try {
    if (fin_is_super_admin()) {
        $sql = "
            SELECT u.id, u.email, u.full_name, u.is_active, u.created_at, u.photo_path,
                   r.id AS role_id, r.name AS role_name, r.label AS role_label,
                   (
                       SELECT GROUP_CONCAT(b.name SEPARATOR ', ')
                       FROM business_user_roles bur
                       JOIN businesses b ON b.id = bur.business_id
                       WHERE bur.user_id = u.id
                   ) AS business_names
            FROM users u
            LEFT JOIN user_roles ur ON ur.user_id = u.id
            LEFT JOIN roles r ON r.id = ur.role_id
            ORDER BY u.id DESC
        ";
        $st = $pdo->query($sql);
        $users_list = $st->fetchAll();
    } else {
        $sql = "
            SELECT u.id, u.email, u.full_name, u.is_active, u.created_at, u.photo_path,
                   r.id AS role_id, r.name AS role_name, r.label AS role_label,
                   (
                       SELECT GROUP_CONCAT(b.name SEPARATOR ', ')
                       FROM business_user_roles bur
                       JOIN businesses b ON b.id = bur.business_id
                       WHERE bur.user_id = u.id
                   ) AS business_names
            FROM users u
            JOIN business_user_roles bur2 ON bur2.user_id = u.id AND bur2.business_id = ?
            LEFT JOIN user_roles ur ON ur.user_id = u.id
            LEFT JOIN roles r ON r.id = ur.role_id
            WHERE NOT EXISTS (
                SELECT 1 FROM user_roles ur2 JOIN roles r2 ON r2.id = ur2.role_id WHERE ur2.user_id = u.id AND r2.name = 'super_admin'
            )
            ORDER BY u.id DESC
        ";
        $st = $pdo->prepare($sql);
        $st->execute([$current_biz_id]);
        $users_list = $st->fetchAll();
    }
} catch (Throwable $e) {}

// ----------------- Load editing user if requested -----------------
$edit_user = null;
$edit_user_biz_ids = [];
$edit_id = (int)($_GET['edit_id'] ?? 0);
if ($edit_id > 0) {
    try {
        $allowed = true;
        if (!fin_is_super_admin()) {
            $stCheckSuper = $pdo->prepare("
                SELECT COUNT(*) 
                FROM user_roles ur 
                JOIN roles r ON r.id = ur.role_id 
                WHERE ur.user_id = ? AND r.name = 'super_admin'
            ");
            $stCheckSuper->execute([$edit_id]);
            $isSuper = (int)$stCheckSuper->fetchColumn() > 0;

            $stCheckBiz = $pdo->prepare("
                SELECT COUNT(*) FROM business_user_roles WHERE user_id = ? AND business_id = ?
            ");
            $stCheckBiz->execute([$edit_id, $current_biz_id]);
            $hasAccess = (int)$stCheckBiz->fetchColumn() > 0;

            if ($isSuper || !$hasAccess) {
                $allowed = false;
            }
        }

        if ($allowed) {
            $st = $pdo->prepare("
                SELECT u.id, u.email, u.full_name, u.is_active, u.photo_path, r.id AS role_id
                FROM users u
                LEFT JOIN user_roles ur ON ur.user_id = u.id
                LEFT JOIN roles r ON r.id = ur.role_id
                WHERE u.id = ?
                LIMIT 1
            ");
            $st->execute([$edit_id]);
            $edit_user = $st->fetch();

            if ($edit_user) {
                $st = $pdo->prepare("SELECT business_id FROM business_user_roles WHERE user_id = ?");
                $st->execute([$edit_id]);
                $edit_user_biz_ids = $st->fetchAll(PDO::FETCH_COLUMN);
                $edit_user_biz_ids = array_map('intval', $edit_user_biz_ids);
            }
        }
    } catch (Throwable $e) {}
}
?>
<!doctype html>
<html lang="km">
<head>
    <meta charset="utf-8">
    <title>គ្រប់គ្រងអ្នកប្រើប្រាស់ | Finance System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://fonts.googleapis.com/css2?family=Battambang&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body{font-family:'Battambang',sans-serif;background:#f3f4f6;}
        .card-shadow{border:none;border-radius:16px;box-shadow:0 10px 30px rgba(15,23,42,0.08);}
        .cursor-pointer{cursor:pointer;}
        .hover-trigger:hover .hover-opacity{opacity:1 !important;}
    </style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0">គ្រប់គ្រងអ្នកប្រើប្រាស់</h4>
        <div class="text-muted small">
            គ្រប់គ្រងគណនីបុគ្គលិក កំណត់តួនាទី និងសិទ្ធិចូលមើលតាមក្រុមហ៊ុន។
        </div>
    </div>

    <?php if ($messages): ?>
        <div class="alert alert-success py-2">
            <?php foreach ($messages as $m): ?>
                <div>✔ <?= h($m) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger py-2">
            <?php foreach ($errors as $e): ?>
                <div>⚠ <?= h($e) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <!-- Left: Form to Add/Edit User -->
        <div class="col-12 col-lg-4">
            <div class="card card-shadow">
                <div class="card-header bg-white fw-bold">
                    <?= $edit_user ? '✏ កែសម្រួលព័ត៌មានអ្នកប្រើប្រាស់' : '➕ បន្ថែមអ្នកប្រើប្រាស់ថ្មី' ?>
                </div>
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data" class="row g-3">
                        <input type="hidden" name="action" value="save_user">
                        <input type="hidden" name="user_id" value="<?= $edit_user ? (int)$edit_user['id'] : 0 ?>">

                        <div class="col-12">
                            <div class="d-flex align-items-center gap-3">
                                <div class="flex-grow-1">
                                    <label class="form-label small mb-1 fw-bold">ឈ្មោះពេញ</label>
                                    <input type="text" name="full_name" class="form-control" placeholder="ឧ. កុសល សុខ" required
                                           value="<?= h($_POST['full_name'] ?? ($edit_user ? $edit_user['full_name'] : '')) ?>">
                                </div>
                                <div class="text-center" style="margin-top: 24px;">
                                    <label for="photoInput" class="position-relative d-inline-block cursor-pointer hover-trigger" title="ជ្រើសរើសរូបថតប្រវត្តិរូប">
                                        <div class="rounded-circle border d-flex align-items-center justify-content-center bg-light position-relative" style="width: 48px; height: 48px; overflow: hidden;">
                                            <?php if ($edit_user && !empty($edit_user['photo_path'])): ?>
                                                <img id="profilePreview" src="../<?= h($edit_user['photo_path']) ?>" style="width:100%; height:100%; object-fit:cover;">
                                            <?php else: ?>
                                                <div id="profileDefault" class="text-muted d-flex align-items-center justify-content-center">
                                                    <i class="bi bi-cloud-arrow-up" style="font-size: 1.4rem;"></i>
                                                </div>
                                            <?php endif; ?>
                                            <!-- Hover Overlay -->
                                            <div class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center bg-dark bg-opacity-50 opacity-0 hover-opacity" style="transition: opacity .15s ease;">
                                                <i class="bi bi-cloud-arrow-up-fill text-white" style="font-size: 1.1rem;"></i>
                                            </div>
                                        </div>
                                        <input type="file" name="photo" id="photoInput" class="d-none" accept="image/*" onchange="previewImage(this)">
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label small mb-1 fw-bold">អ៊ីមែល (ឡុកអ៊ីន)</label>
                            <input type="email" name="email" class="form-control" placeholder="example@mail.com" required
                                   value="<?= h($_POST['email'] ?? ($edit_user ? $edit_user['email'] : '')) ?>">
                        </div>

                        <div class="col-12">
                            <label class="form-label small mb-1 fw-bold">
                                ពាក្យសម្ងាត់ <?= $edit_user ? '<span class="text-muted fw-normal">(ទុកទទេរ បើមិនចង់ប្តូរ)</span>' : '' ?>
                            </label>
                            <input type="password" name="password" class="form-control" placeholder="••••••••" <?= $edit_user ? '' : 'required' ?>>
                        </div>

                        <div class="col-12">
                            <label class="form-label small mb-1 fw-bold">តួនាទី (Role)</label>
                            <select name="role_id" class="form-select" required>
                                <option value="">-- ជ្រើសរើសតួនាទី --</option>
                                <?php foreach ($roles as $r): ?>
                                    <?php 
                                    $selected = '';
                                    $current_r_id = (int)($_POST['role_id'] ?? ($edit_user ? $edit_user['role_id'] : 0));
                                    if ($current_r_id === (int)$r['id']) $selected = 'selected';
                                    ?>
                                    <option value="<?= (int)$r['id'] ?>" <?= $selected ?>>
                                        <?= h($r['label'] ?: $r['name']) ?> (<?= h($r['name']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label small mb-1 fw-bold">សិទ្ធិចូលមើលក្រុមហ៊ុន (Businesses)</label>
                            <?php if (fin_is_super_admin()): ?>
                                <div class="border rounded p-2 bg-light-subtle">
                                    <?php foreach ($businesses as $b): ?>
                                        <?php
                                        $checked = '';
                                        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                                            $post_bizs = $_POST['biz_ids'] ?? [];
                                            if (in_array((int)$b['id'], array_map('intval', $post_bizs), true)) $checked = 'checked';
                                        } elseif ($edit_user) {
                                            if (in_array((int)$b['id'], $edit_user_biz_ids, true)) $checked = 'checked';
                                        } else {
                                            // Default: check current active business or URL parameter biz_id
                                            $target_biz_id = isset($_GET['biz_id']) ? (int)$_GET['biz_id'] : $current_biz_id;
                                            if ((int)$b['id'] === (int)$target_biz_id) $checked = 'checked';
                                        }
                                        ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="biz_ids[]" 
                                                   id="biz<?= (int)$b['id'] ?>" value="<?= (int)$b['id'] ?>" <?= $checked ?>>
                                            <label class="form-check-label small" for="biz<?= (int)$b['id'] ?>">
                                                <?= h($b['name']) ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <input type="hidden" name="biz_ids[]" value="<?= (int)$current_biz_id ?>">
                                <div class="border rounded p-2 bg-light-subtle">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" checked disabled>
                                        <label class="form-check-label small">
                                            <?= h($businesses[0]['name'] ?? 'ក្រុមហ៊ុនបច្ចុប្បន្ន') ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12">
                            <div class="form-check form-switch">
                                <?php
                                $act_checked = 'checked';
                                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                                    $act_checked = isset($_POST['is_active']) ? 'checked' : '';
                                } elseif ($edit_user) {
                                    $act_checked = (int)$edit_user['is_active'] === 1 ? 'checked' : '';
                                }
                                ?>
                                <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="is_active" <?= $act_checked ?>>
                                <label class="form-check-label small fw-bold" for="is_active">គណនីសកម្ម (Active)</label>
                            </div>
                        </div>

                        <div class="col-12 d-flex gap-2">
                            <button class="btn btn-primary btn-sm flex-fill py-2">
                                💾 រក្សាទុក
                            </button>
                            <?php if ($edit_user): ?>
                                <a href="users.php" class="btn btn-secondary btn-sm py-2">
                                    បោះបង់
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Right: Users List -->
        <div class="col-12 col-lg-8">
            <div class="card card-shadow">
                <div class="card-header bg-white fw-bold">
                    👥 បញ្ជីអ្នកប្រើប្រាស់ក្នុងប្រព័ន្ធ
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>ឈ្មោះ និងអ៊ីមែល</th>
                                    <th>តួនាទី</th>
                                    <th>ក្រុមហ៊ុនអនុញ្ញាត</th>
                                    <th class="text-center">ស្ថានភាព</th>
                                    <th class="text-end px-3">សកម្មភាព</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users_list)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted">មិនទាន់មានអ្នកប្រើប្រាស់ទេ។</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($users_list as $u): ?>
                                        <tr>
                                            <td class="px-3">
                                                <div class="d-flex align-items-center">
                                                    <div class="me-2">
                                                        <?php if (!empty($u['photo_path'])): ?>
                                                            <img src="../<?= h($u['photo_path']) ?>" class="rounded-circle" style="width: 38px; height: 38px; object-fit: cover; border: 1px solid #ddd;">
                                                        <?php else: ?>
                                                            <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 38px; height: 38px; font-size: 0.95rem;">
                                                                <?= h(mb_strtoupper(mb_substr($u['full_name'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <div class="fw-bold"><?= h($u['full_name']) ?></div>
                                                        <div class="text-muted small"><?= h($u['email']) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary text-wrap">
                                                    <?= h($u['role_label'] ?: ($u['role_name'] ?: 'គ្មាន')) ?>
                                                </span>
                                            </td>
                                            <td class="small text-muted">
                                                <?= h($u['business_names'] ?: 'គ្មាន') ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ((int)$u['is_active'] === 1): ?>
                                                    <span class="badge bg-success">សកម្ម</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">អសកម្ម</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end px-3">
                                                <a href="?edit_id=<?= (int)$u['id'] ?>" class="btn btn-sm btn-outline-primary py-1">
                                                    ✏ កែសម្រួល
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
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function previewImage(input) {
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = function(e) {
      let img = document.getElementById('profilePreview');
      if (!img) {
        img = document.createElement('img');
        img.id = 'profilePreview';
        img.style.width = '100%';
        img.style.height = '100%';
        img.style.objectFit = 'cover';
        
        const container = document.getElementById('profileDefault').parentNode;
        const def = document.getElementById('profileDefault');
        if (def) def.remove();
        container.appendChild(img);
      }
      img.src = e.target.result;
    }
    reader.readAsDataURL(input.files[0]);
  }
}
</script>
</body>
</html>
