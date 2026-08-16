<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/booking_status.php';
require_once __DIR__ . '/includes/customer_ingest.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$booking = null;
$errorMsg = '';
$statusUpdateErrorMsg = '';
$assignRiderErrorMsg = '';

// Manual driver assignment: the booking is still "Looking for Driver" (no
// broadcast match yet) but the admin has already lined up a driver outside
// the app (e.g. by phone) and just needs to attach them to the booking.
// Looked up by mobile number only — same normalization rule as
// booking_create.php's find_rider_by_mobile. Moves the booking straight to
// Accepted by Driver, mirroring what a normal broadcast-accept would do.
if ($id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_rider_mobile'])) {
    [$driverMobile, $driverMobileRecognized] = normalize_mobile_to_63(trim($_POST['assign_rider_mobile']));

    if ($driverMobile === '' || !$driverMobileRecognized) {
        $assignRiderErrorMsg = 'Enter a valid driver mobile number.';
    } else {
        try {
            $pdo = get_pdo();

            $riderStmt = $pdo->prepare(
                "SELECT id FROM public.riders WHERE mobile_no = :mobile AND deleted_at IS NULL LIMIT 1"
            );
            $riderStmt->execute([':mobile' => $driverMobile]);
            $riderId = $riderStmt->fetchColumn();

            if ($riderId === false) {
                $assignRiderErrorMsg = 'No driver found with that mobile number.';
            } else {
                // Re-checked here (status = 0) so a stale page can't attach a
                // driver to a booking that has since moved on.
                $assignStmt = $pdo->prepare(
                    "UPDATE public.booking
                     SET rider_id = :rider_id,
                         status = 1,
                         updated_at = NOW()
                     WHERE id = :id
                       AND status = 0
                     RETURNING id"
                );
                $assignStmt->execute([':rider_id' => (int)$riderId, ':id' => $id]);

                if (!$assignStmt->fetch()) {
                    $assignRiderErrorMsg = 'Rider was not assigned — the booking may no longer be Looking for Driver. Refresh and try again.';
                } else {
                    header('Location: booking_show.php?id=' . $id . '&rider_assigned=1');
                    exit;
                }
            }
        } catch (PDOException $e) {
            $assignRiderErrorMsg = 'Rider assignment failed: ' . $e->getMessage();
        }
    }
}

