-- ============================================================
-- PRODUCTION SCHOOL YEAR ROLLOVER (SAFE TEMPLATE)
--
-- Purpose:
--   Move the system to a new school year safely for deployment.
--
-- What this script does:
--   1) Closes previous active SY rows
--   2) Creates one new active SY
--   3) Creates Semester 1, Semester 2, Summer for that SY
--   4) Optionally creates enrollment settings for those terms
--   5) Provides verification queries
--
-- IMPORTANT:
--   - Review dates before running.
--   - Run on backup/staging first.
--   - Keep exactly ONE active school year.
-- ============================================================

SET @new_sy_name  = '2026-2027';
SET @new_sy_start = '2026-06-01';
SET @new_sy_end   = '2027-03-31';

SET @sem1_start   = '2026-06-01';
SET @sem1_end     = '2026-10-31';
SET @sem2_start   = '2026-11-01';
SET @sem2_end     = '2027-03-31';
SET @sum_start    = '2027-04-01';
SET @sum_end      = '2027-05-31';

-- Optional enrollment windows (set to NULL or adjust if not used)
SET @enroll_open_start = '2026-06-01 00:00:00';
SET @enroll_open_end   = '2027-05-31 23:59:59';
SET @max_units         = 30;

START TRANSACTION;

-- 1) Close all currently active school years
UPDATE tbl_sy
SET status = 'closed'
WHERE status = 'active';

-- 2) Insert new active school year
INSERT INTO tbl_sy (sy_name, start_date, end_date, status)
VALUES (@new_sy_name, @new_sy_start, @new_sy_end, 'active');

SET @new_sy_id = LAST_INSERT_ID();

-- 3) Insert canonical terms for the new SY
INSERT INTO tbl_term (sy_id, term_name, start_date, end_date, status)
VALUES
(@new_sy_id, 'Semester 1', @sem1_start, @sem1_end, 'active'),
(@new_sy_id, 'Semester 2', @sem2_start, @sem2_end, 'active'),
(@new_sy_id, 'Summer',     @sum_start,  @sum_end,  'active');

-- Capture term IDs for enrollment settings
SELECT id INTO @sem1_id FROM tbl_term WHERE sy_id = @new_sy_id AND term_name = 'Semester 1' ORDER BY id DESC LIMIT 1;
SELECT id INTO @sem2_id FROM tbl_term WHERE sy_id = @new_sy_id AND term_name = 'Semester 2' ORDER BY id DESC LIMIT 1;
SELECT id INTO @summer_id FROM tbl_term WHERE sy_id = @new_sy_id AND term_name = 'Summer' ORDER BY id DESC LIMIT 1;

-- 4) Optional: create/open enrollment settings for new SY terms
--    Remove this block if your school manages these manually.
INSERT INTO tbl_enrollment_settings (sy_id, term_id, enrollment_start, enrollment_end, max_units, is_open)
VALUES
(@new_sy_id, @sem1_id, @enroll_open_start, @enroll_open_end, @max_units, 1),
(@new_sy_id, @sem2_id, @enroll_open_start, @enroll_open_end, @max_units, 1),
(@new_sy_id, @summer_id, @enroll_open_start, @enroll_open_end, @max_units, 1)
ON DUPLICATE KEY UPDATE
  enrollment_start = VALUES(enrollment_start),
  enrollment_end   = VALUES(enrollment_end),
  max_units        = VALUES(max_units),
  is_open          = VALUES(is_open),
  updated_at       = NOW();

COMMIT;

-- ============================================================
-- Post-run validation checks
-- ============================================================

-- A) Exactly one active SY should exist
SELECT status, COUNT(*) AS cnt
FROM tbl_sy
GROUP BY status;

-- B) Show current active SY
SELECT *
FROM tbl_sy
WHERE status = 'active'
ORDER BY id DESC
LIMIT 1;

-- C) Show terms for active SY
SELECT t.*
FROM tbl_term t
JOIN tbl_sy sy ON sy.id = t.sy_id
WHERE sy.status = 'active'
ORDER BY t.id;

-- D) Show enrollment settings for active SY
SELECT es.*
FROM tbl_enrollment_settings es
JOIN tbl_sy sy ON sy.id = es.sy_id
WHERE sy.status = 'active'
ORDER BY es.term_id;

-- ============================================================
-- NEXT OPERATIONAL STEPS AFTER THIS SCRIPT
-- ============================================================
-- 1) Update / create sections with the new sy_id (admin/sections.php)
-- 2) Create teacher-subject assignments for the new sy_id
--    (principal/teacher_subjects.php or dean/teacher_subjects.php)
-- 3) Enroll students into subjects for the new SY
--    (admin/enrollment.php or your re-enrollment SQL flow)
-- 4) Verify grade approval/report pages are filtering the intended SY
-- ============================================================
