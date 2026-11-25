# Content Calendar: Special Day Notification Migration Guide

## 1. Overview
This migration introduces the deduplication table `rise_cc_special_day_notices` and adds a single-column index on `rise_cc_special_days(special_date)` to ensure daily cron runs and dashboard queries execute with optimal index seeks without table scans.

Compatible with:
- **MySQL 8.0.46+** (strict mode compliant)
- **MariaDB 10.11+**
- Does **NOT** query `INFORMATION_SCHEMA` (safe for restricted shared database hosts).

---

## 2. Pre-Check

Check if `special_date` already has a leading index:
```sql
SHOW INDEX FROM `rise_cc_special_days` WHERE Column_name = 'special_date' AND Seq_in_index = 1;
```
If empty, the index is needed.

---

## 3. Migration Steps (Forward)

Run the following SQL commands in your database:

```sql
-- Step 1: Create deduplication table for special day notifications
CREATE TABLE IF NOT EXISTS `rise_cc_special_day_notices` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `notice_date` DATE NOT NULL,
  `label_key` VARCHAR(190) NOT NULL,
  `label` VARCHAR(255) NOT NULL,
  `notified_at` DATETIME NULL,
  `recipients` INT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cc_sdn_date_key` (`notice_date`, `label_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 2: Add index on rise_cc_special_days for fast date lookup
ALTER TABLE `rise_cc_special_days` ADD INDEX `idx_cc_special_days_date` (`special_date`);
```

---

## 4. Verification

Verify the table and index were successfully created:
```sql
SHOW CREATE TABLE `rise_cc_special_day_notices`;
SHOW INDEX FROM `rise_cc_special_days` WHERE Key_name = 'idx_cc_special_days_date';
```

---

## 5. Rollback Steps

If you need to roll back this change:
```sql
DROP TABLE IF EXISTS `rise_cc_special_day_notices`;
ALTER TABLE `rise_cc_special_days` DROP INDEX `idx_cc_special_days_date`;
```
