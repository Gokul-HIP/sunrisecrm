<?php
try {
    helper('task_rules');
    if (function_exists('task_rules_tables_exist') && task_rules_tables_exist() && isset($model_info) && is_object($model_info) && !empty($model_info->id)) {
        $Task_meta_model = model("App\Models\Task_meta_model");
        $task_meta = $Task_meta_model->get_by_task_id($model_info->id);
        if ($task_meta && !empty($task_meta->department)) {
            $dept_text = esc($task_meta->department);
            if (!empty($task_meta->sub_department)) {
                $dept_text .= " / " . esc($task_meta->sub_department);
            }
            ?>
            <div id="task-view-department-section" class="col-md-12 mb15 task-department-meta">
                <span class="badge bg-light text-secondary border px-2 py-1" style="font-size: 12px; font-weight: 500; background-color: #f8fafc; color: #475569; border: 1px solid #e2e8f0; border-radius: 4px;">
                    <i data-feather="briefcase" class="icon-14" style="vertical-align: -2px; margin-right: 4px;"></i>
                    <strong>Department:</strong> <span id="task-view-department-badge"><?php echo $dept_text; ?></span>
                </span>
            </div>
            <?php
        }
    }
} catch (\Throwable $e) {
    log_message('error', 'Task view department section error: ' . $e->getMessage());
}
?>
