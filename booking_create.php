<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/customer_ingest.php';
require_once __DIR__ . '/includes/geocoding.php';
require_once __DIR__ . '/includes/fare.php';

$tabTitle = 'Create Booking';

// Manual/phone-dispatch booking creation: admin already has a passenger and
// a specific driver on the line. Passenger is looked up by mobile and
// created if new (reusing the same logic as customer_create.php); driver
// must already exist (no "any available driver" broadcast here — this
// creates the booking already assigned to, and Accepted by, that driver).
//
// Flow: form -> preview (geocode + compute fare/ETA, editable) -> confirm.

const VEHICLE_TYPES = [
    1 => 'Motorcycle',
    2 => '4 Seater',
    3 => '6 Seater',
];

$mode       = 'form';
$errorMsg   = '';
$formErrors = [];
$preview    = null;

// Prefill for links in from elsewhere (e.g. nearby_drivers.php's "Book This
// Rider" button, and booking_check_fare.php's "Create Booking" button, both
// of which pass along a driver mobile/pickup address already looked up
// there). Only used on the initial GET load — once the form is POSTed,
// $_POST values take over via the same field names below.
$prefillDriverMobile = trim($_GET['driver_mobile'] ?? '');
$prefillPickupAddress = trim($_GET['pickup_address'] ?? '');
$prefillDropoffAddress = trim($_GET['dropoff_address'] ?? '');
$prefillFare = trim($_GET['fare'] ?? '');

function split_name(string $name): array
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    if ($name === '') return ['', ''];
    $parts = explode(' ', $name, 2);
    return [$parts[0], $parts[1] ?? ''];
}

