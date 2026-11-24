# Content Calendar import v2 — database alter

**File to run:** `docs/content_calendar_import_v2_alter.sql`  
**Do not** change or re-run `docs/content_calendar.sql` for this upgrade.  
**Compatible with:** MySQL 8.0.46 (strict) and MariaDB 10.11.  
**Idempotent:** safe to run twice (INFORMATION_SCHEMA checks + `PREPARE` / `EXECUTE`).

## What this changes

1. **`rise_cc_items.publish_date`** — becomes **NULL-able** so imported (and manually saved) content can exist without a date (“Without date”).
2. **`rise_cc_import_logs.run_id`** — new nullable `VARCHAR(40)` that groups every sheet/log of one multi-sheet import so the whole run can be undone together.
3. **`idx_cc_import_logs_run`** — index on `run_id`.

Nothing else is altered (no new tables; columns, options, clients, months, and special days are unchanged by undo).

## Live order

1. Take a backup.
2. Run **`docs/content_calendar_import_v2_alter.sql`** on the database.
3. Upload / deploy the matching application code.
4. Do **not** upload this alter after code that already writes `NULL` dates or `run_id` without the columns existing.

## Verification queries

```sql
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
  FROM INFORMATION_SCHEMA.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'rise_cc_items'
   AND COLUMN_NAME = 'publish_date';
-- Expect: IS_NULLABLE = 'YES'

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
  FROM INFORMATION_SCHEMA.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'rise_cc_import_logs'
   AND COLUMN_NAME = 'run_id';
-- Expect: one row, VARCHAR(40), IS_NULLABLE = 'YES'

SELECT INDEX_NAME, COLUMN_NAME
  FROM INFORMATION_SCHEMA.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'rise_cc_import_logs'
   AND INDEX_NAME = 'idx_cc_import_logs_run';
-- Expect: one row

SELECT COUNT(*) AS undated_items
  FROM rise_cc_items
 WHERE deleted = 0 AND publish_date IS NULL;
```

## Rollback notes

Rollback SQL is commented at the bottom of the `.sql` file.

**Critical:** before making `publish_date` `NOT NULL` again, rows with a `NULL` date must be dealt with (assign a date, soft-delete, or hard-delete). Otherwise the `MODIFY` fails under strict mode.

```sql
SELECT id, calendar_id, title
  FROM rise_cc_items
 WHERE deleted = 0 AND publish_date IS NULL;
```

Then (only after NULL dates are gone), drop `run_id` / its index and restore `NOT NULL` on `publish_date` as shown in the SQL file comments.
