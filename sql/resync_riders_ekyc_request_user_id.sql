-- Re-sync public.riders.ekyc_request_user_id = "<mobile_no>-<code>" for
-- EVERY non-deleted rider, overwriting whatever value is already there.
--
-- Unlike update_riders_ekyc.sql (which only backfills rows where
-- ekyc_request_user_id IS NULL, and never touches an existing value),
-- this script recomputes it for all riders — needed after mobile_no was
-- changed in bulk (see normalize_rider_mobile_numbers.sql), which leaves
-- the old "<old_mobile_no>-<code>" value stale.
--
-- Why this can't be a plain UPDATE on riders alone: top_ph_ekyc_details is
-- linked by generate_request_user_id = riders.ekyc_request_user_id (see
-- upsert_top_ph_ekyc_details.sql). If we only overwrite riders and leave
-- top_ph_ekyc_details as-is, that row's generate_request_user_id keeps
-- the OLD value and silently stops matching its rider — the link breaks.
-- So this script updates BOTH tables, using the OLD value to find the
-- matching top_ph_ekyc_details row before it disappears.
--
-- IMPORTANT: run every step below in the SAME database session/connection.
-- Step 0 captures each rider's old -> new ekyc_request_user_id into a temp
-- table (auto-dropped when the session ends) so steps 1-3 all use that
-- same fixed mapping.

-- ---------------------------------------------------------------
-- 0. Compute the old -> new mapping ONCE, from the current live data,
--    for every rider whose value actually needs to change.
-- ---------------------------------------------------------------
DROP TABLE IF EXISTS _ekyc_resync;
CREATE TEMP TABLE _ekyc_resync AS
SELECT
  r.id AS rider_id,
  r.ekyc_request_user_id AS old_value,
  (r.mobile_no || '-' || r.code) AS new_value
FROM public.riders r
WHERE r.deleted_at IS NULL
  AND coalesce(r.mobile_no, '') <> ''
  AND coalesce(r.code, '') <> ''
  AND r.ekyc_request_user_id IS DISTINCT FROM (r.mobile_no || '-' || r.code);

-- ---------------------------------------------------------------
-- 1. PREVIEW: run this next. Shows every rider whose ekyc_request_user_id
--    will change, old vs new value, and whether a top_ph_ekyc_details row
--    currently matches the old value (and so will be re-linked in step 2a).
--    Nothing is changed by running this.
-- ---------------------------------------------------------------
SELECT
  e.rider_id,
  r.code,
  r.mobile_no,
  e.old_value,
  e.new_value,
  ekd.kyc_id AS matching_top_ph_ekyc_details_kyc_id
FROM _ekyc_resync e
JOIN public.riders r ON r.id = e.rider_id
LEFT JOIN public.top_ph_ekyc_details ekd
       ON ekd.generate_request_user_id = e.old_value
      AND ekd.deleted_at IS NULL
ORDER BY e.rider_id;

-- ---------------------------------------------------------------
-- 2a. UPDATE: re-point top_ph_ekyc_details at the new value FIRST, while
--     the old value is still available to match on.
--     Only run after reviewing the preview above.
-- ---------------------------------------------------------------
UPDATE public.top_ph_ekyc_details ekd
SET generate_request_user_id = e.new_value,
    updated_at = NOW()
FROM _ekyc_resync e
WHERE ekd.generate_request_user_id = e.old_value
  AND e.old_value IS NOT NULL
  AND ekd.deleted_at IS NULL;

-- ---------------------------------------------------------------
-- 2b. UPDATE: now overwrite riders.ekyc_request_user_id itself.
-- ---------------------------------------------------------------
UPDATE public.riders r
SET ekyc_request_user_id = e.new_value,
    updated_at = NOW()
FROM _ekyc_resync e
WHERE r.id = e.rider_id;

-- ---------------------------------------------------------------
-- 3. VERIFY: run after both UPDATE steps above.
--    a) Should return 0 rows (every non-deleted rider's
--       ekyc_request_user_id matches its current mobile_no + code).
-- ---------------------------------------------------------------
SELECT id AS rider_id, code, mobile_no, ekyc_request_user_id
FROM public.riders
WHERE deleted_at IS NULL
  AND coalesce(mobile_no, '') <> ''
  AND coalesce(code, '') <> ''
  AND ekyc_request_user_id IS DISTINCT FROM (mobile_no || '-' || code);

-- b) Should also return 0 rows (no top_ph_ekyc_details row left pointing
--    at an ekyc_request_user_id that no longer matches any rider).
SELECT ekd.id, ekd.generate_request_user_id, ekd.kyc_id
FROM public.top_ph_ekyc_details ekd
WHERE ekd.deleted_at IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM public.riders r
    WHERE r.ekyc_request_user_id = ekd.generate_request_user_id
      AND r.deleted_at IS NULL
  )
  AND EXISTS (
    -- only flag rows that were actually touched by this script
    SELECT 1 FROM _ekyc_resync e WHERE e.new_value = ekd.generate_request_user_id
  );

-- ---------------------------------------------------------------
-- 4. Cleanup: drop the temp table (also auto-dropped when the session ends).
-- ---------------------------------------------------------------
DROP TABLE IF EXISTS _ekyc_resync;
