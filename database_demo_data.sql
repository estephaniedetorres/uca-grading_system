-- =========================================================================
-- DEMO DATA POPULATION SCRIPT
-- For Elementary (ELEM), Senior High School (SHS), and College (CCTE/CON)
-- At least 10 students per level with full enrollment + grades
--
-- IMPORTANT:
-- This script is intended for a CLEAN database schema.
-- Do NOT run this after importing database.sql from this repository,
-- because the main dump already contains these same demo rows.
-- Running both will cause duplicate key conflicts on IDs, usernames,
-- student numbers, section codes, and subject IDs.
-- =========================================================================

SET @sy_id = 2;  -- 2025-2026

-- =========================================================================
-- 1. COLLEGE SUBJECTS (we only have SOFTENG1, need more for BSCS & BSN)
-- =========================================================================
INSERT INTO tbl_subjects (id, subjcode, `desc`, unit, lec_u, lab_u, lec_h, lab_h, education_level, type, status) VALUES
(73, 'GEC101', 'Understanding the Self', 3, 3, 0, 3, 0, 'college', 'Minor', 'active'),
(74, 'GEC102', 'Readings in Philippine History', 3, 3, 0, 3, 0, 'college', 'Minor', 'active'),
(75, 'GEC103', 'The Contemporary World', 3, 3, 0, 3, 0, 'college', 'Minor', 'active'),
(76, 'GEC104', 'Mathematics in the Modern World', 3, 3, 0, 3, 0, 'college', 'Minor', 'active'),
(77, 'GEC105', 'Purposive Communication', 3, 3, 0, 3, 0, 'college', 'Minor', 'active'),
(78, 'CC101', 'Introduction to Computing', 3, 2, 1, 2, 3, 'college', 'Major', 'active'),
(79, 'CC102', 'Computer Programming 1', 3, 2, 1, 2, 3, 'college', 'Major', 'active'),
(80, 'CC103', 'Computer Programming 2', 3, 2, 1, 2, 3, 'college', 'Major', 'active'),
(81, 'PE1', 'Physical Education 1 - Movement Enhancement', 2, 1, 1, 1, 2, 'college', 'Minor', 'active'),
(82, 'NSTP1', 'National Service Training Program 1', 3, 3, 0, 3, 0, 'college', 'Minor', 'active'),
(83, 'NCM100', 'Theoretical Foundations of Nursing', 3, 3, 0, 3, 0, 'college', 'Major', 'active'),
(84, 'NCM101', 'Fundamentals of Nursing Practice', 5, 3, 2, 3, 6, 'college', 'Major', 'active'),
(85, 'ANAT01', 'Human Anatomy and Physiology', 5, 3, 2, 3, 6, 'college', 'Major', 'active'),
(86, 'BIO101', 'General Biology for Health Sciences', 3, 2, 1, 2, 3, 'college', 'Major', 'active'),
(87, 'CHEM01', 'General Chemistry for Health Sciences', 3, 2, 1, 2, 3, 'college', 'Major', 'active');

-- Add BSCS Semester 1 subjects to prospectus
INSERT INTO tbl_prospectus (curriculum_id, subject_id, term_id, status) VALUES
(22, 73, 6, 'active'),  -- GEC101 Sem1
(22, 76, 6, 'active'),  -- GEC104 Sem1
(22, 78, 6, 'active'),  -- CC101  Sem1
(22, 81, 6, 'active'),  -- PE1    Sem1
(22, 82, 6, 'active'),  -- NSTP1  Sem1
(22, 74, 7, 'active'),  -- GEC102 Sem2
(22, 77, 7, 'active'),  -- GEC105 Sem2
(22, 79, 7, 'active'),  -- CC102  Sem2
(22, 75, 7, 'active');  -- GEC103 Sem2

-- Add BSN Semester 1 subjects to prospectus
INSERT INTO tbl_prospectus (curriculum_id, subject_id, term_id, status) VALUES
(26, 73, 6, 'active'),  -- GEC101 Sem1
(26, 83, 6, 'active'),  -- NCM100 Sem1
(26, 85, 6, 'active'),  -- ANAT01 Sem1
(26, 86, 6, 'active'),  -- BIO101 Sem1
(26, 74, 7, 'active'),  -- GEC102 Sem2
(26, 84, 7, 'active'),  -- NCM101 Sem2
(26, 87, 7, 'active'),  -- CHEM01 Sem2
(26, 77, 7, 'active');  -- GEC105 Sem2

-- =========================================================================
-- 2. NEW SECTIONS (for more grade levels)
-- =========================================================================
INSERT INTO tbl_section (id, section_code, academic_track_id, sy_id, adviser_id, status) VALUES
(13, 'GRADE3-A', 6, 2, 3, 'active'),    -- Grade 3
(14, 'GRADE5-A', 8, 2, 3, 'active'),    -- Grade 5
(15, 'ABM11-A', 16, 2, 4, 'active'),    -- ABM 11
(16, 'HUMSS11-A', 18, 2, 4, 'active'),  -- HUMSS 11
(17, 'BSCS-2A', 22, 2, NULL, 'active'), -- BSCS 2nd Year
(18, 'BSIT-1A', 23, 2, NULL, 'active'); -- BSIT 1st Year

-- =========================================================================
-- 3. NEW TEACHERS (for broader coverage)
-- =========================================================================
INSERT INTO tbl_users (id, username, password, role, status) VALUES
(37, 't_reyes', 'teacherpass', 'teacher', 'active'),
(38, 't_santos', 'teacherpass', 'teacher', 'active'),
(39, 't_garcia', 'teacherpass', 'teacher', 'active');

INSERT INTO tbl_teacher (id, user_id, name, email) VALUES
(5, 37, 'T. Reyes', 'reyes@example.com'),
(6, 38, 'T. Santos', 'santos@example.com'),
(7, 39, 'T. Garcia', 'garcia@example.com');

-- =========================================================================
-- 4. TEACHER-SUBJECT ASSIGNMENTS
-- =========================================================================
INSERT INTO tbl_teacher_subject (teacher_id, subject_id, section_id, sy_id) VALUES
-- T. Reyes teaches ELEM subjects
(5, 29, 3, 2),   -- ENG in GRADE1-A
(5, 28, 3, 2),   -- FIL in GRADE1-A
(5, 30, 3, 2),   -- MATH in GRADE1-A
(5, 32, 3, 2),   -- SCI in GRADE1-A
(5, 31, 3, 2),   -- AP in GRADE1-A
(5, 33, 3, 2),   -- EsP in GRADE1-A
(5, 34, 3, 2),   -- MAPEH in GRADE1-A
(5, 35, 3, 2),   -- EPP in GRADE1-A
(5, 36, 3, 2),   -- TLE in GRADE1-A
(5, 27, 3, 2),   -- MTB-MLE in GRADE1-A
(5, 29, 13, 2),  -- ENG in GRADE3-A
(5, 28, 13, 2),  -- FIL in GRADE3-A
(5, 30, 13, 2),  -- MATH in GRADE3-A
(5, 32, 13, 2),  -- SCI in GRADE3-A
(5, 31, 13, 2),  -- AP in GRADE3-A
(5, 33, 13, 2),  -- EsP in GRADE3-A
(5, 34, 13, 2),  -- MAPEH in GRADE3-A
(5, 35, 13, 2),  -- EPP in GRADE3-A
(5, 36, 13, 2),  -- TLE in GRADE3-A
(5, 27, 13, 2),  -- MTB-MLE in GRADE3-A
(5, 29, 14, 2),  -- ENG in GRADE5-A
(5, 28, 14, 2),  -- FIL in GRADE5-A
(5, 30, 14, 2),  -- MATH in GRADE5-A
(5, 32, 14, 2),  -- SCI in GRADE5-A
(5, 31, 14, 2),  -- AP in GRADE5-A
(5, 33, 14, 2),  -- EsP in GRADE5-A
(5, 34, 14, 2),  -- MAPEH in GRADE5-A
(5, 35, 14, 2),  -- EPP in GRADE5-A
(5, 36, 14, 2),  -- TLE in GRADE5-A
(5, 27, 14, 2),  -- MTB-MLE in GRADE5-A

