-- Migration: Add 'registrar' role to tbl_users
-- This adds the registrar role for grade approval and grade reports functionality

ALTER TABLE `tbl_users` MODIFY COLUMN `role` enum('admin','teacher','student','principal','dean','registrar') NOT NULL;
