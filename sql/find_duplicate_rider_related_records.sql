-- For riders sharing a duplicate mobile number (see
-- find_duplicate_rider_mobile_numbers.sql), show what related records
-- exist for each of them in public.wallet, public.wallet_history and
-- public.top_ph_ekyc_details — so it's clear what a merge/delete would
-- affect before anything is touched.
--
-- Read-only: this script only SELECTs, nothing is changed.

-- ---------------------------------------------------------------
-- 1. WALLET SUMMARY: each duplicate rider's wallet(s), with a rolled-up
--    transaction count/total pulled from wallet_history.
-- ---------------------------------------------------------------
WITH normalized AS (
  SELECT r.id, r.code, r.mobile_no,
         right(regexp_replace(r.mobile_no, '\D', '', 'g'), 10) AS mobile_key
  FROM public.riders r
  WHERE r.deleted_at IS NULL
    AND coalesce(r.mobile_no, '') <> ''
),
dupes AS (
  SELECT mobile_key FROM normalized GROUP BY mobile_key HAVING COUNT(*) > 1
)
SELECT
  n.mobile_key,
  n.id            AS rider_id,
  n.code          AS rider_code,
  n.mobile_no,
  w.id            AS wallet_id,
  w.type          AS wallet_type,
  w.ref_code,
  w.avail_balance,
  w.status        AS wallet_status,
  w.deleted_at    AS wallet_deleted_at,
  COUNT(wt.id)                          AS tx_count,
  COALESCE(SUM(wt.credit_amount), 0)    AS tx_total_credit,
  COALESCE(SUM(wt.debit_amount), 0)     AS tx_total_debit
FROM normalized n
JOIN dupes d ON d.mobile_key = n.mobile_key
LEFT JOIN public.wallet w
       ON w.user_id = n.id AND w.user_type = 'rider'
LEFT JOIN public.wallet_history wt
       ON wt.wallet_id = w.id AND wt.deleted_at IS NULL
GROUP BY n.mobile_key, n.id, n.code, n.mobile_no,
         w.id, w.type, w.ref_code, w.avail_balance, w.status, w.deleted_at
ORDER BY n.mobile_key, n.id;

-- ---------------------------------------------------------------
-- 2. WALLET HISTORY DETAIL: every ledger row behind the summary above,
--    for closer review (e.g. before deciding which wallet to keep).
-- ---------------------------------------------------------------
WITH normalized AS (
  SELECT r.id, r.code, r.mobile_no,
         right(regexp_replace(r.mobile_no, '\D', '', 'g'), 10) AS mobile_key
  FROM public.riders r
  WHERE r.deleted_at IS NULL
    AND coalesce(r.mobile_no, '') <> ''
),
dupes AS (
  SELECT mobile_key FROM normalized GROUP BY mobile_key HAVING COUNT(*) > 1
)
SELECT
  n.mobile_key,
  n.id     AS rider_id,
  n.code   AS rider_code,
  w.id     AS wallet_id,
  wt.id    AS wallet_history_id,
  wt.tran_type,
  wt.credit_amount,
  wt.debit_amount,
  wt.status,
  wt.created_at
FROM normalized n
JOIN dupes d ON d.mobile_key = n.mobile_key
JOIN public.wallet w
     ON w.user_id = n.id AND w.user_type = 'rider'
JOIN public.wallet_history wt
     ON wt.wallet_id = w.id AND wt.deleted_at IS NULL
ORDER BY n.mobile_key, n.id, wt.created_at;

-- ---------------------------------------------------------------
-- 3. EKYC DETAIL: each duplicate rider's top_ph_ekyc_details row (if any),
--    matched the same way riders.php / upsert_top_ph_ekyc_details.sql do
--    (riders.ekyc_request_user_id -> top_ph_ekyc_details.generate_request_user_id).
-- ---------------------------------------------------------------
WITH normalized AS (
  SELECT r.id, r.code, r.mobile_no, r.ekyc_request_user_id,
         right(regexp_replace(r.mobile_no, '\D', '', 'g'), 10) AS mobile_key
  FROM public.riders r
  WHERE r.deleted_at IS NULL
    AND coalesce(r.mobile_no, '') <> ''
),
dupes AS (
  SELECT mobile_key FROM normalized GROUP BY mobile_key HAVING COUNT(*) > 1
)
SELECT
  n.mobile_key,
  n.id                       AS rider_id,
  n.code                     AS rider_code,
  n.ekyc_request_user_id,
  e.generate_request_user_id AS matched_ekyc_row,
  e.kyc_id,
  e.mobile_no                AS ekyc_mobile_no,
  e.pretty_mobile_no,
  e.status                   AS ekyc_status,
  e.created_at                AS ekyc_created_at
FROM normalized n
JOIN dupes d ON d.mobile_key = n.mobile_key
LEFT JOIN public.top_ph_ekyc_details e
       ON e.generate_request_user_id = n.ekyc_request_user_id
ORDER BY n.mobile_key, n.id;
