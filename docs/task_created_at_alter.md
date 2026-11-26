# Database Migration: Task `created_at` Column

## 1. Goal
Store the true creation timestamp of every task directly in `rise_tasks.created_at` (UTC datetime), eliminating runtime reliance on the activity log for newly created tasks while preserving backwards compatibility for older tasks.

---

## 2. Main Migration Statement (Mandatory)
Execute on the target database:

```sql
ALTER TABLE `rise_tasks` ADD COLUMN `created_at` DATETIME NULL DEFAULT NULL;
```

### Notes & Safety:
* **No AFTER clause**, default is `NULL`, no new index is required.
* **No INFORMATION_SCHEMA queries**: The application uses framework column detection (`fieldExists()` via `SHOW COLUMNS`).
* **Deploy Order Independent**: The code is backwards-compatible:
  - If the code is deployed **before** the SQL: It detects that `created_at` does not exist, skips writing to it, and falls back to the activity log query for reading.
  - If the SQL is deployed **before** the code: `created_at` defaults to `NULL`, which seamlessly falls back to the activity log reader.

---

## 3. Optional Historical Backfill Statement (OPTIONAL)

> [!NOTE]
> This statement is **completely optional**. If omitted, tasks with `created_at IS NULL` will continue to fetch their creation time from the batch activity log reader or show `"-"` if no log exists.

To populate `created_at` for existing tasks that have a recorded `created` event in `rise_activity_logs`:

```sql
UPDATE `rise_tasks` t
INNER JOIN (
    SELECT log_type_id, MIN(created_at) AS first_created_at
    FROM `rise_activity_logs`
    WHERE deleted = 0 
      AND log_type = 'task' 
      AND action = 'created'
    GROUP BY log_type_id
) act ON t.id = act.log_type_id
SET t.created_at = act.first_created_at
WHERE t.created_at IS NULL;
```

### Local Test Verification:
* Executed locally on `127.0.0.1:8099`.
* Rows affected/filled: **412 rows**.
* Remaining tasks without activity log rows remain `NULL` and correctly display `"-"` without errors.
