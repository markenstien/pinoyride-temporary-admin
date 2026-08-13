<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
$tabTitle = 'Driver Credits';

// ---------------------------------------------------------------
// Read & sanitize filter inputs (GET, so results are bookmarkable)
// ---------------------------------------------------------------
$search      = trim($_GET['search'] ?? '');
$creditOnly  = ($_GET['credit_only'] ?? '1') !== '0'; // default: only riders with a positive balance

$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 100;
$offset   = ($page - 1) * $perPage;

// ---------------------------------------------------------------
// Build WHERE clause dynamically & safely (parameterized)
// ---------------------------------------------------------------
$where  = [
    "w.user_type = 'rider'",
    "w.type = 'user-wallet'",
    'w.deleted_at IS NULL',
    'r.deleted_at IS NULL',
];
$params = [];

if ($search !== '') {
    $where[] = '(r.code ILIKE :search OR r.first_name ILIKE :search OR r.last_name ILIKE :search OR r.mobile_no ILIKE :search)';
    $params[':search'] = '%' . $search . '%';
}

$whereSql = implode(' AND ', $where);
$havingSql = $creditOnly ? 'HAVING COALESCE(SUM(wt.credit_amount), 0) - COALESCE(SUM(wt.debit_amount), 0) > 0' : '';

$rows       = [];
$totalRows  = 0;
$totalPages = 1;
$summary    = ['rider_count' => 0, 'total_balance' => 0];
$errorMsg   = '';

try {
    $pdo = get_pdo();

    // The wallet_history ledger is the source of truth for the rider's real
    // balance — wallet.avail_balance is a cached column that can drift, so
    // we recompute it from the transaction sums (same approach as rider_show.php).
    $baseSql = "SELECT
                    w.id           AS wallet_id,
                    w.ref_code     AS wallet_ref_code,
                    r.id           AS rider_id,
                    r.code         AS rider_code,
                    r.first_name,
                    r.last_name,
                    r.mobile_no,
                    r.status       AS rider_status,
                    COALESCE(SUM(wt.credit_amount), 0) - COALESCE(SUM(wt.debit_amount), 0) AS balance
                FROM public.wallet w
                INNER JOIN public.riders r ON r.id = w.user_id
                LEFT JOIN public.wallet_history wt ON wt.wallet_id = w.id AND wt.deleted_at IS NULL
                WHERE {$whereSql}
                GROUP BY w.id, w.ref_code, r.id, r.code, r.first_name, r.last_name, r.mobile_no, r.status
                {$havingSql}";

    // Count total matching rows (for pagination) + overall summary
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) AS rider_count, COALESCE(SUM(balance), 0) AS total_balance
         FROM ({$baseSql}) sub"
    );
    $countStmt->execute($params);
    if ($row = $countStmt->fetch()) {
        $summary   = $row;
        $totalRows = (int)$row['rider_count'];
    }
    $totalPages = max(1, (int)ceil($totalRows / $perPage));

    $sql = "{$baseSql} ORDER BY balance DESC LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

} catch (PDOException $e) {
    $errorMsg = 'Query failed: ' . $e->getMessage();
}

function fmt_money($v): string
{
    return '₱' . number_format((float)$v, 2);
}

// Helper to keep existing filters when building pagination links
function build_query(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    return htmlspecialchars('?' . http_build_query($params), ENT_QUOTES, 'UTF-8');
}

$activeNav = 'riders';
require __DIR__ . '/includes/header.php';
?>

<div class="mb-3">
  <a href="riders.php" class="btn btn-sm btn-outline-secondary">&laquo; Back to Drivers</a>
</div>

<div class="card filter-card mb-4">
  <div class="card-body">
    <h5 class="card-title mb-3">Filter Driver Credit</h5>
    <form method="get" class="row g-3">
      <div class="col-md-5">
        <label class="form-label">Search (name / code / mobile)</label>
        <input type="text" name="search" class="form-control" placeholder="Search driver" value="<?= htmlspecialchars($search) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Balance</label>
        <select name="credit_only" class="form-control">
          <option value="1" <?= $creditOnly ? 'selected' : '' ?>>With Credit Only (&gt; ₱0)</option>
          <option value="0" <?= !$creditOnly ? 'selected' : '' ?>>All Drivers (incl. ₱0)</option>
        </select>
      </div>
      <div class="col-md-4 d-flex align-items-end gap-2">
        <button type="submit" class="btn btn-primary">Search</button>
        <a href="riders_credit.php" class="btn btn-outline-secondary">Reset</a>
      </div>
    </form>
  </div>
</div>

<?php if ($errorMsg !== ''): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php else: ?>

  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <div class="card text-center h-100">
        <div class="card-body">
          <div class="text-muted small">Drivers Listed</div>
          <div class="fs-3 fw-semibold"><?= number_format((int)$summary['rider_count']) ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card text-center h-100 border-success">
        <div class="card-body">
          <div class="text-muted small">Total Wallet Credit</div>
          <div class="fs-3 fw-semibold text-success"><?= fmt_money($summary['total_balance']) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex justify-content-between align-items-center mb-2">
    <span class="text-muted"><?= number_format($totalRows) ?> result<?= $totalRows === 1 ? '' : 's' ?> found</span>
  </div>

  <div class="table-responsive bg-white">
    <table class="table table-striped table-hover align-middle mb-0 dataTable" data-paging="false">
      <thead>
        <tr>
          <th>Code</th>
          <th>Full Name</th>
          <th>Mobile</th>
          <th>Status</th>
          <th>Wallet Ref</th>
          <th>Balance</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr>
            <td colspan="7" class="text-center text-muted py-4">No drivers found.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><?= htmlspecialchars($row['rider_code'] ?? '') ?></td>
              <td><?= htmlspecialchars(trim($row['first_name'] . ' ' . $row['last_name'])) ?></td>
              <td><?= htmlspecialchars($row['mobile_no'] ?? '—') ?></td>
              <td>
                <span class="badge <?= ((int)$row['rider_status'] === 1) ? 'badge-status-1' : 'badge-status-0' ?>">
                  <?= ((int)$row['rider_status'] === 1) ? 'Active' : 'Inactive' ?>
                </span>
              </td>
              <td><?= htmlspecialchars($row['wallet_ref_code'] ?? '—') ?></td>
              <td class="fw-semibold text-success"><?= fmt_money($row['balance']) ?></td>
              <td class="d-flex gap-1">
                <a href="rider_show.php?id=<?= (int)$row['rider_id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                <a href="wallet_transactions.php?wallet_id=<?= (int)$row['wallet_id'] ?>" class="btn btn-sm btn-outline-secondary">Transactions</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <nav class="mt-3">
    <ul class="pagination justify-content-center">
      <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
        <a class="page-link" href="<?= build_query(['page' => max(1, $page - 1)]) ?>">Previous</a>
      </li>
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
          <a class="page-link" href="<?= build_query(['page' => $p]) ?>"><?= $p ?></a>
        </li>
      <?php endfor; ?>
      <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
        <a class="page-link" href="<?= build_query(['page' => min($totalPages, $page + 1)]) ?>">Next</a>
      </li>
    </ul>
  </nav>
  <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
