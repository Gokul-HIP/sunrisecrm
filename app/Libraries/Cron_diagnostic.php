<?php

namespace App\Libraries;

use App\Controllers\App_Controller;

class Cron_diagnostic {

    private $ci;
    private $logs_model;
    private $table_ready = false;

    function __construct() {
        $this->ci = new App_Controller();
        $this->ensure_table();
        $this->logs_model = model("App\Models\Cron_diagnostic_logs_model");
    }

    function ensure_table() {
        if ($this->table_ready) {
            return true;
        }

        $db = db_connect('default');
        $table = $db->prefixTable('cron_diagnostic_logs');
        $sql = "CREATE TABLE IF NOT EXISTS `$table` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `run_id` varchar(64) NOT NULL DEFAULT '',
            `event_type` varchar(50) NOT NULL DEFAULT '',
            `level` varchar(20) NOT NULL DEFAULT 'info',
            `task_id` int(11) DEFAULT NULL,
            `parent_task_id` int(11) DEFAULT NULL,
            `clone_task_id` int(11) DEFAULT NULL,
            `message` text,
            `context_json` mediumtext,
            `created_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `run_id` (`run_id`),
            KEY `level` (`level`),
            KEY `event_type` (`event_type`),
            KEY `created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8";

        try {
            $db->query($sql);
            $this->table_ready = true;
            return true;
        } catch (\Throwable $e) {
            $this->table_ready = false;
            return false;
        }
    }

    function new_run_id($prefix = "diag") {
        return $prefix . "_" . date("Ymd_His") . "_" . substr(md5(uniqid("", true)), 0, 8);
    }

    function log($run_id, $event_type, $level, $message, $context = array()) {
        $this->ensure_table();
        if (!$this->table_ready) {
            return false;
        }

        $safe_context = $this->sanitize_context($context);
        $data = array(
            "run_id" => substr((string) $run_id, 0, 64),
            "event_type" => substr((string) $event_type, 0, 50),
            "level" => substr((string) $level, 0, 20),
            "task_id" => isset($context["task_id"]) ? (int) $context["task_id"] : null,
            "parent_task_id" => isset($context["parent_task_id"]) ? (int) $context["parent_task_id"] : null,
            "clone_task_id" => isset($context["clone_task_id"]) ? (int) $context["clone_task_id"] : null,
            "message" => $message,
            "context_json" => json_encode($safe_context),
            "created_at" => get_current_utc_time()
        );

        try {
            return $this->logs_model->ci_save($data);
        } catch (\Throwable $e) {
            return false;
        }
    }

    function get_logs($options = array()) {
        $this->ensure_table();
        if (!$this->table_ready) {
            return array();
        }

        $rows = $this->logs_model->get_logs($options)->getResult();
        $logs = array();
        foreach ($rows as $row) {
            $context = array();
            if ($row->context_json) {
                $decoded = json_decode($row->context_json, true);
                if (is_array($decoded)) {
                    $context = $this->sanitize_context($decoded);
                }
            }
            $logs[] = array(
                "id" => (int) $row->id,
                "run_id" => $row->run_id,
                "timestamp" => $row->created_at,
                "local_time" => $row->created_at ? convert_date_utc_to_local($row->created_at) : "",
                "level" => $row->level,
                "event" => $row->event_type,
                "task_id" => $row->task_id ? (int) $row->task_id : null,
                "parent_task_id" => $row->parent_task_id ? (int) $row->parent_task_id : null,
                "clone_id" => $row->clone_task_id ? (int) $row->clone_task_id : null,
                "message" => $row->message,
                "context" => $context
            );
        }
        return $logs;
    }

    function clear_logs() {
        $this->ensure_table();
        if (!$this->table_ready) {
            return false;
        }
        return $this->logs_model->clear_diagnostic_logs();
    }