function find_customer_by_mobile(PDO $pdo, string $mobile): ?array
{
    $stmt = $pdo->prepare('SELECT id, fname, mname, lname, mobile FROM public.customer WHERE mobile = :mobile AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':mobile' => $mobile]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function find_rider_by_mobile(PDO $pdo, string $mobile): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id, code, first_name, last_name, mobile_no, current_lat, current_long, current_location, is_online, status
         FROM public.riders WHERE mobile_no = :mobile AND deleted_at IS NULL LIMIT 1"
    );
    $stmt->execute([':mobile' => $mobile]);
    $row = $stmt->fetch();
    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'confirm') {
    // -----------------------------------------------------------
    // Step 3: re-validate the previewed data and insert
    // -----------------------------------------------------------
    try {
        $pdo = get_pdo();

        $driverId = (int)($_POST['driver_id'] ?? 0);
        $rider = null;
        if ($driverId > 0) {
            $stmt = $pdo->prepare('SELECT id, code FROM public.riders WHERE id = :id AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([':id' => $driverId]);
            $rider = $stmt->fetch();
        }

        $pickupAddress   = trim($_POST['pickup_address'] ?? '');
        $pickupLat       = (float)($_POST['pickup_lat'] ?? 0);
        $pickupLng       = (float)($_POST['pickup_lng'] ?? 0);
        $dropoffAddress  = trim($_POST['dropoff_address'] ?? '');
        $dropoffLat      = (float)($_POST['dropoff_lat'] ?? 0);
        $dropoffLng      = (float)($_POST['dropoff_lng'] ?? 0);
        $distanceKm      = (float)($_POST['distance_km'] ?? 0);
        $bookingType     = (string)($_POST['booking_type'] ?? '1');
        $noteToRider     = trim($_POST['note_to_rider'] ?? '');
        $manualFareRaw   = trim($_POST['fare'] ?? '');
        $manualFare      = ($manualFareRaw !== '' && is_numeric($manualFareRaw)) ? (float)$manualFareRaw : null;

        $passengerMode = (string)($_POST['passenger_mode'] ?? '');
        $passengerId   = (int)($_POST['passenger_id'] ?? 0);
        $passengerMobile = (string)($_POST['passenger_mobile_norm'] ?? '');
        $passengerFirstName = trim($_POST['passenger_first_name'] ?? '');
        $passengerLastName  = trim($_POST['passenger_last_name'] ?? '');

        // Re-checked here (not just at preview) in case the number was
        // registered as a driver in the gap between preview and confirm.
        $passengerMobileIsRider = false;
        if ($passengerMode === 'new' && $passengerMobile !== '') {
            $riderConflictStmt = $pdo->prepare('SELECT 1 FROM public.riders WHERE mobile_no = :mobile AND deleted_at IS NULL LIMIT 1');
            $riderConflictStmt->execute([':mobile' => $passengerMobile]);
            $passengerMobileIsRider = $riderConflictStmt->fetchColumn() !== false;
        }

        if (!$rider) {
            $errorMsg = 'Driver no longer exists — please start over.';
        } elseif ($pickupLat === 0.0 || $pickupLng === 0.0 || $dropoffLat === 0.0 || $dropoffLng === 0.0) {
            $errorMsg = 'Missing pickup/dropoff coordinates — please start over.';
        } elseif (!array_key_exists((int)$bookingType, VEHICLE_TYPES)) {
            $errorMsg = 'Invalid vehicle type — please start over.';
        } elseif ($passengerMode === 'new' && ($passengerFirstName === '' || $passengerLastName === '' || $passengerMobile === '')) {
            $errorMsg = 'Missing passenger details — please start over.';
        } elseif ($passengerMode === 'existing' && $passengerId <= 0) {
            $errorMsg = 'Missing passenger details — please start over.';
        } elseif ($passengerMobileIsRider) {
            $errorMsg = 'This mobile number is already registered to a driver — please start over.';
        } else {
            try {
                $pdo->beginTransaction();

                if ($passengerMode === 'new') {
                    $mapped = [
                        'first_name'        => $passengerFirstName,
                        'last_name'         => $passengerLastName,
                        'mobile_no'         => $passengerMobile,
                        'gender'            => '',
                        'permanent_address' => '',
                    ];
                    $createdAt = date('Y-m-d H:i:s');
                    $seq = next_customer_code_seq($pdo);
                    // false: this function's own transaction would nest inside
                    // the one already open below — the outer catch handles
                    // rollback for this insert too.
                    $inserted = insert_customer_record($pdo, $mapped, $createdAt, $seq, false);
                    $passengerId = $inserted['customer_id'];
                }

                $fare = compute_fare($distanceKm, $manualFare);

                $now = date('Y-m-d H:i:s');
                $bookingStmt = $pdo->prepare(
                    "INSERT INTO public.booking
                        (ref_code, pickup_location, dropoff_location, pickup_lat, pickup_long, dropoff_lat, dropoff_long,
                         customer_id, rider_id, payment_type, distance_km, booking_type, note_to_rider, status,
                         date_created, time_created, updated_by, created_at, updated_at)
                     VALUES
                        ('', :pickup_location, :dropoff_location, :pickup_lat, :pickup_long, :dropoff_lat, :dropoff_long,
                         :customer_id, :rider_id, 'cash', :distance_km, :booking_type, :note_to_rider, 1,
                         :date_created, :time_created, :updated_by, :created_at, :updated_at)
                     RETURNING id"
                );
                $bookingStmt->execute([
                    ':pickup_location'  => $pickupAddress,
                    ':dropoff_location' => $dropoffAddress,
                    ':pickup_lat'       => (string)$pickupLat,
                    ':pickup_long'      => (string)$pickupLng,
                    ':dropoff_lat'      => (string)$dropoffLat,
                    ':dropoff_long'     => (string)$dropoffLng,
                    ':customer_id'      => $passengerId,
                    ':rider_id'         => $rider['id'],
                    ':distance_km'      => (string)$fare['distance_km_round'],
                    ':booking_type'     => $bookingType,
                    ':note_to_rider'    => $noteToRider !== '' ? $noteToRider : null,
                    ':date_created'     => date('Y-m-d'),
                    ':time_created'     => date('h:i A'),
                    ':updated_by'       => 1,
                    ':created_at'       => $now,
                    ':updated_at'       => $now,
                ]);
                $bookingId = (int)$bookingStmt->fetchColumn();

                $refCode = sprintf('%06d', $bookingId);
                $pdo->prepare('UPDATE public.booking SET ref_code = :ref_code WHERE id = :id')
                    ->execute([':ref_code' => $refCode, ':id' => $bookingId]);

                $allPaymentDetails = [
                    'fare_source'          => $fare['fare_source'],
                    'km_basis'             => $fare['km_basis'],
                    'pesos_per_km2'        => $fare['pesos_per_km2'],
                    'exceeding_kms'        => $fare['exceeding_kms'],
                    'exceeding_kms_total'  => $fare['exceeding_kms_total'],
                    'minimum_fare'         => $fare['minimum_fare'],
                    'pesos_per_km'         => $fare['pesos_per_km'],
                    'base_amount'          => $fare['base_amount'],
                    'booking_fee'          => $fare['booking_fee'],
                    'promo_discount'       => $fare['promo_discount'],
                    'distance_km_round'    => $fare['distance_km_round'],
                    'rider_net_amount'     => $fare['rider_net_amount'],
                    'total_amount_wo_promo' => $fare['total_amount_wo_promo'],
                    'total_amount'         => $fare['total_amount'],
                    'commission'           => $fare['commission'],
                    'created_via'          => 'admin_booking_create',
                ];

                $paymentStmt = $pdo->prepare(
                    "INSERT INTO public.booking_payment
                        (booking_id, type, minimum_fare, pesos_per_km, booking_fee, base_amount, distance_km_round,
                         promo_discount, tip, commission, rider_net_amount, total_amount_wo_promo, total_amount,
                         status, all_payment_details, created_at, updated_at)
                     VALUES
                        (:booking_id, 'cash', :minimum_fare, :pesos_per_km, :booking_fee, :base_amount, :distance_km_round,
                         :promo_discount, :tip, :commission, :rider_net_amount, :total_amount_wo_promo, :total_amount,
                         0, :all_payment_details, :created_at, :updated_at)"
                );
                $paymentStmt->execute([
                    ':booking_id'            => $bookingId,
                    ':minimum_fare'          => $fare['minimum_fare'],
                    ':pesos_per_km'          => $fare['pesos_per_km'],
                    ':booking_fee'           => $fare['booking_fee'],
                    ':base_amount'           => $fare['base_amount'],
                    ':distance_km_round'     => $fare['distance_km_round'],
                    ':promo_discount'        => $fare['promo_discount'],
                    ':tip'                   => $fare['tip'],
                    ':commission'            => $fare['commission'],
                    ':rider_net_amount'      => $fare['rider_net_amount'],
                    ':total_amount_wo_promo' => $fare['total_amount_wo_promo'],
                    ':total_amount'          => $fare['total_amount'],
                    ':all_payment_details'   => json_encode($allPaymentDetails),
                    ':created_at'            => $now,
                    ':updated_at'            => $now,
                ]);

                $pdo->commit();

                header('Location: booking_show.php?id=' . $bookingId . '&created=1');
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errorMsg = 'Booking creation failed: ' . $e->getMessage();
            }
        }
    } catch (Throwable $e) {
        $errorMsg = 'Booking creation failed: ' . $e->getMessage();
    }
    $mode = 'form';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // -----------------------------------------------------------
    // Step 2: validate, look up passenger/driver, geocode, compute fare/ETA
    // -----------------------------------------------------------
    $passengerMobileRaw = trim($_POST['passenger_mobile'] ?? '');
    $passengerName       = trim($_POST['passenger_name'] ?? '');
    $driverMobileRaw     = trim($_POST['driver_mobile'] ?? '');
    $pickupAddress       = trim($_POST['pickup_address'] ?? '');
    $dropoffAddress      = trim($_POST['dropoff_address'] ?? '');
    $fareRaw             = trim($_POST['fare'] ?? '');
    $bookingType         = (string)($_POST['booking_type'] ?? '1');
    $noteToRider         = trim($_POST['note_to_rider'] ?? '');

    [$passengerMobile, $passengerMobileRecognized] = normalize_mobile_to_63($passengerMobileRaw);
    [$driverMobile, $driverMobileRecognized]       = normalize_mobile_to_63($driverMobileRaw);

    if ($passengerMobile === '') $formErrors[] = 'Passenger mobile number is required.';
    elseif (!$passengerMobileRecognized) $formErrors[] = 'Passenger mobile number format not recognized.';
    if ($driverMobile === '') $formErrors[] = 'Driver mobile number is required.';
    elseif (!$driverMobileRecognized) $formErrors[] = 'Driver mobile number format not recognized.';
    if ($pickupAddress === '') $formErrors[] = 'Pickup address is required.';
    if ($dropoffAddress === '') $formErrors[] = 'Dropoff address is required.';
    if (!array_key_exists((int)$bookingType, VEHICLE_TYPES)) $formErrors[] = 'Invalid vehicle type.';
    $manualFare = null;
    if ($fareRaw !== '') {
        if (!is_numeric($fareRaw) || (float)$fareRaw <= 0) {
            $formErrors[] = 'Fare must be a positive number, or left blank to auto-compute.';
        } else {
            $manualFare = (float)$fareRaw;
        }
    }
    if (!google_maps_configured()) {
        $formErrors[] = 'Google Maps isn\'t configured yet — add GOOGLE_MAPS_API_KEY to .env before creating bookings.';
    }

    if (empty($formErrors)) {
        try {
            $pdo = get_pdo();

            $passengerRow = find_customer_by_mobile($pdo, $passengerMobile);
            $passengerMode = 'existing';
            $passengerFirstName = '';
            $passengerLastName = '';
            if ($passengerRow) {
                $passengerFirstName = (string)$passengerRow['fname'];
                $passengerLastName  = (string)$passengerRow['lname'];
            } else {
                $passengerMode = 'new';
                [$passengerFirstName, $passengerLastName] = split_name($passengerName);
                if ($passengerFirstName === '' || $passengerLastName === '') {
                    $formErrors[] = 'This mobile number has no existing passenger — enter the passenger\'s full name (first and last) to register them.';
                }
                // A new passenger can't be registered on a mobile number that's
                // already a driver's — customer_edit.php/rider_edit.php both
                // enforce mobile numbers being unique across the two roles, so
                // letting one slip in here would break that invariant later.
                $riderConflictStmt = $pdo->prepare('SELECT 1 FROM public.riders WHERE mobile_no = :mobile AND deleted_at IS NULL LIMIT 1');
                $riderConflictStmt->execute([':mobile' => $passengerMobile]);
                if ($riderConflictStmt->fetchColumn() !== false) {
                    $formErrors[] = 'This mobile number is already registered to a driver — a new passenger can\'t be created with it.';
                }
            }

            $rider = find_rider_by_mobile($pdo, $driverMobile);
            if (!$rider) {
                $formErrors[] = 'No driver found with that mobile number — a booking can only be created for an existing driver.';
            }

            if (empty($formErrors)) {
                $pickupGeo  = geocode_address($pickupAddress);
                $dropoffGeo = geocode_address($dropoffAddress);

                if (!$pickupGeo) $formErrors[] = 'Could not locate the pickup address — try a more specific address.';
                if (!$dropoffGeo) $formErrors[] = 'Could not locate the dropoff address — try a more specific address.';

                if (empty($formErrors)) {
                    $pickupLat  = (float)$pickupGeo['lat'];
                    $pickupLng  = (float)$pickupGeo['lng'];
                    $dropoffLat = (float)$dropoffGeo['lat'];
                    $dropoffLng = (float)$dropoffGeo['lng'];

                    $route = distance_matrix($pickupLat, $pickupLng, $dropoffLat, $dropoffLng);
                    $distanceApprox = false;
                    if ($route) {
                        $distanceKm = $route['distance_km'];
                    } else {
                        $distanceKm = haversine_km($pickupLat, $pickupLng, $dropoffLat, $dropoffLng);
                        $distanceApprox = true;
                    }

                    $etaText = null;
                    $etaApprox = false;
                    $etaUnavailableReason = null;
                    $riderLat = $rider['current_lat'] !== null && $rider['current_lat'] !== '' ? (float)$rider['current_lat'] : null;
                    $riderLng = $rider['current_long'] !== null && $rider['current_long'] !== '' ? (float)$rider['current_long'] : null;
                    if ($riderLat === null || $riderLng === null) {
                        $etaUnavailableReason = 'Driver has no location on file.';
                    } else {
                        $etaRoute = distance_matrix($riderLat, $riderLng, $pickupLat, $pickupLng);
                        if ($etaRoute) {
                            $etaText = $etaRoute['duration_text'];
                        } else {
                            $etaKm = haversine_km($riderLat, $riderLng, $pickupLat, $pickupLng);
                            $assumedSpeedKmh = 25.0;
                            $etaMinutes = ($etaKm / $assumedSpeedKmh) * 60;
                            $etaText = round($etaMinutes) . ' min';
                            $etaApprox = true;
                        }
                    }

                    $fare = compute_fare($distanceKm, $manualFare);

                    $preview = [
                        'passenger_mode'       => $passengerMode,
                        'passenger_id'         => $passengerRow['id'] ?? null,
                        'passenger_first_name' => $passengerFirstName,
                        'passenger_last_name'  => $passengerLastName,
                        'passenger_mobile'     => $passengerMobile,
                        'driver_id'            => $rider['id'],
                        'driver_code'          => $rider['code'],
                        'driver_name'          => trim($rider['first_name'] . ' ' . $rider['last_name']),
                        'driver_mobile'        => $rider['mobile_no'],
                        'driver_online'        => (int)$rider['is_online'] === 1,
                        'driver_location'      => $rider['current_location'],
                        'driver_lat'           => $riderLat,
                        'driver_lng'           => $riderLng,
                        'pickup_address'       => $pickupGeo['formatted_address'],
                        'pickup_lat'           => $pickupLat,
                        'pickup_lng'           => $pickupLng,
                        'dropoff_address'      => $dropoffGeo['formatted_address'],
                        'dropoff_lat'          => $dropoffLat,
                        'dropoff_lng'          => $dropoffLng,
                        'distance_km'          => round($distanceKm, 2),
                        'distance_approx'      => $distanceApprox,
                        'eta_text'             => $etaText,
                        'eta_approx'           => $etaApprox,
                        'eta_unavailable_reason' => $etaUnavailableReason,
                        'booking_type'         => $bookingType,
                        'note_to_rider'        => $noteToRider,
                        'fare'                 => $fare,
                        'manual_fare_input'    => $fareRaw,
                    ];
                    $mode = 'preview';
                }
            }
        } catch (PDOException $e) {
            $errorMsg = 'Lookup failed: ' . $e->getMessage();
        }
    }
}

function val($v): string
{
    return ($v === null || $v === '') ? '—' : htmlspecialchars((string)$v);
}

function fmt_money($v): string
{
    return '₱' . number_format((float)$v, 2);
}

$activeNav = 'bookings';
require __DIR__ . '/includes/header.php';
?>

<div class="mb-3">
  <a href="bookings.php" class="btn btn-sm btn-outline-secondary">&laquo; Back to Bookings</a>
</div>

<h4 class="mb-3">New Booking</h4>

<?php if ($errorMsg !== ''): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>

<?php if ($mode === 'form'): ?>

  <?php if (!empty($formErrors)): ?>
    <div class="alert alert-danger">
      <?php foreach ($formErrors as $err): ?>
        <div><?= htmlspecialchars($err) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="row justify-content-center">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-body">
          <form method="post" class="row g-3">

            <div class="col-12"><h6 class="text-muted mb-0">Passenger</h6></div>
            <div class="col-md-6">
              <label class="form-label">Mobile Number *</label>
              <input type="text" name="passenger_mobile" class="form-control" placeholder="09xxxxxxxxx" value="<?= htmlspecialchars($_POST['passenger_mobile'] ?? '') ?>" required>
              <div class="form-text">If this number isn't registered yet, a new passenger record is created automatically.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Name</label>
              <input type="text" name="passenger_name" class="form-control" placeholder="First Last" value="<?= htmlspecialchars($_POST['passenger_name'] ?? '') ?>">
              <div class="form-text">Only used if the mobile number above is new.</div>
            </div>

            <div class="col-12 mt-4"><h6 class="text-muted mb-0">Driver</h6></div>
            <div class="col-md-6">
              <label class="form-label">Mobile Number *</label>
              <input type="text" name="driver_mobile" class="form-control" placeholder="09xxxxxxxxx" value="<?= htmlspecialchars($_POST['driver_mobile'] ?? $prefillDriverMobile) ?>" required>
              <div class="form-text">Must already be a registered driver.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Vehicle Type</label>
              <select name="booking_type" class="form-select">
                <?php foreach (VEHICLE_TYPES as $val => $label): ?>
                  <option value="<?= $val ?>" <?= ($bookingType ?? '1') == (string)$val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 mt-4"><h6 class="text-muted mb-0">Booking Details</h6></div>
            <div class="col-12">
              <label class="form-label">Pickup Address *</label>
              <input type="text" name="pickup_address" class="form-control" value="<?= htmlspecialchars($_POST['pickup_address'] ?? $prefillPickupAddress) ?>" required>
            </div>
            <div class="col-12">
              <label class="form-label">Dropoff Address *</label>
              <input type="text" name="dropoff_address" class="form-control" value="<?= htmlspecialchars($_POST['dropoff_address'] ?? $prefillDropoffAddress) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Fare</label>
              <div class="input-group">
                <span class="input-group-text">₱</span>
                <input type="number" step="0.01" min="0" name="fare" class="form-control" placeholder="Leave blank to auto-compute" value="<?= htmlspecialchars($_POST['fare'] ?? $prefillFare) ?>">
              </div>
              <div class="form-text">Leave blank to auto-compute from route distance (shown next screen either way).</div>
            </div>
            <div class="col-12">
              <label class="form-label">Note to Rider</label>
              <textarea name="note_to_rider" class="form-control" rows="2"><?= htmlspecialchars($_POST['note_to_rider'] ?? '') ?></textarea>
            </div>

            <div class="col-12 d-flex gap-2 mt-4">
              <button type="submit" class="btn btn-primary">Look Up &amp; Preview</button>
              <a href="bookings.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

<?php elseif ($mode === 'preview'): ?>

  <div class="row justify-content-center">
    <div class="col-lg-8">

      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <div class="card h-100">
            <div class="card-header bg-white fw-semibold">Passenger</div>
            <div class="card-body">
              <?php if ($preview['passenger_mode'] === 'existing'): ?>
                <span class="badge bg-secondary mb-2">Existing</span>
              <?php else: ?>
                <span class="badge bg-success mb-2">New — will be registered</span>
              <?php endif; ?>
              <table class="table table-sm mb-0">
                <tr><th style="width:40%">Name</th><td><?= val(trim($preview['passenger_first_name'] . ' ' . $preview['passenger_last_name'])) ?></td></tr>
                <tr><th>Mobile</th><td><?= val($preview['passenger_mobile']) ?></td></tr>
              </table>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="card h-100">
            <div class="card-header bg-white fw-semibold">Driver</div>
            <div class="card-body">
              <table class="table table-sm mb-0">
                <tr><th style="width:40%">Code</th><td><?= val($preview['driver_code']) ?></td></tr>
                <tr><th>Name</th><td><?= val($preview['driver_name']) ?></td></tr>
                <tr><th>Mobile</th><td><?= val($preview['driver_mobile']) ?></td></tr>
                <tr><th>Status</th><td><?= $preview['driver_online'] ? '<span class="badge bg-success">Online</span>' : '<span class="badge bg-secondary">Offline</span>' ?></td></tr>
                <tr><th>Location</th><td><?= val($preview['driver_location']) ?></td></tr>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header bg-white fw-semibold">Trip</div>
        <div class="card-body">
          <table class="table table-sm mb-0">
            <tr><th style="width:25%">Vehicle Type</th><td><?= htmlspecialchars(VEHICLE_TYPES[(int)$preview['booking_type']]) ?></td></tr>
            <tr><th>Pickup</th><td><?= val($preview['pickup_address']) ?></td></tr>
            <tr><th>Dropoff</th><td><?= val($preview['dropoff_address']) ?></td></tr>
            <tr>
              <th>Distance</th>
              <td>
                <?= number_format($preview['distance_km'], 2) ?> km
                <?php if ($preview['distance_approx']): ?>
                  <span class="badge bg-warning text-dark" title="Distance Matrix API unavailable — straight-line estimate used instead">Approx.</span>
                <?php endif; ?>
              </td>
            </tr>
            <tr>
              <th>Estimated Time of Arrival</th>
              <td>
                <?php if ($preview['eta_unavailable_reason']): ?>
                  <span class="text-muted"><?= htmlspecialchars($preview['eta_unavailable_reason']) ?></span>
                <?php else: ?>
                  <?= htmlspecialchars($preview['eta_text']) ?>
                  <?php if ($preview['eta_approx']): ?>
                    <span class="badge bg-warning text-dark" title="Distance Matrix API unavailable — estimated from straight-line distance at 25km/h">Approx.</span>
                  <?php endif; ?>
                <?php endif; ?>
                <div class="form-text mb-0">Driver's current location &rarr; pickup address.</div>
              </td>
            </tr>
            <?php if ($preview['note_to_rider'] !== ''): ?>
              <tr><th>Note to Rider</th><td><?= val($preview['note_to_rider']) ?></td></tr>
            <?php endif; ?>
          </table>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header bg-white fw-semibold">
          Fare &amp; Commission
          <span class="badge <?= $preview['fare']['fare_source'] === 'manual' ? 'bg-primary' : 'bg-secondary' ?>">
            <?= $preview['fare']['fare_source'] === 'manual' ? 'Manual fare' : 'Auto-computed' ?>
          </span>
        </div>
        <div class="card-body">
          <table class="table table-sm mb-0">
            <tr><th style="width:40%">Base Amount</th><td><?= fmt_money($preview['fare']['base_amount']) ?></td></tr>
            <tr><th>Minimum Fare</th><td><?= fmt_money($preview['fare']['minimum_fare']) ?></td></tr>
            <tr><th>Exceeding Distance Charge</th><td><?= fmt_money($preview['fare']['exceeding_kms_total']) ?></td></tr>
            <tr class="table-light"><th>Total Fare</th><td class="fw-semibold"><?= fmt_money($preview['fare']['total_amount']) ?></td></tr>
            <tr><th>Commission (15%)</th><td class="text-success"><?= fmt_money($preview['fare']['commission']) ?></td></tr>
            <tr><th>Rider Net Amount</th><td><?= fmt_money($preview['fare']['rider_net_amount']) ?></td></tr>
          </table>
        </div>
      </div>

      <form method="post">
        <input type="hidden" name="step" value="confirm">
        <input type="hidden" name="passenger_mode" value="<?= htmlspecialchars($preview['passenger_mode']) ?>">
        <input type="hidden" name="passenger_id" value="<?= (int)($preview['passenger_id'] ?? 0) ?>">
        <input type="hidden" name="passenger_first_name" value="<?= htmlspecialchars($preview['passenger_first_name']) ?>">
        <input type="hidden" name="passenger_last_name" value="<?= htmlspecialchars($preview['passenger_last_name']) ?>">
        <input type="hidden" name="passenger_mobile_norm" value="<?= htmlspecialchars($preview['passenger_mobile']) ?>">
        <input type="hidden" name="driver_id" value="<?= (int)$preview['driver_id'] ?>">
        <input type="hidden" name="pickup_address" value="<?= htmlspecialchars($preview['pickup_address']) ?>">
        <input type="hidden" name="pickup_lat" value="<?= htmlspecialchars((string)$preview['pickup_lat']) ?>">
        <input type="hidden" name="pickup_lng" value="<?= htmlspecialchars((string)$preview['pickup_lng']) ?>">
        <input type="hidden" name="dropoff_address" value="<?= htmlspecialchars($preview['dropoff_address']) ?>">
        <input type="hidden" name="dropoff_lat" value="<?= htmlspecialchars((string)$preview['dropoff_lat']) ?>">
        <input type="hidden" name="dropoff_lng" value="<?= htmlspecialchars((string)$preview['dropoff_lng']) ?>">
        <input type="hidden" name="distance_km" value="<?= htmlspecialchars((string)$preview['distance_km']) ?>">
        <input type="hidden" name="booking_type" value="<?= htmlspecialchars($preview['booking_type']) ?>">
        <input type="hidden" name="note_to_rider" value="<?= htmlspecialchars($preview['note_to_rider']) ?>">
        <input type="hidden" name="fare" value="<?= htmlspecialchars($preview['manual_fare_input']) ?>">
        <div class="d-flex gap-2 mb-4">
          <button type="submit" class="btn btn-success">Confirm &amp; Create Booking</button>
          <a href="booking_create.php" class="btn btn-outline-secondary">Start Over</a>
        </div>
      </form>

    </div>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
