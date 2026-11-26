# Task Filters & Created By Queries

## Summary
No database schema changes were required. The table `rise_tasks` already includes `created_by` (INT) and `created_date` (DATE), and `rise_users` holds the creator credentials.

---

## 1. Deadline Filter SQL Logic
Located in `app/Models/Tasks_model.php`: `_build_deadline_where()`

### Expired:
```sql
AND IF(rise_tasks.deadline IS NULL, rise_milestones.due_date, rise_tasks.deadline) < '$now'
```

### Today:
```sql
AND IF(rise_tasks.deadline IS NULL, rise_milestones.due_date, rise_tasks.deadline) = '$now'
```

### Tomorrow:
```sql
AND IF(rise_tasks.deadline IS NULL, rise_milestones.due_date, rise_tasks.deadline) = '$tomorrow'
```

### In 7 Days (Inclusive of Today):
```sql
AND IF(rise_tasks.deadline IS NULL, rise_milestones.due_date, rise_tasks.deadline) BETWEEN '$now' AND '$in_7_days'
```

### In 15 Days (Inclusive of Today):
```sql
AND IF(rise_tasks.deadline IS NULL, rise_milestones.due_date, rise_tasks.deadline) BETWEEN '$now' AND '$in_15_days'
```

### Custom Range:
```sql
AND IF(rise_tasks.deadline IS NULL, rise_milestones.due_date, rise_tasks.deadline) BETWEEN '$from' AND '$to'
```

### Custom Single Date:
```sql
AND IF(rise_tasks.deadline IS NULL, rise_milestones.due_date, rise_tasks.deadline) = '$deadline'
```

---

## 2. Created By Filter & Join SQL
Located in `app/Models/Tasks_model.php`:

### JOIN:
```sql
LEFT JOIN rise_users AS creators ON creators.id = rise_tasks.created_by
```

### SELECT:
```sql
CONCAT(creators.first_name, ' ', creators.last_name) AS created_by_user_name,
creators.deleted AS created_by_user_deleted
```

### WHERE (when filter applied):
```sql
AND rise_tasks.created_by = $created_by
```
