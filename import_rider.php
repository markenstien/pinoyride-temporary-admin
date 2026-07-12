<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$mode   = 'upload';
$rows   = [];
$errorMsg = '';
$results  = [];

function normalize_timestamp(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [date('Y-m-d H:i:s'), true];
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return [date('Y-m-d H:i:s'), true];
    }
    return [date('Y-m-d H:i:s', $ts), false];
}

function interpret_vehicle_type(string $raw): int
{
    return (stripos(trim($raw), 'motorcycle') !== false) ? 1 : 2;
}

// Image cells (license/OR-CR) are expected to be short filenames/paths.
// Anything past 100 chars is treated as bad data (e.g. a pasted data URI
// or an oversized URL) rather than a real image reference.
const IMG_MAX_LEN = 100;

function cap_image_value(string $v): array
{
    if (strlen($v) > IMG_MAX_LEN) {
        return ['invalid.jpg', true];
    }
    return [$v, false];
}

function map_row(array $r): array
{
    [$createdAt, $createdAtGuessed] = normalize_timestamp($r[0] ?? '');
    $vTypeRaw = trim($r[7] ?? '');
    [$driversLicenseImg, $licenseImgCapped] = cap_image_value(trim($r[13] ?? ''));
    [$vOrCrImg, $orCrImgCapped] = cap_image_value(trim($r[12] ?? ''));

    $mapped = [
        'created_at'          => $createdAt,
        'created_at_guessed'  => $createdAtGuessed,
        'first_name'          => trim($r[2] ?? ''),
        'last_name'           => trim($r[3] ?? ''),
        'drivers_license_img' => $driversLicenseImg,
        'mobile_no'           => trim($r[16] ?? ''),
        'email_address'       => trim($r[19] ?? ''),
        'v_type_raw'          => $vTypeRaw,
        'v_type'              => interpret_vehicle_type($vTypeRaw),
        'v_brand'             => trim($r[8] ?? ''),
        'v_model'             => trim($r[9] ?? ''),
        'v_color'             => trim($r[10] ?? ''),
        'v_plate_number'      => trim($r[11] ?? ''),
        'v_or_cr_img'         => $vOrCrImg,
        'address'             => trim($r[15] ?? ''),
    ];

    $issues = [];
    if ($mapped['first_name'] === '') $issues[] = 'Missing first name';
    if ($mapped['last_name'] === '') $issues[] = 'Missing last name';
    if ($mapped['mobile_no'] === '') $issues[] = 'Missing mobile number';
    if ($mapped['created_at_guessed']) $issues[] = 'Timestamp missing/unparsable — using current time';
    if ($licenseImgCapped) $issues[] = "Driver's license image value over " . IMG_MAX_LEN . ' chars — replaced with invalid.jpg';
    if ($orCrImgCapped) $issues[] = 'OR/CR image value over ' . IMG_MAX_LEN . ' chars — replaced with invalid.jpg';
    $mapped['issues'] = $issues;

    return $mapped;
}

function row_is_blank(array $mapped): bool
{
    foreach (['first_name', 'last_name', 'mobile_no', 'email_address', 'v_brand', 'v_model', 'address'] as $k) {
        if ($mapped[$k] !== '') return false;
    }
    return true;
}

function rider_exists_by_mobile(PDO $pdo, string $mobileNo): bool
{
    if ($mobileNo === '') return false;
    $stmt = $pdo->prepare('SELECT 1 FROM public.riders WHERE mobile_no = :mobile_no LIMIT 1');
    $stmt->execute([':mobile_no' => $mobileNo]);
    return $stmt->fetchColumn() !== false;
}

// DRN-{YY}{MM}-{N}: YY/MM from the rider's own created_at (registration
// month), N continues from the highest existing sequence number so far.
function next_rider_code_seq(PDO $pdo): int
{
    $stmt = $pdo->query(
        "SELECT COALESCE(MAX(NULLIF(regexp_replace(split_part(code, '-', 3), '\\D', '', 'g'), '')::int), 0)
         FROM public.riders
         WHERE code LIKE 'DRN-%'"
    );
    return (int)$stmt->fetchColumn() + 1;
}

function generate_rider_code(string $createdAt, int $seq): string
{
    $ts = strtotime($createdAt) ?: time();
    return sprintf('DRN-%s%s-%d', date('y', $ts), date('m', $ts), $seq);
}

// Dummy placeholder — the sheet only gives us the license image, not a real
// license number, so we fill this required field the same way the app does.
function generate_dummy_license_no(): string
{
    return sprintf('DUM-34-%06d', random_int(0, 999999));
}

