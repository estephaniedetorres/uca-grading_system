-- Migration: Add is_direct_input column to tbl_grades
-- This column tracks whether a grade was entered directly (final grade only)
-- or computed from WW, PT, QA components

-- Add column if it doesn't exist
SET @tablename = 'tbl_grades';
SET @columnname = 'is_direct_input';
SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = @tablename 
     AND COLUMN_NAME = @columnname) > 0,
    'SELECT 1',
    'ALTER TABLE tbl_grades ADD COLUMN is_direct_input TINYINT(1) DEFAULT 0 COMMENT "1 if grade was entered directly without WW/PT/QA"'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
