<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/booking_status.php';

// Live driver-location view for an in-progress booking. Polls
// booking_live_location.php on an interval and moves a marker on a Leaflet/
// OSM map — no Google Maps JS SDK dependency, just the rider's current_lat/
// current_long already used elsewhere (nearby_drivers.php, booking_create.php).
// Nothing here writes to the DB.

$id = (int)($_GET['id'] ?? 0);
$booking = null;
$errorMsg = '';

if ($id <= 0) {
    $errorMsg = 'Invalid booking id.';
} else {
    try {
        $pdo = get_pdo();

        $stmt = $pdo->prepare(
            "SELECT b.id, b.ref_code, b.status, b.rider_id,
                    b.pickup_location, b.pickup_lat, b.pickup_long,
                    b.dropoff_location, b.dropoff_lat, b.dropoff_long,
                    r.code          AS rider_code,
                    r.first_name    AS rider_fname,
                    r.last_name     AS rider_lname,
                    r.mobile_no     AS rider_mobile,
                    r.current_lat,
                    r.current_long,
                    r.current_location,
                    r.is_online,
                    r.updated_at    AS rider_updated_at
             FROM public.booking b
             LEFT JOIN public.riders r ON r.id = b.rider_id
             WHERE b.id = :id
             LIMIT 1"
        );
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

$isActive = $booking && in_array((int)$booking['status'], BOOKING_STATUS_UPDATABLE_FROM, true);
$hasRider = $booking && $booking['rider_id'];

$activeNav = 'bookings';
require __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

<div class="mb-3 d-flex gap-2">
  <a href="booking_show.php?id=<?= (int)$id ?>" class="btn btn-sm btn-outline-secondary">&laquo; Back to Booking</a>
</div>

<?php if ($errorMsg !== ''): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php elseif (!$isActive): ?>
  <div class="alert alert-warning">
    This booking is <strong><?= htmlspecialchars(booking_status_label($booking['status'])) ?></strong> — it's no longer active, so there's nothing to track.
  </div>
<?php elseif (!$hasRider): ?>
  <div class="alert alert-warning">No driver is assigned to this booking yet — nothing to track.</div>
<?php else: ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Live Location &mdash; Booking <?= val($booking['ref_code']) ?></h4>
    <span class="badge <?= booking_status_badge_class($booking['status']) ?> fs-6" id="statusBadge">
      Status: <?= htmlspecialchars(booking_status_label($booking['status'])) ?>
    </span>
  </div>

  <div id="endedBanner" class="alert alert-warning d-none"></div>

  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-body p-0">
          <div id="map" style="height: 520px; border-radius: .375rem;"></div>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card mb-3">
        <div class="card-header bg-white fw-semibold">Driver</div>
        <div class="card-body">
          <table class="table table-sm mb-0">
            <tr>
              <th style="width:40%">Code</th>
              <td><a href="rider_show.php?id=<?= (int)$booking['rider_id'] ?>" class="btn btn-sm btn-outline-primary"><?= val($booking['rider_code']) ?></a></td>
            </tr>
            <tr><th>Name</th><td><?= val(trim(($booking['rider_fname'] ?? '') . ' ' . ($booking['rider_lname'] ?? ''))) ?></td></tr>
            <tr><th>Mobile</th><td><?= val($booking['rider_mobile']) ?></td></tr>
            <tr><th>Online</th><td id="onlineCell"><?= ((int)$booking['is_online'] === 1) ? '<span class="badge bg-success">Online</span>' : '<span class="badge bg-secondary">Offline</span>' ?></td></tr>
            <tr><th>Last Location(RED)</th><td id="locationCell"><?= val($booking['current_location']) ?></td></tr>
            <tr><th>Rider Record Updated</th><td id="updatedCell"><?= fmt_dt($booking['rider_updated_at']) ?></td></tr>
          </table>
        </div>
      </div>
      <div class="card">
        <div class="card-header bg-white fw-semibold">Trip</div>
        <div class="card-body">
          <table class="table table-sm mb-0">
            <tr><th style="width:40%">Pickup (Orange)</th><td><?= val($booking['pickup_location']) ?></td></tr>
            <tr><th>Dropoff (Green)</th><td><?= val($booking['dropoff_location']) ?></td></tr>
          </table>
        </div>
      </div>
    </div>
  </div>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    (function () {
      const bookingId = <?= (int)$id ?>;
      const pollUrl = 'booking_live_location.php?id=' + bookingId;
      const pollIntervalMs = 5000;

      const pickup = <?= ($booking['pickup_lat'] && $booking['pickup_long'])
          ? json_encode(['lat' => (float)$booking['pickup_lat'], 'lng' => (float)$booking['pickup_long']])
          : 'null' ?>;
      const dropoff = <?= ($booking['dropoff_lat'] && $booking['dropoff_long'])
          ? json_encode(['lat' => (float)$booking['dropoff_lat'], 'lng' => (float)$booking['dropoff_long']])
          : 'null' ?>;
      const initialDriver = <?= ($booking['current_lat'] && $booking['current_long'])
          ? json_encode(['lat' => (float)$booking['current_lat'], 'lng' => (float)$booking['current_long']])
          : 'null' ?>;

      function pinIcon(color) {
        return L.divIcon({
          html: '<svg width="24" height="36" viewBox="0 0 24 36" xmlns="http://www.w3.org/2000/svg">'
              + '<path d="M12 0C5.4 0 0 5.4 0 12c0 9 12 24 12 24s12-15 12-24c0-6.6-5.4-12-12-12z" fill="' + color + '"/>'
              + '<circle cx="12" cy="12" r="5" fill="#fff"/>'
              + '</svg>',
          className: '',
          iconSize: [24, 36],
          iconAnchor: [12, 36],
          popupAnchor: [0, -30],
        });
      }

      const center = initialDriver || pickup || dropoff || { lat: 14.5995, lng: 120.9842 }; // Metro Manila fallback
      const map = L.map('map').setView([center.lat, center.lng], 14);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
      }).addTo(map);

      const bounds = [];
      if (pickup) {
        L.marker([pickup.lat, pickup.lng], { icon: pinIcon('#fd7e14'), title: 'Pickup' })
          .addTo(map).bindPopup('Pickup');
        bounds.push([pickup.lat, pickup.lng]);
      }
      if (dropoff) {
        L.marker([dropoff.lat, dropoff.lng], { icon: pinIcon('#198754'), title: 'Dropoff' })
          .addTo(map).bindPopup('Dropoff');
        bounds.push([dropoff.lat, dropoff.lng]);
      }

      const driverIcon = L.divIcon({
        html: '<div style="background:#dc3545;width:30px;height:30px;border-radius:50%;border:2px solid #fff;box-shadow:0 0 3px rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;font-size:16px;line-height:1;">🏍️</div>',
        className: '',
        iconSize: [30, 30],
        iconAnchor: [15, 15],
      });
      let driverMarker = null;
      if (initialDriver) {
        driverMarker = L.marker([initialDriver.lat, initialDriver.lng], { icon: driverIcon, title: 'Driver' }).addTo(map);
        bounds.push([initialDriver.lat, initialDriver.lng]);
      }
      if (bounds.length > 1) {
        map.fitBounds(bounds, { padding: [30, 30] });
      }

      let pollTimer = null;

      function stopPolling() {
        if (pollTimer) clearInterval(pollTimer);
      }

      function showEnded(label) {
        const banner = document.getElementById('endedBanner');
        banner.textContent = 'Booking status changed to ' + label + ' — location tracking stopped.';
        banner.classList.remove('d-none');
        stopPolling();
      }

      async function poll() {
        try {
          const res = await fetch(pollUrl, { cache: 'no-store' });
          if (!res.ok) return;
          const data = await res.json();
          if (data.error) return;

          if (!data.active) {
            showEnded(data.status_label);
            return;
          }

          if (data.lat !== null && data.lng !== null) {
            if (!driverMarker) {
              driverMarker = L.marker([data.lat, data.lng], { icon: driverIcon, title: 'Driver' }).addTo(map);
            } else {
              driverMarker.setLatLng([data.lat, data.lng]);
            }
            map.panTo([data.lat, data.lng]);
          }

          document.getElementById('onlineCell').innerHTML = data.is_online
            ? '<span class="badge bg-success">Online</span>'
            : '<span class="badge bg-secondary">Offline</span>';
          document.getElementById('locationCell').textContent = data.location || '—';
          document.getElementById('updatedCell').textContent = data.updated_at || '—';
        } catch (e) {
          // Transient network hiccup — next tick will retry.
        }
      }

      pollTimer = setInterval(poll, pollIntervalMs);
    })();
  </script>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
