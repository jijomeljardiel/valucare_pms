CREATE DATABASE IF NOT EXISTS `valucare_pms`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE `valucare_pms`;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS
  `activity_log`,
  `notifications`,
  `extension_requests`,
  `messages`,
  `channels`,
  `attachments`,
  `task_assignees`,
  `task_time_logs`,
  `task_comments`,
  `task_tags`,
  `task_dependencies`,
  `tasks`,
  `projects`,
  `team_members`,
  `teams`,
  `user_roles`,
  `role_permissions`,
  `permissions`,
  `roles`,
  `user_notification_settings`,
  `system_settings`,
  `performance_records`,
  `users`,
  `project_statuses`,
  `task_statuses`,
  `tags`,
  `organizations`,
SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE IF NOT EXISTS `organizations` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(160) NOT NULL,
  `slug` VARCHAR(160) NOT NULL UNIQUE,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `org_id` INT NULL,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `email` VARCHAR(255) NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `first_name` VARCHAR(100) NULL,
  `last_name` VARCHAR(100) NULL,
  `role` ENUM('admin','project_manager','systemdev') NOT NULL DEFAULT 'staff',
  `department` VARCHAR(100) NULL,
  `position` VARCHAR(100) NULL,
  `phone` VARCHAR(30) NULL,
  `employee_id` VARCHAR(30) NULL UNIQUE,
  `hire_date` DATE NULL,
  `skills` TEXT NULL,
  `permissions` JSON NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `presence` ENUM('online','away','busy','offline') NOT NULL DEFAULT 'offline',
  `last_login` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_users_org_id` (`org_id`),
  KEY `idx_users_role` (`role`),
  CONSTRAINT `fk_users_org` FOREIGN KEY (`org_id`) REFERENCES `organizations`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `teams` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `org_id` INT NULL,
  `name` VARCHAR(120) NOT NULL UNIQUE,
  `description` TEXT NULL,
  `created_by` INT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_teams_created_by` (`created_by`),
  KEY `idx_teams_org_id` (`org_id`),
  CONSTRAINT `fk_teams_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_teams_org` FOREIGN KEY (`org_id`) REFERENCES `organizations`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `team_members` (
  `team_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `role` ENUM('lead','member') NOT NULL DEFAULT 'member',
  `joined_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`team_id`, `user_id`),
  KEY `idx_team_members_user_id` (`user_id`),
  CONSTRAINT `fk_team_members_team` FOREIGN KEY (`team_id`) REFERENCES `teams`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_team_members_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `project_statuses` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(60) NOT NULL UNIQUE,
  `name` VARCHAR(160) NOT NULL,
  `order_index` INT NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `projects` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `team_id` INT NULL,
  `name` VARCHAR(160) NOT NULL,
  `description` TEXT NULL,
  `project_status_id` INT NOT NULL,
  `priority` ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `category` VARCHAR(100) NULL,
  `progress` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `start_date` DATE NULL,
  `due_date` DATE NULL,
  `project_manager_id` INT NULL,
  `created_by` INT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_projects_team_id` (`team_id`),
  KEY `idx_projects_due_date` (`due_date`),
  KEY `idx_projects_project_manager_id` (`project_manager_id`),
  KEY `idx_projects_status_id` (`project_status_id`),
  CONSTRAINT `fk_projects_team` FOREIGN KEY (`team_id`) REFERENCES `teams`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_projects_project_manager` FOREIGN KEY (`project_manager_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_projects_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_projects_status` FOREIGN KEY (`project_status_id`) REFERENCES `project_statuses`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_statuses` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(60) NOT NULL UNIQUE,
  `name` VARCHAR(160) NOT NULL,
  `order_index` INT NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tasks` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `project_id` INT NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT NULL,
  `task_status_id` INT NOT NULL,
  `priority` ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `category` VARCHAR(100) NULL,
  `assignee_id` INT NULL,
  `start_date` DATE NULL,
  `due_date` DATE NULL,
  `estimate_hours` DECIMAL(6,2) NULL,
  `order_index` INT NULL,
  `is_blocked` TINYINT(1) NOT NULL DEFAULT 0,
  `blocked_reason` TEXT NULL,
  `blocked_at` TIMESTAMP NULL DEFAULT NULL,
  `unblocked_at` TIMESTAMP NULL DEFAULT NULL,
  `parent_task_id` INT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tasks_project_order` (`project_id`,`order_index`),
  KEY `idx_tasks_project_id` (`project_id`),
  KEY `idx_tasks_assignee_id` (`assignee_id`),
  KEY `idx_tasks_status_id` (`task_status_id`),
  KEY `idx_tasks_due_date` (`due_date`),
  KEY `idx_tasks_is_blocked` (`is_blocked`),
  CONSTRAINT `fk_tasks_project` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tasks_assignee` FOREIGN KEY (`assignee_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tasks_status` FOREIGN KEY (`task_status_id`) REFERENCES `task_statuses`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_tasks_parent` FOREIGN KEY (`parent_task_id`) REFERENCES `tasks`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_assignees` (
  `task_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`task_id`,`user_id`),
  KEY `idx_task_assignees_user_id` (`user_id`),
  CONSTRAINT `fk_task_assignees_task` FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_task_assignees_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tags` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(80) NOT NULL UNIQUE,
  `name` VARCHAR(160) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_tags` (
  `task_id` INT NOT NULL,
  `tag_id` INT NOT NULL,
  PRIMARY KEY (`task_id`,`tag_id`),
  CONSTRAINT `fk_task_tags_task` FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_task_tags_tag` FOREIGN KEY (`tag_id`) REFERENCES `tags`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_dependencies` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `task_id` INT NOT NULL,
  `depends_on_task_id` INT NOT NULL,
  `type` ENUM('blocks','relates') NOT NULL DEFAULT 'blocks',
  PRIMARY KEY (`id`),
  KEY `idx_task_dependencies_task` (`task_id`),
  KEY `idx_task_dependencies_depends` (`depends_on_task_id`),
  CONSTRAINT `fk_task_dependencies_task` FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_task_dependencies_depends` FOREIGN KEY (`depends_on_task_id`) REFERENCES `tasks`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_comments` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `task_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `body` TEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `edited_at` TIMESTAMP NULL DEFAULT NULL,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_task_comments_task_id` (`task_id`),
  KEY `idx_task_comments_user_id` (`user_id`),
  CONSTRAINT `fk_task_comments_task` FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_task_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_time_logs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `task_id` INT NOT NULL,
  `user_id` INT NULL,
  `hours` DECIMAL(6,2) NOT NULL,
  `notes` TEXT NULL,
  `logged_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_task_time_logs_task_id` (`task_id`),
  KEY `idx_task_time_logs_user_id` (`user_id`),
  CONSTRAINT `fk_task_time_logs_task` FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_task_time_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attachments` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `project_id` INT NULL,
  `task_id` INT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_size` INT NULL,
  `mime_type` VARCHAR(120) NULL,
  `uploaded_by` INT NULL,
  `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_attachments_project_id` (`project_id`),
  KEY `idx_attachments_task_id` (`task_id`),
  CONSTRAINT `fk_attachments_project` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_attachments_task` FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_attachments_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `channels` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `org_id` INT NULL,
  `team_id` INT NULL,
  `name` VARCHAR(160) NOT NULL,
  `type` ENUM('public','private') NOT NULL DEFAULT 'public',
  `created_by` INT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_channels_team_id` (`team_id`),
  KEY `idx_channels_org_id` (`org_id`),
  CONSTRAINT `fk_channels_team` FOREIGN KEY (`team_id`) REFERENCES `teams`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_channels_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_channels_org` FOREIGN KEY (`org_id`) REFERENCES `organizations`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messages` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `channel_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `content` TEXT NOT NULL,
  `reply_to_id` INT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `edited_at` TIMESTAMP NULL DEFAULT NULL,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_messages_channel_id` (`channel_id`),
  KEY `idx_messages_user_id` (`user_id`),
  KEY `idx_messages_channel_created_at` (`channel_id`,`created_at`),
  CONSTRAINT `fk_messages_channel` FOREIGN KEY (`channel_id`) REFERENCES `channels`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_messages_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_messages_reply` FOREIGN KEY (`reply_to_id`) REFERENCES `messages`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `body` TEXT NULL,
  `url` VARCHAR(500) NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user_id` (`user_id`),
  KEY `idx_notifications_user_read_created_at` (`user_id`,`is_read`,`created_at`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `extension_requests` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `task_id` INT NOT NULL,
  `requested_by` INT NOT NULL,
  `current_due_date` DATE NOT NULL,
  `requested_due_date` DATE NOT NULL,
  `requested_extension_days` INT NOT NULL,
  `reason` TEXT NULL,
  `justification` TEXT NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `priority` ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `reviewed_by` INT NULL,
  `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
  `decision_notes` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_extension_requests_task_id` (`task_id`),
  KEY `idx_extension_requests_requested_by` (`requested_by`),
  KEY `idx_extension_requests_reviewed_by` (`reviewed_by`),
  KEY `idx_extension_requests_task_status` (`task_id`,`status`),
  CONSTRAINT `fk_extension_requests_task` FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_extension_requests_user` FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_extension_requests_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NULL,
  `action` VARCHAR(150) NOT NULL,
  `entity_type` VARCHAR(60) NULL,
  `entity_id` INT NULL,
  `ip_address` VARCHAR(45) NULL,
  `metadata` JSON NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_log_user_id` (`user_id`),
  KEY `idx_activity_log_entity` (`entity_type`,`entity_id`),
  KEY `idx_activity_log_entity_created_at` (`entity_type`,`entity_id`,`created_at`),
  CONSTRAINT `fk_activity_log_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `user_notification_settings` (
  `user_id` INT NOT NULL,
  `email` TINYINT(1) NOT NULL DEFAULT 1,
  `push` TINYINT(1) NOT NULL DEFAULT 1,
  `slack` TINYINT(1) NOT NULL DEFAULT 0,
  `frequency` ENUM('realtime','hourly','daily') NOT NULL DEFAULT 'realtime',
  `types_tasks` TINYINT(1) NOT NULL DEFAULT 1,
  `types_projects` TINYINT(1) NOT NULL DEFAULT 1,
  `types_system` TINYINT(1) NOT NULL DEFAULT 1,
  `types_mentions` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_user_notification_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` INT NOT NULL DEFAULT 1,
  `backup_frequency` ENUM('hourly','daily','weekly') NOT NULL DEFAULT 'daily',
  `backup_retention_days` INT NOT NULL DEFAULT 30,
  `security_2fa` TINYINT(1) NOT NULL DEFAULT 1,
  `security_strong_pw` TINYINT(1) NOT NULL DEFAULT 1,
  `session_timeout_minutes` INT NOT NULL DEFAULT 30,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


INSERT INTO `project_statuses` (`key`,`name`,`order_index`,`active`) VALUES
  ('planning','Planning',10,1),
  ('active','Active',20,1),
  ('on-hold','On Hold',30,1),
  ('completed','Completed',40,1),
  ('archived','Archived',50,1),
  ('at-risk','At Risk',25,1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `order_index`=VALUES(`order_index`), `active`=VALUES(`active`);

INSERT INTO `task_statuses` (`key`,`name`,`order_index`,`active`) VALUES
  ('todo','To Do',10,1),
  ('in-progress','In Progress',20,1),
  ('review','In Review',30,1),
  ('done','Done',40,1),
  ('blocked','Blocked',25,1),
  ('waiting','Waiting',15,1),
  ('planned','Planned',5,1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `order_index`=VALUES(`order_index`), `active`=VALUES(`active`);

INSERT INTO `users` (`username`,`email`,`password`,`first_name`,`last_name`,`role`,`department`,`position`,`phone`,`employee_id`,`hire_date`,`status`,`presence`)
VALUES
  ('admin','admin@example.com','$2y$10$abcdefghijklmnopqrstuv/0123456789abcdefghi','System','Administrator','admin','ICT','admin','0000000000','ICT000','2025-01-01','active','offline')
ON DUPLICATE KEY UPDATE `username`=`username`;

-- No user_roles; admin role assigned directly on `users`