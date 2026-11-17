from pathlib import Path
p = Path("app/Controllers/Projects.php")
t = p.read_text()

old = '''        } else {
            $in_review_status_id = function_exists('get_in_review_task_status_id') ? (int) get_in_review_task_status_id() : 0;
            if ($in_review_status_id && (int) $status_id === $in_review_status_id && get_setting("task_review_workflow_enabled")) {
                $data["submitted_for_review_at"] = $now;
            }
        }

        $save_id = $this->Tasks_model->ci_save($data, $id);
        if ($save_id) {

            if ($is_clone && $main_task_id) {
'''

new = '''        } else {
            $in_review_status_id = function_exists('get_in_review_task_status_id') ? (int) get_in_review_task_status_id() : 0;
            if ($in_review_status_id && (int) $status_id === $in_review_status_id && get_setting("task_review_workflow_enabled")) {
                $data["submitted_for_review_at"] = $now;
            }
        }

        $save_id = $this->Tasks_model->ci_save($data, $id);
        if ($save_id) {
            // Phase 17: create-as-In-review/Done needs task id before timer lock can run
            if (!$id && function_exists("lock_task_points_review_rules")) {
                $created_task = $this->Tasks_model->get_one($save_id);
                if ($created_task && $created_task->id) {
                    $created_in_review_id = function_exists('get_in_review_task_status_id') ? (int) get_in_review_task_status_id() : 0;
                    $created_done_id = function_exists('get_done_task_status_id') ? (int) get_done_task_status_id() : 0;
                    $created_status = (int) $created_task->status_id;
                    if (($created_in_review_id && $created_status === $created_in_review_id)
                        || ($created_done_id && $created_status === $created_done_id)) {
                        $lock_data = array();
                        lock_task_points_review_rules($created_task, $lock_data, $now);
                        if (!empty($lock_data)) {
                            $this->Tasks_model->ci_save($lock_data, $save_id);
                        }
                    }
                }
            }

            if ($is_clone && $main_task_id) {
'''

if old not in t:
    raise SystemExit('create block not found')
if 'Phase 17: create-as-In-review' not in t:
    p.write_text(t.replace(old, new, 1))
    print('create-path lock wired')
else:
    print('already wired')
