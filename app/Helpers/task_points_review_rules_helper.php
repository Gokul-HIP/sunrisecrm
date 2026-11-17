<?php

/**
 * Phase 17: timer lock + lateness-once + change penalty + held points.
 * Active only when task_points_review_rules_enabled and task created on/after start date.
 */

if (!function_exists('task_points_review_rules_enabled')) {

    function task_points_review_rules_enabled() {
        return (string) get_setting("task_points_review_rules_enabled") === "1";
    }

}

if (!function_exists('get_task_points_review_rules_from')) {

    function get_task_points_review_rules_from() {
        return trim((string) get_setting("task_points_review_rules_from"));
    }

}

if (!function_exists('get_task_review_changes_penalty_percent')) {

    function get_task_review_changes_penalty_percent() {
        $p = (float) get_setting("task_review_changes_penalty_percent");
        if ($p < 0) {
            $p = 0;
        }
        return $p;
    }

}

if (!function_exists('is_task_points_review_rules_applicable')) {

    /**
     * Rules apply when flag on, a valid start date is set, task created on/after that UTC date,
     * and task is not a legacy In-review (submitted_for_review_at NULL while In review).
     *
     * DEFAULT SAFETY: task_points_review_rules_from defaults to ''. With the flag enabled and
     * from '' or an invalid date, rules apply to NO tasks (not to all).
     */
    function is_task_points_review_rules_applicable($task) {
        if (!task_points_review_rules_enabled() || !$task) {
            return false;
        }
        $from = get_task_points_review_rules_from();
        // Empty or invalid from-date => apply to no tasks
        if ($from === "" || !is_valid_task_points_review_rules_from_date($from)) {
            return false;
        }
        $created = isset($task->created_date) ? (string) $task->created_date : "";
        if ($created === "" || $created === "0000-00-00") {
            return false;
        }
        $from_date = substr($from, 0, 10);
        if ($created < $from_date) {
            return false;
        }
        // Legacy In review (stamp NULL) keeps today's behaviour
        if (function_exists("is_legacy_in_review_task") && is_legacy_in_review_task($task)) {
            return false;
        }
        return true;
    }

}

if (!function_exists('is_valid_task_points_review_rules_from_date')) {

    /**
     * True only for a real Y-m-d calendar date (rejects '', '0000-00-00', 'banana', '2020-13-40').
     */
    function is_valid_task_points_review_rules_from_date($from) {
        $from = trim((string) $from);
        if ($from === "") {
            return false;
        }
        $from_date = substr($from, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)) {
            return false;
        }
        if ($from_date === "0000-00-00") {
            return false;
        }
        $dt = \DateTime::createFromFormat("Y-m-d", $from_date);
        return $dt && $dt->format("Y-m-d") === $from_date;
    }

}

if (!function_exists('participant_has_task_timer_before')) {

    /**
     * Any non-deleted rise_project_time row for task+user with status open|logged
     * and start_time <= $as_of_utc. Manual vs timer cannot be distinguished — any entry counts.
     */
    function participant_has_task_timer_before($task_id, $user_id, $as_of_utc) {
        $task_id = (int) $task_id;
        $user_id = (int) $user_id;
        if (!$task_id || !$user_id || !$as_of_utc) {
            return false;
        }
        $db = db_connect("default");
        $table = $db->prefixTable("project_time");
        $as_of = $db->escape($as_of_utc);
        $sql = "SELECT id FROM `$table`
                WHERE deleted = 0
                  AND task_id = $task_id
                  AND user_id = $user_id
                  AND status IN ('open', 'logged')
                  AND start_time IS NOT NULL
                  AND start_time != '0000-00-00 00:00:00'
                  AND start_time <= $as_of
                LIMIT 1";
        $row = $db->query($sql)->getRow();
        return $row ? true : false;
    }

}

