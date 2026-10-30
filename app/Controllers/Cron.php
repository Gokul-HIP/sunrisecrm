<?php

namespace App\Controllers;

use App\Libraries\Cron_diagnostic;
use App\Libraries\Cron_job;

class Cron extends App_Controller {

    private $cron_job;
    private $diagnostic;

    function __construct() {
        parent::__construct();
        $this->cron_job = new Cron_job();
        $this->diagnostic = new Cron_diagnostic();
    }

    function index() {
        if ($this->is_scheduler_request()) {
            $this->run_scheduled_cron();
            return;
        }

        return $this->show_dashboard();
    }

    function status() {
        $this->require_dashboard_access(true);
        echo json_encode(array(
            "success" => true,
            "status" => $this->diagnostic->get_status(),
            "health" => $this->diagnostic->get_db_health()
        ));
    }

    function run() {
        $this->require_post();
        $this->require_dashboard_access(true);

        $run_id = $this->diagnostic->new_run_id("manual");
        $started = microtime(true);
        $start_time = get_current_utc_time();

        $this->diagnostic->log($run_id, "cron_run", "info", "Manual diagnostic cron started.", array());

        $last_cron_job_time = get_setting("last_cron_job_time");
        $current_time = strtotime(get_current_utc_time());
        $five_min_open = ($last_cron_job_time == "" || ($current_time > ($last_cron_job_time * 1 + 300)));

        $payload = array(
            "mode" => "manual_diagnostic_cron",
            "label" => "Diagnostic test",
            "start_time" => $start_time,
            "start_time_local" => get_my_local_time(),
            "five_minute_gate_blocked" => !$five_min_open,
            "hourly_gate_blocked" => false,
            "found" => 0,
            "created" => 0,
            "failed" => 0,
            "skipped" => 0,
            "clone_ids" => array(),
            "items" => array(),
            "reason" => "",
            "result" => "SKIPPED"
        );

        if (!$five_min_open) {
            $payload["reason"] = "The 5-minute gate blocked this run. The previous cron ran less than 5 minutes ago.";
            $payload["result"] = "SKIPPED";
            $this->diagnostic->log($run_id, "cron_gate", "warning", $payload["reason"]);
            $this->finish_action_payload($payload, $started, $run_id);
            echo json_encode($payload);
            return;
        }

        try {
            $run_result = $this->cron_job->run(array(
                "diagnostic" => true,
                "run_id" => $run_id,
                "logger" => $this->diagnostic
            ));
            $this->Settings_model->save_setting("last_cron_job_time", $current_time);
            app_hooks()->do_action("app_hook_after_cron_run");

            $hourly = get_array_value($run_result, "hourly");
            $recurring = $hourly && isset($hourly["recurring"]) ? $hourly["recurring"] : array();
            $payload["hourly_gate_blocked"] = empty($hourly["hourly_gate_open"]);
            $payload["found"] = (int) get_array_value($recurring, "found");
            $payload["created"] = (int) get_array_value($recurring, "created");
            $payload["failed"] = (int) get_array_value($recurring, "failed");
            $payload["skipped"] = (int) get_array_value($recurring, "skipped");
            $payload["clone_ids"] = get_array_value($recurring, "clone_ids") ? $recurring["clone_ids"] : array();
            $payload["items"] = get_array_value($recurring, "items") ? $recurring["items"] : array();
            $payload["reason"] = get_array_value($hourly, "skipped_reason");
            if (!$payload["reason"]) {
                $payload["reason"] = get_array_value($recurring, "reason");
            }

            if ($payload["hourly_gate_blocked"]) {
                $payload["result"] = "SKIPPED";
                if (!$payload["reason"]) {
                    $payload["reason"] = "The hourly gate blocked recurring-task processing. The rest of the cron still ran.";
                }
            } else if ($payload["failed"] && !$payload["created"]) {
                $payload["result"] = "ERROR";
            } else {
                $payload["result"] = "SUCCESS";
            }

            $this->Settings_model->save_setting("last_cron_result", strtolower($payload["result"]));
            $this->Settings_model->save_setting("last_cron_clones_created", $payload["created"]);
            $this->Settings_model->save_setting("last_cron_skip_reason", $payload["reason"]);
        } catch (\Throwable $e) {
            $payload["result"] = "ERROR";
            $payload["failed"] = 1;
            $payload["reason"] = "Cron failed: " . $e->getMessage();
            $this->diagnostic->log($run_id, "cron_run", "error", $payload["reason"]);
            $this->Settings_model->save_setting("last_cron_result", "failed");
        }

        $this->finish_action_payload($payload, $started, $run_id);
        echo json_encode($payload);
    }

