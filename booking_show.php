<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$id = (int)($_GET['id'] ?? 0);
$booking = null;
$errorMsg = '';

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

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Booking <?= val($booking['ref_code']) ?></h4>
    <span class="badge bg-info text-dark fs-6">Status: <?= val($booking['status']) ?></span>
  </div>

  <div class="row g-3">

    <!-- Trip details -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold">Trip Details</div>
        <div class="card-body">
          <table class="table table-sm mb-0">
            <tr><th style="width:40%">Ref Code</th><td><?= val($booking['ref_code']) ?></td></tr>
            <tr><th>Booking Type</th><td><?= val($booking['booking_type']) ?></td></tr>
            <tr><th>Status</th><td><?= val($booking['status']) ?></td></tr>
            <tr><th>Payment Method</th><td><?= val($booking['payment_type']) ?></td></tr>
            <tr><th>Distance (km)</th><td><?= val($booking['distance_km']) ?></td></tr>
            <tr><th>Pickup Location</th><td><?= val($booking['pickup_location']) ?></td></tr>
            <tr><th>Pickup Coordinates</th>
              <td>
                <?php if ($booking['pickup_lat'] && $booking['pickup_long']): ?>
                  <a href="https://www.google.com/maps?q=<?= urlencode($booking['pickup_lat'] . ',' . $booking['pickup_long']) ?>" target="_blank" rel="noopener">
                    <?= val($booking['pickup_lat']) ?>, <?= val($booking['pickup_long']) ?>
                  </a>
                <?php else: ?>—<?php endif; ?>
              </td>
            </tr>
            <tr><th>Dropoff Location</th><td><?= val($booking['dropoff_location']) ?></td></tr>
            <tr><th>Dropoff Coordinates</th>
              <td>
                <?php if ($booking['dropoff_lat'] && $booking['dropoff_long']): ?>
                  <a href="https://www.google.com/maps?q=<?= urlencode($booking['dropoff_lat'] . ',' . $booking['dropoff_long']) ?>" target="_blank" rel="noopener">
                    <?= val($booking['dropoff_lat']) ?>, <?= val($booking['dropoff_long']) ?>
                  </a>
                <?php else: ?>—<?php endif; ?>
              </td>
            </tr>
            <tr><th>Note to Rider</th><td><?= val($booking['note_to_rider']) ?></td></tr>
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
              <tr><th style="width:40%">Code</th><td><?= val($booking['rider_code']) ?></td></tr>
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

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
