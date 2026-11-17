from pathlib import Path

path = Path("/home/gokul/projects/crm_sunrise/app/Controllers/Projects.php")
text = path.read_text()

# Un-nest apply_task_points from inside apply_review_stamp function_exists blocks.
# Pattern appears multiple times with slight whitespace differences.
old_patterns = [
(
"""                if (function_exists('apply_review_stamp_on_status_change')) {
                    apply_review_stamp_on_status_change($task_info, $status_id, $data, $now);
                if (function_exists('apply_task_points_review_rules_on_status_change')) {
                    apply_task_points_review_rules_on_status_change($task_info, $status_id, $data, $now);
                }
                }""",
"""                if (function_exists('apply_review_stamp_on_status_change')) {
                    apply_review_stamp_on_status_change($task_info, $status_id, $data, $now);
                }
                if (function_exists('apply_task_points_review_rules_on_status_change')) {
                    apply_task_points_review_rules_on_status_change($task_info, $status_id, $data, $now);
                }"""
),
(
"""            if (function_exists('apply_review_stamp_on_status_change')) {
                apply_review_stamp_on_status_change($task_info, $status_id, $data, $data["status_changed_at"]);
                if (function_exists('apply_task_points_review_rules_on_status_change')) {
                    apply_task_points_review_rules_on_status_change($task_info, $status_id, $data, $data["status_changed_at"]);
                }
            }""",
"""            if (function_exists('apply_review_stamp_on_status_change')) {
                apply_review_stamp_on_status_change($task_info, $status_id, $data, $data["status_changed_at"]);
            }
            if (function_exists('apply_task_points_review_rules_on_status_change')) {
                apply_task_points_review_rules_on_status_change($task_info, $status_id, $data, $data["status_changed_at"]);
            }"""
),
(
"""                if (function_exists('apply_review_stamp_on_status_change')) {
                    apply_review_stamp_on_status_change($task_info, $value, $data, $data["status_changed_at"]);
                if (function_exists('apply_task_points_review_rules_on_status_change')) {
                    apply_task_points_review_rules_on_status_change($task_info, $value, $data, $data["status_changed_at"]);
                }
                }""",
"""                if (function_exists('apply_review_stamp_on_status_change')) {
                    apply_review_stamp_on_status_change($task_info, $value, $data, $data["status_changed_at"]);
                }
                if (function_exists('apply_task_points_review_rules_on_status_change')) {
                    apply_task_points_review_rules_on_status_change($task_info, $value, $data, $data["status_changed_at"]);
                }"""
),
(
"""                if (function_exists('apply_review_stamp_on_status_change')) {
                    apply_review_stamp_on_status_change($task_info, $status_id, $data, $data["status_changed_at"]);
                if (function_exists('apply_task_points_review_rules_on_status_change')) {
                    apply_task_points_review_rules_on_status_change($task_info, $status_id, $data, $data["status_changed_at"]);
                }
                }""",
"""                if (function_exists('apply_review_stamp_on_status_change')) {
                    apply_review_stamp_on_status_change($task_info, $status_id, $data, $data["status_changed_at"]);
                }
                if (function_exists('apply_task_points_review_rules_on_status_change')) {
                    apply_task_points_review_rules_on_status_change($task_info, $status_id, $data, $data["status_changed_at"]);
                }"""
),
(
"""                            if (function_exists('apply_review_stamp_on_status_change')) {
                                apply_review_stamp_on_status_change($task_info, $target_status_id, $data, $now);
                if (function_exists('apply_task_points_review_rules_on_status_change')) {
                    apply_task_points_review_rules_on_status_change($task_info, $target_status_id, $data, $now);
                }
                            }""",
"""                            if (function_exists('apply_review_stamp_on_status_change')) {
                                apply_review_stamp_on_status_change($task_info, $target_status_id, $data, $now);
                            }
                            if (function_exists('apply_task_points_review_rules_on_status_change')) {
                                apply_task_points_review_rules_on_status_change($task_info, $target_status_id, $data, $now);
                            }"""
),
(
"""        if (function_exists('apply_review_stamp_on_status_change')) {
            apply_review_stamp_on_status_change($task_info, $changes_status_id, $data, $now);
                if (function_exists('apply_task_points_review_rules_on_status_change')) {
                    apply_task_points_review_rules_on_status_change($task_info, $changes_status_id, $data, $now);
                }
        }""",
"""        if (function_exists('apply_review_stamp_on_status_change')) {
            apply_review_stamp_on_status_change($task_info, $changes_status_id, $data, $now);
        }
        if (function_exists('apply_task_points_review_rules_on_status_change')) {
            apply_task_points_review_rules_on_status_change($task_info, $changes_status_id, $data, $now);
        }"""
),
]

count = 0
for old, new in old_patterns:
    if old in text:
        text = text.replace(old, new, 1)
        count += 1
    else:
        print("MISSING pattern:")
        print(repr(old[:120]))

# Approve path: lock before Done if still unlocked
approve_old = """        $data = array(
            "status_id" => $done_status_id,
            "status_changed_at" => $now
        );

        $save_id = $this->Tasks_model->ci_save($data, $id);"""

approve_new = """        $data = array(
            "status_id" => $done_status_id,
            "status_changed_at" => $now
        );

        // Phase 17C: lock timer/lateness before Done if In-review task missed the earlier lock
        if (function_exists("ensure_task_points_review_rules_locked")) {
            ensure_task_points_review_rules_locked($task_info);
        }
        if (function_exists("lock_task_points_review_rules") && function_exists("is_task_points_review_rules_applicable")
            && is_task_points_review_rules_applicable($task_info)) {
            lock_task_points_review_rules($task_info, $data, $now);
        }

        $save_id = $this->Tasks_model->ci_save($data, $id);"""

if approve_old in text:
    text = text.replace(approve_old, approve_new, 1)
    count += 1
    print("approve lock patched")
else:
    print("approve block missing")

path.write_text(text)
print(f"patched_blocks={count}")
