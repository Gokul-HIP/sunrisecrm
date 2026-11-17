<?php

if (!function_exists('ensure_task_point_tables')) {

    function ensure_task_point_tables() {
        static $ensured = false;
        if ($ensured) {
            return true;
        }

        $db = db_connect('default');
        $prefix = $db->getPrefix();

        $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}task_point_awards` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `task_id` int(11) NOT NULL,
            `user_id` int(11) NOT NULL DEFAULT 0,
            `is_collaborator` tinyint(1) NOT NULL DEFAULT 0,
            `participants_count` int(11) NOT NULL DEFAULT 1,
            `original_points` decimal(10,2) NOT NULL DEFAULT 0.00,
            `earned_points` decimal(10,2) NOT NULL DEFAULT 0.00,
            `reduction_percent` int(11) NOT NULL DEFAULT 0,
            `reduction_reason` text,
            `deadline` date DEFAULT NULL,
            `completed_date` date NOT NULL,
            `completed_at` datetime NOT NULL,
            `year` int(11) NOT NULL,
            `month` int(11) NOT NULL,
            `deleted` tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `task_user` (`task_id`, `user_id`),
            KEY `idx_task_id` (`task_id`, `deleted`),
            KEY `user_month` (`user_id`, `year`, `month`, `deleted`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

        $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}task_point_monthly_totals` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `year` int(11) NOT NULL,
            `month` int(11) NOT NULL,
            `total_points` decimal(10,2) NOT NULL DEFAULT 0.00,
            `updated_at` datetime DEFAULT NULL,
            `deleted` tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `user_year_month` (`user_id`, `year`, `month`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

        $ensured = true;
        if (function_exists("ensure_task_points_edited_column")) {
            ensure_task_points_edited_column();
        }
        return true;
    }

}

if (!function_exists('get_done_task_status_id')) {

    function get_done_task_status_id() {
        static $status_id = null;
        if ($status_id !== null) {
            return $status_id;
        }

        $status = model("App\Models\Task_status_model")->get_one_where(array("key_name" => "done", "deleted" => 0));
        if (!$status || !$status->id) {
            $status = model("App\Models\Task_status_model")->get_one_where(array("title" => "Done", "deleted" => 0));
        }
        $status_id = ($status && $status->id) ? (int) $status->id : 0;
        return $status_id;
    }

}

if (!function_exists('get_in_review_task_status_id')) {

    function get_in_review_task_status_id() {
        static $status_id = null;
        if ($status_id !== null) {
            return $status_id;
        }

        $status = model("App\Models\Task_status_model")->get_one_where(array("key_name" => "in_review", "deleted" => 0));
        if (!$status || !$status->id) {
            $status = model("App\Models\Task_status_model")->get_one_where(array("title" => "In review", "deleted" => 0));
        }
        $status_id = ($status && $status->id) ? (int) $status->id : 0;
        return $status_id;
    }

}

if (!function_exists('get_changes_task_status_id')) {

    function get_changes_task_status_id() {
        static $status_id = null;
        if ($status_id !== null) {
            return $status_id;
        }

        $status = model("App\Models\Task_status_model")->get_one_where(array("key_name" => "changes", "deleted" => 0));
        if (!$status || !$status->id) {
            $status = model("App\Models\Task_status_model")->get_one_where(array("title" => "Changes", "deleted" => 0));
        }
        $status_id = ($status && $status->id) ? (int) $status->id : 0;
        return $status_id;
    }

}

if (!function_exists('is_task_status_done')) {

    function is_task_status_done($task) {
        if (!$task) {
            return false;
        }

        if (isset($task->status_key_name) && $task->status_key_name === "done") {
            return true;
        }

        return isset($task->status_id) && (string) $task->status_id === (string) get_done_task_status_id();
    }

}

if (!function_exists('get_task_point_highlight_class')) {

    function get_task_point_highlight_class($value) {
        $value = (float) $value;
        if ($value <= 0) {
            return "points-zero";
        }
        if ($value < 50) {
            return "points-low";
        }
        return "points-high";
    }

}

if (!function_exists('format_task_point_value')) {

    function format_task_point_value($value) {
        $value = (float) $value;
        if (floor($value) == $value) {
            return (string) (int) $value;
        }

        return rtrim(rtrim(number_format($value, 2, ".", ""), "0"), ".");
    }

}

if (!function_exists('get_task_deadline_datetime')) {

    function get_task_deadline_datetime($task) {
        $deadline = isset($task->deadline) ? $task->deadline : "";
        if (!$deadline || $deadline === "0000-00-00" || (function_exists("is_date_exists") && !is_date_exists($deadline))) {
            return "";
        }

        $end_time = isset($task->end_time) ? $task->end_time : "";
        if ($end_time && $end_time !== "00:00:00") {
            return trim($deadline . " " . $end_time);
        }

        return $deadline . " 23:59:59";
    }

}

if (!function_exists('get_task_points_collab_split_from')) {

    function get_task_points_collab_split_from() {
        $setting = function_exists("get_setting") ? trim((string) get_setting("task_points_collab_split_from")) : "";
        return $setting;
    }

}

