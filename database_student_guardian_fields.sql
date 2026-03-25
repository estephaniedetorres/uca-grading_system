-- Migration: Move guardian info directly into tbl_student
-- Students will manage their own guardian/parent information

ALTER TABLE `tbl_student`
  ADD COLUMN `guardian_name` VARCHAR(200) DEFAULT NULL AFTER `section_id`,
  ADD COLUMN `guardian_contact` VARCHAR(50) DEFAULT NULL AFTER `guardian_name`,
  ADD COLUMN `guardian_email` VARCHAR(100) DEFAULT NULL AFTER `guardian_contact`;
