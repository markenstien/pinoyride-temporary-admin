<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// ---------------------------------------------------------------
// Read & sanitize filter inputs (GET, so results are bookmarkable)
// ---------------------------------------------------------------
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');
$mobile   = trim($_GET['mobile'] ?? '');
$fname    = trim($_GET['fname'] ?? '');
$lname    = trim($_GET['lname'] ?? '');

$isActive = $_GET['is_online'] ?? '';

$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 100;
$offset   = ($page - 1) * $perPage;

// ---------------------------------------------------------------
// Build WHERE clause dynamically & safely (parameterized)
// ---------------------------------------------------------------
$where  = ['deleted_at IS NULL'];
$params = [];

if ($dateFrom !== '') {
    $where[] = 'created_at >= :date_from';
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'created_at <= :date_to';
    $params[':date_to'] = $dateTo . ' 23:59:59';
}
if ($mobile !== '') {
    $where[] = 'mobile_no ILIKE :mobile';
    $params[':mobile'] = '%' . $mobile . '%';
}
if ($fname !== '') {
    $where[] = 'first_name ILIKE :fname';
    $params[':fname'] = '%' . $fname . '%';
}
if ($lname !== '') {
    $where[] = 'last_name ILIKE :lname';
    $params[':lname'] = '%' . $lname . '%';
}

if($isActive !== '') {
  $where[] = 'is_online = :is_online';
  $params[':is_online'] = $isActive;
}

$whereSql = implode(' AND ', $where);

$riders     = [];
$totalRows  = 0;
$totalPages = 1;
$errorMsg   = '';

try {
    $pdo = get_pdo();

    // Count total matching rows (for pagination)
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM public.riders WHERE {$whereSql}");
    $countStmt->execute($params);
    $totalRows  = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));

    // Fetch page of results
    $sql = "SELECT id, code, first_name, middle_name, last_name, mobile_no, email_address,
                   drivers_license_no, drivers_license_no_expiration_date,
                   status, application_status, is_verified, is_online, is_available,
                   created_at
            FROM public.riders
            WHERE {$whereSql}
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $riders = $stmt->fetchAll();

} catch (PDOException $e) {
    $errorMsg = 'Query failed: ' . $e->getMessage();
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

<div class="card filter-card mb-4">
  <div class="card-body">
    <h5 class="card-title mb-3">Filter Riders</h5>
    <form method="get" class="row g-3">
      <div class="col-md-3">
        <label class="form-label">Created From</label>
        <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Created To</label>
        <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">Mobile</label>
        <input type="text" name="mobile" class="form-control" placeholder="09xxxxxxxxx" value="<?= htmlspecialchars($mobile) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">First Name</label>
        <input type="text" name="fname" class="form-control" placeholder="First name" value="<?= htmlspecialchars($fname) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">Last Name</label>
        <input type="text" name="lname" class="form-control" placeholder="Last name" value="<?= htmlspecialchars($lname) ?>">
      </div>

      <div class="col-md-3">
        <label class="form-label">Installed and Logged In</label>
        <select name="is_online" id="" class="form-control">
          <option value="">--Select</option>
          <option value="1">Installed & Logged In</option>
          <option value="0">Not Active</option>
        </select>
      </div>

      <div class="col-12 d-flex gap-2">
        <button type="submit" class="btn btn-primary">Search</button>
        <a href="riders.php" class="btn btn-outline-secondary">Reset</a>
      </div>
    </form>
  </div>
</div>

<?php if ($errorMsg !== ''): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-2">
  <span class="text-muted"><?= number_format($totalRows) ?> result<?= $totalRows === 1 ? '' : 's' ?> found</span>
  <div class="d-flex gap-2">
    <a href="riders_export.php<?= build_query() ?>" class="btn btn-sm btn-outline-primary">Export CSV</a>
    <a href="import_rider.php" class="btn btn-sm btn-success">Import from CSV</a>
  </div>
</div>

<div class="table-responsive bg-white">
  <table class="table table-striped table-hover align-middle mb-0">
    <thead>
      <tr>
        <th>Code</th>
        <th>Full Name</th>
        <th>Mobile</th>
        <th>Email</th>
        <th>License No.</th>
        <th>License Expiry</th>
        <th>Status</th>
        <th>Application</th>
        <th>Verified</th>
        <th>Online</th>
        <th>Available</th>
        <th>Created At</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($riders)): ?>
        <tr>
          <td colspan="13" class="text-center text-muted py-4">No riders found.</td>
        </tr>
      <?php else: ?>
        <?php foreach ($riders as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['code']) ?></td>
            <td>
              <?= htmlspecialchars(trim($r['first_name'] . ' ' . ($r['middle_name'] ? $r['middle_name'] . ' ' : '') . $r['last_name'])) ?>
            </td>
            <td><?= htmlspecialchars($r['mobile_no']) ?></td>
            <td><?= htmlspecialchars($r['email_address'] ?? '—') ?></td>
            <td><?= htmlspecialchars($r['drivers_license_no'] ?? '—') ?></td>
            <td>
              <?= $r['drivers_license_no_expiration_date']
                    ? htmlspecialchars(date('Y-m-d', strtotime($r['drivers_license_no_expiration_date'])))
                    : '—' ?>
            </td>
            <td>
              <span class="badge <?= ((int)$r['status'] === 1) ? 'badge-status-1' : 'badge-status-0' ?>">
                <?= ((int)$r['status'] === 1) ? 'Active' : 'Inactive' ?>
              </span>
            </td>
            <td><?= htmlspecialchars((string)$r['application_status']) ?></td>
            <td>
              <?= ((int)$r['is_verified'] === 1)
                    ? '<span class="badge bg-success">Yes</span>'
                    : '<span class="badge bg-secondary">No</span>' ?>
            </td>
            <td>
              <?= ((int)$r['is_online'] === 1)
                    ? '<span class="badge bg-success">Online</span>'
                    : '<span class="badge bg-secondary">Offline</span>' ?>
            </td>
            <td>
              <?= ((int)$r['is_available'] === 1)
                    ? '<span class="badge bg-success">Yes</span>'
                    : '<span class="badge bg-secondary">No</span>' ?>
            </td>
            <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($r['created_at']))) ?></td>
            <td>
              <a href="rider_show.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
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

<?php require __DIR__ . '/includes/footer.php'; ?>
