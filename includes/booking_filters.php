<?php
declare(strict_types=1);

// Shared WHERE-clause builder for bookings.php and bookings_export.php,
// so the exported CSV always matches exactly what the list page shows.
function get_booking_filters(): array
{
    $dateFrom   = trim($_GET['date_from'] ?? '');
    $dateTo     = trim($_GET['date_to'] ?? '');
    $status     = trim($_GET['status'] ?? '');
    $refCode    = trim($_GET['ref_code'] ?? '');
    $pickup     = trim($_GET['pickup'] ?? '');
    $dropoff    = trim($_GET['dropoff'] ?? '');
    $customerId = (int)($_GET['customer_id'] ?? 0);
    $riderId    = (int)($_GET['rider_id'] ?? 0);

    $where  = ['b.deleted_at IS NULL'];
    $params = [];

    // date_created is stored as varchar (assumed 'YYYY-MM-DD'); cast to date for range comparison
    if ($dateFrom !== '') {
        $where[] = "TO_DATE(b.date_created, 'YYYY-MM-DD') >= :date_from";
        $params[':date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = "TO_DATE(b.date_created, 'YYYY-MM-DD') <= :date_to";
        $params[':date_to'] = $dateTo;
    }
    if ($status !== '' && ctype_digit($status)) {
        $where[] = 'b.status = :status';
        $params[':status'] = (int)$status;
    }
    if ($refCode !== '') {
        $where[] = 'b.ref_code ILIKE :ref_code';
        $params[':ref_code'] = '%' . $refCode . '%';
    }
    if ($pickup !== '') {
        $where[] = 'b.pickup_location ILIKE :pickup';
        $params[':pickup'] = '%' . $pickup . '%';
    }
    if ($dropoff !== '') {
        $where[] = 'b.dropoff_location ILIKE :dropoff';
        $params[':dropoff'] = '%' . $dropoff . '%';
    }
    if ($customerId > 0) {
        $where[] = 'b.customer_id = :customer_id';
        $params[':customer_id'] = $customerId;
    }
    if ($riderId > 0) {
        $where[] = 'b.rider_id = :rider_id';
        $params[':rider_id'] = $riderId;
    }

    return [
        'whereSql'   => implode(' AND ', $where),
        'params'     => $params,
        'dateFrom'   => $dateFrom,
        'dateTo'     => $dateTo,
        'status'     => $status,
        'refCode'    => $refCode,
        'pickup'     => $pickup,
        'dropoff'    => $dropoff,
        'customerId' => $customerId,
        'riderId'    => $riderId,
    ];
}
