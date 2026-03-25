-- Sample SHS subjects and prospectus entries (Semester 1 and 2)
-- Assumes Semester 1 = term_id 6, Semester 2 = term_id 7
-- Assumes SHS STEM 11 curriculum_id = 14

-- SHS Semester 1 subjects (no units/hours)
INSERT INTO tbl_subjects (subjcode, `desc`, unit, lec_u, lab_u, lec_h, lab_h, type, education_level, weight_category, term_restriction, status)
SELECT 'SHS-ENG1', 'Oral Communication', NULL, NULL, NULL, NULL, NULL, 'Core', 'k12', 'languages', 'any', 'active'
WHERE NOT EXISTS (SELECT 1 FROM tbl_subjects WHERE subjcode = 'SHS-ENG1');

INSERT INTO tbl_subjects (subjcode, `desc`, unit, lec_u, lab_u, lec_h, lab_h, type, education_level, weight_category, term_restriction, status)
SELECT 'SHS-MATH1', 'General Mathematics', NULL, NULL, NULL, NULL, NULL, 'Core', 'k12', 'science_math', 'any', 'active'
WHERE NOT EXISTS (SELECT 1 FROM tbl_subjects WHERE subjcode = 'SHS-MATH1');

INSERT INTO tbl_subjects (subjcode, `desc`, unit, lec_u, lab_u, lec_h, lab_h, type, education_level, weight_category, term_restriction, status)
SELECT 'SHS-SCI1', 'Earth and Life Science', NULL, NULL, NULL, NULL, NULL, 'Core', 'k12', 'science_math', 'any', 'active'
WHERE NOT EXISTS (SELECT 1 FROM tbl_subjects WHERE subjcode = 'SHS-SCI1');

-- SHS Semester 2 subjects (no units/hours)
INSERT INTO tbl_subjects (subjcode, `desc`, unit, lec_u, lab_u, lec_h, lab_h, type, education_level, weight_category, term_restriction, status)
SELECT 'SHS-ENG2', 'Reading and Writing Skills', NULL, NULL, NULL, NULL, NULL, 'Core', 'k12', 'languages', 'any', 'active'
WHERE NOT EXISTS (SELECT 1 FROM tbl_subjects WHERE subjcode = 'SHS-ENG2');

INSERT INTO tbl_subjects (subjcode, `desc`, unit, lec_u, lab_u, lec_h, lab_h, type, education_level, weight_category, term_restriction, status)
SELECT 'SHS-MATH2', 'Statistics and Probability', NULL, NULL, NULL, NULL, NULL, 'Core', 'k12', 'science_math', 'any', 'active'
WHERE NOT EXISTS (SELECT 1 FROM tbl_subjects WHERE subjcode = 'SHS-MATH2');

INSERT INTO tbl_subjects (subjcode, `desc`, unit, lec_u, lab_u, lec_h, lab_h, type, education_level, weight_category, term_restriction, status)
SELECT 'SHS-SCI2', 'Physical Science', NULL, NULL, NULL, NULL, NULL, 'Core', 'k12', 'science_math', 'any', 'active'
WHERE NOT EXISTS (SELECT 1 FROM tbl_subjects WHERE subjcode = 'SHS-SCI2');

-- Prospectus entries for STEM 11 (curriculum_id = 14)
INSERT INTO tbl_prospectus (curriculum_id, subject_id, term_id, status)
SELECT 14, s.id, 6, 'active'
FROM tbl_subjects s
WHERE s.subjcode IN ('SHS-ENG1', 'SHS-MATH1', 'SHS-SCI1')
  AND NOT EXISTS (
      SELECT 1 FROM tbl_prospectus p
      WHERE p.curriculum_id = 14 AND p.subject_id = s.id AND p.term_id = 6
  );

INSERT INTO tbl_prospectus (curriculum_id, subject_id, term_id, status)
SELECT 14, s.id, 7, 'active'
FROM tbl_subjects s
WHERE s.subjcode IN ('SHS-ENG2', 'SHS-MATH2', 'SHS-SCI2')
  AND NOT EXISTS (
      SELECT 1 FROM tbl_prospectus p
      WHERE p.curriculum_id = 14 AND p.subject_id = s.id AND p.term_id = 7
  );
