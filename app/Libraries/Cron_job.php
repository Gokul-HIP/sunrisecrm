<?php

namespace App\Libraries;

use App\Controllers\App_Controller;
use App\Libraries\Google_calendar_events;
use App\Libraries\Imap;
use App\Libraries\Outlook_imap;

class Cron_job {

    private $today = null;
    private $current_time = null;
    private $ci = null;
    private $diagnostic = false;
    private $run_id = "";
    private $logger = null;

    private function cron_debug($message, $event_type = "cron_run", $level = "info", $context = array()) {
        if ($this->logger) {
            $this->logger->log($this->run_id, $event_type, $level, $message, $context);
        }
    }

    /**
     * Phase 15 tracker is optional. Phase 16 cron must run cleanly without
     * Cron_performance_tracker.php / Phase 15 tables present.
     */
    private function track_cron_job($job, $trigger_source, $callback, $gate_checker = null) {
        if (class_exists('\App\Libraries\Cron_performance_tracker')) {
            return \App\Libraries\Cron_performance_tracker::track_job($job, $trigger_source, $callback, $gate_checker);
        }
        if ($gate_checker !== null) {
            $gate_result = $gate_checker();
            if (is_array($gate_result) && isset($gate_result['open']) && !$gate_result['open']) {
                return null;
            }
        }
        return $callback();
    }

    private function log_cron_run($job, $trigger_source, $started_at, $duration_ms, $status, $skip_reason = null, $created = null, $memory = null, $error = null) {
        if (class_exists('\App\Libraries\Cron_performance_tracker')) {
            \App\Libraries\Cron_performance_tracker::log_run($job, $trigger_source, $started_at, $duration_ms, $status, $skip_reason, $created, $memory, $error);
        }
    }

    function init_runtime($options = array()) {
        $this->today = get_today_date();
        $this->ci = $this->ci ? $this->ci : new App_Controller();
        $this->current_time = strtotime(get_current_utc_time());
        $this->diagnostic = !empty($options["diagnostic"]);
        $this->run_id = get_array_value($options, "run_id") ? $options["run_id"] : uniqid("cron_", true);
        $this->logger = get_array_value($options, "logger");
    }

    function get_today() {
        if (!$this->today) {
            $this->today = get_today_date();
        }
        return $this->today;
    }

    function get_recurring_query_date() {
        $date = $this->get_today();
        $create_recurring_tasks_before = get_setting("create_recurring_tasks_before");
        if ($create_recurring_tasks_before) {
            $date = add_period_to_date($date, $create_recurring_tasks_before, "days");
        }
        return $date;
    }

    private function normalize_sql_date($date) {
        if ($this->ci && $this->ci->Tasks_model && method_exists($this->ci->Tasks_model, "is_valid_sql_date")) {
            if ($this->ci->Tasks_model->is_valid_sql_date($date)) {
                return substr((string) $date, 0, 10);
            }
        }
        $date = substr(trim((string) $date), 0, 10);
        if ($date === "" || $date === "0000-00-00") {
            return "";
        }
        $parsed = \DateTime::createFromFormat("Y-m-d", $date);
        return ($parsed && $parsed->format("Y-m-d") === $date) ? $date : "";
    }

    function run($options = array()) {
        $this->init_runtime($options);

        $this->cron_debug("Scheduled cron run started. today={$this->today} utc=" . get_current_utc_time(), "cron_run", "info");
        $trigger_source = $this->diagnostic ? "manual" : "scheduler";

        // 2-hour task deadline reminders are checked pre-gate in Cron controller every minute
        $hourly = $this->call_hourly_jobs($options);

        try {
            $this->track_cron_job("run_imap", $trigger_source, function() {
                $this->run_imap();
            });
        } catch (\Exception $e) {
            $this->cron_debug("IMAP job failed: " . $e->getMessage(), "cron_run", "error");
        }

        try {
            $this->track_cron_job("get_google_calendar_events", $trigger_source, function() {
                $this->get_google_calendar_events();
            });
        } catch (\Exception $e) {
            $this->cron_debug("Google calendar job failed: " . $e->getMessage(), "cron_run", "error");
        }

        try {
            $this->track_cron_job("close_inactive_tickets", $trigger_source, function() {
                $this->close_inactive_tickets();
            });
        } catch (\Exception $e) {
            $this->cron_debug("Close inactive tickets failed: " . $e->getMessage(), "cron_run", "error");
        }

        try {
            $this->track_cron_job("content_calendar_special_days", $trigger_source, function() {
                return $this->process_content_calendar_special_days();
            }, function() {
                return $this->check_content_calendar_special_days_gate();
            });
        } catch (\Throwable $e) {
            $this->cron_debug("Content calendar special days job failed: " . $e->getMessage(), "cron_run", "error");
        }

        return array(
            "mode" => $this->diagnostic ? "diagnostic_cron" : "scheduled_cron",
            "hourly" => $hourly
        );
    }