if (!function_exists('is_task_collab_split_eligible')) {

    function is_task_collab_split_eligible($task, $completed_at = "") {
        if (!$task) {
            return false;
        }

        $effective_from = get_task_points_collab_split_from();
        if ($effective_from === "") {
            return false;
        }

        if (!$completed_at) {
            if (!empty($task->status_changed_at) && $task->status_changed_at !== "0000-00-00 00:00:00") {
                $completed_at = $task->status_changed_at;
            } else if (!empty($task->points_completed_at) && $task->points_completed_at !== "0000-00-00 00:00:00") {
                $completed_at = $task->points_completed_at;
            } else if (!empty($task->points_completed_date) && $task->points_completed_date !== "0000-00-00") {
                $completed_at = $task->points_completed_date . " 00:00:00";
            }
        }

        if (!$completed_at) {
            $completed_at = get_current_utc_time();
        }

        $completed_ts = strtotime($completed_at . (strpos($completed_at, 'Z') === false ? ' UTC' : ''));
        $effective_ts = strtotime($effective_from . (strpos($effective_from, 'Z') === false ? ' UTC' : ''));
        return ($completed_ts !== false && $effective_ts !== false && $completed_ts >= $effective_ts);
    }

}

if (!function_exists('get_task_participants')) {

    function get_task_participants($task) {
        $participants = array();
        $assigned_to = isset($task->assigned_to) ? (int) $task->assigned_to : 0;
        if ($assigned_to > 0) {
            $participants[] = $assigned_to;
        }

        $collab_str = isset($task->collaborators) ? (string) $task->collaborators : "";
        if ($collab_str !== "") {
            $parts = explode(",", $collab_str);
            foreach ($parts as $p) {
                $cid = (int) trim($p);
                if ($cid > 0 && !in_array($cid, $participants)) {
                    $participants[] = $cid;
                }
            }
        }
        return $participants;
    }

}

if (!function_exists('distribute_task_points_shares')) {

    function distribute_task_points_shares($total_points, $participants, $assigned_to = 0) {
        $count = count($participants);
        $result = array();
        if ($count === 0) {
            return $result;
        }

        if ($count === 1) {
            $uid = $participants[0];
            $result[$uid] = array(
                "share_original" => round((float) $total_points, 2),
                "is_collab" => ($assigned_to && $uid == $assigned_to) ? 0 : 0,
                "participants_count" => 1
            );
            return $result;
        }

        $total_cents = (int) round(((float) $total_points) * 100);
        $base_cents = intdiv($total_cents, $count);
        $rem_cents = $total_cents % $count;

        foreach ($participants as $i => $uid) {
            $cents = $base_cents + ($i < $rem_cents ? 1 : 0);
            $result[$uid] = array(
                "share_original" => round($cents / 100, 2),
                "is_collab" => ($assigned_to && $uid == $assigned_to) ? 0 : 1,
                "participants_count" => $count
            );
        }

        return $result;
    }

}

if (!function_exists('calculate_task_point_result')) {

    function calculate_task_point_result($task, $completed_at = "", $override_original_points = null, $options = array()) {
        $original_points = ($override_original_points !== null)
            ? (float) $override_original_points
            : (isset($task->points) ? (float) $task->points : 0);
        $result = array(
            "original_points" => $original_points,
            "earned_points" => $original_points,
            "reduction_percent" => 0,
            "reduction_reason" => "",
            "hours_late" => 0,
            "is_reduced" => false,
            "is_preview" => false
        );

        $deadline_datetime = get_task_deadline_datetime($task);
        if (!$deadline_datetime) {
            return $result;
        }

        // Phase 17: when review rules apply, lateness is locked to first_review_submitted_at
        // (never cleared). Otherwise Phase 16G: submitted_for_review_at only when present.
        $lateness_at = is_array($options) ? get_array_value($options, "lateness_at") : null;
        $force_completed_at = is_array($options) ? get_array_value($options, "force_completed_at") : false;

        if ($lateness_at) {
            $completed_local = convert_date_utc_to_local($lateness_at, "Y-m-d H:i:s");
        } else if (function_exists("is_task_points_review_rules_applicable")
            && is_task_points_review_rules_applicable($task)
            && !empty($task->first_review_submitted_at)
            && $task->first_review_submitted_at !== "0000-00-00 00:00:00") {
            $completed_local = convert_date_utc_to_local($task->first_review_submitted_at, "Y-m-d H:i:s");
        } else {
            // Lateness rule (Phase 16G):
            // Use submitted_for_review_at ONLY when workflow is on AND a stamp is present.
            $use_submitted_for_lateness = get_setting("task_review_workflow_enabled")
                && !empty($task->submitted_for_review_at)
                && $task->submitted_for_review_at !== "0000-00-00 00:00:00";
            if ($use_submitted_for_lateness) {
                $completed_local = convert_date_utc_to_local($task->submitted_for_review_at, "Y-m-d H:i:s");
                if (!$completed_at) {
                    $result["is_preview"] = true;
                }
            } else if ($completed_at || $force_completed_at) {
                $use_at = $completed_at ?: get_current_utc_time();
                $completed_local = convert_date_utc_to_local($use_at, "Y-m-d H:i:s");
            } else {
                $completed_local = get_my_local_time("Y-m-d H:i:s");
                $result["is_preview"] = true;
            }
        }

        $hours_late = (strtotime($completed_local) - strtotime($deadline_datetime)) / 3600;
        $result["hours_late"] = $hours_late;

        if ($hours_late <= 0) {
            return $result;
        }

        if ($hours_late <= 24) {
            $result["reduction_percent"] = 10;
            $result["earned_points"] = round($original_points * 0.9, 2);
            $result["reduction_reason"] = $result["is_preview"]
                ? sprintf(app_lang("points_will_be_reduced_24h"), format_task_point_value($original_points), format_task_point_value($result["earned_points"]))
                : sprintf(app_lang("points_reduced_24h"), format_task_point_value($original_points), format_task_point_value($result["earned_points"]));
        } else if ($hours_late <= 48) {
            $result["reduction_percent"] = 20;
            $result["earned_points"] = round($original_points * 0.8, 2);
            $result["reduction_reason"] = $result["is_preview"]
                ? sprintf(app_lang("points_will_be_reduced_48h"), format_task_point_value($original_points), format_task_point_value($result["earned_points"]))
                : sprintf(app_lang("points_reduced_48h"), format_task_point_value($original_points), format_task_point_value($result["earned_points"]));
        } else if ($hours_late < 72) {
            $result["reduction_percent"] = 20;
            $result["earned_points"] = round($original_points * 0.8, 2);
            $result["reduction_reason"] = $result["is_preview"]
                ? sprintf(app_lang("points_will_be_reduced_48h"), format_task_point_value($original_points), format_task_point_value($result["earned_points"]))
                : sprintf(app_lang("points_reduced_48h"), format_task_point_value($original_points), format_task_point_value($result["earned_points"]));
        } else {
            $result["reduction_percent"] = 100;
            $result["earned_points"] = 0;
            $result["reduction_reason"] = $result["is_preview"]
                ? sprintf(app_lang("points_will_be_reduced_72h"), format_task_point_value($original_points))
                : sprintf(app_lang("points_reduced_72h"), format_task_point_value($original_points));
        }

        $result["is_reduced"] = $result["earned_points"] != $original_points;
        return $result;
    }

}

