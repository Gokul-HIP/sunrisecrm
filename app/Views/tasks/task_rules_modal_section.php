<?php
helper('task_rules');

$selected_department = "";
$selected_sub_department = "";
$selected_rule_id = "";
$selected_suggested_points = "";

if (isset($model_info) && is_object($model_info) && !empty($model_info->id) && task_rules_tables_exist()) {
    try {
        $Task_meta_model = model("App\Models\Task_meta_model");
        $task_meta = $Task_meta_model->get_by_task_id($model_info->id);
        if ($task_meta) {
            $selected_department = $task_meta->department;
            $selected_sub_department = $task_meta->sub_department;
            $selected_rule_id = $task_meta->rule_id;
            $selected_suggested_points = $task_meta->suggested_points;
        }
    } catch (\Throwable $e) {
        log_message('error', 'Task rules meta load error: ' . $e->getMessage());
    }
}
?>

<div class="form-group" id="task-rule-dept-section">
    <div class="row">
        <label for="task_department" class="col-md-3"><?php echo app_lang('department'); ?></label>
        <div class="col-md-9">
            <?php
            $dept_options = array("" => "-");
            foreach (task_rules_departments() as $d) {
                $dept_options[$d] = $d;
            }
            echo form_dropdown("task_department", $dept_options, array($selected_department), "id='task_department' class='select2'");
            ?>
            <div id="task-rule-dept-hint" class="text-warning small mt-1" style="display: none; color: #d97706; font-size: 12px; margin-top: 4px;">
                <i data-feather="info" class="icon-14"></i> <?php echo app_lang('choose_department_to_get_points_and_time'); ?>
            </div>
        </div>
    </div>
</div>

<div class="form-group" id="task-rule-sub-dept-section" style="<?php echo ($selected_department === 'Digital Marketing') ? '' : 'display: none;'; ?>">
    <div class="row">
        <label for="task_sub_department" class="col-md-3"><?php echo app_lang('sub_department'); ?></label>
        <div class="col-md-9">
            <?php
            $sub_dept_options = array("" => "-");
            foreach (task_rules_sub_departments() as $sd) {
                $sub_dept_options[$sd] = $sd;
            }
            echo form_dropdown("task_sub_department", $sub_dept_options, array($selected_sub_department), "id='task_sub_department' class='select2'");
            ?>
        </div>
    </div>
</div>

<input type="hidden" name="task_rule_id" id="task_rule_id" value="<?php echo esc($selected_rule_id); ?>" />
<input type="hidden" name="task_suggested_points" id="task_suggested_points" value="<?php echo esc($selected_suggested_points); ?>" />

<div class="form-group" id="task-rule-did-you-mean-row" style="display: none; margin-top: -10px; margin-bottom: 12px;">
    <div class="row">
        <label class="col-md-3"></label>
        <div class="col-md-9">
            <div class="card p-2 bg-light border" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px;">
                <div class="text-muted small fw-bold mb-1" style="font-weight: 600; font-size: 12px; color: #475569;">
                    <i data-feather="help-circle" class="icon-14" style="vertical-align: -2px;"></i> <?php echo app_lang('did_you_mean'); ?>
                </div>
                <div id="task-rule-did-you-mean-list" style="display: flex; flex-direction: column; gap: 4px;"></div>
            </div>
        </div>
    </div>
</div>

<div class="form-group" id="task-rule-suggestion-row" style="display: none; margin-top: -5px; margin-bottom: 12px;">
    <div class="row">
        <label class="col-md-3"></label>
        <div class="col-md-9">
            <div class="d-flex align-items-center" style="display: flex; align-items: center; gap: 10px;">
                <span class="text-muted small" id="task-rule-suggestion-text" style="color: #64748b; font-size: 12px;"></span>
                <a href="javascript:void(0);" id="task-rule-clear-suggestion" class="small text-danger" style="font-size: 12px; text-decoration: underline; cursor: pointer;">
                    <?php echo app_lang('clear_suggestion'); ?>
                </a>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
