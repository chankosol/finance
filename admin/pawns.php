<?php
// /finance/admin/pawns.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

fin_require_login();
fin_require_permission('view_pawns');
$business_id = fin_require_business();
global $pdo;

function h2($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fin_table_exists(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
  ");
  $st->execute([$table]);
  return (int)$st->fetchColumn() > 0;
}
function fin_col_exists(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
  ");
  $st->execute([$table, $col]);
  return (int)$st->fetchColumn() > 0;
}
function fin_first_col(PDO $pdo, string $table, array $candidates, string $fallback=''): string {
  foreach ($candidates as $c) {
    if (fin_col_exists($pdo, $table, $c)) return $c;
  }
  return $fallback;
}
function fin_money_prefix(string $cc): string {
  $cc = strtoupper(trim($cc));
  return $cc === 'KHR' ? '៛ ' : '$ ';
}
function fin_num($n): string { return number_format((float)$n, 2); }

/* =========================
   Detect pawn table/columns
   ========================= */
$pawnTable = fin_table_exists($pdo, 'pawns') ? 'pawns' : (fin_table_exists($pdo,'pawn_loans') ? 'pawn_loans' : 'pawns');
if (!fin_table_exists($pdo, $pawnTable)) {
  http_response_code(500);
  echo "<div style='font-family:Battambang,sans-serif;padding:16px'>
        <h3>Table not found</h3>
        <p>រកមិនឃើញតារាង <b>pawns</b> ឬ <b>pawn_loans</b> ទេ។ សូមបង្កើត table មុន។</p>
        </div>";
  exit;
}

$pawnBizCol = fin_first_col($pdo, $pawnTable, ['business_id','biz_id'], 'business_id');

$pawnIdCol        = fin_first_col($pdo, $pawnTable, ['id'], 'id');
$pawnCodeCol      = fin_first_col($pdo, $pawnTable, ['pawn_code','code','ref_code'], '');
$pawnCustomerCol  = fin_first_col($pdo, $pawnTable, ['customer_id','client_id'], 'customer_id');
$pawnAmountCol    = fin_first_col($pdo, $pawnTable, ['principal','principal_amount','loan_amount','amount'], '');
$pawnCurrencyCol  = fin_first_col($pdo, $pawnTable, ['currency_code','currency'], '');
$pawnStartCol     = fin_first_col($pdo, $pawnTable, ['start_date','pawn_date','created_date','date'], '');
$pawnDueCol       = fin_first_col($pdo, $pawnTable, ['due_date','end_date','maturity_date'], '');
$pawnStatusCol    = fin_first_col($pdo, $pawnTable, ['status'], '');
$pawnTypeCol      = fin_first_col($pdo, $pawnTable, ['pawn_type','type'], '');
$pawnItemCol      = fin_first_col($pdo, $pawnTable, ['collateral','item','item_name','product_name','asset_name'], '');
$pawnNoteCol      = fin_first_col($pdo, $pawnTable, ['note','remarks'], '');

$customersBizCol  = fin_table_exists($pdo,'customers') ? fin_first_col($pdo,'customers',['business_id','biz_id'],'business_id') : '';
$custNameCol      = fin_table_exists($pdo,'customers') ? fin_first_col($pdo,'customers',['full_name','name','customer_name'],'full_name') : '';

/* =========================
   Filters / Search
   ========================= */
$q      = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');      // ACTIVE / CLOSED / OVERDUE ...
$cc     = trim($_GET['cc'] ?? '');          // USD / KHR
$from   = trim($_GET['from'] ?? '');
$to     = trim($_GET['to'] ?? '');
$sort   = trim($_GET['sort'] ?? 'new');     // new|old|due
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

/* Build WHERE */
$where = " WHERE p.`$pawnBizCol` = ? ";
$args  = [$business_id];

if ($status !== '' && $pawnStatusCol !== '') {
  $where .= " AND p.`$pawnStatusCol` = ? ";
  $args[] = $status;
}
if ($cc !== '' && $pawnCurrencyCol !== '') {
  $where .= " AND UPPER(p.`$pawnCurrencyCol`) = ? ";
  $args[] = strtoupper($cc);
}
if ($from !== '' && $pawnStartCol !== '') {
  $where .= " AND DATE(p.`$pawnStartCol`) >= ? ";
  $args[] = $from;
}
if ($to !== '' && $pawnStartCol !== '') {
  $where .= " AND DATE(p.`$pawnStartCol`) <= ? ";
  $args[] = $to;
}

if ($q !== '') {
  $like = "%$q%";
  $or = [];
  $orArgs = [];

  if ($pawnCodeCol !== '') { $or[] = "p.`$pawnCodeCol` LIKE ?"; $orArgs[] = $like; }
  if ($pawnItemCol !== '') { $or[] = "p.`$pawnItemCol` LIKE ?"; $orArgs[] = $like; }
  if ($pawnNoteCol !== '') { $or[] = "p.`$pawnNoteCol` LIKE ?"; $orArgs[] = $like; }
  if ($custNameCol !== '' && fin_table_exists($pdo,'customers')) { $or[] = "c.`$custNameCol` LIKE ?"; $orArgs[] = $like; }

  if ($or) {
    $where .= " AND ( " . implode(" OR ", $or) . " ) ";
    $args = array_merge($args, $orArgs);
  }
}

/* ORDER BY */
$orderBy = " ORDER BY p.`$pawnIdCol` DESC ";
if ($sort === 'old') $orderBy = " ORDER BY p.`$pawnIdCol` ASC ";
if ($sort === 'due' && $pawnDueCol !== '') $orderBy = " ORDER BY p.`$pawnDueCol` ASC, p.`$pawnIdCol` DESC ";

/* SELECT */
$select = "p.*";
$join = "";
if (fin_table_exists($pdo,'customers')) {
  // Only join if there is customer_id column
  if ($pawnCustomerCol !== '' && fin_col_exists($pdo, $pawnTable, $pawnCustomerCol)) {
    $join = " LEFT JOIN customers c ON c.id = p.`$pawnCustomerCol` " . ($customersBizCol ? " AND c.`$customersBizCol` = p.`$pawnBizCol` " : "");
    if ($custNameCol !== '') $select .= ", c.`$custNameCol` AS customer_name";
  }
}

/* Count */
$stC = $pdo->prepare("SELECT COUNT(*) FROM `$pawnTable` p $join $where");
$stC->execute($args);
$totalRows = (int)$stC->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));