if ($id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_status'])) {
    $newStatus = (int)$_POST['new_status'];

    if (!in_array($newStatus, BOOKING_STATUS_UPDATE_OPTIONS, true)) {
        $statusUpdateErrorMsg = 'Invalid target status.';
    } else {
        try {
            $pdo = get_pdo();
            $pdo->beginTransaction();

            // Only move it if it's still in one of the in-progress statuses —
            // re-checked here server-side so a stale page can't push an
            // already-terminal booking (e.g. already Cancelled) to another state.
            $updateStmt = $pdo->prepare(
                "UPDATE public.booking
                 SET status = :new_status,
                     updated_at = NOW()
                 WHERE id = :id
                   AND status IN (0, 1, 2)
                 RETURNING rider_id, ref_code"
            );
            $updateStmt->bindValue(':new_status', $newStatus, PDO::PARAM_INT);
            $updateStmt->bindValue(':id', $id, PDO::PARAM_INT);
            $updateStmt->execute();
            $updated = $updateStmt->fetch();

            if (!$updated) {
                $pdo->rollBack();
                $statusUpdateErrorMsg = 'Status was not updated — the booking may no longer be in an updatable state. Refresh and try again.';
            } else {
                // Completing a cash trip debits the driver's wallet for the
                // platform commission — mirrors what the rider/customer app
                // already does on a normal completion (see wallet_history
                // rows for e.g. bookings 521/522: type=debit, tran_type=
                // booking, description="Debit|Ref-<ref_code>"). This admin
                // "Complete Trip" action used to skip this step entirely, so
                // commission never got charged when a trip was completed here.
                if ($newStatus === 3 && $updated['rider_id']) {
                    $payStmt = $pdo->prepare(
                        "SELECT commission FROM public.booking_payment WHERE booking_id = :id AND type = 'cash' LIMIT 1"
                    );
                    $payStmt->execute([':id' => $id]);
                    $commission = $payStmt->fetchColumn();

                    if ($commission !== false && $commission !== null && (float)$commission > 0) {
                        $riderId = (int)$updated['rider_id'];

                        $walletStmt = $pdo->prepare(
                            "SELECT id FROM public.wallet
                             WHERE user_id = :rider_id AND user_type = 'rider' AND type = 'user-wallet'
                             LIMIT 1"
                        );
                        $walletStmt->execute([':rider_id' => $riderId]);
                        $walletId = $walletStmt->fetchColumn();

                        if ($walletId === false) {
                            $walletRefCode = 'WL-' . strtoupper(bin2hex(random_bytes(5)));
                            $createWalletStmt = $pdo->prepare(
                                "INSERT INTO public.wallet
                                    (ref_code, user_id, user_type, type, avail_balance, credit_amount, debit_amount, status, created_at, updated_at)
                                 VALUES
                                    (:ref_code, :user_id, 'rider', 'user-wallet', 0, 0, 0, 1, NOW(), NOW())
                                 RETURNING id"
                            );
                            $createWalletStmt->bindValue(':ref_code', $walletRefCode);
                            $createWalletStmt->bindValue(':user_id', $riderId, PDO::PARAM_INT);
                            $createWalletStmt->execute();
                            $walletId = (int)$createWalletStmt->fetchColumn();
                        }

                        $debitStmt = $pdo->prepare(
                            "UPDATE public.wallet
                             SET avail_balance = avail_balance - :amount,
                                 debit_amount = debit_amount + :amount,
                                 updated_at = NOW()
                             WHERE id = :wallet_id"
                        );
                        $debitStmt->execute([':amount' => (float)$commission, ':wallet_id' => $walletId]);

                        $historyStmt = $pdo->prepare(
                            "INSERT INTO public.wallet_history
                                (type, credit_amount, debit_amount, time_created, date_created,
                                 status, created_at, updated_at, wallet_id, description, booking_id, tran_type)
                             VALUES
                                ('debit', 0, :amount, :time_created, :date_created,
                                 0, NOW(), NOW(), :wallet_id, :description, :booking_id, 'booking')"
                        );
                        $historyStmt->execute([
                            ':amount'       => (float)$commission,
                            ':time_created' => date('h:i A'),
                            ':date_created' => date('Y-m-d'),
                            ':wallet_id'    => $walletId,
                            ':description'  => 'Debit|Ref-' . $updated['ref_code'],
                            ':booking_id'   => (string)$id,
                        ]);
                    }
                }

                $pdo->commit();
                header('Location: booking_show.php?id=' . $id . '&status_updated=1');
                exit;
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $statusUpdateErrorMsg = 'Status update failed: ' . $e->getMessage();
        }
    }
}

if ($id <= 0) {
    $errorMsg = 'Invalid booking id.';
} else {
    try {
        $pdo = get_pdo();

        $sql = "SELECT
                    b.*,
                    c.code          AS customer_code,
                    c.fname         AS customer_fname,
                    c.mname         AS customer_mname,
                    c.lname         AS customer_lname,
                    c.mobile        AS customer_mobile,
                    c.email         AS customer_email,
                    r.code          AS rider_code,
                    r.first_name    AS rider_fname,
                    r.middle_name   AS rider_mname,
                    r.last_name     AS rider_lname,
                    r.mobile_no     AS rider_mobile,
                    r.email_address AS rider_email,
                    r.drivers_license_no AS rider_license_no,
                    bp.id                    AS payment_id,
                    bp.type                  AS payment_record_type,
                    bp.minimum_fare          AS payment_minimum_fare,
                    bp.pesos_per_km          AS payment_pesos_per_km,
                    bp.booking_fee           AS payment_booking_fee,
                    bp.base_amount           AS payment_base_amount,
                    bp.distance_km_round     AS payment_distance_km_round,
                    bp.promo_discount        AS payment_promo_discount,
                    bp.tip                   AS payment_tip,
                    bp.commission            AS payment_commission,
                    bp.rider_net_amount      AS payment_rider_net_amount,
                    bp.total_amount_wo_promo AS payment_total_amount_wo_promo,
                    bp.total_amount          AS payment_total_amount,
                    bp.status                AS payment_status,
                    bp.all_payment_details   AS payment_all_payment_details,
                    bp.created_at            AS payment_created_at,
                    bp.updated_at            AS payment_updated_at
                FROM public.booking b
                LEFT JOIN public.customer c        ON c.id = b.customer_id
                LEFT JOIN public.riders   r        ON r.id = b.rider_id
                LEFT JOIN public.booking_payment bp ON bp.booking_id = b.id
                WHERE b.id = :id
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $booking = $stmt->fetch();

        if (!$booking) {
            $errorMsg = 'Booking not found.';
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

// Precomputed once here for reuse in the "Share Summary" modal below (the
// Customer/Rider cards build these same strings inline where they're used).
$customerName = $booking ? trim(($booking['customer_fname'] ?? '') . ' ' . ($booking['customer_mname'] ? $booking['customer_mname'] . ' ' : '') . ($booking['customer_lname'] ?? '')) : '';
$riderName    = $booking ? trim(($booking['rider_fname'] ?? '') . ' ' . ($booking['rider_mname'] ? $booking['rider_mname'] . ' ' : '') . ($booking['rider_lname'] ?? '')) : '';

function fmt_json($v): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    $decoded = json_decode((string)$v, true);
    $pretty  = json_last_error() === JSON_ERROR_NONE
        ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : (string)$v;
    return htmlspecialchars($pretty);
}

$activeNav = 'bookings';
require __DIR__ . '/includes/header.php';
?>

<div class="mb-3">
  <a href="bookings.php" class="btn btn-sm btn-outline-secondary">&laquo; Back to Bookings</a>
</div>

<?php if ($errorMsg !== ''): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php else: ?>

  <?php if (($_GET['created'] ?? '') === '1'): ?>
    <div class="alert alert-success">Booking created successfully.</div>
  <?php endif; ?>
  <?php if (($_GET['status_updated'] ?? '') === '1'): ?>
    <div class="alert alert-success">Booking status updated successfully.</div>
  <?php endif; ?>
  <?php if (($_GET['rider_assigned'] ?? '') === '1'): ?>
    <div class="alert alert-success">Rider assigned successfully.</div>
  <?php endif; ?>
  <?php if ($statusUpdateErrorMsg !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($statusUpdateErrorMsg) ?></div>
  <?php endif; ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Booking <?= val($booking['ref_code']) ?></h4>
    <div class="d-flex align-items-center gap-2">
      <?php if (in_array((int)$booking['status'], BOOKING_STATUS_UPDATABLE_FROM, true) && $booking['rider_id']): ?>
        <a href="booking_live.php?id=<?= (int)$id ?>" class="btn btn-sm btn-outline-primary">Track Live Location</a>
      <?php endif; ?>
      <span class="badge <?= booking_status_badge_class($booking['status']) ?> fs-6">Status: <?= htmlspecialchars(booking_status_label($booking['status'])) ?></span>
    </div>
  </div>

  <?php if (in_array((int)$booking['status'], BOOKING_STATUS_UPDATABLE_FROM, true)): ?>
    <div class="card mb-3">
      <div class="card-header bg-white fw-semibold">Update Status</div>
      <div class="card-body d-flex align-items-center gap-2">
        <span class="text-muted">Mark this booking as:</span>
        <?php foreach (BOOKING_STATUS_UPDATE_OPTIONS as $optStatus): ?>
          <form method="post" class="d-inline"
                onsubmit="return confirm('Mark this booking as <?= htmlspecialchars(booking_status_label($optStatus)) ?>?');">
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <input type="hidden" name="new_status" value="<?= $optStatus ?>">
            <button type="submit" class="btn btn-sm <?= $optStatus === 3 ? 'btn-success' : 'btn-danger' ?>">
              <?= htmlspecialchars(booking_status_label($optStatus)) ?>
            </button>
          </form>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ((int)$booking['status'] === 0 && !$booking['rider_id']): ?>
    <div class="card mb-3">
      <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Already Found a Rider?</span>
        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#assignRiderForm">
          Add Rider
        </button>
      </div>
      <div class="collapse<?= $assignRiderErrorMsg !== '' ? ' show' : '' ?>" id="assignRiderForm">
        <div class="card-body">
          <?php if ($assignRiderErrorMsg !== ''): ?>
            <div class="alert alert-danger py-2 mb-2"><?= htmlspecialchars($assignRiderErrorMsg) ?></div>
          <?php endif; ?>
          <form method="post" class="row gx-2 gy-2 align-items-end"
                onsubmit="return confirm('Assign this driver to the booking and mark it Accepted by Driver?');">
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <div class="col-auto">
              <label class="form-label mb-0">Driver Mobile Number</label>
              <input type="text" name="assign_rider_mobile" class="form-control form-control-sm"
                     placeholder="09xxxxxxxxx" value="<?= htmlspecialchars($_POST['assign_rider_mobile'] ?? '') ?>" required>
            </div>
            <div class="col-auto">
              <button type="submit" class="btn btn-sm btn-primary">Assign Rider</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="row g-3">

    <!-- Trip details -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
          <span>Trip Details</span>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#shareSummaryModal">
            Share Summary
          </button>
        </div>
        <div class="card-body">
          <table class="table table-sm mb-0">
            <tr><th style="width:40%">Ref Code</th><td><?= val($booking['ref_code']) ?></td></tr>
            <tr><th>Booking Type</th><td><?= val($booking['booking_type']) ?></td></tr>
            <tr><th>Status</th><td><span class="badge <?= booking_status_badge_class($booking['status']) ?>"><?= htmlspecialchars(booking_status_label($booking['status'])) ?></span></td></tr>
            <tr><th>Payment Method</th><td><?= val($booking['payment_type']) ?></td></tr>
            <tr><th>Pickup Location</th><td><?= val($booking['pickup_location']) ?></td></tr>
            <tr><th>Dropoff Location</th><td><?= val($booking['dropoff_location']) ?></td></tr>
            <tr><th>Note to Rider</th><td><?= val($booking['note_to_rider']) ?></td></tr>
            <tr><th>Distance (km)</th><td><?= val($booking['distance_km']) ?></td></tr>
            <tr><th>Cost : <td><?= fmt_money($booking['payment_total_amount']) ?></td></th></tr>
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
            <tr><th style="width:40%">Booking ID</th><td><?= val($booking['id']) ?></td></tr>
            <tr><th>Date Created (raw)</th><td><?= val($booking['date_created']) ?></td></tr>
            <tr><th>Time Created (raw)</th><td><?= val($booking['time_created']) ?></td></tr>
            <tr><th>Created At</th><td><?= fmt_dt($booking['created_at']) ?></td></tr>
            <tr><th>Updated At</th><td><?= fmt_dt($booking['updated_at']) ?></td></tr>
            <tr><th>Updated By (user id)</th><td><?= val($booking['updated_by']) ?></td></tr>
            <tr><th>Updated By (user)</th><td><?= val($booking['updated_by_user']) ?></td></tr>
            <tr><th>Deleted At</th><td><?= fmt_dt($booking['deleted_at']) ?></td></tr>
          </table>
        </div>
      </div>
    </div>

    <!-- Customer -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold">Customer</div>
        <div class="card-body">
          <?php if ($booking['customer_id']): ?>
            <table class="table table-sm mb-0">
              <tr><th style="width:40%">Code</th><td><?= val($booking['customer_code']) ?></td></tr>
              <tr><th>Name</th>
                <td><?= val(trim(($booking['customer_fname'] ?? '') . ' ' . ($booking['customer_mname'] ? $booking['customer_mname'] . ' ' : '') . ($booking['customer_lname'] ?? ''))) ?></td>
              </tr>
              <tr><th>Mobile</th><td><?= val($booking['customer_mobile']) ?></td></tr>
              <tr><th>Email</th><td><?= val($booking['customer_email']) ?></td></tr>
            </table>
          <?php else: ?>
            <span class="text-muted">No customer linked to this booking.</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Rider -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold">Rider</div>
        <div class="card-body">
          <?php if ($booking['rider_id']): ?>
            <table class="table table-sm mb-0">
              <tr>
                <th style="width:40%">Code</th>
                <td><a href="rider_show.php?id=<?= (int)$booking['rider_id'] ?>" class="btn btn-sm btn-outline-primary"><?= val($booking['rider_code']) ?></a></td>
              </tr>
              <tr><th>Name</th>
                <td><?= val(trim(($booking['rider_fname'] ?? '') . ' ' . ($booking['rider_mname'] ? $booking['rider_mname'] . ' ' : '') . ($booking['rider_lname'] ?? ''))) ?></td>
              </tr>
              <tr><th>Mobile</th><td><?= val($booking['rider_mobile']) ?></td></tr>
              <tr><th>Email</th><td><?= val($booking['rider_email']) ?></td></tr>
              <tr><th>License No.</th><td><?= val($booking['rider_license_no']) ?></td></tr>
            </table>
          <?php else: ?>
            <span class="text-muted">No rider linked to this booking.</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Payment -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold">Payment Details</div>
        <div class="card-body">
          <?php if ($booking['payment_id']): ?>
            <table class="table table-sm mb-0">
              <tr><th style="width:40%">Payment Type</th><td><?= val($booking['payment_record_type']) ?></td></tr>
              <tr><th>Status</th><td><?= val($booking['payment_status']) ?></td></tr>
              <tr><th>Minimum Fare</th><td><?= fmt_money($booking['payment_minimum_fare']) ?></td></tr>
              <tr><th>Pesos per KM</th><td><?= fmt_money($booking['payment_pesos_per_km']) ?></td></tr>
              <tr><th>Booking Fee</th><td><?= fmt_money($booking['payment_booking_fee']) ?></td></tr>
              <tr><th>Base Amount</th><td><?= fmt_money($booking['payment_base_amount']) ?></td></tr>
              <tr><th>Distance (km, rounded)</th><td><?= val($booking['payment_distance_km_round']) ?></td></tr>
              <tr><th>Promo Discount</th><td><?= fmt_money($booking['payment_promo_discount']) ?></td></tr>
              <tr><th>Tip</th><td><?= fmt_money($booking['payment_tip']) ?></td></tr>
              <tr><th>Commission</th><td><?= fmt_money($booking['payment_commission']) ?></td></tr>
              <tr><th>Rider Net Amount</th><td><?= fmt_money($booking['payment_rider_net_amount']) ?></td></tr>
              <tr><th>Total Amount (w/o Promo)</th><td><?= fmt_money($booking['payment_total_amount_wo_promo']) ?></td></tr>
              <tr><th>Total Amount</th><td><?= fmt_money($booking['payment_total_amount']) ?></td></tr>
              <tr><th>Payment Created At</th><td><?= fmt_dt($booking['payment_created_at']) ?></td></tr>
              <tr><th>Payment Updated At</th><td><?= fmt_dt($booking['payment_updated_at']) ?></td></tr>
              <tr>
                <th>All Payment Details</th>
                <td><pre class="mb-0 small"><?= fmt_json($booking['payment_all_payment_details']) ?></pre></td>
              </tr>
            </table>
          <?php else: ?>
            <span class="text-muted">No payment record linked to this booking.</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>

  <!-- Share Summary: screenshot-friendly booking card for sending to the customer/passenger -->
  <div class="modal fade" id="shareSummaryModal" tabindex="-1" aria-labelledby="shareSummaryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="shareSummaryModalLabel">Booking Summary</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="p-3 border rounded bg-white">
            <div class="text-center mb-3">
              <div class="fw-bold fs-4 text-primary">PinoyRide</div>
              <div class="text-muted small">Trip Confirmation</div>
            </div>
            <table class="table table-sm mb-0">
              <tr><th style="width:40%">Reference Code</th><td><?= val($booking['ref_code']) ?></td></tr>
              <tr><th>Status</th><td><span class="badge <?= booking_trip_status_badge_class($booking['status']) ?>"><?= htmlspecialchars(booking_trip_status_label($booking['status'])) ?></span></td></tr>
              <tr><th>Pickup</th><td><?= val($booking['pickup_location']) ?></td></tr>
              <tr><th>Dropoff</th><td><?= val($booking['dropoff_location']) ?></td></tr>
              <tr><th>Distance</th><td><?= $booking['distance_km'] !== null && $booking['distance_km'] !== '' ? val($booking['distance_km']) . ' km' : '—' ?></td></tr>
              <tr><th>Cost</th><td><?= fmt_money($booking['payment_total_amount']) ?></td></tr>
              <tr><th>Driver</th><td><?= val($riderName !== '' ? $riderName : null) ?></td></tr>
              <tr><th>Customer</th><td><?= val($customerName !== '' ? $customerName : null) ?></td></tr>
              <tr><th>Note to Rider</th><td><?= val($booking['note_to_rider']) ?></td></tr>
            </table>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
