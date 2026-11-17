<?php load_css(array("assets/css/timesheet-grouped.css")); ?>
<div id="page-content" class="page-wrapper clearfix grid-button">
    <div class="card">
        <ul id="project-all-timesheet-tabs" data-bs-toggle="ajax-tab" class="nav nav-tabs bg-white title" role="tablist">
            <li class="title-tab"><h4 class="pl15 pt10 pr15"><?php echo app_lang("timesheets"); ?></h4></li>

            <li><a id="timesheet-details-button"  role="presentation" data-bs-toggle="tab" href="javascript:;" data-bs-target="#timesheet-details"><?php echo app_lang("details"); ?></a></li>
            <li><a role="presentation" data-bs-toggle="tab" href="<?php echo_uri("projects/all_timesheet_summary/"); ?>" data-bs-target="#timesheet-summary"><?php echo app_lang('summary'); ?></a></li>
            <li><a role="presentation" data-bs-toggle="tab" href="<?php echo_uri("projects/timesheet_chart/"); ?>" data-bs-target="#timesheet-chart"><?php echo app_lang('chart'); ?></a></li>
        </ul>

        <div class="tab-content">
            <div role="tabpanel" class="tab-pane fade" id="timesheet-details">
                <div class="tg-wrap" id="timesheet-grouped-root">
                    <div class="tg-toolbar">
                        <div class="tg-switch" role="group" aria-label="View mode">
                            <button type="button" data-tg-mode="grouped" class="is-active" aria-pressed="true"><?php echo app_lang("grouped") ?: "Grouped"; ?></button>
                            <button type="button" data-tg-mode="table" aria-pressed="false"><?php echo app_lang("table") ?: "Table"; ?></button>
                        </div>

                        <button type="button" class="tg-btn tg-filters-toggle" data-tg-filters-toggle><?php echo app_lang("filters") ?: "Filters"; ?></button>

                        <div class="tg-filters" data-tg-filters data-tg-grouped-only>
                            <input type="text" class="form-control" name="tg_start_date" value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>" placeholder="From" autocomplete="off" />
                            <input type="text" class="form-control" name="tg_end_date" value="<?php echo date('Y-m-d'); ?>" placeholder="To" autocomplete="off" />
                            <select class="form-control" name="tg_user_id" id="tg-user-id">
                                <option value="">- <?php echo app_lang("member"); ?> -</option>
                            </select>
                            <select class="form-control" name="tg_project_id" id="tg-project-id">
                                <option value="">- <?php echo app_lang("project"); ?> -</option>
                            </select>
<?php if ($login_user->is_admin || get_array_value($login_user->permissions, "client")) { ?>
                            <select class="form-control" name="tg_client_id" id="tg-client-id">
                                <option value="">- <?php echo app_lang("client"); ?> -</option>
                            </select>
<?php } ?>
                            <input type="search" class="form-control tg-search" name="tg_search" placeholder="<?php echo app_lang("search"); ?>" />
                            <button type="button" class="tg-btn" data-tg-apply><?php echo app_lang("apply") ?: "Apply"; ?></button>
                        </div>

                        <div class="tg-actions">
                            <span data-tg-grouped-only>
                                <button type="button" class="tg-btn" data-tg-expand-all><?php echo app_lang("expand_all") ?: "Expand all"; ?></button>
                                <button type="button" class="tg-btn" data-tg-collapse-all><?php echo app_lang("collapse_all") ?: "Collapse all"; ?></button>
                            </span>
                            <span class="tg-dt-buttons-host" id="tg-export-buttons-host" title="<?php echo app_lang('excel_exports_by_entry_date') ?: 'Excel exports time entries by entry date'; ?>"></span>
                            <span class="tg-excel-tip" title="<?php echo app_lang('excel_exports_by_entry_date') ?: 'Excel exports time entries by entry date'; ?>">
                                <span class="tg-tip-icon" aria-hidden="true">i</span>
                                <span class="d-none d-md-inline"><?php echo app_lang('excel_exports_by_entry_date') ?: 'Excel exports time entries by entry date'; ?></span>
                            </span>
                        </div>
                    </div>
                    <div class="tg-caption" data-tg-caption></div>

                    <div id="timesheet-grouped-pane">
                        <div class="tg-loading" data-tg-loading style="display:none;"><div class="spinner-border" role="status"></div></div>
                        <div class="tg-empty" data-tg-empty style="display:none;"><?php echo app_lang("no_tasks_created_in_this_range") ?: "No tasks created in this range"; ?></div>
                        <div class="tg-list" data-tg-list></div>
                    </div>

                    <div id="timesheet-table-pane" class="is-hidden">
                        <div class="table-responsive">
                            <table id="all-project-timesheet-table" class="display" width="100%">
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div role="tabpanel" class="tab-pane fade" id="timesheet-summary"></div>
            <div role="tabpanel" class="tab-pane fade" id="timesheet-chart"></div>
        </div>
    </div>
