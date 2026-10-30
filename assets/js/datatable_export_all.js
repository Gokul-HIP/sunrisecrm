/**
 * Server-side DataTables only keep the current page in the DOM.
 * Excel/Print therefore exported ~10 rows instead of the filtered total.
 * Fetch every row that matches the current filters, then run the native export.
 */
(function ($) {
    if (!$.fn.dataTable || !$.fn.dataTable.ext || !$.fn.dataTable.ext.buttons) {
        return;
    }

    var buttons = $.fn.dataTable.ext.buttons;

    if (!window.appTableNativeExcelAction && buttons.excelHtml5 && buttons.excelHtml5.action) {
        window.appTableNativeExcelAction = buttons.excelHtml5.action;
    }

    if (!window.appTableNativePrintAction && buttons.print && buttons.print.action) {
        window.appTableNativePrintAction = buttons.print.action;
    }

    if (!window.appTableExportFilteredData) {
        window.appTableExportFilteredData = function (buttonApi, e, dt, button, config, originalAction) {
            var tableNode = dt.table().node();
            var tableId = tableNode ? tableNode.id : "";
            var settings = (window.InstanceCollection && window.InstanceCollection[tableId]) ? window.InstanceCollection[tableId] : null;

            if (!originalAction) {
                return;
            }

            if (!settings || !settings.serverSide || !settings.source) {
                originalAction.call(buttonApi, e, dt, button, config);
                return;
            }

            if (buttonApi && typeof buttonApi.processing === "function") {
                buttonApi.processing(true);
            }

            if (typeof appLoader !== "undefined") {
                appLoader.show();
            }

            var params = {};
            try {
                params = dt.ajax.params() || {};
            } catch (err) {
                params = {};
            }

            params = $.extend(true, {}, params, settings.filterParams || {}, {
                export_all: 1,
                skip: 0,
                start: 0,
                limit: -1,
                length: -1,
                server_side: 1
            });

            $.ajax({
                url: settings.source,
                type: "POST",
                data: params,
                dataType: "json"
            }).done(function (json) {
                var rows = (json && $.isArray(json.data)) ? json.data : [];
                var seenIds = {};
                rows = $.grep(rows, function (row) {
                    if (!$.isArray(row) || row[1] == null) {
                        return true;
                    }
                    var id = $("<div>").html(row[1]).text().replace(/\D/g, "");
                    if (!id) {
                        return true;
                    }
                    if (seenIds[id]) {
                        return false;
                    }
                    seenIds[id] = true;
                    return true;
                });

                var $tmp = $("<table></table>").attr("aria-hidden", "true").css({
                    position: "absolute",
                    left: "-9999px",
                    top: "0"
                });

                var $theadRow = $("<tr></tr>");
                $.each(settings.columns || [], function (index, column) {
                    var title = column && column.title ? String(column.title) : "";
                    $theadRow.append($("<th></th>").html(title));
                });
                $tmp.append($("<thead></thead>").append($theadRow));

                var $tbody = $("<tbody></tbody>");
                $.each(rows, function (_, row) {
                    var $tr = $("<tr></tr>");
                    if ($.isArray(row)) {
                        $.each(row, function (i, cell) {
                            $tr.append($("<td></td>").html(cell == null ? "" : cell));
                        });
                    }
                    $tbody.append($tr);
                });

                var alreadyHasSummary = false;
                $.each(rows, function (_, row) {
                    if (!$.isArray(row) || !row[2]) {
                        return;
                    }
                    var label = $("<div>").html(row[2]).text().toLowerCase();
                    if (label.indexOf("total task points") !== -1 || label.indexOf("this month gained") !== -1) {
                        alreadyHasSummary = true;
                    }
                });

                if (!alreadyHasSummary && json) {
                    var appendSummaryRow = function (label, pointsValue, gainedValue) {
                        var $tr = $("<tr></tr>");
                        $.each(settings.columns || [], function (index, column) {
                            var title = $("<div>").html(column && column.title ? String(column.title) : "").text().toLowerCase();
                            var cellText = "";
                            if (index === 2) {
                                cellText = label || "";
                            } else if ((title === "points" || title === "point") && pointsValue !== "") {
                                cellText = pointsValue;
                            } else if ((title.indexOf("gained") !== -1 || title.indexOf("earned") !== -1) && gainedValue !== "") {
                                cellText = gainedValue;
                            }
                            $tr.append($("<td></td>").text(cellText));
                        });
                        $tbody.append($tr);
                    };

                    $tbody.append($("<tr></tr>"));
                    appendSummaryRow(json.export_total_task_points_label || "Total task points", json.total_given_points_label || "0", "");
                    appendSummaryRow(json.export_total_gained_points_label || "Gained points", "", json.total_gained_points_label || "0");
                    appendSummaryRow(json.previous_month_gained_points_title || "Previous month gained points", "", json.previous_month_gained_points_label || "0");
                    appendSummaryRow(json.this_month_gained_points_title || "This month gained points", "", json.this_month_gained_points_label || "0");
                }

                $tmp.append($tbody);
                $("body").append($tmp);

                var tmpApi = $tmp.DataTable({
                    paging: false,
                    searching: false,
                    info: false,
                    ordering: false,
                    autoWidth: false,
                    destroy: true,
                    dom: "t"
                });

                try {
                    originalAction.call(buttonApi, e, tmpApi, button, $.extend({}, config, {
                        title: "",
                        messageTop: "",
                        messageBottom: "",
                        footer: false
                    }));
                } finally {
                    tmpApi.destroy();
                    $tmp.remove();
                }
            }).fail(function () {
                if (typeof appAlert !== "undefined" && appAlert.error) {
                    appAlert.error("Export failed. Please try again.");
                }
            }).always(function () {
                if (buttonApi && typeof buttonApi.processing === "function") {
                    buttonApi.processing(false);
                }
                if (typeof appLoader !== "undefined") {
                    appLoader.hide();
                }
            });
        };
    }

    if (buttons.excelHtml5) {
        buttons.excelHtml5.action = function (e, dt, button, config) {
            window.appTableExportFilteredData(this, e, dt, button, config, window.appTableNativeExcelAction);
        };
    }

    if (buttons.print) {
        buttons.print.action = function (e, dt, button, config) {
            window.appTableExportFilteredData(this, e, dt, button, config, window.appTableNativePrintAction);
        };
    }
})(jQuery);