    private function call_hourly_jobs($options = array()) {
        //wait 1 hour for each call of following actions
        $hourly_ok = $this->_is_hourly_job_runnable();
        $last_hourly_job_time = get_setting('last_hourly_job_time');
        $wait_needed = 3600;
        $elapsed = $last_hourly_job_time ? ($this->current_time - (int) $last_hourly_job_time) : "never";
        $trigger_source = $this->diagnostic ? "manual" : "scheduler";
        $result = array(
            "hourly_gate_open" => (bool) $hourly_ok,
            "last_hourly_job_time" => $last_hourly_job_time,
            "elapsed" => $elapsed,
            "wait_needed" => $wait_needed,
            "recurring" => null,
            "skipped_reason" => ""
        );

        $this->cron_debug("Hourly gate " . ($hourly_ok ? "OPEN" : "CLOSED") . ". elapsed={$elapsed} need>{$wait_needed}", "hourly_gate", $hourly_ok ? "info" : "warning");

        if ($hourly_ok) {


            try {
                $this->track_cron_job("create_recurring_invoices", $trigger_source, function() {
                    $this->create_recurring_invoices();
                });
            } catch (\Exception $e) {
                echo $e;
            }

            try {
                $this->track_cron_job("create_recurring_expenses", $trigger_source, function() {
                    $this->create_recurring_expenses();
                });
            } catch (\Exception $e) {
                echo $e;
            }

            try {
                $this->track_cron_job("send_invoice_due_pre_reminder", $trigger_source, function() {
                    $this->send_invoice_due_pre_reminder();
                });
            } catch (\Exception $e) {
                echo $e;
            }


            try {
                $this->track_cron_job("send_invoice_due_after_reminder", $trigger_source, function() {
                    $this->send_invoice_due_after_reminder();
                });
            } catch (\Exception $e) {
                echo $e;
            }


            try {
                $this->track_cron_job("send_recurring_invoice_creation_reminder", $trigger_source, function() {
                    $this->send_recurring_invoice_creation_reminder();
                });
            } catch (\Exception $e) {
                echo $e;
            }


            try {
                $result["recurring"] = $this->track_cron_job("create_recurring_tasks", $trigger_source, function() use ($options) {
                    return $this->create_recurring_tasks($options);
                });
            } catch (\Exception $e) {
                $this->cron_debug("Recurring tasks failed: " . $e->getMessage(), "recurring_tasks", "error");
                $result["recurring"] = array(
                    "error" => $e->getMessage(),
                    "created" => 0,
                    "failed" => 1
                );
            }

            try {
                $this->track_cron_job("send_task_reminder_notifications", $trigger_source, function() {
                    $this->send_task_reminder_notifications();
                });
            } catch (\Exception $e) {
                echo $e;
            }

            try {
                $this->track_cron_job("create_recurring_reminders", $trigger_source, function() {
                    $this->create_recurring_reminders();
                });
            } catch (\Exception $e) {
                echo $e;
            }

            try {
                $this->track_cron_job("create_subscription_invoices", $trigger_source, function() {
                    $this->create_subscription_invoices();
                });
            } catch (\Exception $e) {
                echo $e;
            }

            try {
                $this->track_cron_job("hourly_cleanup", $trigger_source, function() {
                    return $this->clean_performance_and_cron_logs();
                });
            } catch (\Exception $e) {
                $this->cron_debug("Hourly retention cleanup failed: " . $e->getMessage(), "hourly_gate", "warning");
            }

            $this->ci->Settings_model->save_setting("last_hourly_job_time", $this->current_time);
            $this->cron_debug("Hourly jobs finished; saved last_hourly_job_time={$this->current_time}", "hourly_gate", "success");
        } else {
            $result["skipped_reason"] = "Hourly gate is closed. Recurring-task creation waits until one hour has passed since the last hourly job.";
            $this->cron_debug($result["skipped_reason"], "hourly_gate", "warning");
            $this->log_cron_run("create_recurring_tasks", $trigger_source, gmdate('Y-m-d H:i:s'), 0, 'skipped_by_gate', $result["skipped_reason"]);
        }

        return $result;
    }

    public function clean_performance_and_cron_logs() {
        // Phase 15 tables only — no-op when Phase 15 is not deployed.
        if (!class_exists('\App\Libraries\Cron_performance_tracker')) {
            return 0;
        }

        $db = db_connect('default');
        $cron_table = $db->prefixTable('cron_run_log');
        $load_table = $db->prefixTable('request_load_minute');
        $slow_table = $db->prefixTable('slow_queries');

        foreach (array($cron_table, $load_table, $slow_table) as $table) {
            $exists = $db->query("SHOW TABLES LIKE " . $db->escape($table))->getRow();
            if (!$exists) {
                return 0;
            }
        }

        $db->query("DELETE FROM `$cron_table` WHERE `started_at` < UTC_TIMESTAMP() - INTERVAL 30 DAY");
        $db->query("DELETE FROM `$load_table` WHERE `minute` < UTC_TIMESTAMP() - INTERVAL 14 DAY");
        $db->query("DELETE FROM `$slow_table` WHERE `logged_at` < UTC_TIMESTAMP() - INTERVAL 14 DAY");

        return 1;
    }

    private function _is_hourly_job_runnable() {
        $last_hourly_job_time = get_setting('last_hourly_job_time');
        if ($last_hourly_job_time == "" || ($this->current_time > ($last_hourly_job_time * 1 + 3600))) {
            return true;
        }
    }

    private function send_invoice_due_pre_reminder() {

        $reminder_date = get_setting("send_invoice_due_pre_reminder");
        $reminder_date2 = get_setting("send_invoice_due_pre_second_reminder");
        if (!($reminder_date || $reminder_date2)) {
            return false;
        }

        //prepare invoice due date accroding to the setting
        $reminder_due_date = $reminder_date ? add_period_to_date($this->today, $reminder_date, "days") : "";
        $reminder_due_date2 = $reminder_date2 ? add_period_to_date($this->today, $reminder_date2, "days") : "";

        $invoices = $this->ci->Invoices_model->get_details(array(
                    "status" => "not_paid_and_partially_paid", //find all invoices which are not paid yet but due date not expired
                    "reminder_due_date" => $reminder_due_date,
                    "reminder_due_date2" => $reminder_due_date2,
                    "exclude_due_reminder_date" => $this->today //don't find invoices which reminder already sent today
                ))->getResult();

        foreach ($invoices as $invoice) {
            log_notification("invoice_due_reminder_before_due_date", array("invoice_id" => $invoice->id), "0");
        }
    }

    private function send_invoice_due_after_reminder() {

        $reminder_date = get_setting("send_invoice_due_after_reminder");
        $reminder_date2 = get_setting("send_invoice_due_after_second_reminder");
        if (!($reminder_date || $reminder_date2)) {
            return false;
        }

        //prepare invoice due date accroding to the setting
        $reminder_due_date = $reminder_date ? subtract_period_from_date($this->today, $reminder_date, "days") : "";
        $reminder_due_date2 = $reminder_date2 ? subtract_period_from_date($this->today, $reminder_date2, "days") : "";

        $invoices = $this->ci->Invoices_model->get_details(array(
                    "status" => "overdue", //find all invoices where due date has expired
                    "reminder_due_date" => $reminder_due_date,
                    "reminder_due_date2" => $reminder_due_date2,
                    "exclude_due_reminder_date" => $this->today //don't find invoices which reminder already sent today
                ))->getResult();

        foreach ($invoices as $invoice) {
            log_notification("invoice_overdue_reminder", array("invoice_id" => $invoice->id), "0");
        }
    }

