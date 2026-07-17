-- Create a 'pr-user-wallet' row in public.wallet for every DRN rider that
-- doesn't already have one.
--
-- user_id       = riders.id
-- ref_code      = riders.id zero-padded to 6 digits (e.g. id 8207 -> '008207')
-- user_type     = 'rider'
-- type          = 'pr-user-wallet'
-- avail_balance = 0, credit_amount = 0, debit_amount = 0, status = 0
--
-- Scope: riders with code LIKE '%DRN%', not deleted, that don't already
-- have a non-deleted 'pr-user-wallet' row.
--
-- Insert-only (no upsert): a rider can legitimately have more than one
-- wallet row (different types), so there's no natural per-rider conflict
-- key to upsert on. Re-running this is still safe/idempotent because the
-- NOT EXISTS check skips riders that already got a pr-user-wallet from a
-- prior run.

-- ---------------------------------------------------------------
-- 1. PREVIEW: run this first. Shows every rider that will get a new
--    pr-user-wallet row, and the exact ref_code it will be assigned.
--    Nothing is changed by running this.
-- ---------------------------------------------------------------
SELECT
  r.id AS rider_id,
  r.code,
  r.mobile_no,
  lpad(r.id::text, 6, '0') AS new_ref_code,
  0 AS avail_balance,
  0 AS credit_amount,
  0 AS debit_amount,
  0 AS status
FROM public.riders r
WHERE r.deleted_at IS NULL
  AND r.code LIKE '%DRN%'
  AND NOT EXISTS (
    SELECT 1 FROM public.wallet w
    WHERE w.user_id = r.id
      AND w.user_type = 'rider'
      AND w.type = 'pr-user-wallet'
      AND w.deleted_at IS NULL
  )
ORDER BY r.id;

-- ---------------------------------------------------------------
-- 2. INSERT: only run after reviewing the preview above.
-- ---------------------------------------------------------------
INSERT INTO public.wallet (
  ref_code, user_id, user_type, type,
  avail_balance, credit_amount, debit_amount,
  status, created_at, updated_at
)
SELECT
  TO_CHAR(FLOOR(RANDOM() * 1000000)::int, 'FM000000'),
  r.id,
  'rider',
  'pr-user-wallet',
  0, 0, 0,
  0, NOW(), NOW()
FROM public.riders r
WHERE r.deleted_at IS NULL
  AND r.code LIKE '%DRN%'
  AND NOT EXISTS (
    SELECT 1 FROM public.wallet w
    WHERE w.user_id = r.id
      AND w.user_type = 'rider'
      AND w.type = 'pr-user-wallet'
      AND w.deleted_at IS NULL
  ) order by r.id desc;

-- ---------------------------------------------------------------
-- 3. VERIFY: run after the INSERT. Should return 0 rows
--    (i.e. no DRN rider is left without a pr-user-wallet row).
-- ---------------------------------------------------------------
SELECT r.id AS rider_id, r.code, r.mobile_no
FROM public.riders r
WHERE r.deleted_at IS NULL
  AND r.code LIKE '%DRN%'
  AND NOT EXISTS (
    SELECT 1 FROM public.wallet w
    WHERE w.user_id = r.id
      AND w.user_type = 'rider'
      AND w.type = 'pr-user-wallet'
      AND w.deleted_at IS NULL
  );
