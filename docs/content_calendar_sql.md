# Content Calendar - SQL (phase 1 + phase 2 import)

Target: MySQL 8.0 (strict mode) and MariaDB 10.11. Table prefix `rise_`.
The same SQL is available as a plain file next to this one: `docs/content_calendar.sql`.

**Short version:** run `docs/content_calendar.sql` as it is, on a fresh database or on one that already has phase 1.
The whole file is idempotent (safe to run twice).

## 1. What this changes

Phase 1 (already on your local database):
- 7 tables: `rise_cc_calendars`, `rise_cc_columns`, `rise_cc_items`, `rise_cc_months`, `rise_cc_special_days`, `rise_cc_targets`, `rise_cc_permissions`.

Phase 2 (import):
- New table `rise_cc_import_logs` (one row per import into one client).
- New nullable column `rise_cc_items.import_batch_id VARCHAR(40)` plus index `idx_cc_items_import_batch`.
- No existing row is changed, no seed row, no settings row, no menu row.
- Soft delete (`deleted` = 0/1) is used on calendars, columns and items. "Undo this import" soft-deletes the items of that batch only.

Also create this folder on the server (code creates it if it can, but create it with write permission for the web user):
`writable/files/content_calendar/imports/`

## 2. A. Fresh install: CREATE TABLE IF NOT EXISTS (all 8 tables)