-- T. Santos teaches SHS subjects
(6, 38, 6, 2),   -- ORALCOMM in STEM11-A
(6, 40, 6, 2),   -- GENMATH in STEM11-A
(6, 41, 6, 2),   -- EARTHSCI in STEM11-A
(6, 44, 6, 2),   -- PHILO in STEM11-A
(6, 45, 6, 2),   -- PE in STEM11-A
(6, 48, 6, 2),   -- PRECAL in STEM11-A
(6, 39, 6, 2),   -- KOMPAN in STEM11-A
(6, 46, 6, 2),   -- ICT in STEM11-A
(6, 38, 15, 2),  -- ORALCOMM in ABM11-A
(6, 40, 15, 2),  -- GENMATH in ABM11-A
(6, 44, 15, 2),  -- PHILO in ABM11-A
(6, 45, 15, 2),  -- PE in ABM11-A
(6, 39, 15, 2),  -- KOMPAN in ABM11-A
(6, 46, 15, 2),  -- ICT in ABM11-A
(6, 54, 15, 2),  -- PERDEV in ABM11-A
(6, 65, 15, 2),  -- ENTREP in ABM11-A
(6, 38, 16, 2),  -- ORALCOMM in HUMSS11-A
(6, 40, 16, 2),  -- GENMATH in HUMSS11-A
(6, 44, 16, 2),  -- PHILO in HUMSS11-A
(6, 45, 16, 2),  -- PE in HUMSS11-A
(6, 39, 16, 2),  -- KOMPAN in HUMSS11-A
(6, 46, 16, 2),  -- ICT in HUMSS11-A
(6, 54, 16, 2),  -- PERDEV in HUMSS11-A
(6, 60, 16, 2),  -- UCCP in HUMSS11-A

-- T. Garcia teaches College subjects
(7, 72, 7, 2),   -- SOFTENG1 in BSCS-1A
(7, 73, 7, 2),   -- GEC101 in BSCS-1A
(7, 76, 7, 2),   -- GEC104 in BSCS-1A
(7, 78, 7, 2),   -- CC101 in BSCS-1A
(7, 81, 7, 2),   -- PE1 in BSCS-1A
(7, 82, 7, 2),   -- NSTP1 in BSCS-1A
(7, 73, 8, 2),   -- GEC101 in BSN-1A
(7, 83, 8, 2),   -- NCM100 in BSN-1A
(7, 85, 8, 2),   -- ANAT01 in BSN-1A
(7, 86, 8, 2),   -- BIO101 in BSN-1A
(7, 73, 18, 2),  -- GEC101 in BSIT-1A
(7, 78, 18, 2),  -- CC101 in BSIT-1A
(7, 76, 18, 2);  -- GEC104 in BSIT-1A

-- =========================================================================
-- 5. STUDENTS - ELEMENTARY (10 new students in GRADE1-A, GRADE3-A, GRADE5-A)
-- =========================================================================
INSERT INTO tbl_users (id, username, password, role, status) VALUES
-- ELEM students
(40, '25-00019', 'studentpass', 'student', 'active'),
(41, '25-00020', 'studentpass', 'student', 'active'),
(42, '25-00021', 'studentpass', 'student', 'active'),
(43, '25-00022', 'studentpass', 'student', 'active'),
(44, '25-00023', 'studentpass', 'student', 'active'),
(45, '25-00024', 'studentpass', 'student', 'active'),
(46, '25-00025', 'studentpass', 'student', 'active'),
(47, '25-00026', 'studentpass', 'student', 'active'),
(48, '25-00027', 'studentpass', 'student', 'active'),
(49, '25-00028', 'studentpass', 'student', 'active'),
-- SHS students
(50, '25-00029', 'studentpass', 'student', 'active'),
(51, '25-00030', 'studentpass', 'student', 'active'),
(52, '25-00031', 'studentpass', 'student', 'active'),
(53, '25-00032', 'studentpass', 'student', 'active'),
(54, '25-00033', 'studentpass', 'student', 'active'),
(55, '25-00034', 'studentpass', 'student', 'active'),
(56, '25-00035', 'studentpass', 'student', 'active'),
(57, '25-00036', 'studentpass', 'student', 'active'),
(58, '25-00037', 'studentpass', 'student', 'active'),
(59, '25-00038', 'studentpass', 'student', 'active'),
-- College students
(60, '25-00039', 'studentpass', 'student', 'active'),
(61, '25-00040', 'studentpass', 'student', 'active'),
(62, '25-00041', 'studentpass', 'student', 'active'),
(63, '25-00042', 'studentpass', 'student', 'active'),
(64, '25-00043', 'studentpass', 'student', 'active'),
(65, '25-00044', 'studentpass', 'student', 'active'),
(66, '25-00045', 'studentpass', 'student', 'active'),
(67, '25-00046', 'studentpass', 'student', 'active'),
(68, '25-00047', 'studentpass', 'student', 'active'),
(69, '25-00048', 'studentpass', 'student', 'active'),
(70, '25-00049', 'studentpass', 'student', 'active'),
(71, '25-00050', 'studentpass', 'student', 'active');

-- ELEMENTARY STUDENTS (section_id 3=GRADE1-A, 13=GRADE3-A, 14=GRADE5-A)
INSERT INTO tbl_student (id, user_id, student_no, given_name, middle_name, last_name, section_id, guardian_name, guardian_contact, sex, date_of_birth, place_of_birth, address) VALUES
(18, 40, '25-00019', 'Maria',    'Santos',   'Reyes',     3,  'Ana Reyes',        '09171234501', 'Female', '2019-03-15', 'Manila', 'Brgy. San Antonio, Manila'),
(19, 41, '25-00020', 'Jose',     'Cruz',     'Garcia',    3,  'Pedro Garcia',     '09171234502', 'Male',   '2019-07-22', 'Quezon City', 'Brgy. Holy Spirit, QC'),
(20, 42, '25-00021', 'Ana',      'Lopez',    'Martinez',  3,  'Rosa Martinez',    '09171234503', 'Female', '2019-01-10', 'Pasig', 'Brgy. Kapitolyo, Pasig'),
(21, 43, '25-00022', 'Carlos',   'Ramos',    'Hernandez', 13, 'Luis Hernandez',   '09171234504', 'Male',   '2017-05-18', 'Marikina', 'Brgy. Concepcion, Marikina'),
(22, 44, '25-00023', 'Sofia',    'Torres',   'Flores',    13, 'Carmen Flores',    '09171234505', 'Female', '2017-09-30', 'Taguig', 'Brgy. Western Bicutan, Taguig'),
(23, 45, '25-00024', 'Miguel',   'Navarro',  'Rivera',    13, 'Roberto Rivera',   '09171234506', 'Male',   '2017-02-14', 'Makati', 'Brgy. Poblacion, Makati'),
(24, 46, '25-00025', 'Isabella', 'Mendoza',  'Santos',    13, 'Elena Santos',     '09171234507', 'Female', '2017-11-25', 'Manila', 'Brgy. Sampaloc, Manila'),
(25, 47, '25-00026', 'Gabriel',  'Villanueva','Castillo', 14, 'Mario Castillo',   '09171234508', 'Male',   '2015-06-12', 'Pasay', 'Brgy. San Isidro, Pasay'),
(26, 48, '25-00027', 'Lucia',    'Dela Cruz','Morales',   14, 'Teresa Morales',   '09171234509', 'Female', '2015-08-08', 'Caloocan', 'Brgy. Bagong Barrio, Caloocan'),
(27, 49, '25-00028', 'Rafael',   'Salazar',  'Aquino',    14, 'Diego Aquino',     '09171234510', 'Male',   '2015-12-01', 'Las Pinas', 'Brgy. Almanza, Las Pinas');

