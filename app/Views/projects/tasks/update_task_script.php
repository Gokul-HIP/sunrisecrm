<?php
$review_workflow_enabled = get_setting("task_review_workflow_enabled");
$changes_status_id = function_exists('get_changes_task_status_id') ? (int) get_changes_task_status_id() : 0;
$status_dropdown = array();
foreach ($task_statuses as $status) {
    if ((int) $status->id === $changes_status_id || $status->key_name === "changes") {
        continue; // Never offer Changes in quick status menu
    }
    $status_dropdown[] = array("id" => $status->id, "text" => $status->key_name ? app_lang($status->key_name) : $status->title);
}
?>


<script type="text/javascript">
    $(document).ready(function () {
        if (window.taskStatusUpdaterBound) {
            return;
        }
        window.taskStatusUpdaterBound = true;

        $('body').on('click', '[data-act=update-task-status]', function () {
            $(this).appModifier({
                value: $(this).attr('data-value'),
                actionUrl: '<?php echo_uri("projects/save_task_status") ?>/' + $(this).attr('data-id'),
                select2Option: {data: <?php echo json_encode($status_dropdown) ?>},
                onSuccess: function (response, newValue) {
                    if (response.success) {
                        $("#task-table").appTable({newData: response.data, dataId: response.id});
                        if (typeof window.refreshTaskPointsWidgets === "function") {
                            window.refreshTaskPointsWidgets();
                        }
                        if ($("#task-table").length) {
                            $("#task-table").appTable({reload: true});
                        }
                    }
                }
            });

            return false;
        });

        $('body').on('click', '[data-act=update-task-status-checkbox]', function () {
            $(this).find("span").removeClass("checkbox-checked");
            $(this).find("span").addClass("inline-loader");
            $.ajax({
                url: '<?php echo_uri("projects/save_task_status") ?>/' + $(this).attr('data-id'),
                type: 'POST',
                dataType: 'json',
                data: {value: $(this).attr('data-value')},
                success: function (response) {
                    if (response.success) {
                        $("#task-table").appTable({newData: response.data, dataId: response.id});
                        if (typeof window.refreshTaskPointsWidgets === "function") {
                            window.refreshTaskPointsWidgets();
                        }
                        if ($("#task-table").length) {
                            $("#task-table").appTable({reload: true});
                        }
                    }
                }
            });
        });
    });
</script>