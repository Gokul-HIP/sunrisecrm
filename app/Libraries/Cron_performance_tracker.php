<?php

namespace App\Libraries;

class Cron_performance_tracker {

    public static $job_colors = array(
        "main_cron" => "#0072B2", // Deep Blue
        "pre_gate_2h_reminders" => "#56B4E9", // Sky Blue
        "reminders_endpoint" => "#009E73", // Bluish Green
        "create_recurring_tasks" => "#E69F00", // Orange
        "create_recurring_invoices" => "#D55E00", // Vermilion
        "create_recurring_expenses" => "#CC79A7", // Purple
        "send_invoice_due_pre_reminder" => "#F0E442", // Yellow
        "send_invoice_due_after_reminder" => "#882255", // Wine
        "send_recurring_invoice_creation_reminder" => "#44AA99", // Teal
        "send_task_reminder_notifications" => "#117733", // Green
        "create_recurring_reminders" => "#999933", // Olive
        "create_subscription_invoices" => "#88CCEE", // Light Cyan
        "run_imap" => "#AA4499", // Magenta
        "get_google_calendar_events" => "#332288", // Indigo
        "close_inactive_tickets" => "#661100", // Dark Red
        "hourly_cleanup" => "#666666", // Grey
        "content_calendar_special_days" => "#E05D5D" // Coral Red
    );

    public static $job_labels = array(
        "main_cron" => "Main Cron Runner",
        "pre_gate_2h_reminders" => "2-Hour Deadline Reminders",
        "reminders_endpoint" => "Reminders Endpoint (/cron/reminders)",
        "create_recurring_tasks" => "Recurring Tasks",
        "create_recurring_invoices" => "Recurring Invoices",
        "create_recurring_expenses" => "Recurring Expenses",
        "send_invoice_due_pre_reminder" => "Invoice Pre-Reminders",
        "send_invoice_due_after_reminder" => "Invoice Overdue Reminders",
        "send_recurring_invoice_creation_reminder" => "Recurring Invoice Reminders",
        "send_task_reminder_notifications" => "Task Due Reminders",
        "create_recurring_reminders" => "Recurring Calendar Events",
        "create_subscription_invoices" => "Subscription Invoices",
        "run_imap" => "IMAP Email Piping",
        "get_google_calendar_events" => "Google Calendar Sync",
        "close_inactive_tickets" => "Auto-Close Inactive Tickets",
        "hourly_cleanup" => "Log Retention Cleanup",
        "content_calendar_special_days" => "Content Calendar Special Days"
    );

    public static $job_schedules = array(
        "main_cron" => array("schedule" => "Every 1 min", "interval_sec" => 60),
        "pre_gate_2h_reminders" => array("schedule" => "Every 1 min", "interval_sec" => 60),
        "reminders_endpoint" => array("schedule" => "Every 1 min", "interval_sec" => 60),
        "create_recurring_tasks" => array("schedule" => "Hourly", "interval_sec" => 3600),
        "create_recurring_invoices" => array("schedule" => "Hourly", "interval_sec" => 3600),
        "create_recurring_expenses" => array("schedule" => "Hourly", "interval_sec" => 3600),
        "send_invoice_due_pre_reminder" => array("schedule" => "Hourly", "interval_sec" => 3600),
        "send_invoice_due_after_reminder" => array("schedule" => "Hourly", "interval_sec" => 3600),
        "send_recurring_invoice_creation_reminder" => array("schedule" => "Hourly", "interval_sec" => 3600),
        "send_task_reminder_notifications" => array("schedule" => "Hourly", "interval_sec" => 3600),
        "create_recurring_reminders" => array("schedule" => "Hourly", "interval_sec" => 3600),
        "create_subscription_invoices" => array("schedule" => "Hourly", "interval_sec" => 3600),
        "run_imap" => array("schedule" => "Every 10 min", "interval_sec" => 600),
        "get_google_calendar_events" => array("schedule" => "Every 5 min", "interval_sec" => 300),
        "close_inactive_tickets" => array("schedule" => "Daily", "interval_sec" => 86400),
        "hourly_cleanup" => array("schedule" => "Hourly", "interval_sec" => 3600),
        "content_calendar_special_days" => array("schedule" => "Daily 07:00", "interval_sec" => 86400)
    );

