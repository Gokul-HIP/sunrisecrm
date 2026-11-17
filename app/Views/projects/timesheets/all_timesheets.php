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

                        <div class="tg-filters" data-tg-filters>
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
                            <span class="tg-dt-buttons-host" id="tg-export-buttons-host">
                                <button type="button" class="tg-btn" data-tg-export="xlsx"><?php echo app_lang("excel") ?: "Excel"; ?></button>
                                <button type="button" class="tg-btn" data-tg-export="print"><?php echo app_lang("print") ?: "Print"; ?></button>
                            </span>
                            <span class="tg-excel-tip" title="<?php echo app_lang('excel_exports_current_filters') ?: 'Excel and Print use the current toolbar filters (task created date)'; ?>">
                                <span class="tg-tip-icon" aria-hidden="true">i</span>
                                <span class="d-none d-md-inline"><?php echo app_lang('excel_exports_current_filters') ?: 'Exports match the current toolbar filters'; ?></span>
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

        if (typeof setDatePicker === "function") {
            setDatePicker("[name='tg_start_date'], [name='tg_end_date']");
        }

        var tg = new TimesheetGrouped({
            root: "#timesheet-grouped-root",
            projectsUrl: "<?php echo_uri('projects/timesheet_grouped_projects'); ?>",
            membersUrl: "<?php echo_uri('projects/timesheet_grouped_members'); ?>",
            tasksUrl: "<?php echo_uri('projects/timesheet_grouped_member_tasks'); ?>",
            entriesUrl: "<?php echo_uri('projects/timesheet_grouped_time_entries'); ?>",
            exportUrl: "<?php echo_uri('projects/timesheet_grouped_export'); ?>",
            taskViewUrl: "<?php echo_uri('projects/task_view'); ?>",
            captionTemplate: <?php echo json_encode(app_lang('timesheet_grouped_caption') ?: 'Tasks created between {from} and {to}. Time shows all time logged on these tasks.'); ?>,
            emptyMessage: <?php echo json_encode(app_lang('no_tasks_created_in_this_range') ?: 'No tasks created in this range'); ?>,
            errorMessage: <?php echo json_encode(app_lang('error_occurred') ?: 'Something went wrong. Please try again.'); ?>,
            onFiltersApplied: function (filters) {
                // Table uses the same toolbar filters (live after Apply)
                if (window.InstanceCollection && window.InstanceCollection["all-project-timesheet-table"]) {
                    var inst = window.InstanceCollection["all-project-timesheet-table"];
                    inst.filterParams = $.extend({}, inst.filterParams || {}, {
                        start_date: filters.start_date || "",
                        end_date: filters.end_date || "",
                        user_id: filters.user_id || "",
                        project_id: filters.project_id || "",
                        client_id: filters.client_id || "",
                        search: filters.search || ""
                    });
                    $("#all-project-timesheet-table").appTable({reload: true, filterParams: inst.filterParams});
                }
            }
        });
        tg.setMode("grouped");
        tg.reload();

        // Phase 21C: Table source = flat rows from same created-date filters (not entry-date timesheet_list_data)
        var initialFilters = tg.filters();
        $("#all-project-timesheet-table").appTable({
            source: '<?php echo_uri("projects/timesheet_grouped_flat_list_data/") ?>',
            filterParams: {
                start_date: initialFilters.start_date,
                end_date: initialFilters.end_date,
                user_id: initialFilters.user_id,
                project_id: initialFilters.project_id,
                client_id: initialFilters.client_id,
                search: initialFilters.search
            },
            // No separate date/member dropdowns — toolbar is the single filter source
            filterDropdown: [],
            rangeDatepicker: [],
            columns: [
                {title: "<?php echo app_lang('member') ?>"},
                {title: "<?php echo app_lang('project') ?>"},
                {title: "<?php echo app_lang('client') ?>"},
                {title: "<?php echo app_lang('task') ?>"},
                {visible: false, searchable: false},
                {title: "<?php echo get_setting("users_can_input_only_total_hours_instead_of_period") ? app_lang("date") : app_lang('start_time') ?>", "iDataSort": 4},
                {visible: false, searchable: false},
                {title: "<?php echo app_lang('end_time') ?>", "iDataSort": 6, visible: endTimeVisibility},
                {title: "<?php echo app_lang('total') ?>", "class": "text-right"},
                {visible: false, title: "<?php echo app_lang('hours') ?>", "class": "text-right"},
                {title: '<i data-feather="message-circle" class="icon-16"></i>', "class": "text-center w50"},
                {title: "<?php echo app_lang('id') ?>", visible: false},
                {title: "<?php echo app_lang('selected_month_task_points') ?>"},
                {title: "<?php echo app_lang('start_date') ?>"},
                {title: "<?php echo app_lang('deadline') ?>"},
                {title: "<?php echo app_lang('assigned_to') ?>"},
                {title: "<?php echo app_lang('collaborators') ?>"},
                {title: "<?php echo app_lang('status') ?>", visible: false},
                {title: "<?php echo app_lang('created_at') ?>"},
                {title: "<?php echo app_lang('gained_points') ?>"},
                {visible: false, title: '', "class": "option"}
            ],
            columnShowHideOption: true,
            // Excel/Print are toolbar buttons → timesheet_grouped_export (not DataTables Buttons)
            printColumns: [],
            xlsColumns: [],
            summation: [{column: 8, dataType: 'time'}]
        });
    });
</script>