-- SHS STUDENTS (section_id 6=STEM11-A, 15=ABM11-A, 16=HUMSS11-A)
INSERT INTO tbl_student (id, user_id, student_no, given_name, middle_name, last_name, section_id, guardian_name, guardian_contact, sex, date_of_birth, place_of_birth, address, secondary_school, secondary_school_address, secondary_school_year) VALUES
(28, 50, '25-00029', 'Angelo',   'Bautista', 'Pascual',   6,  'Marta Pascual',    '09181234501', 'Male',   '2009-04-20', 'Quezon City', 'Brgy. Bagong Pag-asa, QC', 'QC National High School', 'Quezon City', '2023-2025'),
(29, 51, '25-00030', 'Patricia', 'Cordero',  'Villanueva',6,  'Edith Villanueva', '09181234502', 'Female', '2009-08-15', 'Manila', 'Brgy. Ermita, Manila', 'Manila Science HS', 'Manila', '2023-2025'),
(30, 52, '25-00031', 'John',     'Espinoza', 'Ramos',     6,  'Henry Ramos',      '09181234503', 'Male',   '2009-11-03', 'Pasig', 'Brgy. Pinagbuhatan, Pasig', 'Pasig City Science HS', 'Pasig City', '2023-2025'),
(31, 53, '25-00032', 'Catherine','Francisco','Soriano',    6,  'Alex Soriano',     '09181234504', 'Female', '2009-02-28', 'Taguig', 'Brgy. Signal Village, Taguig', 'Taguig City NHS', 'Taguig', '2023-2025'),
(32, 54, '25-00033', 'Ryan',     'Galvez',   'Mercado',  15,  'Sandra Mercado',   '09181234505', 'Male',   '2009-06-17', 'Makati', 'Brgy. Guadalupe, Makati', 'Makati Science HS', 'Makati', '2023-2025'),
(33, 55, '25-00034', 'Jasmine',  'Hidalgo',  'Cruz',     15,  'Gloria Cruz',      '09181234506', 'Female', '2009-10-09', 'Marikina', 'Brgy. Sto. Nino, Marikina', 'Marikina HS', 'Marikina', '2023-2025'),
(34, 56, '25-00035', 'Kevin',    'Ignacio',  'Dela Rosa',15,  'Ernesto Dela Rosa','09181234507', 'Male',   '2009-01-25', 'San Juan', 'Brgy. Corazon de Jesus, SJ', 'San Juan NHS', 'San Juan', '2023-2025'),
(35, 57, '25-00036', 'Erica',    'Jimenez',  'Torres',   16,  'Lydia Torres',     '09181234508', 'Female', '2009-07-14', 'Mandaluyong', 'Brgy. Addition Hills, Mandaluyong', 'Mandaluyong HS', 'Mandaluyong', '2023-2025'),
(36, 58, '25-00037', 'Daniel',   'Lacson',   'Ocampo',   16,  'Felipe Ocampo',    '09181234509', 'Male',   '2009-03-03', 'Paranaque', 'Brgy. San Dionisio, Paranaque', 'Paranaque NHS', 'Paranaque', '2023-2025'),
(37, 59, '25-00038', 'Hannah',   'Magno',    'Reyes',    16,  'Victor Reyes',     '09181234510', 'Female', '2009-12-19', 'Valenzuela', 'Brgy. Karuhatan, Valenzuela', 'Valenzuela NHS', 'Valenzuela', '2023-2025');

-- COLLEGE STUDENTS (section_id 7=BSCS-1A, 8=BSN-1A, 18=BSIT-1A)
INSERT INTO tbl_student (id, user_id, student_no, given_name, middle_name, last_name, section_id, guardian_name, guardian_contact, sex, date_of_birth, place_of_birth, address,
    primary_school, primary_school_address, primary_school_year,
    intermediate_school, intermediate_school_address, intermediate_school_year,
    secondary_school, secondary_school_address, secondary_school_year,
    shs_school, shs_school_address, shs_school_year, shs_strand) VALUES
(38, 60, '25-00039', 'Andrei',   'Santos',   'Villanueva', 7,  'Marco Villanueva',  '09191234501', 'Male',   '2006-05-10', 'Manila',     'Brgy. Tondo, Manila',
    'Tondo Elementary School', 'Tondo, Manila', '2012-2015', 'Tondo Elementary School', 'Tondo, Manila', '2015-2018', 'Manila NHS', 'Manila', '2018-2022', 'Manila SHS', 'Manila', '2022-2024', 'STEM'),
(39, 61, '25-00040', 'Christine','Reyes',     'Bautista',   7,  'Lorna Bautista',    '09191234502', 'Female', '2006-09-22', 'Quezon City','Brgy. Tandang Sora, QC',
    'QC Central Elementary', 'Quezon City', '2012-2015', 'QC Central Elementary', 'Quezon City', '2015-2018', 'QC Science HS', 'Quezon City', '2018-2022', 'QC SHS', 'Quezon City', '2022-2024', 'STEM'),
(40, 62, '25-00041', 'Emmanuel', 'Cruz',      'Gonzales',   7,  'Lourdes Gonzales',  '09191234503', 'Male',   '2006-01-15', 'Pasig',     'Brgy. Ugong, Pasig',
    'Pasig Elementary School', 'Pasig City', '2012-2015', 'Pasig Elementary School', 'Pasig City', '2015-2018', 'Pasig Science HS', 'Pasig City', '2018-2022', 'Pasig SHS', 'Pasig City', '2022-2024', 'STEM'),
(41, 63, '25-00042', 'Diana',    'Lopez',     'Fernandez',  7,  'Ricardo Fernandez', '09191234504', 'Female', '2006-11-30', 'Makati',    'Brgy. Bel-Air, Makati',
    'Makati Elementary School', 'Makati City', '2012-2015', 'Makati Elementary School', 'Makati City', '2015-2018', 'Makati Science HS', 'Makati City', '2018-2022', 'Makati SHS', 'Makati City', '2022-2024', 'ABM'),
(42, 64, '25-00043', 'Francis',  'Torres',    'Mendoza',    8,  'Gloria Mendoza',    '09191234505', 'Male',   '2006-03-08', 'Taguig',    'Brgy. Fort Bonifacio, Taguig',
    'Taguig Elementary School', 'Taguig City', '2012-2015', 'Taguig Elementary School', 'Taguig City', '2015-2018', 'Taguig City NHS', 'Taguig City', '2018-2022', 'Taguig SHS', 'Taguig City', '2022-2024', 'STEM'),
