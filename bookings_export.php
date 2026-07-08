<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/booking_filters.php';
require_once __DIR__ . '/includes/booking_status.php';

$filters  = get_booking_filters();
$whereSql = $filters['whereSql'];
$params   = $filters['params'];

// Prevent CSV formula injection (=, +, -, @) when opened in Excel/Sheets
function csv_safe($v): string
{
    $v = (string)($v ?? '');
    if ($v !== '' && strpbrk($v[0], "=+-@") !== false) {
        return "'" . $v;
    }
    return $v;
}

try {
    $pdo = get_pdo();

    $sql = "SELECT
                b.ref_code, b.pickup_location, b.dropoff_location,
                b.status, b.payment_type, b.distance_km, b.booking_type,
                b.date_created, b.time_created, b.created_at,
                c.fname       AS customer_fname,
                c.lname       AS customer_lname,
                c.mobile      AS customer_mobile,
                r.first_name  AS rider_fname,
                r.last_name   AS rider_lname,
                r.mobile_no   AS rider_mobile
            FROM public.booking b
            LEFT JOIN public.customer c ON c.id = b.customer_id
            LEFT JOIN public.riders   r ON r.id = b.rider_id
            WHERE {$whereSql}
            ORDER BY b.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'Export failed: ' . $e->getMessage();
    exit;
}

$filename = 'bookings-export-' . date('Y-m-d-His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
// BOM so Excel opens UTF-8 (e.g. accented names) correctly
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'Ref Code', 'Customer', 'Customer Mobile', 'Rider', 'Rider Mobile',
    'Pickup', 'Dropoff', 'Distance (km)', 'Payment', 'Type', 'Status', 'Status Description',
    'Date Created', 'Time Created', 'Created At',
]);

while ($row = $stmt->fetch()) {
    fputcsv($out, [
        csv_safe($row['ref_code']),
        csv_safe(trim(($row['customer_fname'] ?? '') . ' ' . ($row['customer_lname'] ?? ''))),
        csv_safe($row['customer_mobile']),
        csv_safe(trim(($row['rider_fname'] ?? '') . ' ' . ($row['rider_lname'] ?? ''))),
        csv_safe($row['rider_mobile']),
        csv_safe($row['pickup_location']),
        csv_safe($row['dropoff_location']),
        csv_safe($row['distance_km']),
        csv_safe($row['payment_type']),
        csv_safe($row['booking_type']),
        csv_safe($row['status']),
        csv_safe(booking_status_label($row['status'])),
        csv_safe($row['date_created']),
        csv_safe($row['time_created']),
        csv_safe($row['created_at']),
    ]);
}

fclose($out);
exit;
