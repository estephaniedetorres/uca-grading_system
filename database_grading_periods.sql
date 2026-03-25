-- Migration to add college grading periods (Prelim, Midterm, Semi-Finals, Finals)
-- Run this migration to update the grading system for college-level courses

-- First, update the tbl_grades table comment to reflect the new grading periods
ALTER TABLE `tbl_grades` 
MODIFY COLUMN `grading_period` varchar(20) DEFAULT NULL COMMENT 'K-12: Q1, Q2, Q3, Q4 | College: PRELIM, MIDTERM, SEMIFINAL, FINAL';

-- Update tbl_enroll to add college grading period columns
ALTER TABLE `tbl_enroll`
ADD COLUMN `prelim_grade` decimal(5,2) DEFAULT NULL COMMENT 'Prelim grade (College)' AFTER `q4_grade`,
ADD COLUMN `midterm_grade` decimal(5,2) DEFAULT NULL COMMENT 'Midterm grade (College)' AFTER `prelim_grade`,
ADD COLUMN `semifinal_grade` decimal(5,2) DEFAULT NULL COMMENT 'Semi-Final grade (College)' AFTER `midterm_grade`,
ADD COLUMN `final_exam_grade` decimal(5,2) DEFAULT NULL COMMENT 'Finals grade (College)' AFTER `semifinal_grade`;

-- Note: Existing terms (Semester 1, Semester 2, Summer) are kept
-- The grading_period field in tbl_grades will store:
-- For K-12: Q1, Q2, Q3, Q4
-- For College: PRELIM, MIDTERM, SEMIFINAL, FINAL
-- These are entered within each semester term

-- Example data update (optional - adjust term_id values based on your data):
-- The college grading periods (Prelim, Midterm, Semi-Finals, Finals) are 
-- entered under each Semester (term), so no new term records are needed.
-- Teachers will select the semester (term) and then the grading period within that semester.
