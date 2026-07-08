<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$rider = null;
$wallet = null;
$errorMsg = '';
$walletErrorMsg = '';
$recentBookings = [];
$bookingsErrorMsg = '';
$vehicle = null;
$vehicleErrorMsg = '';
$address = null;
$addressErrorMsg = '';

if ($id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_online'])) {
    try {
        $pdo = get_pdo();
        $newOnline = ((int)$_POST['toggle_online'] === 1) ? 1 : 0;

        $toggleStmt = $pdo->prepare(
            "UPDATE public.riders
             SET is_online = :is_online,
                 updated_at = NOW()
             WHERE id = :id"
        );
        $toggleStmt->bindValue(':is_online', $newOnline, PDO::PARAM_INT);
        $toggleStmt->bindValue(':id', $id, PDO::PARAM_INT);
        $toggleStmt->execute();

        header('Location: rider_show.php?id=' . $id . '&updated=1');
        exit;
    } catch (PDOException $e) {
        $errorMsg = 'Update failed: ' . $e->getMessage();
    }
}

if ($id <= 0) {
    $errorMsg = 'Invalid rider id.';
} else {
    try {
        $pdo = get_pdo();

        $sql = "SELECT r.*
                FROM public.riders r
                WHERE r.id = :id
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $rider = $stmt->fetch();

        if (!$rider) {
            $errorMsg = 'Rider not found.';
        }
    } catch (PDOException $e) {
        $errorMsg = 'Query failed: ' . $e->getMessage();
    }

    if ($rider) {
        try {
            // The wallet_history ledger is the source of truth for the
            // rider's real balance — wallet.avail_balance is a cached column
            // that can drift, so we recompute it from the transaction sums.
            $walletSql = "SELECT w.*,
                                 COALESCE(SUM(wt.credit_amount), 0) AS tx_total_credit,
                                 COALESCE(SUM(wt.debit_amount), 0)  AS tx_total_debit
                          FROM public.wallet w
                          LEFT JOIN public.wallet_history wt
                                 ON wt.wallet_id = w.id AND wt.deleted_at IS NULL
                          WHERE w.user_id = :user_id
                            AND w.user_type = 'rider'
                            AND w.type = 'user-wallet'
                            AND w.deleted_at IS NULL
                          GROUP BY w.id
                          LIMIT 1";

            $walletStmt = $pdo->prepare($walletSql);
            $walletStmt->bindValue(':user_id', $id, PDO::PARAM_INT);
            $walletStmt->execute();
            $wallet = $walletStmt->fetch();
        } catch (PDOException $e) {
            $walletErrorMsg = 'Wallet query failed: ' . $e->getMessage();
        }

        try {
            $bkStmt = $pdo->prepare(
                "SELECT id, ref_code, status, pickup_location, dropoff_location, date_created, created_at
                 FROM public.booking
                 WHERE rider_id = :rider_id AND deleted_at IS NULL
                 ORDER BY created_at DESC
                 LIMIT 5"
            );
            $bkStmt->bindValue(':rider_id', $id, PDO::PARAM_INT);
            $bkStmt->execute();
            $recentBookings = $bkStmt->fetchAll();
        } catch (PDOException $e) {
            $bookingsErrorMsg = 'Bookings query failed: ' . $e->getMessage();
        }

        try {
            // deleted_at on this table isn't a reliable "is deleted" flag
            // (import data has it stamped on every row), so just take the
            // most recently created record for the rider.
            $vehStmt = $pdo->prepare(
                "SELECT v_type, v_brand, v_model, v_color, v_plate_number, v_or_cr_img, v_vehicle_img, status
                 FROM public.rider_vehicle_details
                 WHERE rider_id = :rider_id
                 ORDER BY created_at DESC
                 LIMIT 1"
            );
            $vehStmt->bindValue(':rider_id', $id, PDO::PARAM_INT);
            $vehStmt->execute();
            $vehicle = $vehStmt->fetch();
        } catch (PDOException $e) {
            $vehicleErrorMsg = 'Vehicle query failed: ' . $e->getMessage();
        }

        try {
            $addrStmt = $pdo->prepare(
                "SELECT type, address, barangay, municipality_city, province, zip_code
                 FROM public.rider_address
                 WHERE rider_id = :rider_id
                 ORDER BY created_at DESC
                 LIMIT 1"
            );
            $addrStmt->bindValue(':rider_id', $id, PDO::PARAM_INT);
            $addrStmt->execute();
            $address = $addrStmt->fetch();
        } catch (PDOException $e) {
            $addressErrorMsg = 'Address query failed: ' . $e->getMessage();
        }
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

function fmt_date(?string $v): string
{
    if (!$v) return '—';
    $ts = strtotime($v);
    return $ts ? htmlspecialchars(date('Y-m-d', $ts)) : htmlspecialchars($v);
}

function badge_bool($v, string $onLabel = 'Yes', string $offLabel = 'No'): string
{
    return ((int)$v === 1)
        ? '<span class="badge bg-success">' . htmlspecialchars($onLabel) . '</span>'
        : '<span class="badge bg-secondary">' . htmlspecialchars($offLabel) . '</span>';
}

function fmt_money($v): string
{
    return ($v === null || $v === '') ? '—' : '₱' . number_format((float)$v, 2);
}

function vehicle_type_label($v): string
{
    return ((int)$v === 1) ? 'Motorcycle' : 'Other';
}

$activeNav = 'riders';
require __DIR__ . '/includes/header.php';
?>

<div class="mb-3">
  <a href="riders.php" class="btn btn-sm btn-outline-secondary">&laquo; Back to Riders</a>
</div>

<?php if ($errorMsg !== ''): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php else: ?>

  <?php if (($_GET['credit_added'] ?? '') === '1'): ?>
    <div class="alert alert-success">Credit added successfully.</div>
  <?php endif; ?>
  <?php if (($_GET['updated'] ?? '') === '1'): ?>
    <div class="alert alert-success">Rider updated successfully.</div>
  <?php endif; ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">
      <?= val(trim(($rider['first_name'] ?? '') . ' ' . ($rider['middle_name'] ? $rider['middle_name'] . ' ' : '') . ($rider['last_name'] ?? ''))) ?>
    </h4>
    <span class="badge <?= ((int)$rider['status'] === 1) ? 'badge-status-1' : 'badge-status-0' ?> fs-6">
      <?= ((int)$rider['status'] === 1) ? 'Active' : 'Inactive' ?>
    </span>
  </div>

  <div class="row g-3">

    <!-- Personal info -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold">Personal Info</div>
        <div class="card-body">
          <table class="table table-sm mb-0">
            <tr><th style="width:40%">Code</th><td><?= val($rider['code']) ?></td></tr>
            <tr><th>First Name</th><td><?= val($rider['first_name']) ?></td></tr>
            <tr><th>Middle Name</th><td><?= val($rider['middle_name']) ?></td></tr>
            <tr><th>Last Name</th><td><?= val($rider['last_name']) ?></td></tr>
            <tr><th>Mobile</th><td><?= val($rider['mobile_no']) ?></td></tr>
            <tr><th>Email</th><td><?= val($rider['email_address']) ?></td></tr>
          </table>
        </div>
      </div>
    </div>

    <!-- License info -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
          License Info
          <a href="rider_edit.php?id=<?= (int)$id ?>" class="btn btn-sm btn-outline-primary">Edit</a>
        </div>
        <div class="card-body">
          <table class="table table-sm mb-0">
            <tr><th style="width:40%">License No.</th><td><?= val($rider['drivers_license_no']) ?></td></tr>
            <tr><th>License Expiry</th><td><?= fmt_date($rider['drivers_license_no_expiration_date'] ?? null) ?></td></tr>
            <tr><th>License Image</th><td><?= val($rider['drivers_license_img']) ?></td></tr>
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
                <span class="badge <?= ((int)$rider['status'] === 1) ? 'badge-status-1' : 'badge-status-0' ?>">
                  <?= ((int)$rider['status'] === 1) ? 'Active' : 'Inactive' ?>
                </span>
              </td>
            </tr>
            <tr><th>Application Status</th><td><?= val($rider['application_status']) ?></td></tr>
            <tr><th>Verified</th><td><?= badge_bool($rider['is_verified']) ?></td></tr>
            <tr>
              <th>Online</th>
              <td class="d-flex align-items-center gap-2">
                <?= badge_bool($rider['is_online'], 'Online', 'Offline') ?>
                <form method="post" class="d-inline">
                  <input type="hidden" name="id" value="<?= (int)$id ?>">
                  <input type="hidden" name="toggle_online" value="<?= ((int)$rider['is_online'] === 1) ? 0 : 1 ?>">
                  <button type="submit" class="btn btn-sm <?= ((int)$rider['is_online'] === 1) ? 'btn-outline-secondary' : 'btn-outline-success' ?>">
                    <?= ((int)$rider['is_online'] === 1) ? 'Set Offline' : 'Set Online' ?>
                  </button>
                </form>
              </td>
            </tr>
            <tr><th>Available</th><td><?= badge_bool($rider['is_available']) ?></td></tr>
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
            <tr><th style="width:40%">Rider ID</th><td><?= val($rider['id']) ?></td></tr>
            <tr><th>Created At</th><td><?= fmt_dt($rider['created_at'] ?? null) ?></td></tr>
            <tr><th>Updated At</th><td><?= fmt_dt($rider['updated_at'] ?? null) ?></td></tr>
            <tr><th>Deleted At</th><td><?= fmt_dt($rider['deleted_at'] ?? null) ?></td></tr>
          </table>
        </div>
      </div>
    </div>

    <!-- Vehicle details -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold">Vehicle Details</div>
        <div class="card-body">
          <?php if ($vehicleErrorMsg !== ''): ?>
            <div class="alert alert-danger mb-0"><?= htmlspecialchars($vehicleErrorMsg) ?></div>
          <?php elseif ($vehicle): ?>
            <table class="table table-sm mb-0">
              <tr><th style="width:40%">Type</th><td><?= val(vehicle_type_label($vehicle['v_type'])) ?></td></tr>
              <tr><th>Brand</th><td><?= val($vehicle['v_brand']) ?></td></tr>
              <tr><th>Model</th><td><?= val($vehicle['v_model']) ?></td></tr>
              <tr><th>Color</th><td><?= val($vehicle['v_color']) ?></td></tr>
              <tr><th>Plate Number</th><td><?= val($vehicle['v_plate_number']) ?></td></tr>
              <tr><th>OR/CR Image</th><td><?= val($vehicle['v_or_cr_img']) ?></td></tr>
              <tr><th>Vehicle Image</th><td><?= val($vehicle['v_vehicle_img']) ?></td></tr>
            </table>
          <?php else: ?>
            <span class="text-muted">No vehicle details on file.</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Address details -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold">Address Details</div>
        <div class="card-body">
          <?php if ($addressErrorMsg !== ''): ?>
            <div class="alert alert-danger mb-0"><?= htmlspecialchars($addressErrorMsg) ?></div>
          <?php elseif ($address): ?>
            <table class="table table-sm mb-0">
              <tr><th style="width:40%">Type</th><td><?= val($address['type']) ?></td></tr>
              <tr><th>Address</th><td><?= val($address['address']) ?></td></tr>
              <tr><th>Barangay</th><td><?= val($address['barangay']) ?></td></tr>
              <tr><th>City/Municipality</th><td><?= val($address['municipality_city']) ?></td></tr>
              <tr><th>Province</th><td><?= val($address['province']) ?></td></tr>
              <tr><th>Zip Code</th><td><?= val($address['zip_code']) ?></td></tr>
            </table>
          <?php else: ?>
            <span class="text-muted">No address on file.</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Wallet -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
          Wallet Information
          <div class="d-flex gap-2">
            <?php if ($wallet): ?>
              <a href="wallet_credit_create.php?wallet_id=<?= (int)$wallet['id'] ?>" class="btn btn-sm btn-success">Add Credit</a>
              <a href="wallet_transactions.php?wallet_id=<?= (int)$wallet['id'] ?>" class="btn btn-sm btn-outline-primary">View Transactions</a>
            <?php else: ?>
              <a href="wallet_credit_create.php?rider_id=<?= (int)$id ?>" class="btn btn-sm btn-success">Add Credit</a>
            <?php endif; ?>
          </div>
        </div>
        <div class="card-body">
          <?php if ($walletErrorMsg !== ''): ?>
            <div class="alert alert-danger mb-0"><?= htmlspecialchars($walletErrorMsg) ?></div>
          <?php elseif ($wallet): ?>
            <table class="table table-sm mb-0">
              <tr><th style="width:40%">Ref Code</th><td><?= val($wallet['ref_code']) ?></td></tr>
              <tr><th>Total Debits</th><td><?= fmt_money($wallet['tx_total_debit']) ?></td></tr>
              <tr class="table-light">
                <th>Remaining Balance</th>
                <td class="fw-semibold"><?= fmt_money((float)$wallet['tx_total_credit'] - (float)$wallet['tx_total_debit']) ?></td>
              </tr>
              <tr><th>Wallet Created At</th><td><?= fmt_dt($wallet['created_at']) ?></td></tr>
              <tr><th>Wallet Updated At</th><td><?= fmt_dt($wallet['updated_at']) ?></td></tr>
            </table>
          <?php else: ?>
            <span class="text-muted">No wallet linked to this rider.</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Booking History -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
          Booking History
          <a href="bookings.php?rider_id=<?= (int)$id ?>" class="btn btn-sm btn-outline-primary">View All</a>
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
