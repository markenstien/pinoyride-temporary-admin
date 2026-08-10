<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/customer_ingest.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$customer   = null;
$ekyc       = null;
$errorMsg   = '';
$formErrors = [];

if ($id <= 0) {
    $errorMsg = 'Invalid customer id.';
} else {
    try {
        $pdo = get_pdo();

        $stmt = $pdo->prepare('SELECT * FROM public.customer WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $customer = $stmt->fetch();

        if (!$customer) {
            $errorMsg = 'Customer not found.';
        } elseif (!empty($customer['ekyc_request_user_id'])) {
            $ekycStmt = $pdo->prepare(
                "SELECT * FROM public.top_ph_ekyc_details
                 WHERE generate_request_user_id = :req_id AND deleted_at IS NULL
                 ORDER BY created_at DESC LIMIT 1"
            );
            $ekycStmt->bindValue(':req_id', $customer['ekyc_request_user_id']);
            $ekycStmt->execute();
            $ekyc = $ekycStmt->fetch();
        }
    } catch (PDOException $e) {
        $errorMsg = 'Query failed: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName  = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName   = trim($_POST['last_name'] ?? '');
    $mobileRaw  = trim($_POST['mobile'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $gender     = trim($_POST['gender'] ?? '');
    $address    = trim($_POST['permanent_address'] ?? '');
} else {
    $firstName  = (string)($customer['fname'] ?? '');
    $middleName = (string)($customer['mname'] ?? '');
    $lastName   = (string)($customer['lname'] ?? '');
    $mobileRaw  = (string)($customer['mobile'] ?? '');
    $email      = (string)($customer['email'] ?? '');
    $gender     = (string)($ekyc['gender'] ?? '');
    $address    = (string)($ekyc['permanent_address'] ?? '');
}

if ($errorMsg === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    [$mobileNo, $mobileRecognized] = normalize_mobile_to_63($mobileRaw);

    if ($firstName === '') $formErrors[] = 'First name is required.';
    if ($lastName === '') $formErrors[] = 'Last name is required.';
    if ($mobileNo === '') {
        $formErrors[] = 'Mobile number is required.';
    } elseif (!$mobileRecognized) {
        $formErrors[] = 'Mobile number format not recognized — expected 09xxxxxxxxx, 9xxxxxxxxx, or 63xxxxxxxxxx.';
    }

    if (empty($formErrors)) {
        $dupStmt = $pdo->prepare('SELECT 1 FROM public.customer WHERE mobile = :mobile AND id <> :id LIMIT 1');
        $dupStmt->execute([':mobile' => $mobileNo, ':id' => $id]);
        if ($dupStmt->fetchColumn() !== false) {
            $formErrors[] = 'Another customer already uses this mobile number.';
        }
    }

    if (empty($formErrors)) {
        $riderDupStmt = $pdo->prepare('SELECT 1 FROM public.riders WHERE mobile_no = :mobile LIMIT 1');
        $riderDupStmt->execute([':mobile' => $mobileNo]);
        if ($riderDupStmt->fetchColumn() !== false) {
            $formErrors[] = 'This mobile number is already registered to a driver.';
        }
    }

    if (empty($formErrors)) {
        try {
            $pdo->beginTransaction();

            $updStmt = $pdo->prepare(
                "UPDATE public.customer
                 SET fname = :fname, mname = :mname, lname = :lname,
                     mobile = :mobile, email = :email, updated_at = NOW()
                 WHERE id = :id"
            );
            $updStmt->execute([
                ':fname'  => $firstName,
                ':mname'  => $middleName !== '' ? $middleName : null,
                ':lname'  => $lastName,
                ':mobile' => $mobileNo,
                ':email'  => $email !== '' ? $email : null,
                ':id'     => $id,
            ]);

            if ($ekyc) {
                $ekycUpdStmt = $pdo->prepare(
                    "UPDATE public.top_ph_ekyc_details
                     SET first_name = :first_name, middle_name = :middle_name, last_name = :last_name,
                         email_address = :email_address, mobile_no = :mobile_no, pretty_mobile_no = :pretty_mobile_no,
                         gender = :gender, permanent_address = :permanent_address,
                         updated_at = NOW()
                     WHERE generate_request_user_id = :req_id"
                );
                $ekycUpdStmt->execute([
                    ':first_name'        => $firstName,
                    ':middle_name'       => $middleName !== '' ? $middleName : null,
                    ':last_name'         => $lastName,
                    ':email_address'     => $email !== '' ? $email : null,
                    ':mobile_no'         => $mobileNo,
                    ':pretty_mobile_no'  => $mobileNo,
                    ':gender'            => $gender,
                    ':permanent_address' => $address,
                    ':req_id'            => $customer['ekyc_request_user_id'],
                ]);
            }

            $pdo->commit();

            header('Location: customer_show.php?id=' . $id . '&updated=1');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errorMsg = 'Update failed: ' . $e->getMessage();
        }
    }
}

$activeNav = 'customers';
require __DIR__ . '/includes/header.php';
?>

<div class="mb-3">
  <a href="customer_show.php?id=<?= (int)$id ?>" class="btn btn-sm btn-outline-secondary">&laquo; Back to Customer</a>
</div>

<?php if ($errorMsg !== '' && !$customer): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php else: ?>

  <div class="row justify-content-center">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header bg-white fw-semibold">
          Edit Customer &mdash; <?= htmlspecialchars(trim(($customer['fname'] ?? '') . ' ' . ($customer['lname'] ?? ''))) ?>
        </div>
        <div class="card-body">
          <?php if ($errorMsg !== ''): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
          <?php endif; ?>

          <?php if (!empty($formErrors)): ?>
            <div class="alert alert-danger">
              <?php foreach ($formErrors as $err): ?>
                <div><?= htmlspecialchars($err) ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if (!$ekyc): ?>
            <div class="alert alert-secondary small">
              This customer has no linked eKYC record — gender and permanent address aren't editable here.
            </div>
          <?php endif; ?>

          <form method="post" class="row g-3">
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <div class="col-md-4">
              <label class="form-label">First Name *</label>
              <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($firstName) ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Middle Name</label>
              <input type="text" name="middle_name" class="form-control" value="<?= htmlspecialchars($middleName) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Last Name *</label>
              <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($lastName) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Mobile *</label>
              <input type="text" name="mobile" class="form-control" placeholder="09xxxxxxxxx" value="<?= htmlspecialchars($mobileRaw) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($email) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Gender</label>
              <select name="gender" class="form-select" <?= !$ekyc ? 'disabled' : '' ?>>
                <option value="" <?= $gender === '' ? 'selected' : '' ?>>—</option>
                <option value="Male" <?= $gender === 'Male' ? 'selected' : '' ?>>Male</option>
                <option value="Female" <?= $gender === 'Female' ? 'selected' : '' ?>>Female</option>
                <option value="Other" <?= $gender === 'Other' ? 'selected' : '' ?>>Other</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Permanent Address</label>
              <textarea name="permanent_address" class="form-control" rows="2" <?= !$ekyc ? 'disabled' : '' ?>><?= htmlspecialchars($address) ?></textarea>
            </div>

            <div class="col-12 d-flex gap-2">
              <button type="submit" class="btn btn-primary">Save Changes</button>
              <a href="customer_show.php?id=<?= (int)$id ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
