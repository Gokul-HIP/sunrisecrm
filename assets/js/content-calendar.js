/*
 * Content Calendar (RISE CRM). jQuery + AJAX (JSON). No framework.
 * Two screens share this file:
 *   - window.CC_CONFIG      : calendar page (month grid, table, day panel, column designer,
 *                             Add client popup, Import wizard)
 *   - window.CC_ITEM_FORM   : add / edit content page
 * Every popup (Add client, columns, copy columns, targets, confirmations, Import) uses the one openModal() component.
 * All dynamic text is escaped with CC.esc() before it is put in HTML.
 */
(function ($) {
    "use strict";

    var CC = window.CC = window.CC || {};

    var MONTH_NAMES = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
    var MONTH_SHORT = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
    var DAY_NAMES = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
    var DAY_SHORT = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
    var TYPE_LABELS = {text: "cc_type_text", long: "cc_type_long", select: "cc_type_select", multi: "cc_type_multi", date: "cc_type_date", link: "cc_type_link", number: "cc_type_number", check: "cc_type_check", file: "cc_type_file"};
    var DEFAULT_COLORS = ["#f3f4f6", "#374151"];

    /* ---------------------------------------------------------------- */
    /* utilities                                                         */
    /* ---------------------------------------------------------------- */

    CC.lang = {};

    function L(key) {
        return CC.lang[key] !== undefined ? CC.lang[key] : key;
    }

    CC.L = L;

    CC.esc = function (value) {
        if (value === null || value === undefined) {
            return "";
        }

        return String(value)
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#39;");
    };

    var esc = CC.esc;

    function pad(n) {
        return (n < 10 ? "0" : "") + n;
    }

    function ymd(y, m, d) {
        return y + "-" + pad(m) + "-" + pad(d);
    }

    function parseYmd(s) {
        var p = String(s || "").split("-");
        return {y: parseInt(p[0], 10), m: parseInt(p[1], 10), d: parseInt(p[2], 10)};
    }

    function fmtDate(s) {
        var p = parseYmd(s);
        if (!p.y || !p.m || !p.d) {
            return s || "";
        }

        return p.d + " " + MONTH_SHORT[p.m - 1] + " " + p.y;
    }

    function fmtLongDate(s) {
        var p = parseYmd(s);
        var dt = new Date(p.y, p.m - 1, p.d);
        return DAY_NAMES[dt.getDay()] + ", " + p.d + " " + MONTH_NAMES[p.m - 1] + " " + p.y;
    }

    function sprintf1(text, value) {
        return String(text).replace("%s", value);
    }

    function toast(message, type) {
        message = message || "";
        if (window.appAlert) {
            if (type === "error") {
                window.appAlert.error(message, {duration: 6000});
            } else {
                window.appAlert.success(message, {duration: 3000});
            }
            return;
        }

        var $t = $("<div class='cc-toast' role='status'></div>").addClass(type === "error" ? "cc-toast-error" : "").text(message).appendTo("body");
        setTimeout(function () {
            $t.remove();
        }, 4000);
    }

    CC.toast = toast;

    function csrfData() {
        var data = {};
        if (window.AppHelper && AppHelper.csrfTokenName) {
            data[AppHelper.csrfTokenName] = AppHelper.csrfHash;
        }

        return data;
    }

    //POST JSON. Resolves with the result when success is true, rejects with {message, errors, status}.
    function post(url, data) {
        var d = $.Deferred();
        $.ajax({
            url: url,
            type: "POST",
            dataType: "json",
            data: $.extend({}, csrfData(), data || {})
        }).done(function (result) {
            if (result && result.success) {
                d.resolve(result);
            } else {
                d.reject({message: (result && result.message) || L("cc_error"), errors: (result && result.errors) || {}, status: 200});
            }
        }).fail(function (xhr) {
            var message = L("cc_error");
            var errors = {};
            if (xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
                errors = xhr.responseJSON.errors || {};
            } else if (xhr.status === 403) {
                message = L("cc_forbidden");
            }

            d.reject({message: message, errors: errors, status: xhr.status});
        });

        return d.promise();
    }

    CC.post = post;

    function chipStyle(colors, name) {
        var c = (colors && name !== undefined && colors[String(name).toLowerCase()]) || DEFAULT_COLORS;
        return "background:" + c[0] + ";color:" + c[1] + ";";
    }

    /* ---------------------------------------------------------------- */
    /* modal: ONE component for every popup (Add client, columns,        */
    /* copy columns, targets, confirmations, the import wizard)          */
    /* ---------------------------------------------------------------- */

    var modalStack = [];
    var FOCUSABLE = "a[href], button:not([disabled]), input:not([disabled]):not([type='hidden']), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex='-1'])";

    /**
     * options:
     *   title, body (html), buttons [{label, className, onClick(modal, $btn), close:false, disabled}],
     *   size: "wide" (import wizard, 960px) or default (500px),
     *   isDirty(): true when closing must ask first (Esc, backdrop, the x button),
     *   closeOnBackdrop (default true), returnFocus (element or function) , focus (selector for the first focus),
     *   onOpen(modal), onClose()
     * Behaviour: focus goes into the dialog, Tab is trapped inside it, the page behind does not scroll,
     * Esc / backdrop / x go through requestClose(), and focus returns to the button that opened it.
     */
    function openModal(options) {
        var $root = $("#cc-modal-root");
        if (!$root.length) {
            $root = $("<div id='cc-modal-root'></div>").appendTo("body");
        }

        var $overlay = $("<div class='cc-modal-overlay'></div>");
        var $dialog = $("<div class='cc-modal' role='dialog' aria-modal='true'></div>");
        if (options.size === "wide") {
            $dialog.addClass("cc-modal-wide");
        }

        var titleId = "cc-modal-title-" + (new Date().getTime()) + "-" + modalStack.length;
        $dialog.attr("aria-labelledby", titleId);
        $dialog.append("<div class='cc-modal-head'><h3 class='cc-modal-title' id='" + titleId + "'>" + esc(options.title) + "</h3><button type='button' class='cc-modal-close' aria-label='" + esc(L("close")) + "'>&times;</button></div>");
        var $body = $("<div class='cc-modal-body'></div>").html(options.body || "");
        $dialog.append($body);
        var $foot = $("<div class='cc-modal-foot'></div>");
        $dialog.append($foot);
        $overlay.append($dialog);
        $root.append($overlay);
        $("body").addClass("cc-scroll-lock");

        var previousFocus = document.activeElement;
        var closed = false;

        var modal = {
            $el: $dialog,
            $overlay: $overlay,
            $body: $body,
            $foot: $foot,
            busy: false,
            options: options,
            close: function () {
                if (closed) {
                    return;
                }

                closed = true;
                $overlay.remove();
                modalStack = $.grep(modalStack, function (m) {
                    return m !== modal;
                });

                if (!modalStack.length) {
                    $("body").removeClass("cc-scroll-lock");
                }

                var target = options.returnFocus;
                if (typeof target === "function") {
                    target = target();
                }
                target = target || previousFocus;
                if (target && target.focus && document.body.contains(target)) {
                    try {
                        target.focus();
                    } catch (e) {
                    }
                }

                if (options.onClose) {
                    options.onClose(modal);
                }
            },
            //the way users close it: asks first when there is unsaved input
            requestClose: function () {
                if (modal.busy) {
                    return;
                }

                if (options.isDirty && options.isDirty()) {
                    confirmModal(L("cc_discard_changes_confirm"), function () {
                        modal.close();
                    }, L("cc_discard"), L("cc_keep_editing"), true);
                    return;
                }

                modal.close();
            },
            setError: function (message) {
                $body.find(".cc-modal-error").first().text(message || "");
            },
            setTitle: function (title) {
                $dialog.find(".cc-modal-title").text(title);
            },
            setButtons: function (buttons) {
                $foot.empty();
                $.each(buttons || [], function (i, button) {
                    var $btn = $("<button type='button' class='cc-btn'></button>").addClass(button.className || "").text(button.label);
                    if (button.disabled) {
                        $btn.prop("disabled", true);
                    }

                    $btn.on("click", function () {
                        if (button.onClick) {
                            button.onClick(modal, $btn);
                        }

                        if (button.close !== false && !button.onClick) {
                            modal.close();
                        }
                    });
                    $foot.append($btn);
                });
            }
        };

        modal.setButtons(options.buttons);

        $dialog.find(".cc-modal-close").on("click", function () {
            modal.requestClose();
        });

        var downOnOverlay = false;
        $overlay.on("mousedown", function (e) {
            downOnOverlay = (e.target === $overlay[0]);
        });
        $overlay.on("mouseup", function (e) {
            if (downOnOverlay && e.target === $overlay[0] && options.closeOnBackdrop !== false) {
                modal.requestClose();
            }
            downOnOverlay = false;
        });

        modalStack.push(modal);
        if (options.onOpen) {
            options.onOpen(modal);
        }

        setTimeout(function () {
            if (closed) {
                return;
            }

            var $first = $body.find(options.focus || "input:visible:not([type='file']):not([type='hidden']), select:visible, textarea:visible").first();
            if ($first.length) {
                $first.trigger("focus");
            } else {
                $foot.find("button:not([disabled])").last().trigger("focus");
            }
        }, 30);

        return modal;
    }

    $(document).on("keydown", function (e) {
        if (!modalStack.length) {
            return;
        }

        var modal = modalStack[modalStack.length - 1];

        if (e.key === "Escape") {
            e.preventDefault();
            modal.requestClose();
            return;
        }

        if (e.key === "Tab") {
            //focus trap: Tab and Shift+Tab stay inside the top dialog
            var $items = modal.$el.find(FOCUSABLE).filter(":visible");
            if (!$items.length) {
                e.preventDefault();
                return;
            }

            var first = $items[0], last = $items[$items.length - 1], active = document.activeElement;
            if (!$.contains(modal.$el[0], active)) {
                e.preventDefault();
                first.focus();
            } else if (e.shiftKey && active === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && active === last) {
                e.preventDefault();
                first.focus();
            }
        }
    });

    CC.openModal = openModal;

    //yes / no question. noLabel and neutral (not red) are optional.
    function confirmModal(message, onYes, yesLabel, noLabel, neutral) {
        return openModal({
            title: L("cc_please_confirm"),
            body: "<p>" + esc(message) + "</p>",
            buttons: [
                {label: noLabel || L("cancel"), className: ""},
                {label: yesLabel || L("cc_yes_delete"), className: neutral ? "cc-btn-primary" : "cc-btn-danger", onClick: function (modal) {
                        modal.close();
                        onYes();
                    }}
            ]
        });
    }

    CC.confirm = confirmModal;

    function fmt(text) {
        var args = Array.prototype.slice.call(arguments, 1);
        var i = 0;
        return String(text).replace(/%s/g, function () {
            return i < args.length ? args[i++] : "";
        });
    }

    function nf(n) {
        return String(n === null || n === undefined ? 0 : n).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }

    function postsLabel(n) {
        return n === 1 ? fmt(L("cc_n_post"), 1) : fmt(L("cc_n_posts"), n);
    }

    /* ================================================================ */
    /* IMPORT WIZARD (Choose, Review, Result)                            */
    /* ctx: urls, perms, limits, calendars(), calendarId(), reload(), goTo(calendarId, ym) */
    /* ================================================================ */

    function openImportWizard(ctx) {
        var urls = ctx.urls;
        var perms = ctx.perms;
        var limits = ctx.limits || {};
        var maxBytes = limits.max_bytes || 5242880;
        var maxRows = limits.max_rows || 5000;
        var exts = limits.extensions || ["csv", "tsv", "txt", "xlsx", "xls", "ods"];
        var NEW_TYPES = ["text", "long", "select", "multi", "date", "link", "number", "check"];
        var CORE_MAP = [["date", "cc_imp_map_date"], ["type", "cc_imp_map_type"], ["title", "cc_imp_map_title"], ["status", "cc_imp_map_status"]];

        var current = ctx.calendarId();
        var W = {
            step: 1,
            file: null,
            calendarId: current ? current : ((perms.add_client || perms.admin_manage) ? "new" : 0),
            fileType: "auto",
            parsed: null,
            mode: "single",
            single: -1,
            detail: -1,
            options: {year: new Date().getFullYear(), carry_blank_date: true, skip_duplicates: true},
            sheets: [],
            previews: {},
            previewing: false,
            previewError: "",
            seq: 0,
            timer: null,
            why: {},
            running: false,
            runError: "",
            results: null,
            allOk: false,
            changed: false
        };

        var modal = openModal({
            title: L("cc_imp_title"),
            size: "wide",
            closeOnBackdrop: false,
            body: "<div class='cc-imp-root'></div>",
            returnFocus: function () {
                return document.getElementById("cc-import-open");
            },
            onClose: function () {
                if (W.timer) {
                    clearTimeout(W.timer);
                }
                W.seq++;
                if (W.changed) {
                    ctx.reload();
                }
            }
        });

        var $root = modal.$body.children(".cc-imp-root");

        function sheetBy(index) {
            var found = null;
            $.each(W.sheets, function (i, s) {
                if (s.index === index) {
                    found = s;
                }
            });
            return found;
        }

        function usableCount() {
            var n = 0;
            $.each(W.sheets, function (i, s) {
                if (s.usable) {
                    n++;
                }
            });
            return n;
        }

        function calendarName(id) {
            var name = "";
            $.each(ctx.calendars(), function (i, c) {
                if (String(c.id) === String(id)) {
                    name = c.name;
                }
            });
            return name;
        }

        /* ---------- steps ---------- */

        function stepperHtml() {
            var names = [L("cc_imp_step_choose"), L("cc_imp_step_review"), L("cc_imp_step_result")];
            var html = "<ol class='cc-steps' aria-label='" + esc(L("cc_imp_stepper")) + "'>";
            $.each(names, function (i, name) {
                var n = i + 1;
                var cls = n === W.step ? "current" : (n < W.step ? "done" : "");
                html += "<li class='cc-step " + cls + "'" + (n === W.step ? " aria-current='step'" : "") + "><span class='cc-step-num'>" + (n < W.step ? "&#10003;" : n) + "</span><span class='cc-step-label'>" + esc(name) + "</span></li>";
            });
            return html + "</ol>";
        }

        function step1Html() {
            var clientOptions = "";
            $.each(ctx.calendars(), function (i, c) {
                clientOptions += "<option value='" + c.id + "'" + (String(W.calendarId) === String(c.id) ? " selected" : "") + ">" + esc(c.name) + "</option>";
            });
            if (perms.add_client || perms.admin_manage) {
                clientOptions += "<option value='new'" + (W.calendarId === "new" ? " selected" : "") + ">" + esc(L("cc_imp_new_client_opt")) + "</option>";
            }

            var typeOptions = "";
            $.each([["auto", "cc_imp_type_auto"], ["csv", "cc_imp_type_csv"], ["excel", "cc_imp_type_excel"], ["ods", "cc_imp_type_ods"]], function (i, t) {
                typeOptions += "<option value='" + t[0] + "'" + (W.fileType === t[0] ? " selected" : "") + ">" + esc(L(t[1])) + "</option>";
            });

            var drop;
            if (W.file) {
                drop = "<div class='cc-drop-file'>" + esc(W.file.name) + "</div><div class='cc-muted'>" + esc(Math.max(1, Math.round(W.file.size / 1024)) + " KB") + "</div>" +
                        "<div><button type='button' class='cc-btn cc-btn-sm' data-act='browse'>" + esc(L("cc_imp_browse")) + "</button> " +
                        "<button type='button' class='cc-btn cc-btn-sm cc-btn-danger-ghost' data-act='removefile'>" + esc(L("cc_imp_remove_file")) + "</button></div>";
            } else {
                drop = "<div>" + esc(L("cc_imp_drop")) + "</div><button type='button' class='cc-btn' data-act='browse'>" + esc(L("cc_imp_browse")) + "</button>";
            }

            return "<div class='cc-modal-error' role='alert'></div>" +
                    "<p class='cc-muted'>" + esc(L("cc_imp_help")) + "</p>" +
                    "<div class='cc-imp-grid'>" +
                    "<div class='cc-field'><label class='cc-label' for='cc-imp-client'>" + esc(L("cc_imp_client")) + "</label><select id='cc-imp-client' class='cc-input cc-input-full' data-act='client'>" + clientOptions + "</select></div>" +
                    "<div class='cc-field'><label class='cc-label' for='cc-imp-type'>" + esc(L("cc_imp_file_type")) + "</label><select id='cc-imp-type' class='cc-input cc-input-full' data-act='ftype'>" + typeOptions + "</select></div>" +
                    "</div>" +
                    "<div class='cc-label' style='margin-top:16px'>" + esc(L("cc_imp_file")) + "</div>" +
                    "<div class='cc-drop' id='cc-imp-drop'>" + drop +
                    "<input type='file' id='cc-imp-file' hidden accept='" + esc($.map(exts, function (e) {
                        return "." + e;
                    }).join(",")) + "'></div>" +
                    "<p class='cc-muted' style='margin-top:8px'>" + esc(fmt(L("cc_imp_limits"), Math.round(maxBytes / 1048576), nf(maxRows), exts.join(", "))) + "</p>" +
                    "<div class='cc-progress hide' id='cc-imp-progress'><div class='cc-progress-fill'></div></div>" +
                    "<div class='cc-muted hide' id='cc-imp-progress-text' role='status'></div>";
        }

        function step2ShellHtml() {
            var p = W.parsed;
            var html = "<div class='cc-modal-error' role='alert'></div>";
            html += "<div class='cc-muted'>" + esc(fmt(L("cc_imp_detected"), p.type_label + (p.sheets.length === 1 && p.sheets[0].encoding ? ", " + p.sheets[0].encoding + ", " + L("cc_imp_delim") + " " + (p.sheets[0].delimiter === "\t" ? "Tab" : p.sheets[0].delimiter) : ""))) +
                    (p.sheets.length > 1 ? " - " + esc(fmt(L("cc_imp_sheets_found"), p.sheets.length)) : "") + "</div>";

            $.each(p.notes || [], function (i, note) {
                html += "<div class='cc-imp-note warn'>" + esc(note) + "</div>";
            });

            if (usableCount() > 1) {
                html += "<div class='cc-imp-options' role='radiogroup'>" +
                        "<label class='cc-check'><input type='radio' name='cc-imp-mode' value='single' data-act='mode'" + (W.mode === "single" ? " checked" : "") + "><span>" + esc(L("cc_imp_mode_one")) + "</span></label>" +
                        "<label class='cc-check'><input type='radio' name='cc-imp-mode' value='multi' data-act='mode'" + (W.mode === "multi" ? " checked" : "") + "><span>" + esc(L("cc_imp_mode_each")) + "</span></label>" +
                        "</div>";
            }

            if (W.mode === "single" && W.calendarId !== "new") {
                html += "<div class='cc-muted'>" + esc(L("cc_imp_import_into")) + ": <strong>" + esc(calendarName(W.calendarId)) + "</strong></div>";
            }

            html += "<div class='cc-imp-options'>" +
                    "<label class='cc-imp-inline'><span>" + esc(L("cc_imp_year")) + "</span><input type='text' inputmode='numeric' maxlength='4' class='cc-input' data-act='opt-year' aria-label='" + esc(L("cc_imp_year")) + "' value='" + esc(W.options.year) + "'></label>" +
                    "<label class='cc-check'><input type='checkbox' data-act='opt-dup'" + (W.options.skip_duplicates ? " checked" : "") + "><span>" + esc(L("cc_imp_opt_skip_dup")) + "</span></label>" +
                    "<label class='cc-check'><input type='checkbox' data-act='opt-carry'" + (W.options.carry_blank_date ? " checked" : "") + "><span>" + esc(L("cc_imp_opt_carry")) + "</span></label>" +
                    "</div>";

            html += "<div id='cc-imp-sheets'></div>";
            return html;
        }

        /* ---------- sheet cards (step 2) ---------- */

        function targetSelectHtml(s) {
            var html = "<select class='cc-select' data-act='target' data-index='" + s.index + "' data-fk='target-" + s.index + "' aria-label='" + esc(L("cc_imp_import_into")) + "'>";
            html += "<option value=''>" + esc(L("cc_imp_no_target")) + "</option>";
            $.each(ctx.calendars(), function (i, c) {
                html += "<option value='" + c.id + "'" + (s.target === String(c.id) ? " selected" : "") + ">" + esc(c.name) + "</option>";
            });
            if (perms.add_client || perms.admin_manage) {
                html += "<option value='new'" + (s.target === "new" ? " selected" : "") + ">" + esc(fmt(L("cc_imp_create_named"), s.new_name || s.name)) + "</option>";
            }
            html += "</select>";
            return html;
        }

        function newNameHtml(s) {
            return "<input type='text' class='cc-input' maxlength='120' data-act='newname' data-index='" + s.index + "' data-fk='newname-" + s.index + "' placeholder='" + esc(L("cc_imp_new_client_name")) + "' aria-label='" + esc(L("cc_imp_new_client_name")) + "' value='" + esc(s.new_name) + "'>";
        }

        function summaryHtml(s, entry) {
            var sm = entry.summary;
            if (!sm) {
                return "";
            }

            var cols = sm.new_columns || [];
            var colNames = $.map(cols, function (c) {
                return c.label;
            }).join(", ");

            var monthsNew = sm.months_new;
            var monthsCount = $.isArray(monthsNew) ? monthsNew.length : (parseInt(monthsNew, 10) || 0);
            var html = "<div class='cc-sum'>" +
                    box(L("cc_imp_sum_import"), nf(sm.to_import)) +
                    box(L("cc_imp_sum_skipped"), nf(sm.skipped)) +
                    box(L("cc_imp_sum_cols"), nf(cols.length) + (colNames ? "<div class='cc-muted'>" + esc(colNames) + "</div>" : "")) +
                    box(L("cc_imp_sum_opts"), nf(sm.options_created)) +
                    box(L("cc_imp_sum_range"), sm.date_min ? esc(fmtDate(sm.date_min) + " - " + fmtDate(sm.date_max)) : "-", true) +
                    box(L("cc_imp_sum_months"), nf(monthsCount) + ($.isArray(monthsNew) && monthsNew.length ? "<div class='cc-muted'>" + esc(monthsNew.join(", ")) + "</div>" : "")) +
                    "</div>";

            function box(label, value, small) {
                var v = (typeof value === "string" && value.indexOf("<") === -1 && !small) ? esc(value) : value;
                return "<div class='cc-sum-box'><div class='cc-sum-label'>" + esc(label) + "</div><div class='cc-sum-value" + (small ? " small" : "") + "'>" + v + "</div></div>";
            }

            var notes = "";
            if (sm.header_repeats > 0) {
                notes += note(fmt(L("cc_imp_info_repeats"), sm.header_repeats));
            }
            if (sm.notes > 0) {
                notes += note(fmt(L("cc_imp_info_notes"), sm.notes));
            }
            if (sm.invalid_cells > 0) {
                notes += note(fmt(L("cc_imp_info_invalid_cells"), sm.invalid_cells));
            }
            if (sm.truncated_cells > 0) {
                notes += note(fmt(L("cc_imp_info_truncated"), sm.truncated_cells));
            }
            if (sm.locked_option_cells > 0) {
                notes += note(fmt(L("cc_imp_info_locked_cells"), sm.locked_option_cells) + " " + L("cc_imp_needs_options"), "warn");
            }
            if (sm.locked_columns && sm.locked_columns.length) {
                notes += note(fmt(L("cc_imp_info_locked_columns"), sm.locked_columns.join(", ")) + " (" + L("cc_imp_needs_admin") + ")", "warn");
            }

            function note(text, cls) {
                return "<div class='cc-imp-note " + (cls || "") + "'>" + esc(text) + "</div>";
            }

            var why = "";
            if (entry.skipped && entry.skipped.length) {
                var open = !!W.why[s.index];
                why = "<button type='button' class='cc-btn cc-btn-sm' data-act='why' data-index='" + s.index + "' aria-expanded='" + (open ? "true" : "false") + "'>" + esc(open ? L("cc_imp_hide") : L("cc_imp_see_why")) + " (" + nf(sm.skipped) + ")</button>" +
                        "<ul class='cc-reasons" + (open ? "" : " hide") + "' data-why='" + s.index + "'>";
                $.each(entry.skipped, function (i, r) {
                    why += "<li>" + esc(fmt(L("cc_imp_row_n"), r.row)) + ": " + esc(r.reason) + "</li>";
                });
                why += "</ul>";
                if (sm.skipped > entry.skipped.length) {
                    why += "<div class='cc-muted'>" + esc(L("cc_imp_skipped_more")) + "</div>";
                }
            }

            return html + notes + why;
        }

        function mappingHtml(s, entry) {
            if (!entry.columns || !entry.columns.length) {
                return "";
            }

            var h = "<div class='cc-imp-title'>" + esc(L("cc_imp_review_columns")) + "</div><div class='cc-scroll-box'><table class='cc-imp-table'><thead><tr>" +
                    "<th scope='col'>" + esc(L("cc_imp_col_header")) + "</th><th scope='col'>" + esc(L("cc_imp_col_samples")) + "</th><th scope='col'>" + esc(L("cc_imp_col_detected")) + "</th><th scope='col'>" + esc(L("cc_imp_col_maps")) + "</th></tr></thead><tbody>";

            $.each(entry.columns, function (i, col) {
                var map = col.map || {target: "skip", type: "", label: ""};
                var sel = "<select class='cc-select' data-act='map' data-index='" + s.index + "' data-col='" + col.index + "' data-fk='map-" + s.index + "-" + col.index + "' aria-label='" + esc(L("cc_imp_col_maps") + ": " + col.header) + "'>";
                sel += "<optgroup label='" + esc(L("cc_imp_map_core")) + "'>";
                $.each(CORE_MAP, function (j, c) {
                    sel += "<option value='" + c[0] + "'" + (map.target === c[0] ? " selected" : "") + ">" + esc(L(c[1])) + "</option>";
                });
                sel += "</optgroup>";
                if (entry.target_columns && entry.target_columns.length) {
                    sel += "<optgroup label='" + esc(L("cc_imp_map_existing")) + "'>";
                    $.each(entry.target_columns, function (j, c) {
                        sel += "<option value='col:" + esc(c.key) + "'" + (map.target === "col:" + c.key ? " selected" : "") + ">" + esc(c.label) + "</option>";
                    });
                    sel += "</optgroup>";
                }
                sel += "<option value='new'" + (map.target === "new" ? " selected" : "") + ">" + esc(L("cc_imp_map_new")) + "</option>";
                sel += "<option value='skip'" + (map.target === "skip" ? " selected" : "") + ">" + esc(L("cc_imp_map_skip")) + "</option></select>";

                var extra = "";
                if (map.target === "new") {
                    var typeOptions = "";
                    $.each(NEW_TYPES, function (j, t) {
                        typeOptions += "<option value='" + t + "'" + (map.type === t ? " selected" : "") + ">" + esc(L(TYPE_LABELS[t])) + "</option>";
                    });
                    extra = "<div class='cc-map-extra'>" +
                            "<input type='text' class='cc-input' maxlength='60' data-act='mapname' data-index='" + s.index + "' data-col='" + col.index + "' data-fk='mapname-" + s.index + "-" + col.index + "' value='" + esc(map.label || col.header) + "' aria-label='" + esc(L("cc_imp_new_col_name")) + "' placeholder='" + esc(L("cc_imp_new_col_name")) + "'>" +
                            "<select class='cc-select' data-act='maptype' data-index='" + s.index + "' data-col='" + col.index + "' data-fk='maptype-" + s.index + "-" + col.index + "' aria-label='" + esc(L("cc_imp_new_col_type")) + "'>" + typeOptions + "</select></div>";
                }

                var badges = "";
                if (col.locked) {
                    badges += " <span class='cc-badge cc-badge-warn'>" + esc(L("cc_imp_needs_admin")) + "</span>";
                }
                if (col.options_locked) {
                    badges += " <span class='cc-badge cc-badge-warn'>" + esc(L("cc_imp_needs_options")) + "</span>";
                }
                if (col.new_options && col.new_options.length) {
                    badges += " <span class='cc-badge'>" + esc(fmt(L("cc_imp_n_new_options"), col.new_options.length)) + "</span>";
                }

                h += "<tr><td><strong>" + esc(col.letter) + "</strong> " + esc(col.header) + "</td>" +
                        "<td class='cc-imp-samples'>" + esc((col.samples || []).slice(0, 3).join(" | ")) + "</td>" +
                        "<td>" + esc(L(TYPE_LABELS[col.detected_type] || "cc_type_text")) + "</td>" +
                        "<td>" + sel + extra + (badges ? "<div style='margin-top:6px'>" + badges + "</div>" : "") + "</td></tr>";
            });

            return h + "</tbody></table></div>";
        }

        function previewTableHtml(entry) {
            if (!entry.labels || !entry.preview_rows || !entry.preview_rows.length) {
                return "";
            }

            var h = "<div class='cc-imp-title'>" + esc(fmt(L("cc_imp_preview_title"), entry.preview_rows.length)) + "</div><div class='cc-scroll-box'><table class='cc-imp-table'><thead><tr><th scope='col'>#</th>";
            $.each(entry.labels, function (i, label) {
                h += "<th scope='col'>" + esc(label) + "</th>";
            });
            h += "</tr></thead><tbody>";
            $.each(entry.preview_rows, function (i, row) {
                h += "<tr><td>" + esc(row.row) + "</td>";
                $.each(row.cells, function (j, cell) {
                    h += "<td>" + esc(cell) + "</td>";
                });
                h += "</tr>";
            });
            return h + "</tbody></table></div>";
        }

        function sheetBodyHtml(s) {
            var entry = W.previews[s.index];
            if (!entry) {
                return "<div class='cc-muted' role='status'>" + esc(L("cc_imp_working_preview")) + "</div>";
            }

            var h = "";
            if (entry.error) {
                h += "<div class='cc-imp-note bad' role='alert'>" + esc(entry.error) + "</div>";
            }

            $.each(entry.warnings || [], function (i, w) {
                h += "<div class='cc-imp-note warn'>" + esc(w) + "</div>";
            });

            var isDetail = (W.detail === s.index);
            if (isDetail) {
                var shown = s.header_override || entry.header_row || "";
                h += "<div class='cc-imp-options'><label class='cc-imp-inline'><span>" + esc(L("cc_imp_header_on_row")) + "</span><input type='text' inputmode='numeric' maxlength='6' class='cc-input' data-act='hdr' data-index='" + s.index + "' data-fk='hdr-" + s.index + "' value='" + esc(shown) + "' aria-label='" + esc(L("cc_imp_header_on_row")) + "'></label>" +
                        "<span class='cc-muted'>" + esc(fmt(L("cc_imp_detected_row"), entry.detected_header_row || s.detected_header || "-")) + "</span></div>";
            }

            if (entry.summary) {
                h += summaryHtml(s, entry);
            }

            if (isDetail) {
                h += mappingHtml(s, entry) + previewTableHtml(entry);
            }

            return h;
        }

        function sheetCardHtml(s) {
            var single = W.mode === "single";
            var showPick = W.sheets.length > 1;
            var selected = s.checked;

            var h = "<div class='cc-sheet" + (selected ? "" : " off") + "' data-index='" + s.index + "'><div class='cc-sheet-head'>";

            var label = "<span class='cc-sheet-name' title='" + esc(s.name) + "'>" + esc(s.name) + "</span> <span class='cc-muted'>" + esc(fmt(L("cc_imp_rows_n"), nf(s.data_rows))) + "</span>";
            if (showPick) {
                var input = single
                        ? "<input type='radio' name='cc-imp-sheet' data-act='pick' data-index='" + s.index + "' data-fk='pick-" + s.index + "'" + (selected ? " checked" : "") + (s.usable ? "" : " disabled") + ">"
                        : "<input type='checkbox' data-act='check' data-index='" + s.index + "' data-fk='check-" + s.index + "'" + (selected ? " checked" : "") + (s.usable ? "" : " disabled") + ">";
                h += "<label class='cc-sheet-pick'>" + input + label + "</label>";
            } else {
                h += "<div class='cc-sheet-pick'>" + label + "</div>";
            }

            if (!s.usable) {
                h += "<span class='cc-badge cc-badge-bad'>" + esc(L("cc_imp_sheet_unusable")) + "</span><div class='cc-muted' style='flex:1 1 100%'>" + esc(s.error) + "</div>";
            } else {
                if (!single) {
                    h += "<div class='cc-sheet-target'>" + targetSelectHtml(s) + (s.target === "new" ? newNameHtml(s) : "") + "</div>";
                } else if (selected && W.calendarId === "new") {
                    h += "<div class='cc-sheet-target'>" + newNameHtml(s) + "</div>";
                }

                if (!single && selected) {
                    h += "<button type='button' class='cc-btn cc-btn-sm' data-act='detail' data-index='" + s.index + "' aria-expanded='" + (W.detail === s.index ? "true" : "false") + "'>" + esc(W.detail === s.index ? L("cc_imp_hide_details") : L("cc_imp_review_columns")) + "</button>";
                }
            }

            h += "</div>";

            if (s.usable && selected) {
                h += "<div class='cc-sheet-body'>" + sheetBodyHtml(s) + "</div>";
            }

            return h + "</div>";
        }

        function renderSheets() {
            var $area = $root.find("#cc-imp-sheets");
            if (!$area.length) {
                return;
            }

            var active = document.activeElement;
            var fk = (active && $.contains($area[0], active)) ? $(active).attr("data-fk") : "";

            var html = "";
            $.each(W.sheets, function (i, s) {
                html += sheetCardHtml(s);
            });

            if (W.previewError) {
                html = "<div class='cc-imp-note bad' role='alert'>" + esc(W.previewError) + "</div>" + html;
            }

            $area.html(html).toggleClass("cc-loading", W.previewing);
            if (fk) {
                $area.find("[data-fk='" + fk + "']").first().trigger("focus");
            }
        }

        function importableTotal() {
            var n = 0;
            $.each(W.sheets, function (i, s) {
                var entry = W.previews[s.index];
                if (s.usable && s.checked && s.target && entry && entry.can_import) {
                    n += entry.summary.to_import;
                }
            });
            return n;
        }

        /* ---------- payload / preview ---------- */

        function canRun(s) {
            var entry = W.previews[s.index];
            return s.usable && s.checked && s.target && entry && entry.can_import;
        }

        function buildPayload(forRun) {
            var sheets = [];
            $.each(W.sheets, function (i, s) {
                if (!s.usable || !s.target) {
                    return;
                }

                if (forRun ? !canRun(s) : !(s.checked || s.index === W.detail)) {
                    return;
                }

                sheets.push({index: s.index, checked: s.checked, target: s.target, new_name: s.new_name, header_row: s.header_override || 0, mapping: s.mapping});
            });

            var options = {year: parseInt(W.options.year, 10) || new Date().getFullYear(), carry_blank_date: W.options.carry_blank_date, skip_duplicates: W.options.skip_duplicates};
            return {token: W.parsed.token, mode: W.mode, options: options, sheets: sheets, detail: W.detail};
        }

        function refreshPreview(immediate) {
            if (W.timer) {
                clearTimeout(W.timer);
            }

            W.timer = setTimeout(doPreview, immediate ? 0 : 250);
            W.previewing = true;
            updateFooter();
            renderSheets();
        }

        function doPreview() {
            W.timer = null;
            var payload = buildPayload(false);
            var seq = ++W.seq;
            if (!payload.sheets.length) {
                W.previewing = false;
                W.previewError = "";
                updateFooter();
                renderSheets();
                return;
            }

            post(urls.import_preview, {payload: JSON.stringify(payload)}).done(function (result) {
                if (seq !== W.seq || W.step !== 2) {
                    return;
                }

                W.previewError = "";
                $.each(result.sheets || [], function (i, entry) {
                    W.previews[entry.index] = entry;
                });
            }).fail(function (err) {
                if (seq !== W.seq || W.step !== 2) {
                    return;
                }

                W.previewError = err.message;
            }).always(function () {
                if (seq !== W.seq || W.step !== 2) {
                    return;
                }

                W.previewing = false;
                updateFooter();
                renderSheets();
            });
        }

        /* ---------- sheets setup ---------- */

        function applyMode() {
            var first = -1;
            $.each(W.sheets, function (i, s) {
                if (s.usable && first < 0) {
                    first = s.index;
                }
            });

            if (W.mode === "single") {
                var chosen = sheetBy(W.single);
                if (!chosen || !chosen.usable) {
                    W.single = first;
                }

                $.each(W.sheets, function (i, s) {
                    s.checked = s.usable && s.index === W.single;
                    s.target = W.calendarId === "new" ? "new" : String(W.calendarId);
                });
                W.detail = W.single;
            } else {
                W.detail = -1;
                $.each(W.sheets, function (i, s) {
                    s.target = s.match ? String(s.match) : ((perms.add_client || perms.admin_manage) ? "new" : "");
                    s.checked = s.usable && s.target !== "";
                    if (s.checked && W.detail < 0) {
                        W.detail = s.index;
                    }
                });
            }

            W.previews = {};
        }

        function initSheets() {
            W.sheets = $.map(W.parsed.sheets, function (info) {
                return {index: info.index, name: info.name, rows: info.rows, data_rows: info.data_rows, detected_header: info.header_row, usable: !!info.usable, error: info.error || "", match: info.match_calendar_id || 0, checked: false, target: "", new_name: info.name, header_override: 0, mapping: {}};
            });
            W.single = -1;
            applyMode();
        }

        /* ---------- step 3 ---------- */

        function resultHtml(r, i) {
            var h;
            if (!r.success) {
                return "<div class='cc-result bad'><h4 class='cc-result-title'>" + esc(r.sheet_name) + " <span class='cc-badge cc-badge-bad'>" + esc(L("cc_imp_not_imported")) + "</span></h4>" +
                        "<div class='cc-imp-note bad' role='alert'>" + esc(r.message || L("cc_error")) + "</div></div>";
            }

            var preview = W.previews[r.index];
            var sm = preview && preview.summary ? preview.summary : null;
            h = "<div class='cc-result " + (r.undone ? "undone" : "ok") + "'><h4 class='cc-result-title'>" + esc(r.sheet_name) + " &rarr; " + esc(r.calendar_name) + " " +
                    (r.undone ? "<span class='cc-badge'>" + esc(L("cc_imp_undone_label")) + "</span>" : "<span class='cc-badge cc-badge-ok'>" + esc(L("cc_imp_res_imported")) + "</span>") + "</h4>";

            h += "<div class='cc-sum'>" +
                    sumBox(L("cc_imp_res_imported"), nf(r.undone ? 0 : r.imported)) +
                    sumBox(L("cc_imp_res_skipped"), nf(r.skipped)) +
                    sumBox(L("cc_imp_res_columns"), nf(r.columns_created)) +
                    sumBox(L("cc_imp_res_options"), nf(r.options_created)) +
                    sumBox(L("cc_imp_res_months"), nf(r.months_added)) +
                    "</div>";

            function sumBox(label, value) {
                return "<div class='cc-sum-box'><div class='cc-sum-label'>" + esc(label) + "</div><div class='cc-sum-value'>" + esc(value) + "</div></div>";
            }

            if (r.new_column_names && r.new_column_names.length) {
                h += "<div class='cc-imp-note'>" + esc(L("cc_imp_res_columns")) + ": " + esc(r.new_column_names.join(", ")) + "</div>";
            }
            if (r.locked_option_cells > 0) {
                h += "<div class='cc-imp-note warn'>" + esc(fmt(L("cc_imp_info_locked_cells"), r.locked_option_cells)) + "</div>";
            }
            if (r.locked_columns && r.locked_columns.length) {
                h += "<div class='cc-imp-note warn'>" + esc(fmt(L("cc_imp_info_locked_columns"), r.locked_columns.join(", "))) + "</div>";
            }
            if (sm && sm.invalid_cells > 0) {
                h += "<div class='cc-imp-note'>" + esc(fmt(L("cc_imp_info_invalid_cells"), sm.invalid_cells)) + "</div>";
            }
            if (sm && sm.truncated_cells > 0) {
                h += "<div class='cc-imp-note'>" + esc(fmt(L("cc_imp_info_truncated"), sm.truncated_cells)) + "</div>";
            }

            if (r.skipped_list && r.skipped_list.length) {
                var open = !!W.why["r" + i];
                h += "<button type='button' class='cc-btn cc-btn-sm' data-act='rwhy' data-i='" + i + "' aria-expanded='" + (open ? "true" : "false") + "'>" + esc(open ? L("cc_imp_hide") : L("cc_imp_skipped_rows")) + " (" + nf(r.skipped) + ")</button>" +
                        "<ul class='cc-reasons" + (open ? "" : " hide") + "'>";
                $.each(r.skipped_list, function (j, row) {
                    h += "<li>" + esc(fmt(L("cc_imp_row_n"), row.row)) + ": " + esc(row.reason) + "</li>";
                });
                h += "</ul>";
                if (r.skipped > r.skipped_list.length) {
                    h += "<div class='cc-muted'>" + esc(L("cc_imp_skipped_more")) + "</div>";
                }
            }

            h += "<div class='cc-result-actions'>";
            if (r.skipped_token) {
                h += "<a class='cc-btn cc-btn-sm' href='" + esc(urls.import_skipped_csv + "?token=" + encodeURIComponent(r.skipped_token)) + "' download>" + esc(L("cc_imp_download_skipped")) + "</a>";
            }
            if (!r.undone && r.imported > 0) {
                h += "<button type='button' class='cc-btn cc-btn-sm cc-btn-danger-ghost' data-act='undo' data-i='" + i + "'>" + esc(L("cc_imp_undo")) + "</button>";
            }
            if (!r.undone) {
                h += "<button type='button' class='cc-btn cc-btn-sm cc-btn-primary' data-act='goto' data-i='" + i + "'>" + esc(L("cc_imp_view_in_calendar")) + "</button>";
            }
            return h + "</div></div>";
        }

        function step3Html() {
            if (W.running) {
                return "<p role='status'><strong>" + esc(L("cc_imp_importing")) + "</strong></p><div class='cc-progress indeterminate'><div class='cc-progress-fill'></div></div>";
            }

            if (W.runError) {
                return "<div class='cc-imp-note bad' role='alert'>" + esc(W.runError) + "</div>";
            }

            var h = "<h4 class='cc-imp-title' style='margin-top:0'>" + esc(L("cc_imp_done_title")) + "</h4>";
            if (!W.allOk) {
                h += "<div class='cc-imp-note warn'>" + esc(L("cc_imp_done_partial")) + "</div>";
            }

            $.each(W.results || [], function (i, r) {
                h += resultHtml(r, i);
            });
            return h;
        }

        /* ---------- render + footer ---------- */

        function render() {
            var html = stepperHtml();
            if (W.step === 1) {
                html += step1Html();
            } else if (W.step === 2) {
                html += step2ShellHtml();
            } else {
                html += step3Html();
            }

            $root.html(html);
            if (W.step === 2) {
                renderSheets();
            }

            updateFooter();
        }

        function updateFooter() {
            var buttons = [];
            if (W.step === 1) {
                buttons = [
                    {label: L("cancel"), onClick: function () {
                            modal.requestClose();
                        }},
                    {label: L("cc_imp_next"), className: "cc-btn-primary", disabled: !W.file || modal.busy, onClick: startParse}
                ];
            } else if (W.step === 2) {
                var total = importableTotal();
                buttons = [
                    {label: L("cc_imp_back"), onClick: function () {
                            W.seq++;
                            W.step = 1;
                            render();
                        }},
                    {label: total ? fmt(L("cc_imp_import_n"), nf(total)) : L("cc_imp_apply"), className: "cc-btn-primary", disabled: W.previewing || total < 1, onClick: runImport}
                ];
            } else {
                if (!W.running && (W.runError || (W.results && !W.allOk))) {
                    buttons.push({label: L("cc_imp_back"), onClick: function () {
                            W.step = 2;
                            W.runError = "";
                            render();
                            refreshPreview(true);
                        }});
                }
                buttons.push({label: L("cc_imp_close"), className: "cc-btn-primary", disabled: W.running, onClick: function () {
                        modal.requestClose();
                    }});
            }

            modal.setButtons(buttons);
        }

        /* ---------- step 1: file + upload ---------- */

        function chooseFile(file) {
            modal.setError("");
            if (!file) {
                return;
            }

            var ext = String(file.name || "").split(".").pop().toLowerCase();
            if ($.inArray(ext, exts) === -1) {
                modal.setError(L("cc_imp_err_ext"));
                return;
            }
            if (file.size < 1) {
                modal.setError(L("cc_imp_err_empty"));
                return;
            }
            if (file.size > maxBytes) {
                modal.setError(fmt(L("cc_imp_file_too_big_js"), Math.round(maxBytes / 1048576)));
                return;
            }

            W.file = file;
            render();
        }

        function startParse() {
            modal.setError("");
            if (!W.file) {
                modal.setError(L("cc_imp_choose_file"));
                return;
            }

            var fd = new FormData();
            fd.append("file", W.file);
            fd.append("calendar_id", W.calendarId === "new" ? 0 : W.calendarId);
            fd.append("file_type", W.fileType);
            $.each(csrfData(), function (k, v) {
                fd.append(k, v);
            });

            modal.busy = true;
            updateFooter();
            var $bar = $root.find("#cc-imp-progress").removeClass("hide");
            var $text = $root.find("#cc-imp-progress-text").removeClass("hide").text(L("cc_imp_uploading"));
            $root.find("button, select").prop("disabled", true);

            $.ajax({
                url: urls.import_parse, type: "POST", data: fd, processData: false, contentType: false, dataType: "json",
                xhr: function () {
                    var xhr = $.ajaxSettings.xhr();
                    if (xhr.upload) {
                        xhr.upload.addEventListener("progress", function (e) {
                            if (e.lengthComputable) {
                                var pct = Math.round(e.loaded / e.total * 100);
                                $bar.find(".cc-progress-fill").css("width", pct + "%");
                                if (pct >= 100) {
                                    $bar.addClass("indeterminate");
                                    $text.text(L("cc_imp_reading"));
                                }
                            }
                        });
                    }
                    return xhr;
                }
            }).done(function (result) {
                modal.busy = false;
                if (result && result.success) {
                    W.parsed = result;
                    W.previews = {};
                    W.previewError = "";
                    W.why = {};
                    initSheets();
                    W.step = 2;
                    render();
                    refreshPreview(true);
                } else {
                    parseFailed((result && result.message) || L("cc_error"));
                }
            }).fail(function (xhr) {
                modal.busy = false;
                parseFailed((xhr.responseJSON && xhr.responseJSON.message) || (xhr.status === 403 ? L("cc_forbidden") : L("cc_error")));
            });
        }

        function parseFailed(message) {
            render();
            modal.setError(message);
        }

        /* ---------- step 3: run ---------- */

        function runImport() {
            var payload = buildPayload(true);
            if (!payload.sheets.length) {
                modal.setError(L("cc_imp_nothing_selected"));
                return;
            }

            W.seq++;
            W.step = 3;
            W.running = true;
            W.runError = "";
            W.results = null;
            modal.busy = true;
            render();

            post(urls.import_run, {payload: JSON.stringify(payload)}).done(function (result) {
                W.results = result.results || [];
                W.allOk = !!result.all_ok;
                $.each(W.results, function (i, r) {
                    if (r.success && r.imported > 0) {
                        W.changed = true;
                    }
                });
            }).fail(function (err) {
                W.runError = err.message;
            }).always(function () {
                W.running = false;
                modal.busy = false;
                render();
            });
        }

        /* ---------- events ---------- */

        function sheetFrom($el) {
            return sheetBy(parseInt($el.attr("data-index"), 10));
        }

        $root.on("change", "[data-act='client']", function () {
            var v = $(this).val();
            W.calendarId = v === "new" ? "new" : parseInt(v, 10);
        });
        $root.on("change", "[data-act='ftype']", function () {
            W.fileType = $(this).val();
        });
        $root.on("click", "[data-act='browse']", function () {
            $root.find("#cc-imp-file").trigger("click");
        });
        $root.on("click", "[data-act='removefile']", function () {
            W.file = null;
            render();
        });
        $root.on("change", "#cc-imp-file", function () {
            chooseFile(this.files && this.files[0]);
        });
        $root.on("dragover dragenter", "#cc-imp-drop", function (e) {
            e.preventDefault();
            $(this).addClass("over");
        });
        $root.on("dragleave dragend drop", "#cc-imp-drop", function (e) {
            e.preventDefault();
            $(this).removeClass("over");
        });
        $root.on("drop", "#cc-imp-drop", function (e) {
            var dt = e.originalEvent && e.originalEvent.dataTransfer;
            if (dt && dt.files && dt.files.length) {
                chooseFile(dt.files[0]);
            }
        });

        $root.on("change", "[data-act='mode']", function () {
            W.mode = $(this).val();
            applyMode();
            render();
            refreshPreview(true);
        });
        $root.on("change", "[data-act='opt-year']", function () {
            var y = parseInt($(this).val(), 10);
            W.options.year = (y >= 2000 && y <= 2100) ? y : new Date().getFullYear();
            $(this).val(W.options.year);
            refreshPreview();
        });
        $root.on("change", "[data-act='opt-dup']", function () {
            W.options.skip_duplicates = $(this).prop("checked");
            refreshPreview();
        });
        $root.on("change", "[data-act='opt-carry']", function () {
            W.options.carry_blank_date = $(this).prop("checked");
            refreshPreview();
        });

        $root.on("change", "[data-act='pick']", function () {
            W.single = parseInt($(this).attr("data-index"), 10);
            applyMode();
            refreshPreview();
        });
        $root.on("change", "[data-act='check']", function () {
            var s = sheetFrom($(this));
            if (!s) {
                return;
            }

            s.checked = $(this).prop("checked");
            if (s.checked && !s.target) {
                s.checked = false;
                $(this).prop("checked", false);
            }
            if (W.detail === -1 || !(sheetBy(W.detail) || {}).checked) {
                W.detail = -1;
                $.each(W.sheets, function (i, x) {
                    if (x.checked && W.detail < 0) {
                        W.detail = x.index;
                    }
                });
            }
            refreshPreview();
        });
        $root.on("change", "[data-act='target']", function () {
            var s = sheetFrom($(this));
            if (s) {
                s.target = $(this).val();
                if (!s.target) {
                    s.checked = false;
                }
                refreshPreview();
            }
        });
        $root.on("change", "[data-act='newname']", function () {
            var s = sheetFrom($(this));
            if (s) {
                s.new_name = $.trim($(this).val());
                refreshPreview();
            }
        });
        $root.on("click", "[data-act='detail']", function () {
            var s = sheetFrom($(this));
            if (s) {
                W.detail = (W.detail === s.index) ? -1 : s.index;
                refreshPreview(true);
            }
        });
        $root.on("change", "[data-act='hdr']", function () {
            var s = sheetFrom($(this));
            if (s) {
                var n = parseInt($(this).val(), 10);
                s.header_override = n >= 1 ? n : 0;
                s.mapping = {};
                refreshPreview();
            }
        });

        function mapEntry(s, col) {
            if (!s.mapping[col]) {
                s.mapping[col] = {target: "skip", type: "", label: ""};
                var entry = W.previews[s.index];
                $.each((entry && entry.columns) || [], function (i, c) {
                    if (c.index === col && c.map) {
                        s.mapping[col] = {target: c.map.target, type: c.map.type || "", label: c.map.label || ""};
                    }
                });
            }
            return s.mapping[col];
        }

        $root.on("change", "[data-act='map']", function () {
            var s = sheetFrom($(this));
            if (!s) {
                return;
            }

            var col = parseInt($(this).attr("data-col"), 10);
            var m = mapEntry(s, col);
            m.target = $(this).val();
            if (m.target === "new") {
                var entry = W.previews[s.index];
                $.each((entry && entry.columns) || [], function (i, c) {
                    if (c.index === col) {
                        m.label = m.label || c.header;
                        m.type = m.type || ($.inArray(c.detected_type, NEW_TYPES) !== -1 ? c.detected_type : "text");
                    }
                });
            }
            refreshPreview(true);
        });
        $root.on("change", "[data-act='mapname']", function () {
            var s = sheetFrom($(this));
            if (s) {
                mapEntry(s, parseInt($(this).attr("data-col"), 10)).label = $.trim($(this).val());
                refreshPreview();
            }
        });
        $root.on("change", "[data-act='maptype']", function () {
            var s = sheetFrom($(this));
            if (s) {
                mapEntry(s, parseInt($(this).attr("data-col"), 10)).type = $(this).val();
                refreshPreview();
            }
        });

        $root.on("click", "[data-act='why']", function () {
            var index = parseInt($(this).attr("data-index"), 10);
            W.why[index] = !W.why[index];
            renderSheets();
        });
        $root.on("click", "[data-act='rwhy']", function () {
            var i = parseInt($(this).attr("data-i"), 10);
            W.why["r" + i] = !W.why["r" + i];
            render();
        });

        $root.on("click", "[data-act='undo']", function () {
            var i = parseInt($(this).attr("data-i"), 10);
            var r = W.results && W.results[i];
            if (!r) {
                return;
            }

            confirmModal(fmt(L("cc_imp_undo_confirm"), nf(r.imported)), function () {
                post(urls.import_undo, {log_id: r.log_id}).done(function (result) {
                    r.undone = true;
                    W.changed = true;
                    toast(result.message || L("cc_saved"));
                }).fail(function (err) {
                    toast(err.message, "error");
                }).always(function () {
                    render();
                });
            }, L("cc_imp_undo_yes"));
        });

        $root.on("click", "[data-act='goto']", function () {
            var i = parseInt($(this).attr("data-i"), 10);
            var r = W.results && W.results[i];
            if (!r) {
                return;
            }

            W.changed = false;
            modal.close();
            ctx.goTo(r.calendar_id, r.first_ym || "");
        });

        render();
        return modal;
    }

    CC.openImportWizard = openImportWizard;

    /* ================================================================ */
    /* CALENDAR PAGE                                                     */
    /* ================================================================ */

    function initCalendarPage(config) {
        CC.lang = config.lang || {};

        var urls = config.urls;
        var state = {
            calendars: [],
            calendarId: config.initial.calendar_id || 0,
            columns: [],
            months: [],
            year: 0,
            month: 0,
            items: [],
            specialDays: {},
            targets: {},
            tiles: [],
            filters: {types: [], statuses: [], goals: [], has_goal: false},
            colors: {},
            perms: config.perms,
            today: "",
            selectedDate: config.initial.date || "",
            view: "month",
            filter: {type: "", goal: "", status: ""},
            blankColumns: [],
            clientSources: {},
            loaded: false
        };

        var $app = $("#cc-app");
        var requestId = 0;

        /* ---------- data ---------- */

        function load(params, keepDate) {
            var id = ++requestId;
            $app.addClass("cc-loading");
            var data = {calendar_id: state.calendarId};
            $.extend(data, params || {});

            return post(urls.page_data, data).done(function (result) {
                if (id !== requestId) {
                    return;
                }

                var previousCalendar = state.calendarId;
                state.perms = result.perms || state.perms;
                state.calendars = result.calendars || [];
                state.calendarId = result.calendar_id;
                state.columns = result.columns || [];
                state.months = result.months || [];
                state.year = result.year;
                state.month = result.month;
                state.items = result.items || [];
                state.specialDays = result.special_days || {};
                state.targets = result.targets || {};
                state.tiles = result.tiles || [];
                state.filters = result.filters || state.filters;
                state.colors = result.colors || {};
                state.today = result.today;
                state.blankColumns = result.blank_columns || [];
                state.clientSources = result.client_sources || {};
                state.loaded = true;

                if (previousCalendar !== state.calendarId && !keepDate) {
                    state.filter = {type: "", goal: "", status: ""};
                }

                chooseDate();
                renderAll();
            }).fail(function (err) {
                toast(err.message, "error");
            }).always(function () {
                if (id === requestId) {
                    $app.removeClass("cc-loading");
                }
            });
        }

        function chooseDate() {
            var prefix = state.year + "-" + pad(state.month) + "-";
            if (state.selectedDate && state.selectedDate.indexOf(prefix) === 0) {
                return;
            }

            if (state.today && state.today.indexOf(prefix) === 0) {
                state.selectedDate = state.today;
            } else {
                state.selectedDate = prefix + "01";
            }
        }

        function currentCalendar() {
            var found = null;
            $.each(state.calendars, function (i, c) {
                if (c.id === state.calendarId) {
                    found = c;
                }
            });
            return found;
        }

        function visibleColumns() {
            return $.grep(state.columns, function (c) {
                return c.is_visible;
            });
        }

        function findColumn(key) {
            var found = null;
            $.each(state.columns, function (i, c) {
                if (c.field_key === key) {
                    found = c;
                }
            });
            return found;
        }

        function itemValue(item, column) {
            if (column.field_key === "date" && column.is_core) {
                return item.date;
            }
            if (column.field_key === "type" && column.is_core) {
                return item.type;
            }
            if (column.field_key === "title" && column.is_core) {
                return item.title;
            }
            if (column.field_key === "status" && column.is_core) {
                return item.status;
            }

            var v = item.values ? item.values[column.field_key] : undefined;
            return v === undefined ? "" : v;
        }

        function filteredItems() {
            return $.grep(state.items, function (item) {
                if (state.filter.type && item.type !== state.filter.type) {
                    return false;
                }
                if (state.filter.status && item.status !== state.filter.status) {
                    return false;
                }
                if (state.filter.goal) {
                    var goal = item.values ? item.values.goal : "";
                    if ($.isArray(goal)) {
                        if ($.inArray(state.filter.goal, goal) === -1) {
                            return false;
                        }
                    } else if (goal !== state.filter.goal) {
                        return false;
                    }
                }
                return true;
            });
        }

        function itemsByDate(items) {
            var map = {};
            $.each(items, function (i, item) {
                if (!map[item.date]) {
                    map[item.date] = [];
                }
                map[item.date].push(item);
            });
            return map;
        }

        function itemFormUrl(itemId, date) {
            if (itemId) {
                return urls.item_form + "/" + itemId;
            }

            var d = date || state.selectedDate || (state.year + "-" + pad(state.month) + "-01");
            return urls.item_form + "?calendar_id=" + state.calendarId + "&date=" + encodeURIComponent(d);
        }

        /* ---------- value rendering ---------- */

        function pillHtml(text, extraClass) {
            if (text === "" || text === null || text === undefined) {
                return "";
            }
            return "<span class='cc-pill " + (extraClass || "") + "' style='" + chipStyle(state.colors, text) + "'>" + esc(text) + "</span>";
        }

        function fileUrl(value) {
            if (/^https?:\/\//i.test(value)) {
                return value;
            }
            return (config.base_url || "") + value;
        }

        function valueHtml(column, value) {
            if (value === "" || value === null || value === undefined || value === false || ($.isArray(value) && !value.length)) {
                return "";
            }

            switch (column.input_type) {
                case "select":
                    return pillHtml(value);
                case "multi":
                    var html = "";
                    $.each(value, function (i, v) {
                        html += pillHtml(v) + " ";
                    });
                    return html;
                case "link":
                    if (/^https?:\/\//i.test(value)) {
                        return "<a href='" + esc(value) + "' target='_blank' rel='noopener noreferrer'>" + esc(value) + "</a>";
                    }
                    return esc(value);
                case "file":
                    var name = String(value).split("/").pop();
                    return "<a href='" + esc(fileUrl(value)) + "' target='_blank' rel='noopener noreferrer'>" + esc(/^https?:\/\//i.test(value) ? value : name) + "</a>";
                case "check":
                    return esc(L("yes"));
                case "date":
                    return esc(fmtDate(value));
                case "long":
                    return esc(value).replace(/\n/g, "<br>");
                default:
                    return esc(value);
            }
        }

        /* ---------- render ---------- */

        function renderAll() {
            renderTabs();

            if (!state.calendars.length) {
                $("#cc-empty-calendars").removeClass("hide");
                $("#cc-body").addClass("hide");
                $("#cc-add-content").addClass("hide");
                return;
            }

            $("#cc-empty-calendars").addClass("hide");
            $("#cc-body").removeClass("hide");
            $("#cc-add-content").removeClass("hide");

            renderTiles();
            renderMonths();
            renderViewBar();
            renderDesigner();
            renderViews();

            if (window.feather) {
                feather.replace();
            }
        }

        function renderViews() {
            renderGrid();
            renderDayPanel();
            renderTable();
            $("#cc-month-view").toggleClass("hide", state.view !== "month");
            $("#cc-table-view").toggleClass("hide", state.view !== "table");
            $("#cc-add-content").attr("href", itemFormUrl(0));
        }

        function renderTabs() {
            var html = "";
            $.each(state.calendars, function (i, c) {
                html += "<button type='button' role='tab' class='cc-tab" + (c.id === state.calendarId ? " active" : "") + "' aria-selected='" + (c.id === state.calendarId ? "true" : "false") + "' data-id='" + c.id + "'>" + esc(c.name) + "</button>";
            });

            if (state.perms.add_client || state.perms.admin_manage) {
                html += "<button type='button' class='cc-tab cc-tab-add' id='cc-add-client' aria-haspopup='dialog'>+ " + esc(L("cc_add_client")) + "</button>";
            }

            $("#cc-client-tabs").html(html);
        }

        function renderTiles() {
            var html = "";
            $.each(state.tiles, function (i, t) {
                var bar = "";
                var sub = esc(L("cc_this_month"));
                if (t.target > 0) {
                    var pct = Math.min(100, Math.round(t.count / t.target * 100));
                    sub = esc(sprintf1(L("cc_of_n_planned"), t.target));
                    bar = "<div class='cc-bar'><div class='cc-bar-fill' style='width:" + pct + "%'></div></div>";
                }

                var tag = state.perms.admin_manage ? "button type='button'" : "div";
                var close = state.perms.admin_manage ? "button" : "div";
                html += "<" + tag + " class='cc-tile" + (state.perms.admin_manage ? " cc-tile-click" : "") + "' data-type='" + esc(t.label) + "'" + (state.perms.admin_manage ? " title='" + esc(L("cc_set_target")) + "'" : "") + ">" +
                        "<div class='cc-tile-label'>" + esc(t.label) + "</div>" +
                        "<div class='cc-tile-count'>" + t.count + "</div>" +
                        "<div class='cc-tile-sub'>" + sub + "</div>" + bar +
                        "</" + close + ">";
            });

            $("#cc-tiles").html(html);
        }

        function monthCaption(y, m) {
            var ty = parseYmd(state.today).y, tm = parseYmd(state.today).m;
            var diff = (y * 12 + m) - (ty * 12 + tm);
            if (diff === 0) {
                return L("cc_current");
            }
            if (diff === -1) {
                return L("cc_previous");
            }
            if (diff === 1) {
                return L("cc_next");
            }
            return diff > 1 ? L("cc_upcoming") : L("cc_earlier");
        }

        function nextMonthAfterLast() {
            var y, m;
            if (state.months.length) {
                var last = state.months[state.months.length - 1];
                y = last.year;
                m = last.month + 1;
            } else {
                y = parseYmd(state.today).y;
                m = parseYmd(state.today).m;
            }

            if (m > 12) {
                m = 1;
                y++;
            }

            return {year: y, month: m};
        }

        function renderMonths() {
            var html = "";
            $.each(state.months, function (i, mo) {
                var selected = (mo.year === state.year && mo.month === state.month);
                html += "<button type='button' class='cc-month-pill" + (selected ? " active" : "") + "' data-year='" + mo.year + "' data-month='" + mo.month + "' aria-pressed='" + (selected ? "true" : "false") + "'>" +
                        "<span class='cc-month-cap'>" + esc(monthCaption(mo.year, mo.month)) + "</span>" +
                        "<span class='cc-month-name'>" + esc(MONTH_NAMES[mo.month - 1] + " " + mo.year) + "</span>" +
                        "<span class='cc-month-count'>" + esc(postsLabel(mo.count)) + "</span>" +
                        "</button>";
            });

            if (state.perms.add) {
                var next = nextMonthAfterLast();
                html += "<button type='button' class='cc-month-pill cc-month-add' id='cc-add-month'>" +
                        "<span class='cc-month-cap'>+ " + esc(L("cc_add_month")) + "</span>" +
                        "<span class='cc-month-name'>" + esc(MONTH_NAMES[next.month - 1] + " " + next.year) + "</span>" +
                        "</button>";
            }

            $("#cc-month-row").html(html);
            updateMonthArrows();
            scrollMonthIntoView();
        }

        //the arrows only exist when the strip does not fit; they sit right next to it
        function updateMonthArrows() {
            var row = $("#cc-month-row")[0];
            var $prev = $("#cc-months-prev"), $next = $("#cc-months-next");
            if (!row) {
                return;
            }

            $prev.addClass("hide");
            $next.addClass("hide");
            var overflow = row.scrollWidth > row.clientWidth + 1;
            $prev.toggleClass("hide", !overflow);
            $next.toggleClass("hide", !overflow);
            if (overflow) {
                updateArrowState();
            }
        }

        function updateArrowState() {
            var row = $("#cc-month-row")[0];
            if (!row) {
                return;
            }

            $("#cc-months-prev").prop("disabled", row.scrollLeft <= 1);
            $("#cc-months-next").prop("disabled", row.scrollLeft + row.clientWidth >= row.scrollWidth - 1);
        }

        function scrollMonthIntoView() {
            var $row = $("#cc-month-row");
            var $active = $row.find(".cc-month-pill.active");
            if ($active.length) {
                var left = $active[0].offsetLeft - ($row.width() / 2) + ($active.outerWidth() / 2);
                $row.scrollLeft(Math.max(0, left));
            }
            updateArrowState();
        }

        function fillFilter($select, allLabel, options, selected) {
            var html = "<option value=''>" + esc(allLabel) + "</option>";
            $.each(options, function (i, o) {
                html += "<option value='" + esc(o) + "'" + (o === selected ? " selected" : "") + ">" + esc(o) + "</option>";
            });
            $select.html(html);
            if (selected && $.inArray(selected, options) === -1) {
                $select.val("");
                return "";
            }
            return selected;
        }

        function renderViewBar() {
            $("#cc-month-title").text(MONTH_NAMES[state.month - 1] + " " + state.year);
            $(".cc-toggle-btn").each(function () {
                $(this).toggleClass("active", $(this).data("view") === state.view);
            });

            state.filter.type = fillFilter($("#cc-filter-type"), L("cc_all_types"), state.filters.types || [], state.filter.type);
            state.filter.status = fillFilter($("#cc-filter-status"), L("cc_all_statuses"), state.filters.statuses || [], state.filter.status);

            var $goal = $("#cc-filter-goal");
            if (state.filters.has_goal) {
                $("#cc-filter-goal-wrap").removeClass("hide");
                state.filter.goal = fillFilter($goal, L("cc_all_goals"), state.filters.goals || [], state.filter.goal);
            } else {
                $("#cc-filter-goal-wrap").addClass("hide");
                state.filter.goal = "";
            }
        }

        function renderGrid() {
            var items = filteredItems();
            var byDate = itemsByDate(items);
            var firstDow = new Date(state.year, state.month - 1, 1).getDay();
            var days = new Date(state.year, state.month, 0).getDate();
            var html = "";

            $.each(DAY_SHORT, function (i, d) {
                html += "<div class='cc-dow' role='columnheader'>" + esc(d) + "</div>";
            });

            for (var b = 0; b < firstDow; b++) {
                html += "<div class='cc-day cc-day-blank' aria-hidden='true'></div>";
            }

            for (var d = 1; d <= days; d++) {
                var date = ymd(state.year, state.month, d);
                var list = byDate[date] || [];
                var special = state.specialDays[date];
                var isToday = date === state.today;
                var isSelected = date === state.selectedDate;

                html += "<button type='button' class='cc-day" + (isSelected ? " selected" : "") + (list.length ? " has-items" : "") + "' data-date='" + date + "' role='gridcell' aria-label='" + esc(fmtLongDate(date) + (list.length ? ", " + sprintf1(L("cc_n_items"), list.length) : "")) + "'>";
                html += "<span class='cc-day-top'><span class='cc-day-num" + (isToday ? " today" : "") + "'>" + d + "</span>";
                if (list.length >= 2) {
                    html += "<span class='cc-day-count'>" + esc(sprintf1(L("cc_n_items"), list.length)) + "</span>";
                }
                html += "</span>";

                if (special) {
                    var specialText = "★ " + special;
                    html += "<span class='cc-day-special' title='" + esc(specialText) + "'>" + esc(specialText) + "</span>";
                }

                $.each(list.slice(0, 2), function (i, item) {
                    var text = (item.type ? item.type + ": " : "") + item.title;
                    html += "<span class='cc-chip' style='" + chipStyle(state.colors, item.type) + "' title='" + esc(text) + "'>" + esc(text) + "</span>";
                });

                if (list.length > 2) {
                    html += "<span class='cc-more'>" + esc(sprintf1(L("cc_n_more"), list.length - 2)) + "</span>";
                }

                html += "</button>";
            }

            $("#cc-grid").html(html);
        }

        function renderDayPanel() {
            var date = state.selectedDate;
            var items = itemsByDate(filteredItems())[date] || [];
            var perms = state.perms;
            var dp = parseYmd(date);
            var dd = new Date(dp.y, dp.m - 1, dp.d);
            var html = "<div class='cc-day-head'><div class='cc-day-head-text'><div class='cc-day-weekday'>" + esc(DAY_NAMES[dd.getDay()]) + "</div><div class='cc-day-date'>" + esc(fmtDate(date)) + "</div></div>";

            if (perms.add) {
                html += "<a class='cc-btn cc-btn-primary cc-btn-sm' href='" + esc(itemFormUrl(0, date)) + "'>+ " + esc(L("cc_add_content")) + "</a>";
            }
            html += "</div>";

            var special = state.specialDays[date] || "";
            html += "<div class='cc-special'><label class='cc-label' for='cc-special-input'>" + esc(L("cc_special_day_for_date")) + "</label>";
            if (perms.edit) {
                html += "<input type='text' id='cc-special-input' class='cc-input cc-input-full' maxlength='120' value='" + esc(special) + "' placeholder='" + esc(L("cc_special_placeholder")) + "'>";
            } else {
                html += "<div class='cc-special-readonly'>" + (special ? esc(special) : "<span class='cc-muted'>-</span>") + "</div>";
            }
            html += "</div>";

            if (!items.length) {
                html += "<div class='cc-empty-day'>" + esc(L("cc_nothing_planned")) + "</div>";
            }

            var columns = visibleColumns();
            $.each(items, function (i, item) {
                html += "<div class='cc-item' data-id='" + item.id + "'>";
                html += "<div class='cc-item-pills'>" + pillHtml(item.type) + pillHtml(item.status) + "</div>";
                html += "<div class='cc-item-title'>" + esc(item.title) + "</div>";

                var rows = "";
                $.each(columns, function (j, col) {
                    if (col.is_core) {
                        return;
                    }
                    var v = valueHtml(col, itemValue(item, col));
                    if (v !== "") {
                        rows += "<div class='cc-kv-row'><dt>" + esc(col.label) + "</dt><dd>" + v + "</dd></div>";
                    }
                });
                if (rows) {
                    html += "<dl class='cc-kv'>" + rows + "</dl>";
                }

                if (perms.edit || perms["delete"]) {
                    html += "<div class='cc-item-actions'>";
                    if (perms.edit) {
                        html += "<a class='cc-btn cc-btn-sm' href='" + esc(itemFormUrl(item.id)) + "'>" + esc(L("edit")) + "</a>";
                    }
                    if (perms["delete"]) {
                        html += "<button type='button' class='cc-btn cc-btn-sm cc-btn-danger-ghost cc-item-delete' data-id='" + item.id + "'>" + esc(L("delete")) + "</button>";
                    }
                    html += "</div>";
                }
                html += "</div>";
            });

            $("#cc-day-panel").html(html);
        }

        function renderTable() {
            var items = filteredItems();
            var columns = visibleColumns();
            if (!items.length) {
                $("#cc-table-wrap").html("<div class='cc-empty-day'>" + esc(L("cc_no_content_in_month")) + "</div>");
                return;
            }

            var html = "<table class='cc-table'><thead><tr>";
            $.each(columns, function (i, col) {
                html += "<th scope='col'>" + esc(col.label) + "</th>";
            });
            if (state.perms.edit) {
                html += "<th scope='col'><span class='cc-sr'>" + esc(L("edit")) + "</span></th>";
            }
            html += "</tr></thead><tbody>";

            $.each(items, function (i, item) {
                html += "<tr>";
                $.each(columns, function (j, col) {
                    var v = itemValue(item, col);
                    var cell;
                    if (col.is_core && col.field_key === "type") {
                        cell = pillHtml(v);
                    } else if (col.is_core && col.field_key === "status") {
                        cell = pillHtml(v);
                    } else if (col.is_core && col.field_key === "title") {
                        cell = esc(v);
                    } else {
                        cell = valueHtml(col, v);
                    }
                    html += "<td>" + cell + "</td>";
                });
                if (state.perms.edit) {
                    html += "<td><a class='cc-btn cc-btn-sm' href='" + esc(itemFormUrl(item.id)) + "'>" + esc(L("edit")) + "</a></td>";
                }
                html += "</tr>";
            });

            html += "</tbody></table>";
            $("#cc-table-wrap").html(html);
        }

        /* ---------- column designer ---------- */

        function renderDesigner() {
            if (!(state.perms.add_column || state.perms.admin_manage)) {
                return;
            }

            var html = "";
            $.each(state.columns, function (i, col) {
                var badge;
                if (col.is_core) {
                    badge = "<span class='cc-badge'>" + esc(L("cc_core")) + "</span>";
                } else {
                    badge = "<button type='button' class='cc-badge cc-badge-toggle " + (col.is_visible ? "shown" : "hidden-col") + "' data-id='" + col.id + "' aria-pressed='" + (col.is_visible ? "true" : "false") + "'>" + esc(col.is_visible ? L("cc_shown") : L("cc_hidden")) + "</button>";
                }

                html += "<div class='cc-col-card' draggable='true' data-id='" + col.id + "'>" +
                        "<span class='cc-grip' aria-hidden='true'>&#8942;&#8942;</span>" +
                        "<div class='cc-col-info'><div class='cc-col-name'>" + esc(col.label) + "</div><div class='cc-col-type'>" + esc(L(TYPE_LABELS[col.input_type] || "cc_type_text")) + "</div></div>" +
                        badge +
                        "<button type='button' class='cc-btn cc-btn-sm cc-col-edit' data-id='" + col.id + "'>" + esc(L("edit")) + "</button>" +
                        "</div>";
            });

            $("#cc-designer-grid").html(html);
        }

        function reloadKeepingState() {
            return load({year: state.year, month: state.month}, true);
        }

        function columnById(id) {
            var found = null;
            $.each(state.columns, function (i, c) {
                if (c.id === id) {
                    found = c;
                }
            });
            return found;
        }

        function columnModal(column) {
            var isEdit = !!column;
            var typeOptions = "";
            $.each(["text", "long", "select", "multi", "date", "link", "number", "check", "file"], function (i, t) {
                typeOptions += "<option value='" + t + "'>" + esc(L(TYPE_LABELS[t])) + "</option>";
            });

            var body = "<div class='cc-modal-error cc-field-error' role='alert'></div>" +
                    "<div class='cc-field'><label class='cc-label' for='cc-col-label'>" + esc(L("cc_column_name")) + "</label><input type='text' id='cc-col-label' class='cc-input cc-input-full' maxlength='120'><div class='cc-field-error' role='alert' data-error-for='label'></div></div>";

            if (isEdit) {
                body += "<div class='cc-field'><div class='cc-label'>" + esc(L("cc_input_type")) + "</div><div class='cc-readonly'>" + esc(L(TYPE_LABELS[column.input_type])) + "</div></div>";
            } else {
                body += "<div class='cc-field'><label class='cc-label' for='cc-col-type'>" + esc(L("cc_input_type")) + "</label><select id='cc-col-type' class='cc-input cc-input-full'>" + typeOptions + "</select><div class='cc-field-error' role='alert' data-error-for='input_type'></div></div>";
            }

            body += "<div class='cc-field cc-col-options-field hide'><label class='cc-label' for='cc-col-options'>" + esc(L("cc_options_comma")) + "</label><input type='text' id='cc-col-options' class='cc-input cc-input-full'><div class='cc-field-error' role='alert' data-error-for='options'></div></div>";

            if (isEdit) {
                var index = $.inArray(column, state.columns);
                body += "<div class='cc-field cc-col-move'>" +
                        "<button type='button' class='cc-btn cc-btn-sm' data-dir='up'" + (index === 0 ? " disabled" : "") + ">&larr; " + esc(L("cc_move_earlier")) + "</button> " +
                        "<button type='button' class='cc-btn cc-btn-sm' data-dir='down'" + (index === state.columns.length - 1 ? " disabled" : "") + ">" + esc(L("cc_move_later")) + " &rarr;</button>" +
                        "</div>";
                if (!column.is_core) {
                    body += "<div class='cc-field'><button type='button' class='cc-btn cc-btn-sm cc-btn-danger-ghost cc-col-remove'>" + esc(L("cc_remove_column")) + "</button></div>";
                }
            }

            openModal({
                title: isEdit ? L("cc_edit_column") : L("cc_add_column"),
                body: body,
                buttons: [
                    {label: L("cancel")},
                    {label: L("save"), className: "cc-btn-primary", onClick: function (modal, $btn) {
                            save(modal, $btn);
                        }}
                ],
                onOpen: function (modal) {
                    var $m = modal.$el;
                    var $options = $m.find(".cc-col-options-field");

                    if (isEdit) {
                        $m.find("#cc-col-label").val(column.label);
                        if (column.input_type === "select" || column.input_type === "multi") {
                            $options.removeClass("hide");
                            $m.find("#cc-col-options").val(column.options.join(", "));
                        }
                    }

                    $m.find("#cc-col-type").on("change", function () {
                        var t = $(this).val();
                        $options.toggleClass("hide", !(t === "select" || t === "multi"));
                    });

                    $m.on("keydown", "input", function (e) {
                        if (e.key === "Enter") {
                            e.preventDefault();
                            $m.find(".cc-modal-foot .cc-btn-primary").trigger("click");
                        }
                    });

                    $m.find(".cc-col-move [data-dir]").on("click", function () {
                        var dir = $(this).data("dir");
                        post(urls.reorder_column, {calendar_id: state.calendarId, id: column.id, direction: dir}).done(function () {
                            modal.close();
                            reloadKeepingState();
                        }).fail(function (err) {
                            modal.setError(err.message);
                        });
                    });

                    $m.find(".cc-col-remove").on("click", function () {
                        confirmModal(L("cc_remove_column_confirm"), function () {
                            post(urls.delete_column, {calendar_id: state.calendarId, id: column.id}).done(function () {
                                modal.close();
                                reloadKeepingState();
                            }).fail(function (err) {
                                toast(err.message, "error");
                            });
                        }, L("cc_yes_remove"));
                    });
                }
            });

            function save(modal, $btn) {
                var $m = modal.$el;
                $m.find(".cc-field-error").text("");
                var data = {
                    calendar_id: state.calendarId,
                    id: isEdit ? column.id : 0,
                    label: $.trim($m.find("#cc-col-label").val()),
                    input_type: isEdit ? column.input_type : $m.find("#cc-col-type").val(),
                    options: $m.find("#cc-col-options").val()
                };

                var optionType = (data.input_type === "select" || data.input_type === "multi");
                if (data.label === "") {
                    $m.find("[data-error-for='label']").text(L("cc_column_name_required"));
                    return;
                }

                if (optionType && $.trim(data.options || "") === "") {
                    $m.find("[data-error-for='options']").text(L("cc_options_required"));
                    return;
                }

                $btn.prop("disabled", true);
                post(urls.save_column, data).done(function () {
                    modal.close();
                    toast(L("cc_saved"));
                    reloadKeepingState();
                }).fail(function (err) {
                    $btn.prop("disabled", false);
                    var shown = false;
                    $.each(err.errors || {}, function (key, message) {
                        var $e = $m.find("[data-error-for='" + key + "']");
                        if ($e.length) {
                            $e.text(message);
                            shown = true;
                        }
                    });
                    if (!shown) {
                        modal.setError(err.message);
                    }
                });
            }
        }

        function copyColumnsModal() {
            var options = "";
            $.each(state.calendars, function (i, c) {
                if (c.id !== state.calendarId) {
                    options += "<option value='" + c.id + "'>" + esc(c.name) + "</option>";
                }
            });

            if (!options) {
                toast(L("cc_pick_another_client"), "error");
                return;
            }

            openModal({
                title: L("cc_copy_from_client"),
                body: "<div class='cc-modal-error cc-field-error' role='alert'></div><p class='cc-muted'>" + esc(L("cc_copy_help")) + "</p>" +
                        "<div class='cc-field'><label class='cc-label' for='cc-copy-from'>" + esc(L("cc_client")) + "</label><select id='cc-copy-from' class='cc-input cc-input-full'>" + options + "</select></div>",
                buttons: [
                    {label: L("cancel")},
                    {label: L("cc_copy_columns"), className: "cc-btn-primary", onClick: function (modal, $btn) {
                            $btn.prop("disabled", true);
                            post(urls.copy_columns, {calendar_id: state.calendarId, from_calendar_id: modal.$el.find("#cc-copy-from").val()}).done(function (result) {
                                modal.close();
                                toast(result.message || L("cc_saved"));
                                reloadKeepingState();
                            }).fail(function (err) {
                                $btn.prop("disabled", false);
                                modal.setError(err.message);
                            });
                        }}
                ]
            });
        }

        function targetModal(typeName) {
            var current = state.targets[typeName] || "";
            openModal({
                title: sprintf1(L("cc_target_for"), typeName),
                body: "<div class='cc-modal-error cc-field-error' role='alert'></div><p class='cc-muted'>" + esc(L("cc_target_help")) + "</p>" +
                        "<div class='cc-field'><label class='cc-label' for='cc-target-input'>" + esc(L("cc_target_per_month")) + "</label><input type='text' inputmode='numeric' id='cc-target-input' class='cc-input cc-input-full' maxlength='6' value='" + esc(current) + "'></div>",
                buttons: [
                    {label: L("cancel")},
                    {label: L("save"), className: "cc-btn-primary", onClick: function (modal, $btn) {
                            $btn.prop("disabled", true);
                            post(urls.save_target, {calendar_id: state.calendarId, content_type: typeName, target_count: $.trim(modal.$el.find("#cc-target-input").val())}).done(function () {
                                modal.close();
                                reloadKeepingState();
                            }).fail(function (err) {
                                $btn.prop("disabled", false);
                                modal.setError(err.message);
                            });
                        }}
                ],
                onOpen: function (modal) {
                    modal.$el.on("keydown", "input", function (e) {
                        if (e.key === "Enter") {
                            e.preventDefault();
                            modal.$el.find(".cc-modal-foot .cc-btn-primary").trigger("click");
                        }
                    });
                }
            });
        }

        /* ---------- Add client popup ---------- */

        function normalizeName(value) {
            return $.trim(String(value || "").replace(/\s+/g, " "));
        }

        function columnChipsHtml(columns) {
            var html = "";
            $.each(columns, function (i, col) {
                var info = L(TYPE_LABELS[col.input_type] || "cc_type_text");
                html += "<li>" + esc(col.label) + " <small>" + esc(info) + "</small></li>";
            });
            return html;
        }

        function addClientModal() {
            if (!(state.perms.add_client || state.perms.admin_manage)) {
                return;
            }

            var options = "<option value='0'>" + esc(L("cc_blank_option")) + "</option>";
            $.each(state.calendars, function (i, c) {
                options += "<option value='" + c.id + "'>" + esc(c.name) + "</option>";
            });

            var body = "<p class='cc-muted'>" + esc(L("cc_add_client_help")) + "</p>" +
                    "<div class='cc-modal-error' role='alert'></div>" +
                    "<div class='cc-field'><label class='cc-label' for='cc-client-name'>" + esc(L("cc_client_name")) + "<span class='cc-required' aria-hidden='true'>*</span></label>" +
                    "<input type='text' id='cc-client-name' class='cc-input cc-input-full' maxlength='120' autocomplete='off' aria-required='true'></div>" +
                    "<div class='cc-field'><label class='cc-label' for='cc-client-copy'>" + esc(L("cc_start_with_columns_of")) + "</label>" +
                    "<select id='cc-client-copy' class='cc-input cc-input-full'>" + options + "</select></div>" +
                    "<div class='cc-field'><div class='cc-label' id='cc-client-chips-label'>" + esc(L("cc_columns_start_with")) + "</div>" +
                    "<ul class='cc-col-chips' id='cc-client-chips' aria-labelledby='cc-client-chips-label'></ul></div>";

            var $name = null;

            function submit(modal, $btn) {
                modal.setError("");
                $name.removeAttr("aria-invalid");
                var name = normalizeName($name.val());
                var error = "";

                if (name === "") {
                    error = L("cc_name_required");
                } else if (name.length > 120) {
                    error = L("cc_name_too_long");
                } else {
                    $.each(state.calendars, function (i, c) {
                        if (normalizeName(c.name).toLowerCase() === name.toLowerCase()) {
                            error = L("cc_client_exists");
                        }
                    });
                }

                if (error) {
                    modal.setError(error);
                    $name.attr("aria-invalid", "true").trigger("focus");
                    return;
                }

                $btn.prop("disabled", true).text(L("cc_working"));
                modal.busy = true;
                post(urls.save_client, {name: name, copy_from: modal.$el.find("#cc-client-copy").val()}).done(function (result) {
                    modal.busy = false;
                    modal.close();
                    toast(result.message || L("cc_saved"));
                    state.calendarId = result.calendar_id;
                    state.filter = {type: "", goal: "", status: ""};
                    state.selectedDate = "";
                    //switch to the new tab without reloading the page, then put the focus back on "+ Add client"
                    load({calendar_id: result.calendar_id, year: 0, month: 0}, false).always(function () {
                        $("#cc-add-client").trigger("focus");
                    });
                }).fail(function (err) {
                    modal.busy = false;
                    $btn.prop("disabled", false).text(L("cc_create_calendar"));
                    modal.setError((err.errors && err.errors.name) || err.message);
                    $name.attr("aria-invalid", "true").trigger("focus");
                });
            }

            openModal({
                title: L("cc_add_client_title"),
                body: body,
                focus: "#cc-client-name",
                isDirty: function () {
                    return $name !== null && $.trim($name.val()) !== "";
                },
                returnFocus: function () {
                    return document.getElementById("cc-add-client");
                },
                buttons: [
                    {label: L("cancel"), onClick: function (modal) {
                            modal.requestClose();
                        }},
                    {label: L("cc_create_calendar"), className: "cc-btn-primary", onClick: submit}
                ],
                onOpen: function (modal) {
                    var $m = modal.$el;
                    $name = $m.find("#cc-client-name");

                    function renderChips() {
                        var source = parseInt($m.find("#cc-client-copy").val(), 10) || 0;
                        var columns = source && state.clientSources[source] ? state.clientSources[source] : state.blankColumns;
                        $m.find("#cc-client-chips").html(columnChipsHtml(columns || []));
                    }

                    $m.find("#cc-client-copy").on("change", renderChips);
                    renderChips();

                    $name.on("keydown", function (e) {
                        if (e.key === "Enter") {
                            e.preventDefault();
                            $m.find(".cc-modal-foot .cc-btn-primary").trigger("click");
                        }
                    });
                }
            });
        }

        /* ---------- events ---------- */

        $app.on("click", ".cc-tab[data-id]", function () {
            var id = parseInt($(this).data("id"), 10);
            if (id === state.calendarId) {
                return;
            }

            state.calendarId = id;
            state.filter = {type: "", goal: "", status: ""};
            state.selectedDate = "";
            load({calendar_id: id, year: 0, month: 0}, false);
        });

        $app.on("click", ".cc-month-pill[data-year]", function () {
            var y = parseInt($(this).data("year"), 10), m = parseInt($(this).data("month"), 10);
            if (y === state.year && m === state.month) {
                return;
            }
            state.selectedDate = "";
            load({year: y, month: m}, true);
        });

        $app.on("click", "#cc-add-month", function () {
            var $btn = $(this);
            $btn.prop("disabled", true);
            post(urls.add_month, {calendar_id: state.calendarId}).done(function (result) {
                state.selectedDate = "";
                load({year: result.year, month: result.month}, true);
            }).fail(function (err) {
                $btn.prop("disabled", false);
                toast(err.message, "error");
            });
        });

        $("#cc-months-prev").on("click", function () {
            var $row = $("#cc-month-row");
            $row.animate({scrollLeft: "-=" + Math.max(160, Math.round($row.width() * 0.8))}, 200);
        });

        $("#cc-months-next").on("click", function () {
            var $row = $("#cc-month-row");
            $row.animate({scrollLeft: "+=" + Math.max(160, Math.round($row.width() * 0.8))}, 200);
        });

        $("#cc-month-row").on("scroll", updateArrowState);

        var resizeTimer = null;
        $(window).on("resize", function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                if (state.loaded && !$("#cc-body").hasClass("hide")) {
                    updateMonthArrows();
                }
            }, 120);
        });

        /* ---------- Add client popup (admin) ---------- */

        $app.on("click", "#cc-add-client", addClientModal);

        $("#cc-import-open").on("click", function () {
            openImportWizard({
                urls: urls,
                perms: state.perms,
                limits: config.import_limits,
                calendars: function () {
                    return state.calendars;
                },
                calendarId: function () {
                    return state.calendarId;
                },
                reload: function () {
                    reloadKeepingState();
                },
                goTo: function (calendarId, ym) {
                    var params = {calendar_id: calendarId, year: 0, month: 0};
                    if (/^\d{4}-\d{2}$/.test(ym)) {
                        params.year = parseInt(ym.substr(0, 4), 10);
                        params.month = parseInt(ym.substr(5, 2), 10);
                    }

                    state.calendarId = calendarId;
                    state.filter = {type: "", goal: "", status: ""};
                    state.selectedDate = "";
                    load(params, false);
                }
            });
        });

        $app.on("click", ".cc-toggle-btn", function () {
            state.view = $(this).data("view");
            renderViewBar();
            renderViews();
        });

        $("#cc-filter-type").on("change", function () {
            state.filter.type = $(this).val();
            renderViews();
        });
        $("#cc-filter-goal").on("change", function () {
            state.filter.goal = $(this).val();
            renderViews();
        });
        $("#cc-filter-status").on("change", function () {
            state.filter.status = $(this).val();
            renderViews();
        });

        $app.on("click", ".cc-day[data-date]", function () {
            state.selectedDate = $(this).data("date");
            renderGrid();
            renderDayPanel();
            $("#cc-add-content").attr("href", itemFormUrl(0));
            if (window.feather) {
                feather.replace();
            }
        });

        //special day: save on change / blur
        $app.on("change", "#cc-special-input", function () {
            var date = state.selectedDate;
            var label = $.trim($(this).val());
            post(urls.save_special_day, {calendar_id: state.calendarId, date: date, label: label}).done(function (result) {
                if (result.label) {
                    state.specialDays[date] = result.label;
                } else {
                    delete state.specialDays[date];
                }
                renderGrid();
            }).fail(function (err) {
                toast(err.message, "error");
            });
        });

        $app.on("click", ".cc-item-delete", function () {
            var id = parseInt($(this).data("id"), 10);
            confirmModal(L("cc_delete_item_confirm"), function () {
                post(urls.delete_item, {id: id, calendar_id: state.calendarId}).done(function () {
                    toast(L("cc_deleted"));
                    reloadKeepingState();
                }).fail(function (err) {
                    toast(err.message, "error");
                });
            });
        });

        //designer
        $("#cc-columns-toggle").on("click", function () {
            var $d = $("#cc-designer");
            $d.toggleClass("hide");
            $(this).attr("aria-expanded", $d.hasClass("hide") ? "false" : "true");
        });

        $("#cc-add-column").on("click", function () {
            columnModal(null);
        });

        $("#cc-copy-columns").on("click", copyColumnsModal);

        $("#cc-delete-calendar").on("click", function () {
            var calendar = currentCalendar();
            confirmModal(sprintf1(L("cc_delete_client_confirm"), calendar ? calendar.name : ""), function () {
                post(urls.delete_calendar, {calendar_id: state.calendarId}).done(function () {
                    state.calendarId = 0;
                    state.selectedDate = "";
                    toast(L("cc_deleted"));
                    load({calendar_id: 0, year: 0, month: 0}, false);
                }).fail(function (err) {
                    toast(err.message, "error");
                });
            });
        });

        $app.on("click", ".cc-col-edit", function () {
            var col = columnById(parseInt($(this).data("id"), 10));
            if (col) {
                columnModal(col);
            }
        });

        $app.on("click", ".cc-badge-toggle", function () {
            var id = parseInt($(this).data("id"), 10);
            post(urls.toggle_column_visible, {calendar_id: state.calendarId, id: id}).done(function () {
                reloadKeepingState();
            }).fail(function (err) {
                toast(err.message, "error");
            });
        });

        $app.on("click", ".cc-tile-click", function () {
            targetModal(String($(this).attr("data-type")));
        });

        //drag and drop to reorder the column cards
        var dragId = null;
        $app.on("dragstart", ".cc-col-card", function (e) {
            dragId = parseInt($(this).data("id"), 10);
            $(this).addClass("dragging");
            if (e.originalEvent && e.originalEvent.dataTransfer) {
                e.originalEvent.dataTransfer.effectAllowed = "move";
                e.originalEvent.dataTransfer.setData("text/plain", String(dragId));
            }
        });
        $app.on("dragend", ".cc-col-card", function () {
            $(this).removeClass("dragging");
            $(".cc-col-card").removeClass("drag-over");
        });
        $app.on("dragover", ".cc-col-card", function (e) {
            e.preventDefault();
            $(this).addClass("drag-over");
        });
        $app.on("dragleave", ".cc-col-card", function () {
            $(this).removeClass("drag-over");
        });
        $app.on("drop", ".cc-col-card", function (e) {
            e.preventDefault();
            var targetId = parseInt($(this).data("id"), 10);
            if (dragId === null || dragId === targetId) {
                return;
            }

            var ids = $.map(state.columns, function (c) {
                return c.id;
            });
            ids.splice($.inArray(dragId, ids), 1);
            ids.splice($.inArray(targetId, ids), 0, dragId);
            dragId = null;

            post(urls.reorder_column, {calendar_id: state.calendarId, ordered_ids: ids}).done(function () {
                reloadKeepingState();
            }).fail(function (err) {
                toast(err.message, "error");
            });
        });

        /* ---------- start ---------- */

        var params = {};
        if (config.initial.ym) {
            var p = config.initial.ym.split("-");
            params.year = parseInt(p[0], 10);
            params.month = parseInt(p[1], 10);
        }

        load(params, true);
    }

    /* ================================================================ */
    /* ADD / EDIT CONTENT PAGE                                           */
    /* ================================================================ */

    function initItemForm(config) {
        CC.lang = config.lang || {};
        var colors = config.colors || {};
        var $form = $("#cc-item-form");

        function fieldError(key, message) {
            $form.find("[data-error-for='" + key + "']").text(message || "");
        }

        function clearErrors() {
            $form.find(".cc-field-error").text("");
            $("#cc-form-error").text("");
        }

        function currentValue(key) {
            var $f = $form.find("[name='f[" + key + "]']");
            return $f.length ? $.trim($f.val()) : "";
        }

        function updatePreview() {
            var date = currentValue("date");
            var type = currentValue("type");
            var title = currentValue("title");
            $("#cc-preview-date").text(date ? fmtDate(date) : "");
            var text = (type ? type + ": " : "") + (title || L("cc_sample_title"));
            $("#cc-preview-chip").attr("style", chipStyle(colors, type)).text(text);
        }

        $form.on("input change", "input, select, textarea", updatePreview);
        updatePreview();

        function updateDeleteButtons() {
            $form.find(".cc-addopt[data-type='select']").each(function () {
                var key = $(this).data("key");
                var value = $form.find("select[name='f[" + key + "]']").val();
                $(this).find(".cc-delopt-btn").prop("disabled", !value);
            });
        }

        $form.on("change", ".cc-select-field", updateDeleteButtons);
        updateDeleteButtons();

        //---- add option ----
        function addOptionToField(key, option, type) {
            if (type === "select") {
                var $select = $form.find("select[name='f[" + key + "]']");
                var exists = false;
                $select.find("option").each(function () {
                    if ($(this).val() === option) {
                        exists = true;
                    }
                });
                if (!exists) {
                    $select.append($("<option></option>").attr("value", option).text(option));
                }
                $select.val(option).trigger("change");
            } else {
                var $multi = $form.find(".cc-multi[data-key='" + key + "']");
                var $found = null;
                $multi.find("input[type='checkbox']").each(function () {
                    if ($(this).val() === option) {
                        $found = $(this);
                    }
                });
                if ($found) {
                    $found.prop("checked", true);
                } else {
                    var $label = $("<label class='cc-chip-opt'></label>");
                    $label.append($("<input type='checkbox' checked>").attr("name", "f[" + key + "][]").val(option));
                    $label.append($("<span class='cc-chip-text'></span>").text(option));
                    if (config.can_manage_options) {
                        $label.append($("<button type='button' class='cc-chip-x'>&times;</button>").attr("data-option", option).attr("title", L("cc_delete_option")).attr("aria-label", L("cc_delete_option")));
                    }
                    $multi.append($label);
                }
            }
        }

        function doAddOption($box) {
            var $input = $box.find(".cc-addopt-input");
            var option = $.trim($input.val());
            var key = $box.data("key");
            if (option === "") {
                return;
            }

            fieldError(key, "");
            $box.find(".cc-addopt-btn").prop("disabled", true);
            post(config.save_option_url, {calendar_id: config.calendar_id, column_id: $box.data("column-id"), option: option}).done(function (result) {
                $.extend(colors, result.colors || {});
                addOptionToField(key, result.option, $box.data("type"));
                $input.val("");
                updatePreview();
            }).fail(function (err) {
                fieldError(key, err.message);
            }).always(function () {
                $box.find(".cc-addopt-btn").prop("disabled", false);
            });
        }

        $form.on("click", ".cc-addopt-btn", function () {
            doAddOption($(this).closest(".cc-addopt"));
        });

        $form.on("keydown", ".cc-addopt-input", function (e) {
            if (e.key === "Enter") {
                e.preventDefault();
                doAddOption($(this).closest(".cc-addopt"));
            }
        });

        //---- delete option ----
        function doDeleteOption(key, columnId, option, onDone) {
            confirmModal(sprintf1(L("cc_delete_option_confirm"), option), function () {
                fieldError(key, "");
                post(config.delete_option_url, {calendar_id: config.calendar_id, column_id: columnId, option: option}).done(function () {
                    onDone();
                    updatePreview();
                    updateDeleteButtons();
                }).fail(function (err) {
                    fieldError(key, err.message);
                });
            });
        }

        $form.on("click", ".cc-delopt-btn", function () {
            var $box = $(this).closest(".cc-addopt");
            var key = $box.data("key");
            var $select = $form.find("select[name='f[" + key + "]']");
            var option = $select.val();
            if (!option) {
                return;
            }

            doDeleteOption(key, $box.data("column-id"), option, function () {
                $select.find("option").filter(function () {
                    return $(this).val() === option;
                }).remove();
                $select.val("").trigger("change");
            });
        });

        $form.on("click", ".cc-chip-x", function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $chip = $(this).closest(".cc-chip-opt");
            var $field = $(this).closest(".cc-field");
            var key = $field.data("field");
            var columnId = $field.find(".cc-addopt").data("column-id");
            var option = $(this).attr("data-option");
            doDeleteOption(key, columnId, option, function () {
                $chip.remove();
            });
        });

        //---- file upload ----
        $form.on("change", ".cc-file-input", function () {
            var file = this.files && this.files[0];
            var $field = $(this).closest(".cc-field");
            var key = $field.data("field");
            var input = this;
            if (!file) {
                return;
            }

            var fd = new FormData();
            fd.append("file", file);
            var token = csrfData();
            $.each(token, function (k, v) {
                fd.append(k, v);
            });

            fieldError(key, "");
            $.ajax({url: config.upload_url, type: "POST", data: fd, processData: false, contentType: false, dataType: "json"}).done(function (result) {
                if (result && result.success) {
                    $field.find(".cc-file-value").val(result.path);
                } else {
                    fieldError(key, (result && result.message) || L("cc_upload_failed"));
                }
            }).fail(function (xhr) {
                fieldError(key, (xhr.responseJSON && xhr.responseJSON.message) || L("cc_upload_failed"));
            }).always(function () {
                input.value = "";
            });
        });

        //---- submit ----
        $form.on("submit", function (e) {
            e.preventDefault();
            clearErrors();

            var data = {};
            $.each($form.serializeArray(), function (i, f) {
                if (/\[\]$/.test(f.name)) {
                    if (!data[f.name]) {
                        data[f.name] = [];
                    }
                    data[f.name].push(f.value);
                } else {
                    data[f.name] = f.value;
                }
            });

            //checkboxes not ticked are not sent; the server treats a missing value as "No"
            var $btn = $("#cc-submit").prop("disabled", true);
            post(config.save_url, data).done(function (result) {
                window.location.href = result.redirect || config.back_url;
            }).fail(function (err) {
                $btn.prop("disabled", false);
                var shown = false;
                $.each(err.errors || {}, function (key, message) {
                    fieldError(key, message);
                    shown = true;
                });
                $("#cc-form-error").text(shown ? L("cc_please_fix_errors") : err.message);
                var $firstError = $form.find(".cc-field-error:not(:empty)").first();
                if ($firstError.length && $firstError[0].scrollIntoView) {
                    $firstError[0].scrollIntoView({block: "center"});
                }
            });
        });
    }

    /* ---------------------------------------------------------------- */

    $(function () {
        if (window.CC_CONFIG) {
            initCalendarPage(window.CC_CONFIG);
        }
        if (window.CC_ITEM_FORM) {
            initItemForm(window.CC_ITEM_FORM);
        }
    });

})(jQuery);
