-- Task point awards and monthly totals.
-- Safe to run more than once.
CREATE TABLE IF NOT EXISTS `rise_task_point_awards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL DEFAULT 0,
  `original_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `earned_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `reduction_percent` int(11) NOT NULL DEFAULT 0,
  `reduction_reason` text,
  `deadline` date DEFAULT NULL,
  `completed_date` date NOT NULL,
  `completed_at` datetime NOT NULL,
  `year` int(11) NOT NULL,
  `month` int(11) NOT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `task_id` (`task_id`),
  KEY `user_month` (`user_id`, `year`, `month`, `deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `rise_task_point_monthly_totals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `year` int(11) NOT NULL,
  `month` int(11) NOT NULL,
  `total_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `updated_at` datetime DEFAULT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_year_month` (`user_id`, `year`, `month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
