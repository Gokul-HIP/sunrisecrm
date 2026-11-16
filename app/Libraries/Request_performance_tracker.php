<?php

namespace App\Libraries;

use CodeIgniter\Events\Events;

class Request_performance_tracker {

    public static $start_time = null;
    public static $query_count = 0;
    public static $db_ms = 0.0;
    public static $slow_queries = array();
    public static $has_missed_reminders = false;
    private static $initialized = false;
    private static $finished = false;

    // Okabe-Ito Color-Blind-Safe Palette for Request Categories
    public static $category_colors = array(
        "cron-main" => "#0072B2", // Blue
        "cron-reminders" => "#56B4E9", // Sky Blue
        "chat poll" => "#009E73", // Bluish Green
        "notification poll" => "#F0E442", // Yellow
        "missed-reminders" => "#E69F00", // Orange
        "task list/kanban ajax" => "#D55E00", // Vermilion
        "other ajax" => "#CC79A7", // Purple
        "page load" => "#999999" // Grey
    );

    public static function init() {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
        self::$start_time = microtime(true);

        // Listen for all DB queries
        Events::on('DBQuery', array(__CLASS__, 'record_query'));

        // Register shutdown handler
        register_shutdown_function(array(__CLASS__, 'shutdown'));
    }

    public static function record_query($query) {
        self::$query_count++;
        $duration_sec = 0.0;
        if (is_object($query) && method_exists($query, 'getDuration')) {
            $duration_sec = (float) $query->getDuration();
        }
        $duration_ms = $duration_sec * 1000;
        self::$db_ms += $duration_ms;

        // Capture slow queries (> 300 ms)
        if ($duration_ms >= 300.0) {
            $sql = is_object($query) && method_exists($query, 'getQuery') ? (string) $query->getQuery() : '';
            if ($sql !== '') {
                self::$slow_queries[] = array(
                    "duration_ms" => round($duration_ms, 2),
                    "fingerprint" => self::fingerprint_sql($sql)
                );
            }
        }
    }

    public static function fingerprint_sql($sql) {
        // Strip string literals
        $f = preg_replace("/'[^']*'/", "'?'", $sql);
        // Strip numeric literals
        $f = preg_replace("/\b\d+\b/", "?", $f);
        // Normalize IN lists
        $f = preg_replace("/\?\s*(?:,\s*\?)+/", "?", $f);
        // Collapse whitespace
        $f = preg_replace("/\s+/", " ", trim($f));
        return substr($f, 0, 500);
    }

    public static function classify_request($uri_path, $is_ajax) {
        $uri_path = strtolower(trim($uri_path, '/'));

        if (self::$has_missed_reminders) {
            return "missed-reminders";
        }

        if ($uri_path === 'cron' || strpos($uri_path, 'cron/') === 0) {
            if ($uri_path === 'cron/reminders') {
                return "cron-reminders";
            }
            return "cron-main";
        }

        if (strpos($uri_path, 'messages/count_notifications') !== false || strpos($uri_path, 'messages/users_with_status') !== false) {
            return "chat poll";
        }

        if (strpos($uri_path, 'notifications/count_notifications') !== false) {
            return "notification poll";
        }

        if (
            strpos($uri_path, 'tasks_list_data') !== false ||
            strpos($uri_path, 'all_tasks_kanban_data') !== false ||
            strpos($uri_path, 'tasks/kanban') !== false ||
            strpos($uri_path, 'tasks/task_view') !== false ||
            strpos($uri_path, 'projects/task_view') !== false
        ) {
            return "task list/kanban ajax";
        }

        if ($is_ajax) {
            return "other ajax";
        }

        return "page load";
    }

