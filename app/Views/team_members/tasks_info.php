<div class="card clearfix rounded-0 grid-button all-tasks-view tasks-ui">
    <div class="tasks-sticky">
        <div class="tasks-card tasks-header-card">
            <div class="tab-title clearfix">
                <h4><?php echo app_lang("tasks"); ?></h4>
                <div class="title-button-group">
                    <?php
                    if ($login_user->user_type == "staff") {
                        echo modal_anchor("", "<i data-feather='edit-2' class='icon-16'></i> " . app_lang('batch_update'), array("class" => "btn btn-info text-white hide batch-update-btn", "title" => app_lang('batch_update')));
                        echo js_anchor("<i data-feather='check-square' class='icon-16 ml15'></i> " . app_lang("batch_update"), array("class" => "btn btn-default hide batch-active-btn"));
                        echo js_anchor("<i data-feather='x' class='icon-16'></i> " . app_lang("cancel_selection"), array("class" => "hide btn btn-default batch-cancel-btn"));
                    }
                    if ($can_create_tasks) {
                        echo modal_anchor(get_uri("projects/import_tasks_modal_form"), "<i data-feather='upload' class='icon-16'></i> " . app_lang('import_tasks'), array("class" => "btn btn-default", "title" => app_lang('import_tasks')));
                        echo modal_anchor(get_uri("projects/task_modal_form"), "<i data-feather='plus-circle' class='icon-16'></i> " . app_lang('add_multiple_tasks'), array("class" => "btn btn-default", "title" => app_lang('add_multiple_tasks'), "data-post-add_type" => "multiple"));
                        echo modal_anchor(get_uri("projects/task_modal_form"), "<i data-feather='plus-circle' class='icon-16'></i> " . app_lang('add_task'), array("class" => "btn btn-default", "title" => app_lang('add_task')));
                    }
                    ?>
                </div>
            </div>
        </div>
        <div class="tasks-card tasks-filter-card" id="tasks-filter-card">
            <?php echo view("projects/tasks/task_points_summary", array("summary_id" => "task-points-summary")); ?>
        </div>
    </div>
    <div class="tasks-card tasks-table-card">
        <div class="table-responsive">
            <table id="task-table" class="display" cellspacing="0" width="100%"></table>
        </div>
    </div>
</div>

<?php
$statuses = array();
foreach ($task_statuses as $status) {
    $is_selected = false;
    if (isset($selected_status_id) && $selected_status_id) {
        if ($selected_status_id == $status->id) {
            $is_selected = true;
        }
    } else if ($status->key_name != "done") {
        $is_selected = true;
    }

    $statuses[] = array("text" => ($status->key_name ? app_lang($status->key_name) : $status->title), "value" => $status->id, "isChecked" => $is_selected);
}
?>

<?php echo view("projects/tasks/created_date_apply_filter_js"); ?>
<?php echo view("projects/tasks/task_points_summary_js"); ?>

