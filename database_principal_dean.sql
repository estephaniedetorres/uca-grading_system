-- =============================================
-- Migration: Principal & Dean Department Scoping
-- Principals handle K-12 departments (PRE-EL, ELE, JHS, SHS)
-- Deans handle College departments (CCTE, CON, etc.)
-- A principal/dean can be assigned to MULTIPLE departments
-- =============================================

-- 1. Add dean to the user role enum
ALTER TABLE `tbl_users` MODIFY COLUMN `role` ENUM('admin','teacher','student','principal','dean') NOT NULL;

-- 2. Create junction table for admin-department assignments (many-to-many)
CREATE TABLE IF NOT EXISTS `tbl_admin_departments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `admin_id` INT NOT NULL,
    `dept_id` INT NOT NULL,
    UNIQUE KEY `unique_admin_dept` (`admin_id`, `dept_id`),
    FOREIGN KEY (`admin_id`) REFERENCES `tbl_admin`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`dept_id`) REFERENCES `tbl_departments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. If you previously added a dept_id column to tbl_admin, migrate that data then drop it:
-- INSERT IGNORE INTO tbl_admin_departments (admin_id, dept_id) SELECT id, dept_id FROM tbl_admin WHERE dept_id IS NOT NULL;
-- ALTER TABLE tbl_admin DROP FOREIGN KEY tbl_admin_dept_fk;
-- ALTER TABLE tbl_admin DROP COLUMN dept_id;

-- 4. (Optional) Sample assignments — one principal covering Pre-Elem to JHS:
-- SET @admin_id = (SELECT a.id FROM tbl_admin a JOIN tbl_users u ON a.user_id = u.id WHERE u.username = 'principal_k12');
-- INSERT INTO tbl_admin_departments (admin_id, dept_id) VALUES (@admin_id, 7), (@admin_id, 8), (@admin_id, 9);