    private function send_recurring_invoice_creation_reminder() {

        $reminder_date = get_setting("send_recurring_invoice_reminder_before_creation");

        if ($reminder_date) {

            //prepare invoice due date accroding to the setting
            $start_date = add_period_to_date($this->today, get_setting("send_recurring_invoice_reminder_before_creation"), "days");

            $invoices = $this->ci->Invoices_model->get_details(array(
                        "status" => "not_paid", //non-draft invoices
                        "recurring" => 1,
                        "next_recurring_start_date" => $start_date,
                        "next_recurring_end_date" => $start_date, //both should be same
                        "exclude_recurring_reminder_date" => $this->today //don't find invoices which reminder already sent today
                    ))->getResult();

            foreach ($invoices as $invoice) {
                log_notification("recurring_invoice_creation_reminder", array("invoice_id" => $invoice->id), "0");
            }
        }
    }

    private function create_recurring_invoices() {
        $recurring_invoices = $this->ci->Invoices_model->get_renewable_invoices($this->today);
        if ($recurring_invoices->resultID->num_rows) {
            foreach ($recurring_invoices->getResult() as $invoice) {
                $this->_create_new_invoice($invoice);
            }
        }
    }

    //create new invoice from a recurring invoice 
    private function _create_new_invoice($invoice) {

        //don't update the next recurring date when updating invoice manually?
        //stop backdated recurring invoice creation.
        //check recurring invoice once/hour?
        //settings: send invoice to client


        $bill_date = $invoice->next_recurring_date;
        $diff_of_due_date = get_date_difference_in_days($invoice->due_date, $invoice->bill_date); //calculate the due date difference of the original invoice
        $due_date = add_period_to_date($bill_date, $diff_of_due_date, "days");

        $new_invoice_data = array(
            "client_id" => $invoice->client_id,
            "project_id" => $invoice->project_id,
            "bill_date" => $bill_date,
            "due_date" => $due_date,
            "note" => $invoice->note,
            "status" => "draft",
            "tax_id" => $invoice->tax_id,
            "tax_id2" => $invoice->tax_id2,
            "tax_id3" => $invoice->tax_id3,
            "recurring_invoice_id" => $invoice->id,
            "discount_amount" => $invoice->discount_amount,
            "discount_amount_type" => $invoice->discount_amount_type,
            "discount_type" => $invoice->discount_type,
            "company_id" => $invoice->company_id
        );

        //create new invoice
        $new_invoice_id = $this->ci->Invoices_model->ci_save($new_invoice_data);

        //create invoice items
        $items = $this->ci->Invoice_items_model->get_details(array("invoice_id" => $invoice->id))->getResult();
        foreach ($items as $item) {
            //create invoice items for new invoice
            $new_invoice_item_data = array(
                "title" => $item->title,
                "description" => $item->description,
                "quantity" => $item->quantity,
                "unit_type" => $item->unit_type,
                "rate" => $item->rate,
                "total" => $item->total,
                "invoice_id" => $new_invoice_id,
            );
            $this->ci->Invoice_items_model->ci_save($new_invoice_item_data);
        }


        //update the main recurring invoice
        $no_of_cycles_completed = $invoice->no_of_cycles_completed + 1;
        $next_recurring_date = add_period_to_date($bill_date, $invoice->repeat_every, $invoice->repeat_type);

        $recurring_invoice_data = array(
            "next_recurring_date" => $next_recurring_date,
            "no_of_cycles_completed" => $no_of_cycles_completed
        );
        $this->ci->Invoices_model->ci_save($recurring_invoice_data, $invoice->id);

        //finally send notification
        log_notification("recurring_invoice_created_vai_cron_job", array("invoice_id" => $new_invoice_id), "0");
    }

    private function create_subscription_invoices() {
        $subscriptions = $this->ci->Subscriptions_model->get_renewable_subscriptions($this->today);
        if ($subscriptions->resultID->num_rows) {
            foreach ($subscriptions->getResult() as $subscription) {
                $this->_create_new_invoice_of_subscription($subscription);
            }
        }
    }

    //create new invoice from a subscription
    private function _create_new_invoice_of_subscription($subscription_info) {
        create_invoice_from_subscription($subscription_info->id);

        //update the main recurring subscription
        $no_of_cycles_completed = $subscription_info->no_of_cycles_completed + 1;
        $next_recurring_date = add_period_to_date($subscription_info->next_recurring_date, $subscription_info->repeat_every, $subscription_info->repeat_type);

        $subscription_data = array(
            "next_recurring_date" => $next_recurring_date,
            "no_of_cycles_completed" => $no_of_cycles_completed
        );

        $this->ci->Subscriptions_model->ci_save($subscription_data, $subscription_info->id);

        //finally send notification
    }

    private function get_google_calendar_events() {
        $Google_calendar_events = new Google_calendar_events();
        $Google_calendar_events->get_google_calendar_events();
    }

    private function run_imap() {
        if (!$this->_is_imap_callable()) {
            return false;
        }

        if (!get_setting('imap_type') || get_setting('imap_type') === "general_imap") {
            $imap = new Imap();
            $imap->run_imap();
        } else {
            $imap = new Outlook_imap();
            $imap->run_imap();
        }

        $this->ci->Settings_model->save_setting("last_cron_job_time_of_imap", $this->current_time);
    }

    private function _is_imap_callable() {

        //check if settings is enabled and authorized
        if (!(get_setting("enable_email_piping") && get_setting("imap_authorized"))) {
            return false;
        }

        //wait 10 minutes for each check
        $last_cron_job_time_of_imap = get_setting('last_cron_job_time_of_imap');
        if ($last_cron_job_time_of_imap == "" || ($this->current_time > ($last_cron_job_time_of_imap * 1 + 600))) {
            return true;
        }
    }