if (!function_exists('get_task_timer_lock_map')) {

    function get_task_timer_lock_map($task) {
        if (!$task || empty($task->timer_lock_json)) {
            return array();
        }
        $decoded = json_decode($task->timer_lock_json, true);
        return is_array($decoded) ? $decoded : array();
    }

}

if (!function_exists('lock_task_points_review_rules')) {

    /**
     * Lock timer results + first_review_submitted_at once.
     * Mutates $data for save; also updates $task_info properties for in-request use.
     *
     * Freeze moment preference: existing first_review → submitted stamp (in $data or on task) → $now.
     * Never use a later "now" when the review stamp already captured the submission instant
     * (so a post-submit timer cannot rescue an unlocked-in-review task).
     */
    function lock_task_points_review_rules($task_info, &$data, $now = null) {
        if (!$task_info) {
            return;
        }
        // Stamp from this same status change must count toward applicability/legacy:
        // entering In review writes submitted into $data while $task_info still has the old status.
        if (!empty($data["submitted_for_review_at"])) {
            $task_info->submitted_for_review_at = $data["submitted_for_review_at"];
        }
        if (!is_task_points_review_rules_applicable($task_info)) {
            return;
        }
        if (!empty($task_info->points_rules_locked_at) && $task_info->points_rules_locked_at !== "0000-00-00 00:00:00") {
            // Already locked — never re-evaluate timer; never clear first_review_submitted_at
            if (empty($task_info->first_review_submitted_at) || $task_info->first_review_submitted_at === "0000-00-00 00:00:00") {
                if (!$now) {
                    $now = get_current_utc_time();
                }
                $repair = $now;
                if (!empty($task_info->submitted_for_review_at) && $task_info->submitted_for_review_at !== "0000-00-00 00:00:00") {
                    $repair = $task_info->submitted_for_review_at;
                }
                $data["first_review_submitted_at"] = $repair;
                $task_info->first_review_submitted_at = $repair;
            }
            return;
        }
        if (!$now) {
            $now = get_current_utc_time();
        }

        $as_of = null;
        if (!empty($task_info->first_review_submitted_at) && $task_info->first_review_submitted_at !== "0000-00-00 00:00:00") {
            $as_of = $task_info->first_review_submitted_at;
        } else if (!empty($data["first_review_submitted_at"]) && $data["first_review_submitted_at"] !== "0000-00-00 00:00:00") {
            $as_of = $data["first_review_submitted_at"];
        } else if (!empty($data["submitted_for_review_at"]) && $data["submitted_for_review_at"] !== "0000-00-00 00:00:00") {
            $as_of = $data["submitted_for_review_at"];
        } else if (!empty($task_info->submitted_for_review_at) && $task_info->submitted_for_review_at !== "0000-00-00 00:00:00") {
            $as_of = $task_info->submitted_for_review_at;
        } else {
            $as_of = $now;
        }

        $data["first_review_submitted_at"] = $as_of;
        $task_info->first_review_submitted_at = $as_of;

        $participants = function_exists("get_task_participants") ? get_task_participants($task_info) : array();
        $lock = array();
        foreach ($participants as $uid) {
            $lock[(string) $uid] = participant_has_task_timer_before($task_info->id, $uid, $as_of) ? 1 : 0;
        }
        // Always persist a map (even all-zeros) so queue/approve do not re-open the live check
        if (empty($lock) && !empty($task_info->assigned_to)) {
            $lock[(string) (int) $task_info->assigned_to] = 0;
        }
        $json = json_encode($lock);
        $data["timer_lock_json"] = $json;
        $data["points_rules_locked_at"] = $now;
        $task_info->timer_lock_json = $json;
        $task_info->points_rules_locked_at = $now;
    }

}