if (!function_exists('refresh_user_monthly_task_points')) {

    function refresh_user_monthly_task_points($user_id, $year, $month) {
        $user_id = (int) $user_id;
        $year = (int) $year;
        $month = (int) $month;
        if (!$user_id || !$year || !$month) {
            return 0;
        }

        $total = model("App\Models\Task_point_awards_model")->get_monthly_sum($user_id, $year, $month);
        model("App\Models\Task_point_monthly_totals_model")->upsert_total($user_id, $year, $month, $total);
        return $total;
    }

}

if (!function_exists('parse_task_points_month')) {

    function parse_task_points_month($month_value = "") {
        if ($month_value && preg_match('/^(\d{4})-(\d{1,2})$/', $month_value, $matches)) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            if ($year >= 2000 && $month >= 1 && $month <= 12) {
                return array("year" => $year, "month" => $month, "value" => sprintf("%04d-%02d", $year, $month));
            }
        }

        $year = (int) get_my_local_time("Y");
        $month = (int) get_my_local_time("n");
        return array("year" => $year, "month" => $month, "value" => sprintf("%04d-%02d", $year, $month));
    }

}

if (!function_exists('get_task_points_month_options')) {

    function get_task_points_month_options($count = 24) {
        $options = array();
        $base = strtotime(get_my_local_time("Y-m-01"));
        $count = max(1, (int) $count);

        for ($i = 0; $i < $count; $i++) {
            $timestamp = strtotime("-" . $i . " months", $base);
            $year = (int) date("Y", $timestamp);
            $month = (int) date("n", $timestamp);
            $options[] = array(
                "value" => sprintf("%04d-%02d", $year, $month),
                "text" => date("F Y", $timestamp),
                "year" => $year,
                "month" => $month
            );
        }

        return $options;
    }

}

if (!function_exists('get_user_monthly_task_points')) {

    function get_user_monthly_task_points($user_id, $year = 0, $month = 0) {
        $user_id = (int) $user_id;
        if (!$user_id) {
            return 0;
        }

        if (!$year || !$month) {
            $year = (int) get_my_local_time("Y");
            $month = (int) get_my_local_time("n");
        }

        $stored = model("App\Models\Task_point_monthly_totals_model")->get_for_user_month($user_id, $year, $month);
        if ($stored && $stored->id) {
            return (float) $stored->total_points;
        }

        return model("App\Models\Task_point_awards_model")->get_monthly_sum($user_id, $year, $month);
    }

}

