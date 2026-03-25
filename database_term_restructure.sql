-- =============================================
-- Migration: Restructure Terms & Grading Periods
-- 
-- New structure:
--   tbl_term: Only Semester 1, Semester 2, Summer per SY
--   Grading periods stored in tbl_grades.grading_period:
--     Pre-Elem/Elem/JHS: Q1, Q2 (Sem1) + Q3, Q4 (Sem2)
--     SHS: Q1, Q2 per semester
--     College: PRELIM, MIDTERM, SEMIFINAL, FINAL per semester
-- =============================================

-- STEP 1: Clear all grade-related data (clean start)
SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE tbl_raw_scores;
TRUNCATE TABLE tbl_grade_components;
TRUNCATE TABLE tbl_grade_history;
TRUNCATE TABLE tbl_grade_columns;
TRUNCATE TABLE tbl_grades;

-- STEP 2: Clear enrollment data
TRUNCATE TABLE tbl_enroll;

-- STEP 3: Clear enrollment settings (references term)
TRUNCATE TABLE tbl_enrollment_settings;

-- STEP 4: Clear prospectus (references term)
DELETE FROM tbl_prospectus WHERE term_id IS NOT NULL;

-- STEP 5: Remove old term rows
DELETE FROM tbl_term;

SET FOREIGN_KEY_CHECKS = 1;

-- STEP 6: Remove grading_type and education_level columns from tbl_term
-- (Using procedure to handle cases where columns may not exist)
DROP PROCEDURE IF EXISTS drop_term_columns;
DELIMITER //
CREATE PROCEDURE drop_term_columns()
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_term' AND COLUMN_NAME = 'grading_type') THEN
        ALTER TABLE tbl_term DROP COLUMN grading_type;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_term' AND COLUMN_NAME = 'education_level') THEN
        ALTER TABLE tbl_term DROP COLUMN education_level;
    END IF;
END //
DELIMITER ;
CALL drop_term_columns();
DROP PROCEDURE IF EXISTS drop_term_columns;

-- STEP 7: Insert clean terms (adjust sy_id to match your active school year)
-- Find active SY: SELECT id FROM tbl_sy WHERE status = 'active';
-- Replace sy_id (2) below with your active school year ID if different

INSERT INTO tbl_term (sy_id, term_name, start_date, end_date, status) VALUES
(2, 'Semester 1', '2025-06-01', '2025-10-31', 'active'),
(2, 'Semester 2', '2025-11-01', '2026-03-31', 'active'),
(2, 'Summer', '2026-04-01', '2026-05-31', 'active');

-- Verify
SELECT * FROM tbl_term;
SELECT 'Term restructure complete! Only Semester 1, Semester 2, and Summer terms now.' as Result;
