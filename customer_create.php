<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$errorMsg   = '';
$formErrors = [];

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

$firstName = trim($_POST['first_name'] ?? '');
$lastName  = trim($_POST['last_name'] ?? '');
$mobile    = trim($_POST['mobile'] ?? '');
$email     = trim($_POST['email'] ?? '');
$address   = trim($_POST['address'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($firstName === '') $formErrors[] = 'First name is required.';
    if ($lastName === '') $formErrors[] = 'Last name is required.';
    if ($mobile === '') $formErrors[] = 'Mobile number is required.';

    if (empty($formErrors)) {
        try {
            $pdo = get_pdo();
            $pdo->beginTransaction();

            // customer has no `address` column — address (and other KYC-ish
            // details) live in top_ph_ekyc_details instead.
            $ekycRequestUserId = $mobile . random_chars(8);

            $stmt = $pdo->prepare(
                "INSERT INTO public.customer
                    (code, fname, lname, mobile, email, ekyc_request_user_id,
                     customer_type, status, is_verified, is_success_kyc, created_at, updated_at)
                 VALUES
                    (:code, :fname, :lname, :mobile, :email, :ekyc_request_user_id,
                     1, 1, 0, 1, NOW(), NOW())
                 RETURNING id"
            );
            $stmt->execute([
                ':code'                 => generate_customer_code(),
                ':fname'                => $firstName,
                ':lname'                => $lastName,
                ':mobile'               => $mobile,
                ':email'                => $email !== '' ? $email : null,
                ':ekyc_request_user_id' => $ekycRequestUserId,
            ]);
            $customerId = (int)$stmt->fetchColumn();

            $kycStmt = $pdo->prepare(
                "INSERT INTO public.top_ph_ekyc_details
                    (kyc_id, first_name, last_name, email_address, mobile_no, current_address,
                     status, generate_request_user_id, created_at, updated_at)
                 VALUES
                    (:kyc_id, :first_name, :last_name, :email_address, :mobile_no, :current_address,
                     0, :generate_request_user_id, NOW(), NOW())"
            );
            $kycStmt->execute([
                ':kyc_id'                   => random_chars(12),
                ':first_name'               => $firstName,
                ':last_name'                => $lastName,
                ':email_address'            => $email !== '' ? $email : null,
                ':mobile_no'                => $mobile,
                ':current_address'          => $address !== '' ? $address : null,
                ':generate_request_user_id' => $ekycRequestUserId,
            ]);

            $pdo->commit();

            header('Location: customer_show.php?id=' . $customerId . '&created=1');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errorMsg = 'Insert failed: ' . $e->getMessage();
        }
    }
}

$activeNav = 'customers';
require __DIR__ . '/includes/header.php';
?>

<div class="mb-3">
  <a href="index.php" class="btn btn-sm btn-outline-secondary">&laquo; Back to Customers</a>
</div>

<div class="row justify-content-center">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header bg-white fw-semibold">New Customer</div>
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
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($email) ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Address</label>
            <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($address) ?></textarea>
          </div>

          <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-primary">Create Customer</button>
            <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