(43, 65, '25-00044', 'Grace',    'Navarro',   'Salazar',    8,  'Reynaldo Salazar',  '09191234506', 'Female', '2006-07-19', 'Marikina',  'Brgy. Industrial Valley, Marikina',
    'Marikina Elem School', 'Marikina City', '2012-2015', 'Marikina Elem School', 'Marikina City', '2015-2018', 'Marikina HS', 'Marikina City', '2018-2022', 'Marikina SHS', 'Marikina City', '2022-2024', 'STEM'),
(44, 66, '25-00045', 'Harold',   'Ramos',     'Aguilar',    8,  'Susana Aguilar',    '09191234507', 'Male',   '2006-12-05', 'San Juan',  'Brgy. Greenhills, San Juan',
    'San Juan Elem School', 'San Juan City', '2012-2015', 'San Juan Elem School', 'San Juan City', '2015-2018', 'San Juan NHS', 'San Juan City', '2018-2022', 'San Juan SHS', 'San Juan City', '2022-2024', 'HUMSS'),
(45, 67, '25-00046', 'Irene',    'Espiritu',  'De Leon',    8,  'Fernando De Leon',  '09191234508', 'Female', '2006-04-27', 'Caloocan',  'Brgy. Grace Park, Caloocan',
    'Caloocan Elem School', 'Caloocan City', '2012-2015', 'Caloocan Elem School', 'Caloocan City', '2015-2018', 'Caloocan NHS', 'Caloocan City', '2018-2022', 'Caloocan SHS', 'Caloocan City', '2022-2024', 'STEM'),
(46, 68, '25-00047', 'Jerome',   'Villarosa', 'Tan',       18,  'William Tan',       '09191234509', 'Male',   '2006-08-13', 'Paranaque', 'Brgy. BF Homes, Paranaque',
    'Paranaque Elem', 'Paranaque City', '2012-2015', 'Paranaque Elem', 'Paranaque City', '2015-2018', 'Paranaque NHS', 'Paranaque City', '2018-2022', 'Paranaque SHS', 'Paranaque City', '2022-2024', 'ICT'),
(47, 69, '25-00048', 'Karen',    'Gutierrez', 'Lim',       18,  'Robert Lim',        '09191234510', 'Female', '2006-02-09', 'Las Pinas', 'Brgy. Talon, Las Pinas',
    'Las Pinas Elem', 'Las Pinas City', '2012-2015', 'Las Pinas Elem', 'Las Pinas City', '2015-2018', 'Las Pinas NHS', 'Las Pinas City', '2018-2022', 'Las Pinas SHS', 'Las Pinas City', '2022-2024', 'STEM'),
(48, 70, '25-00049', 'Lorenzo',  'Perez',     'Chan',       7,  'Michael Chan',      '09191234511', 'Male',   '2006-06-21', 'Muntinlupa','Brgy. Alabang, Muntinlupa',
    'Muntinlupa Elem', 'Muntinlupa City', '2012-2015', 'Muntinlupa Elem', 'Muntinlupa City', '2015-2018', 'Muntinlupa NHS', 'Muntinlupa City', '2018-2022', 'Muntinlupa SHS', 'Muntinlupa City', '2022-2024', 'STEM'),
(49, 71, '25-00050', 'Nicole',   'Manalo',    'Sy',        18,  'James Sy',          '09191234512', 'Female', '2006-10-16', 'Valenzuela','Brgy. Lingunan, Valenzuela',
    'Valenzuela Elem', 'Valenzuela City', '2012-2015', 'Valenzuela Elem', 'Valenzuela City', '2015-2018', 'Valenzuela NHS', 'Valenzuela City', '2018-2022', 'Valenzuela SHS', 'Valenzuela City', '2022-2024', 'STEM');

-- =========================================================================
-- 6. ENROLLMENTS
-- =========================================================================

-- ELEMENTARY - GRADE 1 students (18,19,20) enrolled in 10 subjects, quarterly
-- Subjects: ENG(29), FIL(28), MATH(30), SCI(32), AP(31), EsP(33), MAPEH(34), EPP(35), TLE(36), MTB-MLE(27)
INSERT INTO tbl_enroll (student_id, subject_id, section_id, teacher_id, sy_id, term_id, status) VALUES
-- Maria (18) - GRADE1-A
(18, 29, 3, 5, 2, NULL, 'enrolled'), (18, 28, 3, 5, 2, NULL, 'enrolled'),
(18, 30, 3, 5, 2, NULL, 'enrolled'), (18, 32, 3, 5, 2, NULL, 'enrolled'),
(18, 31, 3, 5, 2, NULL, 'enrolled'), (18, 33, 3, 5, 2, NULL, 'enrolled'),
(18, 34, 3, 5, 2, NULL, 'enrolled'), (18, 35, 3, 5, 2, NULL, 'enrolled'),
(18, 36, 3, 5, 2, NULL, 'enrolled'), (18, 27, 3, 5, 2, NULL, 'enrolled'),
-- Jose (19) - GRADE1-A
(19, 29, 3, 5, 2, NULL, 'enrolled'), (19, 28, 3, 5, 2, NULL, 'enrolled'),
(19, 30, 3, 5, 2, NULL, 'enrolled'), (19, 32, 3, 5, 2, NULL, 'enrolled'),
(19, 31, 3, 5, 2, NULL, 'enrolled'), (19, 33, 3, 5, 2, NULL, 'enrolled'),
(19, 34, 3, 5, 2, NULL, 'enrolled'), (19, 35, 3, 5, 2, NULL, 'enrolled'),
(19, 36, 3, 5, 2, NULL, 'enrolled'), (19, 27, 3, 5, 2, NULL, 'enrolled'),
-- Ana (20) - GRADE1-A
(20, 29, 3, 5, 2, NULL, 'enrolled'), (20, 28, 3, 5, 2, NULL, 'enrolled'),
(20, 30, 3, 5, 2, NULL, 'enrolled'), (20, 32, 3, 5, 2, NULL, 'enrolled'),
(20, 31, 3, 5, 2, NULL, 'enrolled'), (20, 33, 3, 5, 2, NULL, 'enrolled'),
(20, 34, 3, 5, 2, NULL, 'enrolled'), (20, 35, 3, 5, 2, NULL, 'enrolled'),
(20, 36, 3, 5, 2, NULL, 'enrolled'), (20, 27, 3, 5, 2, NULL, 'enrolled');