    function create_test_task() {
        $this->require_post();
        $this->require_dashboard_access(true);

        $run_id = $this->diagnostic->new_run_id("testtask");
        $title = trim((string) $this->request->getPost("title"));
        if ($title === "") {
            $title = "CRON DIAGNOSTIC TEST - " . get_my_local_time();
        }

        $project_id = (int) $this->request->getPost("project_id");
        $assigned_to = (int) $this->request->getPost("assigned_to");
        $raw_start_date = $this->request->getPost("start_date");
        $raw_deadline = $this->request->getPost("deadline");
        $start_date = $this->normalize_posted_date($raw_start_date);
        $deadline = $this->normalize_posted_date($raw_deadline);
        $this->diagnostic->log($run_id, "database", "info", "Create test task dates. start original=" . var_export($raw_start_date, true) . " normalized=" . var_export($start_date, true) . "; deadline original=" . var_export($raw_deadline, true) . " normalized=" . var_export($deadline, true));
        $recurring = $this->request->getPost("recurring") ? 1 : 0;
        $repeat_every = (int) $this->request->getPost("repeat_every");
        $repeat_type = $this->request->getPost("repeat_type");
        $no_of_cycles = (int) $this->request->getPost("no_of_cycles");
        $description = $this->request->getPost("description");

        $allowed_repeat = array("days", "weeks", "months", "years");
        if (!in_array($repeat_type, $allowed_repeat)) {
            $repeat_type = "days";
        }
        if ($repeat_every < 1) {
            $repeat_every = 1;
        }
        if ($no_of_cycles < 0) {
            $no_of_cycles = 0;
        }

        if (!$project_id || !$this->Projects_model->get_one($project_id)->id) {
            echo json_encode(array("success" => false, "message" => "Select a valid project."));
            return;
        }
        if ($assigned_to && !$this->Users_model->get_one($assigned_to)->id) {
            echo json_encode(array("success" => false, "message" => "Select a valid assigned user."));
            return;
        }
        if (!$start_date) {
            $start_date = get_today_date();
        }
        if (!$this->Tasks_model->is_valid_sql_date($start_date)) {
            echo json_encode(array("success" => false, "message" => "Start date is invalid. Use a real calendar date."));
            return;
        }
        if ($deadline && !$this->Tasks_model->is_valid_sql_date($deadline)) {
            echo json_encode(array("success" => false, "message" => "Deadline is invalid. Use a real calendar date."));
            return;
        }
        if ($deadline && $deadline < $start_date) {
            echo json_encode(array("success" => false, "message" => "Deadline must be on or after the start date."));
            return;
        }

        $data = array(
            "title" => $title,
            "description" => $description,
            "project_id" => $project_id,
            "assigned_to" => $assigned_to,
            "start_date" => $start_date,
            "deadline" => $deadline ? $deadline : null,
            "status_id" => 1,
            "points" => 1,
            "recurring" => $recurring,
            "repeat_every" => $recurring ? $repeat_every : 0,
            "repeat_type" => $recurring ? $repeat_type : null,
            "no_of_cycles" => $recurring ? $no_of_cycles : 0,
            "no_of_cycles_completed" => 0,
            "parent_task_id" => 0,
            "recurring_task_id" => 0,
            "collaborators" => "",
            "priority_id" => 0,
            "status" => "to_do",
            "blocking" => "",
            "blocked_by" => "",
            "reminder_date" => get_today_date(),
            "ticket_id" => 0,
            "created_date" => get_today_date(),
            "created_by" => $this->Users_model->login_user_id()
        );
        $data["sort"] = $this->Tasks_model->get_next_sort_value($project_id, 1);
        $data = clean_data($data);

        try {
            $task_id = $this->Tasks_model->ci_save($data);
        } catch (\Throwable $e) {
            $this->diagnostic->log($run_id, "database", "error", "Failed to create test task: " . $e->getMessage());
            echo json_encode(array("success" => false, "message" => "Failed to create the test task: " . $e->getMessage()));
            return;
        }

        if (!$task_id) {
            echo json_encode(array("success" => false, "message" => "Failed to create the test task."));
            return;
        }

        $next_recurring_date = "";
        if ($recurring) {
            $next_recurring_date = $this->normalize_posted_date($this->request->getPost("next_recurring_date"));
            if (!$next_recurring_date) {
                $next_recurring_date = get_today_date();
            }
            if (!$this->Tasks_model->is_valid_sql_date($next_recurring_date)) {
                echo json_encode(array("success" => false, "message" => "Next recurring date is invalid."));
                return;
            }
            $reminder = array("next_recurring_date" => $next_recurring_date);
            $this->Tasks_model->save_reminder_date($reminder, $task_id);
        }

        $task = $this->Tasks_model->get_one($task_id);
        $this->diagnostic->log($run_id, "recurring_tasks", "success", "Created test task #{$task_id}.", array("task_id" => $task_id, "parent_task_id" => 0));

        echo json_encode(array(
            "success" => true,
            "message" => "Test task created.",
            "task" => $this->format_task_for_ui($task),
            "task_url" => get_uri("projects/task_view/" . $task_id)
        ));
    }

