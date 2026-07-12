-- Backfill ekyc_request_user_id and mark KYC/application as successful
-- for riders that don't have this data set yet.
--
-- ekyc_request_user_id = "<mobile_no>-<code>"
-- is_success_kyc        = 1
-- application_status    = 1
--
-- Only touches rows missing the data, so it's safe to re-run (idempotent).

-- ---------------------------------------------------------------
-- 1. PREVIEW: run this first. Shows current values alongside what
--    they would become, for every row the UPDATE below would touch.
--    Nothing is changed by running this.
-- ---------------------------------------------------------------
SELECT id, code, mobile_no,
       ekyc_request_user_id AS ekyc_request_user_id_before,
       (mobile_no || '-' || code) AS ekyc_request_user_id_after,
       is_success_kyc AS is_success_kyc_before,
       1 AS is_success_kyc_after,
       application_status AS application_status_before,
       1 AS application_status_after
FROM public.riders
WHERE deleted_at IS NULL
  AND (
    ekyc_request_user_id IS NULL
    OR is_success_kyc IS DISTINCT FROM 1
    OR application_status IS DISTINCT FROM 1
  );

-- ---------------------------------------------------------------
-- 2. UPDATE: only run after reviewing the preview above.
-- ---------------------------------------------------------------
UPDATE public.riders
SET ekyc_request_user_id = mobile_no || '-' || code,
    is_success_kyc = 1,
    application_status = 1,
    updated_at = NOW()
WHERE deleted_at IS NULL
  AND (
    ekyc_request_user_id IS NULL
    OR is_success_kyc IS DISTINCT FROM 1
    OR application_status IS DISTINCT FROM 1
  );

-- ---------------------------------------------------------------
-- 3. VERIFY: run after the UPDATE. Should return 0 rows
--    (i.e. no more non-deleted riders left with missing/incorrect values).
-- ---------------------------------------------------------------
SELECT id, code, mobile_no, ekyc_request_user_id, is_success_kyc, application_status
FROM public.riders
WHERE deleted_at IS NULL
  AND (
    ekyc_request_user_id IS NULL
    OR ekyc_request_user_id <> (mobile_no || '-' || code)
    OR is_success_kyc IS DISTINCT FROM 1
    OR application_status IS DISTINCT FROM 1
  );
