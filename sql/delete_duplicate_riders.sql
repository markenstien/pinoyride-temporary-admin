-- For riders that share the same mobile number (see
-- find_duplicate_rider_mobile_numbers.sql), keep only the most recently
-- created rider and soft-delete the rest.
--
-- "Same" mobile number is judged on a normalized key (digits only, last 10
-- kept) so the 0-prefixed and 63-prefixed forms of the same phone are
-- matched as duplicates, same as the find_* scripts.
--
-- "Keep" = the rider with the latest created_at in the group (ties broken
-- by the highest id, i.e. the row inserted last).
--
-- This is a SOFT delete (riders.deleted_at = NOW()), matching how every
-- page in this app already filters riders ("WHERE deleted_at IS NULL") —
-- it's reversible by setting deleted_at back to NULL if a deletion turns
-- out to be wrong. The rider's own rider_vehicle_details, rider_address
-- and wallet rows are soft-deleted along with it.
--
-- Deliberately NOT touched:
--   - wallet_history: it's the financial ledger/audit trail. If a deleted
--     rider's wallet has transaction history, review it manually first
--     (see find_duplicate_rider_related_records.sql, section 1/2) — this
--     script does not move balances or erase transactions.
--   - top_ph_ekyc_details: it DOES have a deleted_at column (see
--     customer_show.php, which filters on it), but it's keyed by
--     generate_request_user_id (= riders.ekyc_request_user_id), not
--     rider_id, and holds compliance/KYC data — left alone here so it can
--     be reviewed/cleaned up deliberately rather than auto-deleted.
--
-- IMPORTANT: run every step below in the SAME database session/connection.
-- Step 0 stores the exact set of riders to delete in a temp table
-- (auto-dropped when the session ends) so steps 1-3 all act on that same
-- fixed set, instead of each one recomputing "who's a duplicate" fresh
-- (which would drift once step 1a starts changing deleted_at).

-- ---------------------------------------------------------------
-- 0. Compute the losing rider ids ONCE, from the current live data,
--    into a temp table reused by every step below.
-- ---------------------------------------------------------------
DROP TABLE IF EXISTS _dup_rider_losers;
CREATE TEMP TABLE _dup_rider_losers AS
WITH normalized AS (
  SELECT r.id, r.created_at,
         right(regexp_replace(r.mobile_no, '\D', '', 'g'), 10) AS mobile_key
  FROM public.riders r
  WHERE r.deleted_at IS NULL
    AND coalesce(r.mobile_no, '') <> ''
),
ranked AS (
  SELECT id,
         ROW_NUMBER() OVER (PARTITION BY mobile_key ORDER BY created_at DESC, id DESC) AS rn
  FROM normalized
)
SELECT id FROM ranked WHERE rn > 1;

-- ---------------------------------------------------------------
-- 1. PREVIEW: run this next. Shows every duplicate group, which rider
--    will be KEPT, which will be DELETEd, and flags any rider about to be
--    deleted that has wallet transaction history (tx_count > 0) so you can
--    check it manually before proceeding. Nothing is changed by running this.
-- ---------------------------------------------------------------
SELECT
  r.id AS rider_id,
  r.code,
  r.first_name,
  r.last_name,
  r.mobile_no,
  r.created_at,
  CASE WHEN dl.id IS NULL THEN 'KEEP' ELSE 'DELETE' END AS action,
  COALESCE(tx.tx_count, 0) AS tx_count
FROM public.riders r
JOIN (
  -- re-derive the mobile_key grouping just for display purposes
  SELECT id, right(regexp_replace(mobile_no, '\D', '', 'g'), 10) AS mobile_key
  FROM public.riders
  WHERE deleted_at IS NULL AND coalesce(mobile_no, '') <> ''
) g ON g.id = r.id
LEFT JOIN _dup_rider_losers dl ON dl.id = r.id
LEFT JOIN LATERAL (
  SELECT COUNT(wt.id) AS tx_count
  FROM public.wallet w
  JOIN public.wallet_history wt ON wt.wallet_id = w.id AND wt.deleted_at IS NULL
  WHERE w.user_id = r.id AND w.user_type = 'rider' AND w.deleted_at IS NULL
) tx ON TRUE
WHERE g.mobile_key IN (
  SELECT right(regexp_replace(r2.mobile_no, '\D', '', 'g'), 10)
  FROM public.riders r2
  JOIN _dup_rider_losers dl2 ON dl2.id = r2.id
)
ORDER BY g.mobile_key, action DESC, r.created_at DESC;

-- ---------------------------------------------------------------
-- 2a. DELETE: soft-delete the losing riders themselves.
--     Only run after reviewing the preview above.
-- ---------------------------------------------------------------
UPDATE public.riders
SET deleted_at = NOW(),
    updated_at = NOW()
WHERE id IN (SELECT id FROM _dup_rider_losers);

-- ---------------------------------------------------------------
-- 2b. DELETE: soft-delete the losing riders' vehicle detail rows.
-- ---------------------------------------------------------------
UPDATE public.rider_vehicle_details
SET deleted_at = NOW(),
    updated_at = NOW()
WHERE rider_id IN (SELECT id FROM _dup_rider_losers);

-- ---------------------------------------------------------------
-- 2c. DELETE: soft-delete the losing riders' address rows.
-- ---------------------------------------------------------------
UPDATE public.rider_address
SET deleted_at = NOW(),
    updated_at = NOW()
WHERE rider_id IN (SELECT id FROM _dup_rider_losers);

-- ---------------------------------------------------------------
-- 2d. DELETE: soft-delete the losing riders' wallet rows.
--     wallet_history is intentionally left alone (see note at top).
-- ---------------------------------------------------------------
UPDATE public.wallet
SET deleted_at = NOW(),
    updated_at = NOW()
WHERE user_id IN (SELECT id FROM _dup_rider_losers)
  AND user_type = 'rider';

-- ---------------------------------------------------------------
-- 3. VERIFY: run after all the DELETE steps above. Should return 0 rows
--    (i.e. no mobile number is still shared by more than one
--    non-deleted rider).
-- ---------------------------------------------------------------
WITH normalized AS (
  SELECT r.id,
         right(regexp_replace(r.mobile_no, '\D', '', 'g'), 10) AS mobile_key
  FROM public.riders r
  WHERE r.deleted_at IS NULL
    AND coalesce(r.mobile_no, '') <> ''
)
SELECT mobile_key, COUNT(*) AS rider_count
FROM normalized
GROUP BY mobile_key
HAVING COUNT(*) > 1;

-- ---------------------------------------------------------------
-- 4. Cleanup: drop the temp table (also auto-dropped when the session ends).
-- ---------------------------------------------------------------
DROP TABLE IF EXISTS _dup_rider_losers;
