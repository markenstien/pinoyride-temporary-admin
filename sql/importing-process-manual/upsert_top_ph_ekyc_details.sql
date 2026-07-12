-- Sync public.riders -> public.top_ph_ekyc_details
--
-- For every non-deleted rider with an ekyc_request_user_id (set by
-- update_riders_ekyc.sql), create/refresh a matching eKYC detail row:
--   generate_request_user_id = riders.ekyc_request_user_id   (unique upsert key)
--   kyc_id                   = randomly generated UUID (new rows only)
--   current/permanent address = rider_address.address, or 'ADMIN_MISSING_ADDRESS'
--                               if the rider has no rider_address row
--   Everything else per the fixed mapping agreed with the team.
--
-- Safe to re-run: existing rows are updated in place (kyc_id and created_at
-- are preserved, not overwritten, on an existing row).
--
-- Note: the DB user this runs as has no CREATE privilege on schema public,
-- so kyc_id is built as an inline random UUID v4-style expression
-- (8-4-4-4-12 hex groups, correct version/variant nibbles) instead of a
-- stored function or gen_random_uuid() (no pgcrypto/uuid-ossp installed).

-- ---------------------------------------------------------------
-- 1. PREVIEW: run this first. Shows every rider this will touch, whether
--    it will be a fresh INSERT or an UPDATE to an existing row, and the
--    exact values that will be written. Nothing is changed by running this.
-- ---------------------------------------------------------------
SELECT
  r.id AS rider_id,
  r.ekyc_request_user_id AS generate_request_user_id,
  CASE WHEN e.generate_request_user_id IS NULL THEN 'INSERT' ELSE 'UPDATE' END AS action,
  r.first_name,
  r.middle_name,
  r.last_name,
  r.email_address,
  r.mobile_no,
  r.mobile_no AS pretty_mobile_no,
  '01/01/1991' AS date_of_birth,
  'SYSTEM_AUTOMATIC' AS place_of_birth,
  'Filipino' AS nationality,
  'Male' AS gender,
  'Philippines' AS current_country,
  COALESCE(ra.address, 'ADMIN_MISSING_ADDRESS') AS current_address,
  'Philippines' AS permanent_country,
  COALESCE(ra.address, 'ADMIN_MISSING_ADDRESS') AS permanent_address,
  'SYSTEM_AUTOMATIC' AS nature_of_work,
  'Others' AS source_of_fund,
  '630000004' AS id_type,
  r.drivers_license_no AS id_number,
  0 AS status,
  CASE WHEN e.generate_request_user_id IS NULL
       THEN '(new random UUID generated at insert time)'
       ELSE e.kyc_id
  END AS kyc_id,
  e.created_at AS existing_created_at_kept_if_update
FROM public.riders r
LEFT JOIN public.rider_address ra ON ra.rider_id = r.id
LEFT JOIN public.top_ph_ekyc_details e ON e.generate_request_user_id = r.ekyc_request_user_id
WHERE r.deleted_at IS NULL
  AND r.ekyc_request_user_id IS NOT NULL
  AND r.code LIKE '%DRN%'
ORDER BY r.id;

-- ---------------------------------------------------------------
-- 2. UPSERT: only run after reviewing the preview above.
-- ---------------------------------------------------------------
INSERT INTO public.top_ph_ekyc_details (
  kyc_id, first_name, middle_name, last_name, email_address, mobile_no, pretty_mobile_no,
  date_of_birth, place_of_birth, nationality, gender,
  current_country, current_address, permanent_country, permanent_address,
  nature_of_work, source_of_fund, id_type, id_number,
  status, created_at, updated_at, generate_request_user_id
)
SELECT
  lpad(to_hex((random() * 4294967295)::bigint), 8, '0') || '-' ||
  lpad(to_hex((random() * 65535)::int), 4, '0') || '-4' ||
  lpad(to_hex((random() * 4095)::int), 3, '0') || '-' ||
  (ARRAY['8','9','a','b'])[floor(random() * 4 + 1)] ||
  lpad(to_hex((random() * 4095)::int), 3, '0') || '-' ||
  lpad(to_hex((random() * 281474976710655)::bigint), 12, '0'),
  r.first_name,
  r.middle_name,
  r.last_name,
  r.email_address,
  r.mobile_no,
  r.mobile_no,
  '01/01/1991',
  'SYSTEM_AUTOMATIC',
  'Filipino',
  'Male',
  'Philippines',
  COALESCE(ra.address, 'ADMIN_MISSING_ADDRESS'),
  'Philippines',
  COALESCE(ra.address, 'ADMIN_MISSING_ADDRESS'),
  'SYSTEM_AUTOMATIC',
  'Others',
  '630000004',
  r.drivers_license_no,
  0,
  NOW(),
  NOW(),
  r.ekyc_request_user_id
