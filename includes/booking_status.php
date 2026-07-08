<?php
declare(strict_types=1);

// Single source of truth for booking.status meaning, shared by bookings.php,
// bookings_export.php and booking_show.php.
const BOOKING_STATUSES = [
    0 => 'Looking for Driver',
    1 => 'Accepted by Driver',
    2 => 'In Transit',
    3 => 'Complete Trip',
    4 => 'Cancelled',
];

// Statuses still "in progress" — the only ones an admin is allowed to
// manually move forward from, and only into BOOKING_STATUS_UPDATE_OPTIONS.
const BOOKING_STATUS_UPDATABLE_FROM = [0, 1, 2];

// Terminal statuses an in-progress booking can be manually set to.
const BOOKING_STATUS_UPDATE_OPTIONS = [3, 4];

function booking_status_label($status): string
{
    $status = (int)$status;
    return BOOKING_STATUSES[$status] ?? ('Unknown (' . $status . ')');
}

function booking_status_badge_class($status): string
{
    switch ((int)$status) {
        case 0: return 'bg-secondary';
        case 1: return 'bg-primary';
        case 2: return 'bg-info text-dark';
        case 3: return 'bg-success';
        case 4: return 'bg-danger';
        default: return 'bg-dark';
    }
}
