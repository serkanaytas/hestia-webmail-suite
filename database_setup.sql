-- =========================================================================
-- Hestia Webmail & Kurumsal Portal Suite - Complete Database Schema
-- Compatible with MySQL 5.7+ / MySQL 8.0+ / MariaDB 10.3+
-- =========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Mail Accounts
CREATE TABLE IF NOT EXISTS `mail_accounts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `imap_host` VARCHAR(255) NOT NULL,
  `imap_port` INT(11) NOT NULL DEFAULT 993,
  `imap_encryption` VARCHAR(20) DEFAULT 'ssl',
  `imap_username` VARCHAR(255) NOT NULL,
  `imap_password` TEXT NOT NULL,
  `smtp_host` VARCHAR(255) NOT NULL,
  `smtp_port` INT(11) NOT NULL DEFAULT 587,
  `smtp_encryption` VARCHAR(20) DEFAULT 'tls',
  `smtp_username` VARCHAR(255) NOT NULL,
  `smtp_password` TEXT NOT NULL,
  `signature` TEXT DEFAULT NULL,
  `is_default` TINYINT(1) DEFAULT 1,
  `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
  `role` VARCHAR(20) NOT NULL DEFAULT 'user',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_account_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Corporate Domain Directory
CREATE TABLE IF NOT EXISTS `domain_directory` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `domain` VARCHAR(120) NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `source` VARCHAR(50) DEFAULT 'auto',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dir_email` (`email`),
  KEY `idx_dir_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Address Book Contacts
