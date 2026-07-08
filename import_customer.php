<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$mode     = 'upload';
$rows     = [];
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

function random_chars(int $length): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

function generate_customer_code(): string
{
    return sprintf('%06d', random_int(0, 999999));
}

function map_row(array $r): array
{
    [$createdAt, $createdAtGuessed] = normalize_timestamp($r[0] ?? '');

    $mapped = [
        'created_at'         => $createdAt,
        'created_at_guessed' => $createdAtGuessed,
        'first_name'         => trim($r[2] ?? ''),
        'last_name'          => trim($r[3] ?? ''),
        'bdate'              => trim($r[4] ?? ''),
        'age'                => trim($r[5] ?? ''),
        'gender'             => trim($r[6] ?? ''),
        'address'            => trim($r[7] ?? ''),
        'mobile_number'      => trim($r[8] ?? ''),
        'govid'              => trim($r[9] ?? ''),
    ];

    $issues = [];
    if ($mapped['first_name'] === '') $issues[] = 'Missing first name';
    if ($mapped['last_name'] === '') $issues[] = 'Missing last name';
    if ($mapped['mobile_number'] === '') $issues[] = 'Missing mobile number';
    if ($mapped['created_at_guessed']) $issues[] = 'Timestamp missing/unparsable — using current time';
    $mapped['issues'] = $issues;

    return $mapped;
}

