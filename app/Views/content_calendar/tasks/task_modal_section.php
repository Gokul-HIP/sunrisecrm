<?php
helper('content_calendar');

$task_id = (isset($model_info) && is_object($model_info) && !empty($model_info->id)) ? (int) $model_info->id : 0;
$link_info = $task_id ? cc_get_task_link_info($task_id) : null;
$all_content_types = function_exists('cc_get_all_content_types') ? (array) cc_get_all_content_types() : array();
?>

<style>
.cc-task-link-section {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px 14px;
    margin-bottom: 15px;
}
.cc-task-picker-container {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px dashed #cbd5e1;
}
.cc-date-chips-wrap {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.cc-date-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 20px;
    border: 1px solid #d1d5db;
    background: #ffffff;
    color: #374151;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.15s ease;
    user-select: none;
    line-height: 1.4;
}
.cc-date-chip strong {
    font-size: 13px;
    color: inherit;
}
.cc-date-chip .cc-chip-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #f3f4f6;
    color: #4b5563;
    border-radius: 999px;
    font-size: 11px;
    padding: 1px 6px;
    font-weight: 600;
}
.cc-date-chip:hover {
    border-color: #9ca3af;
    background: #f9fafb;
}
.cc-date-chip.is-today {
    border-color: #3b82f6;
    color: #1d4ed8;
    background: #eff6ff;
}
.cc-date-chip.is-today .cc-chip-count {
    background: #dbeafe;
    color: #1d4ed8;
}
.cc-date-chip.active {
    border-color: #2563eb;
    background: #2563eb;
    color: #ffffff;
}
.cc-date-chip.active strong {
    color: #ffffff;
}
.cc-date-chip.active .cc-chip-count {
    background: rgba(255, 255, 255, 0.25);
    color: #ffffff;
}
.cc-items-cards-wrap {
    display: flex;
    flex-direction: column;
    gap: 8px;
    max-height: 280px;
    overflow-y: auto;
    padding: 2px;
}
.cc-item-radio-card {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 8px 12px;
    border-radius: 6px;
    border: 1px solid #e5e7eb;
    background: #ffffff;
    cursor: pointer;
    transition: all 0.15s ease;
}
.cc-item-radio-card:hover {
    border-color: #cbd5e1;
    background: #f9fafb;
}
.cc-item-radio-card.active {
    border-color: #3b82f6;
    background: #f0f7ff;
    box-shadow: 0 0 0 1px #3b82f6;
}
.cc-card-title-row {
    display: flex;
    align-items: center;
    gap: 8px;
}
.cc-item-radio {
    margin: 0;
    cursor: pointer;
}
.cc-card-title {
    font-size: 13px;
    font-weight: 600;
    color: #1f2937;
    flex-grow: 1;
}
.cc-card-pills-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    margin-left: 24px;
}
.cc-card-pills-row .cc-pill {
    display: inline-block;
    padding: 1px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
}
.cc-task-exists-tag {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 11px;
    font-weight: 600;
    color: #b45309;
    background: #fef3c7;
    border: 1px solid #fde68a;
    padding: 1px 7px;
    border-radius: 999px;
}
.cc-dup-warning-box {
    border-radius: 6px;
    background-color: #fffbeb;
    border: 1px solid #fef08a;
}
.cc-linked-item-pill-wrap {
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 6px 12px;
}
@media (max-width: 576px) {
    .cc-task-link-section label.col-md-3 {
        margin-bottom: 4px;
    }
    .cc-date-chips-wrap {
        gap: 4px;
    }
    .cc-date-chip {
        padding: 3px 8px;
        font-size: 12px;
    }
    .cc-item-radio-card {
        padding: 8px 10px;
    }
    .cc-card-pills-row {
        margin-left: 0;
    }
}
</style>

