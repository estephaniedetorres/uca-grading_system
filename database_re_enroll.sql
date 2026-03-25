-- =============================================
-- Re-enrollment Script
-- 
-- Enrolls all students into subjects based on:
--   - Student's assigned section (tbl_student.section_id)
--   - Teacher-subject assignments (tbl_teacher_subject)
--
-- For YEARLY sections (K-10): term_id = NULL (no term needed)
-- For SEMESTRAL sections (SHS/College):
--   Semester 1 (id=13): only subjects with term_restriction IN ('any', 'term1')
--   Semester 2 (id=14): only subjects with term_restriction IN ('any', 'term2')
-- =============================================

-- First, create enrollment settings so the system knows enrollment is open
INSERT INTO tbl_enrollment_settings (sy_id, term_id, enrollment_start, enrollment_end, max_units, is_open)
VALUES
(2, 13, '2025-06-01 00:00:00', '2026-03-31 23:59:59', 30, 1),
(2, 14, '2025-06-01 00:00:00', '2026-03-31 23:59:59', 30, 1),
(2, 15, '2025-06-01 00:00:00', '2026-05-31 23:59:59', 30, 1);

-- YEARLY sections (Pre-Elem, Elementary, JHS): enroll with term_id = NULL
-- Use subquery to deduplicate teacher_subject (some subjects have 2 teachers assigned)
INSERT INTO tbl_enroll (student_id, subject_id, section_id, teacher_id, sy_id, term_id, status, enrolled_at)
SELECT 
    st.id AS student_id,
    ts.subject_id,
    ts.section_id,
    ts.teacher_id,
    ts.sy_id,
    NULL AS term_id,
    'enrolled',
    NOW()
FROM tbl_student st
INNER JOIN (
    SELECT MIN(id) as id, section_id, subject_id, MIN(teacher_id) as teacher_id, sy_id
    FROM tbl_teacher_subject WHERE status = 'active'
    GROUP BY section_id, subject_id, sy_id
) ts ON ts.section_id = st.section_id
INNER JOIN tbl_section s 
    ON s.id = st.section_id
INNER JOIN tbl_academic_track at2 
    ON at2.id = s.academic_track_id
WHERE at2.enrollment_type = 'yearly'
  AND s.sy_id = 2;

-- SEMESTRAL sections (SHS, College): enroll in Semester 1 (term_id = 13)
-- Only subjects with term_restriction = 'any' or 'term1'
INSERT INTO tbl_enroll (student_id, subject_id, section_id, teacher_id, sy_id, term_id, status, enrolled_at)
SELECT 
    st.id AS student_id,
    ts.subject_id,
    ts.section_id,
    ts.teacher_id,
    ts.sy_id,
    13 AS term_id,
    'enrolled',
    NOW()
FROM tbl_student st
INNER JOIN (
    SELECT MIN(id) as id, section_id, subject_id, MIN(teacher_id) as teacher_id, sy_id
    FROM tbl_teacher_subject WHERE status = 'active'
    GROUP BY section_id, subject_id, sy_id
) ts ON ts.section_id = st.section_id
INNER JOIN tbl_subjects sub
    ON sub.id = ts.subject_id
INNER JOIN tbl_section s 
    ON s.id = st.section_id
INNER JOIN tbl_academic_track at2 
    ON at2.id = s.academic_track_id
WHERE at2.enrollment_type = 'semestral'
  AND s.sy_id = 2
  AND sub.term_restriction IN ('any', 'term1');

-- SEMESTRAL sections: also enroll in Semester 2 (term_id = 14)
-- Only subjects with term_restriction = 'any' or 'term2'
INSERT INTO tbl_enroll (student_id, subject_id, section_id, teacher_id, sy_id, term_id, status, enrolled_at)
SELECT 
    st.id AS student_id,
    ts.subject_id,
    ts.section_id,
    ts.teacher_id,
    ts.sy_id,
    14 AS term_id,
    'enrolled',
    NOW()
FROM tbl_student st
INNER JOIN (
    SELECT MIN(id) as id, section_id, subject_id, MIN(teacher_id) as teacher_id, sy_id
    FROM tbl_teacher_subject WHERE status = 'active'
    GROUP BY section_id, subject_id, sy_id
) ts ON ts.section_id = st.section_id
INNER JOIN tbl_subjects sub
    ON sub.id = ts.subject_id
INNER JOIN tbl_section s 
    ON s.id = st.section_id
INNER JOIN tbl_academic_track at2 
    ON at2.id = s.academic_track_id
WHERE at2.enrollment_type = 'semestral'
  AND s.sy_id = 2
  AND sub.term_restriction IN ('any', 'term2');

-- Verify
SELECT 
    CASE WHEN at2.enrollment_type = 'yearly' THEN 'K-10 (Yearly)' ELSE 'SHS/College (Semestral)' END AS type,
    COUNT(*) AS enrollment_count
FROM tbl_enroll e
INNER JOIN tbl_section s ON s.id = e.section_id
INNER JOIN tbl_academic_track at2 ON at2.id = s.academic_track_id
GROUP BY at2.enrollment_type;

SELECT CONCAT('Total enrollments created: ', COUNT(*)) AS result FROM tbl_enroll;
