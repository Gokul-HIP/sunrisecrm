# Content Calendar — permission keys `add_client` and `add_column`

**Date:** 2026-11-20  
**Target:** MySQL 8 / MariaDB 10.11, table prefix `rise_`.  
**Local only until you choose to run this on the server.**

## Do you need a table ALTER?

**No.** Existing table `rise_cc_permissions` already stores free-text keys:

```sql
`permission_key` VARCHAR(30) NOT NULL
```

New keys fit that column:

| Key | Length | Meaning |
|---|---|---|
| `add_client` | 10 | Who can create a client calendar (+ Add client / import “new client”) |
| `add_column` | 10 | Who can open Columns and add / edit / reorder / hide / copy columns |

Rows are inserted only when an admin saves a staff list in **Settings → Content Calendar access**. There is no seed data and no ENUM to extend.

Admins still have everything without being listed (`cc_can` → `is_admin`).

Still admin-only (`admin_manage`, not a Settings card):

- Delete client calendar
- Set monthly targets on the summary tiles

## Optional: verify the column (no change)

Safe to run anytime. Confirms length is enough:

```sql
SHOW COLUMNS FROM `rise_cc_permissions` LIKE 'permission_key';
-- Expect: varchar(30)

SELECT DISTINCT `permission_key`
FROM `rise_cc_permissions`
ORDER BY `permission_key`;
```

## Optional: widen the column (only if you want headroom)

Not required for these two keys. Use only if you prefer a longer key column for the future:

```sql
-- Safe to re-run: sets the same type again
ALTER TABLE `rise_cc_permissions`
  MODIFY COLUMN `permission_key` VARCHAR(40) NOT NULL;
```

## Optional: remove a mistaken assignment

```sql
-- Example: clear Add client for one user
-- DELETE FROM `rise_cc_permissions`
-- WHERE `permission_key` = 'add_client' AND `user_id` = 123;

-- Example: clear all Add column assignments
-- DELETE FROM `rise_cc_permissions`
-- WHERE `permission_key` = 'add_column';
```

## Rollback of the feature (app only)

There is nothing to drop in the schema for this change. To undo the product behaviour, redeploy the previous PHP/JS/views and (optionally) delete the new key rows:

```sql
-- DELETE FROM `rise_cc_permissions`
-- WHERE `permission_key` IN ('add_client', 'add_column');
```

## After deploy

1. Upload the changed PHP / JS / views / language file (no SQL required for this feature).
2. Open **Settings → Content Calendar access**.
3. You should see two new cards: **Who can add a client calendar** and **Who can add and manage columns**.
4. Select staff, Save each card, then test as a non-admin user.