</div>

<?php load_js(array("assets/js/timesheet-grouped.js")); ?>
<script type="text/javascript">
    $(document).ready(function () {
        var endTimeVisibility = true;
<?php if (get_setting("users_can_input_only_total_hours_instead_of_period")) { ?>
            endTimeVisibility = false;
<?php } ?>

        var optionVisibility = false;
<?php if ($login_user->user_type === "staff" && ($login_user->is_admin || get_array_value($login_user->permissions, "timesheet_manage_permission"))) { ?>
            optionVisibility = true;
<?php } ?>

        var membersOptions = <?php echo $members_dropdown; ?>;
        var projectsOptions = <?php echo $projects_dropdown; ?>;
        var clientsOptions = <?php echo isset($clients_dropdown) ? $clients_dropdown : '[]'; ?>;

        function fillSelect($el, options, placeholder) {
            if (!$el.length) return;
            $el.empty();
            $el.append($("<option>").val("").text(placeholder));
            (options || []).forEach(function (o) {
                if (o.id === "" || o.id === undefined) return;
                $el.append($("<option>").val(o.id).text(o.text));
            });
        }
        fillSelect($("#tg-user-id"), membersOptions, "- <?php echo app_lang('member'); ?> -");
        fillSelect($("#tg-project-id"), projectsOptions, "- <?php echo app_lang('project'); ?> -");
        fillSelect($("#tg-client-id"), clientsOptions, "- <?php echo app_lang('client'); ?> -");

        // setDatePicker requires a selector string (not a jQuery object)
        if (typeof setDatePicker === "function") {
            setDatePicker("[name='tg_start_date'], [name='tg_end_date']");
        }

        // Init grouped view BEFORE appTable so a table init error cannot blank the Details pane
        var tg = new TimesheetGrouped({
            root: "#timesheet-grouped-root",
            projectsUrl: "<?php echo_uri('projects/timesheet_grouped_projects'); ?>",
            membersUrl: "<?php echo_uri('projects/timesheet_grouped_members'); ?>",
            tasksUrl: "<?php echo_uri('projects/timesheet_grouped_member_tasks'); ?>",
            entriesUrl: "<?php echo_uri('projects/timesheet_grouped_time_entries'); ?>",
            taskViewUrl: "<?php echo_uri('projects/task_view'); ?>",
            captionTemplate: <?php echo json_encode(app_lang('timesheet_grouped_caption') ?: 'Tasks created between {from} and {to}. Time shows all time logged on these tasks.'); ?>,
            emptyMessage: <?php echo json_encode(app_lang('no_tasks_created_in_this_range') ?: 'No tasks created in this range'); ?>,
            errorMessage: <?php echo json_encode(app_lang('error_occurred') ?: 'Something went wrong. Please try again.'); ?>
        });
        tg.setMode("grouped");
        tg.reload();

        $("#all-project-timesheet-table").appTable({
            source: '<?php echo_uri("projects/timesheet_list_data/") ?>',
            filterDropdown: [
                {name: "user_id", class: "w200", options: <?php echo $members_dropdown; ?>},
                {name: "project_id", class: "w200", options: <?php echo $projects_dropdown; ?>, dependency: ["client_id"], dataSource: '<?php echo_uri("projects/get_projects_of_selected_client_for_filter") ?>', selfDependency: true}
<?php if ($login_user->is_admin || get_array_value($login_user->permissions, "client")) { ?>
                    , {name: "client_id", class: "w200", options: <?php echo $clients_dropdown; ?>, dependent: ["project_id"]}
<?php } ?>
                , <?php echo $custom_field_filters; ?>
            ],

            rangeDatepicker: [{startDate: {name: "start_date", value: moment().format("YYYY-MM-DD")}, endDate: {name: "end_date", value: moment().format("YYYY-MM-DD")}, showClearButton: true}],
            columns: [
                {title: "<?php echo app_lang('member') ?>", order_by: "member_name"},
                {title: "<?php echo app_lang('project') ?>", order_by: "project"},
                {title: "<?php echo app_lang('client') ?>", order_by: "client"},
                {title: "<?php echo app_lang('task') ?>", order_by: "task_title"},
                {visible: false, searchable: false, order_by: "start_time"},
                {title: "<?php echo get_setting("users_can_input_only_total_hours_instead_of_period") ? app_lang("date") : app_lang('start_time') ?>", "iDataSort": 4, order_by: "start_time"},
                {visible: false, searchable: false, order_by: "end_time"},
                {title: "<?php echo app_lang('end_time') ?>", "iDataSort": 6, visible: endTimeVisibility, order_by: "end_time"},
                {title: "<?php echo app_lang('total') ?>", "class": "text-right"},
                {visible: false, title: "<?php echo app_lang('hours') ?>", "class": "text-right"},
                {title: '<i data-feather="message-circle" class="icon-16"></i>', "class": "text-center w50"},
                // Phase 20 task cols — ID & Status hidden by default (still in column menu + Excel/Print)
                {title: "<?php echo app_lang('id') ?>", visible: false},
                {title: "<?php echo app_lang('selected_month_task_points') ?>"},
                {title: "<?php echo app_lang('start_date') ?>"},
                {title: "<?php echo app_lang('deadline') ?>"},
                {title: "<?php echo app_lang('assigned_to') ?>"},
                {title: "<?php echo app_lang('collaborators') ?>"},
                {title: "<?php echo app_lang('status') ?>", visible: false},
                {title: "<?php echo app_lang('created_at') ?>"},
                {title: "<?php echo app_lang('gained_points') ?>"}
<?php echo $custom_field_headers; ?>,
                {visible: optionVisibility, title: '<i data-feather="menu" class="icon-16"></i>', "class": "text-center option w100"}
            ],
            columnShowHideOption: true,
            // Excel/Print unchanged from Phase 20 (flat by entry date)
            printColumns: combineCustomFieldsColumns([0, 1, 2, 3, 5, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19], '<?php echo $custom_field_headers; ?>'),
            xlsColumns: combineCustomFieldsColumns([0, 1, 2, 3, 5, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19], '<?php echo $custom_field_headers; ?>'),
            summation: [{column: 8, dataType: 'time'}],
            onInitComplete: function () {
                // Keep Excel/Print buttons visible while Grouped is default
                var $btns = $("#all-project-timesheet-table_wrapper").find(".dt-buttons, .buttons-excel, .buttons-print").first().closest(".dt-buttons");
                if (!$btns.length) {
                    $btns = $("#all-project-timesheet-table_wrapper .dt-buttons");
                }
                if ($btns.length) {
                    $("#tg-export-buttons-host").append($btns);
                }
            }
        });

        // Fallback: move Excel/Print into toolbar if onInitComplete timing missed them
        setTimeout(function () {
            var $host = $("#tg-export-buttons-host");
            if ($host.children().length) return;
            var $btns = $("#all-project-timesheet-table_wrapper .dt-buttons");
            if ($btns.length) {
                $host.append($btns);
            }
        }, 800);
    });
</script>
