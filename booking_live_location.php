<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/booking_status.php';

// JSON polling endpoint for booking_live.php — read-only, no DB writes.
// Re-checks booking.status on every call so the page knows to stop tracking
// as soon as the trip completes/cancels elsewhere (app, another admin tab).

header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid booking id.']);
    exit;
}

try {
    $pdo = get_pdo();

    $stmt = $pdo->prepare(
        "SELECT b.status, b.rider_id,
                r.current_lat, r.current_long, r.current_location, r.is_online, r.updated_at
         FROM public.booking b
         LEFT JOIN public.riders r ON r.id = b.rider_id
         WHERE b.id = :id
         LIMIT 1"
    );
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Booking not found.']);
        exit;
    }

    $lat = ($row['current_lat'] !== null && $row['current_lat'] !== '') ? (float)$row['current_lat'] : null;
    $lng = ($row['current_long'] !== null && $row['current_long'] !== '') ? (float)$row['current_long'] : null;

    echo json_encode([
        'status'       => (int)$row['status'],
        'status_label' => booking_status_label($row['status']),
        'active'       => in_array((int)$row['status'], BOOKING_STATUS_UPDATABLE_FROM, true),
        'has_rider'    => $row['rider_id'] !== null,
        'lat'          => $lat,
        'lng'          => $lng,
        'location'     => $row['current_location'],
        'is_online'    => $row['is_online'] !== null ? ((int)$row['is_online'] === 1) : null,
        'updated_at'   => $row['updated_at'],
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Query failed.']);
}
