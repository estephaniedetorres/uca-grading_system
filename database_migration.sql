-- =============================================
-- Migration Script: Add Grade Status Workflow
-- Run this to update your existing database
-- =============================================

-- 1. Check if status column exists, if not add it
-- Run each statement separately if you get errors

-- Add status column (ignore error if already exists)
ALTER TABLE `tbl_grades` ADD COLUMN `status` ENUM('pending','draft','submitted','approved','finalized') DEFAULT 'draft';

-- Add workflow columns
ALTER TABLE `tbl_grades` ADD COLUMN `submitted_at` DATETIME DEFAULT NULL;
ALTER TABLE `tbl_grades` ADD COLUMN `approved_by` INT DEFAULT NULL;
ALTER TABLE `tbl_grades` ADD COLUMN `approved_at` DATETIME DEFAULT NULL;
ALTER TABLE `tbl_grades` ADD COLUMN `finalized_at` DATETIME DEFAULT NULL;
ALTER TABLE `tbl_grades` ADD COLUMN `remarks` TEXT DEFAULT NULL;

-- 2. Create grade history table for audit trail
CREATE TABLE IF NOT EXISTS `tbl_grade_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `grade_id` int NOT NULL,
  `changed_by` int DEFAULT NULL COMMENT 'User ID who made the change',
  `action` varchar(50) NOT NULL COMMENT 'Action performed: created, updated, submitted, approved, rejected, finalized',
  `old_status` varchar(20) DEFAULT NULL,
  `new_status` varchar(20) DEFAULT NULL,
  `old_values` JSON DEFAULT NULL COMMENT 'Previous grade values',
  `new_values` JSON DEFAULT NULL COMMENT 'New grade values',
  `remarks` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `grade_id` (`grade_id`),
  KEY `changed_by` (`changed_by`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 3. Create grade components table for detailed component scores
CREATE TABLE IF NOT EXISTS `tbl_grade_components` (
  `id` int NOT NULL AUTO_INCREMENT,
  `grade_id` int NOT NULL,
  `component_type` enum('WW','PT','QA') NOT NULL COMMENT 'Written Work, Performance Task, Quarterly Assessment',
  `component_number` int NOT NULL DEFAULT 1 COMMENT 'Component number (e.g., WW1, WW2, PT1)',
  `max_score` decimal(5,2) DEFAULT 100.00,
  `score` decimal(5,2) NOT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `grade_id` (`grade_id`),
  KEY `idx_component` (`component_type`, `component_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 4. Update existing grades to have 'draft' status
UPDATE `tbl_grades` SET `status` = 'draft' WHERE `status` IS NULL;

-- =============================================
-- Migration: Add Grading Period Dates to Terms
-- This allows automatic quarter selection based on current date
-- =============================================

-- 5. Add start_date and end_date columns to tbl_term
ALTER TABLE `tbl_term` ADD COLUMN `start_date` DATE NULL AFTER `term_name`;
ALTER TABLE `tbl_term` ADD COLUMN `end_date` DATE NULL AFTER `start_date`;

-- Clear quarter dates (no start/end dates for 1st-4th quarter)
UPDATE `tbl_term` SET `start_date` = NULL, `end_date` = NULL WHERE `term_name` IN ('1st Quarter', '2nd Quarter', '3rd Quarter', '4th Quarter');

-- Set dates for semesters
UPDATE `tbl_term` SET `start_date` = '2025-06-01', `end_date` = '2025-10-31' WHERE `term_name` = 'Semester 1';
UPDATE `tbl_term` SET `start_date` = '2025-11-01', `end_date` = '2026-03-31' WHERE `term_name` = 'Semester 2';
UPDATE `tbl_term` SET `start_date` = '2026-04-01', `end_date` = '2026-05-31' WHERE `term_name` = 'Summer';

SELECT 'Migration completed!' as Result;
