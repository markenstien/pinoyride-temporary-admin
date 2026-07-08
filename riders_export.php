<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// ---------------------------------------------------------------
// Same filter inputs/logic as riders.php, so the export matches
// whatever the user currently has filtered/searched for.
// ---------------------------------------------------------------
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');
$mobile   = trim($_GET['mobile'] ?? '');
$fname    = trim($_GET['fname'] ?? '');
$lname    = trim($_GET['lname'] ?? '');
$isActive = $_GET['is_online'] ?? '';

$where  = ['deleted_at IS NULL'];
$params = [];

if ($dateFrom !== '') {
    $where[] = 'created_at >= :date_from';
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'created_at <= :date_to';
    $params[':date_to'] = $dateTo . ' 23:59:59';
}
if ($mobile !== '') {
    $where[] = 'mobile_no ILIKE :mobile';
    $params[':mobile'] = '%' . $mobile . '%';
}
if ($fname !== '') {
    $where[] = 'first_name ILIKE :fname';
    $params[':fname'] = '%' . $fname . '%';
}
if ($lname !== '') {
    $where[] = 'last_name ILIKE :lname';
    $params[':lname'] = '%' . $lname . '%';
}
if ($isActive !== '') {
    $where[] = 'is_online = :is_online';
    $params[':is_online'] = $isActive;
}

$whereSql = implode(' AND ', $where);

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

    $sql = "SELECT code, first_name, middle_name, last_name, mobile_no, email_address,
                   drivers_license_no, drivers_license_no_expiration_date,
                   status, application_status, is_verified, is_online, is_available,
                   created_at
            FROM public.riders
            WHERE {$whereSql}
            ORDER BY created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'Export failed: ' . $e->getMessage();
    exit;
}

$filename = 'riders-export-' . date('Y-m-d-His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
// BOM so Excel opens UTF-8 (e.g. accented names) correctly
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'Code', 'Full Name', 'Mobile', 'Email',
    'License No.', 'License Expiry',
    'Status', 'Application Status', 'Verified', 'Online', 'Available',
    'Created At',
]);

while ($row = $stmt->fetch()) {
    $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . ($row['last_name'] ?? ''));

    fputcsv($out, [
        csv_safe($row['code']),
        csv_safe($fullName),
        csv_safe($row['mobile_no']),
        csv_safe($row['email_address']),
        csv_safe($row['drivers_license_no']),
        csv_safe($row['drivers_license_no_expiration_date']),
        csv_safe(((int)$row['status'] === 1) ? 'Active' : 'Inactive'),
        csv_safe($row['application_status']),
        csv_safe(((int)$row['is_verified'] === 1) ? 'Yes' : 'No'),
        csv_safe(((int)$row['is_online'] === 1) ? 'Online' : 'Offline'),
        csv_safe(((int)$row['is_available'] === 1) ? 'Yes' : 'No'),
        csv_safe($row['created_at']),
    ]);
}

fclose($out);
exit;