-- ELEMENTARY - GRADE 3 students (21,22,23,24) enrolled in same subjects
INSERT INTO tbl_enroll (student_id, subject_id, section_id, teacher_id, sy_id, term_id, status) VALUES
-- Carlos (21) - GRADE3-A
(21, 29, 13, 5, 2, NULL, 'enrolled'), (21, 28, 13, 5, 2, NULL, 'enrolled'),
(21, 30, 13, 5, 2, NULL, 'enrolled'), (21, 32, 13, 5, 2, NULL, 'enrolled'),
(21, 31, 13, 5, 2, NULL, 'enrolled'), (21, 33, 13, 5, 2, NULL, 'enrolled'),
(21, 34, 13, 5, 2, NULL, 'enrolled'), (21, 35, 13, 5, 2, NULL, 'enrolled'),
(21, 36, 13, 5, 2, NULL, 'enrolled'), (21, 27, 13, 5, 2, NULL, 'enrolled'),
-- Sofia (22) - GRADE3-A
(22, 29, 13, 5, 2, NULL, 'enrolled'), (22, 28, 13, 5, 2, NULL, 'enrolled'),
(22, 30, 13, 5, 2, NULL, 'enrolled'), (22, 32, 13, 5, 2, NULL, 'enrolled'),
(22, 31, 13, 5, 2, NULL, 'enrolled'), (22, 33, 13, 5, 2, NULL, 'enrolled'),
(22, 34, 13, 5, 2, NULL, 'enrolled'), (22, 35, 13, 5, 2, NULL, 'enrolled'),
(22, 36, 13, 5, 2, NULL, 'enrolled'), (22, 27, 13, 5, 2, NULL, 'enrolled'),
-- Miguel (23) - GRADE3-A
(23, 29, 13, 5, 2, NULL, 'enrolled'), (23, 28, 13, 5, 2, NULL, 'enrolled'),
(23, 30, 13, 5, 2, NULL, 'enrolled'), (23, 32, 13, 5, 2, NULL, 'enrolled'),
(23, 31, 13, 5, 2, NULL, 'enrolled'), (23, 33, 13, 5, 2, NULL, 'enrolled'),
(23, 34, 13, 5, 2, NULL, 'enrolled'), (23, 35, 13, 5, 2, NULL, 'enrolled'),
(23, 36, 13, 5, 2, NULL, 'enrolled'), (23, 27, 13, 5, 2, NULL, 'enrolled'),
-- Isabella (24) - GRADE3-A
(24, 29, 13, 5, 2, NULL, 'enrolled'), (24, 28, 13, 5, 2, NULL, 'enrolled'),
(24, 30, 13, 5, 2, NULL, 'enrolled'), (24, 32, 13, 5, 2, NULL, 'enrolled'),
(24, 31, 13, 5, 2, NULL, 'enrolled'), (24, 33, 13, 5, 2, NULL, 'enrolled'),
(24, 34, 13, 5, 2, NULL, 'enrolled'), (24, 35, 13, 5, 2, NULL, 'enrolled'),
(24, 36, 13, 5, 2, NULL, 'enrolled'), (24, 27, 13, 5, 2, NULL, 'enrolled');

-- ELEMENTARY - GRADE 5 students (25,26,27) enrolled in same subjects
INSERT INTO tbl_enroll (student_id, subject_id, section_id, teacher_id, sy_id, term_id, status) VALUES
-- Gabriel (25) - GRADE5-A
(25, 29, 14, 5, 2, NULL, 'enrolled'), (25, 28, 14, 5, 2, NULL, 'enrolled'),
(25, 30, 14, 5, 2, NULL, 'enrolled'), (25, 32, 14, 5, 2, NULL, 'enrolled'),
(25, 31, 14, 5, 2, NULL, 'enrolled'), (25, 33, 14, 5, 2, NULL, 'enrolled'),
(25, 34, 14, 5, 2, NULL, 'enrolled'), (25, 35, 14, 5, 2, NULL, 'enrolled'),
(25, 36, 14, 5, 2, NULL, 'enrolled'), (25, 27, 14, 5, 2, NULL, 'enrolled'),
-- Lucia (26) - GRADE5-A
(26, 29, 14, 5, 2, NULL, 'enrolled'), (26, 28, 14, 5, 2, NULL, 'enrolled'),
(26, 30, 14, 5, 2, NULL, 'enrolled'), (26, 32, 14, 5, 2, NULL, 'enrolled'),
(26, 31, 14, 5, 2, NULL, 'enrolled'), (26, 33, 14, 5, 2, NULL, 'enrolled'),
(26, 34, 14, 5, 2, NULL, 'enrolled'), (26, 35, 14, 5, 2, NULL, 'enrolled'),
(26, 36, 14, 5, 2, NULL, 'enrolled'), (26, 27, 14, 5, 2, NULL, 'enrolled'),
-- Rafael (27) - GRADE5-A
(27, 29, 14, 5, 2, NULL, 'enrolled'), (27, 28, 14, 5, 2, NULL, 'enrolled'),
(27, 30, 14, 5, 2, NULL, 'enrolled'), (27, 32, 14, 5, 2, NULL, 'enrolled'),
(27, 31, 14, 5, 2, NULL, 'enrolled'), (27, 33, 14, 5, 2, NULL, 'enrolled'),
(27, 34, 14, 5, 2, NULL, 'enrolled'), (27, 35, 14, 5, 2, NULL, 'enrolled'),
(27, 36, 14, 5, 2, NULL, 'enrolled'), (27, 27, 14, 5, 2, NULL, 'enrolled');

-- SHS - STEM11 students (28,29,30,31) enrolled in Semester 1 subjects
-- ORALCOMM(38), GENMATH(40), EARTHSCI(41), PHILO(44), PE(45), PRECAL(48), KOMPAN(39), ICT(46)
INSERT INTO tbl_enroll (student_id, subject_id, section_id, teacher_id, sy_id, term_id, status) VALUES
-- Angelo (28) - STEM11-A Sem1
(28, 38, 6, 6, 2, 6, 'enrolled'), (28, 40, 6, 6, 2, 6, 'enrolled'),
(28, 41, 6, 6, 2, 6, 'enrolled'), (28, 44, 6, 6, 2, 6, 'enrolled'),
(28, 45, 6, 6, 2, 6, 'enrolled'), (28, 48, 6, 6, 2, 6, 'enrolled'),
(28, 39, 6, 6, 2, 6, 'enrolled'), (28, 46, 6, 6, 2, 6, 'enrolled'),
-- Patricia (29) - STEM11-A Sem1
(29, 38, 6, 6, 2, 6, 'enrolled'), (29, 40, 6, 6, 2, 6, 'enrolled'),
(29, 41, 6, 6, 2, 6, 'enrolled'), (29, 44, 6, 6, 2, 6, 'enrolled'),
(29, 45, 6, 6, 2, 6, 'enrolled'), (29, 48, 6, 6, 2, 6, 'enrolled'),
(29, 39, 6, 6, 2, 6, 'enrolled'), (29, 46, 6, 6, 2, 6, 'enrolled'),
-- John (30) - STEM11-A Sem1
(30, 38, 6, 6, 2, 6, 'enrolled'), (30, 40, 6, 6, 2, 6, 'enrolled'),
(30, 41, 6, 6, 2, 6, 'enrolled'), (30, 44, 6, 6, 2, 6, 'enrolled'),
(30, 45, 6, 6, 2, 6, 'enrolled'), (30, 48, 6, 6, 2, 6, 'enrolled'),
(30, 39, 6, 6, 2, 6, 'enrolled'), (30, 46, 6, 6, 2, 6, 'enrolled'),
-- Catherine (31) - STEM11-A Sem1
(31, 38, 6, 6, 2, 6, 'enrolled'), (31, 40, 6, 6, 2, 6, 'enrolled'),
(31, 41, 6, 6, 2, 6, 'enrolled'), (31, 44, 6, 6, 2, 6, 'enrolled'),
(31, 45, 6, 6, 2, 6, 'enrolled'), (31, 48, 6, 6, 2, 6, 'enrolled'),
(31, 39, 6, 6, 2, 6, 'enrolled'), (31, 46, 6, 6, 2, 6, 'enrolled');

