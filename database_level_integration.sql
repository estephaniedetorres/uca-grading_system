-- =====================================================================
-- Level Integration Migration
-- Connects the `level` table to sections, prospectus, and enrollment
-- =====================================================================

-- Step 1: Clear existing level data (table exists but is empty)
TRUNCATE TABLE `level`;

-- Step 2: Populate levels for ALL academic tracks
-- K-12: Each academic track is its own single level
-- College: Each program has 4 year levels (1st-4th year)
-- SHS: Each track-grade combo is its own level

-- PRE-ELEM levels
INSERT INTO `level` (`code`, `description`, `academic_track_id`, `order`, `created_at`, `updated_at`) VALUES
('NURSERY', 'Nursery', 1, 1, NOW(), NOW()),
('KINDER1', 'Kinder 1', 2, 2, NOW(), NOW()),
('KINDER2', 'Kinder 2', 3, 3, NOW(), NOW());

-- ELEM levels
INSERT INTO `level` (`code`, `description`, `academic_track_id`, `order`, `created_at`, `updated_at`) VALUES
('GRADE1', 'Grade 1', 4, 1, NOW(), NOW()),
('GRADE2', 'Grade 2', 5, 2, NOW(), NOW()),
('GRADE3', 'Grade 3', 6, 3, NOW(), NOW()),
('GRADE4', 'Grade 4', 7, 4, NOW(), NOW()),
('GRADE5', 'Grade 5', 8, 5, NOW(), NOW()),
('GRADE6', 'Grade 6', 9, 6, NOW(), NOW());

-- JHS levels
INSERT INTO `level` (`code`, `description`, `academic_track_id`, `order`, `created_at`, `updated_at`) VALUES
('GRADE7', 'Grade 7', 10, 1, NOW(), NOW()),
('GRADE8', 'Grade 8', 11, 2, NOW(), NOW()),
('GRADE9', 'Grade 9', 12, 3, NOW(), NOW()),
('GRADE10', 'Grade 10', 13, 4, NOW(), NOW());

-- SHS levels (each track-grade is its own level)
INSERT INTO `level` (`code`, `description`, `academic_track_id`, `order`, `created_at`, `updated_at`) VALUES
('STEM11', 'STEM - Grade 11', 14, 1, NOW(), NOW()),
('STEM12', 'STEM - Grade 12', 15, 2, NOW(), NOW()),
('ABM11', 'ABM - Grade 11', 16, 1, NOW(), NOW()),
('ABM12', 'ABM - Grade 12', 17, 2, NOW(), NOW()),
('HUMSS11', 'HUMSS - Grade 11', 18, 1, NOW(), NOW()),
('HUMSS12', 'HUMSS - Grade 12', 19, 2, NOW(), NOW()),
('GAS11', 'GAS - Grade 11', 20, 1, NOW(), NOW()),
('GAS12', 'GAS - Grade 12', 21, 2, NOW(), NOW());

-- College: BSCS has 4 year levels
INSERT INTO `level` (`code`, `description`, `academic_track_id`, `order`, `created_at`, `updated_at`) VALUES
('BSCS-1', '1st Year', 22, 1, NOW(), NOW()),
('BSCS-2', '2nd Year', 22, 2, NOW(), NOW()),
('BSCS-3', '3rd Year', 22, 3, NOW(), NOW()),
('BSCS-4', '4th Year', 22, 4, NOW(), NOW());

-- College: BSIT has 4 year levels
INSERT INTO `level` (`code`, `description`, `academic_track_id`, `order`, `created_at`, `updated_at`) VALUES
('BSIT-1', '1st Year', 23, 1, NOW(), NOW()),
('BSIT-2', '2nd Year', 23, 2, NOW(), NOW()),
('BSIT-3', '3rd Year', 23, 3, NOW(), NOW()),
('BSIT-4', '4th Year', 23, 4, NOW(), NOW());

-- College: BSN has 4 year levels
INSERT INTO `level` (`code`, `description`, `academic_track_id`, `order`, `created_at`, `updated_at`) VALUES
('BSN-1', '1st Year', 26, 1, NOW(), NOW()),
('BSN-2', '2nd Year', 26, 2, NOW(), NOW()),
('BSN-3', '3rd Year', 26, 3, NOW(), NOW()),
('BSN-4', '4th Year', 26, 4, NOW(), NOW());


-- Step 3: Add level_id to tbl_section
ALTER TABLE `tbl_section`
ADD COLUMN `level_id` int DEFAULT NULL AFTER `academic_track_id`,
ADD KEY `level_id` (`level_id`),
ADD CONSTRAINT `tbl_section_level_fk` FOREIGN KEY (`level_id`) REFERENCES `level` (`id`);

-- Step 4: Add level_id to tbl_prospectus
ALTER TABLE `tbl_prospectus`
ADD COLUMN `level_id` int DEFAULT NULL AFTER `curriculum_id`,
ADD KEY `level_id` (`level_id`),
ADD CONSTRAINT `tbl_prospectus_level_fk` FOREIGN KEY (`level_id`) REFERENCES `level` (`id`);


-- Step 5: Auto-assign level_id to existing sections based on academic_track_id
-- For K-12 and SHS: 1-to-1 mapping (each track has exactly 1 level)
UPDATE `tbl_section` s
JOIN `level` l ON l.academic_track_id = s.academic_track_id
SET s.level_id = l.id
WHERE s.academic_track_id IN (
    SELECT id FROM tbl_academic_track WHERE dept_id IN (7, 8, 9, 10)
);

-- For College: derive year level from section_code pattern (e.g., BSCS-1A → year 1)
UPDATE `tbl_section` s
JOIN `tbl_academic_track` at ON s.academic_track_id = at.id
JOIN `level` l ON l.academic_track_id = at.id
    AND l.`order` = CAST(SUBSTRING(s.section_code, LOCATE('-', s.section_code) + 1, 1) AS UNSIGNED)
SET s.level_id = l.id
WHERE at.dept_id IN (11, 12);

-- Step 6: Verify the update
SELECT s.id, s.section_code, s.academic_track_id, s.level_id, l.code as level_code, l.description as level_desc
FROM tbl_section s
LEFT JOIN level l ON s.level_id = l.id
ORDER BY s.id;