    function get_status() {
        $now = strtotime(get_current_utc_time());
        $last_cron = get_setting("last_cron_job_time");
        $last_hourly = get_setting("last_hourly_job_time");
        $seconds_since_cron = ($last_cron !== "" && $last_cron !== null) ? ($now - (int) $last_cron) : null;
        $seconds_since_hourly = ($last_hourly !== "" && $last_hourly !== null) ? ($now - (int) $last_hourly) : null;
        $five_min_open = ($last_cron == "" || $seconds_since_cron > 300);
        $hourly_open = ($last_hourly == "" || $seconds_since_hourly > 3600);
        $recurring_enabled = (bool) get_setting("enable_recurring_option_for_tasks");
        $query_date = $this->get_recurring_query_date();

        $eligible_count = 0;
        try {
            $eligible = $this->ci->Tasks_model->get_renewable_tasks($query_date);
            $eligible_count = $eligible ? count($eligible->getResult()) : 0;
        } catch (\Throwable $e) {
            $eligible_count = 0;
        }

        $last_result = get_setting("last_cron_result");
        if (!$last_result) {
            $last_result = $last_cron ? "success" : "never";
        }

        $endpoint_status = "READY";
        if (!$five_min_open) {
            $endpoint_status = "WAITING";
        } else if ($hourly_open) {
            $endpoint_status = "READY";
        }

        return array(
            "endpoint" => get_uri("cron"),
            "endpoint_status" => $endpoint_status,
            "last_cron_job_time" => $last_cron ? (int) $last_cron : null,
            "last_cron_job_time_local" => $last_cron ? convert_date_utc_to_local(date("Y-m-d H:i:s", (int) $last_cron)) : "Never",
            "last_hourly_job_time" => $last_hourly ? (int) $last_hourly : null,
            "last_hourly_job_time_local" => $last_hourly ? convert_date_utc_to_local(date("Y-m-d H:i:s", (int) $last_hourly)) : "Never",
            "seconds_since_last_cron" => $seconds_since_cron,
            "seconds_since_last_hourly" => $seconds_since_hourly,
            "five_minute_gate_open" => $five_min_open,
            "hourly_gate_open" => $hourly_open,
            "application_timezone" => get_setting("timezone"),
            "php_timezone" => date_default_timezone_get(),
            "utc_time" => get_current_utc_time(),
            "php_time" => date("Y-m-d H:i:s"),
            "application_local_time" => get_my_local_time(),
            "application_date" => get_today_date(),
            "recurring_query_date" => $query_date,
            "create_recurring_tasks_before" => get_setting("create_recurring_tasks_before"),
            "recurring_enabled" => $recurring_enabled,
            "eligible_recurring_tasks" => $eligible_count,
            "clones_created_last_run" => (int) get_setting("last_cron_clones_created"),
            "last_cron_result" => $last_result,
            "last_cron_skip_reason" => get_setting("last_cron_skip_reason")
        );
    }

    function get_recurring_query_date() {
        $date = get_today_date();
        $before = get_setting("create_recurring_tasks_before");
        if ($before) {
            $date = add_period_to_date($date, $before, "days");
        }
        return $date;
    }

    function get_db_health() {
        $db = db_connect('default');
        $settings_table = $db->prefixTable('settings');
        $tasks_table = $db->prefixTable('tasks');
        $logs_table = $db->prefixTable('cron_diagnostic_logs');

        $checks = array();
        $checks[] = $this->setting_exists_check($db, $settings_table, "last_cron_job_time");
        $checks[] = $this->setting_exists_check($db, $settings_table, "last_hourly_job_time");
        $checks[] = array(
            "key" => "enable_recurring_option_for_tasks",
            "label" => "Recurring tasks setting",
            "ok" => (bool) get_setting("enable_recurring_option_for_tasks"),
            "detail" => get_setting("enable_recurring_option_for_tasks") ? "Enabled" : "Disabled in Settings → Tasks"
        );

        $task_fields = array();
        try {
            $task_fields = $db->getFieldNames($tasks_table);
        } catch (\Throwable $e) {
            $task_fields = array();
        }

        foreach (array("parent_task_id", "recurring_task_id", "next_recurring_date", "recurring", "repeat_every", "repeat_type", "no_of_cycles", "no_of_cycles_completed") as $column) {
            $ok = in_array($column, $task_fields);
            $checks[] = array(
                "key" => $column,
                "label" => "$tasks_table.$column",
                "ok" => $ok,
                "detail" => $ok ? "Column exists" : "Column is missing"
            );
        }

        $logs_ok = false;
        try {
            $logs_ok = $db->tableExists($logs_table);
        } catch (\Throwable $e) {
            $logs_ok = false;
        }
        $checks[] = array(
            "key" => "cron_diagnostic_logs",
            "label" => "$logs_table table",
            "ok" => $logs_ok,
            "detail" => $logs_ok ? "Diagnostic log table is ready" : "Run database/add_cron_diagnostic_logs.sql"
        );

        return $checks;
    }

