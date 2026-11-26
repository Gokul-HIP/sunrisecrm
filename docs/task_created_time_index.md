# Activity Logs Created Time Index Documentation

## 1. Context & Query
To display the accurate task creation time in the Tasks list without relying on `rise_tasks.status_changed_at` (which changes whenever a task status updates), task creation times are fetched in a single batch query per page of results:

```sql
SELECT log_type_id, MIN(created_at) AS created_at 
FROM rise_activity_logs 
WHERE deleted=0 AND log_type='task' AND action='created' AND log_type_id IN (...) 
GROUP BY log_type_id;
```

---

## 2. EXPLAIN Query Analysis on Existing Indexes

### Query EXPLAIN:
```sql
EXPLAIN SELECT log_type_id, MIN(created_at) AS created_at 
FROM rise_activity_logs 
WHERE deleted=0 AND log_type='task' AND action='created' AND log_type_id IN (26066, 26065, 26064, 26050, 15775) 
GROUP BY log_type_id;
```

### Output:
| id | select_type | table | partitions | type | possible_keys | key | key_len | ref | rows | filtered | Extra |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | SIMPLE | rise_activity_logs | NULL | range | idx_activity_logs_type_id_del | idx_activity_logs_type_id_del | 97 | NULL | 8 | 100.00 | Using index condition; Using where |

### Findings:
1. The query **successfully uses the existing composite index** `idx_activity_logs_type_id_del` on `(log_type, log_type_id, deleted)`.
2. MySQL uses index condition pushdown (`Using index condition`) for `log_type` and `log_type_id IN (...)`.
3. However, there is currently **no existing composite index containing `action`** (`log_type`, `log_type_id`, `action`). MySQL filters `action = 'created'` as part of `Using where`.

---

## 3. Table Size & Migration Risk Assessment (Local)

* **Table**: `rise_activity_logs`
* **Row Count**: ~1,208 rows
* **Data Length**: ~245,760 bytes (~0.23 MB)
* **Index Length**: ~98,304 bytes (~0.09 MB)
* **Total Table Footprint**: ~0.33 MB
* **Local Query Latency**: < 1 ms for 100 task IDs.
* **Risk Assessment**: Extremely low risk. The table is very lightweight. The existing index `idx_activity_logs_type_id_del` already restricts the search to a handful of rows before checking `action`.

---

## 4. Optional Index Optimization (DO NOT RUN ON LIVE)

If production has a very high volume of activity log entries (e.g., hundreds of thousands or millions of rows), adding an index that covers `action` and `created_at` will allow an index-only scan:

```sql
-- DO NOT RUN ON LIVE WITHOUT TESTING
CREATE INDEX idx_activity_logs_task_created_time 
ON rise_activity_logs (log_type, action, log_type_id, deleted, created_at);
```
