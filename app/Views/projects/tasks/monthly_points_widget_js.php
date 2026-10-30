<script type="text/javascript">
    (function () {
        if (window.initMonthlyTaskPointsWidget) {
            return;
        }

        if (!$("#monthly-task-points-style").length) {
            $("head").append(
                '<style id="monthly-task-points-style">' +
                '.monthly-task-points-widget{display:inline-flex;align-items:center;gap:10px;margin-right:12px;vertical-align:middle;}' +
                '.monthly-task-points-widget .monthly-task-points-month-label{margin:0;font-size:12px;white-space:nowrap;}' +
                '.monthly-task-points-widget .task-points-month-filter{width:160px;height:34px;padding:4px 8px;}' +
                '.monthly-task-points-widget .monthly-task-points-total{min-width:90px;text-align:left;line-height:1.15;}' +
                '.monthly-task-points-widget .task-points-month-value{font-size:20px;line-height:1;}' +
                '.monthly-task-points-widget .task-points-month-value.points-zero{color:#dc2626 !important;}' +
                '.monthly-task-points-widget .task-points-month-value.points-low{color:#f87171 !important;}' +
                '.monthly-task-points-widget .task-points-month-value.points-high{color:#16a34a !important;}' +
                '.monthly-task-points-light{color:#1f2937;}' +
                '.monthly-task-points-light .task-points-month-filter{color:#1f2937;background:#fff;border-color:#ced4da;}' +
                '.monthly-task-points-dark{color:#fff;}' +
                '.monthly-task-points-dark .monthly-task-points-month-label,.monthly-task-points-dark .task-points-month-caption{color:#fff;}' +
                '.monthly-task-points-dark .task-points-month-filter{color:#1f2937;background:#fff;}' +
                '</style>'
            );
        }

        window.initMonthlyTaskPointsWidget = function (rootId) {
            var $root = $("#" + rootId);
            if (!$root.length || $root.data("points-ready")) {
                return;
            }

            $root.data("points-ready", true);

            var defaultUserId = String($root.data("default-user") || "");
            var userSelector = $root.data("user-selector") || "[name='specific_user_id']";

            var currentUserId = function () {
                var $user = $(userSelector).first();
                var userId = $user.length ? String($user.val() || "") : "";
                return userId || defaultUserId;
            };

            var applyPointColor = function (value) {
                var numeric = parseFloat(value);
                if (isNaN(numeric)) {
                    numeric = 0;
                }
                var colorClass = "points-zero";
                if (numeric >= 50) {
                    colorClass = "points-high";
                } else if (numeric > 0) {
                    colorClass = "points-low";
                }
                $root.find(".task-points-month-value")
                    .removeClass("points-zero points-low points-high")
                    .addClass(colorClass);
            };

            var loadPoints = function () {
                var userId = currentUserId();
                var month = $root.find(".task-points-month-filter").val();
                if (!userId) {
                    $root.find(".task-points-month-value").text("0");
                    applyPointColor(0);
                    return;
                }

                $.ajax({
                    url: "<?php echo_uri("projects/monthly_task_points"); ?>",
                    type: "GET",
                    dataType: "json",
                    data: {user_id: userId, month: month},
                    success: function (result) {
                        if (result && result.success) {
                            $root.find(".task-points-month-value").text(result.points);
                            applyPointColor(typeof result.points_raw !== "undefined" ? result.points_raw : result.points);
                        }
                    }
                });
            };

            $root.on("change", ".task-points-month-filter", loadPoints);
            $(document).on("change", userSelector, loadPoints);
            loadPoints();
        };
    })();
</script>