-- SHS - ABM11 students (32,33,34) enrolled in Semester 1 subjects
-- ORALCOMM(38), GENMATH(40), PHILO(44), PE(45), KOMPAN(39), ICT(46), EsP(33), PERDEV(54)
INSERT INTO tbl_enroll (student_id, subject_id, section_id, teacher_id, sy_id, term_id, status) VALUES
(32, 38, 15, 6, 2, 6, 'enrolled'), (32, 40, 15, 6, 2, 6, 'enrolled'),
(32, 44, 15, 6, 2, 6, 'enrolled'), (32, 45, 15, 6, 2, 6, 'enrolled'),
(32, 39, 15, 6, 2, 6, 'enrolled'), (32, 46, 15, 6, 2, 6, 'enrolled'),
(32, 54, 15, 6, 2, 6, 'enrolled'), (32, 65, 15, 6, 2, 6, 'enrolled'),
(33, 38, 15, 6, 2, 6, 'enrolled'), (33, 40, 15, 6, 2, 6, 'enrolled'),
(33, 44, 15, 6, 2, 6, 'enrolled'), (33, 45, 15, 6, 2, 6, 'enrolled'),
(33, 39, 15, 6, 2, 6, 'enrolled'), (33, 46, 15, 6, 2, 6, 'enrolled'),
(33, 54, 15, 6, 2, 6, 'enrolled'), (33, 65, 15, 6, 2, 6, 'enrolled'),
(34, 38, 15, 6, 2, 6, 'enrolled'), (34, 40, 15, 6, 2, 6, 'enrolled'),
(34, 44, 15, 6, 2, 6, 'enrolled'), (34, 45, 15, 6, 2, 6, 'enrolled'),
(34, 39, 15, 6, 2, 6, 'enrolled'), (34, 46, 15, 6, 2, 6, 'enrolled'),
(34, 54, 15, 6, 2, 6, 'enrolled'), (34, 65, 15, 6, 2, 6, 'enrolled');

-- SHS - HUMSS11 students (35,36,37) enrolled
INSERT INTO tbl_enroll (student_id, subject_id, section_id, teacher_id, sy_id, term_id, status) VALUES
(35, 38, 16, 6, 2, 6, 'enrolled'), (35, 40, 16, 6, 2, 6, 'enrolled'),
(35, 44, 16, 6, 2, 6, 'enrolled'), (35, 45, 16, 6, 2, 6, 'enrolled'),
(35, 39, 16, 6, 2, 6, 'enrolled'), (35, 46, 16, 6, 2, 6, 'enrolled'),
(35, 54, 16, 6, 2, 6, 'enrolled'), (35, 60, 16, 6, 2, 6, 'enrolled'),
(36, 38, 16, 6, 2, 6, 'enrolled'), (36, 40, 16, 6, 2, 6, 'enrolled'),
(36, 44, 16, 6, 2, 6, 'enrolled'), (36, 45, 16, 6, 2, 6, 'enrolled'),
(36, 39, 16, 6, 2, 6, 'enrolled'), (36, 46, 16, 6, 2, 6, 'enrolled'),
(36, 54, 16, 6, 2, 6, 'enrolled'), (36, 60, 16, 6, 2, 6, 'enrolled'),
(37, 38, 16, 6, 2, 6, 'enrolled'), (37, 40, 16, 6, 2, 6, 'enrolled'),
(37, 44, 16, 6, 2, 6, 'enrolled'), (37, 45, 16, 6, 2, 6, 'enrolled'),
(37, 39, 16, 6, 2, 6, 'enrolled'), (37, 46, 16, 6, 2, 6, 'enrolled'),
(37, 54, 16, 6, 2, 6, 'enrolled'), (37, 60, 16, 6, 2, 6, 'enrolled');

-- COLLEGE - BSCS-1A students (38,39,40,41,48) enrolled in Sem1 subjects
-- SOFTENG1(72), GEC101(73), GEC104(76), CC101(78), PE1(81), NSTP1(82)
INSERT INTO tbl_enroll (student_id, subject_id, section_id, teacher_id, sy_id, term_id, status) VALUES
-- Andrei (38)
(38, 72, 7, 7, 2, 6, 'enrolled'), (38, 73, 7, 7, 2, 6, 'enrolled'),
(38, 76, 7, 7, 2, 6, 'enrolled'), (38, 78, 7, 7, 2, 6, 'enrolled'),
(38, 81, 7, 7, 2, 6, 'enrolled'), (38, 82, 7, 7, 2, 6, 'enrolled'),
-- Christine (39)
(39, 72, 7, 7, 2, 6, 'enrolled'), (39, 73, 7, 7, 2, 6, 'enrolled'),
(39, 76, 7, 7, 2, 6, 'enrolled'), (39, 78, 7, 7, 2, 6, 'enrolled'),
(39, 81, 7, 7, 2, 6, 'enrolled'), (39, 82, 7, 7, 2, 6, 'enrolled'),
-- Emmanuel (40)
(40, 72, 7, 7, 2, 6, 'enrolled'), (40, 73, 7, 7, 2, 6, 'enrolled'),
(40, 76, 7, 7, 2, 6, 'enrolled'), (40, 78, 7, 7, 2, 6, 'enrolled'),
(40, 81, 7, 7, 2, 6, 'enrolled'), (40, 82, 7, 7, 2, 6, 'enrolled'),
-- Diana (41)
(41, 72, 7, 7, 2, 6, 'enrolled'), (41, 73, 7, 7, 2, 6, 'enrolled'),
(41, 76, 7, 7, 2, 6, 'enrolled'), (41, 78, 7, 7, 2, 6, 'enrolled'),
(41, 81, 7, 7, 2, 6, 'enrolled'), (41, 82, 7, 7, 2, 6, 'enrolled'),
-- Lorenzo (48)
(48, 72, 7, 7, 2, 6, 'enrolled'), (48, 73, 7, 7, 2, 6, 'enrolled'),
(48, 76, 7, 7, 2, 6, 'enrolled'), (48, 78, 7, 7, 2, 6, 'enrolled'),
(48, 81, 7, 7, 2, 6, 'enrolled'), (48, 82, 7, 7, 2, 6, 'enrolled');

-- COLLEGE - BSN-1A students (42,43,44,45) enrolled in Sem1 subjects
-- GEC101(73), NCM100(83), ANAT01(85), BIO101(86)
INSERT INTO tbl_enroll (student_id, subject_id, section_id, teacher_id, sy_id, term_id, status) VALUES
(42, 73, 8, 7, 2, 6, 'enrolled'), (42, 83, 8, 7, 2, 6, 'enrolled'),
(42, 85, 8, 7, 2, 6, 'enrolled'), (42, 86, 8, 7, 2, 6, 'enrolled'),
(43, 73, 8, 7, 2, 6, 'enrolled'), (43, 83, 8, 7, 2, 6, 'enrolled'),
(43, 85, 8, 7, 2, 6, 'enrolled'), (43, 86, 8, 7, 2, 6, 'enrolled'),
(44, 73, 8, 7, 2, 6, 'enrolled'), (44, 83, 8, 7, 2, 6, 'enrolled'),
(44, 85, 8, 7, 2, 6, 'enrolled'), (44, 86, 8, 7, 2, 6, 'enrolled'),
(45, 73, 8, 7, 2, 6, 'enrolled'), (45, 83, 8, 7, 2, 6, 'enrolled'),
(45, 85, 8, 7, 2, 6, 'enrolled'), (45, 86, 8, 7, 2, 6, 'enrolled');

