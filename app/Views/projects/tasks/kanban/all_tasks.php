<?php load_css(array("assets/css/tasks-ui.css")); ?>
<div id="page-content" class="page-wrapper pb0 clearfix grid-button all-tasks-kanban-view tasks-ui">

    <div class="tasks-sticky">
        <div class="tasks-card tasks-header-card">
            <ul class="nav nav-tabs title task-list-sticky-title" role="tablist">
                <li class="title-tab all-tasks-kanban"><h4 class="pl15 pt10 pr15"><?php echo app_lang("tasks"); ?></h4></li>

                <?php echo view("projects/tasks/tabs", array("active_tab" => "tasks_kanban", "selected_tab" => "")); ?>

                <?php echo view("projects/tasks/task_status_counts"); ?>

                <div class="tab-title clearfix no-border">
                    <div class="title-button-group">
                        <?php echo view("projects/tasks/monthly_points_widget", array(
                            "widget_id" => "tasks-kanban-monthly-points",
                            "theme" => "light",
                            "default_user_id" => $login_user->id,
                            "user_selector" => ".all-tasks-kanban-view [name='specific_user_id']"
                        )); ?>
                        <?php
                        if ($login_user->user_type == "staff") {
                            echo modal_anchor("", "<i data-feather='edit-2' class='icon-16'></i> " . app_lang('batch_update'), array("class" => "btn btn-info text-white hide batch-update-btn", "title" => app_lang('batch_update')));
                            echo js_anchor("<i data-feather='x' class='icon-16'></i> " . app_lang("cancel_selection"), array("class" => "hide btn btn-default batch-cancel-btn"));
                        }
                        if ($can_create_tasks) {
                            echo modal_anchor(get_uri("projects/task_modal_form"), "<i data-feather='plus-circle' class='icon-16'></i> " . app_lang('add_multiple_tasks'), array("class" => "btn btn-default", "title" => app_lang('add_multiple_tasks'), "data-post-add_type" => "multiple"));
                            echo modal_anchor(get_uri("projects/task_modal_form"), "<i data-feather='plus-circle' class='icon-16'></i> " . app_lang('add_task'), array("class" => "btn btn-default", "title" => app_lang('add_task')));
                        }
                        ?>
                    </div>
                </div>
            </ul>
        </div>

        <div class="tasks-card tasks-filter-card" id="tasks-filter-card">
            <button class="btn btn-default" id="reload-kanban-button" type="button" title="Refresh" aria-label="Refresh"><i data-feather="refresh-cw" class="icon-16"></i></button>
            <div id="kanban-filters"></div>
        </div>
    </div>

    <div id="load-kanban" class="tasks-kanban-board"></div>
</div>

<script>
    $(document).ready(function () {
        window.scrollToKanbanContent = true;
    });
</script>

<?php echo view("projects/tasks/batch_update/batch_update_script"); ?>
<?php echo view("projects/tasks/kanban/all_tasks_kanban_helper_js"); ?>
<?php echo view("projects/tasks/quick_filters_helper_js"); ?>
<?php echo view("projects/tasks/monthly_points_widget_js"); ?>
<?php load_js(array("assets/js/tasks-ui.js")); ?>
<script>
    $(document).ready(function () {
        if (typeof initMonthlyTaskPointsWidget === "function") {
            initMonthlyTaskPointsWidget("tasks-kanban-monthly-points");
        }
        if (typeof initTasksUiLayout === "function") {
            initTasksUiLayout();
        }
    });
</script>