function row_is_blank(array $mapped): bool
{
    foreach (['first_name', 'last_name', 'mobile_number', 'address', 'govid'] as $k) {
        if ($mapped[$k] !== '') return false;
    }
    return true;
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
            foreach ($decoded as $i => $row) {
                $label = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                if ($label === '') $label = '(row ' . ($i + 1) . ')';

                $mobileNumber       = (string)($row['mobile_number'] ?? '');
                $ekycRequestUserId  = $mobileNumber . random_chars(8);

                try {
                    $pdo->beginTransaction();

                    // Assumption: real columns are fname/lname/mobile (per index.php's
                    // working query) rather than firstname/lastname/mobile_number.
                    $custStmt = $pdo->prepare(
                        "INSERT INTO public.customer
                            (code, fname, lname, bdate, age, gender, mobile, govid, ekyc_request_user_id,
                             customer_type, status, is_verified, is_success_kyc, created_at, updated_at)
                         VALUES
                            (:code, :fname, :lname, :bdate, :age, :gender, :mobile, :govid, :ekyc_request_user_id,
                             1, 1, 0, 1, NOW(), NOW())
                         RETURNING id"
                    );
                    $custStmt->execute([
                        ':code'                  => generate_customer_code(),
                        ':fname'                 => $row['first_name'] ?? '',
                        ':lname'                 => $row['last_name'] ?? '',
                        ':bdate'                 => ($row['bdate'] ?? '') !== '' ? $row['bdate'] : null,
                        ':age'                   => ($row['age'] ?? '') !== '' ? (int)$row['age'] : null,
                        ':gender'                => $row['gender'] ?? '',
                        ':mobile'                => $mobileNumber,
                        ':govid'                 => $row['govid'] ?? '',
                        ':ekyc_request_user_id'  => $ekycRequestUserId,
                    ]);
                    $customerId = (int)$custStmt->fetchColumn();

                    $kycStmt = $pdo->prepare(
                        "INSERT INTO public.top_ph_ekyc_details
                            (kyc_id, first_name, last_name, date_of_birth, gender, current_address, mobile_no,
                             status, generate_request_user_id, created_at, updated_at)
                         VALUES
                            (:kyc_id, :first_name, :last_name, :date_of_birth, :gender, :current_address, :mobile_no,
                             0, :generate_request_user_id, NOW(), NOW())"
                    );
                    $kycStmt->execute([
                        ':kyc_id'                    => random_chars(12),
                        ':first_name'                => $row['first_name'] ?? '',
                        ':last_name'                 => $row['last_name'] ?? '',
                        ':date_of_birth'              => $row['bdate'] ?? '',
                        ':gender'                    => $row['gender'] ?? '',
                        ':current_address'           => $row['address'] ?? '',
                        ':mobile_no'                 => $mobileNumber,
                        ':generate_request_user_id'  => $ekycRequestUserId,
                    ]);

                    $pdo->commit();
                    $results[] = ['row' => $i + 1, 'name' => $label, 'ok' => true, 'customer_id' => $customerId, 'ekyc_id' => $ekycRequestUserId];
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
            $lineNo = 0;
            while (($line = fgetcsv($handle)) !== false) {
                $lineNo++;
                if ($lineNo === 1) continue; // header row
                $mapped = map_row($line);
                if (row_is_blank($mapped)) continue;
                $rows[] = $mapped;
            }
            fclose($handle);

            if (empty($rows)) {
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

$activeNav = 'customers';
require __DIR__ . '/includes/header.php';
?>

<div class="mb-3">
  <a href="index.php" class="btn btn-sm btn-outline-secondary">&laquo; Back to Customers</a>
</div>

<h4 class="mb-3">Import Customers from CSV</h4>

<?php if ($errorMsg !== ''): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>

<?php if ($mode === 'upload'): ?>

  <div class="card filter-card">
    <div class="card-body">
      <p class="text-muted">
        Upload a CSV export of the customer registration sheet. Expected columns (0-indexed):
        Timestamp[0], First Name[2], Last Name[3], Birth Date[4], Age[5], Gender[6], Address[7],
        Mobile Number[8], Government ID[9].
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
    <a href="import_customer.php" class="btn btn-sm btn-outline-secondary">Upload a different file</a>
  </div>

  <div class="table-responsive bg-white mb-3">
    <table class="table table-striped table-hover align-middle mb-0 small">
      <thead>
        <tr>
          <th>#</th>
          <th>Created At</th>
          <th>First Name</th>
          <th>Last Name</th>
          <th>Birth Date</th>
          <th>Age</th>
          <th>Gender</th>
          <th>Address</th>
          <th>Mobile</th>
          <th>Gov ID</th>
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
            <td><?= val($r['bdate']) ?></td>
            <td><?= val($r['age']) ?></td>
            <td><?= val($r['gender']) ?></td>
            <td class="text-truncate" style="max-width:150px" title="<?= htmlspecialchars($r['address']) ?>"><?= val($r['address']) ?></td>
            <td><?= val($r['mobile_number']) ?></td>
            <td class="text-truncate" style="max-width:150px" title="<?= htmlspecialchars($r['govid']) ?>"><?= val($r['govid']) ?></td>
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
    Rows highlighted in yellow have a warning (hover the ⚠ badge for details) but can still be ingested — review carefully first.
    Each row creates one <code>customer</code> row and one linked <code>top_ph_ekyc_details</code> row
    (linked via a generated request user id, not a numeric key).
  </div>

  <form method="post">
    <input type="hidden" name="confirm" value="1">
    <textarea name="payload" style="display:none"><?= htmlspecialchars(json_encode($rows), ENT_QUOTES, 'UTF-8') ?></textarea>
    <button type="submit" class="btn btn-success" onclick="return confirm('Ingest <?= count($rows) ?> customer(s) into the database?');">
      Confirm &amp; Ingest <?= count($rows) ?> Row(s)
    </button>
    <a href="import_customer.php" class="btn btn-outline-secondary">Cancel</a>
  </form>

<?php elseif ($mode === 'ingest'): ?>

  <?php
    $successCount = count(array_filter($results, fn($r) => $r['ok']));
    $failCount    = count($results) - $successCount;
  ?>

  <div class="alert <?= $failCount > 0 ? 'alert-warning' : 'alert-success' ?>">
    Ingested <strong><?= $successCount ?></strong> customer(s) successfully.
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
          <th>Result</th>
          <th>Details</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($results as $r): ?>
          <tr>
            <td><?= $r['row'] ?></td>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td>
              <?php if ($r['ok']): ?>
                <span class="badge bg-success">Success</span>
              <?php else: ?>
                <span class="badge bg-danger">Failed</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($r['ok']): ?>
                <a href="customer_show.php?id=<?= (int)$r['customer_id'] ?>">View customer #<?= (int)$r['customer_id'] ?></a>
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
    <a href="import_customer.php" class="btn btn-outline-secondary">Import Another File</a>
    <a href="index.php" class="btn btn-primary">Go to Customers</a>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