-- COLLEGE - BSIT-1A students (46,47,49) enrolled in Sem1 subjects
-- GEC101(73), CC101(78), GEC104(76), PE1(81)
INSERT INTO tbl_enroll (student_id, subject_id, section_id, teacher_id, sy_id, term_id, status) VALUES
(46, 73, 18, 7, 2, 6, 'enrolled'), (46, 78, 18, 7, 2, 6, 'enrolled'),
(46, 76, 18, 7, 2, 6, 'enrolled'), (46, 81, 18, 7, 2, 6, 'enrolled'),
(47, 73, 18, 7, 2, 6, 'enrolled'), (47, 78, 18, 7, 2, 6, 'enrolled'),
(47, 76, 18, 7, 2, 6, 'enrolled'), (47, 81, 18, 7, 2, 6, 'enrolled'),
(49, 73, 18, 7, 2, 6, 'enrolled'), (49, 78, 18, 7, 2, 6, 'enrolled'),
(49, 76, 18, 7, 2, 6, 'enrolled'), (49, 81, 18, 7, 2, 6, 'enrolled');

-- =========================================================================
-- 7. GRADES - Mixed statuses: approved, submitted, draft for demo variety
-- =========================================================================

-- Helper: We need to find enroll IDs. We'll use subselects.
-- ELEMENTARY GRADES: Q1-Q4 grades for GRADE 1 students (approved for Q1-Q2, submitted for Q3, draft for Q4)

-- Maria (student 18) - Grade 1 subjects Q1-Q4
INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 5, 'Q1', CASE e.subject_id 
    WHEN 29 THEN 92 WHEN 28 THEN 88 WHEN 30 THEN 95 WHEN 32 THEN 90 WHEN 31 THEN 87 
    WHEN 33 THEN 91 WHEN 34 THEN 93 WHEN 35 THEN 86 WHEN 36 THEN 84 WHEN 27 THEN 89 END,
    9, 'approved', 1
FROM tbl_enroll e WHERE e.student_id = 18 AND e.section_id = 3;

INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 5, 'Q2', CASE e.subject_id 
    WHEN 29 THEN 94 WHEN 28 THEN 90 WHEN 30 THEN 93 WHEN 32 THEN 91 WHEN 31 THEN 88 
    WHEN 33 THEN 92 WHEN 34 THEN 95 WHEN 35 THEN 87 WHEN 36 THEN 86 WHEN 27 THEN 91 END,
    10, 'approved', 1
FROM tbl_enroll e WHERE e.student_id = 18 AND e.section_id = 3;

INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 5, 'Q3', CASE e.subject_id 
    WHEN 29 THEN 91 WHEN 28 THEN 89 WHEN 30 THEN 96 WHEN 32 THEN 88 WHEN 31 THEN 90 
    WHEN 33 THEN 93 WHEN 34 THEN 94 WHEN 35 THEN 85 WHEN 36 THEN 87 WHEN 27 THEN 90 END,
    11, 'submitted', 1
FROM tbl_enroll e WHERE e.student_id = 18 AND e.section_id = 3;

INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 5, 'Q4', CASE e.subject_id 
    WHEN 29 THEN 93 WHEN 28 THEN 91 WHEN 30 THEN 94 WHEN 32 THEN 89 WHEN 31 THEN 89 
    WHEN 33 THEN 90 WHEN 34 THEN 92 WHEN 35 THEN 88 WHEN 36 THEN 85 WHEN 27 THEN 92 END,
    12, 'draft', 1
FROM tbl_enroll e WHERE e.student_id = 18 AND e.section_id = 3;

-- Jose (student 19) - Grade 1 Q1-Q2 approved
INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 5, 'Q1', CASE e.subject_id 
    WHEN 29 THEN 85 WHEN 28 THEN 82 WHEN 30 THEN 88 WHEN 32 THEN 84 WHEN 31 THEN 80 
    WHEN 33 THEN 86 WHEN 34 THEN 90 WHEN 35 THEN 83 WHEN 36 THEN 81 WHEN 27 THEN 87 END,
    9, 'approved', 1
FROM tbl_enroll e WHERE e.student_id = 19 AND e.section_id = 3;

INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 5, 'Q2', CASE e.subject_id 
    WHEN 29 THEN 87 WHEN 28 THEN 84 WHEN 30 THEN 90 WHEN 32 THEN 86 WHEN 31 THEN 82 
    WHEN 33 THEN 88 WHEN 34 THEN 91 WHEN 35 THEN 85 WHEN 36 THEN 83 WHEN 27 THEN 88 END,
    10, 'approved', 1
FROM tbl_enroll e WHERE e.student_id = 19 AND e.section_id = 3;

INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 5, 'Q3', CASE e.subject_id 
    WHEN 29 THEN 86 WHEN 28 THEN 83 WHEN 30 THEN 89 WHEN 32 THEN 85 WHEN 31 THEN 81 
    WHEN 33 THEN 87 WHEN 34 THEN 92 WHEN 35 THEN 84 WHEN 36 THEN 82 WHEN 27 THEN 89 END,
    11, 'submitted', 1
FROM tbl_enroll e WHERE e.student_id = 19 AND e.section_id = 3;

-- Ana (student 20) - Grade 1 Q1 approved only
INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 5, 'Q1', CASE e.subject_id 
    WHEN 29 THEN 97 WHEN 28 THEN 95 WHEN 30 THEN 98 WHEN 32 THEN 96 WHEN 31 THEN 94 
    WHEN 33 THEN 97 WHEN 34 THEN 99 WHEN 35 THEN 93 WHEN 36 THEN 92 WHEN 27 THEN 96 END,
    9, 'approved', 1
FROM tbl_enroll e WHERE e.student_id = 20 AND e.section_id = 3;

INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 5, 'Q2', CASE e.subject_id 
    WHEN 29 THEN 96 WHEN 28 THEN 94 WHEN 30 THEN 97 WHEN 32 THEN 95 WHEN 31 THEN 93 
    WHEN 33 THEN 96 WHEN 34 THEN 98 WHEN 35 THEN 94 WHEN 36 THEN 93 WHEN 27 THEN 95 END,
    10, 'approved', 1
FROM tbl_enroll e WHERE e.student_id = 20 AND e.section_id = 3;

-- Carlos (student 21) - Grade 3 Q1-Q4 (all approved - good student)
INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 5, 'Q1', CASE e.subject_id 
    WHEN 29 THEN 90 WHEN 28 THEN 88 WHEN 30 THEN 92 WHEN 32 THEN 89 WHEN 31 THEN 87 
    WHEN 33 THEN 91 WHEN 34 THEN 93 WHEN 35 THEN 86 WHEN 36 THEN 88 WHEN 27 THEN 90 END,
    9, 'approved', 1
FROM tbl_enroll e WHERE e.student_id = 21 AND e.section_id = 13;

INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 5, 'Q2', CASE e.subject_id 
    WHEN 29 THEN 91 WHEN 28 THEN 89 WHEN 30 THEN 93 WHEN 32 THEN 90 WHEN 31 THEN 88 
    WHEN 33 THEN 92 WHEN 34 THEN 94 WHEN 35 THEN 87 WHEN 36 THEN 89 WHEN 27 THEN 91 END,
    10, 'approved', 1