if (!function_exists('ensure_task_points_review_rules_locked')) {

    /**
     * Lazy lock for In review / Changes tasks that missed the status-change lock.
     * Persists first_review + timer_lock_json once, frozen at submitted_for_review_at.
     * Returns true when a lock was written.
     */
    function ensure_task_points_review_rules_locked($task) {
        if (!$task || empty($task->id) || !is_task_points_review_rules_applicable($task)) {
            return false;
        }
        if (!empty($task->points_rules_locked_at) && $task->points_rules_locked_at !== "0000-00-00 00:00:00") {
            return false;
        }
        $status_id = isset($task->status_id) ? (int) $task->status_id : 0;
        $in_review = (int) get_in_review_task_status_id();
        $changes = (int) get_changes_task_status_id();
        if (!(($in_review && $status_id === $in_review) || ($changes && $status_id === $changes))) {
            return false;
        }
        $lock_data = array();
        $freeze = (!empty($task->submitted_for_review_at) && $task->submitted_for_review_at !== "0000-00-00 00:00:00")
            ? $task->submitted_for_review_at
            : get_current_utc_time();
        lock_task_points_review_rules($task, $lock_data, $freeze);
        if (empty($lock_data)) {
            return false;
        }
        $db = db_connect("default");
        $db->table("tasks")->where("id", (int) $task->id)->update($lock_data);
        return true;
    }

}

if (!function_exists('participant_timer_ok_for_task')) {

    function participant_timer_ok_for_task($task, $user_id) {
        $user_id = (int) $user_id;
        if (!$task || !$user_id) {
            return false;
        }
        $map = get_task_timer_lock_map($task);
        $key = (string) $user_id;
        if (array_key_exists($key, $map)) {
            return (int) $map[$key] === 1;
        }
        // Participant added after lock: must have had a timer at/before freeze moment
        if (!empty($task->first_review_submitted_at) && $task->first_review_submitted_at !== "0000-00-00 00:00:00") {
            $as_of = $task->first_review_submitted_at;
        } else if (!empty($task->submitted_for_review_at) && $task->submitted_for_review_at !== "0000-00-00 00:00:00") {
            $as_of = $task->submitted_for_review_at;
        } else {
            $as_of = get_current_utc_time();
        }
        return participant_has_task_timer_before($task->id, $user_id, $as_of);
    }

}

if (!function_exists('apply_change_penalty_to_points')) {

    function apply_change_penalty_to_points($base_after_lateness, $original_points, $review_changes_count, $penalty_percent = null) {
        $base = (float) $base_after_lateness;
        $original = (float) $original_points;
        $count = max(0, (int) $review_changes_count);
        if ($penalty_percent === null) {
            $penalty_percent = get_task_review_changes_penalty_percent();
        }
        $penalty_each = round($original * ((float) $penalty_percent / 100.0), 2);
        $final = $base - ($count * $penalty_each);
        if ($final < 0) {
            $final = 0;
        }
        return round($final, 2);
    }

}