    function task_info() {
        $this->require_dashboard_access(true);
        $task_id = (int) $this->request->getGet("task_id");
        if (!$task_id) {
            $task_id = (int) $this->request->getPost("task_id");
        }
        echo json_encode($this->build_task_preview($task_id));
    }

    function test_recurring() {
        $this->require_post();
        $this->require_dashboard_access(true);

        $run_id = $this->diagnostic->new_run_id("rectest");
        $started = microtime(true);
        $start_time = get_current_utc_time();
        $local_debug = $this->diagnostic->is_local_debug();

        $raw_task_id = $this->request->getPost("task_id");
        $task_id = (int) $raw_task_id;
        $this->diagnostic->log($run_id, "recurring_preview", "info", "Received task ID raw=" . var_export($raw_task_id, true) . " parsed={$task_id}", array("task_id" => $task_id, "parent_task_id" => $task_id));

        $preview = $this->build_task_preview($task_id);
        if (empty($preview["success"])) {
            $this->diagnostic->log($run_id, "recurring_preview", "error", $preview["message"], array("task_id" => $task_id));
            $preview["result"] = "FAILED";
            $preview["run_id"] = $run_id;
            $preview["result_card"] = array(
                "result" => "FAILED",
                "title" => "Could not load the parent task",
                "reason" => $preview["message"],
                "parent_task_id" => $task_id
            );
            echo json_encode($preview);
            return;
        }

        $this->diagnostic->log($run_id, "recurring_preview", "info", "Loaded parent #{$task_id}. start={$preview["task"]["start_date"]} deadline={$preview["task"]["deadline"]} next={$preview["task"]["next_recurring_date"]} recurring={$preview["task"]["recurring"]}", array("task_id" => $task_id, "parent_task_id" => $task_id, "parent" => $preview["task"], "preview" => $preview["preview"]));
        $this->diagnostic->log($run_id, "recurring_preview", "info", "Diagnostic test: gates bypassed for this single task. Calculated clone start={$preview["preview"]["clone_start_date"]} deadline={$preview["preview"]["clone_deadline"]} next={$preview["preview"]["next_recurring_date_after"]} parent_task_id={$preview["preview"]["expected_parent_task_id"]} recurring_task_id={$preview["preview"]["expected_recurring_task_id"]}", array("task_id" => $task_id, "parent_task_id" => $task_id));

        $payload = array(
            "success" => true,
            "mode" => "diagnostic_recurring_test",
            "label" => "Diagnostic test",
            "start_time" => $start_time,
            "start_time_local" => get_my_local_time(),
            "hourly_gate_bypassed" => true,
            "five_minute_gate_blocked" => false,
            "preview" => $preview,
            "before" => $preview["task"],
            "found" => 0,
            "created" => 0,
            "failed" => 0,
            "skipped" => 0,
            "clone_ids" => array(),
            "items" => array(),
            "reason" => "",
            "result" => "ERROR",
            "verification" => null,
            "after" => null
        );

        try {
            $this->cron_job->init_runtime(array(
                "diagnostic" => true,
                "run_id" => $run_id,
                "logger" => $this->diagnostic
            ));
            $recurring = $this->cron_job->process_recurring_tasks(array(
                "diagnostic" => true,
                "task_id" => $task_id,
                "run_id" => $run_id,
                "logger" => $this->diagnostic
            ));

            $payload["found"] = (int) get_array_value($recurring, "found");
            $payload["created"] = (int) get_array_value($recurring, "created");
            $payload["failed"] = (int) get_array_value($recurring, "failed");
            $payload["skipped"] = (int) get_array_value($recurring, "skipped");
            $payload["clone_ids"] = get_array_value($recurring, "clone_ids") ? $recurring["clone_ids"] : array();
            $payload["items"] = get_array_value($recurring, "items") ? $recurring["items"] : array();
            $payload["reason"] = get_array_value($recurring, "reason");
            $payload["after"] = $this->format_task_for_ui($this->Tasks_model->get_one($task_id));

            $first_item = !empty($payload["items"][0]) ? $payload["items"][0] : null;
            $payload["verification"] = $first_item && isset($first_item["verification"]) ? $first_item["verification"] : null;
            $payload["clone"] = $this->clone_snapshot_from_item($first_item);

            if ($payload["created"] && $payload["verification"] && $payload["verification"]["result"] === "SUCCESS") {
                $payload["result"] = "SUCCESS";
            } else if ($payload["created"] && $payload["verification"] && in_array($payload["verification"]["result"], array("FAILED", "ERROR"))) {
                $payload["result"] = "FAILED";
            } else if ($payload["created"] && $payload["verification"] && $payload["verification"]["result"] === "WARNING") {
                $payload["result"] = "WARNING";
            } else if ($payload["skipped"] && !$payload["created"] && !$payload["failed"]) {
                $payload["result"] = "SKIPPED";
            } else if ($payload["failed"]) {
                $payload["result"] = "FAILED";
            } else {
                $payload["result"] = "FAILED";
                if (!$payload["reason"]) {
                    $payload["reason"] = "No clone was created.";
                }
            }
        } catch (\Throwable $e) {
            $payload["success"] = true;
            $payload["result"] = "FAILED";
            $payload["failed"] = 1;
            $payload["reason"] = $e->getMessage();
            $payload["error"] = array(
                "message" => $e->getMessage(),
                "parent_task_id" => $task_id,
                "clone_id" => null,
                "parent_updated" => false
            );
            $this->diagnostic->log($run_id, "recurring_tasks", "error", $payload["reason"], array("task_id" => $task_id, "parent_task_id" => $task_id));
        }

        $payload["result_card"] = $this->build_result_card($payload, $task_id, $run_id);
        $payload["local_debug"] = $local_debug;
        $this->finish_action_payload($payload, $started, $run_id);
        $json = json_encode($payload);
        if ($json === false) {
            echo json_encode(array(
                "success" => true,
                "result" => $payload["result"],
                "reason" => $payload["reason"],
                "result_card" => $payload["result_card"],
                "run_id" => $run_id
            ));
            return;
        }
        echo $json;
    }