// Version-4 UUID for top_ph_ekyc_details.kyc_id (new rows only).
function generate_uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    $hex = bin2hex($data);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

// wallet.ref_code is a unique, sequential 6-digit zero-padded code
// (see sql/insert_rider_wallets.sql) — continue that same sequence here.
function next_wallet_ref_seq(PDO $pdo): int
{
    $stmt = $pdo->query(
        "SELECT COALESCE(MAX(ref_code::bigint), 0)
         FROM public.wallet
         WHERE ref_code ~ '^[0-9]+$'"
    );
    return (int)$stmt->fetchColumn() + 1;
}

function generate_wallet_ref_code(int $seq): string
{
    return sprintf('%06d', $seq);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm']) && $_POST['confirm'] === '1' && isset($_POST['payload'])) {
    // -----------------------------------------------------------
    // Step 3: ingest the previously previewed & confirmed rows
    // -----------------------------------------------------------
    $mode = 'ingest';

    $decoded = json_decode($_POST['payload'], true);
    if (!is_array($decoded)) {
        $errorMsg = 'Could not read the confirmed data — please re-upload the file.';
    } else {
        try {
            $pdo = get_pdo();
        } catch (Throwable $e) {
            $errorMsg = 'Database connection failed.';
        }

        if ($errorMsg === '') {
            $nextSeq = next_rider_code_seq($pdo);

            foreach ($decoded as $i => $row) {
                $label = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                if ($label === '') $label = '(row ' . ($i + 1) . ')';

                // Re-apply the image length cap here too, in case the hidden
                // "payload" field was tampered with between preview and submit.
                [$driversLicenseImg] = cap_image_value((string)($row['drivers_license_img'] ?? ''));
                [$vOrCrImg] = cap_image_value((string)($row['v_or_cr_img'] ?? ''));

                $mobileNo = (string)($row['mobile_no'] ?? '');
                if (rider_exists_by_mobile($pdo, $mobileNo)) {
                    $results[] = ['row' => $i + 1, 'name' => $label, 'ok' => false, 'error' => 'Mobile number already exists — skipped'];
                    continue;
                }

                $createdAt = $row['created_at'] ?? date('Y-m-d H:i:s');
                $code      = generate_rider_code($createdAt, $nextSeq);

                try {
                    $pdo->beginTransaction();

                    $riderStmt = $pdo->prepare(
                        "INSERT INTO public.riders
                            (code, first_name, last_name, drivers_license_img, drivers_license_no, 
                            mobile_no, email_address, created_at, updated_at, updated_by,
                            is_verified, auto_accept, is_available, is_online, is_success_kyc, status,application_status)
                         VALUES
                            (:code, :first_name, :last_name, :drivers_license_img, :drivers_license_no, 
                            :mobile_no, :email_address, :created_at, :updated_at, :updated_by,
                            :is_verified, :auto_accept, :is_available, :is_online, :is_success_kyc, :status, :application_status)
                         RETURNING id"
                    );
                    $riderStmt->execute([
                        ':code'                => $code,
                        ':first_name'          => $row['first_name'] ?? '',
                        ':last_name'           => $row['last_name'] ?? '',
                        ':drivers_license_img' => $driversLicenseImg,
                        ':drivers_license_no'  => generate_dummy_license_no(),
                        ':mobile_no'           => $row['mobile_no'] ?? '',
                        ':email_address'       => ($row['email_address'] ?? '') !== '' ? $row['email_address'] : null,
                        ':created_at'          => $createdAt,

                        ':updated_at'          => $createdAt,
                        ':updated_by'          => 1,
                        ':is_verified'         => 1,
                        ':auto_accept'         => 1,
                        ':is_available'         => 1,
                        ':is_online' => 0,
                        ':is_success_kyc' => 0,
                        ':status'              => 1,
                        ':application_status'  => 1
                    ]);
                    $riderId = (int)$riderStmt->fetchColumn();

                    $vehicleStmt = $pdo->prepare(
                        "INSERT INTO public.rider_vehicle_details
                            (rider_id, v_type, v_brand, v_model, v_color, v_plate_number, v_or_cr_img, status, created_at, updated_at)
                         VALUES
                            (:rider_id, :v_type, :v_brand, :v_model, :v_color, :v_plate_number, :v_or_cr_img, :status, :created_at, :updated_at)"
                    );
                    $vehicleStmt->execute([
                        ':rider_id'       => $riderId,
                        ':v_type'          => $row['v_type'] ?? 2,
                        ':v_brand'         => $row['v_brand'] ?? '',
                        ':v_model'         => $row['v_model'] ?? '',
                        ':v_color'         => $row['v_color'] ?? '',
                        ':v_plate_number'  => $row['v_plate_number'] ?? '',
                        ':v_or_cr_img'     => $vOrCrImg,
                        ':status'     => 1,
                        ':created_at'     => $createdAt,
                        ':updated_at'     => $createdAt,
                    ]);

                    $addressStmt = $pdo->prepare(
                        "INSERT INTO public.rider_address (rider_id, status, address, created_at, updated_at, deleted_at)
                         VALUES (:rider_id, :status, :address, :created_at, :updated_at, :deleted_at)"
                    );
                    $addressStmt->execute([
                        ':rider_id' => $riderId,
                        ':status'  => 1,
                        ':address'   => $row['address'] ?? '',
                        ':created_at'   => $createdAt,
                        ':updated_at'   => $createdAt,
                        ':deleted_at'   => $createdAt,
                    ]);

                    $pdo->commit();
                    $nextSeq++;
                    $results[] = ['row' => $i + 1, 'name' => $label, 'ok' => true, 'rider_id' => $riderId, 'code' => $code];
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $results[] = ['row' => $i + 1, 'name' => $label, 'ok' => false, 'error' => $e->getMessage()];
                }
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    // -----------------------------------------------------------
    // Step 2: parse the uploaded CSV and show a preview
    // -----------------------------------------------------------
    $mode = 'preview';
    $file = $_FILES['csv_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = 'Upload failed (error code ' . $file['error'] . ').';
    } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $errorMsg = 'Please upload a .csv file (export your spreadsheet as CSV first).';
    } else {
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            $errorMsg = 'Could not read the uploaded file.';
        } else {
            try {
                $pdo = get_pdo();
            } catch (Throwable $e) {
                $errorMsg = 'Database connection failed.';
            }

            if ($errorMsg === '') {
                $seenMobiles = [];
                $lineNo = 0;
                while (($line = fgetcsv($handle)) !== false) {
                    $lineNo++;
                    if ($lineNo === 1) continue; // header row
                    $mapped = map_row($line);
                    if (row_is_blank($mapped)) continue;

                    if ($mapped['mobile_no'] !== '') {
                        if (rider_exists_by_mobile($pdo, $mapped['mobile_no'])) {
                            $mapped['issues'][] = 'Mobile number already exists in the database';
                        } elseif (isset($seenMobiles[$mapped['mobile_no']])) {
                            $mapped['issues'][] = 'Duplicate mobile number within this file';
                        } else {
                            $seenMobiles[$mapped['mobile_no']] = true;
                        }
                    }

                    $rows[] = $mapped;
                }
            }
            fclose($handle);

            if ($errorMsg === '' && empty($rows)) {
                $errorMsg = 'No data rows found in the file.';
                $mode = 'upload';
            }
        }
    }
}

function val($v): string
{
    return ($v === null || $v === '') ? '—' : htmlspecialchars((string)$v);
}

$activeNav = 'riders';
require __DIR__ . '/includes/header.php';
?>

<div class="mb-3">
  <a href="riders.php" class="btn btn-sm btn-outline-secondary">&laquo; Back to Riders</a>
</div>

<h4 class="mb-3">Import Riders from CSV</h4>

<?php if ($errorMsg !== ''): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>

<?php if ($mode === 'upload'): ?>

  <div class="card filter-card">
    <div class="card-body">
      <p class="text-muted">
        Upload a CSV export of the driver registration sheet. Expected columns (0-indexed):
        Timestamp[0], First Name[2], Last Name[3], Vehicle Type[7], Brand[8], Model[9], Color[10],
        Plate Number[11], OR/CR[12], Pro Driver's License[13], Address[15], Mobile Number[16], Email[19].
      </p>
      <form method="post" enctype="multipart/form-data" class="row g-3">
        <div class="col-md-8">
          <input type="file" name="csv_file" class="form-control" accept=".csv" required>
        </div>
        <div class="col-md-4">
          <button type="submit" class="btn btn-primary">Upload &amp; Preview</button>
        </div>
      </form>
    </div>
  </div>

<?php elseif ($mode === 'preview'): ?>

  <div class="d-flex justify-content-between align-items-center mb-2">
    <span class="text-muted"><?= count($rows) ?> row(s) parsed — review the mapping below before ingesting.</span>
    <a href="import_rider.php" class="btn btn-sm btn-outline-secondary">Upload a different file</a>
  </div>

  <div class="table-responsive bg-white mb-3">
    <table class="table table-striped table-hover align-middle mb-0 small">
      <thead>
        <tr>
          <th>#</th>
          <th>Created At</th>
          <th>First Name</th>
          <th>Last Name</th>
          <th>Mobile</th>
          <th>Email</th>
          <th>License Img</th>
          <th>Vehicle Type</th>
          <th>Brand</th>
          <th>Model</th>
          <th>Color</th>
          <th>Plate No.</th>
          <th>OR/CR Img</th>
          <th>Address</th>
          <th>Check</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i => $r): ?>
          <tr class="<?= !empty($r['issues']) ? 'table-warning' : '' ?>">
            <td><?= $i + 1 ?></td>
            <td><?= val($r['created_at']) ?></td>
            <td><?= val($r['first_name']) ?></td>
            <td><?= val($r['last_name']) ?></td>
            <td><?= val($r['mobile_no']) ?></td>
            <td><?= val($r['email_address']) ?></td>
            <td class="text-truncate" style="max-width:120px" title="<?= htmlspecialchars($r['drivers_license_img']) ?>"><?= val($r['drivers_license_img']) ?></td>
            <td><?= val($r['v_type_raw']) ?> <span class="badge bg-secondary"><?= $r['v_type'] ?></span></td>
            <td><?= val($r['v_brand']) ?></td>
            <td><?= val($r['v_model']) ?></td>
            <td><?= val($r['v_color']) ?></td>
            <td><?= val($r['v_plate_number']) ?></td>
            <td class="text-truncate" style="max-width:120px" title="<?= htmlspecialchars($r['v_or_cr_img']) ?>"><?= val($r['v_or_cr_img']) ?></td>
            <td class="text-truncate" style="max-width:150px" title="<?= htmlspecialchars($r['address']) ?>"><?= val($r['address']) ?></td>
            <td>
              <?php if (empty($r['issues'])): ?>
                <span class="badge bg-success">OK</span>
              <?php else: ?>
                <span class="badge bg-warning text-dark" title="<?= htmlspecialchars(implode('; ', $r['issues'])) ?>">⚠ <?= count($r['issues']) ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="alert alert-info">
    Rows highlighted in yellow have a warning (hover the ⚠ badge for details) but can still be ingested —
    review them carefully first. Vehicle Type badge: <strong>1</strong> = Motorcycle, <strong>2</strong> = Other.
  </div>

  <form method="post">
    <input type="hidden" name="confirm" value="1">
    <textarea name="payload" style="display:none"><?= htmlspecialchars(json_encode($rows), ENT_QUOTES, 'UTF-8') ?></textarea>
    <button type="submit" class="btn btn-success" onclick="return confirm('Ingest <?= count($rows) ?> rider(s) into the database?');">
      Confirm &amp; Ingest <?= count($rows) ?> Row(s)
    </button>
    <a href="import_rider.php" class="btn btn-outline-secondary">Cancel</a>
  </form>