    /**
     * Wrap execution of a cron job in a timing and diagnostic helper.
     *
     * @param string $job Job identifier
     * @param string $trigger_source Source: 'scheduler', 'manual', 'reminders_endpoint', 'poll_fallback'
     * @param callable $callback Job callback
     * @param callable|null $gate_checker Optional gate check closure returning ['open' => bool, 'reason' => string]
     * @return mixed
     */
    public static function track_job($job, $trigger_source, callable $callback, $gate_checker = null) {
        $started_utc = gmdate('Y-m-d H:i:s');
        $start_time = microtime(true);
        $mem_start = memory_get_peak_usage();

        if ($gate_checker !== null) {
            $gate_result = $gate_checker();
            if (is_array($gate_result) && isset($gate_result['open']) && !$gate_result['open']) {
                $duration_ms = round((microtime(true) - $start_time) * 1000, 2);
                $reason = isset($gate_result['reason']) ? $gate_result['reason'] : "Gate blocked execution";
                self::log_run($job, $trigger_source, $started_utc, $duration_ms, 'skipped_by_gate', $reason, 0, memory_get_peak_usage());
                return isset($gate_result['fallback']) ? $gate_result['fallback'] : null;
            }
        }

        $items = 0;
        $status = 'ok';
        $gate_reason = null;
        $error_msg = null;
        $result = null;

        try {
            $result = $callback();
            if (is_int($result)) {
                $items = $result;
            } else if (is_array($result)) {
                $items = isset($result['created']) ? (int) $result['created'] : (isset($result['found']) ? (int) $result['found'] : 0);
                if (isset($result['status'])) {
                    $status = strtolower($result['status']) === 'skipped' ? 'skipped_by_gate' : (strtolower($result['status']) === 'error' ? 'failed' : strtolower($result['status']));
                }
                if (isset($result['reason'])) {
                    $gate_reason = $result['reason'];
                }
            }
        } catch (\Throwable $e) {
            $status = 'failed';
            $error_msg = $e->getMessage();
        }

        $duration_ms = round((microtime(true) - $start_time) * 1000, 2);
        self::log_run($job, $trigger_source, $started_utc, $duration_ms, $status, $gate_reason, $items, memory_get_peak_usage(), $error_msg);

        if ($status === 'failed' && isset($e)) {
            throw $e;
        }

        return $result;
    }

    /**
     * Compactly log a cron execution into rise_cron_run_log.
     */
    public static function log_run($job, $trigger_source, $started_at, $duration_ms, $status, $gate_reason = null, $items_processed = 0, $memory_peak = 0, $error_message = null) {
        try {
            $db = db_connect('default');
            $table = $db->prefixTable('cron_run_log');
            $sql = "INSERT INTO `$table` 
                    (`job`, `trigger_source`, `started_at`, `duration_ms`, `status`, `gate_reason`, `items_processed`, `memory_peak`, `error_message`) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $db->query($sql, array(
                substr((string) $job, 0, 64),
                substr((string) $trigger_source, 0, 32),
                $started_at,
                (float) $duration_ms,
                $status,
                $gate_reason ? substr((string) $gate_reason, 0, 255) : null,
                (int) $items_processed,
                (int) $memory_peak,
                $error_message
            ));
        } catch (\Throwable $e) {
            // Fail safe: never break cron execution if logging encounters a DB issue
        }
    }