    function log_detail() {
        $this->require_dashboard_access(true);
        $id = (int) $this->request->getGet("id");
        $log = $this->diagnostic->get_log($id);
        if (!$log) {
            echo json_encode(array("success" => false, "message" => "Log entry was not found."));
            return;
        }
        echo json_encode(array("success" => true, "log" => $log, "local_debug" => $this->diagnostic->is_local_debug()));
    }

    function refresh_task() {
        $this->require_dashboard_access(true);
        $task_id = (int) $this->request->getGet("task_id");
        $preview = $this->build_task_preview($task_id);
        if (empty($preview["success"])) {
            echo json_encode($preview);
            return;
        }
        $clones = $this->Tasks_model->get_all_where(array("recurring_task_id" => $task_id, "deleted" => 0))->getResult();
        $clone_snaps = array();
        foreach ($clones as $clone) {
            $clone_snaps[] = $this->format_task_for_ui($clone);
        }
        echo json_encode(array(
            "success" => true,
            "task" => $preview["task"],
            "clones" => $clone_snaps,
            "preview" => $preview
        ));
    }

    function logs() {
        $this->require_dashboard_access(true);
        $filter = $this->request->getGet("filter");
        echo json_encode(array(
            "success" => true,
            "logs" => $this->diagnostic->get_logs(array("filter" => $filter, "limit" => 200))
        ));
    }

    function clear_diagnostic_logs() {
        $this->require_post();
        $this->require_dashboard_access(true);
        $this->diagnostic->clear_logs();
        echo json_encode(array("success" => true, "message" => "Diagnostic logs cleared."));
    }

