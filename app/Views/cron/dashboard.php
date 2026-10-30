<div id="page-content" class="page-wrapper clearfix cron-diagnostic-page">
    <div class="card">
        <div class="page-title clearfix">
            <h1>Cron Diagnostic Dashboard</h1>
            <div class="title-button-group">
                <button type="button" class="btn btn-default" id="cron-refresh-status"><i data-feather="refresh-cw" class="icon-16"></i> Refresh status</button>
            </div>
        </div>
        <div class="card-body">
            <?php if (!empty($is_production_host)) { ?>
                <div class="alert alert-warning">
                    This page is limited to administrators on production. Server wget/curl requests to <code>/index.php/cron</code> still run the scheduled cron without login.
                </div>
            <?php } ?>

            <p class="text-off mb15">
                The server scheduler may call this URL every few minutes. The application’s 5-minute gate can skip processing if cron ran too recently.
                The hourly gate can let cron succeed but skip recurring-task creation until one hour has passed.
                An HTTP 200 response does not mean a clone was created.
            </p>
            <div id="cron-live-result" class="mb15"></div>

            <ul class="nav nav-tabs mb15" id="cron-diag-tabs" role="tablist">
                <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-status">Status</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-manual">Run cron</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-test-task">Test task</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-existing">Existing task</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-logs">Logs</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-health">Database</a></li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="tab-status">
                    <div id="cron-status-cards" class="row"></div>
                    <div class="card mt15">
                        <div class="card-header"><strong>What the badges mean</strong></div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <p><span class="badge bg-primary">RUNNING</span> An action is in progress.</p>
                                    <p><span class="badge bg-success">READY</span> The gate is open and the job can run now.</p>
                                    <p><span class="badge bg-secondary">WAITING</span> A time gate is still closed.</p>
                                    <p><span class="badge bg-warning text-dark">SKIPPED</span> The job ran but did not create a clone, or a gate blocked it.</p>
                                </div>
                                <div class="col-md-6">
                                    <p><span class="badge bg-success">SUCCESS</span> The operation completed and the database result was verified.</p>
                                    <p><span class="badge bg-danger">ERROR</span> The operation failed or the parent link is missing.</p>
                                    <p><span class="badge bg-dark">DISABLED</span> Recurring tasks are turned off in settings.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-manual">
                    <div class="card">
                        <div class="card-header"><strong>Manual cron</strong></div>
                        <div class="card-body">
                            <p>This uses the same 5-minute and hourly gates as the real server cron. It is labeled as a diagnostic run in the logs, but it does not bypass production protections.</p>
                            <button type="button" class="btn btn-primary" id="cron-run-now">Run Cron Now</button>
                            <div id="cron-run-result" class="mt15"></div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-test-task">
                    <div class="card">
                        <div class="card-header"><strong>Create a test recurring task</strong></div>
                        <div class="card-body">
                            <?php echo form_open(get_uri("cron/create_test_task"), array("id" => "cron-test-task-form", "class" => "general-form", "role" => "form")); ?>
                            <div class="form-group">
                                <label>Test task title</label>
                                <?php echo form_input(array("name" => "title", "id" => "cron-test-title", "value" => $default_title, "class" => "form-control")); ?>
                            </div>
                            <div class="form-group">
                                <label>Description</label>
                                <?php echo form_textarea(array("name" => "description", "class" => "form-control", "rows" => 3, "value" => "Created from the Cron Diagnostic Dashboard.")); ?>
                            </div>
                            <div class="row">
                                <div class="col-md-6 form-group">
                                    <label>Project</label>
                                    <?php echo form_dropdown("project_id", $projects_dropdown, "", "class='select2 form-control'"); ?>
                                </div>
                                <div class="col-md-6 form-group">
                                    <label>Assigned user</label>
                                    <?php echo form_dropdown("assigned_to", $users_dropdown, "", "class='select2 form-control'"); ?>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 form-group">
                                    <label>Start date</label>
                                    <?php echo form_input(array("name" => "start_date", "id" => "cron-start-date", "value" => subtract_period_from_date(get_today_date(), 1, "days"), "class" => "form-control")); ?>
                                </div>
                                <div class="col-md-6 form-group">
                                    <label>Deadline</label>
                                    <?php echo form_input(array("name" => "deadline", "id" => "cron-deadline", "value" => get_today_date(), "class" => "form-control")); ?>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="recurring" id="cron-recurring" value="1" checked>
                                    Recurring enabled
                                </label>
                            </div>
                            <div class="row">
                                <div class="col-md-4 form-group">
                                    <label>Repeat every</label>
                                    <?php echo form_input(array("name" => "repeat_every", "value" => "1", "class" => "form-control", "type" => "number", "min" => "1")); ?>
                                </div>
                                <div class="col-md-4 form-group">
                                    <label>Repeat type</label>
                                    <?php echo form_dropdown("repeat_type", array("days" => "days", "weeks" => "weeks", "months" => "months", "years" => "years"), "days", "class='form-control'"); ?>
                                </div>
                                <div class="col-md-4 form-group">
                                    <label>Number of cycles (0 = unlimited)</label>
                                    <?php echo form_input(array("name" => "no_of_cycles", "value" => "0", "class" => "form-control", "type" => "number", "min" => "0")); ?>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Next recurring date</label>
                                <?php echo form_input(array("name" => "next_recurring_date", "id" => "cron-next-date", "value" => get_today_date(), "class" => "form-control")); ?>
                                <small class="text-off">Set this to today or earlier so the diagnostic test can create a clone immediately.</small>
                            </div>
                            <button type="submit" class="btn btn-primary">Create test task</button>
                            <?php echo form_close(); ?>
                            <?php if (empty($status["recurring_enabled"])) { ?>
                                <div class="alert alert-warning mt15">Recurring tasks are disabled. Enable them in Settings → Tasks before a clone can be created.</div>
                            <?php } ?>
                            <div id="cron-created-task" class="mt15"></div>
                            <div id="cron-created-result" class="mt15"></div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-existing">
                    <div class="card">
                        <div class="card-header"><strong>Test an existing task</strong></div>
                        <div class="card-body">
                            <div class="form-group">
                                <label>Task ID</label>
                                <div class="input-group">
                                    <input type="number" id="cron-existing-task-id" class="form-control" min="1" placeholder="Enter task ID">
                                    <button type="button" class="btn btn-default" id="cron-load-task">Load task</button>
                                </div>
                            </div>
                            <div id="cron-existing-task"></div>
                            <div id="cron-existing-result" class="mt15"></div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-logs">
                    <div class="card">
                        <div class="card-header">
                            <strong>Diagnostic logs</strong>
                            <div class="float-end">
                                <button type="button" class="btn btn-default btn-sm" id="cron-refresh-logs">Refresh logs</button>
                                <button type="button" class="btn btn-default btn-sm" id="cron-copy-logs">Copy logs</button>
                                <button type="button" class="btn btn-danger btn-sm" id="cron-clear-logs">Clear diagnostic logs</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="mb15">
                                <button type="button" class="btn btn-default btn-sm cron-log-filter active" data-filter="all">All</button>
                                <button type="button" class="btn btn-default btn-sm cron-log-filter" data-filter="success">Success</button>
                                <button type="button" class="btn btn-default btn-sm cron-log-filter" data-filter="warning">Warning</button>
                                <button type="button" class="btn btn-default btn-sm cron-log-filter" data-filter="error">Error</button>
                                <button type="button" class="btn btn-default btn-sm cron-log-filter" data-filter="recurring">Recurring tasks</button>
                                <button type="button" class="btn btn-default btn-sm cron-log-filter" data-filter="clone">Clone creation</button>
                                <button type="button" class="btn btn-default btn-sm cron-log-filter" data-filter="cron_gate">Cron gate</button>
                                <button type="button" class="btn btn-default btn-sm cron-log-filter" data-filter="database">Database</button>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-striped" id="cron-log-table">
                                    <thead>
                                        <tr>
                                            <th>Timestamp</th>
                                            <th>Level</th>
                                            <th>Event</th>
                                            <th>Task ID</th>
                                            <th>Parent task ID</th>
                                            <th>Clone ID</th>
                                            <th>Message</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-health">
                    <div class="card">
                        <div class="card-header"><strong>Database health</strong></div>
                        <div class="card-body">
                            <div id="cron-health-list"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .cron-diagnostic-page .cron-status-card { min-height: 130px; }
    .cron-diagnostic-page .copy-id { cursor: pointer; border-bottom: 1px dashed #888; }
    .cron-diagnostic-page .verify-error { color: #dc3545; font-weight: 600; }
    .cron-diagnostic-page .verify-ok { color: #198754; font-weight: 600; }
    .cron-diagnostic-page .cron-summary { border-left: 4px solid #0d6efd; padding: 12px 15px; background: #f8f9fa; }
    .cron-diagnostic-page .cron-summary.success { border-left-color: #198754; }
    .cron-diagnostic-page .cron-summary.warning { border-left-color: #ffc107; }
    .cron-diagnostic-page .cron-summary.error { border-left-color: #dc3545; }
    .cron-diagnostic-page .cron-log-filter.active { background: #0d6efd; color: #fff; }
</style>

<script>
    $(document).ready(function () {
        var csrfName = "<?php echo csrf_token(); ?>";
        var csrfHash = "<?php echo csrf_hash(); ?>";
        var urls = {
            status: "<?php echo get_uri("cron/status"); ?>",
            run: "<?php echo get_uri("cron/run"); ?>",
            createTask: "<?php echo get_uri("cron/create_test_task"); ?>",
            taskInfo: "<?php echo get_uri("cron/task_info"); ?>",
            testRecurring: "<?php echo get_uri("cron/test_recurring"); ?>",
            logs: "<?php echo get_uri("cron/logs"); ?>",
            logDetail: "<?php echo get_uri("cron/log_detail"); ?>",
            refreshTask: "<?php echo get_uri("cron/refresh_task"); ?>",
            clearLogs: "<?php echo get_uri("cron/clear_diagnostic_logs"); ?>"
        };
        var localDebug = <?php echo !empty($local_debug) ? "true" : "false"; ?>;
        var initialStatus = <?php echo json_encode($status); ?>;
        var initialHealth = <?php echo json_encode($health); ?>;
        var initialLogs = <?php echo json_encode($logs); ?>;
        var currentFilter = "all";
        var lastLogs = initialLogs || [];
        var selectedTaskId = 0;
        var running = false;

        $(".select2").select2();
        setDatePicker("#cron-start-date, #cron-deadline, #cron-next-date");

        function badge(text, type) {
            var map = {
                READY: "bg-success",
                SUCCESS: "bg-success",
                FAILED: "bg-danger",
                RUNNING: "bg-primary",
                WAITING: "bg-secondary",
                SKIPPED: "bg-warning text-dark",
                WARNING: "bg-warning text-dark",
                ERROR: "bg-danger",
                DISABLED: "bg-dark",
                OPEN: "bg-success",
                CLOSED: "bg-secondary",
                ENABLED: "bg-success"
            };
            return '<span class="badge ' + (map[text] || "bg-secondary") + '">' + escapeHtml(text) + '</span>';
        }

        function escapeHtml(value) {
            return $("<div>").text(value == null ? "" : value).html();
        }

        function copyText(value) {
            if (!value) {
                return;
            }
            if (navigator.clipboard) {
                navigator.clipboard.writeText(String(value));
            }
            appAlert.success("Copied " + value, {duration: 2000});
        }

        function copyIdHtml(value) {
            if (value === null || value === "" || typeof value === "undefined") {
                return "-";
            }
            return '<span class="copy-id" data-copy="' + escapeHtml(value) + '">' + escapeHtml(value) + '</span>';
        }

        function secondsLabel(seconds) {
            if (seconds === null || typeof seconds === "undefined") {
                return "Never";
            }
            return seconds + " seconds";
        }

        function renderStatus(status) {
            var cards = [
                ["Cron endpoint", status.endpoint_status, status.endpoint],
                ["Last cron execution", status.last_cron_result ? status.last_cron_result.toUpperCase() : "WAITING", status.last_cron_job_time_local],
                ["Last hourly job", status.hourly_gate_open ? "READY" : "WAITING", status.last_hourly_job_time_local],
                ["Seconds since last cron", status.five_minute_gate_open ? "READY" : "WAITING", secondsLabel(status.seconds_since_last_cron)],
                ["Seconds since last hourly job", status.hourly_gate_open ? "READY" : "WAITING", secondsLabel(status.seconds_since_last_hourly)],
                ["5-minute gate", status.five_minute_gate_open ? "OPEN" : "CLOSED", status.five_minute_gate_open ? "Ready to process" : "Waiting for 5 minutes"],
                ["Hourly gate", status.hourly_gate_open ? "OPEN" : "CLOSED", status.hourly_gate_open ? "Recurring tasks can run" : "Recurring tasks wait for 1 hour"],
                ["Application timezone", "READY", status.application_timezone],
                ["PHP / server timezone", "READY", status.php_timezone],
                ["Current UTC time", "READY", status.utc_time],
                ["PHP time", "READY", status.php_time],
                ["Application-local time", "READY", status.application_local_time],
                ["Application date", "READY", status.application_date],
                ["Recurring comparison date", "READY", status.recurring_query_date + (status.create_recurring_tasks_before ? " (includes " + status.create_recurring_tasks_before + " day lead)" : "")],
                ["Recurring tasks", status.recurring_enabled ? "ENABLED" : "DISABLED", status.recurring_enabled ? "Setting is on" : "Setting is off"],
                ["Eligible recurring tasks", status.eligible_recurring_tasks > 0 ? "READY" : "WAITING", String(status.eligible_recurring_tasks)],
                ["Clones created last run", status.clones_created_last_run > 0 ? "SUCCESS" : "SKIPPED", String(status.clones_created_last_run)],
                ["Last cron result", (status.last_cron_result || "never").toUpperCase(), status.last_cron_skip_reason || ""]
            ];
            var html = "";
            cards.forEach(function (card) {
                html += '<div class="col-md-4 col-sm-6 mb15"><div class="card cron-status-card"><div class="card-body">';
                html += '<div class="mb10">' + badge(card[1]) + '</div>';
                html += '<div class="strong">' + escapeHtml(card[0]) + '</div>';
                html += '<div class="text-off">' + escapeHtml(card[2]) + '</div>';
                html += '</div></div></div>';
            });
            $("#cron-status-cards").html(html);
        }

        function renderHealth(health) {
            var html = '<ul class="list-group">';
            (health || []).forEach(function (item) {
                html += '<li class="list-group-item d-flex justify-content-between align-items-center">';
                html += '<span>' + escapeHtml(item.label) + '<br><small class="text-off">' + escapeHtml(item.detail) + '</small></span>';
                html += item.ok ? badge("SUCCESS") : badge("ERROR");
                html += '</li>';
            });
            html += '</ul>';
            $("#cron-health-list").html(html);
        }

        function renderLogs(logs) {
            lastLogs = logs || [];
            var body = "";
            lastLogs.forEach(function (log) {
                body += "<tr>";
                body += "<td>" + escapeHtml(log.local_time || log.timestamp) + "</td>";
                body += "<td>" + badge((log.level || "").toUpperCase()) + "</td>";
                body += "<td>" + escapeHtml(log.event) + "</td>";
                body += "<td>" + copyIdHtml(log.task_id) + "</td>";
                body += "<td>" + copyIdHtml(log.parent_task_id) + "</td>";
                body += "<td>" + copyIdHtml(log.clone_id) + "</td>";
                body += "<td>" + escapeHtml(log.message) + "</td>";
                body += '<td><button type="button" class="btn btn-default btn-sm cron-log-detail" data-id="' + escapeHtml(log.id) + '">View details</button></td>';
                body += "</tr>";
            });
            if (!body) {
                body = '<tr><td colspan="8" class="text-off">No diagnostic logs yet.</td></tr>';
            }
            $("#cron-log-table tbody").html(body);
        }

        function taskFieldsHtml(task) {
            if (!task) {
                return "";
            }
            var fields = [
                ["ID", task.id],
                ["Title", task.title],
                ["Recurring", task.recurring],
                ["Repeat every", task.repeat_every],
                ["Repeat type", task.repeat_type],
                ["Number of cycles", task.no_of_cycles],
                ["Number of completed cycles", task.no_of_cycles_completed],
                ["Start date", task.start_date],
                ["Deadline", task.deadline],
                ["Next recurring date", task.next_recurring_date],
                ["Parent task ID", task.parent_task_id],
                ["Recurring task ID", task.recurring_task_id],
                ["Deleted status", task.deleted],
                ["Created date", task.created_date]
            ];
            var html = '<div class="table-responsive"><table class="table"><tbody>';
            fields.forEach(function (row) {
                var extra = (row[0] === "Parent task ID" && (!row[1] || row[1] == 0)) ? ' class="verify-error"' : "";
                html += "<tr" + extra + "><th>" + escapeHtml(row[0]) + "</th><td>" + copyIdHtml(row[1]) + "</td></tr>";
            });
            html += "</tbody></table></div>";
            if (task.task_url) {
                html += '<p><a href="' + task.task_url + '" target="_blank">Open task</a></p>';
            }
            return html;
        }

        function previewHtml(preview, extra) {
            extra = extra || {};
            var html = '<div class="table-responsive"><table class="table">';
            html += row("Original task ID", extra.task_id || preview.parent_task_id);
            html += row("Parent title", preview.parent_title);
            html += row("Parent start date", preview.parent_start_date);
            html += row("Parent deadline", preview.parent_deadline);
            html += row("Parent next recurring date", preview.parent_next_recurring_date);
            html += row("Current application date", extra.application_date);
            html += row("Calculated query date", extra.query_date || preview.query_date);
            html += row("Task should be eligible", extra.eligible ? "Yes" : "No");
            html += row("Hourly gate bypassed for diagnostic mode", extra.hourly_gate_bypassed ? "Yes" : "No");
            html += row("Repeat interval", preview.repeat_interval);
            html += row("Calculated clone start date", preview.clone_start_date);
            html += row("Calculated clone deadline", preview.clone_deadline);
            html += row("Calculated next recurring date after cloning", preview.next_recurring_date_after);
            html += row("Expected parent_task_id", preview.expected_parent_task_id);
            html += row("Expected recurring_task_id", preview.expected_recurring_task_id);
            html += "</table></div>";
            return html;
        }

        function row(label, value) {
            return "<tr><th>" + escapeHtml(label) + "</th><td>" + copyIdHtml(value) + "</td></tr>";
        }

        function verificationTable(verification) {
            if (!verification) {
                return "";
            }
            var html = '<div class="cron-summary ' + (verification.result || "").toLowerCase() + '">';
            html += badge(verification.result || "ERROR") + " <strong>" + escapeHtml(verification.message) + "</strong></div>";
            if (verification.rows && verification.rows.length) {
                html += '<div class="table-responsive mt15"><table class="table"><thead><tr><th>Field</th><th>Original task</th><th>New clone</th><th>Expected result</th><th>Status</th></tr></thead><tbody>';
                verification.rows.forEach(function (rowItem) {
                    var cls = rowItem.status === "ok" ? "verify-ok" : "verify-error";
                    var mark = rowItem.field === "parent_task_id" && (!rowItem.clone || rowItem.clone == 0) ? " PARENT LINK MISSING" : "";
                    html += "<tr class='" + cls + "'>";
                    html += "<td>" + escapeHtml(rowItem.field) + "</td>";
                    html += "<td>" + copyIdHtml(rowItem.original) + "</td>";
                    html += "<td>" + copyIdHtml(rowItem.clone) + "</td>";
                    html += "<td>" + copyIdHtml(rowItem.expected) + "</td>";
                    html += "<td>" + escapeHtml((rowItem.status || "").toUpperCase() + mark) + "</td>";
                    html += "</tr>";
                });
                html += "</tbody></table></div>";
            }
            return html;
        }

        function renderResultCard(card) {
            if (!card) {
                return "";
            }
            var html = '<div class="cron-summary ' + (card.result || "").toLowerCase() + '">';
            html += '<p>' + badge(card.result || "FAILED") + ' <strong>' + escapeHtml(card.title || card.result) + '</strong></p>';
            html += '<p>' + escapeHtml(card.gates_bypassed || "Diagnostic test: gates bypassed for this single task") + '</p>';
            html += '<p>' + escapeHtml(card.reason || "") + '</p>';
            html += '<div class="table-responsive"><table class="table mb0">';
            html += row("Parent task ID", card.parent_task_id);
            html += row("Clone task ID", card.clone_task_id);
            html += row("Clone parent_task_id", card.clone_parent_task_id);
            html += row("Clone recurring_task_id", card.clone_recurring_task_id);
            html += row("Clone start date", card.clone_start_date);
            html += row("Clone deadline", card.clone_deadline);
            html += row("Clone next recurring date", card.clone_next_recurring_date);
            html += row("Parent next recurring date after processing", card.parent_next_recurring_date_after);
            html += row("Diagnostic run ID", card.run_id);
            html += "</table></div></div>";
            return html;
        }

        function renderActionResult(target, data) {
            var html = renderResultCard(data.result_card);
            html += '<div class="cron-summary ' + (data.result || "").toLowerCase() + '">';
            html += '<p>' + badge(data.result || "ERROR") + ' <strong>' + escapeHtml(data.label || data.mode || "Result") + '</strong></p>';
            html += '<p>' + escapeHtml(data.reason || "") + '</p>';
            html += '<p>Start: ' + escapeHtml(data.start_time_local || data.start_time) + ' &nbsp; End: ' + escapeHtml(data.end_time_local || data.end_time) + ' &nbsp; Duration: ' + escapeHtml(data.duration_ms) + ' ms</p>';
            html += '<p>5-minute gate blocked: ' + (data.five_minute_gate_blocked ? "Yes" : "No") + '</p>';
            if (typeof data.hourly_gate_blocked !== "undefined") {
                html += '<p>Hourly gate blocked recurring tasks: ' + (data.hourly_gate_blocked ? "Yes" : "No") + '</p>';
            }
            if (data.hourly_gate_bypassed) {
                html += '<p>Diagnostic test: gates bypassed for this single task</p>';
            }
            html += '<p>Recurring tasks found: ' + escapeHtml(data.found) + ' &nbsp; Clones created: ' + escapeHtml(data.created) + ' &nbsp; Failed: ' + escapeHtml(data.failed) + ' &nbsp; Skipped: ' + escapeHtml(data.skipped) + '</p>';
            if (data.clone_ids && data.clone_ids.length) {
                html += "<p>Created clones:</p><ul>";
                data.clone_ids.forEach(function (item) {
                    html += "<li>Clone ID " + copyIdHtml(item.clone_id) + " from parent " + copyIdHtml(item.parent_task_id) + "</li>";
                });
                html += "</ul>";
            }
            html += "</div>";
            if (data.before && data.after) {
                html += "<h5 class='mt15'>Database values before and after</h5><div class='row'>";
                html += "<div class='col-md-6'><h6>Before</h6>" + taskFieldsHtml(data.before) + "</div>";
                html += "<div class='col-md-6'><h6>After</h6>" + taskFieldsHtml(data.after) + "</div>";
                html += "</div>";
            }
            if (data.verification) {
                html += verificationTable(data.verification);
            } else if (data.items) {
                data.items.forEach(function (item) {
                    if (item.verification) {
                        html += verificationTable(item.verification);
                    } else if (item.reason) {
                        html += '<p class="mt10">' + escapeHtml(item.reason) + '</p>';
                    }
                });
            }
            $("#cron-live-result").html(html);
            if (target) {
                $(target).html(html);
            }
            $("html, body").animate({scrollTop: $("#cron-live-result").offset().top - 80}, 200);
            if (data.status) {
                renderStatus(data.status);
            }
            if (data.health) {
                renderHealth(data.health);
            }
            if (data.logs) {
                renderLogs(data.logs);
            }
        }

        function withCsrf(data) {
            data = data || {};
            if (typeof data === "string") {
                return data + (data ? "&" : "") + encodeURIComponent(csrfName) + "=" + encodeURIComponent(csrfHash);
            }
            data[csrfName] = csrfHash;
            return data;
        }

        function postAction(url, data, button, target) {
            if (running) {
                return;
            }
            running = true;
            var $button = $(button);
            var original = $button.html();
            $button.prop("disabled", true).html("RUNNING...");
            appLoader.show();
            $.ajax({
                url: url,
                type: "POST",
                dataType: "json",
                data: withCsrf(data),
                success: function (response) {
                    appLoader.hide();
                    running = false;
                    $button.prop("disabled", false).html(original);
                    if (!response || response.success === false) {
                        appAlert.error((response && response.message) ? response.message : "The request failed.");
                        return;
                    }
                    if (target) {
                        renderActionResult(target, response);
                    }
                    if (response.message && !target) {
                        appAlert.success(response.message);
                    }
                    if (response.status) {
                        renderStatus(response.status);
                    }
                    if (response.logs) {
                        renderLogs(response.logs);
                    }
                },
                error: function (xhr) {
                    appLoader.hide();
                    running = false;
                    $button.prop("disabled", false).html(original);
                    var message = "The request failed.";
                    try {
                        var parsed = JSON.parse(xhr.responseText);
                        if (parsed && (parsed.message || parsed.reason)) {
                            message = parsed.message || parsed.reason;
                        }
                    } catch (ignore) {
                        if (xhr.status) {
                            message = "The request failed (HTTP " + xhr.status + ").";
                        }
                    }
                    appAlert.error(message);
                    $("#cron-live-result").html('<div class="cron-summary error">' + badge("FAILED") + " " + escapeHtml(message) + "</div>");
                }
            });
        }

        function loadLogs(filter) {
            currentFilter = filter || "all";
            $(".cron-log-filter").removeClass("active");
            $('.cron-log-filter[data-filter="' + currentFilter + '"]').addClass("active");
            $.getJSON(urls.logs, {filter: currentFilter}, function (response) {
                if (response && response.logs) {
                    renderLogs(response.logs);
                }
            });
        }

        function renderCreatedTask(task) {
            selectedTaskId = parseInt(task.id, 10);
            $("#cron-existing-task-id").val(task.id);
            var html = "<h5>Created task</h5>" + taskFieldsHtml(task);
            html += '<input type="hidden" id="cron-created-task-id" name="task_id" value="' + escapeHtml(task.id) + '">';
            html += '<button type="button" class="btn btn-primary" id="cron-run-against-created" data-task-id="' + escapeHtml(task.id) + '">Run Test Against This Task</button> ';
            html += '<button type="button" class="btn btn-default cron-refresh-task" data-task-id="' + escapeHtml(task.id) + '">Refresh task data</button>';
            $("#cron-created-task").html(html);
        }

        function renderExistingTask(response) {
            selectedTaskId = response.task.id;
            var html = taskFieldsHtml(response.task);
            html += "<h5>Before creating a clone</h5>";
            html += previewHtml(response.preview, response);
            html += '<button type="button" class="btn btn-primary" id="cron-run-existing" data-task-id="' + escapeHtml(response.task.id) + '">Run Recurring Test</button> ';
            html += '<button type="button" class="btn btn-default cron-refresh-task" data-task-id="' + escapeHtml(response.task.id) + '">Refresh task data</button>';
            $("#cron-existing-task").html(html);
        }

        $("#cron-refresh-status").on("click", function () {
            $.getJSON(urls.status, function (response) {
                if (response.status) {
                    renderStatus(response.status);
                }
                if (response.health) {
                    renderHealth(response.health);
                }
            });
        });

        $("#cron-run-now").on("click", function () {
            postAction(urls.run, {}, this, "#cron-run-result");
        });

        $("#cron-test-task-form").on("submit", function (e) {
            e.preventDefault();
            if (!confirm("Create this diagnostic test task?")) {
                return;
            }
            var $form = $(this);
            var $button = $form.find("button[type=submit]");
            if (running) {
                return;
            }
            running = true;
            var original = $button.html();
            $button.prop("disabled", true).html("RUNNING...");
            appLoader.show();
            $.ajax({
                url: urls.createTask,
                type: "POST",
                dataType: "json",
                data: withCsrf($form.serialize()),
                success: function (response) {
                    appLoader.hide();
                    running = false;
                    $button.prop("disabled", false).html(original);
                    if (response && response.success && response.task) {
                        renderCreatedTask(response.task);
                        appAlert.success(response.message || "Test task created.");
                    } else {
                        appAlert.error((response && response.message) ? response.message : "Could not create the test task.");
                    }
                },
                error: function (xhr) {
                    appLoader.hide();
                    running = false;
                    $button.prop("disabled", false).html(original);
                    var message = "The request failed.";
                    try {
                        var parsed = JSON.parse(xhr.responseText);
                        if (parsed && parsed.message) {
                            message = parsed.message;
                        }
                    } catch (ignore) {
                        if (xhr.status) {
                            message = "The request failed (" + xhr.status + ").";
                        }
                    }
                    appAlert.error(message);
                }
            });
        });

        function runDiagnosticTest(taskId, button, resultTarget) {
            taskId = parseInt(taskId, 10);
            if (!taskId) {
                appAlert.error("The task ID is missing. Create or load a task first.");
                $("#cron-live-result").html('<div class="cron-summary error">' + badge("FAILED") + " The task ID was empty, so the test was not sent.</div>");
                return;
            }
            selectedTaskId = taskId;
            $.ajax({
                url: urls.taskInfo,
                type: "GET",
                dataType: "json",
                data: {task_id: taskId},
                success: function (response) {
                    if (!response || !response.success) {
                        var loadMessage = (response && response.message) ? response.message : "Could not load the task.";
                        appAlert.error(loadMessage);
                        $("#cron-live-result").html('<div class="cron-summary error">' + badge("FAILED") + " " + escapeHtml(loadMessage) + "</div>");
                        return;
                    }
                    var message = "Run a diagnostic recurring test now?\n\n";
                    message += "Original task ID: " + response.task.id + "\n";
                    message += "Current next recurring date: " + response.task.next_recurring_date + "\n";
                    message += "Calculated query date: " + response.query_date + "\n";
                    message += "Current application date: " + response.application_date + "\n";
                    message += "Should be eligible: " + (response.eligible ? "Yes" : "No") + "\n";
                    message += "Diagnostic test: gates bypassed for this single task\n\n";
                    message += "Expected parent_task_id: " + response.preview.expected_parent_task_id + "\n";
                    message += "Expected clone start: " + response.preview.clone_start_date;
                    if (!confirm(message)) {
                        return;
                    }
                    postAction(urls.testRecurring, {task_id: taskId}, button, resultTarget);
                },
                error: function (xhr) {
                    var message = "Could not load task #" + taskId + " (HTTP " + xhr.status + ").";
                    appAlert.error(message);
                    $("#cron-live-result").html('<div class="cron-summary error">' + badge("FAILED") + " " + escapeHtml(message) + "</div>");
                }
            });
        }

        $(document).on("click", "#cron-run-against-created, #cron-run-existing", function () {
            var taskId = $(this).attr("data-task-id") || $("#cron-created-task-id").val() || selectedTaskId;
            var resultTarget = $(this).attr("id") === "cron-run-against-created" ? "#cron-created-result" : "#cron-existing-result";
            runDiagnosticTest(taskId, this, resultTarget);
        });

        $(document).on("click", ".cron-refresh-task", function () {
            var taskId = $(this).attr("data-task-id") || selectedTaskId;
            $.getJSON(urls.refreshTask, {task_id: taskId}, function (response) {
                if (!response || !response.success) {
                    appAlert.error((response && response.message) ? response.message : "Could not refresh the task.");
                    return;
                }
                selectedTaskId = response.task.id;
                if ($("#cron-created-task").children().length) {
                    renderCreatedTask(response.task);
                }
                if (response.clones && response.clones.length) {
                    var cloneHtml = "<h5>Reloaded clones</h5>";
                    response.clones.forEach(function (clone) {
                        cloneHtml += taskFieldsHtml(clone);
                    });
                    $("#cron-live-result").append(cloneHtml);
                }
                appAlert.success("Task data reloaded from the database.");
            });
        });

        $(document).on("click", ".cron-log-detail", function () {
            var id = $(this).data("id");
            $.getJSON(urls.logDetail, {id: id}, function (response) {
                if (!response || !response.success) {
                    appAlert.error((response && response.message) ? response.message : "Log not found.");
                    return;
                }
                var log = response.log;
                var html = "<p><strong>" + escapeHtml(log.local_time || log.timestamp) + "</strong> " + badge((log.level || "").toUpperCase()) + "</p>";
                html += "<p>Event: " + escapeHtml(log.event) + "</p>";
                html += "<p>Task ID: " + copyIdHtml(log.task_id) + " &nbsp; Parent: " + copyIdHtml(log.parent_task_id) + " &nbsp; Clone: " + copyIdHtml(log.clone_id) + "</p>";
                html += "<p>" + escapeHtml(log.message) + "</p>";
                if (localDebug && log.context && Object.keys(log.context).length) {
                    html += "<pre class='bg-light p10'>" + escapeHtml(JSON.stringify(log.context, null, 2)) + "</pre>";
                }
                $("#cron-log-detail-body").html(html);
                var modal = document.getElementById("cron-log-detail-modal");
                if (window.bootstrap && bootstrap.Modal) {
                    bootstrap.Modal.getOrCreateInstance(modal).show();
                } else {
                    $(modal).modal("show");
                }
            });
        });

        $("#cron-load-task").on("click", function () {
            var taskId = $("#cron-existing-task-id").val();
            $.getJSON(urls.taskInfo, {task_id: taskId}, function (response) {
                if (!response || !response.success) {
                    appAlert.error((response && response.message) ? response.message : "Could not load the task.");
                    return;
                }
                renderExistingTask(response);
            });
        });

        $(".cron-log-filter").on("click", function () {
            loadLogs($(this).data("filter"));
        });

        $("#cron-refresh-logs").on("click", function () {
            loadLogs(currentFilter);
        });

        $("#cron-copy-logs").on("click", function () {
            var text = lastLogs.map(function (log) {
                return [log.local_time || log.timestamp, log.level, log.event, log.task_id, log.parent_task_id, log.clone_id, log.message].join(" | ");
            }).join("\n");
            copyText(text || "No logs");
        });

        $("#cron-clear-logs").on("click", function () {
            if (!confirm("Clear only diagnostic logs created by this dashboard?")) {
                return;
            }
            postAction(urls.clearLogs, {}, this, null);
            setTimeout(function () {
                loadLogs(currentFilter);
            }, 400);
        });

        $(document).on("click", ".copy-id", function () {
            copyText($(this).data("copy"));
        });

        renderStatus(initialStatus);
        renderHealth(initialHealth);
        renderLogs(initialLogs);
    });
</script>
<div class="modal fade" id="cron-log-detail-modal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Diagnostic log details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="cron-log-detail-body"></div>
        </div>
    </div>
</div>
