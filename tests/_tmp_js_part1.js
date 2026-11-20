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
            calendarId: current ? current : (perms.admin_manage ? "new" : 0),
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
            if (perms.admin_manage) {
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
            if (perms.admin_manage) {
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

            var html = "<div class='cc-sum'>" +
                    box(L("cc_imp_sum_import"), nf(sm.to_import)) +
                    box(L("cc_imp_sum_skipped"), nf(sm.skipped)) +
                    box(L("cc_imp_sum_cols"), nf(cols.length) + (colNames ? "<div class='cc-muted'>" + esc(colNames) + "</div>" : "")) +
                    box(L("cc_imp_sum_opts"), nf(sm.options_created)) +
                    box(L("cc_imp_sum_range"), sm.date_min ? esc(fmtDate(sm.date_min) + " - " + fmtDate(sm.date_max)) : "-", true) +
                    box(L("cc_imp_sum_months"), nf(sm.months_new)) +
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
                    s.target = s.match ? String(s.match) : (perms.admin_manage ? "new" : "");
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

