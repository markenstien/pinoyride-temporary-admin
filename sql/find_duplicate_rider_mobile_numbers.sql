-- Find riders that share the same mobile number.
--
-- "Same" is judged on a normalized number (digits only, last 10 kept) so
-- that the 0-prefixed local format ('09701470888') and the 63-prefixed
-- format ('639701470888') for the same phone are correctly matched as
-- duplicates, not missed because of formatting.
--
-- Read-only: this script only SELECTs, nothing is changed.

-- ---------------------------------------------------------------
-- 1. SUMMARY: normalized mobile numbers that belong to more than one
--    non-deleted rider, with how many riders share each one.
-- ---------------------------------------------------------------
WITH normalized AS (
  SELECT
    r.id,
    r.code,
    r.first_name,
    r.last_name,
    r.mobile_no,
    right(regexp_replace(r.mobile_no, '\D', '', 'g'), 10) AS mobile_key
  FROM public.riders r
  WHERE r.deleted_at IS NULL
    AND coalesce(r.mobile_no, '') <> ''
)
SELECT
  mobile_key,
  COUNT(*) AS rider_count,
  array_agg(id ORDER BY id)   AS rider_ids,
  array_agg(code ORDER BY id) AS rider_codes
FROM normalized
GROUP BY mobile_key
HAVING COUNT(*) > 1
ORDER BY rider_count DESC, mobile_key;

-- ---------------------------------------------------------------
-- 2. DETAIL: every rider row involved in a duplicate mobile number,
--    grouped together for side-by-side review.
-- ---------------------------------------------------------------
WITH normalized AS (
  SELECT
    r.id,
    r.code,
    r.first_name,
    r.last_name,
    r.mobile_no,
    r.email_address,
    r.status,
    r.application_status,
    r.created_at,
    right(regexp_replace(r.mobile_no, '\D', '', 'g'), 10) AS mobile_key
  FROM public.riders r
  WHERE r.deleted_at IS NULL
    AND coalesce(r.mobile_no, '') <> ''
),
dupes AS (
  SELECT mobile_key
  FROM normalized
  GROUP BY mobile_key
  HAVING COUNT(*) > 1
)
SELECT n.*
FROM normalized n
JOIN dupes d ON d.mobile_key = n.mobile_key
ORDER BY n.mobile_key, n.id;
