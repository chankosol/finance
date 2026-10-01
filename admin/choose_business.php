<?php
// /finance/admin/choose_business.php
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

fin_require_login();
global $pdo;

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$uid = fin_current_user_id();
$businesses = fin_user_businesses($pdo, $uid);

// If user has exactly ONE business, auto-select and go to dashboard
if (count($businesses) === 1) {
  fin_set_business_id((int)$businesses[0]['id']);
  header('Location: dashboard.php');
  exit;
}

// Handle form submit
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $bid = (int)($_POST['business_id'] ?? 0);
  foreach ($businesses as $b) {
    if ((int)$b['id'] === $bid) {
      fin_set_business_id($bid);
      header('Location: dashboard.php');
      exit;
    }
  }
  $error = 'ក្រុមហ៊ុន/ហាង មិនត្រឹមត្រូវ!';
}

/* Optional: show user name (nice UX) */
$user_name = '';
try{
  $st = $pdo->prepare("SELECT full_name FROM users WHERE id=? LIMIT 1");
  $st->execute([$uid]);
  $user_name = (string)$st->fetchColumn();
}catch(Throwable $e){ $user_name=''; }

/* Helper: map type label */
function fin_type_label($type): string {
  $t = strtolower(trim((string)$type));
  if ($t === 'loan') return 'ឥណទាន';
  if ($t === 'pawn') return 'បញ្ចាំ';
  if ($t === 'both' || $t === '') return 'ទាំងអស់';
  return $t;
}
?>
<!doctype html>
<html lang="km">
<head>
  <meta charset="utf-8">
  <title>ជ្រើសរើសក្រុមហ៊ុន / ហាង</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://fonts.googleapis.com/css2?family=Battambang:wght@300;400;700;900&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

  <style>
    :root{
      --bg:#f3f4f6;
      --card:#fff;
      --ink:#0f172a;
      --muted:#64748b;
      --line:rgba(15,23,42,.10);
      --brand:#1671ff;
      --brand2:#0f5fe6;
    }
    body{
      font-family:'Battambang',system-ui,sans-serif;
      background: radial-gradient(1200px 600px at 20% 0%, rgba(22,113,255,.12), transparent 55%),
                  radial-gradient(1000px 500px at 90% 10%, rgba(15,95,230,.10), transparent 55%),
                  var(--bg);
      color:var(--ink);
      min-height:100vh;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:24px 14px;
    }
    .wrap{ width:100%; max-width: 820px; }
    .hero{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:16px;
      margin-bottom:14px;
    }
    .brand{
      display:flex; align-items:center; gap:10px;
    }
    .logo-chip{
      width:44px;height:44px;border-radius:14px;
      background: linear-gradient(180deg, var(--brand), var(--brand2));
      color:#fff;
      display:flex; align-items:center; justify-content:center;
      box-shadow: 0 18px 40px rgba(15,95,230,.25);
      flex:0 0 auto;
    }
    .hero h3{ margin:0; font-weight:900; letter-spacing:.2px; }
    .hero .sub{ color:var(--muted); font-size:.92rem; margin-top:2px; }
    .userpill{
      display:inline-flex; align-items:center; gap:8px;
      padding:.45rem .7rem;
      border-radius:999px;
      border:1px solid var(--line);
      background: rgba(255,255,255,.75);
      backdrop-filter: blur(8px);
      color: var(--ink);
      font-weight:800;
      white-space:nowrap;
    }
    .cardx{
      background: var(--card);
      border:1px solid var(--line);
      border-radius: 18px;
      box-shadow: none; /* keep your preference */
      overflow:hidden;
    }
    .card-head{
      padding:16px 18px;
      border-bottom:1px solid var(--line);
      background: linear-gradient(180deg, rgba(22,113,255,.08), rgba(22,113,255,0));
    }
    .card-head .ttl{
      font-weight:900;
      display:flex; align-items:center; gap:10px;
      margin:0;
    }
    .card-head .ttl i{ color: var(--brand2); }
    .card-bodyx{ padding:16px 18px 18px; }

    .biz-grid{
      display:grid;
      grid-template-columns: repeat(2, minmax(0,1fr));
      gap:12px;
      margin-top:10px;
    }
    @media (max-width: 640px){
      .biz-grid{ grid-template-columns: 1fr; }
      .hero{ flex-direction:column; align-items:flex-start; }
      .userpill{ width:100%; justify-content:center; }
    }

    .biz-card{
      border:1px solid var(--line);
      border-radius:16px;
      padding:14px 14px;
      background:#fff;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      text-decoration:none;
      color:var(--ink);
      transition: transform .12s ease, border-color .12s ease, background .12s ease;
    }
    .biz-card:hover{
      transform: translateY(-1px);
      border-color: rgba(22,113,255,.35);
      background: rgba(22,113,255,.03);
      color:var(--ink);
    }
    .biz-left{ display:flex; align-items:center; gap:12px; min-width:0; }
    .biz-ico{
      width:42px;height:42px;border-radius:14px;
      background: rgba(22,113,255,.10);
      color: var(--brand2);
      display:flex;align-items:center;justify-content:center;
      flex:0 0 auto;
    }
    .biz-meta{ min-width:0; }
    .biz-name{
      font-weight:900;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
      max-width: 360px;
    }
    .biz-type{
      color: var(--muted);
      font-size:.9rem;
      display:flex; align-items:center; gap:6px;
      margin-top:2px;
    }
    .tag{
      display:inline-flex; align-items:center; justify-content:center;
      padding:.18rem .55rem;
      border-radius:999px;
      border:1px solid var(--line);
      background:#fff;
      font-weight:800;
      font-size:.78rem;
      color: var(--ink);
      white-space:nowrap;
    }
    .tag.loan{ color:#1d4ed8; border-color: rgba(29,78,216,.20); background: rgba(29,78,216,.06); }
    .tag.pawn{ color:#b45309; border-color: rgba(180,83,9,.20); background: rgba(180,83,9,.06); }
    .tag.both{ color:#0f172a; }

    .alert{ border-radius:14px; }

    /* hidden form submit */
    .hidden-form{ display:none; }
  </style>
</head>
<body>
<div class="wrap">

  <div class="hero">
    <div class="brand">
      <div class="logo-chip"><i class="bi bi-shop-window"></i></div>
      <div>
        <h3>ជ្រើសរើសក្រុមហ៊ុន / ហាង</h3>
        <div class="sub">ជ្រើសមួយ ដើម្បីបន្តទៅផ្ទាំងគ្រប់គ្រង</div>
      </div>
    </div>

    <div class="userpill">
      <i class="bi bi-person-circle"></i>
      <span><?= h($user_name ?: 'អ្នកប្រើប្រាស់') ?></span>
    </div>
  </div>

  <div class="cardx">
    <div class="card-head">
      <p class="ttl"><i class="bi bi-grid-1x2-fill"></i> បញ្ជីក្រុមហ៊ុន / ហាង</p>
    </div>

    <div class="card-bodyx">

      <?php if (empty($businesses)): ?>
        <div class="alert alert-warning mb-0">
          <div class="fw-bold mb-1">មិនមានក្រុមហ៊ុនភ្ជាប់</div>
          បច្ចុប្បន្ន គណនីរបស់អ្នកមិនត្រូវបានភ្ជាប់ជាមួយក្រុមហ៊ុនណាមួយទេ។<br>
          សូមទាក់ទងអ្នកគ្រប់គ្រង ប្រព័ន្ធ។
        </div>
      <?php else: ?>

        <?php if (!empty($error)): ?>
          <div class="alert alert-danger py-2"><?= h($error) ?></div>
        <?php endif; ?>

        <!-- ✅ Click card to select (best UX) -->
        <div class="biz-grid">
          <?php foreach ($businesses as $b): ?>
            <?php
              $bid  = (int)($b['id'] ?? 0);
              $name = (string)($b['name'] ?? '');
              $type = strtolower(trim((string)($b['type'] ?? 'both')));
              $typeLabel = fin_type_label($type);

              $tagClass = 'both';
              if ($type === 'loan') $tagClass = 'loan';
              else if ($type === 'pawn') $tagClass = 'pawn';
            ?>
            <a class="biz-card" href="#" onclick="event.preventDefault(); finPickBiz(<?= $bid ?>);">
              <div class="biz-left">
                <div class="biz-ico">
                  <i class="bi <?= $type === 'pawn' ? 'bi-lock-fill' : ($type === 'loan' ? 'bi-cash-stack' : 'bi-building') ?>"></i>
                </div>
                <div class="biz-meta">
                  <div class="biz-name"><?= h($name) ?></div>
                  <div class="biz-type">
                    <i class="bi bi-tag"></i>
                    <span><?= h($typeLabel) ?></span>
                  </div>
                </div>
              </div>

              <div class="tag <?= $tagClass ?>">
                <?= $type === 'loan' ? 'Loan' : ($type === 'pawn' ? 'Pawn' : 'Both') ?>
              </div>
            </a>
          <?php endforeach; ?>
        </div>

        <!-- fallback dropdown (kept, but optional) -->
        <hr class="my-4">
        <form method="post" class="row g-2 align-items-end">
          <div class="col-12 col-md-8">
            <label class="form-label fw-bold">ជ្រើសរើសតាមបញ្ជី</label>
            <select name="business_id" class="form-select" required>
              <option value="">-- សូមជ្រើសរើស --</option>
              <?php foreach ($businesses as $b): ?>
                <option value="<?= (int)$b['id'] ?>">
                  <?= h($b['name']) ?> <?php if (($b['type'] ?? 'both') !== 'both'): ?>
                    (<?= ($b['type'] === 'loan') ? 'ឥណទាន' : 'បញ្ចាំ' ?>)
                  <?php endif; ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-4">
            <button class="btn btn-primary w-100" style="border-radius:14px;">
              <i class="bi bi-arrow-right-circle me-1"></i> បន្ត
            </button>
          </div>
        </form>

        <!-- hidden quick submit for card click -->
        <form method="post" class="hidden-form" id="finBizForm">
          <input type="hidden" name="business_id" id="finBizId" value="">
        </form>

      <?php endif; ?>
    </div>
  </div>

  <div class="text-center mt-3" style="color:#64748b;font-size:.9rem;">
    <i class="bi bi-shield-lock"></i> សុវត្ថិភាព៖ គណនីត្រូវចូលប្រើមុនពេលជ្រើសរើស
  </div>

</div>

<script>
  function finPickBiz(id){
    const inp = document.getElementById('finBizId');
    const frm = document.getElementById('finBizForm');
    if(!inp || !frm) return;
    inp.value = String(id);
    frm.submit();
  }
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
