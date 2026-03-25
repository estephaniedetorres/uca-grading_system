-- Fix for BSCS-1A enrollment and teacher assignment issues
-- Run this SQL in your MySQL database

-- ============================================
-- ISSUE 1: No student in BSCS-1A section
-- ============================================

-- First, check if student4 user exists (user_id = 14)
-- If not, create the user
INSERT IGNORE INTO `tbl_users` (`id`, `username`, `password`, `role`, `status`) VALUES
	(14, 'student4', '$2y$10$xfExS4bGOMTrr9OhNF0z3O4l6BYGLGt7lFzLjL6S/.dJEdpwC0z5m', 'student', 'active');

-- Add student4 to tbl_student in BSCS-1A section (section_id = 7)
INSERT INTO `tbl_student` (`user_id`, `given_name`, `middle_name`, `last_name`, `section_id`) 
SELECT 14, 'Student', 'Four', 'Test', 7
WHERE NOT EXISTS (SELECT 1 FROM `tbl_student` WHERE user_id = 14);

-- Get the student_id (it should be 11 if auto-increment continues)
-- SELECT @student_id := id FROM tbl_student WHERE user_id = 14;


-- ============================================
-- ISSUE 2: t_sarmiento not assigned to BSCS-1A
-- ============================================

-- T. Sarmiento = teacher_id = 3
-- BSCS-1A = section_id = 7
-- BSCS Curriculum subjects for Semester 1 (term_id = 6):
--   - subject_id 13 (MATH1)
--   - subject_id 14 (ENG1)  
--   - subject_id 18 (PHY1)

-- Add teacher assignments for t_sarmiento in BSCS-1A section
INSERT IGNORE INTO `tbl_teacher_subject` (`teacher_id`, `section_id`, `subject_id`, `sy_id`, `status`) VALUES
	(3, 7, 13, 2, 'active'),  -- MATH1
	(3, 7, 14, 2, 'active'),  -- ENG1
	(3, 7, 18, 2, 'active');  -- PHY1


-- ============================================
-- VERIFY THE DATA
-- ============================================

-- Check student4 exists in BSCS-1A
-- SELECT s.*, u.username, sec.section_code 
-- FROM tbl_student s 
-- JOIN tbl_users u ON s.user_id = u.id 
-- JOIN tbl_section sec ON s.section_id = sec.id
-- WHERE sec.section_code = 'BSCS-1A';

-- Check teacher assignments for BSCS-1A
-- SELECT ts.*, t.name as teacher_name, sec.section_code, sub.subjcode, sub.`desc`
-- FROM tbl_teacher_subject ts
-- JOIN tbl_teacher t ON ts.teacher_id = t.id
-- JOIN tbl_section sec ON ts.section_id = sec.id
-- JOIN tbl_subjects sub ON ts.subject_id = sub.id
-- WHERE sec.section_code = 'BSCS-1A';

-- Check prospectus entries for BSCS curriculum
-- SELECT p.*, c.curriculum_name, s.subjcode, s.`desc`, t.term_name
-- FROM tbl_prospectus p
-- JOIN tbl_curriculum c ON p.curriculum_id = c.id
-- JOIN tbl_subjects s ON p.subject_id = s.id
-- LEFT JOIN tbl_term t ON p.term_id = t.id
-- WHERE p.curriculum_id = 22;