if (!function_exists('calculate_task_participant_award_preview')) {

    /**
     * Compute per-participant earned under Phase 17 rules (or legacy when not applicable).
     * Returns array keyed by user_id: share_original, earned_points, timer_ok, reduction_*, is_held
     */
    function calculate_task_participant_award_preview($task, $completed_at = "", $for_held = false) {
        $result = array(
            "applicable" => false,
            "participants" => array(),
            "held_total" => 0.0,
            "held_original_total" => 0.0,
        );
        if (!$task) {
            return $result;
        }

        $total_points = isset($task->points) ? (float) $task->points : 0;
        $assigned_to = isset($task->assigned_to) ? (int) $task->assigned_to : 0;
        $is_split = function_exists("is_task_collab_split_eligible") && is_task_collab_split_eligible($task, $completed_at ?: get_current_utc_time());
        if ($is_split) {
            $participants = get_task_participants($task);
        } else {
            $participants = $assigned_to ? array($assigned_to) : array();
        }
        if (empty($participants)) {
            return $result;
        }

        $shares = distribute_task_points_shares($total_points, $participants, $assigned_to);
        $applicable = is_task_points_review_rules_applicable($task);
        $result["applicable"] = $applicable;
        $changes_count = isset($task->review_changes_count) ? (int) $task->review_changes_count : 0;

        // Lateness evaluation moment (locked at first In review / direct Done).
        // Fallback to submitted_for_review_at when lock columns are not yet filled
        // so queue/approve projection matches the freeze the stamp already captured.
        $eval_at = $completed_at;
        if ($applicable) {
            if (!empty($task->first_review_submitted_at) && $task->first_review_submitted_at !== "0000-00-00 00:00:00") {
                $eval_at = $task->first_review_submitted_at;
            } else if (!empty($task->submitted_for_review_at) && $task->submitted_for_review_at !== "0000-00-00 00:00:00") {
                $eval_at = $task->submitted_for_review_at;
            } else if (!$eval_at) {
                $eval_at = get_current_utc_time();
            }
        }

        $held_total = 0.0;
        $held_original = 0.0;
        foreach ($participants as $uid) {
            $share = $shares[$uid];
            $calc = calculate_task_point_result($task, $eval_at, $share["share_original"], array(
                "force_completed_at" => $applicable,
                "lateness_at" => $applicable ? $eval_at : null,
            ));
            $earned = (float) $calc["earned_points"];
            if ($applicable) {
                $earned = apply_change_penalty_to_points($earned, $share["share_original"], $changes_count);
                $timer_ok = participant_timer_ok_for_task($task, $uid);
                if (!$timer_ok) {
                    $earned = 0.0;
                }
            } else {
                $timer_ok = true;
            }

            $result["participants"][$uid] = array(
                "share_original" => $share["share_original"],
                "earned_points" => $earned,
                "timer_ok" => $timer_ok ? 1 : 0,
                "is_collab" => $share["is_collab"],
                "participants_count" => $share["participants_count"],
                "reduction_percent" => $calc["reduction_percent"],
                "reduction_reason" => $calc["reduction_reason"],
            );
            $held_total += $earned;
            $held_original += (float) $share["share_original"];
        }
        $result["held_total"] = round($held_total, 2);
        $result["held_original_total"] = round($held_original, 2);
        return $result;
    }

}

if (!function_exists('is_task_in_held_points_status')) {

    function is_task_in_held_points_status($task) {
        if (!$task || !is_task_points_review_rules_applicable($task)) {
            return false;
        }
        $status_id = isset($task->status_id) ? (int) $task->status_id : 0;
        $in_review = (int) get_in_review_task_status_id();
        $changes = (int) get_changes_task_status_id();
        return ($in_review && $status_id === $in_review) || ($changes && $status_id === $changes);
    }

}

if (!function_exists('get_task_held_points_display')) {

    function get_task_held_points_display($task, $for_user_id = 0) {
        $empty = array(
            "is_held" => false,
            "held" => 0,
            "original" => isset($task->points) ? (float) $task->points : 0,
            "label" => "",
            "held_label" => "0",
            "original_label" => format_task_point_value(isset($task->points) ? $task->points : 0),
        );
        if (!is_task_in_held_points_status($task)) {
            return $empty;
        }
        // Same projection Approve uses (via calculate_task_participant_award_preview)
        $proj = get_task_projected_award_display($task, $for_user_id);
        return array(
            "is_held" => true,
            "held" => $proj["award"],
            "original" => $proj["original"],
            "held_label" => $proj["award_label"],
            "original_label" => $proj["original_label"],
            "label" => $proj["held_label"],
            "preview" => $proj["preview"],
            "projection" => $proj,
        );
    }

}

