-- For riders that share the same mobile number (see
-- find_duplicate_rider_mobile_numbers.sql / find_duplicate_rider_related_records.sql),
-- delete the wallet + wallet_history data belonging to the LOSING riders
-- (every rider in the group except the most recently created one), for
-- BOTH wallet types ('user-wallet' and 'pr-user-wallet').
--
-- wallet_history is deleted first, then wallet — same order as
-- delete_duplicate_user_wallets.sql / delete_duplicate_pr_user_wallets.sql,
-- keeping the ledger rows from ever pointing at a wallet row that's
-- already gone.
--
-- "Keep" = the rider with the latest created_at in the mobile-number
-- group (ties broken by the highest id). Only that rider's wallet(s) are
-- left alone; every other rider in the group has ALL of their wallet
-- rows (both types) and that wallet's wallet_history rows deleted.
--
-- This is a SOFT delete (deleted_at = NOW()) on both public.wallet and
-- public.wallet_history, matching how every page in this app already
-- filters on deleted_at — reversible by setting deleted_at back to NULL
-- if a deletion turns out to be wrong.
--
-- Relationship to other scripts:
--   - delete_duplicate_riders.sql soft-deletes the losing riders
--     themselves (plus rider_vehicle_details/rider_address) but
--     deliberately leaves wallet/wallet_history untouched for manual
--     review. THIS script is that manual step, once you've confirmed
--     (e.g. via the preview below) that the losing riders' wallets are
--     safe to remove.
--   - Safe to run in either order relative to delete_duplicate_riders.sql,
--     and safe to re-run (already-deleted rows are simply skipped).
--
-- IMPORTANT: run every step below in the SAME database session/connection.
-- Step 0 stores the exact set of losing rider ids in a temp table
-- (auto-dropped when the session ends) so steps 1-3 all act on that same
-- fixed set, instead of each one recomputing "who's a duplicate" fresh.

-- ---------------------------------------------------------------
-- 0. Compute the losing rider ids ONCE, from the current live data,
--    into a temp table reused by every step below.
-- ---------------------------------------------------------------
DROP TABLE IF EXISTS _dup_rider_wallet_losers;
CREATE TEMP TABLE _dup_rider_wallet_losers AS
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
SELECT id AS rider_id FROM ranked WHERE rn > 1;

-- ---------------------------------------------------------------
-- 1. PREVIEW: run this next. Shows every wallet (both types) belonging to
--    a losing rider, its balance and its wallet_history transaction
--    totals, so you can check for real activity before deleting anything.
--    Nothing is changed by running this.
-- ---------------------------------------------------------------
SELECT
  dl.rider_id,
  r.code    AS rider_code,
  r.mobile_no,
  w.id      AS wallet_id,
  w.type    AS wallet_type,
  w.ref_code,
  w.avail_balance,
  w.status  AS wallet_status,
  w.deleted_at AS wallet_already_deleted_at,
  COALESCE(tx.tx_count, 0)        AS tx_count,
  COALESCE(tx.tx_total_credit, 0) AS tx_total_credit,
  COALESCE(tx.tx_total_debit, 0)  AS tx_total_debit
FROM _dup_rider_wallet_losers dl
JOIN public.riders r ON r.id = dl.rider_id
LEFT JOIN public.wallet w
       ON w.user_id = dl.rider_id AND w.user_type = 'rider'
LEFT JOIN LATERAL (
  SELECT COUNT(wt.id) AS tx_count,
         COALESCE(SUM(wt.credit_amount), 0) AS tx_total_credit,
         COALESCE(SUM(wt.debit_amount), 0)  AS tx_total_debit
  FROM public.wallet_history wt
  WHERE wt.wallet_id = w.id AND wt.deleted_at IS NULL
) tx ON TRUE
ORDER BY dl.rider_id, w.type;

-- ---------------------------------------------------------------
-- 2a. DELETE: soft-delete wallet_history rows for the losing riders'
--     wallets FIRST. Only run after reviewing the preview above.
-- ---------------------------------------------------------------
UPDATE public.wallet_history wh
SET deleted_at = NOW(),
    updated_at = NOW()
FROM public.wallet w
WHERE wh.wallet_id = w.id
  AND w.user_id IN (SELECT rider_id FROM _dup_rider_wallet_losers)
  AND w.user_type = 'rider'
  AND wh.deleted_at IS NULL;

-- ---------------------------------------------------------------
-- 2b. DELETE: soft-delete the losing riders' wallet rows (both types)
--     AFTER their wallet_history rows are gone.
-- ---------------------------------------------------------------
UPDATE public.wallet
SET deleted_at = NOW(),
    updated_at = NOW()
WHERE user_id IN (SELECT rider_id FROM _dup_rider_wallet_losers)
  AND user_type = 'rider'
  AND deleted_at IS NULL;

-- ---------------------------------------------------------------
-- 3. VERIFY: run after both DELETE steps above. Should return 0 rows
--    (i.e. no losing rider is left with a non-deleted wallet, and no
--    non-deleted wallet_history row points at one of their wallets).
-- ---------------------------------------------------------------
SELECT dl.rider_id, w.id AS wallet_id, w.type, wh.id AS wallet_history_id
FROM _dup_rider_wallet_losers dl
JOIN public.wallet w ON w.user_id = dl.rider_id AND w.user_type = 'rider'
LEFT JOIN public.wallet_history wh ON wh.wallet_id = w.id AND wh.deleted_at IS NULL
WHERE w.deleted_at IS NULL
   OR wh.id IS NOT NULL;

-- ---------------------------------------------------------------
-- 4. Cleanup: drop the temp table (also auto-dropped when the session ends).
-- ---------------------------------------------------------------
DROP TABLE IF EXISTS _dup_rider_wallet_losers;
