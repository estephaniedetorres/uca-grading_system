-- ============================================================
-- STUDENT ENROLLMENT SYSTEM MIGRATION
-- Run this script to add enrollment functionality
-- ============================================================

-- Enrollment Settings Table
CREATE TABLE IF NOT EXISTS `tbl_enrollment_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sy_id` int NOT NULL,
  `term_id` int NOT NULL,
  `enrollment_start` datetime NOT NULL,
  `enrollment_end` datetime NOT NULL,
  `max_units` int DEFAULT 30 COMMENT 'Maximum units a student can enroll per term',
  `is_open` tinyint(1) DEFAULT 1 COMMENT 'Whether enrollment is currently open',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_sy_term` (`sy_id`, `term_id`),
  KEY `sy_id` (`sy_id`),
  KEY `term_id` (`term_id`),
  CONSTRAINT `tbl_enrollment_settings_ibfk_1` FOREIGN KEY (`sy_id`) REFERENCES `tbl_sy` (`id`),
  CONSTRAINT `tbl_enrollment_settings_ibfk_2` FOREIGN KEY (`term_id`) REFERENCES `tbl_term` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Subject Prerequisites Table
CREATE TABLE IF NOT EXISTS `tbl_subject_prerequisites` (
  `id` int NOT NULL AUTO_INCREMENT,
  `subject_id` int NOT NULL COMMENT 'The subject that requires prerequisites',
  `prerequisite_subject_id` int NOT NULL COMMENT 'The required prerequisite subject',
  `min_grade` decimal(5,2) DEFAULT 75.00 COMMENT 'Minimum grade required to pass prerequisite',
  `status` varchar(20) DEFAULT 'active',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_prereq` (`subject_id`, `prerequisite_subject_id`),
  KEY `subject_id` (`subject_id`),
  KEY `prerequisite_subject_id` (`prerequisite_subject_id`),
  CONSTRAINT `tbl_subject_prerequisites_ibfk_1` FOREIGN KEY (`subject_id`) REFERENCES `tbl_subjects` (`id`),
  CONSTRAINT `tbl_subject_prerequisites_ibfk_2` FOREIGN KEY (`prerequisite_subject_id`) REFERENCES `tbl_subjects` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Add enrollment limit to prospectus (optional per-subject limit)
-- Note: Run this only if the column doesn't exist
-- ALTER TABLE `tbl_prospectus` ADD COLUMN `max_enrollees` int DEFAULT NULL COMMENT 'Maximum students per section for this subject';

-- Check and add column if not exists (MySQL 8 compatible)
SET @dbname = 'grading_system';
SET @tablename = 'tbl_prospectus';
SET @columnname = 'max_enrollees';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' int DEFAULT NULL COMMENT \'Maximum students per section for this subject\'')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Insert sample enrollment settings for current term
INSERT INTO `tbl_enrollment_settings` (`sy_id`, `term_id`, `enrollment_start`, `enrollment_end`, `max_units`, `is_open`)
SELECT 2, 6, '2025-06-01 00:00:00', '2026-06-30 23:59:59', 30, 1
WHERE NOT EXISTS (SELECT 1 FROM `tbl_enrollment_settings` WHERE sy_id = 2 AND term_id = 6);

-- Insert sample prerequisite (Physics 2 requires Physics 1)
INSERT INTO `tbl_subject_prerequisites` (`subject_id`, `prerequisite_subject_id`, `min_grade`)
SELECT 19, 18, 75.00
WHERE NOT EXISTS (SELECT 1 FROM `tbl_subject_prerequisites` WHERE subject_id = 19 AND prerequisite_subject_id = 18);

-- Add more prospectus entries for Term 2 subjects
INSERT IGNORE INTO `tbl_prospectus` (`curriculum_id`, `subject_id`, `term_id`, `status`) VALUES
(14, 19, 7, 'active'),  -- STEM11 Physics 2 in Term 2
(14, 17, 6, 'active'),  -- STEM11 PE in Term 1
(22, 19, 7, 'active');  -- BSCS Physics 2 in Term 2

