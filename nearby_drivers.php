<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/geocoding.php';
$tabTitle = 'Nearby Drivers';
// Admin types a pickup address, we geocode it and list active (status=1)
// drivers whose last known location (current_lat/current_long) is within
// NEARBY_RADIUS_KM straight-line distance. "Online only" is checked by
// default and requires both is_online=1 and is_login=1 (app-online and
// actually logged in) so results reflect who could actually be dispatched
// right now; unchecking it also surfaces active-but-offline/logged-out
// drivers in the area. Candidate set is small (~100-300 at a time) so
// distance is computed in PHP with the same haversine_km() helper
// booking_create.php uses for its ETA fallback, rather than doing it in SQL.

const NEARBY_RADIUS_KM = 10.0;

$address  = trim($_GET['address'] ?? '');
$errorMsg = '';
$pickup   = null;
$results  = [];
$searched = $address !== '';

// Unchecked checkboxes submit nothing, so a hidden 0 sent right before the
// checkbox lets PHP tell "never searched" (default true) apart from
// "searched with the box unchecked" (0, since it's the only value sent).
$onlineOnly = $searched ? (($_GET['online_only'] ?? '0') === '1') : true;

if ($searched) {
    if (!google_maps_configured()) {
        $errorMsg = 'Google Maps isn\'t configured yet — add GOOGLE_MAPS_API_KEY to .env before searching.';
    } else {
        $pickup = geocode_address($address);

        if (!$pickup) {
            $errorMsg = 'Could not locate that address — try a more specific address.';
        } else {
            try {
                $pdo = get_pdo();

                $sql = "SELECT id, code, first_name, middle_name, last_name, mobile_no,
                               current_lat, current_long, current_location, is_online, is_login, is_available
                        FROM public.riders
                        WHERE deleted_at IS NULL AND status = 1
                          AND current_lat IS NOT NULL AND current_lat <> ''
                          AND current_long IS NOT NULL AND current_long <> ''"
                        . ($onlineOnly ? ' AND is_online = 1 AND is_login = 1' : '');

                $stmt = $pdo->query($sql);
                $candidates = $stmt->fetchAll();

                $pickupLat = (float)$pickup['lat'];
                $pickupLng = (float)$pickup['lng'];

                foreach ($candidates as $r) {
                    $distanceKm = haversine_km($pickupLat, $pickupLng, (float)$r['current_lat'], (float)$r['current_long']);
                    if ($distanceKm <= NEARBY_RADIUS_KM) {
                        $r['distance_km'] = $distanceKm;
                        $results[] = $r;
                    }
                }

                usort($results, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);

                if (!empty($results)) {
                    $riderIds = array_map(fn($r) => (int)$r['id'], $results);
                    $placeholders = implode(',', array_fill(0, count($riderIds), '?'));
                    $vehStmt = $pdo->prepare(
                        "SELECT DISTINCT ON (rider_id) rider_id, v_type, v_brand, v_model, v_color, v_plate_number
                         FROM public.rider_vehicle_details
                         WHERE rider_id IN ($placeholders)
                         ORDER BY rider_id, created_at DESC"
                    );
                    $vehStmt->execute($riderIds);

                    $vehiclesByRider = [];
                    foreach ($vehStmt->fetchAll() as $v) {
                        $vehiclesByRider[(int)$v['rider_id']] = $v;
                    }
                    foreach ($results as &$r) {
                        $r['vehicle'] = $vehiclesByRider[(int)$r['id']] ?? null;
                    }
                    unset($r);

                    // ETA: one batched Distance Matrix call for every driver on the
                    // page rather than one call each. Same 25km/h straight-line
                    // fallback booking_create.php uses when a route can't be found.
                    $etaOrigins = [];
                    foreach ($results as $idx => $r) {
                        $etaOrigins[$idx] = [(float)$r['current_lat'], (float)$r['current_long']];
                    }
                    $etaBatch = distance_matrix_batch($etaOrigins, $pickupLat, $pickupLng);

                    foreach ($results as $idx => &$r) {
                        if (isset($etaBatch[$idx])) {
                            $r['eta_text']   = $etaBatch[$idx]['duration_text'];
                            $r['eta_approx'] = false;
                        } else {
                            $assumedSpeedKmh = 25.0;
                            $etaMinutes = ($r['distance_km'] / $assumedSpeedKmh) * 60;
                            $r['eta_text']   = round($etaMinutes) . ' min';
                            $r['eta_approx'] = true;
                        }
                    }
                    unset($r);
                }
            } catch (PDOException $e) {
                $errorMsg = 'Query failed: ' . $e->getMessage();
            }
        }
    }
}

function val($v): string
{
    return ($v === null || $v === '') ? '—' : htmlspecialchars((string)$v);
}

function vehicle_type_label($v): string
{
    return ((int)$v === 1) ? 'Motorcycle' : 'Other';
}

// Mobile number and vehicle details are sensitive — rendered blurred/masked
// by default and only swapped in from data-value on click (see toggleSensitive()
// in the inline script below). Value is already in the page, so no AJAX
// round-trip is needed to reveal it.
function sensitive_span(string $value, string $mask): string
{
    if ($value === '') {
        return '<span class="text-muted">—</span>';
    }

    return '<span class="sensitive-field" data-value="' . htmlspecialchars($value) . '" data-mask="' . htmlspecialchars($mask) . '" onclick="toggleSensitive(this)" title="Click to reveal">'
         . htmlspecialchars($mask) . '</span>';
}

