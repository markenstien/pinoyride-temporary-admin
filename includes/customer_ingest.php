<?php
declare(strict_types=1);

// Shared customer ingestion logic used by both import_customer.php (bulk CSV
// import) and customer_create.php (single manual registration), so a
// customer created either way ends up structurally identical — same code
// sequence, same ekyc_request_user_id format, same linked eKYC row.

// Normalize a mobile number to the canonical '63' + 10-digit format, same
// rules as sql/importing-process-manual/normalize_rider_mobile_numbers.sql.
// Returns [normalized_or_best_effort, recognized] — recognized = false means
// the value didn't fit a known shape and was left as digits-only for review.
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

function random_chars(int $length): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

// referral_code: 10 chars, uppercase letters + digits (e.g. 'IJVGCQC08P').
function generate_referral_code(): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $out = '';
    for ($i = 0; $i < 10; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

// CUST-{YY}{MM}-{N}: YY/MM from the customer's own created_at (registration
// month), N continues from the highest existing sequence number so far —
// same pattern as riders' DRN-{YY}{MM}-{N} code.
function next_customer_code_seq(PDO $pdo): int
{
    $stmt = $pdo->query(
        "SELECT COALESCE(MAX(NULLIF(regexp_replace(split_part(code, '-', 3), '\\D', '', 'g'), '')::int), 0)
         FROM public.customer
         WHERE code LIKE 'CUST-%'"
    );
    return (int)$stmt->fetchColumn() + 1;
}

function generate_customer_code(string $createdAt, int $seq): string
{
    $ts = strtotime($createdAt) ?: time();
    return sprintf('CUST-%s%s-%d', date('y', $ts), date('m', $ts), $seq);
}

function customer_exists_by_mobile(PDO $pdo, string $mobile): bool
{
    if ($mobile === '') return false;
    $stmt = $pdo->prepare('SELECT 1 FROM public.customer WHERE mobile = :mobile LIMIT 1');
    $stmt->execute([':mobile' => $mobile]);
    return $stmt->fetchColumn() !== false;
}

// Validate + normalize a customer's core fields. Shared by CSV row mapping
// (import_customer.php) and the manual registration form (customer_create.php).
function validate_customer_fields(string $firstName, string $lastName, string $mobileRaw, string $gender, string $permanentAddress): array
{
    [$mobileNo, $mobileRecognized] = normalize_mobile_to_63($mobileRaw);

    $mapped = [
        'first_name'        => trim($firstName),
        'last_name'         => trim($lastName),
        'mobile_no'         => $mobileNo,
        'mobile_recognized' => $mobileRecognized,
        'gender'            => trim($gender),
        'permanent_address' => trim($permanentAddress),
    ];

    $issues = [];
    if ($mapped['first_name'] === '') $issues[] = 'Missing first name';
    if ($mapped['last_name'] === '') $issues[] = 'Missing last name';
    if ($mapped['mobile_no'] === '') {
        $issues[] = 'Missing mobile number';
    } elseif (!$mapped['mobile_recognized']) {
        $issues[] = 'Mobile number format not recognized — stored as digits-only, please review';
    }
    $mapped['issues'] = $issues;

    return $mapped;
}

// Insert one customer + its linked top_ph_ekyc_details row inside a single
// transaction. $mapped is the output of validate_customer_fields() (or an
// equivalent array with the same keys) and must have a non-empty mobile_no.
// Returns ['customer_id' => int, 'code' => string]. Throws PDOException on
// failure (transaction is rolled back before rethrowing).
//
// $manageTransaction: pass false when the caller already has an open
// transaction of its own (e.g. booking_create.php wrapping a new-passenger
// insert together with the booking/payment inserts) — PDO doesn't support
// nested transactions, so a second beginTransaction() would throw "There is
// already an active transaction". In that case the caller owns commit/rollback.
function insert_customer_record(PDO $pdo, array $mapped, string $createdAt, int $seq, bool $manageTransaction = true): array
{
    $code              = generate_customer_code($createdAt, $seq);
    $referralCode      = generate_referral_code();
    $ekycRequestUserId = $mapped['mobile_no'] . '-' . $code;

    try {
        if ($manageTransaction) {
            $pdo->beginTransaction();
        }

        // customer_type/status/is_verified/is_success_kyc are literal
        // constants (not bound) so Postgres can coerce them into
        // customer_type's varchar(6) column the same way the original
        // import script did.
        $custStmt = $pdo->prepare(
            "INSERT INTO public.customer
                (code, fname, lname, mobile, customer_type, status, login_type,
                 is_verified, is_success_kyc, referral_code, ekyc_request_user_id,
                 updated_by, created_at, updated_at)
             VALUES
                (:code, :fname, :lname, :mobile, 1, 1, :login_type,
                 0, 1, :referral_code, :ekyc_request_user_id,
                 :updated_by, :created_at, :updated_at)
             RETURNING id"
        );
        $custStmt->execute([
            ':code'                 => $code,
            ':fname'                => $mapped['first_name'],
            ':lname'                => $mapped['last_name'],
            ':mobile'               => $mapped['mobile_no'],
            ':login_type'           => 'mobile_number',
            ':referral_code'        => $referralCode,
            ':ekyc_request_user_id' => $ekycRequestUserId,
            ':updated_by'           => 1,
            ':created_at'           => $createdAt,
            ':updated_at'           => $createdAt,
        ]);
        $customerId = (int)$custStmt->fetchColumn();

        $kycStmt = $pdo->prepare(
            "INSERT INTO public.top_ph_ekyc_details
                (kyc_id, first_name, last_name, gender, mobile_no, pretty_mobile_no,
                 permanent_address, status, generate_request_user_id, created_at, updated_at)
             VALUES
                (:kyc_id, :first_name, :last_name, :gender, :mobile_no, :pretty_mobile_no,
                 :permanent_address, 0, :generate_request_user_id, :created_at, :updated_at)"
        );
        $kycStmt->execute([
            ':kyc_id'                   => random_chars(12),
            ':first_name'               => $mapped['first_name'],
            ':last_name'                => $mapped['last_name'],
            ':gender'                   => $mapped['gender'],
            ':mobile_no'                => $mapped['mobile_no'],
            ':pretty_mobile_no'         => $mapped['mobile_no'],
            ':permanent_address'        => $mapped['permanent_address'],
            ':generate_request_user_id' => $ekycRequestUserId,
            ':created_at'               => $createdAt,
            ':updated_at'               => $createdAt,
        ]);

        if ($manageTransaction) {
            $pdo->commit();
        }
    } catch (PDOException $e) {
        if ($manageTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return ['customer_id' => $customerId, 'code' => $code];
}
