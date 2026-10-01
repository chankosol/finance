<?php
// /finance/admin/roles_permissions.php
// Page for managing roles and their permissions (checkbox matrix)

require_once '../includes/auth.php';
require_once '../includes/helpers.php';

fin_require_login();
fin_require_permission('manage_roles_permissions');
global $pdo;

// If you later add a "super admin" check, call it here
if (function_exists('fin_require_super_admin')) {
    fin_require_super_admin(); // optional; will only run if defined
}

// ----------------- Handle Add Role -----------------
$messages = [];
$errors   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Add new role
    if ($action === 'add_role') {
        $name  = trim($_POST['name'] ?? '');
        $label = trim($_POST['label'] ?? '');

        if ($name === '' || !preg_match('/^[a-z0-9_]+$/i', $name)) {
            $errors[] = 'សូមបញ្ចូល role name (a-z, 0-9, _)';
        }
        if ($label === '') {
            $errors[] = 'សូមបញ្ចូលឈ្មោះតួនាទីជាភាសាខ្មែរ/អង់គ្លេស';
        }

        if (!$errors) {
            try {
                $st = $pdo->prepare("
                    INSERT INTO roles (name, label, is_system)
                    VALUES (?, ?, 0)
                ");
                $st->execute([$name, $label]);
                $messages[] = 'បានបន្ថែមតួនាទីថ្មីដោយជោគជ័យ!';
            } catch (Throwable $e) {
                $errors[] = 'មិនអាចបន្ថែមតួនាទីបានទេ (អាចមានឈ្មោះស្ទួន)។';
            }
        }
    }

    // Edit role
    if ($action === 'edit_role') {
        $role_id = (int)($_POST['role_id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $label   = trim($_POST['label'] ?? '');

        $is_sys = false;
        try {
            $st = $pdo->prepare("SELECT is_system, name FROM roles WHERE id = ?");
            $st->execute([$role_id]);
            $r_orig = $st->fetch();
            if ($r_orig) {
                $is_sys = (int)$r_orig['is_system'] === 1;
                if ($is_sys) $name = $r_orig['name'];
            } else {
                $errors[] = 'រកមិនឃើញតួនាទីនេះទេ។';
            }
        } catch (Throwable $e) {}

        if (!$is_sys && ($name === '' || !preg_match('/^[a-z0-9_]+$/i', $name))) {
            $errors[] = 'សូមបញ្ចូល role name (a-z, 0-9, _)';
        }
        if ($label === '') {
            $errors[] = 'សូមបញ្ចូលឈ្មោះតួនាទីជាភាសាខ្មែរ/អង់គ្លេស';
        }

        if (!$errors) {
            try {
                if ($is_sys) {
                    $st = $pdo->prepare("UPDATE roles SET label = ? WHERE id = ?");
                    $st->execute([$label, $role_id]);
                } else {
                    $st = $pdo->prepare("UPDATE roles SET name = ?, label = ? WHERE id = ?");
                    $st->execute([$name, $label, $role_id]);
                }
                $messages[] = 'បានកែសម្រួលតួនាទីដោយជោគជ័យ!';
                header("Location: roles_permissions.php?role_id=" . $role_id);
                exit;
            } catch (Throwable $e) {
                $errors[] = 'មិនអាចកែសម្រួលតួនាទីបានទេ (អាចមានឈ្មោះស្ទួន)។';
            }
        }
    }

    // Update permissions for a role
    if ($action === 'update_permissions') {
        $role_id = (int)($_POST['role_id'] ?? 0);
        $perm_ids = $_POST['perm'] ?? [];

        if ($role_id <= 0) {
            $errors[] = 'សូមជ្រើសរើសតួនាទីជាមុនសិន។';
        } else {
            // Normalize permission IDs to integers
            $ids_clean = [];
            foreach ($perm_ids as $pid) {
                $pid = (int)$pid;
                if ($pid > 0) $ids_clean[] = $pid;
            }

            try {
                $pdo->beginTransaction();

                // delete old mappings
                $del = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
                $del->execute([$role_id]);

                // insert new ones
                if (!empty($ids_clean)) {
                    $ins = $pdo->prepare("
                        INSERT INTO role_permissions (role_id, permission_id)
                        VALUES (?, ?)
                    ");
                    foreach ($ids_clean as $pid) {
                        $ins->execute([$role_id, $pid]);
                    }
                }

                $pdo->commit();
                $messages[] = 'បានធ្វើបច្ចុប្បន្នភាពសិទ្ធិសម្រាប់តួនាទីនេះ។';
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'មានបញ្ហាក្នុងការរក្សាទុកសិទ្ធិ។';
            }
        }
    }
}

// ----------------- Load roles -----------------
$roles = [];
try {
    $st = $pdo->query("
        SELECT id, name, label, is_system
        FROM roles
        ORDER BY is_system DESC, id ASC
    ");
    $roles = $st->fetchAll();
} catch (Throwable $e) {
    $roles = [];
}

// Selected role
$selected_role_id = (int)($_GET['role_id'] ?? ($_POST['role_id'] ?? 0));
if ($selected_role_id <= 0 && !empty($roles)) {
    $selected_role_id = (int)$roles[0]['id'];
}

// Load editing role
$edit_role = null;
$edit_role_id = (int)($_GET['edit_role_id'] ?? 0);
if ($edit_role_id > 0) {
    foreach ($roles as $r) {
        if ((int)$r['id'] === $edit_role_id) {
            $edit_role = $r;
            break;
        }
    }
}

// ----------------- Load permissions -----------------
$permissions = [];
try {
    $st = $pdo->query("
        SELECT id, code, label, group_key, is_system
        FROM permissions
        ORDER BY group_key, id
    ");
    $permissions = $st->fetchAll();
} catch (Throwable $e) {
    $permissions = [];
}

// Group permissions by group_key
$perm_by_group = [];
foreach ($permissions as $p) {
    $g = $p['group_key'] ?: 'other';
    if (!isset($perm_by_group[$g])) {
        $perm_by_group[$g] = [];
    }
    $perm_by_group[$g][] = $p;
}

// ----------------- Load current permissions for selected role -----------------
$current_perm_ids = [];
if ($selected_role_id > 0) {
    try {
        $st = $pdo->prepare("
            SELECT permission_id
            FROM role_permissions
            WHERE role_id = ?
        ");
        $st->execute([$selected_role_id]);
        $current_perm_ids = $st->fetchAll(PDO::FETCH_COLUMN);
        $current_perm_ids = array_map('intval', $current_perm_ids);
    } catch (Throwable $e) {
        $current_perm_ids = [];
    }
}
?>
<!doctype html>
<html lang="km">
<head>
    <meta charset="utf-8">
    <title>តួនាទី / សិទ្ធិ | Finance System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://fonts.googleapis.com/css2?family=Battambang&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body{font-family:'Battambang',sans-serif;background:#f3f4f6;}
        .card-shadow{border:none;border-radius:16px;box-shadow:0 10px 30px rgba(15,23,42,0.08);}
        .perm-group-title{font-weight:600;font-size:.95rem;}
        .perm-item{font-size:.9rem;}
        .role-pill{font-size:.9rem;}
    </style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0">តួនាទី / សិទ្ធិ</h4>
        <div class="text-muted small">
            ប្រើសម្រាប់កំណត់ថា តួនាទីណា អាចធ្វើអ្វីបានខ្លះក្នុងប្រព័ន្ធ។
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
        <!-- Left: roles list + add -->
        <div class="col-12 col-lg-4">
            <div class="card card-shadow mb-3">
                <div class="card-header bg-white">
                    <strong>បញ្ជីតួនាទី</strong>
                </div>
                <div class="card-body">
                    <?php if (empty($roles)): ?>
                        <div class="text-muted">មិនទាន់មានតួនាទីទេ។</div>
                    <?php else: ?>
                        <div class="list-group mb-3">
                            <?php foreach ($roles as $r): ?>
                                <?php
                                $is_sys = (int)$r['is_system'] === 1;
                                $active = ($selected_role_id == $r['id']);
                                ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center<?= $active?' list-group-item-info':''; ?>">
                                    <a href="?role_id=<?= (int)$r['id'] ?>" class="text-decoration-none text-dark flex-grow-1">
                                        <div class="fw-bold"><?= h($r['label'] ?: $r['name']) ?></div>
                                        <div class="text-muted small">
                                            <?= h($r['name']) ?>
                                            <?php if ($is_sys): ?>
                                                <span class="badge bg-warning text-dark ms-1">ប្រព័ន្ធ</span>
                                            <?php endif; ?>
                                        </div>
                                    </a>
                                    <div>
                                        <a href="?edit_role_id=<?= (int)$r['id'] ?>&role_id=<?= $selected_role_id ?>" class="btn btn-sm btn-outline-secondary py-0 px-1" title="កែសម្រួល">
                                            ✏
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <hr>

                    <h6 class="mb-2"><?= $edit_role ? '✏ កែសម្រួលតួនាទី' : '➕ បន្ថែមតួនាទីថ្មី' ?></h6>
                    <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="<?= $edit_role ? 'edit_role' : 'add_role' ?>">
                        <input type="hidden" name="role_id" value="<?= $edit_role ? (int)$edit_role['id'] : 0 ?>">
                        <div class="col-12">
                            <label class="form-label small mb-1">
                                Role name (អក្សរអង់គ្លេស a-z0-9_)
                            </label>
                            <input type="text" name="name" class="form-control form-control-sm"
                                   placeholder="loan_officer" required
                                   value="<?= h($_POST['name'] ?? ($edit_role ? $edit_role['name'] : '')) ?>"
                                   <?= ($edit_role && (int)$edit_role['is_system'] === 1) ? 'readonly disabled' : '' ?>>
                            <?php if ($edit_role && (int)$edit_role['is_system'] === 1): ?>
                                <div class="text-muted small" style="font-size:0.75rem;">តួនាទីប្រព័ន្ធមិនអាចប្ដូរឈ្មោះកូដបានទេ។</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-1">
                                ឈ្មោះតួនាទី (បង្ហាញក្នុងប្រព័ន្ធ)
                            </label>
                            <input type="text" name="label" class="form-control form-control-sm"
                                   placeholder="មន្ត្រីឥណទាន" required
                                   value="<?= h($_POST['label'] ?? ($edit_role ? $edit_role['label'] : '')) ?>">
                        </div>
                        <div class="col-12 d-flex gap-2 mt-2">
                            <button class="btn btn-primary btn-sm flex-fill">
                                <?= $edit_role ? 'រក្សាទុកការកែប្រែ' : 'រក្សាទុកតួនាទី' ?>
                            </button>
                            <?php if ($edit_role): ?>
                                <a href="roles_permissions.php?role_id=<?= $selected_role_id ?>" class="btn btn-secondary btn-sm">
                                    បោះបង់
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Right: permissions matrix -->
        <div class="col-12 col-lg-8">
            <div class="card card-shadow">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <div>
                        <strong>សិទ្ធិតាមតួនាទី</strong>
                        <?php if ($selected_role_id > 0): ?>
                            <?php
                            $selected_label = '';
                            foreach ($roles as $r) {
                                if ((int)$r['id'] === $selected_role_id) {
                                    $selected_label = $r['label'] ?: $r['name'];
                                    break;
                                }
                            }
                            ?>
                            <span class="text-muted small ms-2">
                                (តួនាទី៖ <?= h($selected_label) ?>)
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card-body">
                    <?php if ($selected_role_id <= 0): ?>
                        <div class="text-muted">
                            សូមជ្រើសរើសតួនាទីខាងឆ្វេងជាមុនសិន។
                        </div>
                    <?php elseif (empty($permissions)): ?>
                        <div class="text-muted">
                            មិនទាន់កំណត់តារាងសិទ្ធិ (permissions) នៅក្នុង database ទេ។
                        </div>
                    <?php else: ?>
                        <form method="post">
                            <input type="hidden" name="action" value="update_permissions">
                            <input type="hidden" name="role_id" value="<?= (int)$selected_role_id ?>">

                            <div class="row g-3">
                                <?php foreach ($perm_by_group as $group => $perms): ?>
                                    <div class="col-12 col-md-6">
                                        <div class="border rounded p-2 h-100 bg-light-subtle">
                                            <div class="perm-group-title mb-1">
                                                <?= h($group === 'other' ? 'ផ្សេងៗ' : $group) ?>
                                            </div>
                                            <?php foreach ($perms as $p): ?>
                                                <?php $checked = in_array((int)$p['id'], $current_perm_ids, true); ?>
                                                <div class="form-check perm-item">
                                                    <input class="form-check-input"
                                                           type="checkbox"
                                                           name="perm[]"
                                                           id="perm<?= (int)$p['id'] ?>"
                                                           value="<?= (int)$p['id'] ?>"
                                                           <?= $checked ? 'checked':''; ?>>
                                                    <label class="form-check-label" for="perm<?= (int)$p['id'] ?>">
                                                        <?= h($p['label'] ?: $p['code']) ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="mt-3">
                                <button class="btn btn-primary">
                                    💾 រក្សាទុកសិទ្ធិសម្រាប់តួនាទីនេះ
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
