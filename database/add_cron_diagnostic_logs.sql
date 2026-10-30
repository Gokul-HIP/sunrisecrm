-- Structured logs for the Cron Diagnostic Dashboard only.
-- Safe to run more than once.
CREATE TABLE IF NOT EXISTS `rise_cron_diagnostic_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `run_id` varchar(64) NOT NULL DEFAULT '',
  `event_type` varchar(50) NOT NULL DEFAULT '',
  `level` varchar(20) NOT NULL DEFAULT 'info',
  `task_id` int(11) DEFAULT NULL,
  `parent_task_id` int(11) DEFAULT NULL,
  `clone_task_id` int(11) DEFAULT NULL,
  `message` text,
  `context_json` mediumtext,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `run_id` (`run_id`),
  KEY `level` (`level`),
  KEY `event_type` (`event_type`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
