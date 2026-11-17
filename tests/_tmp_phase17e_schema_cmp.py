#!/usr/bin/env python3
"""Compare on-disk phase17 schema files to the exact 17B report paste."""
from pathlib import Path

SCHEMA_17B = """-- PHASE 17: timer rule + review-change penalty + held points
-- Idempotent. No hard-coded status ids. Settings default OFF.
-- Live: run with SET SESSION sql_mode='';

SET SESSION sql_mode = '';

-- 1) Settings (FROM DUAL, idempotent)
INSERT INTO `rise_settings` (`setting_name`, `setting_value`, `deleted`)
SELECT 'task_points_review_rules_enabled', '0', 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `rise_settings` WHERE `setting_name` = 'task_points_review_rules_enabled');

INSERT INTO `rise_settings` (`setting_name`, `setting_value`, `deleted`)
SELECT 'task_points_review_rules_from', '', 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `rise_settings` WHERE `setting_name` = 'task_points_review_rules_from');

INSERT INTO `rise_settings` (`setting_name`, `setting_value`, `deleted`)
SELECT 'task_review_changes_penalty_percent', '10', 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `rise_settings` WHERE `setting_name` = 'task_review_changes_penalty_percent');

-- 2) rise_tasks columns
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'rise_tasks'
                     AND COLUMN_NAME = 'review_changes_count');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `rise_tasks` ADD COLUMN `review_changes_count` int(11) NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'rise_tasks'
                     AND COLUMN_NAME = 'first_review_submitted_at');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `rise_tasks` ADD COLUMN `first_review_submitted_at` datetime DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'rise_tasks'
                     AND COLUMN_NAME = 'timer_lock_json');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `rise_tasks` ADD COLUMN `timer_lock_json` text DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'rise_tasks'
                     AND COLUMN_NAME = 'points_rules_locked_at');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `rise_tasks` ADD COLUMN `points_rules_locked_at` datetime DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
"""

ROLLBACK_17B = """-- PHASE 17 ROLLBACK
SET SESSION sql_mode = '';

DELETE FROM `rise_settings` WHERE `setting_name` IN (
    'task_points_review_rules_enabled',
    'task_points_review_rules_from',
    'task_review_changes_penalty_percent'
);

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rise_tasks' AND COLUMN_NAME = 'review_changes_count');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE `rise_tasks` DROP COLUMN `review_changes_count`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rise_tasks' AND COLUMN_NAME = 'first_review_submitted_at');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE `rise_tasks` DROP COLUMN `first_review_submitted_at`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rise_tasks' AND COLUMN_NAME = 'timer_lock_json');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE `rise_tasks` DROP COLUMN `timer_lock_json`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rise_tasks' AND COLUMN_NAME = 'points_rules_locked_at');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE `rise_tasks` DROP COLUMN `points_rules_locked_at`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
"""

root = Path(__file__).resolve().parents[1]
schema = (root / "database/phase17_schema.sql").read_text(encoding="utf-8")
rollback = (root / "database/phase17_schema_rollback.sql").read_text(encoding="utf-8")

def norm(s: str) -> str:
    return s.replace("\r\n", "\n").rstrip() + "\n"

print("schema_match", norm(schema) == norm(SCHEMA_17B))
print("rollback_match", norm(rollback) == norm(ROLLBACK_17B))
if norm(schema) != norm(SCHEMA_17B):
    import difflib
    print("--- schema diff ---")
    print("".join(difflib.unified_diff(norm(SCHEMA_17B).splitlines(True), norm(schema).splitlines(True), fromfile="17B", tofile="disk")))
if norm(rollback) != norm(ROLLBACK_17B):
    import difflib
    print("--- rollback diff ---")
    print("".join(difflib.unified_diff(norm(ROLLBACK_17B).splitlines(True), norm(rollback).splitlines(True), fromfile="17B", tofile="disk")))
