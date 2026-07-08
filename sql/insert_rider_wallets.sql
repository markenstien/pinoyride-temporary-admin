-- Create a 'user-wallet' row in public.wallet for every DRN rider that
-- doesn't already have one.
--
-- user_id       = riders.id
-- user_type     = 'rider'
-- type          = 'user-wallet'   (the type rider_show.php actually reads)
-- avail_balance = 0, credit_amount = 0, debit_amount = 0, status = 0
-- ref_code      = next sequential 6-digit zero-padded code, continuing the
--                 existing numeric ref_code sequence (e.g. '000072' -> '000073', ...),
--                 assigned in rider id order.
--
-- Scope: riders with code LIKE '%DRN%', not deleted, that don't already
-- have a non-deleted 'user-wallet' row.
--
-- Insert-only (no upsert): a rider can legitimately have more than one
-- wallet row (different types), so there's no natural per-rider conflict
-- key to upsert on. Re-running this is still safe/idempotent because the
-- NOT EXISTS check skips riders that already got a wallet from a prior run.

-- ---------------------------------------------------------------
-- 1. PREVIEW: run this first. Shows every rider that will get a new
--    wallet row, and the exact ref_code it will be assigned.
--    Nothing is changed by running this.
-- ---------------------------------------------------------------
WITH target_riders AS (
  SELECT r.id AS rider_id, r.code, r.mobile_no,
         ROW_NUMBER() OVER (ORDER BY r.id) AS rn
  FROM public.riders r
  WHERE r.deleted_at IS NULL
    AND r.code LIKE '%DRN%'
    AND NOT EXISTS (
      SELECT 1 FROM public.wallet w
      WHERE w.user_id = r.id
        AND w.user_type = 'rider'
        AND w.type = 'user-wallet'
        AND w.deleted_at IS NULL
    )
),
next_ref AS (
  SELECT COALESCE(MAX(ref_code::bigint), 0) AS max_ref
  FROM public.wallet
  WHERE ref_code ~ '^[0-9]+$'
)
SELECT
  tr.rider_id,
  tr.code,
  tr.mobile_no,
  lpad((next_ref.max_ref + tr.rn)::text, 6, '0') AS new_ref_code,
  0 AS avail_balance,
  0 AS credit_amount,
  0 AS debit_amount,
  0 AS status
FROM target_riders tr
CROSS JOIN next_ref
ORDER BY tr.rider_id;

-- ---------------------------------------------------------------
-- 2. INSERT: only run after reviewing the preview above.
-- ---------------------------------------------------------------
WITH target_riders AS (
  SELECT r.id AS rider_id,
         ROW_NUMBER() OVER (ORDER BY r.id) AS rn
  FROM public.riders r
  WHERE r.deleted_at IS NULL
    AND r.code LIKE '%DRN%'
    AND NOT EXISTS (
      SELECT 1 FROM public.wallet w
      WHERE w.user_id = r.id
        AND w.user_type = 'rider'
        AND w.type = 'user-wallet'
        AND w.deleted_at IS NULL
    )
),
next_ref AS (
  SELECT COALESCE(MAX(ref_code::bigint), 0) AS max_ref
  FROM public.wallet
  WHERE ref_code ~ '^[0-9]+$'
)
INSERT INTO public.wallet (
  ref_code, user_id, user_type, type,
  avail_balance, credit_amount, debit_amount,
  status, created_at, updated_at
)
SELECT
  lpad((next_ref.max_ref + tr.rn)::text, 6, '0'),
  tr.rider_id,
  'rider',
  'user-wallet',
  0, 0, 0,
  0, NOW(), NOW()
FROM target_riders tr
CROSS JOIN next_ref;

-- ---------------------------------------------------------------
-- 3. VERIFY: run after the INSERT. Should return 0 rows
--    (i.e. no DRN rider is left without a user-wallet row).
-- ---------------------------------------------------------------
SELECT r.id AS rider_id, r.code, r.mobile_no
FROM public.riders r
WHERE r.deleted_at IS NULL
  AND r.code LIKE '%DRN%'
  AND NOT EXISTS (
    SELECT 1 FROM public.wallet w
    WHERE w.user_id = r.id
      AND w.user_type = 'rider'
      AND w.type = 'user-wallet'
      AND w.deleted_at IS NULL
  );