```sql
-- 1) Calendars (one per client)
CREATE TABLE IF NOT EXISTS `rise_cc_calendars` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `client_id` INT(11) NULL DEFAULT NULL,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  `deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT(11) NOT NULL DEFAULT 0,
  `created_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cc_calendars_deleted_sort` (`deleted`, `sort_order`),
  KEY `idx_cc_calendars_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Columns of each calendar
CREATE TABLE IF NOT EXISTS `rise_cc_columns` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `calendar_id` INT(11) NOT NULL,
  `field_key` VARCHAR(80) NOT NULL,
  `label` VARCHAR(120) NOT NULL,
  `input_type` VARCHAR(20) NOT NULL DEFAULT 'text',
  `options` JSON NULL,
  `is_core` TINYINT(1) NOT NULL DEFAULT 0,
  `is_visible` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  `deleted` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cc_columns_calendar_key` (`calendar_id`, `field_key`),
  KEY `idx_cc_columns_calendar_sort` (`calendar_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Content items (phase 2: import_batch_id marks the rows of one import)
CREATE TABLE IF NOT EXISTS `rise_cc_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `calendar_id` INT(11) NOT NULL,
  `publish_date` DATE NOT NULL,
  `content_type` VARCHAR(60) NOT NULL DEFAULT '',
  `status` VARCHAR(60) NOT NULL DEFAULT '',
  `title` VARCHAR(255) NOT NULL DEFAULT '',
  `values_json` JSON NULL,
  `deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT(11) NOT NULL DEFAULT 0,
  `updated_by` INT(11) NOT NULL DEFAULT 0,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  `import_batch_id` VARCHAR(40) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cc_items_calendar_date` (`calendar_id`, `publish_date`),
  KEY `idx_cc_items_calendar_type_status` (`calendar_id`, `content_type`, `status`),
  KEY `idx_cc_items_import_batch` (`import_batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Months shown in the month row
CREATE TABLE IF NOT EXISTS `rise_cc_months` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `calendar_id` INT(11) NOT NULL,
  `year` SMALLINT(6) NOT NULL,
  `month` TINYINT(4) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cc_months_calendar_ym` (`calendar_id`, `year`, `month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) Special days
CREATE TABLE IF NOT EXISTS `rise_cc_special_days` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `calendar_id` INT(11) NOT NULL,
  `special_date` DATE NOT NULL,
  `label` VARCHAR(120) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cc_special_days_calendar_date` (`calendar_id`, `special_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) Monthly targets per content type (summary tiles only)
CREATE TABLE IF NOT EXISTS `rise_cc_targets` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `calendar_id` INT(11) NOT NULL,
  `content_type` VARCHAR(60) NOT NULL,
  `target_count` INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cc_targets_calendar_type` (`calendar_id`, `content_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7) Permissions (view, add, edit, delete, manage_options)
CREATE TABLE IF NOT EXISTS `rise_cc_permissions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `permission_key` VARCHAR(30) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `created_by` INT(11) NOT NULL DEFAULT 0,
  `created_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cc_permissions_key_user` (`permission_key`, `user_id`),
  KEY `idx_cc_permissions_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8) Import log (phase 2): one row per import into one client
CREATE TABLE IF NOT EXISTS `rise_cc_import_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `calendar_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL DEFAULT 0,
  `file_name` VARCHAR(255) NOT NULL DEFAULT '',
  `sheet_name` VARCHAR(120) NOT NULL DEFAULT '',
  `rows_total` INT(11) NOT NULL DEFAULT 0,
  `rows_imported` INT(11) NOT NULL DEFAULT 0,
  `rows_skipped` INT(11) NOT NULL DEFAULT 0,
  `columns_created` INT(11) NOT NULL DEFAULT 0,
  `options_created` INT(11) NOT NULL DEFAULT 0,
  `batch_id` VARCHAR(40) NOT NULL,
  `undone` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cc_import_logs_batch` (`batch_id`),
  KEY `idx_cc_import_logs_calendar` (`calendar_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 3. B. Upgrade for a database that already has phase 1 (idempotent)

MySQL 8 has no `ADD COLUMN IF NOT EXISTS`, so the column and the index are added with
`INFORMATION_SCHEMA` + `PREPARE` / `EXECUTE`. Run this block together with the `CREATE TABLE IF NOT EXISTS rise_cc_import_logs` statement (table 8 above).

```sql
-- B1) the log table
CREATE TABLE IF NOT EXISTS `rise_cc_import_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `calendar_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL DEFAULT 0,
  `file_name` VARCHAR(255) NOT NULL DEFAULT '',
  `sheet_name` VARCHAR(120) NOT NULL DEFAULT '',
  `rows_total` INT(11) NOT NULL DEFAULT 0,
  `rows_imported` INT(11) NOT NULL DEFAULT 0,
  `rows_skipped` INT(11) NOT NULL DEFAULT 0,
  `columns_created` INT(11) NOT NULL DEFAULT 0,
  `options_created` INT(11) NOT NULL DEFAULT 0,
  `batch_id` VARCHAR(40) NOT NULL,
  `undone` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cc_import_logs_batch` (`batch_id`),
  KEY `idx_cc_import_logs_calendar` (`calendar_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- B2) rise_cc_items.import_batch_id (nullable)
SET @cc_col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rise_cc_items' AND COLUMN_NAME = 'import_batch_id');
SET @cc_sql := IF(@cc_col_exists = 0, 'ALTER TABLE `rise_cc_items` ADD COLUMN `import_batch_id` VARCHAR(40) NULL DEFAULT NULL', 'SELECT 1');
PREPARE cc_stmt FROM @cc_sql;
EXECUTE cc_stmt;
DEALLOCATE PREPARE cc_stmt;

-- B3) index on rise_cc_items.import_batch_id
SET @cc_idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rise_cc_items' AND INDEX_NAME = 'idx_cc_items_import_batch');
SET @cc_sql := IF(@cc_idx_exists = 0, 'ALTER TABLE `rise_cc_items` ADD INDEX `idx_cc_items_import_batch` (`import_batch_id`)', 'SELECT 1');
PREPARE cc_stmt FROM @cc_sql;
EXECUTE cc_stmt;
DEALLOCATE PREPARE cc_stmt;
```

The user variables are named `@cc_*` so they do not clash with anything else in your session.

## 4. Settings / menu seed rows

None. The permission lists live in `rise_cc_permissions`; the menu item is added in code. Import uses the existing `add` permission (rows), admin (new columns, new clients) and `manage_options` or admin (new options). No new permission card.

## 5. Verification queries (read only)

```sql
-- table list (expect 8)
SHOW TABLES LIKE 'rise\_cc\_%';

-- row counts
SELECT 'rise_cc_calendars' AS t, COUNT(*) AS n FROM rise_cc_calendars
UNION ALL SELECT 'rise_cc_columns', COUNT(*) FROM rise_cc_columns
UNION ALL SELECT 'rise_cc_items', COUNT(*) FROM rise_cc_items
UNION ALL SELECT 'rise_cc_months', COUNT(*) FROM rise_cc_months
UNION ALL SELECT 'rise_cc_special_days', COUNT(*) FROM rise_cc_special_days
UNION ALL SELECT 'rise_cc_targets', COUNT(*) FROM rise_cc_targets
UNION ALL SELECT 'rise_cc_permissions', COUNT(*) FROM rise_cc_permissions
UNION ALL SELECT 'rise_cc_import_logs', COUNT(*) FROM rise_cc_import_logs;

-- phase 2: the new column (expect 1 row: varchar(40), YES) and index (expect 1 row)
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rise_cc_items' AND COLUMN_NAME = 'import_batch_id';
SELECT INDEX_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rise_cc_items' AND INDEX_NAME = 'idx_cc_items_import_batch';

-- index list
SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
  FROM INFORMATION_SCHEMA.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'rise\_cc\_%'
 GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE
 ORDER BY TABLE_NAME, INDEX_NAME;

-- imports and how many of their rows are still live
SELECT l.id, l.calendar_id, l.file_name, l.sheet_name, l.rows_imported, l.undone,
       (SELECT COUNT(*) FROM rise_cc_items i WHERE i.import_batch_id = l.batch_id AND i.deleted = 0) AS live_rows
  FROM rise_cc_import_logs l ORDER BY l.id DESC LIMIT 20;
```

## 6. Rollback (DESTRUCTIVE)

### 6a. Phase 2 only

Removes the import history and the `import_batch_id` column. Items, columns and calendars stay. Take a backup first. Not meant to run twice (the second run errors because the index / column is already gone).

```sql
DROP TABLE IF EXISTS `rise_cc_import_logs`;
ALTER TABLE `rise_cc_items` DROP INDEX `idx_cc_items_import_batch`;
ALTER TABLE `rise_cc_items` DROP COLUMN `import_batch_id`;
```

### 6b. The whole module

**This deletes all Content Calendar data (calendars, columns, items, months, special days, targets, permissions, import logs). Take a backup first and only run it if you really want to remove the module.** It only touches the `rise_cc_*` tables.

```sql
DROP TABLE IF EXISTS `rise_cc_import_logs`;
DROP TABLE IF EXISTS `rise_cc_permissions`;
DROP TABLE IF EXISTS `rise_cc_targets`;
DROP TABLE IF EXISTS `rise_cc_special_days`;
DROP TABLE IF EXISTS `rise_cc_months`;
DROP TABLE IF EXISTS `rise_cc_items`;
DROP TABLE IF EXISTS `rise_cc_columns`;
DROP TABLE IF EXISTS `rise_cc_calendars`;
```

## 7. Which statements are safe to run twice

| Section | Safe to run twice? |
|---|---|
| 2. A. CREATE TABLE IF NOT EXISTS (all 8) | Yes. A second run changes nothing. |
| 3. B1 `CREATE TABLE IF NOT EXISTS rise_cc_import_logs` | Yes. |
| 3. B2 / B3 column and index (INFORMATION_SCHEMA + PREPARE) | Yes. They only ALTER when the column / index is missing, otherwise they run `SELECT 1`. |
| The whole `docs/content_calendar.sql` file | Yes, on a fresh database and on a phase 1 database. |
| 5. Verification queries | Yes (read only). |
| 6a / 6b. Rollback | No. Destructive and not repeatable. Both are commented out in `content_calendar.sql`. |

Notes:
- `JSON` columns: on MySQL 8 they are native JSON; on MariaDB 10.11 `JSON` is an alias of `LONGTEXT` with a JSON validity check. The code only ever writes valid JSON or NULL.
- There are no foreign keys (same as the rest of RISE); rows are scoped by `calendar_id` in code.
- A calendar name is unique among non-deleted calendars; this is checked in code (a SQL unique index would block re-using the name of a soft-deleted calendar).
- Until the upgrade is run, the calendar pages keep working (they never read `import_batch_id`); only Import fails, with a clear message.
