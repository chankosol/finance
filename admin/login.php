<?php
// /finance/admin/login.php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/helpers.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    if ($email !== '' && $pass !== '') {
        $stmt = $pdo->prepare("SELECT id, password_hash, full_name FROM users WHERE email=? AND is_active=1 LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($pass, $user['password_hash'])) {
            $_SESSION['fin_user_id'] = (int)$user['id'];
            $_SESSION['fin_user_name'] = $user['full_name'];
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'អ៊ីម៉ែល ឬពាក្យសម្ងាត់ មិនត្រឹមត្រូវ!';
        }
    } else {
        $error = 'សូមបញ្ចូលព័ត៌មានឱ្យពេញលេញ!';
    }
}
?>
<!doctype html>
<html lang="km">
<head>
    <meta charset="utf-8">
    <title>Finance System - Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <style>
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #7bc1ff, #004aad);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            max-width: 360px;
            width: 100%;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 8px 30px rgba(0,0,0,.15);
        }
    </style>
</head>
<body>
<div class="login-card bg-white p-4">
    <h4 class="text-center mb-3">ចូលប្រព័ន្ធ FINANCE</h4>

    <?php if ($error): ?>
        <div class="alert alert-danger py-2"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="mb-3">
            <label class="form-label">អ៊ីម៉ែល</label>
            <input type="email" name="email" class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label">ពាក្យសម្ងាត់</label>
            <input type="password" name="password" class="form-control" required>
        </div>

        <button class="btn btn-primary w-100">ចូលប្រព័ន្ធ</button>
    </form>
</div>
</body>
</html>
