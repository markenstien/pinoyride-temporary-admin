-- For riders with more than one non-deleted 'user-wallet' row (type =
-- 'user-wallet', user_type = 'rider'), keep only the most recently created
-- one and soft-delete the rest, along with that wallet's wallet_history
-- ledger rows.
--
-- Companion script for the 'pr-user-wallet' type is
-- delete_duplicate_pr_user_wallets.sql — the two types are kept in
-- separate files/temp tables so they can be reviewed and run independently.
--
-- "Keep" = the wallet with the latest created_at per rider (ties broken by
-- the highest id, i.e. the row inserted last).
--
-- This is a SOFT delete (deleted_at = NOW()) on both public.wallet and
-- public.wallet_history, matching how every page in this app already
-- filters on deleted_at — reversible by setting deleted_at back to NULL
-- if a deletion turns out to be wrong.
--
-- IMPORTANT: run every step below in the SAME database session/connection.
-- Step 0 stores the exact set of wallet ids to delete in a temp table
-- (auto-dropped when the session ends) so steps 1-3 all act on that same
-- fixed set, instead of each one recomputing "who's a duplicate" fresh
-- (which would drift once step 2a/2b starts changing deleted_at).

-- ---------------------------------------------------------------
-- 0. Compute the losing wallet ids ONCE, from the current live data,
--    into a temp table reused by every step below.
-- ---------------------------------------------------------------
DROP TABLE IF EXISTS _dup_user_wallet_losers;
CREATE TEMP TABLE _dup_user_wallet_losers AS
WITH ranked AS (
  SELECT
    w.id,
    ROW_NUMBER() OVER (PARTITION BY w.user_id ORDER BY w.created_at DESC, w.id DESC) AS rn
  FROM public.wallet w
  WHERE w.user_type = 'rider'
    AND w.type = 'user-wallet'
    AND w.deleted_at IS NULL
)
SELECT id FROM ranked WHERE rn > 1;

-- ---------------------------------------------------------------
-- 1. PREVIEW: run this next. Shows every wallet involved in a duplicate
--    group, which one will be KEPT, which will be DELETEd, and each
--    wallet's transaction totals (from wallet_history) so you can check
--    balances before proceeding. Nothing is changed by running this.
-- ---------------------------------------------------------------
SELECT
  w.user_id AS rider_id,
  r.code    AS rider_code,
  w.id      AS wallet_id,
  w.ref_code,
  w.avail_balance,
  w.status  AS wallet_status,
  w.created_at,
  CASE WHEN dl.id IS NULL THEN 'KEEP' ELSE 'DELETE' END AS action,
  COALESCE(tx.tx_count, 0)          AS tx_count,
  COALESCE(tx.tx_total_credit, 0)   AS tx_total_credit,
  COALESCE(tx.tx_total_debit, 0)    AS tx_total_debit
FROM public.wallet w
LEFT JOIN public.riders r ON r.id = w.user_id
LEFT JOIN _dup_user_wallet_losers dl ON dl.id = w.id
LEFT JOIN LATERAL (
  SELECT COUNT(wt.id) AS tx_count,
         COALESCE(SUM(wt.credit_amount), 0) AS tx_total_credit,
         COALESCE(SUM(wt.debit_amount), 0)  AS tx_total_debit
  FROM public.wallet_history wt
  WHERE wt.wallet_id = w.id AND wt.deleted_at IS NULL
) tx ON TRUE
WHERE w.user_type = 'rider'
  AND w.type = 'user-wallet'
  AND w.deleted_at IS NULL
  AND w.user_id IN (
    SELECT w2.user_id
    FROM public.wallet w2
    JOIN _dup_user_wallet_losers dl2 ON dl2.id = w2.id
  )
ORDER BY w.user_id, action DESC, w.created_at DESC;

-- ---------------------------------------------------------------
-- 2a. DELETE: soft-delete the losing wallets' wallet_history rows first.
--     Only run after reviewing the preview above.
-- ---------------------------------------------------------------
UPDATE public.wallet_history
SET deleted_at = NOW(),
    updated_at = NOW()
WHERE wallet_id IN (SELECT id FROM _dup_user_wallet_losers)
  AND deleted_at IS NULL;

-- ---------------------------------------------------------------
-- 2b. DELETE: soft-delete the losing wallets themselves.
-- ---------------------------------------------------------------
UPDATE public.wallet
SET deleted_at = NOW(),
    updated_at = NOW()
WHERE id IN (SELECT id FROM _dup_user_wallet_losers);

-- ---------------------------------------------------------------
-- 3. VERIFY: run after both DELETE steps above. Should return 0 rows
--    (i.e. no rider is left with more than one non-deleted
--    'user-wallet' row).
-- ---------------------------------------------------------------
SELECT user_id AS rider_id, COUNT(*) AS wallet_count
FROM public.wallet
WHERE user_type = 'rider'
  AND type = 'user-wallet'
  AND deleted_at IS NULL
GROUP BY user_id
HAVING COUNT(*) > 1;

-- ---------------------------------------------------------------
-- 4. Cleanup: drop the temp table (also auto-dropped when the session ends).
-- ---------------------------------------------------------------
DROP TABLE IF EXISTS _dup_user_wallet_losers;
