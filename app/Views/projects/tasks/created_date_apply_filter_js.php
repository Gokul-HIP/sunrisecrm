<script type="text/javascript">
    /**
     * From–to created_date filter with Apply button.
     * Dates can be chosen freely; filter runs only after Apply.
     * Until Apply (or after Clear), list/kanban stays unfiltered by created date.
     */
    window.initTasksCreatedDateApplyFilter = function (config) {
        var instanceId = config.instanceId,
                $toolbar = config.$toolbar,
                mode = config.mode || "table", // table | filters
                today = moment().format("YYYY-MM-DD"),
                todayLabel = moment().format("Do MMMM YYYY"),
                emptyIcon = '<i data-feather="calendar" class="icon-16"></i>',
                draftFrom = today,
                draftTo = today,
                inputDateFormat = "YYYY-MM-DD";

        if (!$toolbar || !$toolbar.length || $("#" + instanceId + "-created-date-filter").length) {
            return;
        }

        if (!$("#tasks-created-date-filter-style").length) {
            $("head").append(
                '<style id="tasks-created-date-filter-style">' +
                '.created-date-filter-row{' +
                    'display:flex;' +
                    'width:100%;' +
                    'clear:both;' +
                    'justify-content:flex-end;' +
                    'padding:0 15px 8px;' +
                    'box-sizing:border-box;' +
                '}' +
                '.created-date-filter-wrap{' +
                    'display:inline-flex !important;' +
                    'float:none !important;' +
                    'flex-wrap:nowrap;' +
                    'flex-shrink:0;' +
                    'white-space:nowrap;' +
                    'align-items:center;' +
                    'vertical-align:middle;' +
                '}' +
                '.created-date-filter-wrap .input-daterange{' +
                    'display:inline-flex;' +
                    'align-items:center;' +
                    'flex-wrap:nowrap;' +
                '}' +
                '.created-date-filter-wrap .form-control{' +
                    'height:34px;' +
                    'line-height:20px;' +
                    'padding:6px 10px;' +
                    'white-space:nowrap;' +
                    'width:auto;' +
                    'max-width:170px;' +
                    'overflow:hidden;' +
                    'text-overflow:ellipsis;' +
                '}' +
                '.created-date-filter-wrap .created-date-apply-btn,' +
                '.created-date-filter-wrap .created-date-clear-btn{' +
                    'height:34px;' +
                    'flex-shrink:0;' +
                    'white-space:nowrap;' +
                '}' +
                '@media (max-width:767px){' +
                    '.created-date-filter-row{justify-content:flex-start;}' +
                    '.created-date-filter-wrap{flex-wrap:wrap;white-space:normal;}' +
                    '.created-date-filter-wrap .form-control{max-width:none;}' +
                '}' +
                '</style>'
            );
        }

        var $row = $('<div class="created-date-filter-row"></div>');

        var $wrap = $(
                '<div id="' + instanceId + '-created-date-filter" class="created-date-filter-wrap mr15 DTTT_container mb10">' +
                '<div class="input-daterange input-group">' +
                '<button type="button" class="btn btn-default form-control created-from-btn" data-date="' + today + '">' + todayLabel + '</button>' +
                '<span class="input-group-addon">-</span>' +
                '<button type="button" class="btn btn-default form-control created-to-btn" data-date="' + today + '">' + todayLabel + '</button>' +
                '</div>' +
                '<button type="button" class="btn btn-primary created-date-apply-btn ml5"><?php echo app_lang("apply"); ?></button>' +
                '<button type="button" class="btn btn-default created-date-clear-btn ml5"><?php echo app_lang("clear"); ?></button>' +
                '</div>'
                );

        $row.append($wrap);

        // Keep date filter outside the floated custom-toolbar to avoid breaking DataTables layout.
        if (mode === "table") {
            var $wrapper = $("#" + instanceId).closest(".dataTables_wrapper");
            var $toolsRow = $wrapper.find(".datatable-tools").has(".custom-toolbar, .dataTables_length").first();
            if (!$toolsRow.length) {
                $toolsRow = $("#tasks-filter-card .custom-toolbar, #tasks-filter-card .datatable-tools").first();
            }
            if ($toolsRow.length) {
                $toolsRow.after($row);
            } else {
                $toolbar.append($row);
            }
        } else {
            $toolbar.append($row);
        }

        feather.replace();

        var $range = $wrap.find(".input-daterange"),
                $fromBtn = $wrap.find(".created-from-btn"),
                $toBtn = $wrap.find(".created-to-btn"),
                inputs = $range.find("button.form-control").toArray();

        var updateLabels = function () {
            $fromBtn.attr("data-date", draftFrom).html(draftFrom ? moment(draftFrom, inputDateFormat).format("Do MMMM YYYY") : emptyIcon);
            $toBtn.attr("data-date", draftTo).html(draftTo ? moment(draftTo, inputDateFormat).format("Do MMMM YYYY") : emptyIcon);
            feather.replace();
        };

        var reloadInstance = function () {
            var settings = window.InstanceCollection && window.InstanceCollection[instanceId];
            if (!settings) {
                return;
            }
            if (mode === "filters") {
                $("#" + instanceId).appFilters({reload: true});
            } else {
                $("#" + instanceId).appTable({reload: true});
            }
        };

        $range.datepicker({
            format: "yyyy-mm-dd",
            autoclose: true,
            todayHighlight: true,
            language: "custom",
            weekStart: AppHelper.settings.firstDayOfWeek || 0,
            orientation: "bottom",
            inputs: inputs
        }).on("changeDate", function (e) {
            var date = moment(e.date).format(inputDateFormat);
            var name = $(e.target).hasClass("created-from-btn") ? "from" : "to";
            if (name === "from") {
                draftFrom = date;
                if (!draftTo) {
                    draftTo = date;
                }
            } else {
                draftTo = date;
                if (!draftFrom) {
                    draftFrom = date;
                }
            }
            updateLabels();
        });

        $wrap.on("click", ".created-date-apply-btn", function (e) {
            e.preventDefault();
            e.stopPropagation();

            var settings = window.InstanceCollection && window.InstanceCollection[instanceId];
            if (!settings) {
                return;
            }
            if (!draftFrom || !draftTo) {
                appAlert.warning("<?php echo app_lang('field_required'); ?>", {duration: 3000});
                return;
            }

            settings.filterParams.created_from = draftFrom;
            settings.filterParams.created_to = draftTo;
            reloadInstance();
        });

        $wrap.on("click", ".created-date-clear-btn", function (e) {
            e.preventDefault();
            e.stopPropagation();

            var settings = window.InstanceCollection && window.InstanceCollection[instanceId];
            if (!settings) {
                return;
            }

            draftFrom = today;
            draftTo = today;
            updateLabels();

            settings.filterParams.created_from = "";
            settings.filterParams.created_to = "";
            reloadInstance();
        });
    };
</script>