    function task_snapshot($task) {
        if (!$task) {
            return array();
        }
        return array(
            "id" => isset($task->id) ? $task->id : "",
            "title" => isset($task->title) ? $task->title : "",
            "start_date" => isset($task->start_date) ? $task->start_date : "",
            "deadline" => isset($task->deadline) ? $task->deadline : "",
            "recurring" => isset($task->recurring) ? $task->recurring : "",
            "repeat_every" => isset($task->repeat_every) ? $task->repeat_every : "",
            "repeat_type" => isset($task->repeat_type) ? $task->repeat_type : "",
            "no_of_cycles" => isset($task->no_of_cycles) ? $task->no_of_cycles : "",
            "no_of_cycles_completed" => isset($task->no_of_cycles_completed) ? $task->no_of_cycles_completed : "",
            "parent_task_id" => isset($task->parent_task_id) ? $task->parent_task_id : "",
            "recurring_task_id" => isset($task->recurring_task_id) ? $task->recurring_task_id : "",
            "next_recurring_date" => isset($task->next_recurring_date) ? $task->next_recurring_date : "",
            "created_date" => isset($task->created_date) ? $task->created_date : "",
            "deleted" => isset($task->deleted) ? $task->deleted : ""
        );
    }

    function verify_clone($original, $clone, $expected) {
        $rows = array();
        $fields = array("id", "title", "start_date", "deadline", "recurring", "repeat_every", "repeat_type", "no_of_cycles", "no_of_cycles_completed", "parent_task_id", "recurring_task_id", "next_recurring_date", "created_date", "deleted");
        $original_snap = $this->task_snapshot($original);
        $clone_snap = $this->task_snapshot($clone);

        $all_ok = true;
        $parent_missing = false;

        foreach ($fields as $field) {
            $expected_value = isset($expected[$field]) ? $expected[$field] : "";
            $clone_value = isset($clone_snap[$field]) ? $clone_snap[$field] : "";
            $status = "ok";

            if ($field === "id") {
                $status = ($clone_value && (string) $clone_value !== (string) $original_snap["id"]) ? "ok" : "error";
            } else if ($field === "parent_task_id") {
                if ($clone_value === "" || $clone_value === null || (int) $clone_value === 0) {
                    $status = "error";
                    $parent_missing = true;
                } else if ((int) $clone_value !== (int) $original->id) {
                    $status = "error";
                    $parent_missing = true;
                }
            } else if ($field === "recurring_task_id") {
                if ((int) $clone_value !== (int) $original->id) {
                    $status = "error";
                }
            } else if ($field === "deleted") {
                $status = ((int) $clone_value === 0) ? "ok" : "error";
            } else if (in_array($field, array("start_date", "deadline", "next_recurring_date"))) {
                $invalid = ($clone_value === "" || $clone_value === null || $clone_value === "0000-00-00");
                if ($invalid || ($expected_value !== "" && (string) $clone_value !== (string) $expected_value)) {
                    $status = "error";
                    $all_ok = false;
                }
            } else if ($expected_value !== "" && (string) $clone_value !== (string) $expected_value) {
                $status = "error";
            }

            if ($status !== "ok") {
                $all_ok = false;
            }

            $rows[] = array(
                "field" => $field,
                "original" => $original_snap[$field],
                "clone" => $clone_value,
                "expected" => $expected_value,
                "status" => $status
            );
        }

        $result = "SUCCESS";
        $message = "Clone created and linked correctly.";
        if (!$clone || empty($clone->id)) {
            $result = "ERROR";
            $message = "No clone was created.";
        } else if ($parent_missing) {
            $result = "FAILED";
            $message = "Task inserted, but parent relationship is missing.";
        } else if (!$all_ok) {
            $result = "FAILED";
            $message = "Clone was created, but one or more fields did not match the expected result.";
        }

        return array(
            "result" => $result,
            "message" => $message,
            "rows" => $rows,
            "parent_missing" => $parent_missing
        );
    }

