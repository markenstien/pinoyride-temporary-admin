<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/booking_filters.php';
require_once __DIR__ . '/includes/booking_status.php';

// ---------------------------------------------------------------
// Read & sanitize filter inputs (GET, so results are bookmarkable)
// ---------------------------------------------------------------
$filters    = get_booking_filters();
$whereSql   = $filters['whereSql'];
$params     = $filters['params'];
$dateFrom   = $filters['dateFrom'];
$dateTo     = $filters['dateTo'];
$status     = $filters['status'];
$refCode    = $filters['refCode'];
$pickup     = $filters['pickup'];
$dropoff    = $filters['dropoff'];
$customerId = $filters['customerId'];
$riderId    = $filters['riderId'];

$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 100;
$offset   = ($page - 1) * $perPage;

$bookings   = [];
$totalRows  = 0;
$totalPages = 1;
$errorMsg   = '';

try {
    $pdo = get_pdo();

    // Count total matching rows (for pagination)
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM public.booking b WHERE {$whereSql}");
    $countStmt->execute($params);
    $totalRows  = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));

    // Fetch page of results, joined against customer and riders
    $sql = "SELECT
                b.id, b.ref_code, b.pickup_location, b.dropoff_location,
                b.status, b.payment_type, b.distance_km, b.booking_type,
                b.date_created, b.time_created, b.created_at,
                c.fname       AS customer_fname,
                c.lname       AS customer_lname,
                c.mobile      AS customer_mobile,
                r.first_name  AS rider_fname,
                r.last_name   AS rider_lname,
                r.mobile_no   AS rider_mobile
            FROM public.booking b
            LEFT JOIN public.customer c ON c.id = b.customer_id
            LEFT JOIN public.riders   r ON r.id = b.rider_id
            WHERE {$whereSql}
            ORDER BY b.created_at DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $bookings = $stmt->fetchAll();

} catch (PDOException $e) {
    $errorMsg = 'Query failed: ' . $e->getMessage();
}

// If scoped to a specific customer/rider, fetch their name for the page header
$scopeName = '';
if ($errorMsg === '' && $customerId > 0) {
    $scopeStmt = $pdo->prepare('SELECT fname, lname FROM public.customer WHERE id = :id LIMIT 1');
    $scopeStmt->bindValue(':id', $customerId, PDO::PARAM_INT);
    $scopeStmt->execute();
    if ($scopeRow = $scopeStmt->fetch()) {
        $scopeName = trim($scopeRow['fname'] . ' ' . $scopeRow['lname']);
    }
} elseif ($errorMsg === '' && $riderId > 0) {
    $scopeStmt = $pdo->prepare('SELECT first_name, last_name FROM public.riders WHERE id = :id LIMIT 1');
    $scopeStmt->bindValue(':id', $riderId, PDO::PARAM_INT);
    $scopeStmt->execute();
    if ($scopeRow = $scopeStmt->fetch()) {
        $scopeName = trim($scopeRow['first_name'] . ' ' . $scopeRow['last_name']);
    }
}

// Helper to keep existing filters when building pagination links
function build_query(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    return htmlspecialchars('?' . http_build_query($params), ENT_QUOTES, 'UTF-8');
}

$activeNav = 'bookings';
require __DIR__ . '/includes/header.php';
?>

<?php if ($customerId > 0 || $riderId > 0): ?>
  <div class="mb-3 d-flex justify-content-between align-items-center">
    <div>
      <a href="<?= $customerId > 0 ? 'customer_show.php?id=' . $customerId : 'rider_show.php?id=' . $riderId ?>" class="btn btn-sm btn-outline-secondary">
        &laquo; Back to <?= $customerId > 0 ? 'Customer' : 'Rider' ?>
      </a>
    </div>
    <span class="text-muted">
      Booking history for <strong><?= htmlspecialchars($scopeName !== '' ? $scopeName : ('#' . ($customerId > 0 ? $customerId : $riderId))) ?></strong>
    </span>
  </div>
<?php endif; ?>