    private function create_recurring_tasks($options = array()) {
        return $this->process_recurring_tasks($options);
    }

    //create new task from a recurring task
    private function _create_new_task($task) {
        return $this->create_clone_from_recurring_task($task);
    }

    public function process_recurring_tasks($options = array()) {
        if (!$this->ci) {
            $this->init_runtime($options);
        }

        $enabled = (bool) get_setting("enable_recurring_option_for_tasks");
        $query_date = $this->get_recurring_query_date();
        $task_id = (int) get_array_value($options, "task_id");
        $diagnostic = !empty($options["diagnostic"]);

        $result = array(
            "enabled" => $enabled,
            "today" => $this->get_today(),
            "query_date" => $query_date,
            "found" => 0,
            "created" => 0,
            "failed" => 0,
            "skipped" => 0,
            "clone_ids" => array(),
            "items" => array(),
            "reason" => ""
        );

        $this->cron_debug("process_recurring_tasks enabled=" . ($enabled ? "yes" : "no") . " query_date={$query_date}" . ($task_id ? " task_id={$task_id}" : ""), "recurring_tasks", "info", array("task_id" => $task_id));

        if (!$enabled) {
            $result["reason"] = "Recurring-task setting is disabled. Enable it in Settings → Tasks.";
            $this->cron_debug($result["reason"], "recurring_tasks", "warning");
            return $result;
        }

        $tasks = array();
        if ($task_id) {
            $task = $this->ci->Tasks_model->get_one($task_id);
            if (!$task || !$task->id) {
                $result["reason"] = "Task #{$task_id} was not found.";
                $result["failed"] = 1;
                $this->cron_debug($result["reason"], "recurring_tasks", "error", array("task_id" => $task_id));
                return $result;
            }
            $tasks[] = $task;
        } else {
            $query = $this->ci->Tasks_model->get_renewable_tasks($query_date);
            $tasks = $query ? $query->getResult() : array();
        }

        $result["found"] = count($tasks);
        $this->cron_debug("Eligible or selected recurring tasks: " . $result["found"], "recurring_tasks", "info");

        if (!$result["found"]) {
            $result["reason"] = $task_id ? "The selected task is not a renewable recurring task for query date {$query_date}." : "No recurring tasks are eligible for query date {$query_date}.";
            $this->cron_debug($result["reason"], "recurring_eligibility", "warning", array("task_id" => $task_id));
            if ($task_id) {
                $task = $this->ci->Tasks_model->get_one($task_id);
                if ($task && $task->id) {
                    $item = $this->create_clone_from_recurring_task($task, $options);
                    $result["items"][] = $item;
                    $this->apply_clone_item_counts($result, $item);
                }
            }
            return $result;
        }

        foreach ($tasks as $task) {
            if ($diagnostic && $task_id) {
                $calc = $this->calculate_next_clone($task);
                $existing = $this->ci->Tasks_model->find_clone_for_occurrence($task->id, $calc["clone_start_date"]);
                if (!$existing) {
                    $existing = $this->ci->Tasks_model->find_recent_clone($task->id, 300);
                }
                if ($existing) {
                    $item = array(
                        "status" => "skipped",
                        "reason" => "SKIPPED: A clone already exists for this parent and recurring date. Existing clone #{$existing->id} start_date={$existing->start_date}.",
                        "parent_task_id" => (int) $task->id,
                        "clone_task_id" => (int) $existing->id,
                        "verification" => array(
                            "result" => "SKIPPED",
                            "message" => "SKIPPED: A clone already exists for this parent and recurring date.",
                            "rows" => array()
                        )
                    );
                    $result["items"][] = $item;
                    $this->apply_clone_item_counts($result, $item);
                    $this->cron_debug($item["reason"], "clone_skip", "warning", array("task_id" => $task->id, "parent_task_id" => $task->id, "clone_task_id" => $existing->id));
                    continue;
                }
            }

            $item = $this->create_clone_from_recurring_task($task, $options);
            $result["items"][] = $item;
            $this->apply_clone_item_counts($result, $item);
        }

        return $result;
    }