CREATE TABLE IF NOT EXISTS `contacts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `account_id` INT(11) DEFAULT 0,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) DEFAULT NULL,
  `email` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `company` VARCHAR(150) DEFAULT NULL,
  `job_title` VARCHAR(150) DEFAULT NULL,
  `group_name` VARCHAR(100) DEFAULT 'Genel',
  `notes` TEXT DEFAULT NULL,
  `avatar_color` VARCHAR(20) DEFAULT '#4f46e5',
  `is_favorite` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_contact_acc_email` (`account_id`, `email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Calendar & Shared Events
CREATE TABLE IF NOT EXISTS `calendar_events` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `account_id` INT(11) DEFAULT 0,
  `domain` VARCHAR(100) DEFAULT NULL,
  `is_shared` TINYINT(1) DEFAULT 1,
  `creator_name` VARCHAR(150) DEFAULT NULL,
  `creator_email` VARCHAR(150) DEFAULT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `location` VARCHAR(255) DEFAULT NULL,
  `start_date` DATETIME NOT NULL,
  `end_date` DATETIME NOT NULL,
  `all_day` TINYINT(1) DEFAULT 0,
  `color` VARCHAR(30) DEFAULT '#4f46e5',
  `category` VARCHAR(50) DEFAULT 'Toplantı',
  `status` VARCHAR(20) DEFAULT 'confirmed',
  `reminder_minutes` INT(11) DEFAULT 15,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cal_account` (`account_id`),
  KEY `idx_cal_domain` (`domain`, `is_shared`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Custom IMAP Folders
CREATE TABLE IF NOT EXISTS `mail_folders` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `account_id` INT(11) DEFAULT 0,
  `folder_name` VARCHAR(150) NOT NULL,
  `display_name` VARCHAR(150) NOT NULL,
  `icon` VARCHAR(50) DEFAULT 'folder',
  `color` VARCHAR(30) DEFAULT '#4f46e5',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_folder_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Incoming Mail Filters & Rules
CREATE TABLE IF NOT EXISTS `mail_filters` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `account_id` INT(11) DEFAULT 0,
  `filter_name` VARCHAR(150) NOT NULL,
  `match_field` VARCHAR(50) NOT NULL DEFAULT 'from',
  `match_type` VARCHAR(50) NOT NULL DEFAULT 'contains',
  `match_value` VARCHAR(255) NOT NULL,
  `action_type` VARCHAR(50) NOT NULL DEFAULT 'move',
  `action_target` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_filter_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Mail Drafts
CREATE TABLE IF NOT EXISTS `mail_drafts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `account_id` INT(11) DEFAULT 0,
  `to_email` TEXT DEFAULT NULL,
  `cc_email` TEXT DEFAULT NULL,
  `bcc_email` TEXT DEFAULT NULL,
  `subject` VARCHAR(255) DEFAULT NULL,
  `body` LONGTEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_draft_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. External IMAP Mail Migration Jobs
CREATE TABLE IF NOT EXISTS `mail_imports` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `account_id` INT(11) NOT NULL,
  `source_email` VARCHAR(255) NOT NULL,
  `source_password` TEXT DEFAULT NULL,
  `source_host` VARCHAR(255) NOT NULL,
  `source_port` INT(11) DEFAULT 993,
  `source_encryption` VARCHAR(20) DEFAULT 'ssl',
  `source_folder` VARCHAR(255) DEFAULT 'ALL',
  `target_mode` VARCHAR(20) DEFAULT 'same',
  `custom_target_folder` VARCHAR(255) DEFAULT 'Eski_Postalar',
  `date_filter` VARCHAR(50) DEFAULT 'all',
  `limit_count` INT(11) DEFAULT 0,
  `skip_duplicates` TINYINT(4) DEFAULT 1,
  `target_folder` VARCHAR(255) DEFAULT 'INBOX',
  `current_folder` VARCHAR(255) DEFAULT NULL,
  `progress_percent` INT(11) DEFAULT 0,
  `pid` INT(11) DEFAULT NULL,
  `imported_count` INT(11) DEFAULT 0,
  `total_found` INT(11) DEFAULT 0,
  `skipped_count` INT(11) DEFAULT 0,
  `failed_count` INT(11) DEFAULT 0,
  `status` VARCHAR(50) DEFAULT 'pending',
  `error_message` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_imp_acc_status` (`account_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Cloud Drive Folders
CREATE TABLE IF NOT EXISTS `drive_folders` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `account_id` INT(11) NOT NULL,
  `parent_id` INT(11) DEFAULT 0,
  `name` VARCHAR(255) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_acc_dfolder` (`account_id`),
  KEY `idx_parent_dfolder` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Cloud Drive Files
CREATE TABLE IF NOT EXISTS `drive_files` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `account_id` INT(11) NOT NULL,
  `folder_id` INT(11) DEFAULT 0,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `file_size` BIGINT(20) DEFAULT 0,
  `mime_type` VARCHAR(100) DEFAULT 'application/octet-stream',
  `extension` VARCHAR(20) DEFAULT '',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_acc_dfile` (`account_id`),
  KEY `idx_folder_dfile` (`folder_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. User Notes
CREATE TABLE IF NOT EXISTS `user_notes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `account_id` INT(11) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `content` MEDIUMTEXT DEFAULT NULL,
  `color` VARCHAR(30) DEFAULT '#6366f1',
  `is_pinned` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_acc_note` (`account_id`),
  KEY `idx_note_pin` (`is_pinned`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. User Tasks
CREATE TABLE IF NOT EXISTS `user_tasks` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `account_id` INT(11) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `due_date` DATE DEFAULT NULL,
  `priority` VARCHAR(20) DEFAULT 'medium',
  `is_completed` TINYINT(1) DEFAULT 0,
  `completed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_acc_task` (`account_id`),
  KEY `idx_task_comp` (`is_completed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Internal Domain Chat - Conversations
CREATE TABLE IF NOT EXISTS `chat_conversations` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `type` ENUM('direct','group') NOT NULL DEFAULT 'direct',
  `domain` VARCHAR(150) NOT NULL,
  `title` VARCHAR(255) DEFAULT NULL,
  `created_by` VARCHAR(255) NOT NULL,
  `last_message` TEXT DEFAULT NULL,
  `last_message_sender` VARCHAR(150) DEFAULT NULL,
  `last_message_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_domain_type` (`domain`, `type`),
  KEY `idx_last_msg_at` (`last_message_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Internal Domain Chat - Messages
CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `conversation_id` INT(11) NOT NULL,
  `sender_email` VARCHAR(255) NOT NULL,
  `sender_name` VARCHAR(150) NOT NULL,
  `message` LONGTEXT DEFAULT NULL,
  `attachment_url` VARCHAR(255) DEFAULT NULL,
  `attachment_name` VARCHAR(255) DEFAULT NULL,
  `attachment_size` INT(11) DEFAULT NULL,
  `attachment_type` VARCHAR(100) DEFAULT NULL,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `is_pinned` TINYINT(1) DEFAULT 0,
  `reactions` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_msg_conv_created` (`conversation_id`, `created_at`),
  KEY `idx_msg_sender` (`sender_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Internal Domain Chat - Participants
CREATE TABLE IF NOT EXISTS `chat_participants` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `conversation_id` INT(11) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `name` VARCHAR(150) DEFAULT NULL,
  `role` VARCHAR(20) DEFAULT 'member',
  `last_read_message_id` INT(11) DEFAULT 0,
  `unread_count` INT(11) DEFAULT 0,
  `is_muted` TINYINT(1) DEFAULT 0,
  `joined_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_conv_email` (`conversation_id`, `email`),
  KEY `idx_chat_email` (`email`),
  KEY `idx_chat_conv` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Internal Domain Chat - Presence & Typing Status
CREATE TABLE IF NOT EXISTS `chat_presence` (
  `email` VARCHAR(255) NOT NULL,
  `domain` VARCHAR(150) NOT NULL,
  `name` VARCHAR(150) DEFAULT NULL,
  `status` VARCHAR(20) DEFAULT 'online',
  `custom_status` VARCHAR(255) DEFAULT NULL,
  `typing_in` INT(11) DEFAULT 0,
  `typing_at` DATETIME DEFAULT NULL,
  `last_seen` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`email`),
  KEY `idx_pres_domain` (`domain`, `status`),
  KEY `idx_pres_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. Internal Domain Chat - Hidden Colleagues
CREATE TABLE IF NOT EXISTS `chat_hidden_colleagues` (
  `domain` VARCHAR(150) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `hidden_by` VARCHAR(255) DEFAULT '',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`domain`, `email`),
  KEY `idx_domain_hidden` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. Internal Domain Chat - Removed Group Members
CREATE TABLE IF NOT EXISTS `chat_removed_members` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `conversation_id` INT(11) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `removed_by` VARCHAR(255) NOT NULL,
  `removed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_conv_email_rem` (`conversation_id`, `email`),
  KEY `idx_rem_conv` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. Internal Domain Chat - Global Settings
CREATE TABLE IF NOT EXISTS `chat_settings` (
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_val` TEXT DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. System & Portal Settings
CREATE TABLE IF NOT EXISTS `mail_settings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) UNIQUE NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default Settings
INSERT INTO `mail_settings` (`setting_key`, `setting_value`) VALUES
('theme', 'dark'),
('page_size', '25'),
('auto_refresh_minutes', '3'),
('app_title', 'Hestia Mail'),
('app_subtitle', 'Web Suite'),
('auto_clean_trash', '1'),
('trash_retention_days', '30'),
('auto_clean_spam', '1'),
('spam_retention_days', '30')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

SET FOREIGN_KEY_CHECKS = 1;