$activeNav = 'nearby_drivers';
require __DIR__ . '/includes/header.php';
?>

<style>
  .sensitive-field {
    cursor: pointer;
    filter: blur(4px);
    transition: filter 0.15s ease;
    user-select: none;
    display: inline-block;
  }
  .sensitive-field.revealed {
    filter: none;
    user-select: text;
  }
</style>

<h4 class="mb-3">Nearby Active Drivers</h4>

<div class="card filter-card mb-4">
  <div class="card-body">
    <form method="get" class="row g-3">
      <div class="col-md-9">
        <label class="form-label">Pickup Address</label>
        <input type="text" name="address" class="form-control" placeholder="e.g. 40 Falcon, Quezon City, Metro Manila" value="<?= htmlspecialchars($address) ?>" required>
      </div>
      <div class="col-md-3 d-flex align-items-end">
        <button type="submit" class="btn btn-primary w-100">Find Drivers</button>
      </div>
      <div class="col-12">
        <input type="hidden" name="online_only" value="0">
        <div class="form-check">
          <input type="checkbox" name="online_only" id="online_only" value="1" class="form-check-input" <?= $onlineOnly ? 'checked' : '' ?>>
          <label class="form-check-label" for="online_only">Online only (app-online and logged in)</label>
        </div>
      </div>
    </form>
  </div>
</div>

<?php if ($errorMsg !== ''): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>

<?php if ($pickup): ?>
  <div class="alert alert-secondary">
    Searching within <?= (int)NEARBY_RADIUS_KM ?> km of
    <strong><?= htmlspecialchars($pickup['formatted_address']) ?></strong>
    (<?= htmlspecialchars($pickup['lat']) ?>, <?= htmlspecialchars($pickup['lng']) ?>)
  </div>

  <div class="d-flex justify-content-between align-items-center mb-2">
    <span class="text-muted"><?= count($results) ?> driver<?= count($results) === 1 ? '' : 's' ?> found</span>
  </div>

  <div class="table-responsive bg-white">
    <table class="table table-striped table-hover align-middle mb-0 dataTable">
      <thead>
        <tr>
          <th>Distance</th>
          <th>ETA</th>
          <th>Code</th>
          <th>Full Name</th>
          <th>Mobile</th>
          <th>Vehicle</th>
          <th>Online</th>
          <th>Available</th>
          <th>Last Known Location</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($results)): ?>
          <tr>
            <td colspan="10" class="text-center text-muted py-4">No active drivers within <?= (int)NEARBY_RADIUS_KM ?> km of that address.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($results as $r): ?>
            <tr>
              <td><?= number_format($r['distance_km'], 2) ?> km</td>
              <td>
                <?= htmlspecialchars($r['eta_text']) ?>
                <?php if ($r['eta_approx']): ?>
                  <span class="badge bg-warning text-dark" title="Distance Matrix API unavailable — estimated from straight-line distance at 25km/h">Approx.</span>
                <?php endif; ?>
              </td>
              <td><?= val($r['code']) ?></td>
              <td><?= val(trim($r['first_name'] . ' ' . ($r['middle_name'] ? $r['middle_name'] . ' ' : '') . $r['last_name'])) ?></td>
              <td><?php
                $mobile = (string)($r['mobile_no'] ?? '');
                echo sensitive_span($mobile, $mobile === '' ? '' : str_repeat('•', max(8, strlen($mobile))));
              ?></td>
              <td><?php
                $vehText = '';
                if ($r['vehicle']) {
                    $vehText = vehicle_type_label($r['vehicle']['v_type']) . ' — ' . trim($r['vehicle']['v_brand'] . ' ' . $r['vehicle']['v_model']);
                    if ($r['vehicle']['v_plate_number']) {
                        $vehText .= ' (' . $r['vehicle']['v_plate_number'] . ')';
                    }
                }
                echo sensitive_span($vehText, 'Vehicle details — click to reveal');
              ?></td>
              <td>
                <?= ((int)$r['is_online'] === 1 && (int)$r['is_login'] === 1)
                      ? '<span class="badge bg-success">Online</span>'
                      : '<span class="badge bg-secondary">Offline</span>' ?>
              </td>
              <td>
                <?= ((int)$r['is_available'] === 1)
                      ? '<span class="badge bg-success">Yes</span>'
                      : '<span class="badge bg-secondary">No</span>' ?>
              </td>
              <td class="small"><?= val($r['current_location']) ?></td>
              <td class="d-flex gap-1">
                <a href="rider_show.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                <a href="booking_create.php?<?= htmlspecialchars(http_build_query([
                     'driver_mobile'  => $r['mobile_no'],
                     'pickup_address' => $pickup['formatted_address'],
                   ])) ?>" class="btn btn-sm btn-success">Book</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
<?php elseif ($searched): ?>
  <!-- geocoding failed; error already shown above -->
<?php else: ?>
  <div class="text-muted">Enter a pickup address above to find nearby active drivers.</div>
<?php endif; ?>

<script>
function toggleSensitive(el) {
  var revealed = el.classList.toggle('revealed');
  el.textContent = revealed ? el.dataset.value : el.dataset.mask;
  el.title = revealed ? 'Click to hide' : 'Click to reveal';
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