if (!function_exists('build_task_projected_award_reasons')) {

    /**
     * Short reason lines for queue/modal tooltips (same inputs Award uses).
     */
    function build_task_projected_award_reasons($part, $changes_count = 0, $penalty_percent = null) {
        $reasons = array();
        $reduction_percent = isset($part["reduction_percent"]) ? (int) $part["reduction_percent"] : 0;
        if ($reduction_percent >= 100) {
            $reasons[] = app_lang("task_points_reason_overdue_72h") ?: "Overdue more than 72h";
        } else if ($reduction_percent >= 20) {
            $reasons[] = app_lang("task_points_reason_overdue_48h") ?: "Overdue 24–48h";
        } else if ($reduction_percent >= 10) {
            $reasons[] = app_lang("task_points_reason_overdue_24h") ?: "Overdue within 24h";
        }
        if (isset($part["timer_ok"]) && !(int) $part["timer_ok"]) {
            $reasons[] = app_lang("task_points_reason_no_timer") ?: "No timer";
        }
        $changes_count = (int) $changes_count;
        if ($changes_count > 0) {
            if ($penalty_percent === null) {
                $penalty_percent = get_task_review_changes_penalty_percent();
            }
            $share_original = isset($part["share_original"]) ? (float) $part["share_original"] : 0;
            $penalty_each = round($share_original * ((float) $penalty_percent / 100.0), 2);
            $deduct = round($changes_count * $penalty_each, 2);
            $tpl = app_lang("task_points_reason_change_penalty");
            if (!$tpl) {
                $tpl = $changes_count === 1
                    ? "1 change request: -%s"
                    : "%d change requests: -%s";
            }
            if (substr_count($tpl, "%") >= 2) {
                $reasons[] = sprintf($tpl, $changes_count, format_task_point_value($deduct));
            } else {
                $reasons[] = sprintf($tpl, format_task_point_value($deduct));
            }
        }
        return $reasons;
    }

}