    /**
     * Retrieve status summary for all known cron jobs from rise_cron_run_log.
     */
    public static function get_jobs_status() {
        $db = db_connect('default');
        $table = $db->prefixTable('cron_run_log');

        $status_map = array();
        foreach (self::$job_labels as $job_key => $label) {
            $sched_info = isset(self::$job_schedules[$job_key]) ? self::$job_schedules[$job_key] : array("schedule" => "Unknown", "interval_sec" => 3600);
            $initial_next = "Pending initial run";
            if ($job_key === 'content_calendar_special_days') {
                $now_kol = new \DateTime('now', new \DateTimeZone('Asia/Kolkata'));
                $target_today = new \DateTime('today 07:00:00', new \DateTimeZone('Asia/Kolkata'));
                if ($now_kol < $target_today) {
                    $initial_next = $target_today->format('Y-m-d H:i:s') . ' IST';
                } else {
                    $tomorrow = clone $target_today;
                    $tomorrow->modify('+1 day');
                    $initial_next = $tomorrow->format('Y-m-d H:i:s') . ' IST';
                }
            }
            $status_map[$job_key] = array(
                "job" => $job_key,
                "label" => $label,
                "schedule" => $sched_info["schedule"],
                "color" => self::$job_colors[$job_key],
                "last_run_utc" => null,
                "last_run_ist" => "Never",
                "next_expected_run_ist" => $initial_next,
                "time_ago" => "Never",
                "duration_ms" => 0,
                "status" => "never_run",
                "gate_reason" => null,
                "items_processed" => 0,
                "memory_peak_mb" => 0,
                "last_error" => null
            );
        }

        try {
            $sql = "SELECT t1.*
                    FROM `$table` t1
                    JOIN (
                        SELECT `job`, MAX(`id`) AS max_id
                        FROM `$table`
                        GROUP BY `job`
                    ) t2 ON t1.id = t2.max_id";
            $query = $db->query($sql);
            if ($query) {
                $rows = $query->getResult();
                $now_utc = time();
                foreach ($rows as $row) {
                    $job = $row->job;
                    $sched_info = isset(self::$job_schedules[$job]) ? self::$job_schedules[$job] : array("schedule" => "Unknown", "interval_sec" => 3600);
                    if (!isset($status_map[$job])) {
                        $status_map[$job] = array(
                            "job" => $job,
                            "label" => ucwords(str_replace('_', ' ', $job)),
                            "schedule" => $sched_info["schedule"],
                            "color" => "#666666",
                            "last_run_utc" => null,
                            "last_run_ist" => "Never",
                            "next_expected_run_ist" => "Pending initial run",
                            "time_ago" => "Never",
                            "duration_ms" => 0,
                            "status" => "never_run",
                            "gate_reason" => null,
                            "items_processed" => 0,
                            "memory_peak_mb" => 0,
                            "last_error" => null
                        );
                    }

                    $epoch = strtotime($row->started_at . ' UTC');
                    $diff_seconds = max(0, $now_utc - $epoch);
                    $time_ago = self::format_time_ago($diff_seconds);

                    // Convert UTC to IST (+5:30)
                    $ist_dt = new \DateTime($row->started_at, new \DateTimeZone('UTC'));
                    $ist_dt->setTimezone(new \DateTimeZone('Asia/Kolkata'));
                    $last_run_ist = $ist_dt->format('Y-m-d H:i:s') . ' IST';

                    // Next expected run calculation
                    if ($job === 'content_calendar_special_days') {
                        $now_kol = new \DateTime('now', new \DateTimeZone('Asia/Kolkata'));
                        $target_today = new \DateTime('today 07:00:00', new \DateTimeZone('Asia/Kolkata'));
                        if ($now_kol < $target_today) {
                            $next_expected_run_ist = $target_today->format('Y-m-d H:i:s') . ' IST';
                        } else {
                            $tomorrow = clone $target_today;
                            $tomorrow->modify('+1 day');
                            $next_expected_run_ist = $tomorrow->format('Y-m-d H:i:s') . ' IST';
                        }
                    } else {
                        $next_epoch = $epoch + $sched_info["interval_sec"];
                        if ($next_epoch <= $now_utc) {
                            $next_expected_run_ist = "Due now";
                        } else {
                            $next_dt = new \DateTime('@' . $next_epoch);
                            $next_dt->setTimezone(new \DateTimeZone('Asia/Kolkata'));
                            $next_expected_run_ist = $next_dt->format('H:i:s') . ' IST';
                        }
                    }

                    $status_map[$job]["last_run_utc"] = $row->started_at;
                    $status_map[$job]["last_run_ist"] = $last_run_ist;
                    $status_map[$job]["next_expected_run_ist"] = $next_expected_run_ist;
                    $status_map[$job]["time_ago"] = $time_ago;
                    $status_map[$job]["duration_ms"] = (float) $row->duration_ms;
                    $status_map[$job]["status"] = $row->status;
                    $status_map[$job]["gate_reason"] = $row->gate_reason;
                    $status_map[$job]["items_processed"] = (int) $row->items_processed;
                    $status_map[$job]["memory_peak_mb"] = round($row->memory_peak / (1024 * 1024), 2);
                    $status_map[$job]["last_error"] = $row->error_message;
                }
            }
        } catch (\Throwable $e) {
            // Return defaults on error
        }

        return array_values($status_map);
    }