if (!function_exists('get_user_task_points_header_summary')) {

    function get_user_task_points_header_summary($user_id, $options = array()) {
        $user_id = (int) $user_id;
        if (!$user_id) {
            return array(
                "score" => 0,
                "score_label" => "0",
                "solo_earned" => 0,
                "solo_earned_label" => "0",
                "solo_overall" => 0,
                "solo_overall_label" => "0",
                "collab_earned" => 0,
                "collab_earned_label" => "0",
                "collab_overall" => 0,
                "collab_overall_label" => "0",
                "year" => (int) get_my_local_time("Y"),
                "month" => (int) get_my_local_time("n")
            );
        }

        $month_param = get_array_value($options, "month");
        if (!$month_param) {
            $month_param = get_array_value($options, "task_points_month");
        }
        $from = get_array_value($options, "created_from");
        $to = get_array_value($options, "created_to");

        if (!$month_param && $from) {
            $month_param = substr($from, 0, 7);
        }

        $parsed = parse_task_points_month($month_param);
        $year = $parsed["year"];
        $month = $parsed["month"];

        if (!$from || !$to) {
            $from = sprintf("%04d-%02d-01", $year, $month);
            $to = date("Y-m-t", strtotime($from));
        }

        $db = db_connect('default');
        $awards_table = $db->prefixTable('task_point_awards');
        $tasks_table = $db->prefixTable('tasks');

        // 1. Earned points from awards table by completion month (independent of status filter)
        $earned_sql = "SELECT
            COALESCE(SUM(CASE WHEN (participants_count <= 1 AND is_collaborator = 0) THEN earned_points ELSE 0 END), 0) AS solo_earned,
            COALESCE(SUM(CASE WHEN (participants_count > 1 OR is_collaborator = 1) THEN earned_points ELSE 0 END), 0) AS collab_earned,
            COALESCE(SUM(earned_points), 0) AS total_earned
        FROM $awards_table
        WHERE user_id = $user_id
          AND year = $year
          AND month = $month
          AND deleted = 0";
        $earned_row = $db->query($earned_sql)->getRow();

        $solo_earned = $earned_row ? (float) $earned_row->solo_earned : 0.0;
        $collab_earned = $earned_row ? (float) $earned_row->collab_earned : 0.0;
        $score = $earned_row ? (float) $earned_row->total_earned : 0.0;

        // 2. Overall points = user's share of tasks created in that period (solo vs collab), including not-done tasks
        $project_id = (int) get_array_value($options, "project_id");
        $project_filter = $project_id ? " AND t.project_id = $project_id " : "";

        $escaped_from = $db->escape($from);
        $escaped_to = $db->escape($to);
        $collab_split_from = get_task_points_collab_split_from();
        $split_active = ($collab_split_from !== "");
        $done_status_id = (int) get_done_task_status_id();

        $overall_sql = "SELECT
            COALESCE(SUM(CASE WHEN task_rows.is_collab = 0 THEN task_rows.user_share ELSE 0 END), 0) AS solo_overall,
            COALESCE(SUM(CASE WHEN task_rows.is_collab = 1 THEN task_rows.user_share ELSE 0 END), 0) AS collab_overall
        FROM (
            SELECT
                t.id,
                CASE
                    WHEN a.id IS NOT NULL THEN (IF(a.participants_count > 1 OR a.is_collaborator = 1, 1, 0))
                    WHEN " . ($split_active ? "1" : "0") . " = 1 AND (t.collaborators IS NOT NULL AND t.collaborators != '') THEN 1
                    ELSE 0
                END AS is_collab,
                MAX(CASE
                    WHEN IFNULL(a.original_points, 0) > 0 THEN a.original_points
                    WHEN " . ($split_active ? "1" : "0") . " = 0 OR (t.collaborators IS NULL OR t.collaborators = '') THEN IF(t.assigned_to = $user_id, t.points, 0)
                    ELSE ROUND(t.points / (IF(t.assigned_to > 0, 1, 0) + LENGTH(t.collaborators) - LENGTH(REPLACE(t.collaborators, ',', '')) + 1), 2)
                END) AS user_share
            FROM $tasks_table t
            LEFT JOIN $awards_table a ON (t.id = a.task_id AND a.user_id = $user_id AND a.deleted = 0 AND ((a.year = $year AND a.month = $month) OR (a.completed_date >= $escaped_from AND a.completed_date <= $escaped_to)))
            WHERE t.deleted = 0
              AND (t.assigned_to = $user_id OR FIND_IN_SET($user_id, t.collaborators))
              AND (
                  (a.id IS NOT NULL)
                  OR
                  (t.status_id = $done_status_id AND (
                      (t.status_changed_at IS NOT NULL AND DATE(t.status_changed_at) >= $escaped_from AND DATE(t.status_changed_at) <= $escaped_to)
                      OR (t.status_changed_at IS NULL AND (
                          (t.deadline IS NOT NULL AND t.deadline >= $escaped_from AND t.deadline <= $escaped_to)
                          OR (t.deadline IS NULL AND t.created_date >= $escaped_from AND t.created_date <= $escaped_to)
                      ))
                  ))
                  OR
                  (t.status_id != $done_status_id AND (
                      (t.deadline IS NOT NULL AND t.deadline >= $escaped_from AND t.deadline <= $escaped_to)
                      OR (t.deadline IS NULL AND t.created_date >= $escaped_from AND t.created_date <= $escaped_to)
                  ))
              )
              $project_filter
            GROUP BY t.id
        ) AS task_rows";
        $overall_row = $db->query($overall_sql)->getRow();

        $solo_overall = $overall_row ? (float) $overall_row->solo_overall : 0.0;
        $collab_overall = $overall_row ? (float) $overall_row->collab_overall : 0.0;

        $held_points = 0.0;
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
            "month" => $month
        );
    }

}

