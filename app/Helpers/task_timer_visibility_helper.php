<?php

/**
 * Phase 18: show open task timers to anyone who can open the task.
 */

if (!function_exists('get_open_timer_elapsed_seconds')) {

    /**
     * Elapsed seconds from a UTC start_time string to current UTC.
     */
    function get_open_timer_elapsed_seconds($utc_start_time) {
        if (!$utc_start_time) {
            return 0;
        }
        try {
            $start = new DateTime($utc_start_time, new DateTimeZone('UTC'));
            $now = new DateTime(get_current_utc_time(), new DateTimeZone('UTC'));
            return max(0, $now->getTimestamp() - $start->getTimestamp());
        } catch (Exception $e) {
            return 0;
        }
    }

}

if (!function_exists('format_open_timer_elapsed')) {

    /**
     * Under 24h: hh:mm:ss. Over 24h: "2d 3h" style.
     */
    function format_open_timer_elapsed($seconds) {
        $seconds = (int) $seconds;
        if ($seconds < 0) {
            $seconds = 0;
        }
        if ($seconds >= 86400) {
            $days = (int) floor($seconds / 86400);
            $hours = (int) floor(($seconds % 86400) / 3600);
            $out = $days . "d";
            if ($hours > 0) {
                $out .= " " . $hours . "h";
            }
            return $out;
        }
        $h = (int) floor($seconds / 3600);
        $m = (int) floor(($seconds % 3600) / 60);
        $s = $seconds % 60;
        return sprintf("%02d:%02d:%02d", $h, $m, $s);
    }

}

if (!function_exists('format_open_timer_started_local')) {

    /**
     * Start time in app timezone as HH:MM (for tooltips / labels).
     */
    function format_open_timer_started_local($utc_start_time) {
        if (!$utc_start_time) {
            return "";
        }
        $local = convert_date_utc_to_local($utc_start_time, "Y-m-d H:i:s");
        $dt = new DateTime($local);
        if (get_setting("time_format") == "24_hours") {
            return $dt->format("H:i");
        }
        return convert_time_to_12hours_format($dt->format("H:i:s"));
    }

}

if (!function_exists('get_task_running_timer_list_indicator_html')) {

    /**
     * Compact red-dot + tooltip for task rows / kanban / in-review.
     * Shows first open timer's person; tooltip lists all.
     */
    function get_task_running_timer_list_indicator_html($timers) {
        if (!$timers || !is_array($timers) || !count($timers)) {
            return "";
        }
        $parts = array();
        foreach ($timers as $timer) {
            $name = isset($timer->user_name) ? trim($timer->user_name) : "";
            if ($name === "") {
                $name = "User";
            }
            $started = format_open_timer_started_local($timer->start_time);
            $parts[] = $name . " is working on this, started " . $started;
        }
        $title = htmlspecialchars(implode(" | ", $parts), ENT_QUOTES, "UTF-8");
        return "<span class='task-running-timer-dot ml5' title='" . $title . "' data-bs-toggle='tooltip' "
            . "style='display:inline-block;width:8px;height:8px;border-radius:50%;background:#dc3545;vertical-align:middle;'></span>";
    }

}

if (!function_exists('index_open_timers_by_task_id')) {

    function index_open_timers_by_task_id($rows) {
        $map = array();
        if (!$rows) {
            return $map;
        }
        foreach ($rows as $row) {
            $tid = (int) $row->task_id;
            if (!isset($map[$tid])) {
                $map[$tid] = array();
            }
            $map[$tid][] = $row;
        }
        return $map;
    }

}