if (!function_exists('get_task_projected_award_display')) {

    /**
     * Single UI projection for queue / modal / held / monthly.
     * Uses calculate_task_participant_award_preview (same path Approve awards through).
     * When rules flag is off or task is before from-date: lateness only (old behaviour).
     */
    function get_task_projected_award_display($task, $for_user_id = 0) {
        $total_points = isset($task->points) ? (float) $task->points : 0;
        $assignee = isset($task->assigned_to) ? (int) $task->assigned_to : 0;
        $uid = $for_user_id ? (int) $for_user_id : $assignee;
        $result = array(
            "award" => $total_points,
            "original" => $total_points,
            "award_label" => format_task_point_value($total_points),
            "original_label" => format_task_point_value($total_points),
            "queue_label" => format_task_point_value($total_points),
            "held_label" => format_task_point_value($total_points),
            "approval_label" => "",
            "reason" => "",
            "reasons" => array(),
            "tooltip" => "",
            "rules_applicable" => false,
            "lateness_only" => true,
            "confirm_needed" => false,
            "assignee_id" => $assignee,
            "assignee_name" => "",
            "participants_count" => 1,
            "split_label" => "",
            "participant_shares" => array(),
            "preview" => null,
            "for_user_id" => $uid,
        );

        if (!$task) {
            return $result;
        }

        if (!empty($task->assigned_to_user)) {
            $result["assignee_name"] = $task->assigned_to_user;
        } else if ($assignee && function_exists("get_team_member_names")) {
            // leave blank; UI can fill from row
        }

        // Phase 17C: tasks already In review/Changes without a lock — lock at first display
        // (freeze at submitted_for_review_at) so queue shows the same timer result Approve will use.
        if (function_exists("ensure_task_points_review_rules_locked")) {
            ensure_task_points_review_rules_locked($task);
        }

        $preview = calculate_task_participant_award_preview($task, "", true);
        $result["preview"] = $preview;
        $result["rules_applicable"] = !empty($preview["applicable"]);
        $result["lateness_only"] = empty($preview["applicable"]);

        $changes_count = isset($task->review_changes_count) ? (int) $task->review_changes_count : 0;
        $parts = isset($preview["participants"]) ? $preview["participants"] : array();

        // When rules not applicable, preview still has lateness-only participant rows
        if (empty($parts) && $assignee) {
            // Safety: ensure at least assignee lateness projection
            $calc = calculate_task_point_result($task, "", $total_points);
            $parts[$assignee] = array(
                "share_original" => $total_points,
                "earned_points" => (float) $calc["earned_points"],
                "timer_ok" => 1,
                "is_collab" => false,
                "participants_count" => 1,
                "reduction_percent" => (int) $calc["reduction_percent"],
                "reduction_reason" => $calc["reduction_reason"],
            );
        }

        $p_count = count($parts);
        $result["participants_count"] = $p_count;
        $shares_out = array();
        foreach ($parts as $pid => $part) {
            $shares_out[(int) $pid] = array(
                "user_id" => (int) $pid,
                "award" => (float) $part["earned_points"],
                "original" => (float) $part["share_original"],
                "is_collab" => !empty($part["is_collab"]),
            );
        }
        $result["participant_shares"] = $shares_out;

        if ($uid && isset($parts[$uid])) {
            $focus = $parts[$uid];
        } else if ($assignee && isset($parts[$assignee])) {
            $focus = $parts[$assignee];
            $uid = $assignee;
        } else {
            $focus = reset($parts);
        }

        $award = $focus ? (float) $focus["earned_points"] : 0.0;
        $original = $focus ? (float) $focus["share_original"] : $total_points;
        $award_label = format_task_point_value($award);
        $orig_label = format_task_point_value($original);

        $reasons = array();
        if ($result["rules_applicable"] && $focus) {
            $reasons = build_task_projected_award_reasons($focus, $changes_count);
        } else if ($focus && !empty($focus["reduction_percent"])) {
            // Lateness-only short reasons
            $reasons = build_task_projected_award_reasons(array_merge($focus, array("timer_ok" => 1)), 0);
        }
        $reason = implode("; ", $reasons);

        $will = app_lang("will_award_points") ?: "Will award %s of %s";
        $held = app_lang("held_award_points") ?: "Held %s of %s";
        $on_approval = app_lang("on_approval_will_award") ?: "On approval will award %s of %s";

        $queue_label = sprintf($will, $award_label, $orig_label);
        $held_label = sprintf($held, $award_label, $orig_label);
        $approval_label = sprintf($on_approval, $award_label, $orig_label);
        if ($reason !== "") {
            $approval_label .= " (" . $reason . ")";
            $queue_label_with_sub = $queue_label;
        } else {
            $queue_label_with_sub = $queue_label;
        }

        if ($p_count > 1) {
            $split = sprintf(app_lang("task_points_split_between") ?: "split between %s", $p_count);
            $result["split_label"] = $split;
            // Include per-share brief for assignee focus
            $share_bits = array();
            foreach ($shares_out as $sid => $s) {
                $who = ((int) $sid === $assignee) ? "assignee" : ("#" . $sid);
                $share_bits[] = $who . " " . format_task_point_value($s["award"]) . " of " . format_task_point_value($s["original"]);
            }
            if ($share_bits) {
                $queue_label_with_sub .= " (" . implode("; ", $share_bits) . ")";
            } else {
                $queue_label_with_sub .= " (" . $split . ")";
            }
        }

        $result["award"] = $award;
        $result["original"] = $original;
        $result["award_label"] = $award_label;
        $result["original_label"] = $orig_label;
        $result["queue_label"] = $queue_label_with_sub;
        $result["queue_label_short"] = $queue_label;
        $result["held_label"] = $held_label;
        $result["approval_label"] = $approval_label;
        $result["reason"] = $reason;
        $result["reasons"] = $reasons;
        $result["tooltip"] = $reason !== "" ? $reason : $queue_label;
        $result["confirm_needed"] = ($award <= 0) || ($award < $original);
        $result["for_user_id"] = $uid;
        return $result;
    }

}

