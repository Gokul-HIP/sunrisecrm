from pathlib import Path

p = Path("app/Helpers/activity_logs_helper.php")
text = p.read_text()

old1 = """            } else if (get_array_value($schema_info, \"type\") === \"time\") {
                $changes = \"<del>\" . $from_value . \"</del> <ins>\" . $to_value . \"</ins>\";
            } else if (get_array_value($schema_info, \"type\") === \"date_time\") {
                $changes = \"<del>\" . $from_value . \"</del> <ins>\" . $to_value . \"</ins>\";
            } else {
                $changes = \"<del>\" . $from_value . \"</del> <ins>\" . $to_value . \"</ins>\";
            }"""

new1 = """            } else if (get_array_value($schema_info, \"type\") === \"time\") {
                // end_time is a wall-clock time (not a UTC datetime)
                $from_display = $from_value;
                $to_display = $to_value;
                if ($from_value && $from_value !== \"00:00:00\" && function_exists(\"format_to_time\")) {
                    $from_display = format_to_time($from_value, false);
                }
                if ($to_value && $to_value !== \"00:00:00\" && function_exists(\"format_to_time\")) {
                    $to_display = format_to_time($to_value, false);
                }
                $changes = \"<del>\" . $from_display . \"</del> <ins>\" . $to_display . \"</ins>\";
            } else if (get_array_value($schema_info, \"type\") === \"date_time\") {
                $from_display = $from_value;
                $to_display = $to_value;
                if ($from_value && function_exists(\"format_to_datetime\")) {
                    $from_display = format_to_datetime($from_value);
                }
                if ($to_value && function_exists(\"format_to_datetime\")) {
                    $to_display = format_to_datetime($to_value);
                }
                $changes = \"<del>\" . $from_display . \"</del> <ins>\" . $to_display . \"</ins>\";
            } else {
                $changes = \"<del>\" . $from_value . \"</del> <ins>\" . $to_value . \"</ins>\";
            }"""

# File uses unescaped quotes — rewrite without Python escapes matching PHP source
old1 = r'''            } else if (get_array_value($schema_info, "type") === "time") {
                $changes = "<del>" . $from_value . "</del> <ins>" . $to_value . "</ins>";
            } else if (get_array_value($schema_info, "type") === "date_time") {
                $changes = "<del>" . $from_value . "</del> <ins>" . $to_value . "</ins>";
            } else {
                $changes = "<del>" . $from_value . "</del> <ins>" . $to_value . "</ins>";
            }'''

new1 = r'''            } else if (get_array_value($schema_info, "type") === "time") {
                // end_time is a wall-clock time (not a UTC datetime)
                $from_display = $from_value;
                $to_display = $to_value;
                if ($from_value && $from_value !== "00:00:00" && function_exists("format_to_time")) {
                    $from_display = format_to_time($from_value, false);
                }
                if ($to_value && $to_value !== "00:00:00" && function_exists("format_to_time")) {
                    $to_display = format_to_time($to_value, false);
                }
                $changes = "<del>" . $from_display . "</del> <ins>" . $to_display . "</ins>";
            } else if (get_array_value($schema_info, "type") === "date_time") {
                $from_display = $from_value;
                $to_display = $to_value;
                if ($from_value && function_exists("format_to_datetime")) {
                    $from_display = format_to_datetime($from_value);
                }
                if ($to_value && function_exists("format_to_datetime")) {
                    $to_display = format_to_datetime($to_value);
                }
                $changes = "<del>" . $from_display . "</del> <ins>" . $to_display . "</ins>";
            } else {
                $changes = "<del>" . $from_value . "</del> <ins>" . $to_value . "</ins>";
            }'''

old2 = r'''                } else if ($action === "all" || $action === "updated") {
                    foreach ($changes as $field => $value) {
                        if ($is_notification) {
                            array_push($changes_array, get_change_logs_of_custom_fields($field, $is_notification));
                        } else {
                            $change_log = get_change_logs($log_type, $field, $value);
                            if ($change_log) {
                                array_push($changes_array, "<li>" . $change_log . "</li>");
                            }
                        }
                    }
                }'''

new2 = r'''                } else if ($action === "all" || $action === "updated") {
                    foreach ($changes as $field => $value) {
                        if ($log_type === "task" && in_array($field, get_task_activity_log_hidden_fields(), true)) {
                            continue;
                        }
                        if ($is_notification) {
                            array_push($changes_array, get_change_logs_of_custom_fields($field, $is_notification));
                        } else {
                            $change_log = get_change_logs($log_type, $field, $value);
                            if ($change_log) {
                                array_push($changes_array, "<li>" . $change_log . "</li>");
                            }
                        }
                    }
                }'''

if old1 not in text:
    raise SystemExit("old1 not found")
if old2 not in text:
    raise SystemExit("old2 not found")
text = text.replace(old1, new1, 1).replace(old2, new2, 1)
p.write_text(text)
print("patched ok")
