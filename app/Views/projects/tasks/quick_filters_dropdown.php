<?php

$quick_filters_dropdown = array(
    array("id" => "", "text" => "- " . app_lang("quick_filters") . " -"),
    array("id" => "recently_updated", "text" => app_lang("recently_updated"))
);

$review_workflow_enabled = get_setting("task_review_workflow_enabled");
$in_review_status_id = function_exists('get_in_review_task_status_id') ? (int) get_in_review_task_status_id() : 0;
$changes_status_id = function_exists('get_changes_task_status_id') ? (int) get_changes_task_status_id() : 0;

foreach ($task_statuses as $task_status) {
    if (!$review_workflow_enabled && ((int) $task_status->id === $changes_status_id || $task_status->key_name === "changes")) {
        continue;
    }
    $quick_filters_dropdown[] = array("id" => $task_status->id, "text" => $task_status->key_name ? app_lang("recently_moved_to") . " " . app_lang($task_status->key_name) : app_lang("recently_moved_to") . " " . $task_status->title);
}

$quick_filters_dropdown[] = array("id" => "recently_commented", "text" => app_lang("recently_commented"));
$quick_filters_dropdown[] = array("id" => "mentioned_me", "text" => app_lang("mentioned_me"));
$quick_filters_dropdown[] = array("id" => "recently_mentioned_me", "text" => app_lang("recently_mentioned_me"));
$quick_filters_dropdown[] = array("id" => "recurring_tasks", "text" => app_lang("recurring_tasks"));
$quick_filters_dropdown[] = array("id" => "recently_meaning", "text" => "<i data-feather='settings' class='icon-16'></i> " . app_lang("recently_meaning"));

echo json_encode($quick_filters_dropdown);
?>