FROM public.riders r
LEFT JOIN public.rider_address ra ON ra.rider_id = r.id
WHERE r.deleted_at IS NULL
  AND r.ekyc_request_user_id IS NOT NULL
  AND r.code LIKE '%DRN%'
ON CONFLICT (generate_request_user_id) DO UPDATE SET
  first_name         = EXCLUDED.first_name,
  middle_name        = EXCLUDED.middle_name,
  last_name          = EXCLUDED.last_name,
  email_address      = EXCLUDED.email_address,
  mobile_no          = EXCLUDED.mobile_no,
  pretty_mobile_no   = EXCLUDED.pretty_mobile_no,
  date_of_birth      = EXCLUDED.date_of_birth,
  place_of_birth     = EXCLUDED.place_of_birth,
  nationality        = EXCLUDED.nationality,
  gender             = EXCLUDED.gender,
  current_country    = EXCLUDED.current_country,
  current_address    = EXCLUDED.current_address,
  permanent_country  = EXCLUDED.permanent_country,
  permanent_address  = EXCLUDED.permanent_address,
  nature_of_work     = EXCLUDED.nature_of_work,
  source_of_fund     = EXCLUDED.source_of_fund,
  id_type            = EXCLUDED.id_type,
  id_number          = EXCLUDED.id_number,
  status             = EXCLUDED.status,
  updated_at         = NOW();
  -- kyc_id, created_at and generate_request_user_id are intentionally
  -- left out of the DO UPDATE SET list, so existing rows keep their
  -- original KYC session id and creation timestamp.

-- ---------------------------------------------------------------
-- 3a. PREVIEW: mobile_no normalization for the synthetic rows this script
--     creates (id_number LIKE '%DUM%'). mobile_no is copied straight from
--     riders.mobile_no (63-prefixed, e.g. '639701470888'), but real eKYC
--     provider rows use the local 0-prefixed 11-digit format
--     (e.g. '09701470888') in mobile_no, keeping the 63-prefixed form only
--     in pretty_mobile_no. Run this to review before the update below.
-- ---------------------------------------------------------------
SELECT
  id,
  generate_request_user_id,
  mobile_no AS mobile_no_before,
  '0' || substring(mobile_no from 3) AS mobile_no_after
FROM public.top_ph_ekyc_details
WHERE id_number LIKE '%DUM%'
  AND mobile_no LIKE '63%';

-- ---------------------------------------------------------------
-- 3b. UPDATE: only run after reviewing the preview above.
-- ---------------------------------------------------------------
UPDATE public.top_ph_ekyc_details
SET mobile_no = '0' || substring(mobile_no from 3),
    updated_at = NOW()
WHERE id_number LIKE '%DUM%'
  AND mobile_no LIKE '63%';

-- ---------------------------------------------------------------
-- 4. VERIFY: run after both the UPSERT and the mobile_no UPDATE above.
--    Should return 0 rows (i.e. no targeted rider is left missing a row,
--    out of sync, or with an un-normalized mobile_no).
-- ---------------------------------------------------------------
SELECT
  r.id AS rider_id,
  r.ekyc_request_user_id,
  e.generate_request_user_id AS matched_row
FROM public.riders r
LEFT JOIN public.rider_address ra ON ra.rider_id = r.id
LEFT JOIN public.top_ph_ekyc_details e ON e.generate_request_user_id = r.ekyc_request_user_id
WHERE r.deleted_at IS NULL
  AND r.ekyc_request_user_id IS NOT NULL
  AND r.code LIKE '%DRN%'
  AND (
    e.generate_request_user_id IS NULL
    OR e.first_name IS DISTINCT FROM r.first_name
    OR e.middle_name IS DISTINCT FROM r.middle_name
    OR e.last_name IS DISTINCT FROM r.last_name
    OR e.email_address IS DISTINCT FROM r.email_address
    OR e.mobile_no IS DISTINCT FROM ('0' || substring(r.mobile_no from 3))
    OR e.pretty_mobile_no IS DISTINCT FROM r.mobile_no
    OR e.current_address IS DISTINCT FROM COALESCE(ra.address, 'ADMIN_MISSING_ADDRESS')
    OR e.permanent_address IS DISTINCT FROM COALESCE(ra.address, 'ADMIN_MISSING_ADDRESS')
    OR e.id_number IS DISTINCT FROM r.drivers_license_no
    OR e.status IS DISTINCT FROM 0
  );