<?php elseif ($mode === 'ingest'): ?>

  <?php
    $successCount = count(array_filter($results, fn($r) => $r['ok']));
    $failCount    = count($results) - $successCount;
  ?>

  <div class="alert <?= $failCount > 0 ? 'alert-warning' : 'alert-success' ?>">
    Ingested <strong><?= $successCount ?></strong> rider(s) successfully.
    <?php if ($failCount > 0): ?>
      <strong><?= $failCount ?></strong> row(s) failed — see details below.
    <?php endif; ?>
  </div>

  <div class="table-responsive bg-white">
    <table class="table table-striped table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>Code</th>
          <th>Result</th>
          <th>Details</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($results as $r): ?>
          <tr>
            <td><?= $r['row'] ?></td>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td><?= htmlspecialchars($r['code'] ?? '—') ?></td>
            <td>
              <?php if ($r['ok']): ?>
                <span class="badge bg-success">Success</span>
              <?php else: ?>
                <span class="badge bg-danger">Failed</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($r['ok']): ?>
                <a href="rider_show.php?id=<?= (int)$r['rider_id'] ?>">View rider #<?= (int)$r['rider_id'] ?></a>
              <?php else: ?>
                <span class="text-danger small"><?= htmlspecialchars($r['error']) ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="mt-3">
    <a href="import_rider.php" class="btn btn-outline-secondary">Import Another File</a>
    <a href="riders.php" class="btn btn-primary">Go to Riders</a>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
