<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$walletId = (int)($_GET['wallet_id'] ?? $_POST['wallet_id'] ?? 0);
$riderId  = (int)($_GET['rider_id'] ?? $_POST['rider_id'] ?? 0);
$wallet   = null;
$rider    = null;
$errorMsg = '';
$formErrors = [];

$amount      = trim($_POST['amount'] ?? '');
$description = trim($_POST['description'] ?? '');

if ($walletId <= 0 && $riderId <= 0) {
    $errorMsg = 'Invalid wallet or rider id.';
} else {
    try {
        $pdo = get_pdo();

        if ($walletId > 0) {
            $walletStmt = $pdo->prepare(
                "SELECT w.*, r.first_name AS rider_fname, r.last_name AS rider_lname, r.code AS rider_code
                 FROM public.wallet w
                 LEFT JOIN public.riders r ON r.id = w.user_id AND w.user_type = 'rider'
                 WHERE w.id = :wallet_id
                 LIMIT 1"
            );
            $walletStmt->bindValue(':wallet_id', $walletId, PDO::PARAM_INT);
            $walletStmt->execute();
            $wallet = $walletStmt->fetch();

            if (!$wallet) {
                $errorMsg = 'Wallet not found.';
            } else {
                $riderId = (int)$wallet['user_id'];
            }
        } else {
            // No wallet yet — look up the rider directly so we still have
            // something to show/validate against; the wallet gets created
            // on submit.
            $riderStmt = $pdo->prepare(
                "SELECT id, code, first_name, last_name FROM public.riders WHERE id = :id LIMIT 1"
            );
            $riderStmt->bindValue(':id', $riderId, PDO::PARAM_INT);
            $riderStmt->execute();
            $rider = $riderStmt->fetch();

            if (!$rider) {
                $errorMsg = 'Rider not found.';
            }
        }
    } catch (PDOException $e) {
        $errorMsg = 'Query failed: ' . $e->getMessage();
    }
}

if ($errorMsg === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($amount === '' || !is_numeric($amount) || (float)$amount <= 0) {
        $formErrors[] = 'Enter a valid credit amount greater than 0.';
    }

    if (empty($formErrors)) {
        $pdo->beginTransaction();
        try {
            if (!$wallet) {
                // Rider has no wallet record yet — create one first.
                $walletRefCode = 'WL-' . strtoupper(bin2hex(random_bytes(5)));

                $createWalletStmt = $pdo->prepare(
                    "INSERT INTO public.wallet
                        (ref_code, user_id, user_type, type, avail_balance, credit_amount, debit_amount, status, created_at, updated_at)
                     VALUES
                        (:ref_code, :user_id, 'rider', 'user-wallet', 0, 0, 0, 1, NOW(), NOW())
                     RETURNING id"
                );
                $createWalletStmt->bindValue(':ref_code', $walletRefCode);
                $createWalletStmt->bindValue(':user_id', $riderId, PDO::PARAM_INT);
                $createWalletStmt->execute();
                $walletId = (int)$createWalletStmt->fetchColumn();
            }

            $refCode = 'CI-' . strtoupper(bin2hex(random_bytes(5)));

            $insertStmt = $pdo->prepare(
                "INSERT INTO public.wallet_history
                    (type, credit_amount, debit_amount, time_created, date_created,
                     status, created_at, updated_at, wallet_id,
                     creditor_account_number, description, tran_type)
                 VALUES
                    (:type, :credit_amount, 0, :time_created, :date_created,
                     1, NOW(), NOW(), :wallet_id,
                     :ref_code, :description, :tran_type)"
            );
            $insertStmt->bindValue(':type', 'cash_in');
            $insertStmt->bindValue(':credit_amount', (float)$amount);
            $insertStmt->bindValue(':time_created', date('H:i:s'));
            $insertStmt->bindValue(':date_created', date('Y-m-d'));
            $insertStmt->bindValue(':wallet_id', $walletId, PDO::PARAM_INT);
            $insertStmt->bindValue(':ref_code', $refCode);
            $insertStmt->bindValue(':description', $description !== '' ? $description : 'Manual cash-in by admin');
            $insertStmt->bindValue(':tran_type', 'cash_in');
            $insertStmt->execute();

            $pdo->commit();

            header('Location: rider_show.php?id=' . $riderId . '&credit_added=1');
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errorMsg = 'Insert failed: ' . $e->getMessage();
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
  <?php if ($riderId > 0): ?>
    <a href="rider_show.php?id=<?= (int)$riderId ?>" class="btn btn-sm btn-outline-secondary">&laquo; Back to Rider</a>
  <?php else: ?>
    <a href="riders.php" class="btn btn-sm btn-outline-secondary">&laquo; Back to Riders</a>
  <?php endif; ?>
</div>

<?php if ($errorMsg !== ''): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php else: ?>

  <div class="row justify-content-center">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header bg-white fw-semibold">Add Credit</div>
        <div class="card-body">
          <?php if ($wallet): ?>
            <p class="text-muted">
              Wallet <strong><?= val($wallet['ref_code']) ?></strong>
              <?php if ($wallet['rider_fname']): ?>
                &mdash; <?= val(trim($wallet['rider_fname'] . ' ' . $wallet['rider_lname'])) ?>
              <?php endif; ?>
            </p>
          <?php else: ?>
            <p class="text-muted">
              <?= val(trim(($rider['first_name'] ?? '') . ' ' . ($rider['last_name'] ?? ''))) ?>
              (<?= val($rider['code'] ?? null) ?>) has no wallet yet — one will be created automatically when you submit this form.
            </p>
          <?php endif; ?>

          <?php if (!empty($formErrors)): ?>
            <div class="alert alert-danger">
              <?php foreach ($formErrors as $err): ?>
                <div><?= htmlspecialchars($err) ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <form method="post" class="row g-3">
            <input type="hidden" name="wallet_id" value="<?= (int)$walletId ?>">
            <input type="hidden" name="rider_id" value="<?= (int)$riderId ?>">
            <div class="col-12">
              <label class="form-label">Amount (₱)</label>
              <input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="0.00" value="<?= htmlspecialchars($amount) ?>" required>
            </div>
            <div class="col-12">
              <label class="form-label">Description (optional)</label>
              <textarea name="description" class="form-control" rows="3" placeholder="Reason for this cash-in"><?= htmlspecialchars($description) ?></textarea>
            </div>
            <div class="col-12 d-flex gap-2">
              <button type="submit" class="btn btn-primary">Add Credit</button>
              <a href="rider_show.php?id=<?= (int)$riderId ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