    function is_production_host() {
        $host = strtolower((string) (isset($_SERVER["HTTP_HOST"]) ? $_SERVER["HTTP_HOST"] : ""));
        return (strpos($host, "sunrisedigital.co.in") !== false);
    }

    function is_local_debug() {
        if ($this->is_production_host()) {
            return false;
        }
        $host = strtolower((string) (isset($_SERVER["HTTP_HOST"]) ? $_SERVER["HTTP_HOST"] : ""));
        return (ENVIRONMENT !== "production") || (strpos($host, "localhost") !== false) || (strpos($host, "127.0.0.1") !== false);
    }

    function get_log($id) {
        $this->ensure_table();
        $id = (int) $id;
        if ($id < 1) {
            return null;
        }
        $table = db_connect("default")->prefixTable("cron_diagnostic_logs");
        $row = db_connect("default")->query("SELECT * FROM $table WHERE id=$id LIMIT 1")->getRow();
        if (!$row) {
            return null;
        }
        $context = array();
        if ($row->context_json) {
            $decoded = json_decode($row->context_json, true);
            if (is_array($decoded)) {
                $context = $this->sanitize_context($decoded);
            }
        }
        return array(
            "id" => (int) $row->id,
            "run_id" => $row->run_id,
            "timestamp" => $row->created_at,
            "local_time" => $row->created_at ? convert_date_utc_to_local($row->created_at) : "",
            "level" => $row->level,
            "event" => $row->event_type,
            "task_id" => $row->task_id ? (int) $row->task_id : null,
            "parent_task_id" => $row->parent_task_id ? (int) $row->parent_task_id : null,
            "clone_id" => $row->clone_task_id ? (int) $row->clone_task_id : null,
            "message" => $row->message,
            "context" => $this->is_local_debug() ? $context : array()
        );
    }

    function dashboard_allowed($login_user) {
        if (!$login_user || empty($login_user->id)) {
            return false;
        }
        if (!empty($login_user->is_admin)) {
            return true;
        }
        $permissions = isset($login_user->permissions) ? $login_user->permissions : array();
        return (bool) get_array_value($permissions, "can_manage_all_kinds_of_settings");
    }

    private function setting_exists_check($db, $settings_table, $name) {
        $ok = false;
        $detail = "Setting row is missing";
        try {
            $name_esc = $db->escape($name);
            $row = $db->query("SELECT setting_name, setting_value FROM $settings_table WHERE setting_name=$name_esc LIMIT 1")->getRow();
            if ($row) {
                $ok = true;
                $detail = $row->setting_value === "" || $row->setting_value === null ? "Row exists (empty value)" : "Row exists";
            }
        } catch (\Throwable $e) {
            $detail = "Could not read settings table";
        }
        return array(
            "key" => $name,
            "label" => "$settings_table.$name",
            "ok" => $ok,
            "detail" => $detail
        );
    }

    private function sanitize_context($context) {
        if (!is_array($context)) {
            return array();
        }
        $blocked = array("password", "passwd", "pwd", "secret", "token", "csrf", "session", "cookie", "authorization", "db_password", "database_password", "username");
        $clean = array();
        foreach ($context as $key => $value) {
            $key_l = strtolower((string) $key);
            foreach ($blocked as $bad) {
                if (strpos($key_l, $bad) !== false) {
                    continue 2;
                }
            }
            if (is_array($value)) {
                $clean[$key] = $this->sanitize_context($value);
            } else if (is_object($value)) {
                $clean[$key] = $this->sanitize_context((array) $value);
            } else {
                $clean[$key] = $value;
            }
        }
        return $clean;
    }
}
