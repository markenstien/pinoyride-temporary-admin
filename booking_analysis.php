<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// ---------------------------------------------------------------
// Read & sanitize filter inputs (GET, so results are bookmarkable)
// ---------------------------------------------------------------
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');

$where  = ['b.deleted_at IS NULL'];
$params = [];

// date_created is stored as varchar (assumed 'YYYY-MM-DD'); cast to date for range comparison
if ($dateFrom !== '') {
    $where[] = "TO_DATE(b.date_created, 'YYYY-MM-DD') >= :date_from";
    $params[':date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = "TO_DATE(b.date_created, 'YYYY-MM-DD') <= :date_to";
    $params[':date_to'] = $dateTo;
}

$whereSql = implode(' AND ', $where);

$totalBookings  = 0;
$topPickups     = [];
$topDropoffs    = [];
$hourlyCounts   = array_fill(0, 24, 0);
$errorMsg       = '';

try {
    $pdo = get_pdo();

    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM public.booking b WHERE {$whereSql}");
    $totalStmt->execute($params);
    $totalBookings = (int)$totalStmt->fetchColumn();

    // Top pickup locations
    $pickupStmt = $pdo->prepare(
        "SELECT b.pickup_location AS location, COUNT(*) AS cnt
         FROM public.booking b
         WHERE {$whereSql} AND b.pickup_location IS NOT NULL AND b.pickup_location <> ''
         GROUP BY b.pickup_location
         ORDER BY cnt DESC
         LIMIT 10"
    );
    $pickupStmt->execute($params);
    $topPickups = $pickupStmt->fetchAll();

    // Top dropoff locations
    $dropoffStmt = $pdo->prepare(
        "SELECT b.dropoff_location AS location, COUNT(*) AS cnt
         FROM public.booking b
         WHERE {$whereSql} AND b.dropoff_location IS NOT NULL AND b.dropoff_location <> ''
         GROUP BY b.dropoff_location
         ORDER BY cnt DESC
         LIMIT 10"
    );
    $dropoffStmt->execute($params);
    $topDropoffs = $dropoffStmt->fetchAll();

    // Peak hours (based on created_at, which is a real timestamp — date_created/time_created are free-text)
    $hourStmt = $pdo->prepare(
        "SELECT EXTRACT(HOUR FROM b.created_at)::int AS hr, COUNT(*) AS cnt
         FROM public.booking b
         WHERE {$whereSql}
         GROUP BY hr
         ORDER BY hr"
    );
    $hourStmt->execute($params);
    foreach ($hourStmt->fetchAll() as $row) {
        $hourlyCounts[(int)$row['hr']] = (int)$row['cnt'];
    }
} catch (PDOException $e) {
    $errorMsg = 'Query failed: ' . $e->getMessage();
}

$peakHour = array_search(max($hourlyCounts), $hourlyCounts, true);

$activeNav = 'bookings';
require __DIR__ . '/includes/header.php';
?>

<div class="mb-3">
  <a href="bookings.php" class="btn btn-sm btn-outline-secondary">&laquo; Back to Bookings</a>
</div>

<div class="card filter-card mb-4">
  <div class="card-body">
    <h5 class="card-title mb-3">Booking Analysis</h5>
    <form method="get" class="row g-3">
      <div class="col-md-3">
        <label class="form-label">Date Created From</label>
        <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Date Created To</label>
        <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
      </div>
      <div class="col-md-6 d-flex align-items-end gap-2">
        <button type="submit" class="btn btn-primary">Apply</button>
        <a href="booking_analysis.php" class="btn btn-outline-secondary">Reset</a>
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
          <div class="text-muted small">Total Bookings</div>
          <div class="fs-3 fw-semibold"><?= number_format($totalBookings) ?></div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card text-center h-100">
        <div class="card-body">
          <div class="text-muted small">Peak Hour</div>
          <div class="fs-3 fw-semibold">
            <?= $totalBookings > 0 ? htmlspecialchars(sprintf('%02d:00 - %02d:00', $peakHour, ($peakHour + 1) % 24)) : '—' ?>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card text-center h-100">
        <div class="card-body">
          <div class="text-muted small">Top Pickup Spot</div>
          <div class="fs-6 fw-semibold text-truncate" title="<?= htmlspecialchars($topPickups[0]['location'] ?? '') ?>">
            <?= htmlspecialchars($topPickups[0]['location'] ?? '—') ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header bg-white fw-semibold">Bookings by Hour of Day</div>
    <div class="card-body">
      <canvas id="hourlyChart" height="90"></canvas>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold">Top 10 Pickup Locations</div>
        <div class="card-body">
          <?php if (empty($topPickups)): ?>
            <span class="text-muted">No data.</span>
          <?php else: ?>
            <canvas id="pickupChart" height="220"></canvas>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold">Top 10 Dropoff Locations</div>
        <div class="card-body">
          <?php if (empty($topDropoffs)): ?>
            <span class="text-muted">No data.</span>
          <?php else: ?>
            <canvas id="dropoffChart" height="220"></canvas>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
  <script>
    const hourlyLabels = <?= json_encode(array_map(fn($h) => sprintf('%02d:00', $h), range(0, 23))) ?>;
    const hourlyData = <?= json_encode(array_values($hourlyCounts)) ?>;

    new Chart(document.getElementById('hourlyChart'), {
      type: 'bar',
      data: {
        labels: hourlyLabels,
        datasets: [{
          label: 'Bookings',
          data: hourlyData,
          backgroundColor: '#0d6efd',
        }],
      },
      options: {
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
      },
    });

    <?php if (!empty($topPickups)): ?>
    new Chart(document.getElementById('pickupChart'), {
      type: 'bar',
      data: {
        labels: <?= json_encode(array_map(fn($r) => $r['location'], $topPickups), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        datasets: [{
          label: 'Bookings',
          data: <?= json_encode(array_map(fn($r) => (int)$r['cnt'], $topPickups)) ?>,
          backgroundColor: '#198754',
        }],
      },
      options: {
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
      },
    });
    <?php endif; ?>

    <?php if (!empty($topDropoffs)): ?>
    new Chart(document.getElementById('dropoffChart'), {
      type: 'bar',
      data: {
        labels: <?= json_encode(array_map(fn($r) => $r['location'], $topDropoffs), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        datasets: [{
          label: 'Bookings',
          data: <?= json_encode(array_map(fn($r) => (int)$r['cnt'], $topDropoffs)) ?>,
          backgroundColor: '#fd7e14',
        }],
      },
      options: {
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
      },
    });
    <?php endif; ?>
  </script>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
