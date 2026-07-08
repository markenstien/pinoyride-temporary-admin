<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$id = (int)($_GET['id'] ?? 0);
$customer = null;
$errorMsg = '';
$recentBookings = [];
$bookingsErrorMsg = '';
$ekyc = null;
$ekycErrorMsg = '';

if ($id <= 0) {
    $errorMsg = 'Invalid customer id.';
} else {
    try {
        $pdo = get_pdo();

        $sql = "SELECT c.*
                FROM public.customer c
                WHERE c.id = :id
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $customer = $stmt->fetch();

        if (!$customer) {
            $errorMsg = 'Customer not found.';
        }
    } catch (PDOException $e) {
        $errorMsg = 'Query failed: ' . $e->getMessage();
    }

    if ($customer) {
        try {
            $bkStmt = $pdo->prepare(
                "SELECT id, ref_code, status, pickup_location, dropoff_location, date_created, created_at
                 FROM public.booking
                 WHERE customer_id = :customer_id AND deleted_at IS NULL
                 ORDER BY created_at DESC
                 LIMIT 5"
            );
            $bkStmt->bindValue(':customer_id', $id, PDO::PARAM_INT);
            $bkStmt->execute();
            $recentBookings = $bkStmt->fetchAll();
        } catch (PDOException $e) {
            $bookingsErrorMsg = 'Bookings query failed: ' . $e->getMessage();
        }

        if (!empty($customer['ekyc_request_user_id'])) {
            try {
                $ekycStmt = $pdo->prepare(
                    "SELECT *
                     FROM public.top_ph_ekyc_details
                     WHERE generate_request_user_id = :req_id AND deleted_at IS NULL
                     ORDER BY created_at DESC
                     LIMIT 1"
                );
                $ekycStmt->bindValue(':req_id', $customer['ekyc_request_user_id']);
                $ekycStmt->execute();
                $ekyc = $ekycStmt->fetch();
            } catch (PDOException $e) {
                $ekycErrorMsg = 'eKYC query failed: ' . $e->getMessage();
            }
        }
    }
}

