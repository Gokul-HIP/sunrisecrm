from pathlib import Path
p = Path("app/Helpers/task_points_helper.php")
text = p.read_text()
old = '''        $total_points = isset($task->points) ? (float) $task->points : 0;
        $shares = distribute_task_points_shares($total_points, $participants, $assigned_to);

        foreach ($participants as $uid) {
            $share_info = $shares[$uid];
            $calc = calculate_task_point_result($task, $completed_at, $share_info["share_original"]);

            $award_data = array(
                "task_id" => $task->id,
                "user_id" => $uid,
                "is_collaborator" => $share_info["is_collab"] ? 1 : 0,
                "participants_count" => $share_info["participants_count"],
                "original_points" => $share_info["share_original"],
                "earned_points" => $calc["earned_points"],
                "reduction_percent" => $calc["reduction_percent"],
                "reduction_reason" => $calc["reduction_reason"],
                "deadline" => (!empty($task->deadline) && $task->deadline !== "0000-00-00") ? $task->deadline : null,
                "completed_date" => $completed_date,
                "completed_at" => $completed_at,
                "year" => $year,
                "month" => $month,
                "deleted" => 0
            );

            $awards_model->upsert_award_for_user($award_data, $task->id, $uid);
            refresh_user_monthly_task_points($uid, $year, $month);
        }

        return $awards_model->get_by_task_id($task->id);
    }

}'''

new = '''        $total_points = isset($task->points) ? (float) $task->points : 0;
        $shares = distribute_task_points_shares($total_points, $participants, $assigned_to);

        // Phase 17: when rules apply, use locked first-submission lateness + change penalty + timer
        $rules_preview = null;
        if (function_exists("is_task_points_review_rules_applicable") && is_task_points_review_rules_applicable($task)
            && function_exists("calculate_task_participant_award_preview")) {
            $rules_preview = calculate_task_participant_award_preview($task, $completed_at, false);
        }

        foreach ($participants as $uid) {
            $share_info = $shares[$uid];
            if ($rules_preview && isset($rules_preview["participants"][$uid])) {
                $part = $rules_preview["participants"][$uid];
                $earned = (float) $part["earned_points"];
                $reduction_percent = (int) $part["reduction_percent"];
                $reduction_reason = $part["reduction_reason"];
                if (empty($part["timer_ok"])) {
                    $reduction_percent = 100;
                    $reduction_reason = app_lang("task_points_no_timer_zero") ?: "No timer entry before review submission — 0 points.";
                } else if ((int) ($task->review_changes_count ?? 0) > 0 && $earned < (float) $share_info["share_original"]) {
                    if ($reduction_reason) {
                        $reduction_reason .= " ";
                    }
                    $reduction_reason .= sprintf(
                        app_lang("task_points_change_penalty_applied") ?: "Change penalty: %s round(s).",
                        (int) $task->review_changes_count
                    );
                }
            } else {
                $calc = calculate_task_point_result($task, $completed_at, $share_info["share_original"]);
                $earned = $calc["earned_points"];
                $reduction_percent = $calc["reduction_percent"];
                $reduction_reason = $calc["reduction_reason"];
            }

            $award_data = array(
                "task_id" => $task->id,
                "user_id" => $uid,
                "is_collaborator" => $share_info["is_collab"] ? 1 : 0,
                "participants_count" => $share_info["participants_count"],
                "original_points" => $share_info["share_original"],
                "earned_points" => $earned,
                "reduction_percent" => $reduction_percent,
                "reduction_reason" => $reduction_reason,
                "deadline" => (!empty($task->deadline) && $task->deadline !== "0000-00-00") ? $task->deadline : null,
                "completed_date" => $completed_date,
                "completed_at" => $completed_at,
                "year" => $year,
                "month" => $month,
                "deleted" => 0
            );

            $awards_model->upsert_award_for_user($award_data, $task->id, $uid);
            refresh_user_monthly_task_points($uid, $year, $month);
        }

        return $awards_model->get_by_task_id($task->id);
    }

}'''

if old not in text:
    raise SystemExit('recalc block not found')
p.write_text(text.replace(old, new, 1))
print('recalc patched')
