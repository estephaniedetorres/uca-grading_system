-- Create settings table for system-wide settings
CREATE TABLE IF NOT EXISTS `tbl_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` varchar(255) NOT NULL DEFAULT '',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Default: grades NOT visible to students
INSERT INTO `tbl_settings` (`setting_key`, `setting_value`) VALUES ('grades_visible', '0')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
