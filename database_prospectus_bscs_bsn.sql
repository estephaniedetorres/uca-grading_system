-- =====================================================================
-- BSCS & BSN Prospectus Population
-- Adds college subjects and populates prospectus for BSCS and BSN curricula
-- =====================================================================

-- Additional CS subjects
INSERT INTO tbl_subjects (subjcode, `desc`, unit, lec_u, lab_u, lec_h, lab_h, type, education_level, weight_category, term_restriction, status) VALUES
('CC104', 'Data Structures and Algorithms', 3, 2, 1, 3, 3, 'Major', 'college', 'core', 'any', 'active'),
('CC105', 'Information Management', 3, 2, 1, 3, 3, 'Major', 'college', 'core', 'any', 'active'),
('CC106', 'Application Development', 3, 2, 1, 3, 3, 'Major', 'college', 'core', 'any', 'active'),
('CS101', 'Discrete Mathematics', 3, 3, 0, 3, 0, 'Major', 'college', 'science_math', 'any', 'active'),
('CS102', 'Object-Oriented Programming', 3, 2, 1, 3, 3, 'Major', 'college', 'core', 'any', 'active'),
('CS103', 'Architecture and Organization', 3, 3, 0, 3, 0, 'Major', 'college', 'core', 'any', 'active'),
('CS104', 'Operating Systems', 3, 2, 1, 3, 3, 'Major', 'college', 'core', 'any', 'active'),
('CS105', 'Networks and Communications', 3, 2, 1, 3, 3, 'Major', 'college', 'core', 'any', 'active'),
('CS106', 'Automata Theory and Formal Languages', 3, 3, 0, 3, 0, 'Major', 'college', 'core', 'any', 'active'),
('CS107', 'Algorithms and Complexity', 3, 3, 0, 3, 0, 'Major', 'college', 'science_math', 'any', 'active'),
('CS108', 'Programming Languages', 3, 2, 1, 3, 3, 'Major', 'college', 'core', 'any', 'active'),
('CS109', 'Software Engineering 2', 3, 2, 1, 3, 3, 'Major', 'college', 'core', 'any', 'active'),
('CS110', 'Intelligent Systems', 3, 2, 1, 3, 3, 'Major', 'college', 'core', 'any', 'active'),
('CS-ELEC1', 'CS Elective 1', 3, 2, 1, 3, 3, 'Major', 'college', 'core', 'any', 'active'),
('CS-ELEC2', 'CS Elective 2', 3, 2, 1, 3, 3, 'Major', 'college', 'core', 'any', 'active'),
('CS-THESIS1', 'Thesis Writing 1', 3, 1, 2, 1, 6, 'Major', 'college', 'core', 'any', 'active'),
('CS-THESIS2', 'Thesis Writing 2', 3, 1, 2, 1, 6, 'Major', 'college', 'core', 'any', 'active'),
('CS-OJT', 'On-the-Job Training', 3, 0, 3, 0, 9, 'Major', 'college', 'core', 'any', 'active');

-- Additional GE / Minor subjects
INSERT INTO tbl_subjects (subjcode, `desc`, unit, lec_u, lab_u, lec_h, lab_h, type, education_level, weight_category, term_restriction, status) VALUES
('GEC106', 'Art Appreciation', 3, 3, 0, 3, 0, 'Minor', 'college', 'core', 'any', 'active'),
('GEC107', 'Science, Technology and Society', 3, 3, 0, 3, 0, 'Minor', 'college', 'core', 'any', 'active'),
('GEC108', 'Ethics', 3, 3, 0, 3, 0, 'Minor', 'college', 'core', 'any', 'active'),
('GEC109', 'Life and Works of Rizal', 3, 3, 0, 3, 0, 'Minor', 'college', 'core', 'any', 'active'),
('PE2', 'Physical Education 2 - Fitness Activities', 2, 1, 1, 2, 2, 'Minor', 'college', 'core', 'any', 'active'),
('PE3', 'Physical Education 3 - Team Sports', 2, 1, 1, 2, 2, 'Minor', 'college', 'core', 'any', 'active'),
('PE4', 'Physical Education 4 - Recreation', 2, 1, 1, 2, 2, 'Minor', 'college', 'core', 'any', 'active'),
('NSTP2', 'National Service Training Program 2', 3, 3, 0, 3, 0, 'Minor', 'college', 'core', 'any', 'active'),
('MATH01', 'Linear Algebra', 3, 3, 0, 3, 0, 'Minor', 'college', 'science_math', 'any', 'active'),
('STAT01', 'Probability and Statistics', 3, 3, 0, 3, 0, 'Minor', 'college', 'science_math', 'any', 'active');