    public function calculate_next_clone($task) {
        if (!$this->today) {
            $this->today = get_today_date();
        }

        $start_date = $this->normalize_sql_date($task->next_recurring_date);
        $no_of_cycles_completed = (int) $task->no_of_cycles_completed;
        $no_of_cycles = (int) $task->no_of_cycles;
        $repeat_every = $task->repeat_every ? $task->repeat_every : 1;
        $repeat_type = $task->repeat_type ? $task->repeat_type : "days";
        $reasons = array();

        if (!$task->id) {
            $reasons[] = "Invalid task ID.";
        }
        if (!empty($task->deleted)) {
            $reasons[] = "Task is deleted.";
        }
        if (empty($task->recurring)) {
            $reasons[] = "Task is not recurring.";
        }
        if (!$task->next_recurring_date) {
            $reasons[] = "next_recurring_date is empty.";
        }

        $skipped = 0;
        while ($start_date && $start_date < $this->today) {
            if ($no_of_cycles >= 1 && $no_of_cycles_completed >= $no_of_cycles) {
                break;
            }
            $start_date = add_period_to_date($start_date, $repeat_every, $repeat_type);
            $no_of_cycles_completed++;
            $skipped++;
            if ($skipped > 2000) {
                break;
            }
        }

        $parent_start_date = $this->normalize_sql_date($task->start_date ? $task->start_date : $task->created_date);
        if ($start_date && $parent_start_date && $start_date === $parent_start_date) {
            $start_date = add_period_to_date($start_date, $repeat_every, $repeat_type);
            $reasons[] = "Clone start date was advanced one interval so it does not copy the parent occurrence.";
        }

        $target_date = $this->get_recurring_query_date();
        $cycle_limit_reached = ($no_of_cycles >= 1 && $no_of_cycles_completed >= $no_of_cycles);
        $is_due_now = $start_date && $start_date <= $target_date && !$cycle_limit_reached;

        if ($cycle_limit_reached) {
            $reasons[] = "Cycle limit reached ({$no_of_cycles_completed}/{$no_of_cycles}).";
        }
        if ($start_date && $start_date > $target_date) {
            $reasons[] = "Calculated clone start date {$start_date} is after the query date {$target_date}.";
        }

        $deadline = $start_date;
        $parent_deadline = $this->normalize_sql_date($task->deadline);
        if ($start_date && $parent_start_date && $parent_deadline) {
            $diff_of_deadline = (int) get_date_difference_in_days($parent_start_date, $parent_deadline);
            $deadline = add_period_to_date($start_date, $diff_of_deadline, "days");
        }

        $next_recurring_date = $start_date ? add_period_to_date($start_date, $repeat_every, $repeat_type) : "";

        return array(
            "parent_task_id" => (int) $task->id,
            "parent_title" => $task->title,
            "parent_start_date" => $task->start_date,
            "parent_deadline" => $task->deadline,
            "parent_next_recurring_date" => $task->next_recurring_date,
            "repeat_every" => $repeat_every,
            "repeat_type" => $repeat_type,
            "repeat_interval" => $repeat_every . " " . $repeat_type,
            "clone_start_date" => $start_date,
            "clone_deadline" => $deadline,
            "next_recurring_date_after" => $next_recurring_date,
            "expected_parent_task_id" => (int) $task->id,
            "expected_recurring_task_id" => (int) $task->id,
            "no_of_cycles" => $no_of_cycles,
            "no_of_cycles_completed" => $no_of_cycles_completed,
            "no_of_cycles_completed_after" => $is_due_now ? ($no_of_cycles_completed + 1) : $no_of_cycles_completed,
            "skipped_overdue_cycles" => $skipped,
            "cycle_limit_reached" => $cycle_limit_reached,
            "is_due_now" => $is_due_now,
            "query_date" => $target_date,
            "today" => $this->get_today(),
            "reasons" => $reasons
        );
    }