<?php if ($task_id) { ?>
    <!-- Edit mode -->
    <?php if ($link_info && !empty($link_info->is_linked)) { ?>
        <!-- Linked task: read-only Content link, type pill, status pill -->
        <div class="form-group mb15 cc-linked-content-group">
            <div class="row align-items-center">
                <label class="col-md-3 font-13"><?php echo cc_lang('cc_content_calendar'); ?></label>
                <div class="col-md-9">
                    <div class="d-flex align-items-center flex-wrap gap-2 font-13">
                        <?php if (!empty($link_info->content_type)) { ?>
                            <span class="cc-pill" style="<?php echo esc($link_info->type_pill_style ?? ''); ?>">
                                <?php echo esc($link_info->content_type); ?>
                            </span>
                        <?php } ?>
                        <?php if (!empty($link_info->status)) { ?>
                            <span class="cc-pill" style="<?php echo esc($link_info->status_pill_style ?? ''); ?>">
                                <?php echo esc($link_info->status); ?>
                            </span>
                        <?php } ?>
                        <span class="text-off">•</span>
                        <a href="<?php echo esc($link_info->link_url ?? '#'); ?>" target="_blank" rel="noopener noreferrer" class="strong text-primary text-decoration-none" title="<?php echo esc(cc_lang('cc_view')); ?>">
                            <?php echo esc($link_info->calendar_name ?? ''); ?> • <?php echo esc($link_info->formatted_date ?? ''); ?> • <?php echo esc($link_info->title ?? ''); ?>
                        </a>
                        <i data-feather="external-link" class="icon-12 text-muted ms-1"></i>
                    </div>
                </div>
            </div>
        </div>
        <script type="text/javascript">
            if (window.feather) { feather.replace(); }
        </script>
    <?php } else { ?>
        <!-- Manual task: editable Content type select dropdown -->
        <?php if (!empty($all_content_types)) { ?>
            <div class="form-group mb15" id="cc_manual_content_type_row">
                <div class="row align-items-center">
                    <label for="cc_manual_content_type" class="col-md-3 font-13"><?php echo cc_lang('cc_content_type'); ?></label>
                    <div class="col-md-9">
                        <select id="cc_manual_content_type" name="cc_manual_content_type" class="form-control" style="width: 100%;">
                            <option value="">- <?php echo cc_lang('cc_type'); ?> (optional) -</option>
                            <?php
                            $cur_type = ($link_info && !empty($link_info->content_type)) ? $link_info->content_type : "";
                            foreach ($all_content_types as $ctype) {
                                $selected = ($ctype === $cur_type) ? 'selected="selected"' : '';
                            ?>
                                <option value="<?php echo esc($ctype); ?>" <?php echo $selected; ?>><?php echo esc($ctype); ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
            </div>
            <script type="text/javascript">
                if ($("#cc_manual_content_type").length) {
                    $("#cc_manual_content_type").select2();
                }
            </script>
        <?php } ?>
    <?php } ?>
<?php } else { ?>
    <!-- Add mode: Content Calendar Link & Manual Content Type Section -->
    <div id="cc-task-link-section" class="cc-task-link-section mb15">
        <!-- Switch row -->
        <div class="form-group mb10">
            <div class="row align-items-center">
                <label class="col-md-3 font-13"><?php echo cc_lang('cc_content_calendar'); ?></label>
                <div class="col-md-9">
                    <div class="form-check form-switch d-inline-flex align-items-center gap-2 m-0 p-0">
                        <input class="form-check-input ms-0 me-2 cursor-pointer" type="checkbox" id="cc_link_switch" name="cc_link_switch" value="1">
                        <label class="form-check-label font-13 cursor-pointer text-dark user-select-none" for="cc_link_switch">
                            <?php echo cc_lang('cc_link_to_content'); ?>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Manual Content Type Row (Visible when Switch is OFF) -->
        <?php if (!empty($all_content_types)) { ?>
            <div class="form-group mb10" id="cc_manual_content_type_row">
                <div class="row align-items-center">
                    <label for="cc_manual_content_type" class="col-md-3 font-13"><?php echo cc_lang('cc_type'); ?></label>
                    <div class="col-md-9">
                        <select id="cc_manual_content_type" name="cc_manual_content_type" class="form-control" style="width: 100%;">
                            <option value="">- <?php echo cc_lang('cc_type'); ?> (optional) -</option>
                            <?php foreach ($all_content_types as $ctype) { ?>
                                <option value="<?php echo esc($ctype); ?>"><?php echo esc($ctype); ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
            </div>
        <?php } ?>

        <!-- Collapsible Container (Visible when Switch is ON) -->
        <div id="cc-task-picker-container" class="cc-task-picker-container" style="display: none;">
            <input type="hidden" id="cc_item_id" name="cc_item_id" value="" disabled>

            <!-- Client Select Row -->
            <div class="form-group mb10" id="cc_picker_client_row">
                <div class="row align-items-center">
                    <label for="cc_picker_client" class="col-md-3 font-13"><?php echo app_lang('client'); ?></label>
                    <div class="col-md-9">
                        <select id="cc_picker_client" class="form-control" style="width: 100%;">
                            <option value="">- <?php echo cc_lang('cc_choose_client'); ?> -</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Month Select Row -->
            <div class="form-group mb10" id="cc_picker_month_row" style="display: none;">
                <div class="row align-items-center">
                    <label for="cc_picker_month" class="col-md-3 font-13"><?php echo cc_lang('cc_month'); ?></label>
                    <div class="col-md-9">
                        <select id="cc_picker_month" class="form-control" style="width: 100%;">
                            <option value="">- <?php echo cc_lang('cc_choose_month'); ?> -</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Date Chips Row -->
            <div class="form-group mb10" id="cc_picker_dates_row" style="display: none;">
                <div class="row">
                    <label class="col-md-3 font-13 pt-1"><?php echo app_lang('date'); ?></label>
                    <div class="col-md-9">
                        <div id="cc_date_chips_container" class="cc-date-chips-wrap"></div>
                        <div id="cc_show_earlier_wrap" style="display: none;" class="mt-1">
                            <a href="javascript:void(0);" id="cc_show_earlier_btn" class="font-12 text-primary text-decoration-none">
                                <i data-feather="chevron-down" class="icon-12"></i> <?php echo cc_lang('cc_show_earlier_dates'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Content Cards Row -->
            <div class="form-group mb10" id="cc_picker_items_row" style="display: none;">
                <div class="row">
                    <label class="col-md-3 font-13 pt-1"><?php echo app_lang('content'); ?></label>
                    <div class="col-md-9">
                        <div id="cc_items_cards_container" class="cc-items-cards-wrap"></div>

                        <!-- Duplicate Warning Box -->
                        <div id="cc_duplicate_warning_box" class="cc-dup-warning-box alert alert-warning p-2 mt-2" style="display: none;">
                            <div class="d-flex align-items-start gap-2">
                                <i data-feather="alert-triangle" class="icon-16 text-warning mt-1 flex-shrink-0"></i>
                                <div class="flex-grow-1">
                                    <div id="cc_duplicate_warning_text" class="font-13 strong text-dark"></div>
                                    <div class="form-check mt-1 mb-0 p-0">
                                        <input class="form-check-input ms-0 me-2" type="checkbox" id="cc_duplicate_confirmed" name="cc_duplicate_confirmed" value="1">
                                        <label class="form-check-label font-12 cursor-pointer text-dark" for="cc_duplicate_confirmed">
                                            <?php echo cc_lang('cc_create_another_task_anyway'); ?>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Validation Error Message -->
                        <div id="cc_picker_validation_error" class="text-danger font-12 mt-1" style="display: none;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script type="text/javascript">
    (function () {
        var endpoints = {
            clients: "<?php echo get_uri('content_calendar/task_picker_clients'); ?>",
            months: "<?php echo get_uri('content_calendar/task_picker_months'); ?>",
            dates: "<?php echo get_uri('content_calendar/task_picker_dates'); ?>",
            items: "<?php echo get_uri('content_calendar/task_picker_items'); ?>"
        };

        var lastSuggestedTitle = "";
        var lastSuggestedDesc = "";
        var lastSuggestedHtml = "";
        var loadedItems = {};
        var clientsLoaded = false;

        function escapeHtml(str) {
            if (str === null || str === undefined) return "";
            return $("<div>").text(String(str)).html();
        }

        // Init manual select2 if present
        if ($("#cc_manual_content_type").length) {
            $("#cc_manual_content_type").select2();
        }

        // Project suggestion container
        function getProjectNoteEl() {
            var $el = $("#cc-project-suggestion-note");
            if (!$el.length) {
                var $target = $("#project_id").closest(".col-md-9, .form-group");
                if (!$target.length) {
                    $target = $("#project_id").parent();
                }
                if ($target.length) {
                    $el = $("<div id='cc-project-suggestion-note' class='font-12 mt-1'></div>");
                    $target.append($el);
                }
            }
            return $el;
        }

        var lastSuggestedProjectId = null;

        function clearProjectNote() {
            lastSuggestedProjectId = null;
            var $el = getProjectNoteEl();
            if ($el && $el.length) {
                $el.empty().hide();
            }
        }

        function setProjectNote(type, message) {
            var $el = getProjectNoteEl();
            if (!$el || !$el.length) return;
            var colorClass = "text-muted";
            var iconName = "info";
            var clearBtnHtml = "";
            if (type === "success") {
                colorClass = "text-success";
                iconName = "check-circle";
                clearBtnHtml = " <a href='javascript:void(0);' id='cc-clear-project-suggestion' class='text-muted font-11 ms-2 text-decoration-underline cursor-pointer'>Clear suggestion</a>";
            } else if (type === "warning") {
                colorClass = "text-warning";
                iconName = "alert-circle";
            } else if (type === "info") {
                colorClass = "text-info";
                iconName = "info";
            }
            $el.html("<span class='" + colorClass + " d-inline-flex align-items-center gap-1'><i data-feather='" + iconName + "' class='icon-12'></i> " + escapeHtml(message) + "</span>" + clearBtnHtml).show();
            if (window.feather) { feather.replace(); }
        }

        $(document).on("click", "#cc-clear-project-suggestion", function (e) {
            e.preventDefault();
            lastSuggestedProjectId = null;
            clearProjectNote();
            if ($("#project_id").data("select2")) {
                $("#project_id").select2("val", "", true);
            } else {
                $("#project_id").val("").trigger("change");
            }
        });

        $(document).on("change", "#project_id", function () {
            if (lastSuggestedProjectId && String($(this).val()) !== String(lastSuggestedProjectId)) {
                clearProjectNote();
            }
        });

        // Toggle Switch
        $("#cc_link_switch").on("change", function () {
            var isOn = $(this).is(":checked");
            if (isOn) {
                $("#cc_item_id").prop("disabled", false);
                $("#cc_manual_content_type_row").slideUp(150);
                $("#cc_manual_content_type").val("").trigger("change");
                $("#milestone_id").closest(".form-group").slideUp(150);
                $("#milestone_id").val("");
                $("#cc-task-picker-container").slideDown(150);
                if (!clientsLoaded) {
                    loadClients();
                }
            } else {
                $("#cc_item_id").prop("disabled", true).val("");
                $("#cc-task-picker-container").slideUp(150);
                $("#cc_manual_content_type_row").slideDown(150);
                $("#milestone_id").closest(".form-group").slideDown(150);
                resetAllPickerSteps();
                clearProjectNote();
                $("#cc_picker_validation_error").hide();
            }
        });

        if ($("#cc_link_switch").is(":checked")) {
            $("#milestone_id").closest(".form-group").hide();
        }

        function resetAllPickerSteps() {
            $("#cc_item_id").val("");
            var $sel = $("#cc_picker_client");
            if ($sel.data("select2")) {
                $sel.val("").trigger("change.select2");
            } else {
                $sel.val("");
            }
            $("#cc_picker_month_row").hide();
            $("#cc_picker_month").empty().append("<option value=''>- <?php echo cc_lang('cc_choose_month'); ?> -</option>");
            $("#cc_picker_dates_row").hide();
            $("#cc_date_chips_container").empty();
            $("#cc_show_earlier_wrap").hide();
            $("#cc_picker_items_row").hide();
            $("#cc_items_cards_container").empty();
            $("#cc_duplicate_warning_box").hide();
            $("#cc_duplicate_confirmed").prop("checked", false);
            loadedItems = {};
        }

        function loadClients() {
            var $sel = $("#cc_picker_client");
            $sel.empty().append("<option value=''>- Loading clients... -</option>");
            if ($sel.data("select2")) {
                $sel.trigger("change.select2");
            }

            $.ajax({
                url: endpoints.clients,
                type: "GET",
                dataType: "json",
                success: function (res) {
                    $sel.empty();
                    if (res && res.success && res.clients && res.clients.length) {
                        clientsLoaded = true;
                        $sel.append("<option value=''>- <?php echo cc_lang('cc_choose_client'); ?> -</option>");
                        $.each(res.clients, function (i, c) {
                            var clientName = escapeHtml(c.name);
                            $sel.append("<option value='" + c.id + "' data-client-id='" + (c.client_id || 0) + "'>" + clientName + "</option>");
                        });
                    } else {
                        $sel.append("<option value=''>- No clients available -</option>");
                    }

                    if ($sel.data("select2")) {
                        $sel.select2("destroy");
                    }
                    $sel.select2();
                },
                error: function () {
                    $sel.empty().append("<option value=''>- Failed to load clients -</option>");
                    if (window.appAlert) {
                        appAlert.error("Failed to load Content Calendar clients");
                    }
                }
            });
        }

        // On Client change
        $("#cc_picker_client").on("change", function () {
            var calId = $(this).val();
            // Clear lower steps
            $("#cc_item_id").val("");
            $("#cc_picker_month_row").hide();
            $("#cc_picker_dates_row").hide();
            $("#cc_date_chips_container").empty();
            $("#cc_show_earlier_wrap").hide();
            $("#cc_picker_items_row").hide();
            $("#cc_items_cards_container").empty();
            $("#cc_duplicate_warning_box").hide();
            $("#cc_duplicate_confirmed").prop("checked", false);
            loadedItems = {};
            clearProjectNote();
            $("#cc_picker_validation_error").hide();

            if (!calId) return;

            var $monthSel = $("#cc_picker_month");
            $monthSel.empty().append("<option value=''>- Loading months... -</option>");
            $("#cc_picker_month_row").show();

            $.ajax({
                url: endpoints.months,
                type: "GET",
                data: { calendar_id: calId },
                dataType: "json",
                success: function (res) {
                    $monthSel.empty();
                    if (res && res.success && res.months && res.months.length) {
                        $monthSel.append("<option value=''>- <?php echo cc_lang('cc_choose_month'); ?> -</option>");
                        var defaultYm = res.current_ym || "";
                        $.each(res.months, function (i, m) {
                            var isSel = (m.ym === defaultYm);
                            var countBadge = " (" + m.count + ")";
                            var mLabel = escapeHtml(m.label);
                            $monthSel.append("<option value='" + m.ym + "'" + (isSel ? " selected" : "") + ">" + mLabel + countBadge + "</option>");
                        });

                        if ($monthSel.data("select2")) {
                            $monthSel.select2("destroy");
                        }
                        $monthSel.select2();

                        if (defaultYm) {
                            loadDates(calId, defaultYm);
                        }
                    } else {
                        $monthSel.append("<option value=''>- No upcoming months -</option>");
                        if ($monthSel.data("select2")) {
                            $monthSel.select2("destroy");
                        }
                        $monthSel.select2();
                    }
                },
                error: function () {
                    $monthSel.empty().append("<option value=''>- Failed to load months -</option>");
                }
            });
        });

        // On Month change
        $("#cc_picker_month").on("change", function () {
            var calId = $("#cc_picker_client").val();
            var ym = $(this).val();

            $("#cc_item_id").val("");
            $("#cc_picker_dates_row").hide();
            $("#cc_date_chips_container").empty();
            $("#cc_show_earlier_wrap").hide();
            $("#cc_picker_items_row").hide();
            $("#cc_items_cards_container").empty();
            $("#cc_duplicate_warning_box").hide();
            $("#cc_duplicate_confirmed").prop("checked", false);
            loadedItems = {};
            clearProjectNote();
            $("#cc_picker_validation_error").hide();

            if (!calId || !ym) return;
            loadDates(calId, ym);
        });

        function loadDates(calId, ym) {
            $.ajax({
                url: endpoints.dates,
                type: "GET",
                data: { calendar_id: calId, month: ym },
                dataType: "json",
                success: function (res) {
                    if (res && res.success && res.dates && res.dates.length) {
                        var $wrap = $("#cc_date_chips_container");
                        $wrap.empty();
                        var hasPast = false;

                        $.each(res.dates, function (i, d) {
                            var chip = $("<button type='button' class='cc-date-chip'></button>")
                                .attr("data-date", d.date)
                                .html("<strong>" + d.day + "</strong><span class='cc-chip-count'>" + d.count + "</span>");

                            if (d.is_today) {
                                chip.addClass("is-today");
                            }
                            if (d.is_past) {
                                chip.addClass("is-past").hide();
                                hasPast = true;
                            }

                            $wrap.append(chip);
                        });

                        $("#cc_picker_dates_row").show();
                        if (hasPast) {
                            $("#cc_show_earlier_wrap").show();
                            $("#cc_show_earlier_btn").show();
                        } else {
                            $("#cc_show_earlier_wrap").hide();
                        }
                    } else {
                        $("#cc_picker_dates_row").hide();
                    }
                }
            });
        }

        // Show earlier dates
        $("#cc_show_earlier_btn").on("click", function (e) {
            e.preventDefault();
            $(".cc-date-chip.is-past").fadeIn(150);
            $(this).hide();
        });

        // Click Date chip
        $(document).on("click", ".cc-date-chip", function () {
            $(".cc-date-chip").removeClass("active");
            $(this).addClass("active");

            var date = $(this).attr("data-date");
            var calId = $("#cc_picker_client").val();

            $("#cc_item_id").val("");
            $("#cc_picker_items_row").hide();
            $("#cc_items_cards_container").empty();
            $("#cc_duplicate_warning_box").hide();
            $("#cc_duplicate_confirmed").prop("checked", false);
            loadedItems = {};
            clearProjectNote();
            $("#cc_picker_validation_error").hide();

            loadItems(calId, date);
        });

        function loadItems(calId, date) {
            $.ajax({
                url: endpoints.items,
                type: "GET",
                data: { calendar_id: calId, date: date },
                dataType: "json",
                success: function (res) {
                    if (res && res.success && res.items && res.items.length) {
                        var $container = $("#cc_items_cards_container");
                        $container.empty();
                        loadedItems = {};

                        $.each(res.items, function (i, item) {
                            loadedItems[item.id] = item;

                            var $card = $("<div class='cc-item-radio-card'></div>")
                                .attr("data-id", item.id)
                                .attr("data-has-task", item.has_task ? "1" : "0")
                                .attr("data-task-id", item.task_id || "");

                            var radioHtml = "<input type='radio' name='cc_item_choice' class='cc-item-radio' value='" + item.id + "'>";
                            var pillsHtml = "<div class='cc-card-pills-row'>";
                            if (item.type) {
                                var tStyle = item.type_style ? item.type_style : ("background:" + item.type_color + "22;color:" + item.type_color + ";");
                                pillsHtml += "<span class='cc-pill' style='" + escapeHtml(tStyle) + "'>" + escapeHtml(item.type) + "</span>";
                            }
                            if (item.status) {
                                var sStyle = item.status_style ? item.status_style : ("background:" + item.status_color + "22;color:" + item.status_color + ";");
                                pillsHtml += "<span class='cc-pill' style='" + escapeHtml(sStyle) + "'>" + escapeHtml(item.status) + "</span>";
                            }
                            if (item.has_task) {
                                pillsHtml += "<span class='cc-task-exists-tag'>Task #" + item.task_id + " exists</span>";
                            }
                            pillsHtml += "</div>";

                            var titleHtml = "<div class='cc-card-title-row'>" + radioHtml + "<span class='cc-card-title'>" + escapeHtml(item.title) + "</span></div>";

                            $card.html(titleHtml + pillsHtml);
                            $container.append($card);
                        });

                        $("#cc_picker_items_row").show();

                        // Handle Project Suggestion
                        if (res.project_suggestion) {
                            var sugg = res.project_suggestion;
                            if (sugg.status === "single" && sugg.project_id) {
                                lastSuggestedProjectId = sugg.project_id;
                                setProjectNote("success", sugg.message);
                                try {
                                    if ($("#project_id").data("select2")) {
                                        $("#project_id").select2("val", sugg.project_id, true);
                                    } else {
                                        $("#project_id").val(sugg.project_id).trigger("change");
                                    }
                                } catch (e) {
                                    $("#project_id").val(sugg.project_id).trigger("change");
                                }
                            } else if (sugg.status === "none") {
                                lastSuggestedProjectId = null;
                                setProjectNote("warning", sugg.message);
                            } else if (sugg.status === "multiple") {
                                lastSuggestedProjectId = null;
                                setProjectNote("info", sugg.message);
                            }
                        }
                    }
                }
            });
        }

        // Click Content Card
        $(document).on("click", ".cc-item-radio-card", function () {
            var $card = $(this);
            var itemId = parseInt($card.attr("data-id"), 10);
            var item = loadedItems[itemId];
            if (!item) return;

            $(".cc-item-radio-card").removeClass("active");
            $card.addClass("active");
            $card.find(".cc-item-radio").prop("checked", true);
            $("#cc_item_id").val(itemId);
            $("#cc_picker_validation_error").hide();

            // Duplicate warning & confirmation
            if (item.has_task) {
                var warnText = "<?php echo cc_lang('cc_task_exists_warning'); ?>".replace("%s", item.task_id);
                $("#cc_duplicate_warning_text").text(warnText);
                $("#cc_duplicate_warning_box").slideDown(150);
                if (window.feather) { feather.replace(); }
            } else {
                $("#cc_duplicate_warning_box").slideUp(150);
                $("#cc_duplicate_confirmed").prop("checked", false);
            }

            // Fill Title (only if empty or holding previous suggestion)
            var currentTitle = $.trim($("#title").val());
            if (currentTitle === "" || currentTitle === lastSuggestedTitle) {
                $("#title").val(item.title);
                lastSuggestedTitle = item.title;
            }

            // Fill Description
            fillDescription(item.description_html || item.description, item.description);

            // Notify listeners (e.g. task rules matcher)
            $(document).trigger("cc_item_selected", item);
        });

        function fillDescription(htmlContent, textContent) {
            var $desc = $("#description");
            var isSummernote = typeof $desc.summernote === "function" && $desc.next(".note-editor").length;

            function normalizeText(txt) {
                return (txt || "").replace(/[\u00A0\s]+/g, " ").trim();
            }

            if (isSummernote) {
                var currentVal = $.trim($desc.summernote("code"));
                var plainText = $("<div>").html(currentVal).text();
                var normCurrent = normalizeText(plainText);
                var normSuggested = normalizeText(lastSuggestedDesc);

                var isAutoFilled = (normCurrent === "" || currentVal === "<p><br></p>" || currentVal === "<p><br/></p>" || currentVal === "<br>" || currentVal === lastSuggestedHtml || normCurrent === normSuggested);
                if (isAutoFilled) {
                    $desc.summernote("code", htmlContent);
                    lastSuggestedHtml = htmlContent;
                    lastSuggestedDesc = textContent;
                }
            } else {
                var currentVal = $.trim($desc.val());
                var normCurrent = normalizeText(currentVal);
                var normSuggested = normalizeText(lastSuggestedDesc);

                var isAutoFilled = (normCurrent === "" || currentVal === lastSuggestedDesc || normCurrent === normSuggested);
                if (isAutoFilled) {
                    $desc.val(textContent);
                    lastSuggestedDesc = textContent;
                }
            }
        }

        // Validation Interceptor on Form Submission Buttons
        $("#task-form").find("button[type='submit'], #save-and-show-button, #save-and-add-button").on("click", function (e) {
            if ($("#cc_link_switch").is(":checked")) {
                var itemId = $("#cc_item_id").val();
                if (!itemId) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    $("#cc_picker_validation_error").text("<?php echo cc_lang('cc_content_item_required'); ?>").show();
                    var $row = $("#cc_picker_items_row");
                    if ($row.length && $row.is(":visible")) {
                        $row[0].scrollIntoView({ behavior: "smooth", block: "center" });
                    }
                    return false;
                }

                var $activeCard = $(".cc-item-radio-card.active");
                if ($activeCard.length && $activeCard.attr("data-has-task") === "1") {
                    if (!$("#cc_duplicate_confirmed").is(":checked")) {
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        $("#cc_picker_validation_error").text("Please confirm to create another task for this content anyway.").show();
                        $("#cc_duplicate_warning_box")[0].scrollIntoView({ behavior: "smooth", block: "center" });
                        return false;
                    }
                }
            }
        });

        // Form Submit Backup check
        $("#task-form").on("submit", function (e) {
            if ($("#cc_link_switch").is(":checked")) {
                var itemId = $("#cc_item_id").val();
                if (!itemId) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    $("#cc_picker_validation_error").text("<?php echo cc_lang('cc_content_item_required'); ?>").show();
                    return false;
                }
                var $activeCard = $(".cc-item-radio-card.active");
                if ($activeCard.length && $activeCard.attr("data-has-task") === "1" && !$("#cc_duplicate_confirmed").is(":checked")) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    $("#cc_picker_validation_error").text("Please confirm to create another task for this content anyway.").show();
                    return false;
                }
            }
        });
    })();
    </script>
<?php } ?>
