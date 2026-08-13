<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/customer_ingest.php';
$tabTitle = 'Add Passenger';

// Same shape as import_customer.php's CSV pipeline (upload -> preview ->
// confirm) but for a single manually-typed record, sharing the same field
// set, code sequence, and insert logic via includes/customer_ingest.php —
// so a customer registered here looks identical to one brought in by CSV.

$mode       = 'form';
$errorMsg   = '';
$formErrors = [];
$mapped     = null;

$firstName = trim($_POST['first_name'] ?? '');
$lastName  = trim($_POST['last_name'] ?? '');
$mobile    = trim($_POST['mobile'] ?? '');
$gender    = trim($_POST['gender'] ?? '');
$address   = trim($_POST['permanent_address'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm']) && $_POST['confirm'] === '1') {
    // -----------------------------------------------------------
    // Step 3: confirmed — insert
    // -----------------------------------------------------------
    $mapped = validate_customer_fields($firstName, $lastName, $mobile, $gender, $address);

    if (in_array('Missing first name', $mapped['issues'], true)
        || in_array('Missing last name', $mapped['issues'], true)
        || $mapped['mobile_no'] === ''
    ) {
        $errorMsg = 'Required fields are missing — please start over.';
        $mode = 'form';
    } else {
        try {
            $pdo = get_pdo();

            if (customer_exists_by_mobile($pdo, $mapped['mobile_no'])) {
                $errorMsg = 'A customer with this mobile number already exists.';
                $mode = 'form';
            } else {
                $createdAt = date('Y-m-d H:i:s');
                $seq       = next_customer_code_seq($pdo);
                $inserted  = insert_customer_record($pdo, $mapped, $createdAt, $seq);

                header('Location: customer_show.php?id=' . $inserted['customer_id'] . '&created=1');
                exit;
            }
        } catch (PDOException $e) {
            $errorMsg = 'Insert failed: ' . $e->getMessage();
            $mode = 'form';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // -----------------------------------------------------------
    // Step 2: validate & show a preview before touching the database
    // -----------------------------------------------------------
    $mapped = validate_customer_fields($firstName, $lastName, $mobile, $gender, $address);

    if (in_array('Missing first name', $mapped['issues'], true)) $formErrors[] = 'First name is required.';
    if (in_array('Missing last name', $mapped['issues'], true)) $formErrors[] = 'Last name is required.';
    if ($mapped['mobile_no'] === '') $formErrors[] = 'Mobile number is required.';

    if (empty($formErrors)) {
        $mode = 'preview';
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

<h4 class="mb-3">New Customer</h4>

<?php if ($errorMsg !== ''): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>

<?php if ($mode === 'form'): ?>

  <div class="row justify-content-center">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header bg-white fw-semibold">Customer Details</div>
        <div class="card-body">
          <?php if (!empty($formErrors)): ?>
            <div class="alert alert-danger">
              <?php foreach ($formErrors as $err): ?>
                <div><?= htmlspecialchars($err) ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <form method="post" class="row g-3">
            <div class="col-md-6">
              <label class="form-label">First Name *</label>
              <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($firstName) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Last Name *</label>
              <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($lastName) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Mobile *</label>
              <input type="text" name="mobile" class="form-control" placeholder="09xxxxxxxxx" value="<?= htmlspecialchars($mobile) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Gender</label>
              <select name="gender" class="form-select">
                <option value="" <?= $gender === '' ? 'selected' : '' ?>>—</option>
                <option value="Male" <?= $gender === 'Male' ? 'selected' : '' ?>>Male</option>
                <option value="Female" <?= $gender === 'Female' ? 'selected' : '' ?>>Female</option>
                <option value="Other" <?= $gender === 'Other' ? 'selected' : '' ?>>Other</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Permanent Address</label>
              <textarea name="permanent_address" class="form-control" rows="2"><?= htmlspecialchars($address) ?></textarea>
            </div>

            <div class="col-12 d-flex gap-2">
              <button type="submit" class="btn btn-primary">Preview</button>
              <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

<?php elseif ($mode === 'preview'): ?>

  <div class="row justify-content-center">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header bg-white fw-semibold">Review before registering</div>
        <div class="card-body">

          <?php if (!empty($mapped['issues'])): ?>
            <div class="alert alert-warning">
              <?php foreach ($mapped['issues'] as $issue): ?>
                <div>&#9888; <?= htmlspecialchars($issue) ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <table class="table table-sm mb-3">
            <tr><th style="width:40%">First Name</th><td><?= val($mapped['first_name']) ?></td></tr>
            <tr><th>Last Name</th><td><?= val($mapped['last_name']) ?></td></tr>
            <tr><th>Mobile</th><td><?= val($mapped['mobile_no']) ?></td></tr>
            <tr><th>Gender</th><td><?= val($mapped['gender']) ?></td></tr>
            <tr><th>Permanent Address</th><td><?= val($mapped['permanent_address']) ?></td></tr>
          </table>

          <p class="text-muted small">
            This creates one <code>customer</code> row and one linked <code>top_ph_ekyc_details</code> row,
            using the same code sequence as CSV imports.
          </p>

          <form method="post" class="d-flex gap-2">
            <input type="hidden" name="confirm" value="1">
            <input type="hidden" name="first_name" value="<?= htmlspecialchars($firstName) ?>">
            <input type="hidden" name="last_name" value="<?= htmlspecialchars($lastName) ?>">
            <input type="hidden" name="mobile" value="<?= htmlspecialchars($mobile) ?>">
            <input type="hidden" name="gender" value="<?= htmlspecialchars($gender) ?>">
            <input type="hidden" name="permanent_address" value="<?= htmlspecialchars($address) ?>">
            <button type="submit" class="btn btn-success">Confirm &amp; Register</button>
            <a href="customer_create.php" class="btn btn-outline-secondary">Start Over</a>
          </form>
        </div>
      </div>
    </div>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