    public function create_clone_from_recurring_task($task, $options = array()) {
        if (!$this->ci) {
            $this->init_runtime($options);
        }

        $calc = $this->calculate_next_clone($task);
        $item = array(
            "status" => "skipped",
            "reason" => "",
            "parent_task_id" => (int) $task->id,
            "clone_task_id" => null,
            "preview" => $calc,
            "parent_before" => null,
            "parent_after" => null,
            "clone" => null,
            "verification" => null
        );

        if ($this->logger) {
            $logger = $this->logger;
            $item["parent_before"] = $logger->task_snapshot($task);
        }

        if (!empty($task->deleted)) {
            $item["reason"] = "Task is deleted.";
            $item["status"] = "error";
            $this->cron_debug($item["reason"], "clone_skip", "error", array("task_id" => $task->id, "parent_task_id" => $task->id));
            return $item;
        }

        if (empty($task->recurring)) {
            $item["reason"] = "Task is not recurring.";
            $this->cron_debug($item["reason"], "clone_skip", "warning", array("task_id" => $task->id, "parent_task_id" => $task->id));
            return $item;
        }

        if (!$calc["is_due_now"]) {
            $item["reason"] = $calc["cycle_limit_reached"]
                ? "Cycle limit reached. No clone was created."
                : "Task is not eligible yet. Calculated start date is {$calc["clone_start_date"]} and query date is {$calc["query_date"]}.";
            $recurring_task_data = array(
                "next_recurring_date" => $calc["clone_start_date"],
                "no_of_cycles_completed" => $calc["no_of_cycles_completed"]
            );
            $this->ci->Tasks_model->save_reminder_date($recurring_task_data, $task->id);
            $this->cron_debug($item["reason"], "clone_skip", "warning", array("task_id" => $task->id, "parent_task_id" => $task->id));
            return $item;
        }

        $existing = $this->ci->Tasks_model->find_clone_for_occurrence($task->id, $calc["clone_start_date"]);
        if ($existing) {
            $item["status"] = "skipped";
            $item["clone_task_id"] = (int) $existing->id;
            $item["reason"] = "SKIPPED: A clone already exists for this parent and recurring date. Existing clone #{$existing->id} has start_date {$calc["clone_start_date"]}.";
            $this->cron_debug($item["reason"], "clone_skip", "warning", array("task_id" => $task->id, "parent_task_id" => $task->id, "clone_task_id" => $existing->id));
            if ($this->logger) {
                $item["verification"] = array(
                    "result" => "WARNING",
                    "message" => "SKIPPED: A clone already exists for this parent and recurring date.",
                    "rows" => array()
                );
            }
            return $item;
        }

        $new_task_data = array(
            "title" => $task->title,
            "description" => $task->description,
            "project_id" => $task->project_id,
            "milestone_id" => $task->milestone_id,
            "assigned_to" => $task->assigned_to,
            "points" => $task->points,
            "status_id" => 1,
            "status_changed_at" => get_current_utc_time(),
            "priority_id" => isset($task->priority_id) ? $task->priority_id : 0,
            "labels" => $task->labels,
            "collaborators" => $task->collaborators,
            "start_date" => $calc["clone_start_date"],
            "deadline" => $calc["clone_deadline"],
            "end_time" => isset($task->end_time) ? $task->end_time : null,
            "created_by" => (int) $task->created_by,
            "parent_task_id" => (int) $task->id,
            "recurring_task_id" => (int) $task->id,
            "recurring" => 0,
            "repeat_every" => $calc["repeat_every"],
            "repeat_type" => $calc["repeat_type"],
            "no_of_cycles" => $calc["no_of_cycles"],
            "next_recurring_date" => $calc["next_recurring_date_after"],
            "blocking" => isset($task->blocking) ? $task->blocking : "",
            "blocked_by" => isset($task->blocked_by) ? $task->blocked_by : "",
            "sort" => $this->ci->Tasks_model->get_next_sort_value($task->project_id, 1)
        );

        $this->cron_debug(
            "Validated clone INSERT for parent #{$task->id}: start_date={$new_task_data["start_date"]} deadline={$new_task_data["deadline"]} next_recurring_date={$new_task_data["next_recurring_date"]} parent_task_id={$new_task_data["parent_task_id"]} recurring_task_id={$new_task_data["recurring_task_id"]}",
            "clone_create",
            "info",
            array(
                "task_id" => $task->id,
                "parent_task_id" => $task->id,
                "insert" => $new_task_data
            )
        );

        try {
            $saved = $this->ci->Tasks_model->insert_recurring_clone($new_task_data);
        } catch (\Throwable $e) {
            $item["status"] = "error";
            $item["reason"] = $e->getMessage();
            $item["verification"] = array(
                "result" => "ERROR",
                "message" => $e->getMessage(),
                "rows" => array()
            );
            $this->cron_debug($item["reason"], "clone_create", "error", array("task_id" => $task->id, "parent_task_id" => $task->id));
            return $item;
        }

        $new_task_id = (int) $saved["id"];
        $this->copy_recurring_checklists_and_subtasks($task, $new_task_id, $calc["clone_start_date"]);

        $no_of_cycles_completed = $calc["no_of_cycles_completed"] + 1;
        $next_recurring_date = $calc["next_recurring_date_after"];
        $recurring_task_data = array(
            "next_recurring_date" => $next_recurring_date,
            "no_of_cycles_completed" => $no_of_cycles_completed
        );
        $this->ci->Tasks_model->save_reminder_date($recurring_task_data, $task->id);

        $notification_option = array("project_id" => $task->project_id, "task_id" => $new_task_id);
        log_notification("recurring_task_created_via_cron_job", $notification_option, "0");

        $clone = $saved["row"] ? $saved["row"] : $this->ci->Tasks_model->get_one($new_task_id);
        $parent_after = $this->ci->Tasks_model->get_one($task->id);

        $item["clone_task_id"] = $new_task_id;
        $item["clone"] = $clone;
        $item["parent_after"] = $parent_after;
        $item["insert"] = $saved["insert"];

        $expected = array(
            "id" => $new_task_id,
            "title" => $task->title,
            "start_date" => $calc["clone_start_date"],
            "deadline" => $calc["clone_deadline"],
            "recurring" => 0,
            "repeat_every" => $calc["repeat_every"],
            "repeat_type" => $calc["repeat_type"],
            "no_of_cycles" => $calc["no_of_cycles"],
            "no_of_cycles_completed" => 0,
            "parent_task_id" => $task->id,
            "recurring_task_id" => $task->id,
            "next_recurring_date" => $calc["next_recurring_date_after"],
            "created_date" => $clone && $clone->created_date ? $clone->created_date : "",
            "deleted" => 0
        );

        if ($this->logger) {
            $item["verification"] = $this->logger->verify_clone($parent_after, $clone, $expected);
            $item["clone"] = $this->logger->task_snapshot($clone);
            $item["parent_after"] = $this->logger->task_snapshot($parent_after);
        } else {
            $db_ok = !empty($saved["verification"]["ok"]);
            $item["verification"] = array(
                "result" => $db_ok ? "SUCCESS" : "ERROR",
                "message" => $saved["verification"]["message"],
                "rows" => array()
            );
        }

        $verify_result = get_array_value($item["verification"], "result");
        if ($verify_result === "SUCCESS") {
            $item["status"] = "created";
            $item["reason"] = "Clone #{$new_task_id} created from parent #{$task->id}.";
            $this->cron_debug($item["reason"], "clone_verify", "success", array("task_id" => $task->id, "parent_task_id" => $task->id, "clone_task_id" => $new_task_id));
        } else if ($verify_result === "WARNING") {
            $item["status"] = "warning";
            $item["reason"] = get_array_value($item["verification"], "message");
            $this->cron_debug($item["reason"], "clone_verify", "warning", array("task_id" => $task->id, "parent_task_id" => $task->id, "clone_task_id" => $new_task_id));
        } else {
            $item["status"] = "error";
            $item["reason"] = get_array_value($item["verification"], "message") ? $item["verification"]["message"] : "Clone verification failed.";
            $this->cron_debug($item["reason"], "clone_verify", "error", array("task_id" => $task->id, "parent_task_id" => $task->id, "clone_task_id" => $new_task_id));
        }

        $parent_next_ok = $parent_after && $parent_after->next_recurring_date === $next_recurring_date;
        if (!$parent_next_ok) {
            $this->cron_debug("Parent #{$task->id} next_recurring_date was not advanced to {$next_recurring_date}.", "clone_verify", "warning", array("task_id" => $task->id, "parent_task_id" => $task->id, "clone_task_id" => $new_task_id));
        }

        return $item;
    }

    private function apply_clone_item_counts(&$result, $item) {
        if ($item["status"] === "created" || $item["status"] === "warning") {
            $result["created"]++;
            if (!empty($item["clone_task_id"])) {
                $result["clone_ids"][] = array(
                    "clone_id" => (int) $item["clone_task_id"],
                    "parent_task_id" => (int) $item["parent_task_id"]
                );
            }
        } else if ($item["status"] === "error") {
            $result["failed"]++;
        } else {
            $result["skipped"]++;
        }
        if (!$result["reason"] && !empty($item["reason"])) {
            $result["reason"] = $item["reason"];
        }
    }

