<script type="text/javascript">
    (function () {
        var styleCss =
            '.task-points-summary{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;padding:10px 16px;border-bottom:1px solid #e5e7eb;background:#fff;}' +
            '.task-points-summary-dates{font-size:13px;color:#374151;font-weight:600;}' +
            '.task-points-summary-values{display:flex;flex-wrap:wrap;gap:24px;}' +
            '.task-status-summary{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:24px;flex:1 1 auto;}' +
            '.task-points-summary-item{display:flex;align-items:baseline;gap:8px;}' +
            '.task-points-summary-label{font-size:13px;color:#4b5563;}' +
            '.task-points-given-value,.task-points-gained-value,.task-todo-count,.task-in-progress-count,.task-in-review-count,.task-done-count{font-size:20px;font-weight:700;line-height:1;}' +
            '.task-todo-count{color:#e04b3c;}' +
            '.task-in-progress-count{color:#2b8fd6;}' +
            '.task-in-review-count{color:#6690F4;}' +
            '.task-done-count{color:#1fa85a;}' +
            '.task-points-summary .points-zero{color:#dc2626;}' +
            '.task-points-summary .points-low{color:#f87171;}' +
            '.task-points-summary .points-high{color:#16a34a;}' +
            '.all-tasks-view:not(.tasks-ui) .table-responsive{overflow:visible !important;}' +
            '.all-tasks-view:not(.tasks-ui) .task-list-sticky-title{background:#fff3e0 !important;border-bottom:1px solid #fdba74;}' +
            '.all-tasks-view:not(.tasks-ui) .task-list-sticky-title .nav-link,.all-tasks-view:not(.tasks-ui) .task-list-sticky-title a,.all-tasks-view:not(.tasks-ui) .task-list-sticky-title h4{background:transparent;}' +
            '.all-tasks-view:not(.tasks-ui) .task-list-sticky-title.nav-tabs.title li a{padding:8px 12px !important;}' +
            '.all-tasks-view:not(.tasks-ui) .task-list-sticky-title .title-tab h4{margin:4px 0 !important;padding:6px 12px !important;}' +
            '.all-tasks-view:not(.tasks-ui) .task-list-sticky-title .title-button-group{margin-top:0 !important;}' +
            '.all-tasks-view:not(.tasks-ui) .task-list-sticky-title .title-button-group .btn{margin:6px 4px !important;}' +
            '.all-tasks-view:not(.tasks-ui) .task-list-sticky-title .monthly-task-points-widget{margin:4px 8px 4px 0 !important;}' +
            '.all-tasks-view:not(.tasks-ui) .task-list-sticky-top{background:#fff8ee;box-shadow:0 1px 0 rgba(15,23,42,0.08);}' +
            '.all-tasks-view:not(.tasks-ui) .task-list-sticky-top .datatable-tools,.all-tasks-view:not(.tasks-ui) .task-list-sticky-top .created-date-filter-row,.all-tasks-view:not(.tasks-ui) .task-list-sticky-top .task-points-summary{background:#fff8ee;}' +
            '.all-tasks-view:not(.tasks-ui) .task-list-sticky-top .datatable-tools{padding-top:6px !important;padding-bottom:4px !important;margin:0 !important;}' +
            '.all-tasks-view:not(.tasks-ui) .task-list-sticky-top .created-date-filter-row{padding:0 15px 4px !important;}' +
            '.all-tasks-view:not(.tasks-ui) .task-list-sticky-top .created-date-filter-wrap{margin-bottom:0 !important;}' +
            '.all-tasks-view:not(.tasks-ui) .task-list-sticky-top .task-points-summary{padding:6px 16px !important;}' +
            '.all-tasks-view:not(.tasks-ui) #task-table{border-collapse:separate !important;border-spacing:0 !important;}' +
            '.all-tasks-view:not(.tasks-ui) #task-table thead th{background:#fff8ee !important;box-shadow:inset 0 -1px 0 #fdba74;padding-top:8px !important;padding-bottom:8px !important;}' +
            '.all-tasks-view:not(.tasks-ui) #task-table tbody tr:first-child td{padding-top:var(--task-first-row-pad, 4px) !important;}';

        if (!$("#task-points-summary-style").length) {
            $("head").append('<style id="task-points-summary-style"></style>');
        }
        $("#task-points-summary-style").text(styleCss);

        var pointClass = function (value) {
            value = parseFloat(value) || 0;
            if (value <= 0) {
                return "points-zero";
            }
            if (value < 50) {
                return "points-low";
            }
            return "points-high";
        };

        window.initTaskPointsSummary = function (tableId, summaryId) {
            var $table = $("#" + tableId);
            var $summary = $("#" + summaryId);
            if (!$table.length || !$summary.length || $summary.data("points-summary-ready")) {
                return;
            }

            $summary.data("points-summary-ready", true);

            var applySummary = function (json) {
                if (!json) {
                    return;
                }

                var given = json.total_given_points_label || "0";
                var gained = json.total_gained_points_label || "0";
                var $given = $summary.find(".task-points-given-value");
                var $gained = $summary.find(".task-points-gained-value");
                var $dates = $summary.find(".task-points-summary-dates");

                $given.removeClass("points-zero points-low points-high").addClass(pointClass(json.total_given_points)).text(given);
                $gained.removeClass("points-zero points-low points-high").addClass(pointClass(json.total_gained_points)).text(gained);
                var $countsRoot = $summary.closest(".tasks-ui");
                if (!$countsRoot.length) {
                    $countsRoot = $summary;
                }
                $countsRoot.find(".task-todo-count").text(json.todo_count || 0);
                $countsRoot.find(".task-in-progress-count").text(json.in_progress_count || 0);
                $countsRoot.find(".task-in-review-count").text(json.in_review_count || 0);
                $countsRoot.find(".task-done-count").text(json.done_count || 0);

                if (json.date_filter_applied && json.date_range_label) {
                    $dates.removeClass("hide").text(json.date_range_label);
                } else {
                    $dates.addClass("hide").text("");
                }
            };

            $table.on("xhr.dt", function (event, settings, json) {
                applySummary(json);
            });

            $summary.data("apply-summary", applySummary);
        };

        window.pinTaskListHeader = function (tableId, summaryId) {
            var $table = $("#" + tableId);
            var $wrapper = $table.closest(".dataTables_wrapper");
            var $summary = $("#" + summaryId);
            if (!$table.length || !$wrapper.length) {
                return;
            }

            if ($table.closest(".tasks-ui").length) {
                if (typeof window.initTasksUiLayout === "function") {
                    window.initTasksUiLayout();
                }
                return;
            }

            var $tools = $wrapper.find(".datatable-tools").first();
            var $dateRow = $wrapper.children(".created-date-filter-row").first();
            if (!$dateRow.length) {
                $dateRow = $wrapper.find(".created-date-filter-row").first();
            }

            var $sticky = $wrapper.children(".task-list-sticky-top").first();
            if (!$sticky.length) {
                $sticky = $('<div class="task-list-sticky-top"></div>');
                if ($tools.length) {
                    $tools.before($sticky);
                    $sticky.append($tools);
                } else {
                    $wrapper.prepend($sticky);
                }
                if ($dateRow.length) {
                    $sticky.append($dateRow);
                }
                if ($summary.length) {
                    $sticky.append($summary);
                }
            } else if ($summary.length && !$sticky.find("#" + summaryId).length) {
                $sticky.append($summary);
            }

            var $titleBar = $table.closest(".all-tasks-view").children(".nav.nav-tabs.title, .task-list-sticky-title").first();
            if (!$titleBar.length) {
                $titleBar = $(".all-tasks-view > .nav.nav-tabs.title, .all-tasks-view > .task-list-sticky-title").first();
            }
            if ($titleBar.length) {
                $titleBar.addClass("task-list-sticky-title");
            }

            var applyOffsets = function () {
                var navH = Math.round($("#default-navbar").outerHeight() || 65);
                var $ths = $table.find("thead tr").first().find("th");
                var scrollTop = $(window).scrollTop() || 0;

                if ($titleBar.length) {
                    $titleBar.css({
                        position: "sticky",
                        top: navH + "px",
                        zIndex: 32
                    });
                }

                var titleH = $titleBar.length ? Math.round($titleBar.outerHeight() || 0) : 0;
                $sticky.css({
                    position: "sticky",
                    top: (navH + titleH) + "px",
                    zIndex: 31
                });

                var stickyBottom = $sticky.length ? Math.round($sticky[0].getBoundingClientRect().bottom) : (navH + titleH);
                var theadTop = stickyBottom;

                // At page top, keep the column header in its natural place so it cannot slide over the first task.
                if (scrollTop <= 2) {
                    $ths.css({ position: "static", top: "" });
                    var theadEl = $table.find("thead").get(0);
                    if (theadEl) {
                        var naturalTop = Math.round(theadEl.getBoundingClientRect().top);
                        if (naturalTop > 0) {
                            theadTop = Math.min(stickyBottom, naturalTop);
                        }
                    }
                }

                $ths.css({
                    position: "sticky",
                    top: theadTop + "px",
                    zIndex: 30,
                    background: "#fff8ee"
                });

                var applyPad = function () {
                    var pad = 4;
                    if (($(window).scrollTop() || 0) <= 2) {
                        var thEl = $ths.get(0);
                        var firstRow = $table.find("tbody tr").first().get(0);
                        if (thEl && firstRow) {
                            var overlap = Math.ceil(thEl.getBoundingClientRect().bottom - firstRow.getBoundingClientRect().top);
                            if (overlap > 4) {
                                pad = overlap + 4;
                            }
                        }
                    }
                    $wrapper.css("--task-first-row-pad", pad + "px");
                };

                if (window.requestAnimationFrame) {
                    requestAnimationFrame(applyPad);
                } else {
                    applyPad();
                }
            };

            applyOffsets();
            setTimeout(applyOffsets, 150);
            setTimeout(applyOffsets, 400);
            $table.off("draw.dt.taskListSticky").on("draw.dt.taskListSticky", function () {
                setTimeout(applyOffsets, 0);
            });
            $(window).off("resize.taskListSticky").on("resize.taskListSticky", applyOffsets);
        };
    })();
</script>
