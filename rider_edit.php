<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const IMG_MAX_LEN = 100;

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$rider = null;
$errorMsg = '';
$formErrors = [];

if ($id <= 0) {
    $errorMsg = 'Invalid rider id.';
} else {
    try {
        $pdo = get_pdo();

        $stmt = $pdo->prepare(
            "SELECT id, first_name, last_name, drivers_license_img, drivers_license_no_expiration_date
             FROM public.riders
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $rider = $stmt->fetch();

        if (!$rider) {
            $errorMsg = 'Rider not found.';
        }
    } catch (PDOException $e) {
        $errorMsg = 'Query failed: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $driversLicenseImg = trim($_POST['drivers_license_img'] ?? '');
    $licenseExpiry      = trim($_POST['drivers_license_no_expiration_date'] ?? '');
} else {
    $driversLicenseImg = (string)($rider['drivers_license_img'] ?? '');
    $rawExpiry = $rider['drivers_license_no_expiration_date'] ?? '';
    $expiryTs  = $rawExpiry !== '' ? strtotime((string)$rawExpiry) : false;
    $licenseExpiry = $expiryTs ? date('Y-m-d', $expiryTs) : '';
}

if ($errorMsg === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (strlen($driversLicenseImg) > IMG_MAX_LEN) {
        $formErrors[] = "Driver's license image value is " . strlen($driversLicenseImg) . ' characters — must be ' . IMG_MAX_LEN . ' or fewer.';
    }

    $expiryValue = null;
    if ($licenseExpiry !== '') {
        $ts = strtotime($licenseExpiry);
        if ($ts === false) {
            $formErrors[] = 'License expiration date is not a valid date.';
        } else {
            $expiryValue = date('Y-m-d', $ts);
        }
    }

    if (empty($formErrors)) {
        try {
            $updateStmt = $pdo->prepare(
                "UPDATE public.riders
                 SET drivers_license_img = :drivers_license_img,
                     drivers_license_no_expiration_date = :expiry,
                     updated_at = NOW()
                 WHERE id = :id"
            );
            $updateStmt->bindValue(':drivers_license_img', $driversLicenseImg);
            $updateStmt->bindValue(':expiry', $expiryValue);
            $updateStmt->bindValue(':id', $id, PDO::PARAM_INT);
            $updateStmt->execute();

            header('Location: rider_show.php?id=' . $id . '&updated=1');
            exit;
        } catch (PDOException $e) {
            $errorMsg = 'Update failed: ' . $e->getMessage();
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
  <a href="rider_show.php?id=<?= (int)$id ?>" class="btn btn-sm btn-outline-secondary">&laquo; Back to Rider</a>
</div>

<?php if ($errorMsg !== ''): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php else: ?>

  <div class="row justify-content-center">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header bg-white fw-semibold">
          Edit Rider &mdash; <?= val(trim(($rider['first_name'] ?? '') . ' ' . ($rider['last_name'] ?? ''))) ?>
        </div>
        <div class="card-body">
          <?php if (!empty($formErrors)): ?>
            <div class="alert alert-danger">
              <?php foreach ($formErrors as $err): ?>
                <div><?= htmlspecialchars($err) ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <form method="post" class="row g-3">
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <div class="col-12">
              <label class="form-label">Driver's License Image</label>
              <input type="text" name="drivers_license_img" class="form-control" maxlength="<?= IMG_MAX_LEN ?>" value="<?= htmlspecialchars($driversLicenseImg) ?>">
              <div class="form-text">Max <?= IMG_MAX_LEN ?> characters (filename/path).</div>
            </div>
            <div class="col-12">
              <label class="form-label">Driver's License Expiration Date</label>
              <input type="date" name="drivers_license_no_expiration_date" class="form-control" value="<?= htmlspecialchars($licenseExpiry) ?>">
            </div>
            <div class="col-12 d-flex gap-2">
              <button type="submit" class="btn btn-primary">Save Changes</button>
              <a href="rider_show.php?id=<?= (int)$id ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
