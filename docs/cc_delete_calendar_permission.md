# Content Calendar: Delete Client Calendar Permission (`delete_client`)

## Schema Analysis
The permissions for Content Calendar are stored in `rise_cc_permissions`:
```sql
CREATE TABLE `rise_cc_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `permission_key` varchar(30) NOT NULL,
  `user_id` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
);
```

## SQL Migration Required: None
Because `permission_key` is a standard `varchar(30)` column without enum constraints, adding the key `"delete_client"` (13 characters) is handled natively by `Cc_permissions_model` and `Settings::save_content_calendar_access()`.

No `ALTER TABLE`, `CREATE TABLE`, or DDL statement is required on live or local environments.
