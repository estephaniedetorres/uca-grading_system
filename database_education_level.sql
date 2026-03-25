-- Add education_level column to tbl_departments for dynamic TOR / Form 137 detection
-- K-12 departments get Form 137, College departments get Transcript of Records

ALTER TABLE tbl_departments 
ADD COLUMN education_level ENUM('k12','college') NOT NULL DEFAULT 'k12' AFTER description;

-- Set college departments
UPDATE tbl_departments SET education_level = 'college' WHERE code IN ('CCTE', 'CON');

-- Verify
SELECT id, code, description, education_level FROM tbl_departments;