<script type="text/javascript">
    $(document).ready(function () {
        var showOption = true,
                optionVisibility = <?php echo !empty($show_task_options) ? 'true' : 'false'; ?>,
                optionColumnClass = "w100",
                idColumnClass = "w10p",
                titleColumnClass = "";

        if (isMobile()) {
            showOption = false;
            idColumnClass = "w25p";
            titleColumnClass = "w75p";
        }

        var fixTaskTableColumnsOnce = function () {
            if (!showOption || $("#task-table").data("columns-fixed")) {
                return;
            }
            try {
                var table = $("#task-table").DataTable();
                [3, 5, 7, 9, 10, 11, 12, 13].forEach(function (colIndex) {
                    if (!table.column(colIndex).visible()) {
                        table.column(colIndex).visible(true, false);
                    }
                });
                table.columns.adjust();
                $("#task-table").data("columns-fixed", true);
            } catch (e) {
            }
        };

        if ($.fn.dataTable && $.fn.dataTable.isDataTable("#task-table")) {
            return;
        }

        if (typeof initTaskPointsSummary === "function") {
            initTaskPointsSummary("task-table", "task-points-summary");
        }

        $("#task-table").appTable({
            source: '<?php echo_uri("projects/my_tasks_list_data") ?>',
            serverSide: true,
            order: [[1, "desc"]],
            responsive: false,
            stateSave: false,
            filterDropdown: [
                {name: "specific_user_id", class: "w200", options: <?php echo $team_members_dropdown; ?>, searchable: true},
                {name: "milestone_id", class: "w150", options: [{id: "", text: "- <?php echo app_lang('milestone'); ?> -"}], dependency: ["project_id"], dataSource: '<?php echo_uri("projects/get_milestones_for_filter") ?>'},
                {name: "priority_id", class: "w100", options: <?php echo $priorities_dropdown; ?>},
                {name: "project_id", class: "w200", options: <?php echo $projects_dropdown; ?>, dependent: ["milestone_id"]},
                {name: "quick_filter", class: "w200", showHtml: true, options: <?php echo view("projects/tasks/quick_filters_dropdown"); ?>}
                , <?php echo $custom_field_filters; ?>
            ],
            rangeDatepicker: [],
            singleDatepicker: [{name: "deadline", defaultText: "<?php echo app_lang('deadline') ?>",
                    options: [
                        {value: "expired", text: "<?php echo app_lang('expired') ?>"},
                        {value: moment().format("YYYY-MM-DD"), text: "<?php echo app_lang('today') ?>"},
                        {value: moment().add(1, 'days').format("YYYY-MM-DD"), text: "<?php echo app_lang('tomorrow') ?>"},
                        {value: moment().add(7, 'days').format("YYYY-MM-DD"), text: "<?php echo sprintf(app_lang('in_number_of_days'), 7); ?>"},
                        {value: moment().add(15, 'days').format("YYYY-MM-DD"), text: "<?php echo sprintf(app_lang('in_number_of_days'), 15); ?>"}
                    ]}],
            multiSelect: [
                {
                    name: "status_id",
                    text: "<?php echo app_lang('status'); ?>",
                    options: <?php echo json_encode($statuses); ?>
                }
            ],
            columns: [
                {visible: false, searchable: false},
                {title: '<?php echo app_lang("id") ?>', "class": "c-id c-chk " + idColumnClass, className: "c-id c-chk " + idColumnClass, order_by: "id"},
                {title: '<?php echo app_lang("title") ?>', "class": "c-title " + titleColumnClass, className: "c-title " + titleColumnClass, order_by: "title"},
                {title: '<?php echo app_lang("selected_month_task_points") ?>', "class": "c-score", className: "c-score", visible: showOption, order_by: "points"},
                {visible: false, searchable: false, order_by: "start_date"},
                {title: '<?php echo app_lang("start_date") ?>', "class": "c-start", className: "c-start", "iDataSort": 4, visible: showOption, order_by: "start_date"},
                {visible: false, searchable: false, order_by: "deadline"},
                {title: '<?php echo app_lang("deadline") ?>', "class": "c-deadline", className: "c-deadline", "iDataSort": 6, visible: showOption, order_by: "deadline"},
                {title: '<?php echo app_lang("milestone") ?>', "class": "c-milestone", className: "c-milestone", visible: false, searchable: false, order_by: "milestone"},
                {title: '<?php echo app_lang("project") ?>', "class": "c-project", className: "c-project", visible: showOption, order_by: "project"},
                {title: '<?php echo app_lang("assigned_to") ?>', "class": "c-assignee", className: "c-assignee", visible: showOption, order_by: "assigned_to"},
                {title: '<?php echo app_lang("collaborators") ?>', "class": "c-collab", className: "c-collab", visible: showOption},
                {title: '<?php echo app_lang("status") ?>', "class": "c-status", className: "c-status", visible: showOption, order_by: "status"},
                {title: '<?php echo app_lang("created_at") ?>', "class": "c-created", className: "c-created", visible: showOption, order_by: "created_date"},
                {title: '<?php echo app_lang("gained_points") ?>', visible: false, searchable: false}
<?php echo $custom_field_headers; ?>,
                {title: '<i data-feather="menu" class="icon-16"></i>', visible: optionVisibility, "class": "c-actions text-center option " + optionColumnClass, className: "c-actions text-center option " + optionColumnClass}
            ],
            printColumns: combineCustomFieldsColumns([1, 2, 3, 5, 7, 9, 10, 11, 12, 13, 14], '<?php echo $custom_field_headers; ?>'),
            xlsColumns: combineCustomFieldsColumns([1, 2, 3, 5, 7, 9, 10, 11, 12, 13, 14], '<?php echo $custom_field_headers; ?>'),
            rowCallback: function (nRow, aData) {
                $('td:eq(0)', nRow).attr("style", "border-left:5px solid " + aData[0] + " !important;");
            },
            onRelaodCallback: function () {
                hideBatchTasksBtn(true);
            },
            onInitComplete: function () {
                if (optionVisibility && typeof feather !== "undefined") {
                    feather.replace();
                }

                initTasksCreatedDateApplyFilter({
                    instanceId: "task-table",
                    mode: "table",
                    $toolbar: (function () {
                        var $toolbar = $("#tasks-filter-card .custom-toolbar");
                        if ($toolbar.length) {
                            return $toolbar;
                        }
                        return $("#task-table").closest(".dataTables_wrapper").find(".custom-toolbar");
                    })()
                });

                if (typeof initTaskPointsSummary === "function") {
                    initTaskPointsSummary("task-table", "task-points-summary");
                }

                if (typeof pinTaskListHeader === "function") {
                    pinTaskListHeader("task-table", "task-points-summary");
                }

                requestAnimationFrame(fixTaskTableColumnsOnce);
                if (typeof window.initTasksUiLayout === "function") {
                    window.initTasksUiLayout();
                }
            }
        });

        if (typeof feather !== "undefined") {
            feather.replace();
        }
    });
</script>

<?php echo view("projects/tasks/batch_update/batch_update_script"); ?>
<?php echo view("projects/tasks/update_task_script"); ?>
<?php echo view("projects/tasks/update_task_read_comments_status_script"); ?>
<?php echo view("projects/tasks/quick_filters_helper_js"); ?>
