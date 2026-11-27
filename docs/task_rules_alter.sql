-- Task points rules tables
-- Character set: utf8mb4

CREATE TABLE IF NOT EXISTS `rise_task_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `department` varchar(100) NOT NULL,
  `sub_department` varchar(100) DEFAULT NULL,
  `group_name` varchar(100) DEFAULT NULL,
  `task` varchar(255) NOT NULL,
  `keywords` text DEFAULT NULL,
  `points_min` int(11) DEFAULT NULL,
  `points_max` int(11) DEFAULT NULL,
  `minutes_min` int(11) DEFAULT NULL,
  `minutes_max` int(11) DEFAULT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_dept_subdept_deleted` (`department`, `sub_department`, `deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rise_task_meta` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `department` varchar(100) NOT NULL,
  `sub_department` varchar(100) DEFAULT NULL,
  `rule_id` int(11) DEFAULT NULL,
  `suggested_points` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_task_id` (`task_id`),
  INDEX `idx_task_meta_dept` (`department`, `sub_department`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