    private function run_scheduled_cron() {
        header("Content-Type: text/plain; charset=utf-8");
        ini_set("max_execution_time", 300);

        $last_cron_job_time = get_setting("last_cron_job_time");
        $current_time = strtotime(get_current_utc_time());

        if ($last_cron_job_time == "" || ($current_time > ($last_cron_job_time * 1 + 300))) {
            $run_id = $this->diagnostic->new_run_id("sched");
            try {
                $result = $this->cron_job->run(array(
                    "diagnostic" => false,
                    "run_id" => $run_id,
                    "logger" => $this->diagnostic
                ));
                $this->Settings_model->save_setting("last_cron_job_time", $current_time);
                app_hooks()->do_action("app_hook_after_cron_run");

                $hourly = get_array_value($result, "hourly");
                $recurring = $hourly && isset($hourly["recurring"]) ? $hourly["recurring"] : array();
                $created = (int) get_array_value($recurring, "created");
                $this->Settings_model->save_setting("last_cron_result", "success");
                $this->Settings_model->save_setting("last_cron_clones_created", $created);
                $this->Settings_model->save_setting("last_cron_skip_reason", get_array_value($hourly, "skipped_reason"));
                echo "Cron job executed.";
            } catch (\Throwable $e) {
                $this->Settings_model->save_setting("last_cron_result", "failed");
                $this->diagnostic->log($run_id, "cron_run", "error", "Scheduled cron failed: " . $e->getMessage());
                echo "Cron job failed.";
            }
        } else {
            $this->Settings_model->save_setting("last_cron_result", "skipped");
            $this->Settings_model->save_setting("last_cron_skip_reason", "Cron job skipped (ran less than 5 minutes ago).");
            echo "Cron job skipped (ran less than 5 minutes ago).";
        }
    }

    private function show_dashboard() {
        $login_user = $this->get_login_user();
        if (!$login_user) {
            app_redirect("signin?redirect=" . get_uri("cron"));
            return;
        }
        if (!$this->diagnostic->dashboard_allowed($login_user)) {
            app_redirect("forbidden");
            return;
        }

        $this->diagnostic->ensure_table();

        $projects = array("" => "- Select project -") + $this->Projects_model->get_dropdown_list(array("title"), "id");
        $users = array("" => "- Select user -") + $this->Users_model->get_dropdown_list(array("first_name", "last_name"), "id", array("user_type" => "staff"));

        $view_data = array(
            "status" => $this->diagnostic->get_status(),
            "health" => $this->diagnostic->get_db_health(),
            "logs" => $this->diagnostic->get_logs(array("limit" => 100)),
            "projects_dropdown" => $projects,
            "users_dropdown" => $users,
            "default_title" => "CRON DIAGNOSTIC TEST - " . get_my_local_time(),
            "is_production_host" => $this->diagnostic->is_production_host(),
            "local_debug" => $this->diagnostic->is_local_debug()
        );

        return $this->template->rander("cron/dashboard", $view_data);
    }

    private function build_task_preview($task_id) {
        $task_id = (int) $task_id;
        if (!$task_id) {
            return array("success" => false, "message" => "Enter a valid task ID.");
        }

        $task = $this->Tasks_model->get_one($task_id);
        if (!$task || !$task->id) {
            return array("success" => false, "message" => "Task #{$task_id} was not found.");
        }

        $this->cron_job->init_runtime(array("diagnostic" => true, "logger" => $this->diagnostic));
        $calc = $this->cron_job->calculate_next_clone($task);
        $status = $this->diagnostic->get_status();
        $query_date = $status["recurring_query_date"];
        $eligible = !empty($task->recurring) && empty($task->deleted) && $calc["is_due_now"];

        return array(
            "success" => true,
            "task" => $this->format_task_for_ui($task),
            "task_url" => get_uri("projects/task_view/" . $task->id),
            "preview" => $calc,
            "application_date" => get_today_date(),
            "query_date" => $query_date,
            "eligible" => $eligible,
            "hourly_gate_bypassed" => true,
            "hourly_gate_open" => $status["hourly_gate_open"],
            "recurring_enabled" => $status["recurring_enabled"]
        );
    }

    private function clone_snapshot_from_item($item) {
        if (!$item) {
            return null;
        }
        if (!empty($item["clone"]) && is_array($item["clone"])) {
            return $item["clone"];
        }
        if (!empty($item["clone_task_id"])) {
            return $this->format_task_for_ui($this->Tasks_model->get_one($item["clone_task_id"]));
        }
        return null;
    }

