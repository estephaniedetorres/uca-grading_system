-- ============================================================
-- DEMO: College Student With Previous School Year Records
--
-- Goal:
--   Make School Year selectors visibly work in student portal
--   (Dashboard, My Grades, My Subjects) for a college student.
--
-- Default demo student: 25-00039 (from database_demo_data.sql)
--
-- What this script does:
--   1) Ensures a previous school year exists (2024-2025)
--   2) Ensures previous SY terms exist
--   3) Copies selected student's current SY enrollments into previous SY
--   4) Copies approved/finalized grades for those copied enrollments
--   5) Provides validation queries
--
-- Safe to re-run: uses NOT EXISTS guards where possible.
-- ============================================================

SET @student_no = '25-00039';
SET @prev_sy_name = '2024-2025';
SET @prev_sy_start = '2024-06-01';
SET @prev_sy_end = '2025-03-31';

START TRANSACTION;

-- Resolve demo student
SELECT id INTO @student_id
FROM tbl_student
WHERE student_no = @student_no
LIMIT 1;

-- Resolve current SY from student's section
SELECT sec.sy_id INTO @current_sy_id
FROM tbl_student st
JOIN tbl_section sec ON sec.id = st.section_id
WHERE st.id = @student_id
LIMIT 1;

-- Ensure previous SY exists
INSERT INTO tbl_sy (sy_name, start_date, end_date, status)
SELECT @prev_sy_name, @prev_sy_start, @prev_sy_end, 'closed'
WHERE NOT EXISTS (
    SELECT 1 FROM tbl_sy WHERE sy_name = @prev_sy_name
);

SELECT id INTO @prev_sy_id
FROM tbl_sy
WHERE sy_name = @prev_sy_name
ORDER BY id DESC
LIMIT 1;

-- Ensure previous SY terms exist (closed to avoid affecting active operations)
INSERT INTO tbl_term (sy_id, term_name, start_date, end_date, status)
SELECT @prev_sy_id, 'Semester 1', '2024-06-01', '2024-10-31', 'closed'
WHERE NOT EXISTS (
    SELECT 1 FROM tbl_term WHERE sy_id = @prev_sy_id AND term_name = 'Semester 1'
);

INSERT INTO tbl_term (sy_id, term_name, start_date, end_date, status)
SELECT @prev_sy_id, 'Semester 2', '2024-11-01', '2025-03-31', 'closed'
WHERE NOT EXISTS (
    SELECT 1 FROM tbl_term WHERE sy_id = @prev_sy_id AND term_name = 'Semester 2'
);

INSERT INTO tbl_term (sy_id, term_name, start_date, end_date, status)
SELECT @prev_sy_id, 'Summer', '2025-04-01', '2025-05-31', 'closed'
WHERE NOT EXISTS (
    SELECT 1 FROM tbl_term WHERE sy_id = @prev_sy_id AND term_name = 'Summer'
);

-- Resolve term IDs for mapping
SELECT id INTO @cur_sem1 FROM tbl_term WHERE sy_id = @current_sy_id AND term_name = 'Semester 1' ORDER BY id DESC LIMIT 1;
SELECT id INTO @cur_sem2 FROM tbl_term WHERE sy_id = @current_sy_id AND term_name = 'Semester 2' ORDER BY id DESC LIMIT 1;
SELECT id INTO @cur_summer FROM tbl_term WHERE sy_id = @current_sy_id AND term_name = 'Summer' ORDER BY id DESC LIMIT 1;

SELECT id INTO @prev_sem1 FROM tbl_term WHERE sy_id = @prev_sy_id AND term_name = 'Semester 1' ORDER BY id DESC LIMIT 1;
SELECT id INTO @prev_sem2 FROM tbl_term WHERE sy_id = @prev_sy_id AND term_name = 'Semester 2' ORDER BY id DESC LIMIT 1;
SELECT id INTO @prev_summer FROM tbl_term WHERE sy_id = @prev_sy_id AND term_name = 'Summer' ORDER BY id DESC LIMIT 1;