/* Fetch page */
$sql = "SELECT $select FROM `$pawnTable` p $join $where $orderBy LIMIT $limit OFFSET $offset";
$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* KPIs */
$kpi = [
  'total' => $totalRows,
  'active' => 0,
  'closed' => 0,
  'overdue' => 0,
];
if ($pawnStatusCol !== '') {
  $stK = $pdo->prepare("
    SELECT
      SUM(CASE WHEN `$pawnStatusCol`='ACTIVE' THEN 1 ELSE 0 END) AS active_cnt,
      SUM(CASE WHEN `$pawnStatusCol`='CLOSED' THEN 1 ELSE 0 END) AS closed_cnt,
      SUM(CASE WHEN `$pawnStatusCol`='OVERDUE' THEN 1 ELSE 0 END) AS overdue_cnt
    FROM `$pawnTable`
    WHERE `$pawnBizCol` = ?
  ");
  $stK->execute([$business_id]);
  $k = $stK->fetch(PDO::FETCH_ASSOC) ?: [];
  $kpi['active']  = (int)($k['active_cnt'] ?? 0);
  $kpi['closed']  = (int)($k['closed_cnt'] ?? 0);
  $kpi['overdue'] = (int)($k['overdue_cnt'] ?? 0);
}

function fin_status_badge($s): array {
  $s = strtoupper(trim((string)$s));
  if ($s === 'ACTIVE') return ['success','ACTIVE'];
  if ($s === 'CLOSED') return ['secondary','CLOSED'];
  if ($s === 'OVERDUE') return ['danger','OVERDUE'];
  if ($s === 'DEFAULT') return ['warning','DEFAULT'];
  return ['light', $s ?: '—'];
}

/* Pagination URL helper */
function fin_build_query(array $overrides=[]): string {
  $q = $_GET;
  foreach ($overrides as $k=>$v) {
    if ($v === null) unset($q[$k]);
    else $q[$k] = $v;
  }
  return http_build_query($q);
}
?>
<!doctype html>
<html lang="km">
<head>
  <meta charset="utf-8">
  <title>បញ្ចាំ / Pawns</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://fonts.googleapis.com/css2?family=Battambang:wght@300;400;700;800;900&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

  <style>
    :root{
      --bg:#f3f4f6; --card:#fff; --ink:#0f172a; --muted:#64748b; --line:#e5e7eb;
    }
    body{font-family:'Battambang',sans-serif;background:var(--bg);color:var(--ink);}
    .cardx{background:var(--card);border:1px solid rgba(15,23,42,.08);border-radius:18px;box-shadow:none;}
    .page-title{font-weight:900}
    .sub{color:var(--muted);font-size:.92rem}
    .kpi{border:1px solid rgba(15,23,42,.08);border-radius:16px;padding:14px 16px;background:#fff;box-shadow:none;}
    .kpi .v{font-size:1.25rem;font-weight:900;line-height:1}
    .kpi .lbl{color:var(--muted);font-size:.88rem;font-weight:800}
    .btn-soft{border:1px solid var(--line);background:#fff;border-radius:12px}
    .form-control,.form-select{border-radius:12px}
    .table td,.table th{vertical-align:middle}
    .nowrap{white-space:nowrap}
    .mono{font-variant-numeric:tabular-nums}
    @media (max-width:576px){
      .hide-sm{display:none}
    }
  </style>
</head>
<body>

<?php include __DIR__ . '/../includes/navbar.php'; ?>

<div class="container py-4">

  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
      <h4 class="page-title mb-0"><i class="bi bi-safe2 me-1"></i> បញ្ចាំ</h4>
      <div class="sub">Pawns / Pawn Loans</div>
    </div>

    <div class="d-flex gap-2 flex-wrap">
      <a class="btn btn-soft" href="dashboard.php"><i class="bi bi-speedometer2 me-1"></i> Dashboard</a>
      <a class="btn btn-primary" href="pawn_add.php"><i class="bi bi-plus-circle me-1"></i> បន្ថែមបញ្ចាំ</a>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-12 col-md-3">
      <div class="kpi">
        <div class="lbl">សរុប</div>
        <div class="v"><?= (int)$kpi['total'] ?></div>
      </div>
    </div>
    <div class="col-12 col-md-3">
      <div class="kpi">
        <div class="lbl">ACTIVE</div>
        <div class="v"><?= (int)$kpi['active'] ?></div>
      </div>
    </div>
    <div class="col-12 col-md-3">
      <div class="kpi">
        <div class="lbl">OVERDUE</div>
        <div class="v"><?= (int)$kpi['overdue'] ?></div>
      </div>
    </div>
    <div class="col-12 col-md-3">
      <div class="kpi">
        <div class="lbl">CLOSED</div>
        <div class="v"><?= (int)$kpi['closed'] ?></div>
      </div>
    </div>
  </div>

  <div class="cardx mb-3">
    <div class="card-body p-3 p-lg-4">
      <form class="row g-2 align-items-end" method="get">
        <div class="col-12 col-lg-4">
          <label class="form-label fw-bold">ស្វែងរក</label>
          <input type="text" class="form-control" name="q" value="<?= h2($q) ?>" placeholder="កូដ / អតិថិជន / ទ្រព្យបញ្ចាំ / note...">
        </div>

        <div class="col-6 col-lg-2">
          <label class="form-label fw-bold">Status</label>
          <select class="form-select" name="status">
            <option value="">ទាំងអស់</option>
            <option value="ACTIVE" <?= $status==='ACTIVE'?'selected':'' ?>>ACTIVE</option>
            <option value="OVERDUE" <?= $status==='OVERDUE'?'selected':'' ?>>OVERDUE</option>
            <option value="CLOSED" <?= $status==='CLOSED'?'selected':'' ?>>CLOSED</option>
            <option value="DEFAULT" <?= $status==='DEFAULT'?'selected':'' ?>>DEFAULT</option>
          </select>
        </div>

        <div class="col-6 col-lg-2">
          <label class="form-label fw-bold">Currency</label>
          <select class="form-select" name="cc">
            <option value="">All</option>
            <option value="USD" <?= strtoupper($cc)==='USD'?'selected':'' ?>>USD</option>
            <option value="KHR" <?= strtoupper($cc)==='KHR'?'selected':'' ?>>KHR</option>
          </select>
        </div>

        <div class="col-6 col-lg-2">
          <label class="form-label fw-bold">From</label>
          <input type="date" class="form-control" name="from" value="<?= h2($from) ?>">
        </div>

        <div class="col-6 col-lg-2">
          <label class="form-label fw-bold">To</label>
          <input type="date" class="form-control" name="to" value="<?= h2($to) ?>">
        </div>

        <div class="col-6 col-lg-2">
          <label class="form-label fw-bold">Sort</label>
          <select class="form-select" name="sort">
            <option value="new" <?= $sort==='new'?'selected':'' ?>>ថ្មី → ចាស់</option>
            <option value="old" <?= $sort==='old'?'selected':'' ?>>ចាស់ → ថ្មី</option>
            <?php if ($pawnDueCol !== ''): ?>
              <option value="due" <?= $sort==='due'?'selected':'' ?>>Due date</option>
            <?php endif; ?>
          </select>
        </div>

        <div class="col-6 col-lg-2 d-flex gap-2">
          <button class="btn btn-primary w-100"><i class="bi bi-search me-1"></i> Filter</button>
          <a class="btn btn-soft w-100" href="pawns.php"><i class="bi bi-x-circle me-1"></i> Clear</a>
        </div>
      </form>
    </div>
  </div>

  <div class="cardx">
    <div class="card-body p-3 p-lg-4">

      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="fw-bold"><i class="bi bi-list-ul me-1"></i> បញ្ជីបញ្ចាំ</div>
        <div class="sub">Total: <?= (int)$totalRows ?></div>
      </div>

      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th style="width:60px">#</th>
              <th>Code</th>
              <th>Customer</th>
              <th class="text-end">Amount</th>
              <th class="hide-sm">Currency</th>
              <th class="hide-sm">Start</th>
              <th class="hide-sm">Due</th>
              <th>Status</th>
              <th class="text-end" style="width:160px">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">មិនមានទិន្នន័យ</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $idx => $r): ?>
              <?php
                $no = $offset + $idx + 1;
                $code = $pawnCodeCol !== '' ? ($r[$pawnCodeCol] ?? '') : ('#'.($r[$pawnIdCol] ?? ''));
                $customer = $r['customer_name'] ?? '';
                $amount = ($pawnAmountCol !== '') ? (float)($r[$pawnAmountCol] ?? 0) : 0.0;
                $cur = ($pawnCurrencyCol !== '') ? (string)($r[$pawnCurrencyCol] ?? 'USD') : 'USD';
                $prefix = fin_money_prefix($cur);
                $startV = ($pawnStartCol !== '') ? (string)($r[$pawnStartCol] ?? '') : '';
                $dueV = ($pawnDueCol !== '') ? (string)($r[$pawnDueCol] ?? '') : '';
                $stt = ($pawnStatusCol !== '') ? (string)($r[$pawnStatusCol] ?? '') : '';
                [$bg, $lbl] = fin_status_badge($stt);
                $id = (int)($r[$pawnIdCol] ?? 0);
              ?>
              <tr>
                <td class="fw-bold"><?= (int)$no ?></td>
                <td class="nowrap">
                  <div class="fw-semibold"><?= h2($code ?: '—') ?></div>
                  <?php if ($pawnItemCol !== '' && !empty($r[$pawnItemCol])): ?>
                    <div class="sub"><?= h2($r[$pawnItemCol]) ?></div>
                  <?php endif; ?>
                </td>
                <td><?= h2($customer ?: '—') ?></td>
                <td class="text-end mono fw-bold"><?= h2($prefix) ?><?= fin_num($amount) ?></td>
                <td class="hide-sm"><?= h2(strtoupper($cur)) ?></td>
                <td class="hide-sm nowrap"><?= h2($startV) ?></td>
                <td class="hide-sm nowrap"><?= h2($dueV) ?></td>
                <td><span class="badge bg-<?= h2($bg) ?>"><?= h2($lbl) ?></span></td>
                <td class="text-end">
                  <div class="d-flex justify-content-end gap-2 flex-wrap">
                    <a class="btn btn-sm btn-outline-primary" href="pawn_view.php?id=<?= $id ?>">
                      <i class="bi bi-eye me-1"></i> មើល
                    </a>
                    <a class="btn btn-sm btn-outline-warning" href="pawn_edit.php?id=<?= $id ?>">
                      <i class="bi bi-pencil-square me-1"></i> កែ
                    </a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
        <nav class="mt-3 d-flex justify-content-center">
          <ul class="pagination pagination-sm flex-wrap mb-0">
            <?php
              $prev = max(1, $page-1);
              $next = min($totalPages, $page+1);
            ?>
            <li class="page-item <?= $page<=1?'disabled':'' ?>">
              <a class="page-link" href="pawns.php?<?= h2(fin_build_query(['page'=>$prev])) ?>">‹</a>
            </li>

            <?php
              // compact pagination
              $start = max(1, $page-3);
              $end = min($totalPages, $page+3);
              for($p=$start;$p<=$end;$p++):
            ?>
              <li class="page-item <?= $p===$page?'active':'' ?>">
                <a class="page-link" href="pawns.php?<?= h2(fin_build_query(['page'=>$p])) ?>"><?= $p ?></a>
              </li>
            <?php endfor; ?>

            <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
              <a class="page-link" href="pawns.php?<?= h2(fin_build_query(['page'=>$next])) ?>">›</a>
            </li>
          </ul>
        </nav>
      <?php endif; ?>

    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