FROM tbl_enroll e WHERE e.student_id = 21 AND e.section_id = 13;

INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 5, 'Q3', CASE e.subject_id 
    WHEN 29 THEN 92 WHEN 28 THEN 90 WHEN 30 THEN 91 WHEN 32 THEN 91 WHEN 31 THEN 89 
    WHEN 33 THEN 93 WHEN 34 THEN 95 WHEN 35 THEN 88 WHEN 36 THEN 90 WHEN 27 THEN 92 END,
    11, 'approved', 1
FROM tbl_enroll e WHERE e.student_id = 21 AND e.section_id = 13;

INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 5, 'Q4', CASE e.subject_id 
    WHEN 29 THEN 93 WHEN 28 THEN 91 WHEN 30 THEN 94 WHEN 32 THEN 92 WHEN 31 THEN 90 
    WHEN 33 THEN 94 WHEN 34 THEN 96 WHEN 35 THEN 89 WHEN 36 THEN 91 WHEN 27 THEN 93 END,
    12, 'approved', 1
FROM tbl_enroll e WHERE e.student_id = 21 AND e.section_id = 13;

-- Sofia (22), Miguel (23), Isabella (24) - Grade 3 Q1-Q2 approved
INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 5, 'Q1', ROUND(80 + RAND() * 15, 0), 9, 'approved', 1
FROM tbl_enroll e WHERE e.student_id IN (22,23,24) AND e.section_id = 13;

INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 5, 'Q2', ROUND(80 + RAND() * 15, 0), 10, 'approved', 1
FROM tbl_enroll e WHERE e.student_id IN (22,23,24) AND e.section_id = 13;

INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 5, 'Q3', ROUND(78 + RAND() * 17, 0), 11, 'submitted', 1
FROM tbl_enroll e WHERE e.student_id IN (22,23,24) AND e.section_id = 13;

-- Gabriel (25), Lucia (26), Rafael (27) - Grade 5 Q1-Q2 approved
INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 5, 'Q1', ROUND(82 + RAND() * 13, 0), 9, 'approved', 1
FROM tbl_enroll e WHERE e.student_id IN (25,26,27) AND e.section_id = 14;

INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 5, 'Q2', ROUND(81 + RAND() * 14, 0), 10, 'approved', 1
FROM tbl_enroll e WHERE e.student_id IN (25,26,27) AND e.section_id = 14;

INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 5, 'Q3', ROUND(80 + RAND() * 15, 0), 11, 'submitted', 1
FROM tbl_enroll e WHERE e.student_id IN (25,26,27) AND e.section_id = 14;

INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 5, 'Q4', ROUND(79 + RAND() * 16, 0), 12, 'draft', 1
FROM tbl_enroll e WHERE e.student_id IN (25,26,27) AND e.section_id = 14;

-- =========================================================================
-- SHS GRADES - Semester 1 (Q1+Q2 grades for SHS students)
-- =========================================================================

-- STEM11 students (28,29,30,31) - Q1 approved, Q2 mix
INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 6, 'Q1', ROUND(80 + RAND() * 15, 0), 6, 'approved', 1
FROM tbl_enroll e WHERE e.student_id IN (28,29,30,31) AND e.section_id = 6;

INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 6, 'Q2', ROUND(78 + RAND() * 17, 0), 6, 'approved', 1
FROM tbl_enroll e WHERE e.student_id IN (28,29) AND e.section_id = 6;

INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 6, 'Q2', ROUND(80 + RAND() * 15, 0), 6, 'submitted', 1
FROM tbl_enroll e WHERE e.student_id IN (30,31) AND e.section_id = 6;

-- ABM11 students (32,33,34) - Q1 approved, Q2 submitted
INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 6, 'Q1', ROUND(78 + RAND() * 17, 0), 6, 'approved', 1
FROM tbl_enroll e WHERE e.student_id IN (32,33,34) AND e.section_id = 15;

INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 6, 'Q2', ROUND(77 + RAND() * 18, 0), 6, 'submitted', 1
FROM tbl_enroll e WHERE e.student_id IN (32,33,34) AND e.section_id = 15;

-- HUMSS11 students (35,36,37) - Q1 approved
INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 6, 'Q1', ROUND(80 + RAND() * 15, 0), 6, 'approved', 1
FROM tbl_enroll e WHERE e.student_id IN (35,36,37) AND e.section_id = 16;

INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 6, 'Q2', ROUND(79 + RAND() * 16, 0), 6, 'draft', 1
FROM tbl_enroll e WHERE e.student_id IN (35,36,37) AND e.section_id = 16;

-- =========================================================================
-- COLLEGE GRADES - Semester 1 midterm/final via grading_period
-- College uses term-based grading, so grading_period is NULL or the term period
-- =========================================================================

-- BSCS-1A students (38,39,40,41,48) - Sem1 grades (approved for some, submitted/draft for others)
INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 7, NULL, ROUND(1.0 + RAND() * 2.0, 2) * 25 + 25, 6, 'approved', 1
FROM tbl_enroll e WHERE e.student_id IN (38,39) AND e.section_id = 7;

-- Give more realistic college grades (75-98 range)
INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 7, NULL, ROUND(78 + RAND() * 17, 2), 6, 'approved', 1
FROM tbl_enroll e WHERE e.student_id IN (40,41) AND e.section_id = 7;

INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 7, NULL, ROUND(80 + RAND() * 15, 2), 6, 'submitted', 1
FROM tbl_enroll e WHERE e.student_id = 48 AND e.section_id = 7;

-- BSN-1A students (42,43,44,45)
INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 7, NULL, ROUND(80 + RAND() * 15, 2), 6, 'approved', 1
FROM tbl_enroll e WHERE e.student_id IN (42,43) AND e.section_id = 8;

INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 7, NULL, ROUND(76 + RAND() * 19, 2), 6, 'submitted', 1
FROM tbl_enroll e WHERE e.student_id IN (44,45) AND e.section_id = 8;

-- BSIT-1A students (46,47,49)
INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 7, NULL, ROUND(79 + RAND() * 16, 2), 6, 'approved', 1
FROM tbl_enroll e WHERE e.student_id IN (46,47) AND e.section_id = 18;

INSERT INTO tbl_grades (enroll_id, teacher_id, grading_period, period_grade, term_id, status, is_direct_input) 
SELECT e.id, 7, NULL, ROUND(75 + RAND() * 20, 2), 6, 'draft', 1
FROM tbl_enroll e WHERE e.student_id = 49 AND e.section_id = 18;

-- =========================================================================
-- 8. ADD DEAN for SHS (d_shs) to cover teacher_subjects visibility
-- =========================================================================
INSERT INTO tbl_users (id, username, password, role, status) VALUES
(72, 'd_shs', 'deanpassword', 'dean', 'active');

-- =========================================================================
-- Done! Summary:
-- Elementary: 10 students (3 in G1, 4 in G3, 3 in G5) with Q1-Q4 grades
-- SHS: 10 students (4 STEM11, 3 ABM11, 3 HUMSS11) with Sem1 Q1+Q2 grades
-- College: 12 students (5 BSCS, 4 BSN, 3 BSIT) with Sem1 grades
-- 3 new teachers assigned across all levels
-- Mixed grade statuses: approved, submitted, draft
-- =========================================================================