-- Copy enrollments from current SY -> previous SY for this student
-- Keeps same section/subject/teacher; remaps term IDs by term name.
INSERT INTO tbl_enroll (
    student_id, subject_id, section_id, teacher_id, sy_id, term_id,
    q1_grade, q2_grade, q3_grade, q4_grade, average_grade, final_grade,
    status, enrolled_at, updated_at
)
SELECT
    e.student_id,
    e.subject_id,
    e.section_id,
    e.teacher_id,
    @prev_sy_id AS sy_id,
    CASE
        WHEN e.term_id IS NULL THEN NULL
        WHEN e.term_id = @cur_sem1 THEN @prev_sem1
        WHEN e.term_id = @cur_sem2 THEN @prev_sem2
        WHEN e.term_id = @cur_summer THEN @prev_summer
        ELSE NULL
    END AS term_id,
    e.q1_grade,
    e.q2_grade,
    e.q3_grade,
    e.q4_grade,
    e.average_grade,
    e.final_grade,
    e.status,
    NOW(),
    NOW()
FROM tbl_enroll e
WHERE e.student_id = @student_id
  AND e.sy_id = @current_sy_id
  AND NOT EXISTS (
      SELECT 1
      FROM tbl_enroll x
      WHERE x.student_id = e.student_id
        AND x.subject_id = e.subject_id
        AND x.sy_id = @prev_sy_id
        AND (
            (x.term_id IS NULL AND e.term_id IS NULL)
            OR
            (x.term_id = CASE
                WHEN e.term_id = @cur_sem1 THEN @prev_sem1
                WHEN e.term_id = @cur_sem2 THEN @prev_sem2
                WHEN e.term_id = @cur_summer THEN @prev_summer
                ELSE NULL
            END)
        )
  );

-- Copy approved/finalized grades to the newly copied previous-SY enrollments.
INSERT INTO tbl_grades (
    enroll_id, teacher_id, grading_period,
    ww_total, pt_total, qa_score,
    ww_ps, pt_ps, qa_ps,
    ww_ws, pt_ws, qa_ws,
    initial_grade, period_grade, term_id, status, is_direct_input,
    submitted_at, approved_by, approved_at, finalized_at, remarks,
    created_at, updated_at
)
SELECT
    ne.id AS enroll_id,
    g.teacher_id,
    g.grading_period,
    g.ww_total, g.pt_total, g.qa_score,
    g.ww_ps, g.pt_ps, g.qa_ps,
    g.ww_ws, g.pt_ws, g.qa_ws,
    g.initial_grade, g.period_grade,
    CASE
        WHEN g.term_id IS NULL THEN NULL
        WHEN g.term_id = @cur_sem1 THEN @prev_sem1
        WHEN g.term_id = @cur_sem2 THEN @prev_sem2
        WHEN g.term_id = @cur_summer THEN @prev_summer
        ELSE NULL
    END AS term_id,
    g.status,
    g.is_direct_input,
    g.submitted_at,
    g.approved_by,
    g.approved_at,
    g.finalized_at,
    g.remarks,
    NOW(),
    NOW()
FROM tbl_grades g
JOIN tbl_enroll ce ON ce.id = g.enroll_id
JOIN tbl_enroll ne
  ON ne.student_id = ce.student_id
 AND ne.subject_id = ce.subject_id
 AND ne.sy_id = @prev_sy_id
WHERE ce.student_id = @student_id
  AND ce.sy_id = @current_sy_id
  AND g.status IN ('approved', 'finalized')
  AND NOT EXISTS (
      SELECT 1
      FROM tbl_grades gx
      WHERE gx.enroll_id = ne.id
        AND gx.grading_period = g.grading_period
        AND (
            (gx.term_id IS NULL AND g.term_id IS NULL)
            OR
            (gx.term_id = CASE
                WHEN g.term_id = @cur_sem1 THEN @prev_sem1
                WHEN g.term_id = @cur_sem2 THEN @prev_sem2
                WHEN g.term_id = @cur_summer THEN @prev_summer
                ELSE NULL
            END)
        )
  );

COMMIT;

-- ============================================================
-- Validation queries
-- ============================================================

SELECT st.student_no, st.given_name, st.last_name, sy.sy_name, COUNT(*) AS subject_count
FROM tbl_enroll e
JOIN tbl_student st ON st.id = e.student_id
JOIN tbl_sy sy ON sy.id = e.sy_id
WHERE st.student_no = @student_no
GROUP BY st.student_no, st.given_name, st.last_name, sy.sy_name
ORDER BY sy.sy_name DESC;

SELECT sy.id, sy.sy_name
FROM tbl_enroll e
JOIN tbl_sy sy ON sy.id = e.sy_id
JOIN tbl_student st ON st.id = e.student_id
WHERE st.student_no = @student_no
GROUP BY sy.id, sy.sy_name
ORDER BY sy.id DESC;

-- Expected result for UI:
--   Student should now have at least 2 SY options in:
--   - student/dashboard.php (college)
--   - student/grades.php
--   - student/subjects.php