    private function copy_recurring_checklists_and_subtasks($task, $new_task_id, $start_date) {
        $Checklist_items_model = model("App\Models\Checklist_items_model");
        $checklist_item_options = array("task_id" => $task->id);
        $checklist_items = $Checklist_items_model->get_details($checklist_item_options);
        if ($checklist_items->resultID->num_rows) {
            foreach ($checklist_items->getResult() as $item) {
                $checklist_item_data = array(
                    "title" => $item->title,
                    "is_checked" => $item->is_checked,
                    "task_id" => $new_task_id,
                    "sort" => $item->sort
                );
                $Checklist_items_model->ci_save($checklist_item_data);
            }
        }

        $sub_tasks = $this->ci->Tasks_model->get_all_where(array("parent_task_id" => $task->id, "deleted" => 0))->getResult();
        foreach ($sub_tasks as $sub_task) {
            if (!empty($sub_task->recurring_task_id) && (int) $sub_task->recurring_task_id === (int) $task->id) {
                continue;
            }

            $sub_task_data = (array) $sub_task;
            unset($sub_task_data["id"]);
            unset($sub_task_data["blocked_by"]);
            unset($sub_task_data["blocking"]);

            if ($task->start_date && $sub_task->start_date) {
                $sub_task_data['start_date'] = $start_date;
            } else {
                $sub_task_data['start_date'] = null;
            }

            $sub_task_data['status_id'] = 1;
            $sub_task_data['parent_task_id'] = $new_task_id;
            $sub_task_data['created_date'] = get_current_utc_time();
            $sub_task_data['status_changed_at'] = get_current_utc_time();
            $sub_task_data['deadline'] = null;
            $sub_task_data["sort"] = $this->ci->Tasks_model->get_next_sort_value(get_array_value($sub_task_data, "project_id"), $sub_task_data["status_id"]);

            $sub_task_save_id = $this->ci->Tasks_model->ci_save($sub_task_data);

            $sub_checklists = $Checklist_items_model->get_all_where(array("task_id" => $sub_task->id, "deleted" => 0))->getResult();
            foreach ($sub_checklists as $checklist_item) {
                $checklist_item_data = (array) $checklist_item;
                unset($checklist_item_data["id"]);
                $checklist_item_data['task_id'] = $sub_task_save_id;
                $Checklist_items_model->ci_save($checklist_item_data);
            }
        }
    }

    private function send_task_reminder_notifications() {
        $notification_option = array("notification_multiple_tasks" => true);
        log_notification("project_task_deadline_pre_reminder", $notification_option, "0");
        log_notification("project_task_deadline_overdue_reminder", $notification_option, "0");
        log_notification("project_task_reminder_on_the_day_of_deadline", $notification_option, "0");
    }

    public function send_task_deadline_2_hour_notifications() {
        // Task deadline 2-hour reminders are checked pre-gate in Cron::run() and Cron::run_scheduled_cron().
        // Kept as safe fallback if invoked standalone.
        if (function_exists('check_and_send_task_deadline_2_hour_reminders')) {
            return check_and_send_task_deadline_2_hour_reminders(false);
        }
        return 0;
    }

    private function close_inactive_tickets() {

        $inactive_ticket_closing_date = get_setting("inactive_ticket_closing_date");
        if (!($inactive_ticket_closing_date == "" || ($inactive_ticket_closing_date != $this->today))) {
            return false;
        }

        $auto_close_ticket_after_days = get_setting("auto_close_ticket_after");

        if ($auto_close_ticket_after_days) {
            //prepare last activity date accroding to the setting
            $last_activity_date = subtract_period_from_date($this->today, get_setting("auto_close_ticket_after"), "days");

            $tickets = $this->ci->Tickets_model->get_details(array(
                        "status" => "open", //don't find closed tickets
                        "last_activity_date_or_before" => $last_activity_date
                    ))->getResult();

            foreach ($tickets as $ticket) {
                //make ticket closed
                $ticket_data = array(
                    "status" => "closed",
                    "closed_at" => get_current_utc_time()
                );

                $this->ci->Tickets_model->ci_save($ticket_data, $ticket->id);

                //send notification
                log_notification("ticket_closed", array("ticket_id" => $ticket->id), "0");
            }
        }

        $this->ci->Settings_model->save_setting("inactive_ticket_closing_date", $this->today);
    }

    private function create_recurring_expenses() {
        $recurring_expenses = $this->ci->Expenses_model->get_renewable_expenses($this->today);
        if ($recurring_expenses->resultID->num_rows) {
            foreach ($recurring_expenses->getResult() as $expense) {
                $this->_create_new_expense($expense);
            }
        }
    }

    //create new expense from a recurring expense 
    private function _create_new_expense($expense) {

        //don't update the next recurring date when updating expense manually?
        //stop backdated recurring expense creation.
        //check recurring expense once/hour?

        $expense_date = $expense->next_recurring_date;

        $new_expense_data = array(
            "title" => $expense->title,
            "expense_date" => $expense_date,
            "description" => $expense->description,
            "category_id" => $expense->category_id,
            "amount" => $expense->amount,
            "project_id" => $expense->project_id,
            "user_id" => $expense->user_id,
            "tax_id" => $expense->tax_id,
            "tax_id2" => $expense->tax_id2,
            "recurring_expense_id" => $expense->id
        );

        //create new expense
        $new_expense_id = $this->ci->Expenses_model->ci_save($new_expense_data);

        //update the main recurring expense
        $no_of_cycles_completed = $expense->no_of_cycles_completed + 1;
        $next_recurring_date = add_period_to_date($expense_date, $expense->repeat_every, $expense->repeat_type);

        $recurring_expense_data = array(
            "next_recurring_date" => $next_recurring_date,
            "no_of_cycles_completed" => $no_of_cycles_completed
        );

        $this->ci->Expenses_model->ci_save($recurring_expense_data, $expense->id);

        //finally send notification
//        log_notification("recurring_expense_created_vai_cron_job", array("expense_id" => $new_expense_id), "0");
    }

    private function create_recurring_reminders() {
        $options = array(
            "type" => "all",
            "recurring" => true,
            "reminder_status" => "new",
        );

        $recurring_reminders = $this->ci->Events_model->get_details($options)->getResult();
        foreach ($recurring_reminders as $reminder) {

            $now = get_my_local_time();
            $target_time = is_null($reminder->next_recurring_time) ? ($reminder->start_date . " " . $reminder->start_time) : $reminder->next_recurring_time;

            if ($target_time < $now && (!$reminder->no_of_cycles || $reminder->no_of_cycles_completed < $reminder->no_of_cycles)) {
                $data["next_recurring_time"] = add_period_to_date($target_time, $reminder->repeat_every, $reminder->repeat_type, "Y-m-d H:i:s");
                $data['no_of_cycles_completed'] = (int) $reminder->no_of_cycles_completed + 1;

                $this->ci->Events_model->ci_save($data, $reminder->id);
            }
        }
    }

