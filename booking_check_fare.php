<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/customer_ingest.php';
require_once __DIR__ . '/includes/geocoding.php';
require_once __DIR__ . '/includes/fare.php';

$tabTitle = 'Check Fare';

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
// Rider" button, which passes the driver's mobile and the searched pickup
// address). Only used on the initial GET load — once the form is POSTed,
// $_POST values take over via the same field names below.
$prefillDriverMobile = trim($_GET['driver_mobile'] ?? '');
$prefillPickupAddress = trim($_GET['pickup_address'] ?? '');

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



if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $post = $_POST;
    $formErrors = [];
    if(isset($post['fare_lookup'])) {
        $pickupAddress = $post['pickup_address'];
        $dropOffAddress = $post['dropoff_address'];
        $manualFare = $post['manualFare'] ?? null;
        
        if(!empty($pickupAddress) && !empty($dropOffAddress)) {

            $pickupGeo  = geocode_address($pickupAddress);
            $dropoffGeo = geocode_address($dropOffAddress);

           if(!$pickupGeo) $formErrors[] = 'Could not locate the pickup address — try a more specific address.';
           if(!$dropoffGeo) $formErrors[] = 'Could not locate the dropoff address — try a more specific address.';


           if(empty($formErrors)) 
            {
                $pickupLat  = (float)$pickupGeo['lat'];
                $pickupLng  = (float)$pickupGeo['lng'];
                $dropoffLat = (float)$dropoffGeo['lat'];
                $dropoffLng = (float)$dropoffGeo['lng'];

                $etaText = null;
                $etaApprox = false;
                $etaUnavailableReason = null;

                $route = distance_matrix($pickupLat, $pickupLng, $dropoffLat, $dropoffLng);
                $distanceApprox = false;
                if ($route) {
                    $distanceKm = $route['distance_km'];
                    $etaText = $etaRoute['duration_text'];
                } else {
                    $distanceKm = haversine_km($pickupLat, $pickupLng, $dropoffLat, $dropoffLng);
                    $distanceApprox = true;
                    $assumedSpeedKmh = 30.0;
                    $etaMinutes = ($distanceKm / $assumedSpeedKmh) * 60;
                    $etaText = round($etaMinutes) . ' min';
                    $etaApprox = true;
                }

                $fare = compute_fare($distanceKm, $manualFare);

                if(!empty($post['driver_number'])) {
                    $pdo = get_pdo();
                    $driverNumber = normalize_mobile_to_63($post['driver_number']);

                    if($driverNumber[1]) {
                        $rider = find_rider_by_mobile($pdo, $driverNumber[0]);

                        // Only build driver info (and offer "Create Booking")
                        // when a driver actually exists for that number —
                        // previously this ran even on a miss, producing a
                        // blank-name/blank-mobile "driver" card.
                        if ($rider) {
                            $riderLat = $rider['current_lat'] !== null && $rider['current_lat'] !== '' ? (float)$rider['current_lat'] : null;
                            $riderLng = $rider['current_long'] !== null && $rider['current_long'] !== '' ? (float)$rider['current_long'] : null;

                            if ($riderLat === null || $riderLng === null) {
                                $riderEtaUnavailableReason = 'Driver has no location on file.';
                            } else {
                                $riderRoute = distance_matrix($riderLat, $riderLng, $pickupLat, $pickupLng);
                                if ($riderRoute) {
                                    $riderEtaText = $riderRoute['duration_text'];
                                    $riderDistanceKm = $riderRoute['distance_km'];
                                } else {
                                    $riderDistanceKm = haversine_km($riderLat, $riderLng, $pickupLat, $pickupLng);
                                    $riderAssumedSpeedKmh = 25.0;
                                    $riderEtaMinutes = ($riderDistanceKm / $riderAssumedSpeedKmh) * 60;
                                    $riderEtaText = round($riderEtaMinutes) . ' min';
                                    $riderEtaApprox = true;
                                }
                            }

                            $driverData = [
                                'name' => trim($rider['first_name'] . ' ' . $rider['last_name']),
                                'mobileNumber' => $rider['mobile_no'],
                                'riderEtaUnavailableReason' => $riderEtaUnavailableReason ?? '',
                                'riderEtaMinutes' => $riderEtaMinutes ?? null,
                                'distance_km' => round($riderDistanceKm ?? 0.0, 2),
                                'riderEtaText' => $riderEtaText ?? null,
                                'riderEtaApprox' => $riderEtaApprox ?? null
                            ];
                        } else {
                            $driverNotFound = true;
                        }
                    }

                }

                $preview = [
                    'pickup_address' => $pickupAddress,
                    'dropoff_address' => $dropOffAddress,
                    'pickupLat' => $pickupLat,
                    'pickupLng' => $pickupLng,
                    'dropoffLat' => $dropoffLat,
                    'dropoffLng' => $dropoffLng,
                    'route' => $route,
                    'distance_approx' => $distanceApprox,
                    'eta' => [
                        'etaMinutes' => $etaMinutes,
                        'etaText'    => $etaText,
                        'etaApprox'  => $etaApprox
                    ],
                    'eta_text' => $etaText,
                    'eta_approx' => $etaApprox,

                    'distance_km' => round($distanceKm, 2),
                    'eta_unavailable_reason' => '',
                    'fare'                 => $fare,

                    'driver' => $driverData ?? null,
                    'driver_not_found' => $driverNotFound ?? false
                ];

                $mode = 'view_fare';
           }

        } else {
            //error
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

<h4 class="mb-3">Fare Check</h4>

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
            <div class="col-12 mt-4"><h6 class="text-muted mb-0">Booking Details</h6></div>
            <div class="col-12">
              <label class="form-label">Pickup Address *</label>
              <input type="text" name="pickup_address" class="form-control" value="<?= htmlspecialchars($_POST['pickup_address'] ?? $prefillPickupAddress) ?>" required>
            </div>
            <div class="col-12">
              <label class="form-label">Dropoff Address *</label>
              <input type="text" name="dropoff_address" class="form-control" value="<?= htmlspecialchars($_POST['dropoff_address'] ?? '') ?>" required>
            </div>

            <div class="col-12">
              <label class="form-label">Driver Number</label>
              <input type="text" name="driver_number" 
                class="form-control" value="<?= htmlspecialchars($_POST['driver_number'] ?? '') ?>">
            </div>
            <div class="col-12 d-flex gap-2 mt-4">
              <button name="fare_lookup" type="submit" class="btn btn-primary">Fare Look Up</button>
              <a href="bookings.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
<?php elseif ($mode == 'view_fare') :?>
    <div class="row justify-content-center">
        <div class="col-lg-8">

            <div class="card mb-3">
                <div class="card-header bg-white fw-semibold">Trip</div>
                <div class="card-body">
                <table class="table table-sm mb-0">
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
                    <th>ETA Drop Off</th>
                    <td>
                        <?php if ($preview['eta_unavailable_reason']): ?>
                        <span class="text-muted"><?= htmlspecialchars($preview['eta_unavailable_reason']) ?></span>
                        <?php else: ?>
                        <?= htmlspecialchars($preview['eta_text']) ?>
                        <?php if ($preview['eta_approx']): ?>
                            <span class="badge bg-warning text-dark" title="Distance Matrix API unavailable — estimated from straight-line distance at 25km/h">Approx.</span>
                        <?php endif; ?>
                        <?php endif; ?>
                        <div class="form-text mb-0">Pick Up location &rarr; to Drop Off address.</div>
                    </td>
                    </tr>
                </table>
                </div>
            </div>

            <?php if($preview['driver_not_found']) :?>
                <div class="alert alert-warning py-2">No driver found with that mobile number.</div>
            <?php endif; ?>

            <?php if($preview['driver'] != null) :?>
                <div class="card mb-3">
                    <div class="card-header bg-white fw-semibold">Driver</div>
                    <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><th>Name</th><td><?= val($preview['driver']['name']) ?> => <?= val($preview['driver']['mobileNumber']) ?> </td></tr>
                        <tr>
                            <th>Distance</th>
                            <td>
                                <?= number_format($preview['driver']['distance_km'], 2) ?> km
                                <?php if ($preview['distance_approx']): ?>
                                <span class="badge bg-warning text-dark" title="Distance Matrix API unavailable — straight-line estimate used instead">Approx.</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>
                                ETA Pick Up
                            </th>
                            <td>
                                <?php if ($preview['driver']['riderEtaUnavailableReason']): ?>
                                <span class="text-muted"><?= htmlspecialchars($preview['driver']['riderEtaUnavailableReason']) ?></span>
                                <?php else: ?>
                                <?= htmlspecialchars($preview['driver']['riderEtaText']) ?>
                                <?php if ($preview['driver']['riderEtaApprox']): ?>
                                    <span class="badge bg-warning text-dark" 
                                        title="Distance Matrix API unavailable — estimated from 
                                        straight-line distance at 25km/h">Approx.</span>
                                <?php endif; ?>
                                <?php endif; ?>
                                <div class="form-text mb-0">Pick Up location &rarr; to Drop Off address.</div>
                            </td>
                        </tr>
                         <tr><th>ETA Pick Up</th><td><?= val($preview['dropoff_address']) ?></td></tr>
                    </table>
                    </div>
                </div>
            <?php endif?>

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

            <div class="col-12 d-flex gap-2 mt-4">
                <?php if($preview['driver'] != null): ?>
                    <a href="booking_create.php?<?= http_build_query([
                        'driver_mobile'   => $preview['driver']['mobileNumber'],
                        'pickup_address'  => $preview['pickup_address'],
                        'dropoff_address' => $preview['dropoff_address'],
                        'fare'            => $preview['fare']['total_amount'],
                    ]) ?>" class="btn btn-success">Create Booking</a>
                <?php endif; ?>
                <a href="post_booking_create.php?<?= http_build_query([
                    'pickup_address'  => $preview['pickup_address'],
                    'dropoff_address' => $preview['dropoff_address'],
                    'fare'            => $preview['fare']['total_amount'],
                ]) ?>" class="btn btn-outline-success">Post Booking</a>
                <a href="booking_check_fare.php" class="btn btn-outline-secondary">Done</a>
            </div>
        </div>
    </div>

<?php endif; ?>



<?php require __DIR__ . '/includes/footer.php'; ?>
