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
            UNIQUE KEY `task_id` (`task_id`),
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
        $status_id = ($status && $status->id) ? $status->id : 3;
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

if (!function_exists('calculate_task_point_result')) {

    function calculate_task_point_result($task, $completed_at = "") {
        $original_points = isset($task->points) ? (float) $task->points : 0;
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

        if ($completed_at) {
            $completed_local = convert_date_utc_to_local($completed_at, "Y-m-d H:i:s");
        } else {
            $completed_local = get_my_local_time("Y-m-d H:i:s");
            $result["is_preview"] = true;
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
        } else if ($hours_late <= 72) {
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

        ensure_task_point_tables();
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

        ensure_task_point_tables();
        $stored = model("App\Models\Task_point_monthly_totals_model")->get_for_user_month($user_id, $year, $month);
        if ($stored && $stored->id) {
            return (float) $stored->total_points;
        }

        return model("App\Models\Task_point_awards_model")->get_monthly_sum($user_id, $year, $month);
    }

}

if (!function_exists('sync_task_point_award')) {

    function sync_task_point_award($task_or_id) {
        ensure_task_point_tables();

        $task = is_object($task_or_id) ? $task_or_id : null;
        $task_id = is_object($task_or_id) ? (isset($task_or_id->id) ? $task_or_id->id : 0) : (int) $task_or_id;
        if (!$task && $task_id) {
            $task = model("App\Models\Tasks_model")->get_details(array("id" => $task_id))->getRow();
        }

        if (!$task || empty($task->id)) {
            return null;
        }

        $awards_model = model("App\Models\Task_point_awards_model");

        if (!is_task_status_done($task)) {
            $revoked = $awards_model->revoke_by_task_id($task->id);
            if ($revoked && $revoked->user_id) {
                refresh_user_monthly_task_points($revoked->user_id, $revoked->year, $revoked->month);
            }
            return null;
        }

        $existing = $awards_model->get_by_task_id($task->id);
        if ($existing) {
            return $existing;
        }

        $completed_at = (!empty($task->status_changed_at) && $task->status_changed_at !== "0000-00-00 00:00:00")
            ? $task->status_changed_at
            : get_current_utc_time();

        $calc = calculate_task_point_result($task, $completed_at);
        $completed_date = convert_date_utc_to_local($completed_at, "Y-m-d");
        $year = (int) date("Y", strtotime($completed_date));
        $month = (int) date("n", strtotime($completed_date));
        $user_id = isset($task->assigned_to) ? (int) $task->assigned_to : 0;

        $data = array(
            "task_id" => $task->id,
            "user_id" => $user_id,
            "original_points" => $calc["original_points"],
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

        $awards_model->upsert_award($data, $task->id);
        if ($user_id) {
            refresh_user_monthly_task_points($user_id, $year, $month);
        }

        return $awards_model->get_by_task_id($task->id);
    }

}

if (!function_exists('get_task_gained_points_value')) {

    function get_task_gained_points_value($task) {
        if (!$task) {
            return 0;
        }

        if (isset($task->awarded_points) && $task->awarded_points !== null && $task->awarded_points !== "") {
            return (float) $task->awarded_points;
        }

        $display = get_task_point_display($task);
        if (!empty($display["is_completed"])) {
            return (float) $display["earned_points"];
        }

        return 0;
    }

}

if (!function_exists('get_task_point_display')) {

    function get_task_point_display($task) {
        $original_points = isset($task->points) ? (float) $task->points : 0;
        $display = array(
            "original_points" => $original_points,
            "earned_points" => $original_points,
            "reduction_percent" => 0,
            "reduction_reason" => "",
            "is_reduced" => false,
            "is_preview" => false,
            "is_completed" => false,
            "completed_date" => ""
        );

        if (!$task) {
            return $display;
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
            return $display;
        }

        if (is_task_status_done($task) && !empty($task->status_changed_at) && $task->status_changed_at !== "0000-00-00 00:00:00") {
            $award = sync_task_point_award($task);
            if ($award) {
                $display["original_points"] = (float) $award->original_points;
                $display["earned_points"] = (float) $award->earned_points;
                $display["reduction_percent"] = (int) $award->reduction_percent;
                $display["reduction_reason"] = $award->reduction_reason;
                $display["is_reduced"] = $display["earned_points"] != $display["original_points"];
                $display["is_completed"] = true;
                $display["completed_date"] = $award->completed_date;
                return $display;
            }

            $calc = calculate_task_point_result($task, $task->status_changed_at);
            $display["earned_points"] = $calc["earned_points"];
            $display["reduction_percent"] = $calc["reduction_percent"];
            $display["reduction_reason"] = $calc["reduction_reason"];
            $display["is_reduced"] = $calc["is_reduced"];
            $display["is_completed"] = true;
            return $display;
        }

        $calc = calculate_task_point_result($task);
        $display["earned_points"] = $calc["earned_points"];
        $display["reduction_percent"] = $calc["reduction_percent"];
        $display["reduction_reason"] = $calc["reduction_reason"];
        $display["is_reduced"] = $calc["is_reduced"];
        $display["is_preview"] = $calc["is_preview"] && $calc["is_reduced"];
        return $display;
    }

}

if (!function_exists('get_task_points_badge_html')) {

    function get_task_points_badge_html($task, $options = array()) {
        $display = get_task_point_display($task);
        $compact = get_array_value($options, "compact");
        $show_reason = get_array_value($options, "show_reason");
        if ($show_reason === null) {
            $show_reason = true;
        }

        $original = format_task_point_value($display["original_points"]);
        $earned = format_task_point_value($display["earned_points"]);
        $reason = $display["reduction_reason"] ? htmlspecialchars($display["reduction_reason"]) : app_lang("points");

        $points_text = "<span class='task-points-given'>" . $original . "</span>";
        if ($display["is_reduced"]) {
            $points_text .= " <span class='task-points-earned'>→ " . $earned . "</span>";
        }

        $html = "<span class='badge clickable mt0 task-points-badge' title='" . $reason . "'>" . $points_text . "</span>";

        if ($show_reason && $display["is_reduced"] && $display["reduction_reason"]) {
            $reason_class = $display["is_preview"] ? "text-warning" : "text-danger";
            if ($compact) {
                $html .= " <span class='small $reason_class task-points-reduction' title='" . $reason . "'>-" . (int) $display["reduction_percent"] . "%</span>";
            } else {
                $html .= "<div class='small $reason_class mt5 task-points-reason'><i data-feather='alert-circle' class='icon-14'></i> " . $reason . "</div>";
            }
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