<div class="card filter-card mb-4">
  <div class="card-body">
    <h5 class="card-title mb-3">Filter Bookings</h5>
    <form method="get" class="row g-3">
      <?php if ($customerId > 0): ?>
        <input type="hidden" name="customer_id" value="<?= (int)$customerId ?>">
      <?php endif; ?>
      <?php if ($riderId > 0): ?>
        <input type="hidden" name="rider_id" value="<?= (int)$riderId ?>">
      <?php endif; ?>
      <div class="col-md-3">
        <label class="form-label">Date Created From</label>
        <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Date Created To</label>
        <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-control">
          <option value="">-- All --</option>
          <?php foreach (BOOKING_STATUSES as $sVal => $sLabel): ?>
            <option value="<?= $sVal ?>" <?= ((string)$sVal === $status) ? 'selected' : '' ?>><?= htmlspecialchars($sLabel) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Ref Code</label>
        <input type="text" name="ref_code" class="form-control" placeholder="Booking ref code" value="<?= htmlspecialchars($refCode) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Pickup Location</label>
        <input type="text" name="pickup" class="form-control" placeholder="Pickup keyword" value="<?= htmlspecialchars($pickup) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Dropoff Location</label>
        <input type="text" name="dropoff" class="form-control" placeholder="Dropoff keyword" value="<?= htmlspecialchars($dropoff) ?>">
      </div>

      <div class="col-12 d-flex gap-2">
        <button type="submit" class="btn btn-primary">Search</button>
        <a href="<?= $customerId > 0 ? 'bookings.php?customer_id=' . $customerId : ($riderId > 0 ? 'bookings.php?rider_id=' . $riderId : 'bookings.php') ?>" class="btn btn-outline-secondary">Reset</a>
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
    <a href="booking_analysis.php" class="btn btn-sm btn-outline-secondary">Booking Analysis</a>
    <a href="bookings_export.php?<?= htmlspecialchars(http_build_query($_GET), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-success">Export CSV</a>
  </div>
</div>

<div class="table-responsive bg-white">
  <table class="table table-striped table-hover align-middle mb-0">
    <thead>
      <tr>
        <th>Ref Code</th>
        <th>Customer</th>
        <th>Rider</th>
        <th>Pickup</th>
        <th>Dropoff</th>
        <th>Distance (km)</th>
        <th>Payment</th>
        <th>Type</th>
        <th>Status</th>
        <th>Date Created</th>
        <th>Time Created</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($bookings)): ?>
        <tr>
          <td colspan="12" class="text-center text-muted py-4">No bookings found.</td>
        </tr>
      <?php else: ?>
        <?php foreach ($bookings as $b): ?>
          <tr>
            <td><?= htmlspecialchars($b['ref_code']) ?></td>
            <td>
              <?php if ($b['customer_fname']): ?>
                <?= htmlspecialchars(trim($b['customer_fname'] . ' ' . $b['customer_lname'])) ?>
                <div class="text-muted small"><?= htmlspecialchars($b['customer_mobile'] ?? '') ?></div>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($b['rider_fname']): ?>
                <?= htmlspecialchars(trim($b['rider_fname'] . ' ' . $b['rider_lname'])) ?>
                <div class="text-muted small"><?= htmlspecialchars($b['rider_mobile'] ?? '') ?></div>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($b['pickup_location'] ?? '—') ?></td>
            <td><?= htmlspecialchars($b['dropoff_location'] ?? '—') ?></td>
            <td><?= htmlspecialchars($b['distance_km'] ?? '—') ?></td>
            <td><?= htmlspecialchars($b['payment_type'] ?? '—') ?></td>
            <td><?= htmlspecialchars($b['booking_type'] ?? '—') ?></td>
            <td><span class="badge <?= booking_status_badge_class($b['status']) ?>"><?= htmlspecialchars(booking_status_label($b['status'])) ?></span></td>
            <td><?= htmlspecialchars($b['date_created'] ?? '—') ?></td>
            <td><?= htmlspecialchars($b['time_created'] ?? '—') ?></td>
            <td>
              <a href="booking_show.php?id=<?= (int)$b['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
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