function file_link($v): string
{
    $v = trim((string)($v ?? ''));
    if ($v === '') return '—';
    if (preg_match('#^https?://#i', $v)) {
        return '<a href="' . htmlspecialchars($v) . '" target="_blank" rel="noopener">View</a>';
    }
    return htmlspecialchars($v);
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

$activeNav = 'customers';
require __DIR__ . '/includes/header.php';
?>

<div class="mb-3">
  <a href="index.php" class="btn btn-sm btn-outline-secondary">&laquo; Back to Customers</a>
</div>

<?php if ($errorMsg !== ''): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php else: ?>

  <?php if (($_GET['created'] ?? '') === '1'): ?>
    <div class="alert alert-success">Customer created successfully.</div>
  <?php endif; ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">
      <?= val(trim(($customer['fname'] ?? '') . ' ' . ($customer['mname'] ? $customer['mname'] . ' ' : '') . ($customer['lname'] ?? ''))) ?>
    </h4>
    <span class="badge <?= ((int)$customer['status'] === 1) ? 'badge-status-1' : 'badge-status-0' ?> fs-6">
      <?= ((int)$customer['status'] === 1) ? 'Active' : 'Inactive' ?>
    </span>
  </div>

  <div class="row g-3">

    <!-- Personal info -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold">Personal Info</div>
        <div class="card-body">
          <table class="table table-sm mb-0">
            <tr><th style="width:40%">Code</th><td><?= val($customer['code']) ?></td></tr>
            <tr><th>First Name</th><td><?= val($customer['fname']) ?></td></tr>
            <tr><th>Middle Name</th><td><?= val($customer['mname']) ?></td></tr>
            <tr><th>Last Name</th><td><?= val($customer['lname']) ?></td></tr>
            <tr><th>Mobile</th><td><?= val($customer['mobile']) ?></td></tr>
            <tr><th>Email</th><td><?= val($customer['email']) ?></td></tr>
            <tr><th>Customer Type</th><td><?= val($customer['customer_type']) ?></td></tr>
          </table>
        </div>
      </div>
    </div>

    <!-- Account status -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold">Account Status</div>
        <div class="card-body">
          <table class="table table-sm mb-0">
            <tr><th style="width:40%">Status</th>
              <td>
                <span class="badge <?= ((int)$customer['status'] === 1) ? 'badge-status-1' : 'badge-status-0' ?>">
                  <?= ((int)$customer['status'] === 1) ? 'Active' : 'Inactive' ?>
                </span>
              </td>
            </tr>
            <tr><th>Verified</th>
              <td>
                <?= ((int)$customer['is_verified'] === 1)
                      ? '<span class="badge bg-success">Yes</span>'
                      : '<span class="badge bg-secondary">No</span>' ?>
              </td>
            </tr>
          </table>
        </div>
      </div>
    </div>

    <!-- Meta / audit -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold">Record Info</div>
        <div class="card-body">
          <table class="table table-sm mb-0">
            <tr><th style="width:40%">Customer ID</th><td><?= val($customer['id']) ?></td></tr>
            <tr><th>Created At</th><td><?= fmt_dt($customer['created_at'] ?? null) ?></td></tr>
            <tr><th>Updated At</th><td><?= fmt_dt($customer['updated_at'] ?? null) ?></td></tr>
            <tr><th>Deleted At</th><td><?= fmt_dt($customer['deleted_at'] ?? null) ?></td></tr>
          </table>
        </div>
      </div>
    </div>

    <!-- eKYC / Verification -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold">eKYC / Verification Details</div>
        <div class="card-body">
          <?php if ($ekycErrorMsg !== ''): ?>
            <div class="alert alert-danger mb-0"><?= htmlspecialchars($ekycErrorMsg) ?></div>
          <?php elseif ($ekyc): ?>
            <table class="table table-sm mb-0">
              <tr><th style="width:40%">KYC ID</th><td><?= val($ekyc['kyc_id']) ?></td></tr>
              <tr><th>Status</th><td><?= val($ekyc['status']) ?></td></tr>
              <tr><th>Place of Birth</th><td><?= val($ekyc['place_of_birth']) ?></td></tr>
              <tr><th>Nationality</th><td><?= val($ekyc['nationality']) ?></td></tr>
              <tr><th>ID Type</th><td><?= val($ekyc['id_type']) ?></td></tr>
              <tr><th>ID Number</th><td><?= val($ekyc['id_number']) ?></td></tr>
              <tr><th>ID Front</th><td><?= file_link($ekyc['id_front']) ?></td></tr>
              <tr><th>ID Back</th><td><?= file_link($ekyc['id_back']) ?></td></tr>
              <tr><th>Selfie</th><td><?= file_link($ekyc['selfie']) ?></td></tr>
              <tr><th>Completed At</th><td><?= fmt_dt($ekyc['completed_at']) ?></td></tr>
            </table>
          <?php else: ?>
            <span class="text-muted">No eKYC record linked to this customer.</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Booking History -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
          Booking History
          <a href="bookings.php?customer_id=<?= (int)$id ?>" class="btn btn-sm btn-outline-primary">View All</a>
        </div>
        <div class="card-body">
          <?php if ($bookingsErrorMsg !== ''): ?>
            <div class="alert alert-danger mb-0"><?= htmlspecialchars($bookingsErrorMsg) ?></div>
          <?php elseif (empty($recentBookings)): ?>
            <span class="text-muted">No bookings yet.</span>
          <?php else: ?>
            <table class="table table-sm mb-0">
              <thead>
                <tr>
                  <th>Ref Code</th>
                  <th>Route</th>
                  <th>Status</th>
                  <th>Date</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentBookings as $bk): ?>
                  <tr>
                    <td><?= val($bk['ref_code']) ?></td>
                    <td class="small"><?= val($bk['pickup_location']) ?> &rarr; <?= val($bk['dropoff_location']) ?></td>
                    <td><span class="badge bg-info text-dark"><?= val($bk['status']) ?></span></td>
                    <td><?= val($bk['date_created']) ?></td>
                    <td><a href="booking_show.php?id=<?= (int)$bk['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