if (!function_exists('recalculate_task_point_awards')) {

    function recalculate_task_point_awards($task_or_id, $force = false) {
        ensure_task_point_tables();

        $task = is_object($task_or_id) ? $task_or_id : null;
        $task_id = is_object($task_or_id) ? (isset($task_or_id->id) ? (int) $task_or_id->id : 0) : (int) $task_or_id;
        if (!$task && $task_id) {
            $task = model("App\Models\Tasks_model")->get_details(array("id" => $task_id))->getRow();
        }

        if (!$task || empty($task->id)) {
            return null;
        }

        $awards_model = model("App\Models\Task_point_awards_model");

        if (!is_task_status_done($task) || !empty($task->deleted)) {
            $revoked_list = $awards_model->revoke_all_by_task_id($task->id);
            foreach ($revoked_list as $revoked) {
                if ($revoked->user_id) {
                    refresh_user_monthly_task_points($revoked->user_id, $revoked->year, $revoked->month);
                }
            }
            return null;
        }

        // Task IS Done.
        $is_split_eligible = is_task_collab_split_eligible($task);
        $existing_awards = $awards_model->get_all_by_task_id($task->id, false);

        // Pre-effective date tasks: keep their current single award untouched
        if (!$is_split_eligible && !empty($existing_awards)) {
            return $existing_awards[0];
        }

        $completed_at = (!empty($task->status_changed_at) && $task->status_changed_at !== "0000-00-00 00:00:00")
            ? $task->status_changed_at
            : (!empty($existing_awards[0]->completed_at) ? $existing_awards[0]->completed_at : get_current_utc_time());

        $completed_date = convert_date_utc_to_local($completed_at, "Y-m-d");
        $year = (int) date("Y", strtotime($completed_date));
        $month = (int) date("n", strtotime($completed_date));

        $assigned_to = isset($task->assigned_to) ? (int) $task->assigned_to : 0;
        if (!$is_split_eligible) {
            $participants = $assigned_to ? array($assigned_to) : array();
        } else {
            $participants = get_task_participants($task);
        }

        if (empty($participants)) {
            $revoked_list = $awards_model->revoke_all_by_task_id($task->id);
            foreach ($revoked_list as $revoked) {
                if ($revoked->user_id) {
                    refresh_user_monthly_task_points($revoked->user_id, $revoked->year, $revoked->month);
                }
            }
            return null;
        }

        // Revoke awards for users who no longer participate
        $participant_map = array_flip($participants);
        foreach ($existing_awards as $old_award) {
            if (!isset($participant_map[$old_award->user_id])) {
                $awards_model->revoke_for_user($task->id, $old_award->user_id);
                refresh_user_monthly_task_points($old_award->user_id, $old_award->year, $old_award->month);
                if ($old_award->year != $year || $old_award->month != $month) {
                    refresh_user_monthly_task_points($old_award->user_id, $year, $month);
                }
            }
        }

        $total_points = isset($task->points) ? (float) $task->points : 0;
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

}

if (!function_exists('sync_task_point_award')) {

    function sync_task_point_award($task_or_id) {
        ensure_task_point_tables();

        $task = is_object($task_or_id) ? $task_or_id : null;
        $task_id = is_object($task_or_id) ? (isset($task_or_id->id) ? (int) $task_or_id->id : 0) : (int) $task_or_id;
        if (!$task && $task_id) {
            $task = model("App\Models\Tasks_model")->get_details(array("id" => $task_id))->getRow();
        }

        if (!$task || empty($task->id)) {
            return null;
        }

        $awards_model = model("App\Models\Task_point_awards_model");

        if (!is_task_status_done($task)) {
            $revoked_list = $awards_model->revoke_all_by_task_id($task->id);
            foreach ($revoked_list as $revoked) {
                if ($revoked->user_id) {
                    refresh_user_monthly_task_points($revoked->user_id, $revoked->year, $revoked->month);
                }
            }
            return null;
        }

        // Early return for read/display paths: if award exists, return immediately without recalculating
        $existing = $awards_model->get_by_task_id($task->id);
        if ($existing) {
            return $existing;
        }

        return recalculate_task_point_awards($task, false);
    }

}

if (!function_exists('get_task_gained_points_value')) {

    function get_task_gained_points_value($task, $for_user_id = 0) {
        if (!$task) {
            return 0;
        }

        $for_user_id = (int) $for_user_id;
        if (isset($task->awarded_points) && $task->awarded_points !== null && $task->awarded_points !== "") {
            return (float) $task->awarded_points;
        }

        if ($for_user_id > 0) {
            // Phase 21E: archived/gained for a member is only their award row (not a borrowed/synced amount)
            if (!empty($task->id) && function_exists("ensure_task_point_tables")) {
                ensure_task_point_tables();
                $member_award = model("App\Models\Task_point_awards_model")->get_by_task_and_user((int) $task->id, $for_user_id);
                if ($member_award) {
                    return (float) $member_award->earned_points;
                }
            }
            return 0;
        }

        $display = get_task_point_display($task, $for_user_id);
        if (!empty($display["is_completed"]) && empty($display["no_member_award"])) {
            return (float) $display["earned_points"];
        }

        return 0;
    }

}

if (!function_exists('get_task_point_display')) {

    function get_task_point_display($task, $for_user_id = 0) {
        $original_points = isset($task->points) ? (float) $task->points : 0;
        $display = array(
            "original_points" => $original_points,
            "earned_points" => $original_points,
            "reduction_percent" => 0,
            "reduction_reason" => "",
            "is_reduced" => false,
            "is_preview" => false,
            "is_completed" => false,
            "completed_date" => "",
            "total_task_points" => $original_points,
            "is_collab" => false,
            "participants_count" => 1
        );

        if (!$task) {
            return $display;
        }

        $for_user_id = (int) $for_user_id;

        // Phase 21E: when a specific member is requested, scope award fields to THAT member
        // (do not keep an assignee award from get_details joins).
        // Phase 22: callers may pre-attach awards and set _award_scoped_for to skip N+1 reload.
        if ($for_user_id > 0) {
            $already_scoped = isset($task->_award_scoped_for) && (int) $task->_award_scoped_for === $for_user_id;
            if (!$already_scoped) {
                unset(
                    $task->awarded_points,
                    $task->awarded_original_points,
                    $task->point_reduction_percent,
                    $task->point_reduction_reason,
                    $task->points_completed_date,
                    $task->awarded_is_collaborator,
                    $task->awarded_participants_count
                );
                if (!empty($task->id) && function_exists("ensure_task_point_tables")) {
                    ensure_task_point_tables();
                    $member_award = model("App\Models\Task_point_awards_model")->get_by_task_and_user((int) $task->id, $for_user_id);
                    if ($member_award) {
                        $task->awarded_points = $member_award->earned_points;
                        $task->awarded_original_points = $member_award->original_points;
                        $task->point_reduction_percent = $member_award->reduction_percent;
                        $task->point_reduction_reason = $member_award->reduction_reason;
                        $task->points_completed_date = $member_award->completed_date;
                        $task->awarded_is_collaborator = $member_award->is_collaborator;
                        $task->awarded_participants_count = $member_award->participants_count;
                    }
                }
                $task->_award_scoped_for = $for_user_id;
            }
        }

        $has_award = isset($task->awarded_points) && $task->awarded_points !== null && $task->awarded_points !== "";
        if ($has_award) {
            $display["original_points"] = isset($task->awarded_original_points) && $task->awarded_original_points !== ""
                ? (float) $task->awarded_original_points
                : $original_points;
            $display["earned_points"] = (float) $task->awarded_points;
            $display["reduction_percent"] = isset($task->point_reduction_percent) ? (int) $task->point_reduction_percent : 0;
            $display["reduction_reason"] = isset($task->point_reduction_reason) ? $task->point_reduction_reason : "";
            $display["is_reduced"] = $display["earned_points"] != $display["original_points"];
            $display["is_completed"] = true;
            $display["completed_date"] = isset($task->points_completed_date) ? $task->points_completed_date : "";
            $display["total_task_points"] = $original_points;

            // Phase 21E: when an award exists, Score describes what was ACTUALLY awarded.
            // Do NOT re-derive a split from collaborators added after completion.
            $participants_count = !empty($task->awarded_participants_count) ? (int) $task->awarded_participants_count : 1;
            if ($participants_count < 1) {
                $participants_count = 1;
            }
            $is_collab = $participants_count > 1 || !empty($task->awarded_is_collaborator);
            $display["is_collab"] = $is_collab;
            $display["participants_count"] = $is_collab ? max(2, $participants_count) : 1;
            return $display;
        }

        if (is_task_status_done($task) && !empty($task->status_changed_at) && $task->status_changed_at !== "0000-00-00 00:00:00") {
            // Phase 21E: member-scoped display must not borrow another user's award via sync_task_point_award()
            if ($for_user_id > 0) {
                // Member award already attempted above; none for this user.
                // Show task points as solo (no invented split from collaborators added later).
                $calc = calculate_task_point_result($task, $task->status_changed_at);
                $display["earned_points"] = $calc["earned_points"];
                $display["reduction_percent"] = $calc["reduction_percent"];
                $display["reduction_reason"] = $calc["reduction_reason"];
                $display["is_reduced"] = $calc["is_reduced"];
                $display["is_completed"] = true;
                $display["is_collab"] = false;
                $display["participants_count"] = 1;
                $display["no_member_award"] = true;
                return $display;
            }

            $award = sync_task_point_award($task);
            if ($award) {
                $display["original_points"] = (float) $award->original_points;
                $display["earned_points"] = (float) $award->earned_points;
                $display["reduction_percent"] = (int) $award->reduction_percent;
                $display["reduction_reason"] = $award->reduction_reason;
                $display["is_reduced"] = $display["earned_points"] != $display["original_points"];
                $display["is_completed"] = true;
                $display["completed_date"] = $award->completed_date;
                $display["total_task_points"] = $original_points;
                $participants_count = !empty($award->participants_count) ? (int) $award->participants_count : 1;
                if ($participants_count < 1) {
                    $participants_count = 1;
                }
                $is_collab = $participants_count > 1 || !empty($award->is_collaborator);
                $display["is_collab"] = $is_collab;
                $display["participants_count"] = $is_collab ? max(2, $participants_count) : 1;
                return $display;
            }

            // Done but no award row at all: do not invent a live split from current collaborators
            $calc = calculate_task_point_result($task, $task->status_changed_at);
            $display["earned_points"] = $calc["earned_points"];
            $display["reduction_percent"] = $calc["reduction_percent"];
            $display["reduction_reason"] = $calc["reduction_reason"];
            $display["is_reduced"] = $calc["is_reduced"];
            $display["is_completed"] = true;
            $display["is_collab"] = false;
            $display["participants_count"] = 1;
            return $display;
        }

        // Phase 17 held / In review / Changes: locked projection (same as Approve), not "Completing now"
        $in_review_or_changes = false;
        if (function_exists("is_task_in_held_points_status") && is_task_in_held_points_status($task)) {
            $in_review_or_changes = true;
        } else {
            $sid = isset($task->status_id) ? (int) $task->status_id : 0;
            $ir = (int) get_in_review_task_status_id();
            $ch = (int) get_changes_task_status_id();
            $in_review_or_changes = ($ir && $sid === $ir) || ($ch && $sid === $ch);
        }
        if ($in_review_or_changes && function_exists("get_task_projected_award_display")) {
            $proj = get_task_projected_award_display($task, $for_user_id);
            $display["original_points"] = (float) $proj["original"];
            $display["earned_points"] = (float) $proj["award"];
            $display["is_reduced"] = $display["earned_points"] != $display["original_points"];
            $display["is_preview"] = true;
            $display["is_held"] = !empty($proj["rules_applicable"]);
            $display["held_label"] = $proj["held_label"];
            $display["reduction_reason"] = $proj["approval_label"] !== ""
                ? $proj["approval_label"]
                : $proj["queue_label"];
            $display["projection"] = $proj;
            $display["total_task_points"] = isset($task->points) ? (float) $task->points : 0;
            $p_count = (int) $proj["participants_count"];
            $display["is_collab"] = $p_count > 1;
            $display["participants_count"] = $p_count > 1 ? $p_count : 1;
            return $display;
        }

        $participants = get_task_participants($task);
        $p_count = count($participants);
        $is_collab = $p_count > 1;
        $total_pts = $original_points;

        $user_share_points = $original_points;
        $split_eligible = $is_collab && is_task_collab_split_eligible($task, get_current_utc_time());
        if ($split_eligible) {
            $assigned_to = isset($task->assigned_to) ? (int) $task->assigned_to : 0;
            $shares = distribute_task_points_shares($total_pts, $participants, $assigned_to);
            if ($for_user_id > 0 && isset($shares[$for_user_id])) {
                $user_share_points = $shares[$for_user_id]["share_original"];
            } else {
                $first_share = reset($shares);
                $user_share_points = $first_share ? $first_share["share_original"] : round($total_pts / $p_count, 2);
            }
        } else {
            $is_collab = false;
            $p_count = 1;
        }

        $display["original_points"] = $user_share_points;
        $display["total_task_points"] = $total_pts;
        $display["is_collab"] = $is_collab;
        $display["participants_count"] = $p_count;

        $calc = calculate_task_point_result($task, "", $user_share_points);
        $display["earned_points"] = $calc["earned_points"];
        $display["reduction_percent"] = $calc["reduction_percent"];
        $display["reduction_reason"] = $calc["reduction_reason"];
        $display["is_reduced"] = $calc["is_reduced"];
        $display["is_preview"] = $calc["is_preview"] && $calc["is_reduced"];
        return $display;
    }

}

if (!function_exists('get_task_points_score_plain_text')) {

    /**
     * Phase 21E: single plain Score text for Tasks page Excel, timesheet Excel/Print, and comparisons.
     * Same rules as the badge: when awards exist, describe what was actually awarded.
     */
    function get_task_points_score_plain_text($task, $options = array()) {
        $for_user_id = (int) get_array_value($options, "for_user_id");
        $compact = get_array_value($options, "compact");
        if ($compact === null) {
            $compact = true;
        }
        $display = get_task_point_display($task, $for_user_id);

        $original = format_task_point_value($display["original_points"]);
        $earned = format_task_point_value($display["earned_points"]);
        $is_collab = !empty($display["is_collab"]) && !empty($display["participants_count"]) && $display["participants_count"] > 1;
        $participants_count = $is_collab ? (int) $display["participants_count"] : 1;

        if (!empty($display["is_held"]) && !empty($display["held_label"])) {
            $text = $display["held_label"];
        } else if (!empty($display["projection"]["queue_label_short"])) {
            $text = $display["projection"]["queue_label_short"];
        } else {
            $text = $original;
            if (!empty($display["is_reduced"])) {
                $text .= " → " . $earned;
                if ($compact && !empty($display["reduction_percent"])) {
                    $text .= " -" . (int) $display["reduction_percent"] . "%";
                }
            }
        }

        if ($is_collab) {
            $text .= " (1 of " . $participants_count . ")";
        }

        return trim($text);
    }

}

if (!function_exists('get_task_points_badge_html')) {

    function get_task_points_badge_html($task, $options = array()) {
        $for_user_id = (int) get_array_value($options, "for_user_id");
        $display = get_task_point_display($task, $for_user_id);
        $compact = get_array_value($options, "compact");
        $show_reason = get_array_value($options, "show_reason");
        if ($show_reason === null) {
            $show_reason = true;
        }

        $original = format_task_point_value($display["original_points"]);
        $earned = format_task_point_value($display["earned_points"]);
        $is_collab = !empty($display["is_collab"]) && !empty($display["participants_count"]) && $display["participants_count"] > 1;
        $participants_count = $is_collab ? (int) $display["participants_count"] : 1;

        if (!empty($display["is_held"]) && !empty($display["held_label"])) {
            // Phase 17 held (rules on): same projected number Approve will award
            $points_text = "<span class='task-points-held'>" . htmlspecialchars($display["held_label"]) . "</span>";
        } else if (!empty($display["projection"]["queue_label_short"])) {
            // In review / Changes with rules off: lateness-only projection (not Held)
            $points_text = "<span class='task-points-held'>" . htmlspecialchars($display["projection"]["queue_label_short"]) . "</span>";
        } else {
            $points_text = "<span class='task-points-given'>" . $original . "</span>";
            if ($display["is_reduced"]) {
                $points_text .= " <span class='task-points-earned'>→ " . $earned . "</span>";
            }
        }

        if ($compact && $display["is_reduced"] && empty($display["is_held"])) {
            $points_text .= " <span class='task-points-percent'>-" . (int) $display["reduction_percent"] . "%</span>";
        }

        if ($is_collab) {
            $points_text .= " <span class='task-points-collab-tag'>(1 of " . $participants_count . ")</span>";
        }

        if ($is_collab) {
            $total_pts_str = format_task_point_value($display["total_task_points"]);
            $tooltip = sprintf(
                "Task points %s split among %d members. Your share %s, earned %s",
                $total_pts_str,
                $participants_count,
                number_format((float) $display["original_points"], 2, ".", ""),
                number_format((float) $display["earned_points"], 2, ".", "")
            );
            if ($display["is_reduced"] && $display["reduction_reason"]) {
                $tooltip .= ". " . $display["reduction_reason"];
            }
        } else {
            $tooltip = $display["reduction_reason"] ? $display["reduction_reason"] : app_lang("points");
        }

        $title_attr = htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8');
        $html = "<span class='badge clickable mt0 task-points-badge' title='" . $title_attr . "'>" . $points_text . "</span>";

        if ($show_reason && $display["is_reduced"] && $display["reduction_reason"] && !$compact) {
            $reason_class = $display["is_preview"] ? "text-warning" : "text-danger";
            $html .= "<div class='small $reason_class mt5 task-points-reason'><i data-feather='alert-circle' class='icon-14'></i> " . htmlspecialchars($display["reduction_reason"]) . "</div>";
        }

        return $html;
    }

}

if (!function_exists('ensure_task_points_edited_column')) {

    function ensure_task_points_edited_column() {
        static $ensured = false;
        if ($ensured) {
            return true;
        }

        $db = db_connect('default');
        $prefix = $db->getPrefix();
        $table = $prefix . "tasks";
        try {
            $fields = $db->getFieldNames($table);
            if ($fields && !in_array("points_edited", $fields)) {
                $db->query("ALTER TABLE `{$table}` ADD `points_edited` TINYINT(1) NOT NULL DEFAULT 0");
            }
        } catch (\Throwable $ex) {
            log_message('error', '[ERROR] points_edited column {exception}', ['exception' => $ex]);
        }

        $ensured = true;
        return true;
    }

}

if (!function_exists('task_points_already_edited')) {

    function task_points_already_edited($task) {
        ensure_task_points_edited_column();
        if (!$task) {
            return false;
        }
        return !empty($task->points_edited ?? 0);
    }

}

if (!function_exists('task_points_second_edit_blocked')) {

    function task_points_second_edit_blocked($existing_task, $new_points) {
        ensure_task_points_edited_column();
        if (!$existing_task) {
            return false;
        }

        $old = isset($existing_task->points) ? (float) $existing_task->points : 0;
        $new = (float) $new_points;
        if ($old === $new) {
            return false;
        }

        return task_points_already_edited($existing_task);
    }

}

if (!function_exists('is_legacy_in_review_task')) {

    /**
     * Legacy In-review task: status = In review AND submitted_for_review_at IS NULL.
     * When review workflow is on, these stay visible on the Tasks page and out of the
     * review queue/badges; lateness uses completed_at until they re-enter In review.
     */
    function is_legacy_in_review_task($task) {
        if (!$task || !is_object($task)) {
            return false;
        }
        $in_review_status_id = (int) get_in_review_task_status_id();
        if (!$in_review_status_id) {
            return false;
        }
        if ((int) $task->status_id !== $in_review_status_id) {
            return false;
        }
        $submitted = property_exists($task, "submitted_for_review_at") ? $task->submitted_for_review_at : null;
        return ($submitted === null || $submitted === "" || $submitted === "0000-00-00 00:00:00");
    }

}

if (!function_exists('apply_review_stamp_on_status_change')) {

    /**
     * Stamp / clear submitted_for_review_at for a status transition.
     *
     * Rule:
     * - Entering In review (workflow on): set stamp = now
     * - In review -> Done: keep stamp (lateness judged at submission time)
     * - Leaving In review for any other status: clear stamp
     * - Direct Done (from any non-In-review status): clear stamp
     * - Leaving Done (reopen): clear stamp
     * Mutates $data in place.
     */
    function apply_review_stamp_on_status_change($task_info, $new_status_id, &$data, $now = null) {
        if (!$task_info || !isset($task_info->status_id)) {
            return;
        }
        $old_id = (int) $task_info->status_id;
        $new_id = (int) $new_status_id;
        if ($old_id === $new_id) {
            return;
        }

        $in_review_id = function_exists('get_in_review_task_status_id') ? (int) get_in_review_task_status_id() : 0;
        $done_id = function_exists('get_done_task_status_id') ? (int) get_done_task_status_id() : 0;
        if (!$now) {
            $now = get_current_utc_time();
        }

        if ($in_review_id && $new_id === $in_review_id && get_setting("task_review_workflow_enabled")) {
            $data["submitted_for_review_at"] = $now;
            return;
        }

        // In review -> Done: keep existing stamp for lateness
        if ($in_review_id && $done_id && $old_id === $in_review_id && $new_id === $done_id) {
            return;
        }

        // Leaving In review for non-Done, or direct Done / reopen: clear stamp
        if ($in_review_id && $old_id === $in_review_id) {
            $data["submitted_for_review_at"] = null;
            return;
        }
        if ($done_id && $new_id === $done_id) {
            $data["submitted_for_review_at"] = null;
            return;
        }
        if ($done_id && $old_id === $done_id) {
            $data["submitted_for_review_at"] = null;
        }
    }

}

if (!function_exists('count_tasks_in_review')) {

    function count_tasks_in_review($user = null) {
        if (!get_setting("task_review_workflow_enabled")) {
            return 0;
        }
        if (!$user) {
            $ci = new \App\Controllers\App_Controller();
            $user = $ci->login_user;
        }
        if (!$user || empty($user->id)) {
            return 0;
        }

        $in_review_status_id = get_in_review_task_status_id();
        if (!$in_review_status_id) {
            return 0;
        }

        $db = db_connect('default');
        $prefix = $db->getPrefix();
        $tasks_table = "{$prefix}tasks";

        $uid = (int) $user->id;
        $sql = "SELECT COUNT(*) AS total FROM `$tasks_table` 
                WHERE `deleted` = 0 
                  AND `status_id` = $in_review_status_id 
                  AND `submitted_for_review_at` IS NOT NULL 
                  AND `created_by` = $uid";

        $row = $db->query($sql)->getRow();
        return $row ? (int) $row->total : 0;
    }

}

require_once __DIR__ . "/task_points_review_rules_helper.php";