    public static function shutdown() {
        if (self::$finished || !self::$start_time) {
            return;
        }
        self::$finished = true;

        // Skip non-web CLI commands (unless hitting cron)
        if (is_cli()) {
            return;
        }

        $total_ms = (microtime(true) - self::$start_time) * 1000;
        $uri_path = isset($_SERVER['REQUEST_URI']) ? (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
        // Strip index.php
        $uri_path = preg_replace("#^/index\.php#", "", $uri_path);
        $uri_path = trim($uri_path, '/');

        // Ignore static assets
        if (preg_match('#\.(css|js|png|jpg|jpeg|gif|svg|ico|woff|woff2|ttf)$#i', $uri_path)) {
            return;
        }

        $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        $category = self::classify_request($uri_path, $is_ajax);
        $endpoint = substr($uri_path === '' ? 'home' : $uri_path, 0, 128);

        // Determine user ID
        $user_id = 0;
        if (isset($_SESSION['user_id'])) {
            $user_id = (int) $_SESSION['user_id'];
        }

        // Sampling logic: polling requests sampled at 20% (weight = 5) unless slow (> 300 ms)
        $weight = 1;
        if (($category === 'notification poll' || $category === 'chat poll') && $total_ms < 300.0) {
            if (mt_rand(1, 5) !== 1) {
                return; // Skipped by sampling
            }
            $weight = 5;
        }

        $minute_utc = gmdate('Y-m-d H:i:00');
        $memory_peak = memory_get_peak_usage();

        try {
            $db = db_connect('default');
            $table_load = $db->prefixTable('request_load_minute');
            $sql = "INSERT INTO `$table_load` 
                      (`minute`, `category`, `endpoint`, `requests`, `total_ms`, `max_ms`, `db_ms`, `query_count`, `memory_peak`, `sample_user_ids`)
                    VALUES 
                      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                      `requests` = `requests` + VALUES(`requests`),
                      `total_ms` = `total_ms` + VALUES(`total_ms`),
                      `max_ms` = GREATEST(`max_ms`, VALUES(`max_ms`)),
                      `db_ms` = `db_ms` + VALUES(`db_ms`),
                      `query_count` = `query_count` + VALUES(`query_count`),
                      `memory_peak` = GREATEST(`memory_peak`, VALUES(`memory_peak`)),
                      `sample_user_ids` = IF(`sample_user_ids` = '', VALUES(`sample_user_ids`),
                                          IF(FIND_IN_SET(VALUES(`sample_user_ids`), `sample_user_ids`), `sample_user_ids`,
                                          CONCAT(`sample_user_ids`, ',', VALUES(`sample_user_ids`))))";

            $db->query($sql, array(
                $minute_utc,
                $category,
                $endpoint,
                (int) $weight,
                (float) round($total_ms * $weight, 2),
                (float) round($total_ms, 2),
                (float) round(self::$db_ms * $weight, 2),
                (int) (self::$query_count * $weight),
                (int) $memory_peak,
                $user_id ? (string) $user_id : ""
            ));

            // Log slow queries if any
            if (!empty(self::$slow_queries)) {
                $table_slow = $db->prefixTable('slow_queries');
                $now_utc = gmdate('Y-m-d H:i:s');
                foreach (self::$slow_queries as $sq) {
                    $db->query("INSERT INTO `$table_slow` (`logged_at`, `duration_ms`, `endpoint`, `user_id`, `query_fingerprint`) VALUES (?, ?, ?, ?, ?)", array(
                        $now_utc,
                        $sq['duration_ms'],
                        $endpoint,
                        $user_id,
                        $sq['fingerprint']
                    ));
                }
            }
        } catch (\Throwable $e) {
            // Fail safe
        }
    }

    /**
     * Retrieve stacked area chart data for the Performance Tab.
     */
    public static function get_performance_chart_data($range = '1h') {
        $db = db_connect('default');
        $table_load = $db->prefixTable('request_load_minute');

        $interval_hours = 1;
        if ($range === '6h') {
            $interval_hours = 6;
        } else if ($range === '24h') {
            $interval_hours = 24;
        } else if ($range === '7d') {
            $interval_hours = 168;
        }

        $categories = array_keys(self::$category_colors);
        $datasets = array();
        foreach ($categories as $cat) {
            $datasets[$cat] = array(
                "label" => $cat,
                "borderColor" => self::$category_colors[$cat],
                "backgroundColor" => self::$category_colors[$cat],
                "data" => array(),
                "fill" => true
            );
        }

        $all_minutes = array();
        $minute_cat_data = array();

        try {
            $sql = "SELECT `minute`, `category`, SUM(`requests`) AS total_req, SUM(`total_ms`) AS sum_ms, AVG(`max_ms`) AS avg_max_ms
                    FROM `$table_load`
                    WHERE `minute` >= UTC_TIMESTAMP() - INTERVAL ? HOUR
                    GROUP BY `minute`, `category`
                    ORDER BY `minute` ASC";
            $query = $db->query($sql, array($interval_hours));
            if ($query) {
                foreach ($query->getResult() as $row) {
                    $ist_dt = new \DateTime($row->minute, new \DateTimeZone('UTC'));
                    $ist_dt->setTimezone(new \DateTimeZone('Asia/Kolkata'));
                    $ist_min = $ist_dt->format('Y-m-d H:i');

                    if (!isset($all_minutes[$ist_min])) {
                        $all_minutes[$ist_min] = $ist_min;
                    }
                    $minute_cat_data[$ist_min][$row->category] = (float) round($row->sum_ms, 2);
                }
            }
        } catch (\Throwable $e) {
        }

        $labels = array_values($all_minutes);
        foreach ($categories as $cat) {
            foreach ($labels as $min_label) {
                $val = isset($minute_cat_data[$min_label][$cat]) ? $minute_cat_data[$min_label][$cat] : 0;
                $datasets[$cat]["data"][] = $val;
            }
        }

        // Overlay: Server load / MySQL threads
        $system_metrics = self::get_system_metrics();

        return array(
            "labels" => $labels,
            "datasets" => array_values($datasets),
            "system_metrics" => $system_metrics,
            "range" => $range
        );
    }

    /**
     * Get system metrics (Server load, MySQL threads, request rate, p95, slow query count, cron queue status).
     */
    public static function get_system_metrics() {
        $server_load = null;
        if (function_exists('sys_getloadavg')) {
            $load = @sys_getloadavg();
            if (is_array($load) && isset($load[0])) {
                $server_load = round($load[0], 2);
            }
        }

        $mysql_threads = null;
        $current_req_rate = 0;
        $p95_duration = 0.0;
        $slow_query_count = 0;
        $cron_queue_status = "Ready";

        try {
            $db = db_connect('default');
            $row = $db->query("SHOW STATUS LIKE 'Threads_running'")->getRow();
            if ($row && isset($row->Value)) {
                $mysql_threads = (int) $row->Value;
            }

            // 1. Current minute request rate
            $table_load = $db->prefixTable('request_load_minute');
            $rate_row = $db->query("SELECT SUM(`requests`) AS reqs FROM `$table_load` WHERE `minute` >= UTC_TIMESTAMP() - INTERVAL 2 MINUTE")->getRow();
            if ($rate_row && $rate_row->reqs !== null) {
                $current_req_rate = (int) $rate_row->reqs;
            }

            // 2. P95 Duration over last 1 hour
            $dur_rows = $db->query("SELECT `max_ms` FROM `$table_load` WHERE `minute` >= UTC_TIMESTAMP() - INTERVAL 1 HOUR ORDER BY `max_ms` ASC")->getResult();
            if (!empty($dur_rows)) {
                $c = count($dur_rows);
                $p95_idx = min($c - 1, (int) floor($c * 0.95));
                $p95_duration = round((float) $dur_rows[$p95_idx]->max_ms, 1);
            }

            // 3. Slow queries count last 1 hour
            $table_slow = $db->prefixTable('slow_queries');
            $slow_row = $db->query("SELECT COUNT(*) AS cnt FROM `$table_slow` WHERE `logged_at` >= UTC_TIMESTAMP() - INTERVAL 1 HOUR")->getRow();
            if ($slow_row && isset($slow_row->cnt)) {
                $slow_query_count = (int) $slow_row->cnt;
            }

            // 4. Current cron queue status
            $table_settings = $db->prefixTable('settings');
            $cron_row = $db->query("SELECT `setting_value` FROM `$table_settings` WHERE `setting_name` = 'last_cron_job_time'")->getRow();
            if ($cron_row && $cron_row->setting_value) {
                $last_cron_time = (int) $cron_row->setting_value;
                $diff = abs(time() - $last_cron_time);
                if ($diff < 300) {
                    $cron_queue_status = "Waiting (" . (300 - $diff) . "s)";
                } else {
                    $cron_queue_status = "Ready";
                }
            }
        } catch (\Throwable $e) {
        }

        return array(
            "server_load" => $server_load,
            "mysql_threads" => $mysql_threads,
            "current_req_rate" => $current_req_rate,
            "p95_duration_ms" => $p95_duration,
            "slow_query_count_last_hour" => $slow_query_count,
            "cron_queue_status" => $cron_queue_status,
            "available" => true
        );
    }

    /**
     * Drilldown details for a selected spike minute.
     */
    public static function get_spike_drilldown($minute_ist) {
        $db = db_connect('default');
        $table_load = $db->prefixTable('request_load_minute');
        $table_slow = $db->prefixTable('slow_queries');
        $table_cron = $db->prefixTable('cron_run_log');

        // Convert IST back to UTC
        $ist_dt = \DateTime::createFromFormat('Y-m-d H:i', trim($minute_ist), new \DateTimeZone('Asia/Kolkata'));
        if (!$ist_dt) {
            return array("error" => "Invalid minute format");
        }
        $ist_dt->setTimezone(new \DateTimeZone('UTC'));
        $minute_utc = $ist_dt->format('Y-m-d H:i:00');

        $result = array(
            "minute_ist" => $minute_ist,
            "minute_utc" => $minute_utc,
            "top_endpoints" => array(),
            "top_users" => array(),
            "top_slow_queries" => array(),
            "cron_jobs" => array()
        );

        try {
            // Top endpoints in that minute
            $ep_sql = "SELECT `endpoint`, `category`, SUM(`requests`) AS reqs, SUM(`total_ms`) AS sum_ms, MAX(`max_ms`) AS max_ms, SUM(`query_count`) AS queries
                       FROM `$table_load`
                       WHERE `minute` = ?
                       GROUP BY `endpoint`, `category`
                       ORDER BY sum_ms DESC
                       LIMIT 10";
            $ep_q = $db->query($ep_sql, array($minute_utc));
            if ($ep_q) {
                foreach ($ep_q->getResult() as $r) {
                    $result["top_endpoints"][] = array(
                        "endpoint" => $r->endpoint,
                        "category" => $r->category,
                        "requests" => (int) $r->reqs,
                        "total_ms" => (float) round($r->sum_ms, 2),
                        "max_ms" => (float) round($r->max_ms, 2),
                        "queries" => (int) $r->queries
                    );
                }
            }

            // Top users in that minute
            $u_sql = "SELECT `sample_user_ids` FROM `$table_load` WHERE `minute` = ? AND `sample_user_ids` != ''";
            $u_q = $db->query($u_sql, array($minute_utc));
            $user_counts = array();
            if ($u_q) {
                foreach ($u_q->getResult() as $r) {
                    $ids = explode(',', $r->sample_user_ids);
                    foreach ($ids as $uid) {
                        $uid = trim($uid);
                        if ($uid !== '') {
                            $user_counts[$uid] = isset($user_counts[$uid]) ? $user_counts[$uid] + 1 : 1;
                        }
                    }
                }
            }
            arsort($user_counts);
            foreach (array_slice($user_counts, 0, 10, true) as $uid => $hits) {
                $user_info = $db->query("SELECT first_name, last_name FROM rise_users WHERE id = ?", array($uid))->getRow();
                $name = $user_info ? ($user_info->first_name . ' ' . $user_info->last_name) : "User #$uid";
                $result["top_users"][] = array(
                    "user_id" => (int) $uid,
                    "name" => $name,
                    "samples" => $hits
                );
            }

            // Top slow queries in that minute window (+/- 1 minute)
            $sq_sql = "SELECT `logged_at`, `duration_ms`, `endpoint`, `user_id`, `query_fingerprint`
                       FROM `$table_slow`
                       WHERE `logged_at` >= ? - INTERVAL 1 MINUTE AND `logged_at` <= ? + INTERVAL 1 MINUTE
                       ORDER BY `duration_ms` DESC
                       LIMIT 10";
            $sq_q = $db->query($sq_sql, array($minute_utc, $minute_utc));
            if ($sq_q) {
                foreach ($sq_q->getResult() as $r) {
                    $result["top_slow_queries"][] = array(
                        "duration_ms" => (float) $r->duration_ms,
                        "endpoint" => $r->endpoint,
                        "user_id" => (int) $r->user_id,
                        "fingerprint" => $r->query_fingerprint
                    );
                }
            }

            // Cron jobs that ran in that window
            $cj_sql = "SELECT `job`, `started_at`, `duration_ms`, `status`, `items_processed`, `gate_reason`
                       FROM `$table_cron`
                       WHERE `started_at` >= ? - INTERVAL 1 MINUTE AND `started_at` <= ? + INTERVAL 1 MINUTE
                       ORDER BY `started_at` ASC";
            $cj_q = $db->query($cj_sql, array($minute_utc, $minute_utc));
            if ($cj_q) {
                foreach ($cj_q->getResult() as $r) {
                    $result["cron_jobs"][] = array(
                        "job" => $r->job,
                        "job_label" => isset(Cron_performance_tracker::$job_labels[$r->job]) ? Cron_performance_tracker::$job_labels[$r->job] : $r->job,
                        "started_at" => $r->started_at,
                        "duration_ms" => (float) $r->duration_ms,
                        "status" => $r->status,
                        "items" => (int) $r->items_processed,
                        "gate_reason" => $r->gate_reason
                    );
                }
            }

        } catch (\Throwable $e) {
            $result["error"] = $e->getMessage();
        }

        return $result;
    }
}