    private function check_content_calendar_special_days_gate() {
        $now = new \DateTime("now", new \DateTimeZone("Asia/Kolkata"));
        $hour = (int) $now->format("G");
        if (!$this->diagnostic && $hour < 7) {
            return array(
                "open" => false,
                "reason" => "Scheduled for 07:00 IST (current time: " . $now->format("H:i") . " IST)"
            );
        }
        return array("open" => true);
    }

    public function process_content_calendar_special_days() {
        $db = db_connect('default');

        // Part E requirement: Code must not crash if the table does not exist yet (skip and log once)
        try {
            $table_exists = $db->tableExists($db->prefixTable('cc_special_day_notices'));
        } catch (\Throwable $e) {
            $table_exists = false;
        }
        if (!$table_exists) {
            $this->cron_debug("rise_cc_special_day_notices table does not exist. Please run migration.", "cron_run", "warning");
            return array("created" => 0, "status" => "SKIPPED", "reason" => "Table rise_cc_special_day_notices missing");
        }

        $now_kol = new \DateTime("now", new \DateTimeZone("Asia/Kolkata"));
        $today_date = $now_kol->format("Y-m-d");

        // Read today's special days from rise_cc_special_days (calendars not deleted)
        // Uses index idx_cc_special_days_date (Part B.6)
        $sd_table = $db->prefixTable('cc_special_days');
        $cal_table = $db->prefixTable('cc_calendars');

        $sql = "SELECT DISTINCT d.label 
                FROM `$sd_table` d 
                JOIN `$cal_table` c ON c.id = d.calendar_id 
                WHERE d.special_date = ? AND c.deleted = 0";
        $query = $db->query($sql, array($today_date));
        $rows = $query ? $query->getResult() : array();

        if (empty($rows)) {
            return array("created" => 0, "status" => "OK", "reason" => "No special days for today ($today_date)");
        }

        // Distinct normalised labels: trimmed, case-insensitive, whitespace collapsed; ignore weekday-only or empty
        $weekdays = array('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun');
        $distinct_labels = array();
        foreach ($rows as $row) {
            $raw_label = isset($row->label) ? (string) $row->label : "";
            $cleaned = trim(preg_replace('/\s+/', ' ', $raw_label));
            if ($cleaned === "") {
                continue;
            }
            $key = strtolower($cleaned);
            if (in_array($key, $weekdays, true)) {
                continue;
            }
            if (!isset($distinct_labels[$key])) {
                $distinct_labels[$key] = $cleaned;
            }
        }

        if (empty($distinct_labels)) {
            return array("created" => 0, "status" => "OK", "reason" => "No eligible special day labels for today");
        }

        // Check dedupe table rise_cc_special_day_notices
        $notices_table = $db->prefixTable('cc_special_day_notices');
        $notified_keys = array();
        $notices_query = $db->query("SELECT label_key FROM `$notices_table` WHERE notice_date = ?", array($today_date));
        if ($notices_query) {
            foreach ($notices_query->getResult() as $n_row) {
                $notified_keys[strtolower(trim($n_row->label_key))] = true;
            }
        }

        $new_labels = array();
        foreach ($distinct_labels as $key => $display_label) {
            if (!isset($notified_keys[$key])) {
                $new_labels[$key] = $display_label;
            }
        }

        if (empty($new_labels)) {
            return array("created" => 0, "status" => "SKIPPED", "reason" => "All special days for today have already been notified");
        }

        // Notify every ACTIVE staff user (not clients, not deleted or inactive users)
        $users_table = $db->prefixTable('users');
        $users_query = $db->query("SELECT id FROM `$users_table` WHERE user_type = 'staff' AND status = 'active' AND deleted = 0");
        $active_staff_ids = array();
        if ($users_query) {
            foreach ($users_query->getResult() as $u_row) {
                $active_staff_ids[] = (int) $u_row->id;
            }
        }

        if (empty($active_staff_ids)) {
            return array("created" => 0, "status" => "SKIPPED", "reason" => "No active staff users found to notify");
        }

        // Format: "Today: Children's Day, World Immunization Week" (at most 5 names then "+N more")
        $label_values = array_values($new_labels);
        $first_5 = array_slice($label_values, 0, 5);
        $remaining_count = count($label_values) - 5;
        $description = "Today: " . implode(", ", $first_5);
        if ($remaining_count > 0) {
            $description .= ", +" . $remaining_count . " more";
        }

        $now_utc = get_current_utc_time();
        $notify_to_str = implode(",", $active_staff_ids);

        // Insert into rise_notifications
        $notifications_table = $db->prefixTable('notifications');
        $db->query("INSERT INTO `$notifications_table` 
            (`user_id`, `description`, `created_at`, `notify_to`, `read_by`, `event`, `deleted`) 
            VALUES (0, ?, ?, ?, '', 'content_calendar_special_days', 0)",
            array($description, $now_utc, $notify_to_str)
        );

        // Record in dedupe table rise_cc_special_day_notices
        $now_ist = $now_kol->format("Y-m-d H:i:s");
        $recipients_count = count($active_staff_ids);
        foreach ($new_labels as $key => $display_label) {
            $db->query("INSERT INTO `$notices_table` (`notice_date`, `label_key`, `label`, `notified_at`, `recipients`) 
                        VALUES (?, ?, ?, ?, ?) 
                        ON DUPLICATE KEY UPDATE `notified_at` = VALUES(`notified_at`), `recipients` = VALUES(`recipients`)",
                array($today_date, $key, $display_label, $now_ist, $recipients_count)
            );
        }

        return array(
            "created" => 1,
            "status" => "OK",
            "reason" => "Notified " . $recipients_count . " staff: " . implode(", ", $first_5)
        );
    }

}