    /**
     * Get runs over time time-series chart data for Chart.js.
     */
    public static function get_runs_chart_data($hours = 24) {
        $db = db_connect('default');
        $table = $db->prefixTable('cron_run_log');
        $hours = max(1, min(168, (int) $hours));

        $datasets = array();
        foreach (self::$job_labels as $job_key => $label) {
            $datasets[$job_key] = array(
                "label" => $label,
                "job" => $job_key,
                "borderColor" => self::$job_colors[$job_key],
                "backgroundColor" => self::$job_colors[$job_key],
                "fill" => false,
                "pointRadius" => 3,
                "data" => array()
            );
        }

        try {
            $sql = "SELECT `job`, `started_at`, `duration_ms`, `status`, `items_processed` 
                    FROM `$table` 
                    WHERE `started_at` >= UTC_TIMESTAMP() - INTERVAL ? HOUR 
                    ORDER BY `started_at` ASC";
            $query = $db->query($sql, array($hours));
            if ($query) {
                $rows = $query->getResult();
                foreach ($rows as $row) {
                    $job = $row->job;
                    if (!isset($datasets[$job])) {
                        $datasets[$job] = array(
                            "label" => ucwords(str_replace('_', ' ', $job)),
                            "job" => $job,
                            "borderColor" => "#888888",
                            "backgroundColor" => "#888888",
                            "fill" => false,
                            "pointRadius" => 3,
                            "data" => array()
                        );
                    }

                    $ist_dt = new \DateTime($row->started_at, new \DateTimeZone('UTC'));
                    $ist_dt->setTimezone(new \DateTimeZone('Asia/Kolkata'));
                    $ist_time = $ist_dt->format('Y-m-d H:i:s');

                    $datasets[$job]["data"][] = array(
                        "x" => $ist_time,
                        "y" => (float) $row->duration_ms,
                        "status" => $row->status,
                        "items" => (int) $row->items_processed
                    );
                }
            }
        } catch (\Throwable $e) {
        }

        return array_values($datasets);
    }

    /**
     * Retrieve paginated and filtered log rows.
     */
    public static function get_runs_logs($options = array()) {
        $db = db_connect('default');
        $table = $db->prefixTable('cron_run_log');

        $job = isset($options['job']) ? trim($options['job']) : '';
        $status = isset($options['status']) ? trim($options['status']) : '';
        $limit = isset($options['limit']) ? max(1, min(200, (int) $options['limit'])) : 50;

        $where = array();
        $params = array();

        if ($job !== '') {
            $where[] = "`job` = ?";
            $params[] = $job;
        }
        if ($status !== '') {
            $where[] = "`status` = ?";
            $params[] = $status;
        }

        $where_sql = count($where) ? "WHERE " . implode(" AND ", $where) : "";
        $sql = "SELECT * FROM `$table` $where_sql ORDER BY `id` DESC LIMIT $limit";

        $logs = array();
        try {
            $query = $db->query($sql, $params);
            if ($query) {
                foreach ($query->getResult() as $row) {
                    $ist_dt = new \DateTime($row->started_at, new \DateTimeZone('UTC'));
                    $ist_dt->setTimezone(new \DateTimeZone('Asia/Kolkata'));
                    $started_ist = $ist_dt->format('Y-m-d H:i:s');

                    $logs[] = array(
                        "id" => (int) $row->id,
                        "job" => $row->job,
                        "job_label" => isset(self::$job_labels[$row->job]) ? self::$job_labels[$row->job] : $row->job,
                        "color" => isset(self::$job_colors[$row->job]) ? self::$job_colors[$row->job] : "#666666",
                        "trigger_source" => $row->trigger_source,
                        "started_at_utc" => $row->started_at,
                        "started_at_ist" => $started_ist,
                        "duration_ms" => (float) $row->duration_ms,
                        "status" => $row->status,
                        "gate_reason" => $row->gate_reason,
                        "items_processed" => (int) $row->items_processed,
                        "memory_peak_mb" => round($row->memory_peak / (1024 * 1024), 2),
                        "error_message" => $row->error_message
                    );
                }
            }
        } catch (\Throwable $e) {
        }

        return $logs;
    }

    private static function format_time_ago($seconds) {
        if ($seconds < 60) {
            return "{$seconds}s ago";
        }
        $minutes = (int) round($seconds / 60);
        if ($minutes < 60) {
            return "{$minutes} min ago";
        }
        $hours = (int) floor($minutes / 60);
        $rem_min = $minutes % 60;
        if ($hours < 24) {
            return "{$hours}h {$rem_min}m ago";
        }
        $days = (int) floor($hours / 24);
        return "{$days}d ago";
    }
}
