from pathlib import Path

# Patch get_task_point_display to surface held points before preview calc
p = Path("app/Helpers/task_points_helper.php")
text = p.read_text()

# Insert held-points early return before the preview calc at end of get_task_point_display
marker = '''        $participants = get_task_participants($task);
        $p_count = count($participants);
        $is_collab = $p_count > 1;
        $total_pts = $original_points;

        $user_share_points = $original_points;
        $split_eligible = $is_collab && is_task_collab_split_eligible($task, get_current_utc_time());
'''

insert = '''        // Phase 17 held points (In review / Changes): show held, not earned
        if (function_exists("is_task_in_held_points_status") && is_task_in_held_points_status($task)
            && function_exists("get_task_held_points_display")) {
            $held = get_task_held_points_display($task);
            $display["original_points"] = (float) $held["original"];
            $display["earned_points"] = (float) $held["held"];
            $display["is_reduced"] = $display["earned_points"] != $display["original_points"];
            $display["is_preview"] = true;
            $display["is_held"] = true;
            $display["held_label"] = $held["label"];
            $display["reduction_reason"] = $held["label"];
            $display["total_task_points"] = isset($task->points) ? (float) $task->points : 0;
            $participants = get_task_participants($task);
            $p_count = count($participants);
            $display["is_collab"] = $p_count > 1 && is_task_collab_split_eligible($task, get_current_utc_time());
            $display["participants_count"] = $display["is_collab"] ? $p_count : 1;
            return $display;
        }

''' + marker

if marker not in text:
    raise SystemExit('display marker not found')
if 'is_held"] = true' not in text:
    text = text.replace(marker, insert, 1)
    p.write_text(text)
    print('get_task_point_display held patched')
else:
    print('display already patched')

# Patch badge html for held label
text = p.read_text()
old_badge = '''        $points_text = "<span class='task-points-given'>" . $original . "</span>";
        if ($display["is_reduced"]) {
            $points_text .= " <span class='task-points-earned'>→ " . $earned . "</span>";
        }
'''
new_badge = '''        if (!empty($display["is_held"]) && !empty($display["held_label"])) {
            $points_text = "<span class='task-points-held'>" . htmlspecialchars($display["held_label"]) . "</span>";
        } else {
        $points_text = "<span class='task-points-given'>" . $original . "</span>";
        if ($display["is_reduced"]) {
            $points_text .= " <span class='task-points-earned'>→ " . $earned . "</span>";
        }
        }
'''
if old_badge not in text:
    raise SystemExit('badge block not found')
if "task-points-held" not in text:
    p.write_text(text.replace(old_badge, new_badge, 1))
    print('badge held patched')
else:
    print('badge already patched')

# Header summary: add held_points
text = p.read_text()
old_ret = '''        return array(
            "score" => $score,
            "score_label" => format_task_point_value($score),
            "solo_earned" => $solo_earned,
            "solo_earned_label" => format_task_point_value($solo_earned),
            "solo_overall" => $solo_overall,
            "solo_overall_label" => format_task_point_value($solo_overall),
            "collab_earned" => $collab_earned,
            "collab_earned_label" => format_task_point_value($collab_earned),
            "collab_overall" => $collab_overall,
            "collab_overall_label" => format_task_point_value($collab_overall),
            "year" => $year,
'''
new_ret = '''        $held_points = 0.0;
        if (function_exists("sum_user_held_task_points")) {
            $held_points = sum_user_held_task_points($user_id, $year, $month);
        }

        return array(
            "score" => $score,
            "score_label" => format_task_point_value($score),
            "solo_earned" => $solo_earned,
            "solo_earned_label" => format_task_point_value($solo_earned),
            "solo_overall" => $solo_overall,
            "solo_overall_label" => format_task_point_value($solo_overall),
            "collab_earned" => $collab_earned,
            "collab_earned_label" => format_task_point_value($collab_earned),
            "collab_overall" => $collab_overall,
            "collab_overall_label" => format_task_point_value($collab_overall),
            "held_points" => $held_points,
            "held_points_label" => format_task_point_value($held_points),
            "year" => $year,
'''
if old_ret not in text:
    raise SystemExit('header return not found')
if '"held_points"' not in text:
    p.write_text(text.replace(old_ret, new_ret, 1))
    print('header held patched')
else:
    print('header already patched')
