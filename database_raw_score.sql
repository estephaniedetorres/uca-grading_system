-- Migration: Add tbl_grade_columns for raw score column configuration
-- This stores the column setup (how many columns, highest possible score) per teacher/section/subject/term/grading_period

CREATE TABLE IF NOT EXISTS `tbl_grade_columns` (
  `id` int NOT NULL AUTO_INCREMENT,
  `teacher_id` int NOT NULL,
  `section_id` int NOT NULL,
  `subject_id` int NOT NULL,
  `term_id` int NOT NULL,
  `grading_period` varchar(20) NOT NULL COMMENT 'Q1, Q2, Q3, Q4, PRELIM, MIDTERM, etc.',
  `component_type` enum('ww','pt','qa') NOT NULL COMMENT 'Written Work, Performance Task, Quarterly Assessment',
  `column_number` int NOT NULL DEFAULT 1 COMMENT 'Column order (1, 2, 3...)',
  `highest_possible_score` decimal(7,2) NOT NULL DEFAULT 0 COMMENT 'Max score for this column',
  `description` varchar(100) DEFAULT NULL COMMENT 'Optional activity description',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_column` (`teacher_id`, `section_id`, `subject_id`, `term_id`, `grading_period`, `component_type`, `column_number`),
  KEY `idx_teacher_section_subject` (`teacher_id`, `section_id`, `subject_id`, `term_id`, `grading_period`),
  CONSTRAINT `tbl_grade_columns_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `tbl_teacher` (`id`),
  CONSTRAINT `tbl_grade_columns_ibfk_2` FOREIGN KEY (`section_id`) REFERENCES `tbl_section` (`id`),
  CONSTRAINT `tbl_grade_columns_ibfk_3` FOREIGN KEY (`subject_id`) REFERENCES `tbl_subjects` (`id`),
  CONSTRAINT `tbl_grade_columns_ibfk_4` FOREIGN KEY (`term_id`) REFERENCES `tbl_term` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Migration: Add tbl_raw_scores to store individual student scores per column
-- Links to tbl_grade_columns for the column definition and tbl_enroll for the student enrollment
CREATE TABLE IF NOT EXISTS `tbl_raw_scores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `enroll_id` int NOT NULL COMMENT 'Student enrollment record',
  `column_id` int NOT NULL COMMENT 'Reference to tbl_grade_columns',
  `score` decimal(7,2) DEFAULT NULL COMMENT 'Student raw score (NULL = not yet entered)',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_score` (`enroll_id`, `column_id`),
  KEY `column_id` (`column_id`),
  CONSTRAINT `tbl_raw_scores_ibfk_1` FOREIGN KEY (`enroll_id`) REFERENCES `tbl_enroll` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tbl_raw_scores_ibfk_2` FOREIGN KEY (`column_id`) REFERENCES `tbl_grade_columns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
