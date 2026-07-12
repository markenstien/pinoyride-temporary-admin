-- Normalize public.riders.mobile_no to the canonical '63' + 10-digit format
-- (e.g. '639063387451'), regardless of how it was entered:
--   '09063387451'   (0-prefixed, 11 digits)      -> '639063387451'
--   '+639063387451' (+63-prefixed, with symbols)  -> '639063387451'
--   '9063387451'    (bare 10-digit subscriber no) -> '639063387451'
--   '639063387451'  (already correct)             -> left as-is
--
-- Any value that doesn't fit one of those shapes after stripping
-- non-digits is left untouched and flagged for manual review — this
-- script never guesses on a number it can't confidently normalize.
--
-- Scope: non-deleted riders only (WHERE deleted_at IS NULL), matching how
-- every page in this app already filters riders — and only riders whose
-- code starts with 'DRN' (r.code LIKE 'DRN%'), same scoping used by
-- insert_rider_wallets.sql / upsert_top_ph_ekyc_details.sql.
--
-- Note: normalizing can make two riders' mobile_no collapse into the same
-- value if they were entered in different formats for the same real
-- number (e.g. one row as '09063387451', another as '639063387451').
-- Run find_duplicate_rider_mobile_numbers.sql after this to check —
-- that script already compares on the same normalized key, so its result
-- won't change, but this UPDATE makes riders.mobile_no itself
-- exact-match comparable going forward (e.g. the mobile_no check in
-- import_rider.php).

-- ---------------------------------------------------------------
-- 1. PREVIEW: run this first. Shows every non-deleted rider's mobile_no
--    before/after, and what will happen to it. Nothing is changed by
--    running this.
-- ---------------------------------------------------------------
WITH normalized AS (
  SELECT
    r.id,
    r.code,
    r.mobile_no,
    regexp_replace(r.mobile_no, '\D', '', 'g') AS digits
  FROM public.riders r
  WHERE r.deleted_at IS NULL
    AND r.code LIKE 'DRN%'
    AND coalesce(r.mobile_no, '') <> ''
)
SELECT
  id AS rider_id,
  code,
  mobile_no AS mobile_no_before,
  CASE
    WHEN digits LIKE '63%' AND length(digits) = 12 THEN digits
    WHEN digits LIKE '0%'  AND length(digits) = 11 THEN '63' || substring(digits from 2)
    WHEN digits LIKE '9%'  AND length(digits) = 10 THEN '63' || digits
    ELSE NULL
  END AS mobile_no_after,
  CASE
    WHEN digits LIKE '63%' AND length(digits) = 12 AND digits = mobile_no THEN 'NO CHANGE'
    WHEN digits LIKE '63%' AND length(digits) = 12 THEN 'WILL UPDATE (symbols/spacing only)'
    WHEN digits LIKE '0%'  AND length(digits) = 11 THEN 'WILL UPDATE (0-prefixed -> 63)'
    WHEN digits LIKE '9%'  AND length(digits) = 10 THEN 'WILL UPDATE (bare 10-digit -> 63)'
    ELSE 'UNRECOGNIZED - needs manual review'
  END AS status
FROM normalized
ORDER BY status, rider_id;

-- ---------------------------------------------------------------
-- 2. UPDATE: only run after reviewing the preview above.
--    Only touches rows whose normalized value differs from what's stored.
-- ---------------------------------------------------------------
WITH normalized AS (
  SELECT
    r.id,
    regexp_replace(r.mobile_no, '\D', '', 'g') AS digits
  FROM public.riders r
  WHERE r.deleted_at IS NULL
    AND r.code LIKE 'DRN%'
    AND coalesce(r.mobile_no, '') <> ''
),
targets AS (
  SELECT
    id,
    CASE
      WHEN digits LIKE '63%' AND length(digits) = 12 THEN digits
      WHEN digits LIKE '0%'  AND length(digits) = 11 THEN '63' || substring(digits from 2)
      WHEN digits LIKE '9%'  AND length(digits) = 10 THEN '63' || digits
      ELSE NULL
    END AS mobile_no_after
  FROM normalized
)
UPDATE public.riders r
SET mobile_no  = t.mobile_no_after,
    updated_at = NOW()
FROM targets t
WHERE t.id = r.id
  AND t.mobile_no_after IS NOT NULL
  AND t.mobile_no_after IS DISTINCT FROM r.mobile_no;

-- ---------------------------------------------------------------
-- 3. VERIFY: run after the UPDATE above.
--    a) Should return 0 rows once every normalizable number is fixed
--       (any rows left are the 'UNRECOGNIZED' ones needing manual review).
-- ---------------------------------------------------------------
SELECT id AS rider_id, code, mobile_no
FROM public.riders
WHERE deleted_at IS NULL
  AND code LIKE 'DRN%'
  AND coalesce(mobile_no, '') <> ''
  AND mobile_no !~ '^63\d{10}$';

-- b) Sanity spot-check: confirm no valid number was left mid-format.
SELECT id AS rider_id, code, mobile_no
FROM public.riders
WHERE deleted_at IS NULL
  AND code LIKE 'DRN%'
  AND mobile_no ~ '^63\d{10}$'
ORDER BY id
LIMIT 20;
