<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
$tabTitle = 'Edit Driver';

const IMG_MAX_LEN = 100;

// Same normalization rules as import_rider.php / sql/importing-process-manual/normalize_rider_mobile_numbers.sql.
function normalize_mobile_to_63(string $raw): array
{
    $digits = preg_replace('/\D/', '', $raw) ?? '';
    if ($digits === '') {
        return ['', true];
    }
    if (preg_match('/^63\d{10}$/', $digits)) {
        return [$digits, true];
    }
    if (preg_match('/^0\d{10}$/', $digits)) {
        return ['63' . substr($digits, 1), true];
    }
    if (preg_match('/^9\d{9}$/', $digits)) {
        return ['63' . $digits, true];
    }
    return [$digits, false];
}

function interpret_vehicle_type_select(string $raw): int
{
    return $raw === '1' ? 1 : 2;
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$rider = null;
$vehicle = null;
$address = null;
$ekyc = null;
$errorMsg = '';
$formErrors = [];

if ($id <= 0) {
    $errorMsg = 'Invalid rider id.';
} else {
    try {
        $pdo = get_pdo();

        $stmt = $pdo->prepare('SELECT * FROM public.riders WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $rider = $stmt->fetch();

        if (!$rider) {
            $errorMsg = 'Rider not found.';
        } else {
            if (!empty($rider['ekyc_request_user_id'])) {
                $ekycStmt = $pdo->prepare(
                    "SELECT * FROM public.top_ph_ekyc_details
                     WHERE generate_request_user_id = :req_id AND deleted_at IS NULL
                     ORDER BY created_at DESC LIMIT 1"
                );
                $ekycStmt->bindValue(':req_id', $rider['ekyc_request_user_id']);
                $ekycStmt->execute();
                $ekyc = $ekycStmt->fetch();
            }

            $vehStmt = $pdo->prepare(
                "SELECT id, v_type, v_brand, v_model, v_color, v_plate_number
                 FROM public.rider_vehicle_details
                 WHERE rider_id = :rider_id
                 ORDER BY created_at DESC LIMIT 1"
            );
            $vehStmt->bindValue(':rider_id', $id, PDO::PARAM_INT);
            $vehStmt->execute();
            $vehicle = $vehStmt->fetch();

            $addrStmt = $pdo->prepare(
                "SELECT id, address, barangay, municipality_city, province, zip_code
                 FROM public.rider_address
                 WHERE rider_id = :rider_id
                 ORDER BY created_at DESC LIMIT 1"
            );
            $addrStmt->bindValue(':rider_id', $id, PDO::PARAM_INT);
            $addrStmt->execute();
            $address = $addrStmt->fetch();
        }
    } catch (PDOException $e) {
        $errorMsg = 'Query failed: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName   = trim($_POST['first_name'] ?? '');
    $middleName  = trim($_POST['middle_name'] ?? '');
    $lastName    = trim($_POST['last_name'] ?? '');
    $mobileRaw   = trim($_POST['mobile_no'] ?? '');
    $email       = trim($_POST['email_address'] ?? '');
    $driversLicenseImg = trim($_POST['drivers_license_img'] ?? '');
    $licenseExpiry     = trim($_POST['drivers_license_no_expiration_date'] ?? '');
    $vType       = trim($_POST['v_type'] ?? '2');
    $vBrand      = trim($_POST['v_brand'] ?? '');
    $vModel      = trim($_POST['v_model'] ?? '');
    $vColor      = trim($_POST['v_color'] ?? '');
    $vPlateNumber = trim($_POST['v_plate_number'] ?? '');
    $addrLine    = trim($_POST['address'] ?? '');
    $barangay    = trim($_POST['barangay'] ?? '');
    $municipalityCity = trim($_POST['municipality_city'] ?? '');
    $province    = trim($_POST['province'] ?? '');
    $zipCode     = trim($_POST['zip_code'] ?? '');
} else {
    $firstName   = (string)($rider['first_name'] ?? '');
    $middleName  = (string)($rider['middle_name'] ?? '');
    $lastName    = (string)($rider['last_name'] ?? '');
    $mobileRaw   = (string)($rider['mobile_no'] ?? '');
    $email       = (string)($rider['email_address'] ?? '');
    $driversLicenseImg = (string)($rider['drivers_license_img'] ?? '');
    $rawExpiry = $rider['drivers_license_no_expiration_date'] ?? '';
    $expiryTs  = $rawExpiry !== '' ? strtotime((string)$rawExpiry) : false;
    $licenseExpiry = $expiryTs ? date('Y-m-d', $expiryTs) : '';
    $vType       = (string)($vehicle['v_type'] ?? '2');
    $vBrand      = (string)($vehicle['v_brand'] ?? '');
    $vModel      = (string)($vehicle['v_model'] ?? '');
    $vColor      = (string)($vehicle['v_color'] ?? '');
    $vPlateNumber = (string)($vehicle['v_plate_number'] ?? '');
    $addrLine    = (string)($address['address'] ?? '');
    $barangay    = (string)($address['barangay'] ?? '');
    $municipalityCity = (string)($address['municipality_city'] ?? '');
    $province    = (string)($address['province'] ?? '');
    $zipCode     = (string)($address['zip_code'] ?? '');
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
        $dupStmt = $pdo->prepare('SELECT 1 FROM public.riders WHERE mobile_no = :mobile_no AND id <> :id LIMIT 1');
        $dupStmt->execute([':mobile_no' => $mobileNo, ':id' => $id]);
        if ($dupStmt->fetchColumn() !== false) {
            $formErrors[] = 'Another rider already uses this mobile number.';
        }
    }

    if (empty($formErrors)) {
        $custDupStmt = $pdo->prepare('SELECT 1 FROM public.customer WHERE mobile = :mobile_no LIMIT 1');
        $custDupStmt->execute([':mobile_no' => $mobileNo]);
        if ($custDupStmt->fetchColumn() !== false) {
            $formErrors[] = 'This mobile number is already registered to a customer.';
        }
    }

    if (empty($formErrors)) {
        try {
            $pdo->beginTransaction();

            $updateStmt = $pdo->prepare(
                "UPDATE public.riders
                 SET first_name = :first_name, middle_name = :middle_name, last_name = :last_name,
                     mobile_no = :mobile_no, email_address = :email_address,
                     drivers_license_img = :drivers_license_img,
                     drivers_license_no_expiration_date = :expiry,
                     updated_at = NOW()
                 WHERE id = :id"
            );
            $updateStmt->execute([
                ':first_name'          => $firstName,
                ':middle_name'         => $middleName !== '' ? $middleName : null,
                ':last_name'           => $lastName,
                ':mobile_no'           => $mobileNo,
                ':email_address'       => $email !== '' ? $email : null,
                ':drivers_license_img' => $driversLicenseImg,
                ':expiry'              => $expiryValue,
                ':id'                  => $id,
            ]);

            if ($vehicle) {
                $vehUpdStmt = $pdo->prepare(
                    "UPDATE public.rider_vehicle_details
                     SET v_type = :v_type, v_brand = :v_brand, v_model = :v_model,
                         v_color = :v_color, v_plate_number = :v_plate_number, updated_at = NOW()
                     WHERE id = :veh_id"
                );
                $vehUpdStmt->execute([
                    ':v_type'         => interpret_vehicle_type_select($vType),
                    ':v_brand'        => $vBrand,
                    ':v_model'        => $vModel,
                    ':v_color'        => $vColor,
                    ':v_plate_number' => $vPlateNumber,
                    ':veh_id'         => $vehicle['id'],
                ]);
            } else {
                $vehInsStmt = $pdo->prepare(
                    "INSERT INTO public.rider_vehicle_details
                        (rider_id, v_type, v_brand, v_model, v_color, v_plate_number, status, created_at, updated_at)
                     VALUES
                        (:rider_id, :v_type, :v_brand, :v_model, :v_color, :v_plate_number, 1, NOW(), NOW())"
                );
                $vehInsStmt->execute([
                    ':rider_id'       => $id,
                    ':v_type'         => interpret_vehicle_type_select($vType),
                    ':v_brand'        => $vBrand,
                    ':v_model'        => $vModel,
                    ':v_color'        => $vColor,
                    ':v_plate_number' => $vPlateNumber,
                ]);
            }

            if ($address) {
                $addrUpdStmt = $pdo->prepare(
                    "UPDATE public.rider_address
                     SET address = :address, barangay = :barangay, municipality_city = :municipality_city,
                         province = :province, zip_code = :zip_code, updated_at = NOW()
                     WHERE id = :addr_id"
                );
                $addrUpdStmt->execute([
                    ':address'            => $addrLine,
                    ':barangay'           => $barangay,
                    ':municipality_city'  => $municipalityCity,
                    ':province'           => $province,
                    ':zip_code'           => $zipCode,
                    ':addr_id'            => $address['id'],
                ]);
            } else {
                $addrInsStmt = $pdo->prepare(
                    "INSERT INTO public.rider_address
                        (rider_id, status, address, barangay, municipality_city, province, zip_code, created_at, updated_at)
                     VALUES
                        (:rider_id, 1, :address, :barangay, :municipality_city, :province, :zip_code, NOW(), NOW())"
                );
                $addrInsStmt->execute([
                    ':rider_id'           => $id,
                    ':address'            => $addrLine,
                    ':barangay'           => $barangay,
                    ':municipality_city'  => $municipalityCity,
                    ':province'           => $province,
                    ':zip_code'           => $zipCode,
                ]);
            }

            if ($ekyc) {
                $ekycUpdStmt = $pdo->prepare(
                    "UPDATE public.top_ph_ekyc_details
                     SET first_name = :first_name, middle_name = :middle_name, last_name = :last_name,
                         email_address = :email_address, mobile_no = :mobile_no, pretty_mobile_no = :pretty_mobile_no,
                         current_address = :current_address, permanent_address = :permanent_address,
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
                    ':current_address'   => $addrLine,
                    ':permanent_address' => $addrLine,
                    ':req_id'            => $rider['ekyc_request_user_id'],
                ]);
            }

            $pdo->commit();

            header('Location: rider_show.php?id=' . $id . '&updated=1');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
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

<?php if ($errorMsg !== '' && !$rider): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php else: ?>

  <div class="row justify-content-center">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header bg-white fw-semibold">
          Edit Rider &mdash; <?= val(trim(($rider['first_name'] ?? '') . ' ' . ($rider['last_name'] ?? ''))) ?>
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

          <form method="post" class="row g-3">
            <input type="hidden" name="id" value="<?= (int)$id ?>">

            <div class="col-12"><h6 class="text-muted mb-0">Personal Info</h6></div>
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
              <input type="text" name="mobile_no" class="form-control" placeholder="09xxxxxxxxx" value="<?= htmlspecialchars($mobileRaw) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="email" name="email_address" class="form-control" value="<?= htmlspecialchars($email) ?>">
            </div>

            <div class="col-12 mt-4"><h6 class="text-muted mb-0">License Info</h6></div>
            <div class="col-md-6">
              <label class="form-label">Driver's License Image</label>
              <input type="text" name="drivers_license_img" class="form-control" maxlength="<?= IMG_MAX_LEN ?>" value="<?= htmlspecialchars($driversLicenseImg) ?>">
              <div class="form-text">Max <?= IMG_MAX_LEN ?> characters (filename/path).</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Driver's License Expiration Date</label>
              <input type="date" name="drivers_license_no_expiration_date" class="form-control" value="<?= htmlspecialchars($licenseExpiry) ?>">
            </div>

            <div class="col-12 mt-4"><h6 class="text-muted mb-0">Vehicle Details</h6></div>
            <div class="col-md-3">
              <label class="form-label">Type</label>
              <select name="v_type" class="form-select">
                <option value="1" <?= $vType === '1' ? 'selected' : '' ?>>Motorcycle</option>
                <option value="2" <?= $vType !== '1' ? 'selected' : '' ?>>Other</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Brand</label>
              <input type="text" name="v_brand" class="form-control" value="<?= htmlspecialchars($vBrand) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Model</label>
              <input type="text" name="v_model" class="form-control" value="<?= htmlspecialchars($vModel) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Color</label>
              <input type="text" name="v_color" class="form-control" value="<?= htmlspecialchars($vColor) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Plate Number</label>
              <input type="text" name="v_plate_number" class="form-control" value="<?= htmlspecialchars($vPlateNumber) ?>">
            </div>

            <div class="col-12 mt-4"><h6 class="text-muted mb-0">Address Details</h6></div>
            <div class="col-12">
              <label class="form-label">Address</label>
              <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($addrLine) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Barangay</label>
              <input type="text" name="barangay" class="form-control" value="<?= htmlspecialchars($barangay) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">City/Municipality</label>
              <input type="text" name="municipality_city" class="form-control" value="<?= htmlspecialchars($municipalityCity) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Province</label>
              <input type="text" name="province" class="form-control" value="<?= htmlspecialchars($province) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Zip Code</label>
              <input type="text" name="zip_code" class="form-control" value="<?= htmlspecialchars($zipCode) ?>">
            </div>

            <div class="col-12 d-flex gap-2 mt-4">
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
