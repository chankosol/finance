<?php
// /finance/includes/navbar.php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

fin_require_login();
global $pdo;

$cur = basename($_SERVER['SCRIPT_NAME'] ?? '');

if (!function_exists('fin_nav_active')) {
  function fin_nav_active(array $names, string $cur): string {
    return in_array($cur, $names, true) ? ' active' : '';
  }
}

/* ✅ safety: ensure h() exists */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* current user */
$user_id   = fin_current_user_id();
$user_name = 'អ្នកប្រើប្រាស់';
$user_photo = '';
if ($user_id) {
  try {
    $st = $pdo->prepare("SELECT full_name, photo_path FROM users WHERE id=? LIMIT 1");
    $st->execute([$user_id]);
    $uRow = $st->fetch(PDO::FETCH_ASSOC);
    if ($uRow) {
      $user_name  = $uRow['full_name'];
      $user_photo = $uRow['photo_path'];
    }
  } catch (Throwable $e) {
    try {
      $st = $pdo->prepare("SELECT full_name FROM users WHERE id=? LIMIT 1");
      $st->execute([$user_id]);
      $n = $st->fetchColumn();
      if ($n) $user_name = $n;
    } catch (Throwable $e2) {}
  }
}

/* current user role (show role name/label) */
$user_role = '';
if ($user_id) {
  try {
    $st = $pdo->prepare("
      SELECT r.name, r.label
      FROM user_roles ur
      JOIN roles r ON r.id = ur.role_id
      WHERE ur.user_id = ?
      ORDER BY ur.role_id ASC
      LIMIT 1
    ");
    $st->execute([$user_id]);
    $roleRow = $st->fetch(PDO::FETCH_ASSOC);

    if ($roleRow) {
      $user_role = trim((string)($roleRow['label'] ?? ''));
      if ($user_role === '') $user_role = trim((string)($roleRow['name'] ?? ''));
    }
  } catch (Throwable $e) {}
}

/* current business */
$biz_id   = fin_current_business_id();
$biz_name = 'មិនទាន់ជ្រើសរើស';
$biz_logo = '';

if ($biz_id) {
  try {
    $st = $pdo->prepare("SELECT name FROM businesses WHERE id=? LIMIT 1");
    $st->execute([$biz_id]);
    $b = $st->fetchColumn();
    if ($b) $biz_name = $b;

    $st = $pdo->prepare("SELECT company_name, logo_path FROM business_settings WHERE business_id=? LIMIT 1");
    $st->execute([$biz_id]);
    $bs = $st->fetch(PDO::FETCH_ASSOC);
    if ($bs) {
      if (!empty($bs['company_name'])) $biz_name = $bs['company_name'];
      if (!empty($bs['logo_path']))   $biz_logo = $bs['logo_path'];
    }
  } catch (Throwable $e) { /* ignore */ }
}

/* avatar letter */
$avatar_letter = 'A';
try {
  $avatar_letter = mb_strtoupper(mb_substr(trim((string)$user_name), 0, 1, 'UTF-8'), 'UTF-8');
} catch (Throwable $e) {}

/* =========================
   ✅ Due Today Count (Badge)
   Repayment-day aware query matching payment_collection.php logic
========================= */
$due_today_count = 0;
if ($biz_id) {
  try {
    $today = date('Y-m-d');
    $dayNum = (int)date('j');
    
    // Check if repayment_day column exists in loans
    $hasRepayDay = false;
    $stTest = $pdo->prepare("SHOW COLUMNS FROM loans LIKE 'repayment_day'");
    $stTest->execute();
    if ($stTest->fetch()) {
      $hasRepayDay = true;
    }
    
    if ($hasRepayDay) {
      $tomorrowStart = date('Y-m-d', strtotime($today.' +1 day')) . " 00:00:00";
      $st = $pdo->prepare("
        SELECT COUNT(DISTINCT s.id)
        FROM loan_schedules s
        JOIN loans l ON l.id = s.loan_id
        JOIN customers c ON c.id = l.customer_id
        WHERE s.business_id = ?
          AND l.business_id = ?
          AND c.business_id = ?
          AND l.status = 'ACTIVE'
          AND COALESCE(l.repayment_day, 0) = ?
          AND (COALESCE(s.total_due, 0) - COALESCE(s.paid_total, 0)) > 0
          AND s.due_date < ?
      ");
      $st->execute([$biz_id, $biz_id, $biz_id, $dayNum, $tomorrowStart]);
      $due_today_count = (int)$st->fetchColumn();
    } else {
      $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM loan_schedules
        WHERE business_id = ?
          AND DATE(due_date) = ?
          AND (COALESCE(total_due, 0) - COALESCE(paid_total, 0)) > 0
      ");
      $st->execute([$biz_id, $today]);
      $due_today_count = (int)$st->fetchColumn();
    }
  } catch (Throwable $e) {
    $due_today_count = 0;
  }
}
?>
<link href="https://fonts.googleapis.com/css2?family=Battambang:wght@300;400;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
  :root{
    --ez-blue:#1671ff;
    --ez-blue2:#0f5fe6;
    --ez-pill: rgba(255,255,255,.14);
    --ez-pill2: rgba(255,255,255,.22);
    --ez-text: rgba(255,255,255,.96);
    --ez-muted: rgba(255,255,255,.82);

    --tab-bg: rgba(255,255,255,.98);
    --tab-border: rgba(15,23,42,.10);
    --tab-muted: rgba(15,23,42,.70);
    --tab-active: #1671ff;
  }

  .ez-navbar * , .fin-bottom-tabs *{ font-family:'Battambang',sans-serif; }

  /* =========================
     DESKTOP TOP NAV
  ========================== */
  .ez-navbar{
    background: linear-gradient(180deg, var(--ez-blue) 0%, var(--ez-blue2) 100%);
    box-shadow: 0 10px 28px rgba(15,95,230,.25);
    position: sticky;
    top: 0;
    z-index: 1020;
    transition: transform .22s ease, opacity .22s ease;
    will-change: transform, opacity;
  }
  .ez-navbar.is-hidden {
    transform: translateY(-100%);
    opacity: 0;
    pointer-events: none;
  }

  .ez-menu{
    display:flex;
    align-items:center;
    gap:.35rem;
  }
  .ez-menu .nav-link{
    color: var(--ez-muted);
    border-radius:14px;
    padding:.55rem .85rem;
    display:inline-flex;
    align-items:center;
    gap:.55rem;
    white-space:nowrap;
    transition: all .15s ease;
  }
  .ez-menu .nav-link:hover{
    color: var(--ez-text);
    background: var(--ez-pill);
  }
  .ez-menu .nav-link.active{
    color:#fff;
    background: var(--ez-pill2);
    box-shadow: inset 0 0 0 1px rgba(255,255,255,.22);
  }
  .ez-menu .nav-link i{
    font-size:1.05rem;
    line-height:1;
    transform: translateY(-.5px);
  }

  .ez-user-btn{
    display:flex; align-items:center; gap:.6rem;
    border-radius:999px;
    padding:.35rem .55rem;
    background: rgba(255,255,255,.14);
    border: 1px solid rgba(255,255,255,.22);
    color:#fff;
    text-decoration:none;
    white-space:nowrap;
  }
  .ez-user-btn:hover{ background: rgba(255,255,255,.18); color:#fff; }

  .ez-avatar{
    width:32px;height:32px;border-radius:999px;
    background: rgba(255,255,255,.22);
    border:1px solid rgba(255,255,255,.25);
    display:flex;align-items:center;justify-content:center;
    font-weight:900;
  }
  .ez-user-meta{ line-height:1; }
  .ez-user-meta .u{ font-weight:800; font-size:.9rem; }
  .ez-user-meta .r{ font-size:.80rem; opacity:.9; padding-top:.3rem; }

  .navbar-dark .navbar-toggler{
    border-color: rgba(255,255,255,.35);
    border-radius:12px;
  }

  /* desktop badge (small pill) */
  .ez-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:18px;
    height:18px;
    padding:0 6px;
    border-radius:999px;
    background:#ef4444;
    color:#fff;
    font-weight:900;
    font-size:.72rem;
    line-height:1;
    box-shadow:0 8px 18px rgba(239,68,68,.25);
    margin-left:.35rem;
  }

    @media (max-width: 991.98px) {
      body { padding-bottom: 120px !important; }
    }
    .fin-bottom-space{ height: 110px; }
    @media (min-width: 992px){ .fin-bottom-space{ display:none; } }

  /* =========================
     MOBILE BOTTOM TAB BAR
  ========================== */
  .fin-bottom-tabs{
    position: fixed;
    left: 0; right: 0;
    bottom: 10px;
    z-index: 1030;
    padding: 0 12px;

    transform: translateY(0);
    opacity: 1;
    transition: transform .22s ease, opacity .22s ease;
    will-change: transform, opacity;
  }
  .fin-bottom-tabs.is-hidden{
    transform: translateY(120%);
    opacity: 0;
    pointer-events: none;
  }

  .fin-tabbar{
    background: var(--tab-bg);
    border: 1px solid var(--tab-border);
    border-radius: 18px;
    box-shadow: 0 14px 30px rgba(15,23,42,.14);
    overflow: visible;
    backdrop-filter: blur(8px);
  }

  .fin-tabgrid{
    display:grid;
    grid-template-columns: repeat(5, 1fr);
  }

  .fin-tab{
    padding: .55rem .25rem .55rem;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap: .2rem;
    min-height: 58px;
    position: relative;
    text-decoration:none;
    color: var(--tab-muted);
  }
  .fin-tab i{
    font-size: 1.25rem;
    line-height: 1;
  }
  .fin-tab .lbl{
    font-size: .72rem;
    font-weight: 800;
    line-height: 1;
    opacity: .95;
    text-align:center;
    white-space: nowrap;
  }

  .fin-tab.active{ color: var(--tab-active); }
  .fin-tab.active i,
  .fin-tab.active .lbl{ color: var(--tab-active); }
  .fin-tab.active::after{
    content:"";
    position:absolute;
    left: 18%;
    right: 18%;
    bottom: 6px;
    height: 3px;
    border-radius: 999px;
    background: var(--tab-active);
    opacity: .95;
  }

  /* Badge on bottom tab (red circle) */
  .fin-badge{
    position:absolute;
    top: 7px;
    right: 18px;
    min-width: 18px;
    height: 18px;
    padding: 0 6px;
    border-radius: 999px;
    background:#ef4444;
    color:#fff;
    font-weight:900;
    font-size:.72rem;
    line-height: 1;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    box-shadow:0 10px 20px rgba(239,68,68,.25);
    pointer-events:none;
  }

  /* show bottom tabs ONLY on mobile/tablet */
  @media (min-width: 992px){ .fin-bottom-tabs{ display:none; } }
  /* hide top navbar on mobile (like app) */
  @media (max-width: 991.98px){ .ez-navbar{ display:none; } }

  /* Dropup menu overrides */
  .fin-tab .dropdown-toggle::after {
    display: none !important;
  }
  .fin-tab .dropdown-menu {
    border: 1px solid var(--tab-border);
    box-shadow: 0 -10px 30px rgba(15,23,42,0.12), 0 10px 30px rgba(15,23,42,0.12) !important;
  }
  .fin-tab .dropdown-item {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--tab-muted);
  }
  .fin-tab .dropdown-item.active, 
  .fin-tab .dropdown-item:active {
    background-color: var(--tab-active);
    color: #fff;
  }
  .fin-tab .dropdown-item.active i, 
  .fin-tab .dropdown-item:active i {
    color: #fff !important;
  }
</style>

<!-- =========================
     TOP NAVBAR (Desktop only)
========================= -->
<nav class="navbar navbar-expand-lg navbar-dark ez-navbar mb-3 d-none d-lg-flex">
  <div class="container-xl">

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#finNav">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="finNav">

      <ul class="navbar-nav me-auto mb-2 mb-lg-0 ez-menu ez-scroll">
        <li class="nav-item">
          <a class="nav-link<?= fin_nav_active(['dashboard.php'], $cur) ?>" href="<?= FIN_BASE_URL ?>/admin/dashboard.php">
            <?php if (!empty($biz_logo)): ?>
              <img src="<?= FIN_BASE_URL ?>/<?= h($biz_logo) ?>" alt="" style="width:28px;height:28px;object-fit:cover;border-radius:50%;margin-right:6px;vertical-align:middle;display:inline-block;transform:translateY(-1px);">
            <?php else: ?>
              <i class="bi bi-speedometer2"></i>
            <?php endif; ?>
            <span>ផ្ទាំងគ្រប់គ្រង</span>
          </a>
        </li>

        <li class="nav-item">
          <a class="nav-link<?= fin_nav_active(['customers.php','customer_add.php','customer_edit.php','customer_view.php'], $cur) ?>" href="<?= FIN_BASE_URL ?>/admin/customers.php">
            <i class="bi bi-people"></i><span>អតិថិជន</span>
          </a>
        </li>

        <li class="nav-item">
          <a class="nav-link<?= fin_nav_active(['loans.php','loan_add.php','loan_edit.php','loan_view.php','loan_payment_add.php','loan_payment_receipt.php'], $cur) ?>" href="<?= FIN_BASE_URL ?>/admin/loans.php">
            <i class="bi bi-cash-stack"></i><span>កម្ចី</span>
          </a>
        </li>

        <li class="nav-item">
          <a class="nav-link<?= fin_nav_active(['reports.php'], $cur) ?>" href="<?= FIN_BASE_URL ?>/admin/reports.php">
            <i class="bi bi-bar-chart"></i><span>របាយការណ៍</span>
          </a>
        </li>

        <li class="nav-item">
          <a class="nav-link<?= fin_nav_active(['payment_collection.php'], $cur) ?>"
             href="<?= FIN_BASE_URL ?>/admin/payment_collection.php?quick=today&only_remaining=1">
            <i class="bi bi-receipt-cutoff"></i>
            <span>ប្រមូលប្រាក់</span>
            <?php if ($due_today_count > 0): ?>
              <span class="ez-badge"><?= (int)$due_today_count ?></span>
            <?php endif; ?>
          </a>
        </li>
      </ul>

      <div class="d-flex align-items-center gap-2 ms-lg-2">
        <div class="dropdown">
          <a class="ez-user-btn dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="ez-avatar">
              <?php if ($user_photo): ?>
                <img src="<?= FIN_BASE_URL ?>/<?= h($user_photo) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
              <?php else: ?>
                <?= h($avatar_letter) ?>
              <?php endif; ?>
            </span>
            <span class="ez-user-meta d-none d-sm-block">
              <div class="u"><?= h($user_name) ?></div>
              <div class="r"><?= h($user_role ?: 'គ្មានតួនាទី') ?></div>
            </span>
          </a>

          <ul class="dropdown-menu dropdown-menu-end">
            <li>
              <a class="dropdown-item" href="<?= FIN_BASE_URL ?>/admin/auto_followup.php">
                <i class="bi bi-send-check me-2"></i> តាមដានការសង
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="<?= FIN_BASE_URL ?>/admin/roles_permissions.php">
                <i class="bi bi-shield-lock me-2"></i> តួនាទី / សិទ្ធិ
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="<?= FIN_BASE_URL ?>/admin/users.php">
                <i class="bi bi-person-badge me-2"></i> គ្រប់គ្រងអ្នកប្រើប្រាស់
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="<?= FIN_BASE_URL ?>/admin/settings.php">
                <i class="bi bi-gear me-2"></i> ការកំណត់
              </a>
            </li>
            <?php if (fin_is_super_admin()): ?>
              <li>
                <a class="dropdown-item" href="<?= FIN_BASE_URL ?>/admin/businesses.php">
                  <i class="bi bi-building me-2"></i> គ្រប់គ្រងក្រុមហ៊ុន
                </a>
              </li>
            <?php endif; ?>


            <li><hr class="dropdown-divider"></li>
            <li>
              <a class="dropdown-item text-danger" href="<?= FIN_BASE_URL ?>/admin/logout.php">
                <i class="bi bi-box-arrow-right me-2"></i> ចាកចេញ
              </a>
            </li>
          </ul>
        </div>

      </div>

    </div>

  </div>
</nav>

<!-- =========================
     BOTTOM TAB BAR (Mobile app style)
     - scroll up => show
     - scroll down => hide
========================= -->
<div class="fin-bottom-tabs" id="finBottomTabs" aria-label="Bottom navigation">
  <div class="fin-tabbar">
    <div class="fin-tabgrid">
      <a class="fin-tab<?= fin_nav_active(['dashboard.php'], $cur) ?>" href="<?= FIN_BASE_URL ?>/admin/dashboard.php">
        <i class="bi bi-house-door-fill"></i>
        <div class="lbl">ទំព័រដើម</div>
      </a>

      <a class="fin-tab<?= fin_nav_active(['customers.php','customer_add.php','customer_edit.php','customer_view.php'], $cur) ?>" href="<?= FIN_BASE_URL ?>/admin/customers.php">
        <i class="bi bi-people-fill"></i>
        <div class="lbl">អតិថិជន</div>
      </a>

      <a class="fin-tab<?= fin_nav_active(['loans.php','loan_add.php','loan_edit.php','loan_view.php','loan_payment_add.php','loan_payment_receipt.php'], $cur) ?>" href="<?= FIN_BASE_URL ?>/admin/loans.php">
        <i class="bi bi-cash-stack"></i>
        <div class="lbl">កម្ចី</div>
      </a>

      <a class="fin-tab<?= fin_nav_active(['payment_collection.php'], $cur) ?>"
         href="<?= FIN_BASE_URL ?>/admin/payment_collection.php?quick=today&only_remaining=1">
        <i class="bi bi-receipt"></i>
        <div class="lbl">ទូទាត់ត្រលប់</div>
        <?php if ($due_today_count > 0): ?>
          <span class="fin-badge"><?= (int)$due_today_count ?></span>
        <?php endif; ?>
      </a>

      <div class="dropup fin-tab<?= fin_nav_active(['reports.php', 'auto_followup.php', 'pawns.php', 'pawn_add.php', 'pawn_edit.php', 'roles_permissions.php', 'users.php', 'settings.php', 'businesses.php'], $cur) ?>" style="padding: 0;">
        <a class="dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="text-decoration: none; color: inherit; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.2rem; width: 100%; height: 100%; min-height: 58px;">
          <i class="bi bi-three-dots"></i>
          <div class="lbl">ច្រើនទៀត</div>
        </a>
        <ul class="dropdown-menu dropdown-menu-end shadow" style="border-radius: 16px; margin-bottom: 12px; min-width: 220px; border: 1px solid rgba(15,23,42,.10); padding: 0.5rem 0;">
          <li>
            <a class="dropdown-item d-flex align-items-center<?= fin_nav_active(['reports.php'], $cur) ?>" href="<?= FIN_BASE_URL ?>/admin/reports.php" style="padding: 0.6rem 1rem;">
              <i class="bi bi-bar-chart-fill me-2 text-primary"></i> <span>របាយការណ៍</span>
            </a>
          </li>
          <li>
            <a class="dropdown-item d-flex align-items-center<?= fin_nav_active(['auto_followup.php'], $cur) ?>" href="<?= FIN_BASE_URL ?>/admin/auto_followup.php" style="padding: 0.6rem 1rem;">
              <i class="bi bi-clock-history me-2 text-success"></i> <span>តាមដានការសង</span>
            </a>
          </li>
          <li>
            <a class="dropdown-item d-flex align-items-center<?= fin_nav_active(['pawns.php', 'pawn_add.php', 'pawn_edit.php'], $cur) ?>" href="<?= FIN_BASE_URL ?>/admin/pawns.php" style="padding: 0.6rem 1rem;">
              <i class="bi bi-tag-fill me-2 text-warning"></i> <span>បញ្ជីបញ្ចាំ</span>
            </a>
          </li>
          <li>
            <a class="dropdown-item d-flex align-items-center<?= fin_nav_active(['roles_permissions.php'], $cur) ?>" href="<?= FIN_BASE_URL ?>/admin/roles_permissions.php" style="padding: 0.6rem 1rem;">
              <i class="bi bi-shield-lock-fill me-2 text-info"></i> <span>តួនាទី / សិទ្ធិ</span>
            </a>
          </li>
          <li>
            <a class="dropdown-item d-flex align-items-center<?= fin_nav_active(['users.php'], $cur) ?>" href="<?= FIN_BASE_URL ?>/admin/users.php" style="padding: 0.6rem 1rem;">
              <i class="bi bi-person-badge-fill me-2 text-secondary"></i> <span>គ្រប់គ្រងអ្នកប្រើប្រាស់</span>
            </a>
          </li>
          <li>
            <a class="dropdown-item d-flex align-items-center<?= fin_nav_active(['settings.php'], $cur) ?>" href="<?= FIN_BASE_URL ?>/admin/settings.php" style="padding: 0.6rem 1rem;">
              <i class="bi bi-gear-fill me-2 text-dark"></i> <span>ការកំណត់</span>
            </a>
          </li>
          <?php if (fin_is_super_admin()): ?>
            <li>
              <a class="dropdown-item d-flex align-items-center<?= fin_nav_active(['businesses.php'], $cur) ?>" href="<?= FIN_BASE_URL ?>/admin/businesses.php" style="padding: 0.6rem 1rem;">
                <i class="bi bi-building-fill me-2 text-primary"></i> <span>គ្រប់គ្រងក្រុមហ៊ុន</span>
              </a>
            </li>
          <?php endif; ?>

          <li><hr class="dropdown-divider" style="margin: 0.4rem 0;"></li>
          <li class="d-flex align-items-center justify-content-between" style="padding: 0.4rem 1rem 0.2rem;">
            <div class="d-flex align-items-center gap-2">
              <span class="ez-avatar" style="width: 32px; height: 32px; font-weight: 800; background: var(--tab-active); color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; border: 1px solid rgba(15,23,42,.10);">
                <?php if ($user_photo): ?>
                  <img src="<?= FIN_BASE_URL ?>/<?= h($user_photo) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                <?php else: ?>
                  <?= h($avatar_letter) ?>
                <?php endif; ?>
              </span>
              <div style="line-height: 1.2;">
                <div style="font-weight: 800; font-size: 0.85rem; color: #1e293b;"><?= h($user_name) ?></div>
                <div style="font-size: 0.72rem; color: #64748b;"><?= h($user_role ?: 'គ្មានតួនាទី') ?></div>
              </div>
            </div>
            <a class="d-flex align-items-center justify-content-center" href="<?= FIN_BASE_URL ?>/admin/logout.php" style="color: #ef4444 !important; font-size: 1.25rem; padding: 0.4rem; border-radius: 8px; width: 36px; height: 36px; transition: background 0.15s; text-decoration: none;" onmouseover="this.style.background='rgba(239, 68, 68, 0.08)'" onmouseout="this.style.background='none'" title="ចាកចេញ">
              <i class="bi bi-box-arrow-right"></i>
            </a>
          </li>
        </ul>
      </div>
    </div>
  </div>
</div>

<!-- spacer for mobile so content doesn't hide behind bottom bar -->
<div class="fin-bottom-space d-lg-none"></div>

<script>
(function(){
  // 1. Bottom Tab Bar (Mobile)
  const bar = document.getElementById('finBottomTabs');
  if (bar) {
    const isMobile = () => window.matchMedia('(max-width: 991.98px)').matches;
    let lastY = window.scrollY || 0;

    function onScroll(){
      if(!isMobile()) return;

      const y = window.scrollY || 0;
      const delta = y - lastY;

      if(y < 20){
        bar.classList.remove('is-hidden');
        lastY = y;
        return;
      }

      if(delta > 6){
        bar.classList.add('is-hidden');
      }else if(delta < -6){
        bar.classList.remove('is-hidden');
      }

      lastY = y;
    }

    window.addEventListener('scroll', onScroll, {passive:true});
    window.addEventListener('resize', () => {
      if(!isMobile()){
        bar.classList.add('is-hidden');
      }else{
        bar.classList.remove('is-hidden');
      }
    });

    if(isMobile()) bar.classList.remove('is-hidden');
  }

  // 2. Top Navbar (Desktop)
  const topNav = document.querySelector('.ez-navbar');
  if (topNav) {
    const isDesktop = () => window.matchMedia('(min-width: 992px)').matches;
    let lastTopY = window.scrollY || 0;

    function onTopScroll(){
      if(!isDesktop()) return;

      const y = window.scrollY || 0;
      const delta = y - lastTopY;

      if(y < 60){
        topNav.classList.remove('is-hidden');
        lastTopY = y;
        return;
      }

      if(delta > 10){
        topNav.classList.add('is-hidden');
      }else if(delta < -10){
        topNav.classList.remove('is-hidden');
      }

      lastTopY = y;
    }

    window.addEventListener('scroll', onTopScroll, {passive:true});
    window.addEventListener('resize', () => {
      if(!isDesktop()){
        topNav.classList.remove('is-hidden');
      }
    });
  }
})();
</script>
