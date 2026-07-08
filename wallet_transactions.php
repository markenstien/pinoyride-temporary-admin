<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$walletId = (int)($_GET['wallet_id'] ?? 0);
$wallet   = null;
$errorMsg = '';

// ---------------------------------------------------------------
// Read & sanitize filter inputs (GET, so results are bookmarkable)
// ---------------------------------------------------------------
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');
$tranType = trim($_GET['tran_type'] ?? '');
$status   = trim($_GET['status'] ?? '');

$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 100;
$offset   = ($page - 1) * $perPage;

$transactions = [];
$totalRows    = 0;
$totalPages   = 1;

if ($walletId <= 0) {
    $errorMsg = 'Invalid wallet id.';
} else {
    try {
        $pdo = get_pdo();

        $walletStmt = $pdo->prepare(
            "SELECT w.*, r.first_name AS rider_fname, r.last_name AS rider_lname, r.code AS rider_code
             FROM public.wallet w
             LEFT JOIN public.riders r ON r.id = w.user_id AND w.user_type = 'rider'
             WHERE w.id = :wallet_id
             LIMIT 1"
        );
        $walletStmt->bindValue(':wallet_id', $walletId, PDO::PARAM_INT);
        $walletStmt->execute();
        $wallet = $walletStmt->fetch();

        if (!$wallet) {
            $errorMsg = 'Wallet not found.';
        } else {
            // -----------------------------------------------------------
            // Build WHERE clause dynamically & safely (parameterized)
            // -----------------------------------------------------------
            $where  = ['wt.wallet_id = :wallet_id', 'wt.deleted_at IS NULL'];
            $params = [':wallet_id' => $walletId];

            if ($dateFrom !== '') {
                $where[] = 'wt.created_at >= :date_from';
                $params[':date_from'] = $dateFrom . ' 00:00:00';
            }
            if ($dateTo !== '') {
                $where[] = 'wt.created_at <= :date_to';
                $params[':date_to'] = $dateTo . ' 23:59:59';
            }
            if ($tranType !== '') {
                $where[] = 'wt.tran_type ILIKE :tran_type';
                $params[':tran_type'] = '%' . $tranType . '%';
            }
            if ($status !== '' && ctype_digit($status)) {
                $where[] = 'wt.status = :status';
                $params[':status'] = (int)$status;
            }

            $whereSql = implode(' AND ', $where);

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM public.wallet_history wt WHERE {$whereSql}");
            $countStmt->execute($params);
            $totalRows  = (int)$countStmt->fetchColumn();
            $totalPages = max(1, (int)ceil($totalRows / $perPage));

            $sql = "SELECT wt.*
                    FROM public.wallet_history wt
                    WHERE {$whereSql}
                    ORDER BY wt.created_at DESC
                    LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $transactions = $stmt->fetchAll();
        }
    } catch (PDOException $e) {
        $errorMsg = 'Query failed: ' . $e->getMessage();
    }
}

function val($v): string
{
    return ($v === null || $v === '') ? '—' : htmlspecialchars((string)$v);
}

function fmt_dt(?string $v): string
{
    if (!$v) return '—';
    $ts = strtotime($v);
    return $ts ? htmlspecialchars(date('Y-m-d H:i:s', $ts)) : htmlspecialchars($v);
}

function fmt_money($v): string
{
    return ($v === null || $v === '') ? '—' : '₱' . number_format((float)$v, 2);
}

// Helper to keep existing filters (and wallet_id) when building pagination links
function build_query(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    return htmlspecialchars('?' . http_build_query($params), ENT_QUOTES, 'UTF-8');
}

$activeNav = 'riders';
require __DIR__ . '/includes/header.php';
?>

<div class="mb-3 d-flex gap-2">
  <?php if ($wallet && $wallet['user_id']): ?>
    <a href="rider_show.php?id=<?= (int)$wallet['user_id'] ?>" class="btn btn-sm btn-outline-secondary">&laquo; Back to Rider</a>
  <?php else: ?>
    <a href="riders.php" class="btn btn-sm btn-outline-secondary">&laquo; Back to Riders</a>
  <?php endif; ?>
</div>

<?php if ($errorMsg !== ''): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php else: ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">
      Wallet Transactions
      <small class="text-muted">
        &mdash; <?= val($wallet['ref_code']) ?>
        <?php if ($wallet['rider_fname']): ?>
          (<?= val(trim($wallet['rider_fname'] . ' ' . $wallet['rider_lname'])) ?>)
        <?php endif; ?>
      </small>
    </h4>
    <span class="badge bg-info text-dark fs-6">Avail. Balance: <?= fmt_money($wallet['avail_balance']) ?></span>
  </div>

  <div class="card filter-card mb-4">
    <div class="card-body">
      <h5 class="card-title mb-3">Filter Transactions</h5>
      <form method="get" class="row g-3">
        <input type="hidden" name="wallet_id" value="<?= (int)$walletId ?>">
        <div class="col-md-3">
          <label class="form-label">Created From</label>
          <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Created To</label>
          <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Transaction Type</label>
          <input type="text" name="tran_type" class="form-control" placeholder="e.g. credit" value="<?= htmlspecialchars($tranType) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Status</label>
          <input type="number" name="status" class="form-control" placeholder="e.g. 1" value="<?= htmlspecialchars($status) ?>">
        </div>

        <div class="col-12 d-flex gap-2">
          <button type="submit" class="btn btn-primary">Search</button>
          <a href="wallet_transactions.php?wallet_id=<?= (int)$walletId ?>" class="btn btn-outline-secondary">Reset</a>
        </div>
      </form>
    </div>
  </div>

  <div class="d-flex justify-content-between align-items-center mb-2">
    <span class="text-muted"><?= number_format($totalRows) ?> result<?= $totalRows === 1 ? '' : 's' ?> found</span>
  </div>

  <div class="table-responsive bg-white">
    <table class="table table-striped table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Created At</th>
          <th>Tran Type</th>
          <th>Type</th>
          <th>Credit</th>
          <th>Debit</th>
          <th>Status</th>
          <th>Creditor Acct</th>
          <th>Debitor Acct</th>
          <th>Booking</th>
          <th>Description</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($transactions)): ?>
          <tr>
            <td colspan="10" class="text-center text-muted py-4">No transactions found.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($transactions as $t): ?>
            <tr>
              <td><?= fmt_dt($t['created_at']) ?></td>
              <td><?= val($t['tran_type']) ?></td>
              <td><?= val($t['type']) ?></td>
              <td class="text-success"><?= (float)$t['credit_amount'] > 0 ? fmt_money($t['credit_amount']) : '—' ?></td>
              <td class="text-danger"><?= (float)$t['debit_amount'] > 0 ? fmt_money($t['debit_amount']) : '—' ?></td>
              <td><?= val($t['status']) ?></td>
              <td><?= val($t['creditor_account_number']) ?></td>
              <td><?= val($t['debitor_account_number']) ?></td>
              <td>
                <?php if ($t['booking_id'] && ctype_digit((string)$t['booking_id'])): ?>
                  <a href="booking_show.php?id=<?= (int)$t['booking_id'] ?>"><?= val($t['booking_id']) ?></a>
                <?php else: ?>
                  <?= val($t['booking_id']) ?>
                <?php endif; ?>
              </td>
              <td><?= val($t['description']) ?></td>
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