    private function build_result_card($payload, $task_id, $run_id) {
        $clone = isset($payload["clone"]) ? $payload["clone"] : null;
        $after = isset($payload["after"]) ? $payload["after"] : null;
        $title = "FAILED";
        if ($payload["result"] === "SUCCESS") {
            $title = "Clone created successfully";
        } else if ($payload["result"] === "SKIPPED") {
            $title = "Clone was not created";
        }
        return array(
            "result" => $payload["result"],
            "title" => $title,
            "reason" => isset($payload["reason"]) ? $payload["reason"] : "",
            "gates_bypassed" => "Diagnostic test: gates bypassed for this single task",
            "run_id" => $run_id,
            "parent_task_id" => $task_id,
            "clone_task_id" => $clone && !empty($clone["id"]) ? $clone["id"] : (isset($payload["clone_ids"][0]["clone_id"]) ? $payload["clone_ids"][0]["clone_id"] : null),
            "clone_parent_task_id" => $clone && isset($clone["parent_task_id"]) ? $clone["parent_task_id"] : null,
            "clone_recurring_task_id" => $clone && isset($clone["recurring_task_id"]) ? $clone["recurring_task_id"] : null,
            "clone_start_date" => $clone && isset($clone["start_date"]) ? $clone["start_date"] : null,
            "clone_deadline" => $clone && isset($clone["deadline"]) ? $clone["deadline"] : null,
            "clone_next_recurring_date" => $clone && isset($clone["next_recurring_date"]) ? $clone["next_recurring_date"] : null,
            "parent_next_recurring_date_after" => $after && isset($after["next_recurring_date"]) ? $after["next_recurring_date"] : null
        );
    }

    private function format_task_for_ui($task) {
        $snap = $this->diagnostic->task_snapshot($task);
        $snap["task_url"] = $task && $task->id ? get_uri("projects/task_view/" . $task->id) : "";
        return $snap;
    }

    private function finish_action_payload(&$payload, $started, $run_id) {
        $payload["success"] = true;
        $payload["run_id"] = $run_id;
        $payload["end_time"] = get_current_utc_time();
        $payload["end_time_local"] = get_my_local_time();
        $payload["duration_ms"] = (int) round((microtime(true) - $started) * 1000);
        $payload["status"] = $this->diagnostic->get_status();
        $payload["health"] = $this->diagnostic->get_db_health();
        $payload["logs"] = $this->diagnostic->get_logs(array("limit" => 100));
    }

    private function is_scheduler_request() {
        $agent = $this->request->getUserAgent();
        $agent_string = "";
        if (is_object($agent) && method_exists($agent, "getAgentString")) {
            $agent_string = strtolower((string) $agent->getAgentString());
        } else {
            $agent_string = strtolower((string) (isset($_SERVER["HTTP_USER_AGENT"]) ? $_SERVER["HTTP_USER_AGENT"] : $agent));
        }

        if ($agent_string === "" || preg_match("/wget|curl|libwww-perl|python-requests|go-http-client|httpie/", $agent_string)) {
            return true;
        }

        return false;
    }

    private function get_login_user() {
        $login_user_id = $this->Users_model->login_user_id();
        if (!$login_user_id) {
            return null;
        }
        $security = new Security_Controller(false);
        return $security->login_user;
    }

    private function require_dashboard_access($json = false) {
        $login_user = $this->get_login_user();
        if (!$login_user) {
            if ($json) {
                echo json_encode(array("success" => false, "message" => "Please sign in as an administrator."));
                exit();
            }
            app_redirect("signin?redirect=" . get_uri("cron"));
        }
        if (!$this->diagnostic->dashboard_allowed($login_user)) {
            if ($json) {
                echo json_encode(array("success" => false, "message" => "Only administrators can use the cron diagnostic dashboard."));
                exit();
            }
            app_redirect("forbidden");
        }
    }

    private function require_post() {
        if (strtolower((string) $this->request->getMethod()) !== "post") {
            echo json_encode(array("success" => false, "message" => "This action must be submitted with POST."));
            exit();
        }
    }

    private function normalize_posted_date($value) {
        $value = trim((string) $value);
        if ($value === "") {
            return "";
        }

        if (preg_match("/^\d{4}-\d{2}-\d{2}$/", $value)) {
            return $value;
        }

        $formats = array("d-m-Y", "d/m/Y", "m-d-Y", "m/d/Y", "d.m.Y", "Y.m.d");
        foreach ($formats as $format) {
            $parsed = \DateTime::createFromFormat($format, $value);
            if ($parsed && $parsed->format($format) === $value) {
                return $parsed->format("Y-m-d");
            }
        }

        $timestamp = strtotime($value);
        if ($timestamp) {
            return date("Y-m-d", $timestamp);
        }

        return "";
    }
}