-- BSN-specific subjects
INSERT INTO tbl_subjects (subjcode, `desc`, unit, lec_u, lab_u, lec_h, lab_h, type, education_level, weight_category, term_restriction, status) VALUES
('NCM102', 'Health Assessment', 4, 2, 2, 3, 6, 'Major', 'college', 'core', 'any', 'active'),
('NCM103', 'Pharmacology', 3, 3, 0, 3, 0, 'Major', 'college', 'core', 'any', 'active'),
('NCM104', 'Care of Mother, Child, and Adolescent (Well)', 5, 3, 2, 3, 6, 'Major', 'college', 'core', 'any', 'active'),
('NCM105', 'Care of Mother, Child, and Adolescent (At Risk)', 5, 3, 2, 3, 6, 'Major', 'college', 'core', 'any', 'active'),
('NCM106', 'Care of Clients with Problems in Oxygenation', 5, 3, 2, 3, 6, 'Major', 'college', 'core', 'any', 'active'),
('NCM107', 'Care of Clients with Maladaptive Patterns', 5, 3, 2, 3, 6, 'Major', 'college', 'core', 'any', 'active'),
('NCM108', 'Community Health Nursing 1', 4, 2, 2, 3, 6, 'Major', 'college', 'core', 'any', 'active'),
('NCM109', 'Community Health Nursing 2', 4, 2, 2, 3, 6, 'Major', 'college', 'core', 'any', 'active'),
('NCM110', 'Nursing Research 1', 3, 2, 1, 3, 3, 'Major', 'college', 'core', 'any', 'active'),
('NCM111', 'Nursing Research 2', 3, 2, 1, 3, 3, 'Major', 'college', 'core', 'any', 'active'),
('NCM112', 'Nursing Leadership and Management', 4, 2, 2, 3, 6, 'Major', 'college', 'core', 'any', 'active'),
('NCM-RLE1', 'Related Learning Experience 1', 2, 0, 2, 0, 6, 'Major', 'college', 'core', 'any', 'active'),
('NCM-RLE2', 'Related Learning Experience 2', 3, 0, 3, 0, 9, 'Major', 'college', 'core', 'any', 'active'),
('NCM-RLE3', 'Related Learning Experience 3', 3, 0, 3, 0, 9, 'Major', 'college', 'core', 'any', 'active'),
('NCM-RLE4', 'Related Learning Experience 4', 4, 0, 4, 0, 12, 'Major', 'college', 'core', 'any', 'active'),
('MICRO01', 'Microbiology and Parasitology', 3, 2, 1, 3, 3, 'Major', 'college', 'science_math', 'any', 'active'),
('BIOCHEM01', 'Biochemistry', 3, 2, 1, 3, 3, 'Major', 'college', 'science_math', 'any', 'active'),
('NUT01', 'Nutrition and Diet Therapy', 3, 3, 0, 3, 0, 'Major', 'college', 'core', 'any', 'active');

-- =====================================================================
-- BSCS Prospectus (curriculum_id=22)
-- Levels: BSCS-1(22), BSCS-2(23), BSCS-3(24), BSCS-4(25)
-- Terms: Semester 1(13), Semester 2(14)
-- =====================================================================

-- Use subject IDs from the inserts above (adjust IDs as needed for your DB)
-- This section uses subjcode references; run after subjects are inserted

INSERT INTO tbl_prospectus (curriculum_id, level_id, subject_id, term_id, status)
SELECT 22, 22, s.id, 13, 'active' FROM tbl_subjects s WHERE s.subjcode IN ('GEC101','GEC104','GEC105','CC101','PE1','NSTP1')
UNION ALL
SELECT 22, 22, s.id, 14, 'active' FROM tbl_subjects s WHERE s.subjcode IN ('GEC102','GEC103','CC102','CC103','PE2','NSTP2')
UNION ALL
SELECT 22, 23, s.id, 13, 'active' FROM tbl_subjects s WHERE s.subjcode IN ('CS101','CS102','CC104','GEC106','PE3','STAT01')
UNION ALL
SELECT 22, 23, s.id, 14, 'active' FROM tbl_subjects s WHERE s.subjcode IN ('CS103','CC105','CC106','GEC107','MATH01','PE4')
UNION ALL
SELECT 22, 24, s.id, 13, 'active' FROM tbl_subjects s WHERE s.subjcode IN ('CS104','CS105','CS106','SOFTENG1','GEC108','CS-ELEC1')
UNION ALL
SELECT 22, 24, s.id, 14, 'active' FROM tbl_subjects s WHERE s.subjcode IN ('CS107','CS108','CS109','CS110','GEC109','CS-ELEC2')
UNION ALL
SELECT 22, 25, s.id, 13, 'active' FROM tbl_subjects s WHERE s.subjcode IN ('CS-THESIS1','CS-OJT')
UNION ALL
SELECT 22, 25, s.id, 14, 'active' FROM tbl_subjects s WHERE s.subjcode IN ('CS-THESIS2');

-- =====================================================================
-- BSN Prospectus (curriculum_id=26)
-- Levels: BSN-1(30), BSN-2(31), BSN-3(32), BSN-4(33)
-- Terms: Semester 1(13), Semester 2(14)
-- =====================================================================

INSERT INTO tbl_prospectus (curriculum_id, level_id, subject_id, term_id, status)
SELECT 26, 30, s.id, 13, 'active' FROM tbl_subjects s WHERE s.subjcode IN ('GEC101','GEC104','GEC105','BIO101','PE1','NSTP1','NCM100')
UNION ALL
SELECT 26, 30, s.id, 14, 'active' FROM tbl_subjects s WHERE s.subjcode IN ('GEC102','GEC103','CHEM01','ANAT01','PE2','NSTP2','NCM101')
UNION ALL
SELECT 26, 31, s.id, 13, 'active' FROM tbl_subjects s WHERE s.subjcode IN ('NCM102','NCM103','NCM104','MICRO01','GEC106','PE3','NCM-RLE1')
UNION ALL
SELECT 26, 31, s.id, 14, 'active' FROM tbl_subjects s WHERE s.subjcode IN ('NCM105','NCM106','BIOCHEM01','NUT01','GEC107','PE4','NCM-RLE2')
UNION ALL
SELECT 26, 32, s.id, 13, 'active' FROM tbl_subjects s WHERE s.subjcode IN ('NCM107','NCM108','NCM110','GEC108','GEC109','NCM-RLE3')
UNION ALL
SELECT 26, 32, s.id, 14, 'active' FROM tbl_subjects s WHERE s.subjcode IN ('NCM109','NCM111','NCM112','NCM-RLE4');