if (!function_exists('sum_user_held_task_points')) {

    function sum_user_held_task_points($user_id, $year = 0, $month = 0) {
        $user_id = (int) $user_id;
        if (!$user_id || !task_points_review_rules_enabled()) {
            return 0.0;
        }
        if (!$year || !$month) {
            $year = (int) get_my_local_time("Y");
            $month = (int) get_my_local_time("n");
        }
        $from = sprintf("%04d-%02d-01", $year, $month);
        $to = date("Y-m-t", strtotime($from));
        $rules_from = get_task_points_review_rules_from();
        // Empty/invalid from => no held (rules apply to no tasks)
        if ($rules_from === "" || !is_valid_task_points_review_rules_from_date($rules_from)) {
            return 0.0;
        }
        $rules_from_date = substr($rules_from, 0, 10);

        $db = db_connect("default");
        $tasks = $db->prefixTable("tasks");
        $in_review = (int) get_in_review_task_status_id();
        $changes = (int) get_changes_task_status_id();
        if (!$in_review && !$changes) {
            return 0.0;
        }
        $status_ids = array_filter(array($in_review, $changes));
        $status_in = implode(",", $status_ids);
        $sql = "SELECT * FROM `$tasks`
                WHERE deleted = 0
                  AND status_id IN ($status_in)
                  AND created_date >= " . $db->escape($rules_from_date) . "
                  AND (assigned_to = $user_id OR FIND_IN_SET($user_id, collaborators))
                  AND (
                      (deadline IS NOT NULL AND deadline >= " . $db->escape($from) . " AND deadline <= " . $db->escape($to) . ")
                      OR (deadline IS NULL AND created_date >= " . $db->escape($from) . " AND created_date <= " . $db->escape($to) . ")
                  )";
        $rows = $db->query($sql)->getResult();
        $sum = 0.0;
        foreach ($rows as $task) {
            if (!is_task_points_review_rules_applicable($task)) {
                continue;
            }
            $disp = get_task_held_points_display($task, $user_id);
            if ($disp["is_held"]) {
                $sum += (float) $disp["held"];
            }
        }
        return round($sum, 2);
    }

}

if (!function_exists('apply_task_points_review_rules_on_status_change')) {

    /**
     * Mutates $data:
     * - First enter In review OR direct Done: lock first_review_submitted_at + timer
     * - Enter Changes (from queue or Done->Changes): review_changes_count += 1
     * Never clears first_review_submitted_at.
     */
    function apply_task_points_review_rules_on_status_change($task_info, $new_status_id, &$data, $now = null) {
        if (!$task_info || !isset($task_info->status_id)) {
            return;
        }
        if (!is_task_points_review_rules_applicable($task_info)) {
            return;
        }
        $old_id = (int) $task_info->status_id;
        $new_id = (int) $new_status_id;
        if ($old_id === $new_id) {
            return;
        }
        if (!$now) {
            $now = get_current_utc_time();
        }

        $in_review_id = (int) get_in_review_task_status_id();
        $done_id = (int) get_done_task_status_id();
        $changes_id = (int) get_changes_task_status_id();

        // Lock on first In review or direct Done (skip-review)
        if (($in_review_id && $new_id === $in_review_id) || ($done_id && $new_id === $done_id && $old_id !== $in_review_id)) {
            lock_task_points_review_rules($task_info, $data, $now);
        }

        // Change rounds: entering Changes from anywhere (queue Request changes, or Done->Changes)
        if ($changes_id && $new_id === $changes_id) {
            $prev = isset($task_info->review_changes_count) ? (int) $task_info->review_changes_count : 0;
            if (isset($data["review_changes_count"])) {
                $prev = (int) $data["review_changes_count"];
            }
            $data["review_changes_count"] = $prev + 1;
            $task_info->review_changes_count = $prev + 1;
        }
    }

}