$(document).ready(function() {
    var pointsDirty = false;
    var endTimeDirty = false;
    var deadlineDirty = false;
    var isAutomating = false;
    var canSetDirty = false;

    setTimeout(function() {
        canSetDirty = true;
    }, 600);

    var ccContentType = "";
    var ccContentTitle = "";
    var ccContentText = "";

    function positionRows() {
        var $titleGroup = $("#title").closest(".form-group");
        if ($titleGroup.length && $("#task-rule-did-you-mean-row").prev()[0] !== $titleGroup[0]) {
            $("#task-rule-did-you-mean-row").insertAfter($titleGroup);
        }

        var $pointsGroup = $('select[name="points"]').closest(".form-group");
        if ($pointsGroup.length && $("#task-rule-suggestion-row").prev()[0] !== $pointsGroup[0]) {
            $("#task-rule-suggestion-row").insertAfter($pointsGroup);
        }
    }

    // Try positioning immediately and after short delay for AJAX modal rendering
    positionRows();
    setTimeout(positionRows, 200);

    // Delegated event listeners for form inputs - only set dirty on genuine user action
    $(document).off("select2:select.tr change.tr", 'select[name="points"]')
               .on("select2:select.tr change.tr", 'select[name="points"]', function(e) {
        if (canSetDirty && !isAutomating) {
            pointsDirty = true;
        }
    });

    $(document).off("input.tr keyup.tr changeTime.timepicker.tr", '#end_time')
               .on("input.tr keyup.tr changeTime.timepicker.tr", '#end_time', function() {
        if (canSetDirty && !isAutomating) {
            endTimeDirty = true;
        }
    });

    $(document).off("input.tr keyup.tr changeDate.tr", '#deadline')
               .on("input.tr keyup.tr changeDate.tr", '#deadline', function() {
        if (canSetDirty && !isAutomating) {
            deadlineDirty = true;
        }
    });

    // Clear suggestion button
    $(document).off("click.tr", "#task-rule-clear-suggestion")
               .on("click.tr", "#task-rule-clear-suggestion", function(e) {
        e.preventDefault();
        $("#task-rule-suggestion-row").hide();
    });

    // Department change handler
    $(document).off("change.tr", "#task_department")
               .on("change.tr", "#task_department", function() {
        var dept = $(this).val();
        if (dept === "Digital Marketing") {
            $("#task-rule-sub-dept-section").slideDown(150);
        } else {
            $("#task-rule-sub-dept-section").slideUp(150);
            $("#task_sub_department").val("").trigger("change.select2");
        }

        if (dept) {
            $("#task-rule-dept-hint").hide();
        } else if (ccContentType || ccContentTitle) {
            $("#task-rule-dept-hint").show();
        }

        runMatch();
    });

    // Sub-department change handler
    $(document).off("change.tr", "#task_sub_department")
               .on("change.tr", "#task_sub_department", function() {
        runMatch();
    });

    // Title input debounce with delegated listener
    var debounceTimer = null;
    $(document).off("input.tr keyup.tr", "#title")
               .on("input.tr keyup.tr", "#title", function() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function() {
            runMatch();
        }, 400);
    });

    // Function to apply a specific rule
    function applyRule(rule) {
        if (!rule) return;

        $("#task_rule_id").val(rule.id);
        $("#task_suggested_points").val(rule.points_min !== null ? rule.points_min : "");

        // Set Points if not dirty
        if (!pointsDirty && rule.points_min !== null) {
            isAutomating = true;
            $('select[name="points"]').val(rule.points_min).trigger('change');
            isAutomating = false;
        }

        // Set End time & Deadline if not dirty
        if (!endTimeDirty && rule.timing) {
            isAutomating = true;
            $("#end_time").val(rule.timing.end_time_str);

            if (rule.timing.passes_midnight && !deadlineDirty) {
                $("#deadline").val(rule.timing.deadline_date);
            } else if (!$("#deadline").val() && !deadlineDirty) {
                $("#deadline").val(rule.timing.today_date);
            }
            isAutomating = false;
        }

        // Build suggestion note
        var note = "Suggested from: " + rule.task;
        if (rule.points_min !== null) {
            note += " - " + rule.points_min + " pts";
        }
        if (rule.time_formatted) {
            note += ", about " + rule.time_formatted;
        }

        $("#task-rule-suggestion-text").text(note);
        $("#task-rule-suggestion-row").show();
        $("#task-rule-did-you-mean-row").hide();
    }

    // Function to run matching query
    function runMatch(simulateTime) {
        var dept = $("#task_department").val();
        var subDept = $("#task_sub_department").val();
        var title = $.trim($("#title").val());

        console.log("runMatch called. dept:", dept, "subDept:", subDept, "title:", title);

        if (!dept) {
            if (ccContentType || ccContentTitle) {
                $("#task-rule-dept-hint").show();
            }
            $("#task-rule-did-you-mean-row").hide();
            return;
        } else {
            $("#task-rule-dept-hint").hide();
        }

        var matchQuery = title;
        if (ccContentType) {
            matchQuery = ccContentType + " " + matchQuery;
        }
        if (ccContentText) {
            matchQuery += " " + ccContentText;
        }

        console.log("matchQuery:", matchQuery);
        if (matchQuery.length < 3) {
            $("#task-rule-did-you-mean-row").hide();
            return;
        }

        var postData = {
            rise_csrf_token: $("input[name='rise_csrf_token']").val() || $("meta[name='csrf-token']").attr("content") || "",
            department: dept,
            sub_department: subDept,
            title: title,
            content_type: ccContentType,
            content_text: ccContentText
        };
        if (simulateTime) {
            postData.simulate_time = simulateTime;
        }

        console.log("Sending AJAX to:", "<?php echo get_uri('task_rules/match'); ?>", postData);

        $.ajax({
            url: "<?php echo get_uri('task_rules/match'); ?>",
            type: "POST",
            dataType: "json",
            data: postData,
            success: function(response) {
                console.log("AJAX success response:", response);
                if (!response || !response.success) {
                    return;
                }

                if (response.decision === "apply" && response.top_match) {
                    applyRule(response.top_match);
                } else if (response.decision === "suggest" && response.matches && response.matches.length > 0) {
                    var $list = $("#task-rule-did-you-mean-list").empty();
                    $.each(response.matches, function(idx, m) {
                        var ptsStr = (m.points_min !== null) ? (m.points_min + " pts") : "";
                        var timeStr = m.time_formatted ? m.time_formatted : "";
                        var metaStr = "";
                        if (ptsStr && timeStr) metaStr = ptsStr + ", " + timeStr;
                        else if (ptsStr) metaStr = ptsStr;
                        else if (timeStr) metaStr = timeStr;

                        var $item = $('<a href="javascript:void(0);" class="task-rule-pick-item" style="color: #2563eb; text-decoration: none; font-size: 13px; padding: 2px 0;">' +
                            '<span style="margin-right: 4px;">&rsaquo;</span> <strong>' + escapeHtml(m.task) + '</strong> ' +
                            (metaStr ? ('<span class="text-muted small" style="color: #64748b;">(' + metaStr + ')</span>') : '') +
                            '</a>');

                        $item.on("click", function(e) {
                            e.preventDefault();
                            applyRule(m);
                        });

                        $list.append($item);
                    });

                    $("#task-rule-did-you-mean-row").show();
                } else {
                    // No match
                    $("#task-rule-did-you-mean-row").hide();
                    if (!endTimeDirty && !$("#end_time").val() && response.default_timing) {
                        isAutomating = true;
                        $("#end_time").val(response.default_timing.end_time_str);
                        if (response.default_timing.passes_midnight && !deadlineDirty) {
                            $("#deadline").val(response.default_timing.deadline_date);
                        } else if (!$("#deadline").val() && !deadlineDirty) {
                            $("#deadline").val(response.default_timing.today_date);
                        }
                        isAutomating = false;
                    }
                }
            },
            error: function(xhr, status, error) {
                console.log("AJAX ERROR:", status, error, xhr.status, xhr.responseText);
            }
        });
    }

    // Helper to escape HTML
    function escapeHtml(text) {
        if (!text) return "";
        return $('<div>').text(text).html();
    }

    // Expose runMatch on window for simulation / testing if needed
    window._taskRuleRunMatch = runMatch;

    // Listen for Content Calendar item selection
    $(document).on("cc_item_selected", function(e, item) {
        if (item) {
            ccContentType = item.content_type || "";
            ccContentTitle = item.title || "";
            ccContentText = item.description || "";

            var dept = $("#task_department").val();
            if (!dept) {
                $("#task-rule-dept-hint").show();
            } else {
                $("#task-rule-dept-hint").hide();
                runMatch();
            }
        }
    });

    // Fallback: observe clicks on .cc-item-card if cc_item_selected is not fired
    $(document).on("click", ".cc-item-card", function() {
        var $card = $(this);
        var typeText = $card.find(".badge, .cc-type-badge").text();
        var cardTitle = $card.find(".cc-card-title").text();
        if (typeText || cardTitle) {
            ccContentType = $.trim(typeText);
            ccContentTitle = $.trim(cardTitle);
            var dept = $("#task_department").val();
            if (!dept) {
                $("#task-rule-dept-hint").show();
            } else {
                $("#task-rule-dept-hint").hide();
                setTimeout(runMatch, 100);
            }
        }
    });

    if (window.feather) {
        feather.replace();
    }
});
</script>
