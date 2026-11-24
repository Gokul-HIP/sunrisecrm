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

    function renderBold(escapedText) {
        if (typeof escapedText !== "string" || escapedText.indexOf("**") === -1) {
            return escapedText;
        }
        return escapedText.replace(/\*\*([^\*\r\n]+?)\*\*/g, "<strong>$1</strong>");
    }

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

    //toast with one action button (e.g. "Moved to 12 Apr 2026" + "Go to date"). Always our own element: appAlert has no buttons.
    function toastAction(message, actionLabel, onAction) {
        var $t = $("<div class='cc-toast cc-toast-action' role='status'></div>");
        $t.append($("<span></span>").text(message));
        var $btn = $("<button type='button' class='cc-toast-link'></button>").text(actionLabel);
        $btn.on("click", function () {
            $t.remove();
            onAction();
        });
        $t.append($btn).appendTo("body");
        setTimeout(function () {
            $t.remove();
        }, 8000);
    }

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
        if (options.className) {
            $dialog.addClass(options.className);
        }

        var titleId = "cc-modal-title-" + (new Date().getTime()) + "-" + modalStack.length;
        $dialog.attr("aria-labelledby", titleId);
        var titleInner = options.titleHtml ? ("<div class='cc-modal-title' id='" + titleId + "'>" + options.titleHtml + "</div>") : ("<h3 class='cc-modal-title' id='" + titleId + "'>" + esc(options.title) + "</h3>");
        $dialog.append("<div class='cc-modal-head'>" + titleInner + "<button type='button' class='cc-modal-close' aria-label='" + esc(L("close")) + "'>&times;</button></div>");
        var $body = $("<div class='cc-modal-body'></div>").html(options.body || "");
        $dialog.append($body);
        var $foot = $("<div class='cc-modal-foot'></div>");
        if (options.footHtml) {
            $foot.html(options.footHtml);
        }
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
        var limits = ctx.limits || {};
        var maxBytes = limits.max_bytes || 5242880;
        var maxRows = limits.max_rows || 5000;
        var exts = limits.extensions || ["csv", "tsv", "txt", "xlsx", "xls", "ods"];
        var NEW_TYPES = ["text", "long", "select", "multi", "date", "link", "number", "check"];
        var CORE_MAP = [["date", "cc_imp_map_date"], ["type", "cc_imp_map_type"], ["title", "cc_imp_map_title"], ["status", "cc_imp_map_status"]];
        var WARN_NEW_OPTIONS = 40;
        var NORM_RE;
        try {
            NORM_RE = new RegExp("[^\\p{L}\\p{N}]+", "gu");
        } catch (e) {
            NORM_RE = /[^a-z0-9]+/g;
        }

        var W = {
            step: 1,
            file: null,
            force: 0, //0 = detect the client of every sheet, otherwise the id of the client that gets all sheets
            fileType: "auto",
            token: "",
            total: 0,
            parsedCount: 0,
            summaries: [],
            fileName: "",
            typeLabel: "",
            notes: [],
            retries: 0,
            gen: 0, //changes when a read is cancelled or started again, so an old response is ignored
            perms: ctx.perms || {},
            calendars: $.map(ctx.calendars() || [], function (c) {
                return {id: c.id, name: c.name};
            }),
            sheets: [],
            meta: {}, //per client group (key = normalized client name): target, mapping by header, value merges, keep as text
            options: {
                year: new Date().getFullYear(),
                carry_blank_date: false,
                import_without_date: true,
                skip_duplicates: true,
                fix_swapped_dates: true,
                special_ignore: "regular design, regular, regular post",
                blank_status: "Planned",
                set_targets_from_counts: false
            },
            search: "",
            detailKey: "",
            detailIndex: -1,
            previews: {},
            previewing: false,
            previewError: "",
            seq: 0,
            timer: null,
            why: {},
            running: false,
            runError: "",
            runId: "",
            results: [],
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
                W.gen++;
                if (W.changed) {
                    ctx.reload();
                }
            }
        });

        var $root = modal.$body.children(".cc-imp-root");

        /* ---------- small helpers ---------- */

        function jsNorm(text) {
            return String(text || "").toLowerCase().replace(NORM_RE, "");
        }

        function canCreate() {
            return !!(W.perms.add_client || W.perms.add_clients || W.perms.admin_manage);
        }

        function calendarName(id) {
            var name = "";
            $.each(W.calendars, function (i, c) {
                if (String(c.id) === String(id)) {
                    name = c.name;
                }
            });
            return name;
        }

        //id of the existing client with this name ("Acme Pvt. Ltd" and "acme pvt ltd" are the same), 0 when there is none
        function findCalendar(name) {
            var n = jsNorm(name);
            var found = 0;
            if (n === "") {
                return 0;
            }
            $.each(W.calendars, function (i, c) {
                if (!found && jsNorm(c.name) === n) {
                    found = c.id;
                }
            });
            return found;
        }

        function sheetBy(index) {
            var found = null;
            $.each(W.sheets, function (i, s) {
                if (s.index === index) {
                    found = s;
                }
            });
            return found;
        }

        function metaFor(key) {
            if (!W.meta[key]) {
                W.meta[key] = {target: "", mapping: {}, merges: {}, leave: {}};
            }
            return W.meta[key];
        }

        //keep the focused field and the scroll position of a scroll box while its html is rebuilt
        function rebuild($area, html, scrollSelector) {
            if (!$area.length) {
                return;
            }
            var active = document.activeElement;
            var fk = (active && $.contains($area[0], active)) ? $(active).attr("data-fk") : "";
            var $scroll = scrollSelector ? $area.find(scrollSelector) : $();
            var top = $scroll.length ? $scroll.scrollTop() : 0;
            $area.html(html);
            if (scrollSelector && top) {
                $area.find(scrollSelector).scrollTop(top);
            }
            if (fk) {
                $area.find("[data-fk='" + fk + "']").first().trigger("focus");
            }
        }

        function box(label, value, small) {
            return "<div class='cc-sum-box'><div class='cc-sum-label'>" + esc(label) + "</div><div class='cc-sum-value" + (small ? " small" : "") + "'>" + value + "</div></div>";
        }

        function note(text, cls) {
            return "<div class='cc-imp-note " + (cls || "") + "'>" + esc(text) + "</div>";
        }

        /* ---------- groups: sheets of the same client are one client ---------- */

        /**
         * The review table is built from the sheets. A group = the sheets that have the same (normalized) client name.
         * Every group has its own state (W.meta): chosen client, column mapping by header, value merges.
         */
        function groupList() {
            var list = [];
            var map = {};
            $.each(W.sheets, function (i, s) {
                var key;
                if (!s.usable || !s.is_cal) {
                    key = "~" + s.index;
                } else if (W.force) {
                    key = "forced";
                } else {
                    key = jsNorm(s.client_name) || ("~n" + s.index);
                }

                if (!map[key]) {
                    map[key] = {key: key, sheets: [], importable: !!(s.usable && s.is_cal), forced: key === "forced"};
                    list.push(map[key]);
                }
                map[key].sheets.push(s);
            });

            $.each(list, function (i, g) {
                var first = g.sheets[0];
                g.index = i;
                g.target = "";
                g.isNew = false;
                g.needsPerm = false;
                if (!g.importable) {
                    g.name = first.orig_client || first.name;
                    return;
                }

                g.name = g.forced ? calendarName(W.force) : $.trim(first.client_name);
                var meta = metaFor(g.key);
                var match = findCalendar(g.name);
                g.target = g.forced ? String(W.force) : (meta.target ? meta.target : (match ? String(match) : "new"));
                g.isNew = g.target === "new";
                g.needsPerm = g.isNew && !canCreate();
            });

            return list;
        }

        function groupByKey(list, key) {
            var found = null;
            $.each(list, function (i, g) {
                if (g.key === key) {
                    found = g;
                }
            });
            return found;
        }

        function eligible(s, g) {
            return s.usable && s.is_cal && !s.done && !(g && g.needsPerm);
        }

        //a group that needs the add client permission cannot be ticked; it is ticked again when it gets an existing client
        function normalizeChecks() {
            $.each(groupList(), function (i, g) {
                $.each(g.sheets, function (j, s) {
                    if (!g.importable) {
                        s.checked = false;
                    } else if (g.needsPerm && s.checked) {
                        s.checked = false;
                        s.autoOff = true;
                    } else if (!g.needsPerm && s.autoOff && !s.done) {
                        s.checked = true;
                        s.autoOff = false;
                    }
                });
            });
        }

        function ensureDetail() {
            var list = groupList();
            var g = W.detailKey ? groupByKey(list, W.detailKey) : null;
            if (!g || !g.importable) {
                g = null;
                $.each(list, function (i, x) {
                    if (!g && x.importable && $.grep(x.sheets, function (s) {
                        return s.checked;
                    }).length) {
                        g = x;
                    }
                });
            }

            if (!g) {
                W.detailKey = "";
                W.detailIndex = -1;
                return;
            }

            W.detailKey = g.key;
            var s = sheetBy(W.detailIndex);
            if (!s || $.inArray(s, g.sheets) === -1) {
                var pick = $.grep(g.sheets, function (x) {
                    return x.checked;
                })[0] || g.sheets[0];
                W.detailIndex = pick.index;
            }
        }

        function setGroupName(g, name) {
            name = $.trim(name);
            if (name === "" || g.forced) {
                return;
            }

            var oldKey = g.key;
            $.each(g.sheets, function (i, s) {
                s.client_name = name;
            });

            var newKey = jsNorm(name) || oldKey;
            var meta = W.meta[oldKey];
            if (meta) {
                meta.target = ""; //a new name = match it again against the existing clients
                if (oldKey !== newKey) {
                    if (!W.meta[newKey]) {
                        W.meta[newKey] = meta;
                    }
                    delete W.meta[oldKey];
                }
            }

            if (W.detailKey === oldKey) {
                W.detailKey = newKey;
            }
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
            var clientOptions = "<option value='auto'" + (!W.force ? " selected" : "") + ">" + esc(L("cc_imp_into_auto")) + "</option>";
            $.each(W.calendars, function (i, c) {
                clientOptions += "<option value='" + c.id + "'" + (String(W.force) === String(c.id) ? " selected" : "") + ">" + esc(c.name) + "</option>";
            });

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
                    "<div class='cc-field'><label class='cc-label' for='cc-imp-client'>" + esc(L("cc_imp_into")) + "</label><select id='cc-imp-client' class='cc-input cc-input-full' data-act='client'>" + clientOptions + "</select></div>" +
                    "<div class='cc-field'><label class='cc-label' for='cc-imp-type'>" + esc(L("cc_imp_file_type")) + "</label><select id='cc-imp-type' class='cc-input cc-input-full' data-act='ftype'>" + typeOptions + "</select></div>" +
                    "</div>" +
                    "<div class='cc-label' style='margin-top:16px'>" + esc(L("cc_imp_file")) + "</div>" +
                    "<div class='cc-drop' id='cc-imp-drop'>" + drop +
                    "<input type='file' id='cc-imp-file' hidden accept='" + esc($.map(exts, function (e) {
                        return "." + e;
                    }).join(",")) + "'></div>" +
                    "<p class='cc-muted' style='margin-top:8px'>" + esc(fmt(L("cc_imp_limits"), Math.round(maxBytes / 1048576), nf(maxRows), exts.join(", "))) + "</p>" +
                    "<div class='cc-progress hide' id='cc-imp-progress'><div class='cc-progress-fill'></div></div>" +
                    "<div class='cc-muted hide' id='cc-imp-progress-text' role='status' aria-live='polite'></div>";
        }

        /* ---------- step 2: options ---------- */

        function optionsHtml() {
            var o = W.options;
            var h = "<div class='cc-imp-optbox'><div class='cc-imp-title' style='margin-top:0'>" + esc(L("cc_imp_options_title")) + "</div><div class='cc-imp-options'>" +
                    "<label class='cc-imp-inline'><span>" + esc(L("cc_imp_year")) + "</span><input type='text' inputmode='numeric' maxlength='4' class='cc-input' data-act='opt-year' aria-label='" + esc(L("cc_imp_year")) + "' value='" + esc(o.year) + "'></label>" +
                    "<label class='cc-check'><input type='checkbox' data-act='opt-dup'" + (o.skip_duplicates ? " checked" : "") + "><span>" + esc(L("cc_imp_opt_skip_dup")) + "</span></label>" +
                    "<label class='cc-check'><input type='checkbox' data-act='opt-nodate'" + (o.import_without_date ? " checked" : "") + "><span>" + esc(L("cc_imp_opt_nodate")) + "</span></label>" +
                    "<label class='cc-check'><input type='checkbox' data-act='opt-carry'" + (o.carry_blank_date ? " checked" : "") + "><span>" + esc(L("cc_imp_opt_carry")) + "</span></label>" +
                    "</div><div class='cc-imp-options'>" +
                    "<label class='cc-imp-inline'><span>" + esc(L("cc_imp_opt_blank_status")) + "</span><select class='cc-select' data-act='opt-blank'>" +
                    "<option value='Planned'" + (o.blank_status === "Planned" ? " selected" : "") + ">" + esc(L("cc_imp_status_planned")) + "</option>" +
                    "<option value=''" + (o.blank_status === "" ? " selected" : "") + ">" + esc(L("cc_imp_status_leave_empty")) + "</option></select></label>";
            if (W.perms.admin_manage) {
                h += "<label class='cc-check'><input type='checkbox' data-act='opt-targets'" + (o.set_targets_from_counts ? " checked" : "") + "><span>" + esc(L("cc_imp_opt_targets")) + "</span></label>";
            }
            h += "</div><div class='cc-imp-options'><label class='cc-imp-inline cc-imp-grow'><span>" + esc(L("cc_imp_opt_special_ignore")) + "</span>" +
                    "<input type='text' class='cc-input' maxlength='600' data-act='opt-ignore' aria-label='" + esc(L("cc_imp_opt_special_ignore")) + "' value='" + esc(o.special_ignore) + "'></label></div>" +
                    "<div id='cc-imp-swap'></div></div>";
            return h;
        }

        function step2ShellHtml() {
            var html = "<div class='cc-modal-error' role='alert'></div>";
            html += "<div class='cc-muted'>" + esc(fmt(L("cc_imp_detected"), W.typeLabel)) + " - " + esc(fmt(L("cc_imp_sheets_found"), W.sheets.length)) + " - " + esc(W.fileName) + "</div>";
            $.each(W.notes || [], function (i, n) {
                html += note(n, "warn");
            });

            html += "<datalist id='cc-imp-clients'>";
            $.each(W.calendars, function (i, c) {
                html += "<option value='" + esc(c.name) + "'></option>";
            });
            html += "</datalist>";

            html += optionsHtml();
            html += "<div class='cc-imp-toolbar'>" +
                    "<input type='search' class='cc-input cc-imp-search' data-act='search' data-fk='search' placeholder='" + esc(L("cc_imp_search")) + "' aria-label='" + esc(L("cc_imp_search")) + "' value='" + esc(W.search) + "'>" +
                    "<button type='button' class='cc-btn cc-btn-sm' data-act='sel-all'>" + esc(L("cc_imp_select_all")) + "</button>" +
                    "<button type='button' class='cc-btn cc-btn-sm' data-act='sel-none'>" + esc(L("cc_imp_select_none")) + "</button>" +
                    "<span class='cc-muted' id='cc-imp-selcount' role='status'></span></div>";
            html += "<div id='cc-imp-table'></div><div class='cc-muted cc-imp-hint'>" + esc(L("cc_imp_group_hint")) + "</div>";
            html += "<div id='cc-imp-totals'></div><div id='cc-imp-extras'></div><div id='cc-imp-detail'></div>";
            return html;
        }

        /* ---------- step 2: the table of sheets ---------- */

        function previewEntry(g) {
            return W.previews[g.key] || null;
        }

        function sheetPlan(entry, s) {
            var found = null;
            if (entry && entry.sheets) {
                $.each(entry.sheets, function (i, sp) {
                    if (sp.index === s.index) {
                        found = sp;
                    }
                });
            }
            return found;
        }

        //{cls, text} shown in the Status column
        function statusOf(g, s) {
            if (s.done) {
                return {cls: "ok", text: L("cc_imp_status_imported")};
            }
            if (!s.usable) {
                return {cls: "bad", text: s.error || L("cc_imp_sheet_unusable")};
            }
            if (!s.is_cal) {
                return {cls: "off", text: L("cc_imp_not_calendar")};
            }
            if (g.needsPerm) {
                return {cls: "warn", text: L("cc_imp_needs_add_clients")};
            }

            var entry = previewEntry(g);
            if (entry && s.checked) {
                var sp = sheetPlan(entry, s);
                if (sp && !sp.ok && sp.error) {
                    return {cls: "bad", text: sp.error};
                }
                if (entry.error && !entry.can_import) {
                    return {cls: "bad", text: entry.error};
                }
                if (!entry.can_import) {
                    return {cls: "warn", text: L("cc_imp_status_nothing")};
                }
            }
            return {cls: "ok", text: L("cc_imp_status_ready")};
        }

        function statusHtml(st) {
            return "<span class='cc-imp-status " + st.cls + "' title='" + esc(st.text) + "'>" + esc(st.text) + "</span>";
        }

        function clientInputHtml(g, s, isGroup) {
            var disabled = g.forced ? " disabled" : "";
            var tag = (isGroup && g.isNew && g.importable) ? " <span class='cc-badge cc-badge-new'>" + esc(L("cc_imp_new_client_tag")) + "</span>" : "";
            if (!g.importable) {
                return "<span class='cc-muted'>" + esc(s.orig_client || "-") + "</span>";
            }
            var act = isGroup ? "gclient" : "sclient";
            var fk = isGroup ? "gclient-" + g.index : "sclient-" + s.index;
            return "<div class='cc-imp-clientcell'><input type='text' class='cc-input' list='cc-imp-clients' maxlength='120' autocomplete='off' data-act='" + act + "' data-gkey='" + esc(g.key) + "' data-index='" + s.index + "' data-fk='" + fk + "' value='" + esc(isGroup ? g.name : s.client_name) + "' aria-label='" + esc(L("cc_imp_col_client")) + "'" + disabled + ">" + tag + "</div>";
        }

        function headerInputHtml(s, g) {
            if (!g.importable) {
                return "<span class='cc-muted'>-</span>";
            }
            return "<input type='text' inputmode='numeric' maxlength='6' class='cc-input cc-imp-hdr' data-act='hdr' data-index='" + s.index + "' data-fk='hdr-" + s.index + "' value='" + esc(s.header_override || s.detected_header || "") + "' aria-label='" + esc(L("cc_imp_col_header_row")) + "'>";
        }

        function matchesSearch(g) {
            var q = $.trim(W.search).toLowerCase();
            if (q === "") {
                return true;
            }
            if (String(g.name).toLowerCase().indexOf(q) !== -1) {
                return true;
            }
            var hit = false;
            $.each(g.sheets, function (i, s) {
                if (String(s.name).toLowerCase().indexOf(q) !== -1 || String(s.client_name).toLowerCase().indexOf(q) !== -1) {
                    hit = true;
                }
            });
            return hit;
        }

        function visibleGroups() {
            return $.grep(groupList(), matchesSearch);
        }

        function sheetRowHtml(g, s, child, shade) {
            var st = statusOf(g, s);
            var checkable = eligible(s, g);
            var sel = (W.detailKey === g.key && W.detailIndex === s.index) ? " selected" : "";
            var h = "<tr class='cc-imp-row " + shade + (child ? " child" : "") + (s.checked ? "" : " off") + sel + "' data-row='1' data-gkey='" + esc(g.key) + "' data-index='" + s.index + "'>";
            h += "<td class='cc-imp-c-check'><input type='checkbox' data-act='check' data-index='" + s.index + "' data-fk='check-" + s.index + "'" + (s.checked ? " checked" : "") + (checkable ? "" : " disabled") + " aria-label='" + esc(s.name) + "'></td>";
            h += "<td class='cc-imp-c-sheet'><span class='cc-imp-sheetname' title='" + esc(s.name) + "'>" + esc(s.name) + "</span></td>";
            h += "<td class='cc-imp-c-client'>" + (child ? clientInputHtml(g, s, false) : clientInputHtml(g, s, true)) + "</td>";
            h += "<td class='cc-imp-c-hdr'>" + headerInputHtml(s, g) + "</td>";
            h += "<td class='num'>" + (s.is_cal && s.usable ? nf(s.content_rows) : "-") + "</td>";
            h += "<td class='num'>" + (s.is_cal && s.usable ? nf(s.without_date_rows) : "-") + "</td>";
            h += "<td class='num'>" + (s.is_cal && s.usable ? nf(s.empty_date_rows) : "-") + "</td>";
            h += "<td class='cc-imp-c-status'>" + statusHtml(st) + "</td></tr>";
            return h;
        }

        function groupHeadHtml(g, shade) {
            var checked = $.grep(g.sheets, function (s) {
                return s.checked;
            }).length;
            var usable = $.grep(g.sheets, function (s) {
                return eligible(s, g);
            }).length;
            var sums = {content: 0, nodate: 0, empty: 0};
            $.each(g.sheets, function (i, s) {
                sums.content += s.content_rows || 0;
                sums.nodate += s.without_date_rows || 0;
                sums.empty += s.empty_date_rows || 0;
            });

            var st = g.needsPerm ? {cls: "warn", text: L("cc_imp_needs_add_clients")} : {cls: "ok", text: fmt(L("cc_imp_sheets_n"), g.sheets.length)};
            var sel = (W.detailKey === g.key) ? " selected" : "";
            var h = "<tr class='cc-imp-row cc-imp-grouprow " + shade + sel + "' data-row='1' data-gkey='" + esc(g.key) + "' data-index='" + g.sheets[0].index + "'>";
            h += "<td class='cc-imp-c-check'><input type='checkbox' data-act='gcheck' data-gkey='" + esc(g.key) + "' data-fk='gcheck-" + g.index + "'" + (checked && checked === usable ? " checked" : "") + (usable ? "" : " disabled") + " aria-label='" + esc(g.name) + "'></td>";
            h += "<td class='cc-imp-c-sheet'><strong>" + esc(fmt(L("cc_imp_sheets_n"), g.sheets.length)) + "</strong></td>";
            h += "<td class='cc-imp-c-client'>" + clientInputHtml(g, g.sheets[0], true) + "</td>";
            h += "<td></td><td class='num'><strong>" + nf(sums.content) + "</strong></td><td class='num'><strong>" + nf(sums.nodate) + "</strong></td><td class='num'><strong>" + nf(sums.empty) + "</strong></td>";
            h += "<td class='cc-imp-c-status'>" + statusHtml(st) + "</td></tr>";
            return h;
        }

        function renderTable() {
            var $area = $root.find("#cc-imp-table");
            if (!$area.length) {
                return;
            }

            var list = visibleGroups();
            var h = "<div class='cc-imp-tablebox' tabindex='-1'><table class='cc-imp-review'><thead><tr>" +
                    "<th class='cc-imp-c-check'></th><th scope='col'>" + esc(L("cc_imp_col_sheet")) + "</th><th scope='col'>" + esc(L("cc_imp_col_client")) + "</th><th scope='col'>" + esc(L("cc_imp_col_header_row")) + "</th>" +
                    "<th scope='col' class='num'>" + esc(L("cc_imp_col_content")) + "</th><th scope='col' class='num'>" + esc(L("cc_imp_col_nodate")) + "</th><th scope='col' class='num'>" + esc(L("cc_imp_col_emptydate")) + "</th><th scope='col'>" + esc(L("cc_imp_col_status")) + "</th></tr></thead><tbody>";

            var shown = 0;
            $.each(list, function (i, g) {
                var shade = g.index % 2 ? "g1" : "g0";
                if (g.sheets.length > 1 && g.importable) {
                    h += groupHeadHtml(g, shade);
                    $.each(g.sheets, function (j, s) {
                        h += sheetRowHtml(g, s, true, shade);
                        shown++;
                    });
                } else {
                    $.each(g.sheets, function (j, s) {
                        h += sheetRowHtml(g, s, false, shade);
                        shown++;
                    });
                }
            });

            if (!shown) {
                h += "<tr><td colspan='8' class='cc-muted' style='padding:16px'>" + esc(L("cc_imp_no_match")) + "</td></tr>";
            }

            h += "</tbody></table></div>";
            rebuild($area, h, ".cc-imp-tablebox");
            updateSelCount();
        }

        function updateSelCount() {
            var total = 0;
            var picked = 0;
            $.each(W.sheets, function (i, s) {
                if (s.usable && s.is_cal) {
                    total++;
                    if (s.checked) {
                        picked++;
                    }
                }
            });
            $root.find("#cc-imp-selcount").text(fmt(L("cc_imp_selected_of"), picked, total));
        }

        /* ---------- step 2: totals, swapped dates, value merges ---------- */

        function checkedEntries() {
            var out = [];
            $.each(groupList(), function (i, g) {
                var entry = previewEntry(g);
                var any = $.grep(g.sheets, function (s) {
                    return s.checked && !s.done;
                }).length;
                if (g.importable && entry && any) {
                    out.push({g: g, entry: entry});
                }
            });
            return out;
        }

        function importableTotal() {
            var n = 0;
            $.each(checkedEntries(), function (i, x) {
                if (x.entry.can_import && !x.g.needsPerm && x.entry.summary) {
                    n += x.entry.summary.to_import;
                }
            });
            return n;
        }

        function renderTotals() {
            var $area = $root.find("#cc-imp-totals");
            var t = {groups: 0, sheets: 0, items: 0, nodate: 0, special: 0, skipped: 0, cols: 0, fresh: 0};
            $.each(checkedEntries(), function (i, x) {
                if (!x.entry.can_import || x.g.needsPerm || !x.entry.summary) {
                    return;
                }
                var sm = x.entry.summary;
                t.groups++;
                t.fresh += x.g.isNew ? 1 : 0;
                t.sheets += $.grep(x.g.sheets, function (s) {
                    return s.checked && !s.done;
                }).length;
                t.items += sm.to_import;
                t.nodate += sm.without_date;
                t.special += sm.special_days;
                t.skipped += sm.skipped;
                t.cols += (sm.new_columns || []).length;
            });

            var h = "";
            if (W.previewError) {
                h += "<div class='cc-imp-note bad' role='alert'>" + esc(W.previewError) + "</div>";
            }

            if (t.groups) {
                h += "<div class='cc-sum cc-imp-totalsbox'>" +
                        box(L("cc_imp_tot_clients"), nf(t.groups) + (t.fresh ? "<div class='cc-muted'>" + esc(fmt(L("cc_imp_tot_new_clients"), t.fresh)) + "</div>" : "")) +
                        box(L("cc_imp_sum_import"), nf(t.items)) +
                        box(L("cc_imp_col_nodate"), nf(t.nodate)) +
                        box(L("cc_imp_special_days"), nf(t.special)) +
                        box(L("cc_imp_sum_skipped"), nf(t.skipped)) +
                        box(L("cc_imp_sum_cols"), nf(t.cols)) +
                        "</div>";
            } else if (!W.previewing) {
                h += "<div class='cc-imp-note'>" + esc(L("cc_imp_nothing_selected")) + "</div>";
            }

            $area.toggleClass("cc-loading", W.previewing);
            $area.html(h);
        }

        function renderSwap() {
            var $area = $root.find("#cc-imp-swap");
            var count = 0;
            var examples = [];
            $.each(checkedEntries(), function (i, x) {
                var sw = x.entry.summary && x.entry.summary.swap_flags;
                if (sw && sw.count > 0) {
                    count += sw.count;
                    $.each(sw.examples || [], function (j, e) {
                        if (examples.length < 5) {
                            examples.push(e);
                        }
                    });
                }
            });

            var h = "";
            if (count > 0) {
                h = "<div class='cc-imp-note warn'><label class='cc-check'><input type='checkbox' data-act='opt-swap'" + (W.options.fix_swapped_dates ? " checked" : "") + "><span><strong>" + esc(L("cc_imp_opt_fix_swap")) + "</strong> - " + esc(fmt(L("cc_imp_swap_found"), nf(count))) + "</span></label><ul class='cc-reasons'>";
                $.each(examples, function (i, e) {
                    h += "<li>" + esc(fmt(L("cc_imp_swap_example"), $.trim(e.sheet), e.row, fmtDate(e.from), fmtDate(e.to))) + "</li>";
                });
                h += "</ul></div>";
            }
            $area.html(h);
        }

        function renderExtras() {
            renderSwap();
            var $area = $root.find("#cc-imp-extras");
            var h = "";
            var mergeHtml = "";
            $.each(checkedEntries(), function (i, x) {
                var e = x.entry;
                $.each(e.many_options || [], function (j, m) {
                    h += "<div class='cc-imp-note warn'><div>" + esc(fmt(L("cc_imp_many_options"), x.g.name + " / " + m.label, nf(m.new_distinct), WARN_NEW_OPTIONS)) + "</div>" +
                            "<label class='cc-check'><input type='checkbox' data-act='leave-text' data-gkey='" + esc(x.g.key) + "' data-tkey='" + esc(m.key) + "'" + (m.as_text ? " checked" : "") + "><span>" + esc(L("cc_imp_keep_as_text")) + "</span></label></div>";
                });

                if (e.summary && e.summary.special_days_locked) {
                    h += note(x.g.name + ": " + L("cc_imp_special_locked"), "warn");
                }

                if (e.merge_suggestions && e.merge_suggestions.length) {
                    mergeHtml += "<div class='cc-imp-mergegroup'><div class='cc-imp-mergehead'>" + esc(x.g.name) + "</div>";
                    $.each(e.merge_suggestions, function (j, m) {
                        mergeHtml += "<label class='cc-check cc-imp-mergerow'><input type='checkbox' data-act='merge' data-gkey='" + esc(x.g.key) + "' data-tkey='" + esc(m.target_key) + "' data-from='" + esc(m.from_key) + "'" + (m.accept ? " checked" : "") + ">" +
                                "<span><strong>" + esc(m.from) + "</strong> &rarr; <strong>" + esc(m.to) + "</strong> <span class='cc-muted'>(" + esc(m.label) + ", " + nf(m.count_from) + " + " + nf(m.count_to) + ", " + esc(L(m.reason === "same" ? "cc_imp_merge_same" : "cc_imp_merge_similar")) + ")</span></span></label>";
                    });
                    mergeHtml += "</div>";
                }
            });

            if (mergeHtml) {
                h += "<div class='cc-imp-title'>" + esc(L("cc_imp_merges_title")) + "</div><p class='cc-muted' style='margin:4px 0 8px'>" + esc(L("cc_imp_merges_help")) + "</p><div class='cc-imp-merges'>" + mergeHtml + "</div>";
            }

            $area.html(h);
        }

        /* ---------- step 2: detail of one client (columns + preview) ---------- */

        function columnsHtml(g, entry) {
            if (!entry.columns || !entry.columns.length) {
                return "";
            }

            var h = "<div class='cc-imp-title'>" + esc(L("cc_imp_review_columns")) + "</div><div class='cc-scroll-box cc-imp-colbox'><table class='cc-imp-table'><thead><tr>" +
                    "<th scope='col'>" + esc(L("cc_imp_col_header")) + "</th><th scope='col'>" + esc(L("cc_imp_col_samples")) + "</th><th scope='col'>" + esc(L("cc_imp_col_detected")) + "</th><th scope='col'>" + esc(L("cc_imp_col_maps")) + "</th></tr></thead><tbody>";

            $.each(entry.columns, function (i, col) {
                var map = col.map || {target: "skip", type: "", label: ""};
                var key = col.key || ("column" + String(col.letter || "").toLowerCase());
                var base = "data-gkey='" + esc(g.key) + "' data-key='" + esc(key) + "' data-col='" + col.index + "'";
                var sel = "<select class='cc-select' data-act='map' " + base + " data-fk='map-" + col.index + "' aria-label='" + esc(L("cc_imp_col_maps") + ": " + col.header) + "'>";
                sel += "<optgroup label='" + esc(L("cc_imp_map_core")) + "'>";
                $.each(CORE_MAP, function (j, c) {
                    sel += "<option value='" + c[0] + "'" + (map.target === c[0] ? " selected" : "") + ">" + esc(L(c[1])) + "</option>";
                });
                sel += "<option value='special_day'" + (map.target === "special_day" ? " selected" : "") + ">" + esc(L("cc_imp_special_days")) + "</option></optgroup>";
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
                            "<input type='text' class='cc-input' maxlength='60' data-act='mapname' " + base + " data-fk='mapname-" + col.index + "' value='" + esc(map.label || col.header) + "' aria-label='" + esc(L("cc_imp_new_col_name")) + "' placeholder='" + esc(L("cc_imp_new_col_name")) + "'>" +
                            "<select class='cc-select' data-act='maptype' " + base + " data-fk='maptype-" + col.index + "' aria-label='" + esc(L("cc_imp_new_col_type")) + "'>" + typeOptions + "</select></div>";
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

            var h = "<div class='cc-imp-title'>" + esc(fmt(L("cc_imp_preview_title"), entry.preview_rows.length)) + "</div><div class='cc-scroll-box'><table class='cc-imp-table cc-imp-preview-table'><thead><tr><th scope='col'>#</th>";
            $.each(entry.labels, function (i, label) {
                h += "<th scope='col'>" + esc(label) + "</th>";
            });
            h += "</tr></thead><tbody>";
            $.each(entry.preview_rows, function (i, row) {
                h += "<tr><td>" + esc(row.row) + "</td>";
                $.each(row.cells, function (j, cell) {
                    h += "<td><div class='cc-imp-preview-cell'>" + esc(cell) + "</div></td>";
                });
                h += "</tr>";
            });
            return h + "</tbody></table></div>";
        }

        function detailSummaryHtml(entry) {
            var sm = entry.summary;
            if (!sm) {
                return "";
            }

            var cols = sm.new_columns || [];
            var colNames = $.map(cols, function (c) {
                return c.label;
            }).join(", ");
            var monthsNew = $.isArray(sm.months_new) ? sm.months_new : [];
            var h = "<div class='cc-sum'>" +
                    box(L("cc_imp_sum_import"), nf(sm.to_import)) +
                    box(L("cc_imp_col_nodate"), nf(sm.without_date)) +
                    box(L("cc_imp_special_days"), nf(sm.special_days)) +
                    box(L("cc_imp_sum_skipped"), nf(sm.skipped)) +
                    box(L("cc_imp_sum_cols"), nf(cols.length) + (colNames ? "<div class='cc-muted'>" + esc(colNames) + "</div>" : "")) +
                    box(L("cc_imp_sum_opts"), nf(sm.options_created)) +
                    box(L("cc_imp_sum_range"), sm.date_min ? esc(fmtDate(sm.date_min) + " - " + fmtDate(sm.date_max)) : "-", true) +
                    box(L("cc_imp_sum_months"), nf(monthsNew.length) + (monthsNew.length ? "<div class='cc-muted'>" + esc(monthsNew.join(", ")) + "</div>" : "")) +
                    "</div>";

            if (sm.header_repeats > 0) {
                h += note(fmt(L("cc_imp_info_repeats"), sm.header_repeats));
            }
            if (sm.notes > 0) {
                h += note(fmt(L("cc_imp_info_notes"), sm.notes));
            }
            if (sm.invalid_cells > 0) {
                h += note(fmt(L("cc_imp_info_invalid_cells"), sm.invalid_cells));
            }
            if (sm.truncated_cells > 0) {
                h += note(fmt(L("cc_imp_info_truncated"), sm.truncated_cells));
            }
            if (sm.locked_option_cells > 0) {
                h += note(fmt(L("cc_imp_info_locked_cells"), sm.locked_option_cells) + " " + L("cc_imp_needs_options"), "warn");
            }
            if (sm.locked_columns && sm.locked_columns.length) {
                h += note(fmt(L("cc_imp_info_locked_columns"), sm.locked_columns.join(", ")) + " (" + L("cc_imp_needs_admin") + ")", "warn");
            }

            var counts = [];
            $.each(sm.header_counts || {}, function (label, n) {
                counts.push(label + " " + n);
            });
            if (counts.length) {
                h += "<div class='cc-muted'>" + esc(fmt(L("cc_imp_header_counts"), counts.join(", "))) + "</div>";
            }

            if (entry.skipped && entry.skipped.length) {
                var open = !!W.why[entry.key];
                h += "<button type='button' class='cc-btn cc-btn-sm' data-act='why' data-gkey='" + esc(entry.key) + "' aria-expanded='" + (open ? "true" : "false") + "'>" + esc(open ? L("cc_imp_hide") : L("cc_imp_see_why")) + " (" + nf(sm.skipped) + ")</button>" +
                        "<ul class='cc-reasons" + (open ? "" : " hide") + "'>";
                $.each(entry.skipped, function (i, r) {
                    h += "<li>" + esc((r.sheet ? $.trim(r.sheet) + " - " : "") + fmt(L("cc_imp_row_n"), r.row)) + ": " + esc(r.reason) + "</li>";
                });
                h += "</ul>";
                if (sm.skipped > entry.skipped.length) {
                    h += "<div class='cc-muted'>" + esc(L("cc_imp_skipped_more")) + "</div>";
                }
            }

            return h;
        }

        function renderDetail() {
            var $area = $root.find("#cc-imp-detail");
            var g = W.detailKey ? groupByKey(groupList(), W.detailKey) : null;
            if (!g || !g.importable) {
                $area.html("<div class='cc-muted cc-imp-hint'>" + esc(L("cc_imp_detail_hint")) + "</div>");
                return;
            }

            var entry = previewEntry(g);
            var h = "<div class='cc-imp-detailbox'><div class='cc-imp-detailhead'><h4 class='cc-imp-detailtitle'>" + esc(fmt(L("cc_imp_detail_title"), g.name)) + "</h4>";

            if (!g.forced) {
                var sel = "<select class='cc-select' data-act='target' data-gkey='" + esc(g.key) + "' data-fk='target' aria-label='" + esc(L("cc_imp_import_into")) + "'>";
                if (canCreate() || g.isNew) {
                    sel += "<option value='new'" + (g.isNew ? " selected" : "") + ">" + esc(fmt(L("cc_imp_target_new"), g.name || "?")) + "</option>";
                }
                $.each(W.calendars, function (i, c) {
                    sel += "<option value='" + c.id + "'" + (g.target === String(c.id) ? " selected" : "") + ">" + esc(c.name) + "</option>";
                });
                h += "<label class='cc-imp-inline'><span>" + esc(L("cc_imp_import_into")) + "</span>" + sel + "</select></label>";
            }
            h += "</div>";

            if (g.sheets.length > 1) {
                h += "<div class='cc-imp-sheettabs' role='tablist'>";
                $.each(g.sheets, function (i, s) {
                    h += "<button type='button' class='cc-btn cc-btn-sm" + (s.index === W.detailIndex ? " cc-btn-primary" : "") + "' data-act='dsheet' data-index='" + s.index + "' aria-pressed='" + (s.index === W.detailIndex ? "true" : "false") + "'>" + esc(s.name) + "</button>";
                });
                h += "</div>";
            }

            var current = sheetBy(W.detailIndex);
            if (current) {
                h += "<div class='cc-muted'>" + esc(fmt(L("cc_imp_detected_row"), current.detected_header || "-")) + "</div>";
            }

            if (g.needsPerm) {
                h += note(L("cc_imp_needs_add_clients") + " - " + L("cc_imp_pick_existing"), "warn");
            }

            if (!entry) {
                h += "<div class='cc-muted' role='status'>" + esc(L("cc_imp_working_preview")) + "</div>";
            } else {
                entry.key = g.key;
                if (entry.error) {
                    h += "<div class='cc-imp-note bad' role='alert'>" + esc(entry.error) + "</div>";
                }
                $.each(entry.warnings || [], function (i, w) {
                    h += note(w, "warn");
                });

                h += detailSummaryHtml(entry);
                if (entry.columns && entry.detail_index === W.detailIndex) {
                    var hasMap = false;
                    $.each(metaFor(g.key).mapping, function () {
                        hasMap = true;
                    });
                    h += columnsHtml(g, entry);
                    if (hasMap) {
                        h += "<button type='button' class='cc-btn cc-btn-sm' data-act='map-reset' data-gkey='" + esc(g.key) + "'>" + esc(L("cc_imp_map_reset")) + "</button>";
                    }
                    h += previewTableHtml(entry);
                } else if (entry.columns) {
                    h += "<div class='cc-muted' role='status'>" + esc(L("cc_imp_working_preview")) + "</div>";
                }
            }

            $area.toggleClass("cc-loading", W.previewing);
            rebuild($area, h + "</div>");
        }

        function renderReview() {
            renderTable();
            renderTotals();
            renderExtras();
            renderDetail();
            updateFooter();
        }

        /* ---------- payload / preview ---------- */

        function canRunGroup(g) {
            var entry = previewEntry(g);
            return g.importable && !g.needsPerm && entry && entry.can_import;
        }

        function buildPayload(forRun) {
            var groups = [];
            var o = W.options;
            $.each(groupList(), function (i, g) {
                if (!g.importable) {
                    return;
                }

                var meta = metaFor(g.key);
                var chosen = $.grep(g.sheets, function (s) {
                    return s.checked && !s.done;
                });
                var isDetail = g.key === W.detailKey;
                if (forRun) {
                    if (!chosen.length || !canRunGroup(g)) {
                        return;
                    }
                } else if (!chosen.length && !isDetail) {
                    return;
                }

                var use = chosen.length ? chosen : $.grep(g.sheets, function (s) {
                    return s.usable && s.is_cal;
                });
                var headers = {};
                $.each(use, function (j, s) {
                    if (s.header_override) {
                        headers[s.index] = s.header_override;
                    }
                });

                groups.push({
                    key: g.key, checked: chosen.length > 0, client_name: g.name, target: g.target, new_name: g.name,
                    sheet_indexes: $.map(use, function (s) {
                        return s.index;
                    }),
                    header_rows: headers, mapping_by_header: meta.mapping, value_merges: meta.merges, leave_as_text: meta.leave
                });
            });

            var year = parseInt(o.year, 10);
            return {
                token: W.token, run_id: W.runId,
                options: {
                    year: year >= 2000 && year <= 2100 ? year : new Date().getFullYear(),
                    carry_blank_date: o.carry_blank_date, import_without_date: o.import_without_date, skip_duplicates: o.skip_duplicates,
                    fix_swapped_dates: o.fix_swapped_dates, special_ignore: o.special_ignore, blank_status: o.blank_status,
                    set_targets_from_counts: !!(o.set_targets_from_counts && W.perms.admin_manage)
                },
                groups: groups, detail_group: W.detailKey, detail_index: W.detailIndex
            };
        }

        function refreshPreview(immediate) {
            if (W.timer) {
                clearTimeout(W.timer);
            }

            W.timer = setTimeout(doPreview, immediate ? 0 : 250);
            W.previewing = true;
            updateFooter();
            $root.find("#cc-imp-totals, #cc-imp-detail").addClass("cc-loading");
        }

        function doPreview() {
            W.timer = null;
            var payload = buildPayload(false);
            var seq = ++W.seq;
            if (!payload.groups.length) {
                W.previews = {};
                W.previewing = false;
                W.previewError = "";
                renderReview();
                return;
            }

            post(urls.import_preview, {payload: JSON.stringify(payload)}).done(function (result) {
                if (seq !== W.seq || W.step !== 2) {
                    return;
                }

                W.previewError = "";
                W.previews = {};
                $.each(result.groups || [], function (i, entry) {
                    W.previews[entry.key] = entry;
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
                renderReview();
            });
        }

        /* ---------- sheets setup after the parse ---------- */

        function initSheets(summaries, serverGroups) {
            W.meta = {};
            W.sheets = $.map(summaries, function (info) {
                return {
                    index: info.index, name: $.trim(info.name), rows: info.rows, data_rows: info.data_rows, detected_header: info.header_row,
                    usable: !!info.usable, error: info.error || "", status: info.status || "", reason: info.reason || "",
                    is_cal: !!info.is_content_calendar, orig_client: info.client_name || "", client_name: info.client_name || "",
                    match: info.match_calendar_id || 0, header_override: 0, checked: false, done: false, autoOff: false,
                    content_rows: info.content_rows || 0, without_date_rows: info.without_date_rows || 0, empty_date_rows: info.empty_date_rows || 0,
                    suggested: !!info.checked
                };
            });

            //the server merged the sheets of one client (Truscan / Truscan Diagnostis ...): they get one name
            $.each(serverGroups || [], function (i, g) {
                if (!g.is_content_calendar) {
                    return;
                }
                $.each(g.sheet_indexes, function (j, index) {
                    var s = sheetBy(index);
                    if (s) {
                        s.client_name = g.client_name;
                    }
                });
            });

            //one sheet (a CSV) while a client is open and nothing matches: it goes to the open client
            var openId = ctx.calendarId();
            var calSheets = $.grep(W.sheets, function (s) {
                return s.usable && s.is_cal;
            });
            if (!W.force && openId && W.sheets.length === 1 && calSheets.length === 1 && !findCalendar(calSheets[0].client_name)) {
                calSheets[0].client_name = calendarName(openId);
            }

            $.each(W.sheets, function (i, s) {
                s.checked = s.usable && s.is_cal && s.suggested;
            });
            normalizeChecks();
            W.detailKey = "";
            W.detailIndex = -1;
            ensureDetail();
        }

        /* ---------- step 3: result ---------- */

        function computeTotals() {
            var t = {clients: 0, clients_created: 0, failed: 0, imported: 0, without_date: 0, special_days_written: 0, skipped: 0, columns_created: 0, options_created: 0, months_added: 0};
            $.each(W.results || [], function (i, r) {
                if (!r.success) {
                    t.failed++;
                    return;
                }
                t.clients++;
                t.clients_created += r.is_new_client ? 1 : 0;
                $.each(r.logs || [], function (j, l) {
                    if (!l.undone) {
                        t.imported += l.imported;
                        t.without_date += l.without_date;
                    }
                });
                t.special_days_written += r.special_days_written || 0;
                t.skipped += r.skipped || 0;
                t.columns_created += r.columns_created || 0;
                t.options_created += r.options_created || 0;
                t.months_added += r.months_added || 0;
            });
            return t;
        }

        function resultImported(r) {
            var n = 0;
            $.each(r.logs || [], function (i, l) {
                if (!l.undone) {
                    n += l.imported;
                }
            });
            return n;
        }

        function resultNoDate(r) {
            var n = 0;
            $.each(r.logs || [], function (i, l) {
                if (!l.undone) {
                    n += l.without_date;
                }
            });
            return n;
        }

        function resultHtml(r, i) {
            if (!r.success) {
                return "<div class='cc-result bad'><h4 class='cc-result-title'>" + esc(r.client_name) + " <span class='cc-badge cc-badge-bad'>" + esc(L("cc_imp_not_imported")) + "</span></h4>" +
                        "<div class='cc-imp-note bad' role='alert'>" + esc(r.message || L("cc_error")) + "</div></div>";
            }

            var imported = resultImported(r);
            var noDate = resultNoDate(r);
            var allUndone = imported === 0 && (r.logs || []).length > 0 && $.grep(r.logs, function (l) {
                return !l.undone;
            }).length === 0;
            var h = "<div class='cc-result " + (allUndone ? "undone" : "ok") + "'><h4 class='cc-result-title'>" + esc(r.calendar_name || r.client_name) +
                    (r.is_new_client ? " <span class='cc-badge cc-badge-new'>" + esc(L("cc_imp_new_client_tag")) + "</span>" : "") +
                    (allUndone ? " <span class='cc-badge'>" + esc(L("cc_imp_undone_label")) + "</span>" : " <span class='cc-badge cc-badge-ok'>" + esc(L("cc_imp_res_imported")) + "</span>") + "</h4>";

            h += "<div class='cc-sum'>" +
                    box(L("cc_imp_res_imported"), nf(imported)) +
                    box(L("cc_imp_col_nodate"), nf(noDate)) +
                    box(L("cc_imp_special_days"), nf(r.special_days_written || 0)) +
                    box(L("cc_imp_res_skipped"), nf(r.skipped || 0)) +
                    box(L("cc_imp_res_columns"), nf(r.columns_created || 0)) +
                    box(L("cc_imp_res_options"), nf(r.options_created || 0)) +
                    box(L("cc_imp_res_months"), nf(r.months_added || 0)) +
                    "</div>";

            if (r.new_column_names && r.new_column_names.length) {
                h += note(L("cc_imp_res_columns") + ": " + r.new_column_names.join(", "));
            }
            if (r.special_days_locked) {
                h += note(L("cc_imp_special_locked"), "warn");
            }
            if (r.locked_option_cells > 0) {
                h += note(fmt(L("cc_imp_info_locked_cells"), r.locked_option_cells), "warn");
            }
            if (r.locked_columns && r.locked_columns.length) {
                h += note(fmt(L("cc_imp_info_locked_columns"), r.locked_columns.join(", ")), "warn");
            }
            if (r.swap_flags && r.swap_flags.count > 0) {
                h += note(fmt(L(r.swap_flags.fixed ? "cc_imp_warn_swapped_fixed" : "cc_imp_warn_swapped"), r.swap_flags.count));
            }

            if ((r.logs || []).length > 1 || (r.logs || []).length === 1) {
                h += "<ul class='cc-imp-sheetlist'>";
                $.each(r.logs || [], function (j, l) {
                    h += "<li><span class='cc-imp-sheetname'>" + esc($.trim(l.sheet_name)) + "</span> <span class='cc-muted'>" + esc(l.undone ? L("cc_imp_undone_label") : fmt(L("cc_imp_sheet_result"), nf(l.imported), nf(l.without_date), nf(l.skipped))) + "</span>";
                    if (!l.undone && l.imported > 0) {
                        h += " <button type='button' class='cc-btn cc-btn-sm cc-btn-danger-ghost' data-act='undo-sheet' data-i='" + i + "' data-l='" + j + "'>" + esc(L("cc_imp_undo_sheet")) + "</button>";
                    }
                    h += "</li>";
                });
                h += "</ul>";
            }

            if (r.skipped_list && r.skipped_list.length) {
                var open = !!W.why["r" + i];
                h += "<button type='button' class='cc-btn cc-btn-sm' data-act='rwhy' data-i='" + i + "' aria-expanded='" + (open ? "true" : "false") + "'>" + esc(open ? L("cc_imp_hide") : L("cc_imp_skipped_rows")) + " (" + nf(r.skipped) + ")</button>" +
                        "<ul class='cc-reasons" + (open ? "" : " hide") + "'>";
                $.each(r.skipped_list, function (j, row) {
                    h += "<li>" + esc((row.sheet ? $.trim(row.sheet) + " - " : "") + fmt(L("cc_imp_row_n"), row.row)) + ": " + esc(row.reason) + "</li>";
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
            if (noDate > 0) {
                h += "<button type='button' class='cc-btn cc-btn-sm' data-act='nodate' data-i='" + i + "'>" + esc(fmt(L("cc_imp_n_without_date"), nf(noDate))) + "</button>";
            }
            if (!allUndone) {
                h += "<button type='button' class='cc-btn cc-btn-sm cc-btn-primary' data-act='goto' data-i='" + i + "'>" + esc(L("cc_imp_view_client")) + "</button>";
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

            var t = computeTotals();
            var h = "<h4 class='cc-imp-title' style='margin-top:0'>" + esc(L("cc_imp_done_title")) + "</h4>";
            if (!W.allOk) {
                h += note(L("cc_imp_done_partial"), "warn");
            }

            h += "<div class='cc-sum cc-imp-totalsbox'>" +
                    box(L("cc_imp_tot_clients"), nf(t.clients) + (t.clients_created ? "<div class='cc-muted'>" + esc(fmt(L("cc_imp_tot_new_clients"), t.clients_created)) + "</div>" : "")) +
                    box(L("cc_imp_res_imported"), nf(t.imported)) +
                    box(L("cc_imp_col_nodate"), nf(t.without_date)) +
                    box(L("cc_imp_special_days"), nf(t.special_days_written)) +
                    box(L("cc_imp_res_skipped"), nf(t.skipped)) +
                    box(L("cc_imp_res_columns"), nf(t.columns_created)) +
                    box(L("cc_imp_res_options"), nf(t.options_created)) +
                    box(L("cc_imp_res_months"), nf(t.months_added)) +
                    "</div>";

            if (t.imported > 0 && W.runId) {
                h += "<div class='cc-result-actions' style='margin:0 0 12px'><button type='button' class='cc-btn cc-btn-sm cc-btn-danger-ghost' data-act='undo-all'>" + esc(L("cc_imp_undo_all")) + "</button></div>";
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
                renderReview();
            }

            updateFooter();
        }

        function updateFooter() {
            var buttons = [];
            if (W.step === 1) {
                if (W.reading) {
                    buttons = [{label: L("cancel"), onClick: cancelReading}];
                } else {
                    buttons = [
                        {label: L("cancel"), onClick: function () {
                                modal.requestClose();
                            }},
                        {label: L("cc_imp_next"), className: "cc-btn-primary", disabled: !W.file || modal.busy, onClick: startParse}
                    ];
                }
            } else if (W.step === 2) {
                var total = importableTotal();
                buttons = [
                    {label: L("cc_imp_back"), onClick: function () {
                            W.seq++;
                            W.step = 1;
                            render();
                        }},
                    {label: total ? fmt(L("cc_imp_import_items_n"), nf(total)) : L("cc_imp_apply"), className: "cc-btn-primary", disabled: W.previewing || total < 1, onClick: runImport}
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

        /* ---------- step 1: file + upload + reading sheet by sheet ---------- */

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

        function setProgress(pct, text, indeterminate) {
            var $bar = $root.find("#cc-imp-progress").removeClass("hide").toggleClass("indeterminate", !!indeterminate);
            $bar.find(".cc-progress-fill").css("width", indeterminate ? "" : pct + "%");
            $root.find("#cc-imp-progress-text").removeClass("hide").text(text);
        }

        function startParse() {
            modal.setError("");
            if (!W.file) {
                modal.setError(L("cc_imp_choose_file"));
                return;
            }

            var fd = new FormData();
            fd.append("file", W.file);
            fd.append("calendar_id", W.force ? W.force : 0);
            fd.append("file_type", W.fileType);
            $.each(csrfData(), function (k, v) {
                fd.append(k, v);
            });

            var gen = ++W.gen;
            W.reading = true;
            modal.busy = true;
            updateFooter();
            setProgress(0, L("cc_imp_uploading"), false);
            $root.find("button, select").prop("disabled", true);

            $.ajax({
                url: urls.import_parse, type: "POST", data: fd, processData: false, contentType: false, dataType: "json",
                xhr: function () {
                    var xhr = $.ajaxSettings.xhr();
                    if (xhr.upload) {
                        xhr.upload.addEventListener("progress", function (e) {
                            if (e.lengthComputable) {
                                var pct = Math.round(e.loaded / e.total * 100);
                                if (pct >= 100) {
                                    setProgress(100, L("cc_imp_reading"), true);
                                } else {
                                    setProgress(pct, L("cc_imp_uploading"), false);
                                }
                            }
                        });
                    }
                    return xhr;
                }
            }).done(function (result) {
                if (gen !== W.gen) {
                    return;
                }

                if (result && result.success) {
                    W.token = result.token;
                    W.total = result.total || (result.sheet_names || []).length;
                    W.fileName = result.file_name || W.file.name;
                    W.typeLabel = result.type_label || "";
                    W.notes = result.notes || [];
                    W.calendars = result.calendars || W.calendars;
                    W.perms = result.perms || W.perms;
                    W.parsedCount = 0;
                    W.summaries = [];
                    W.retries = 0;
                    W.previews = {};
                    W.previewError = "";
                    W.why = {};
                    W.runId = "";
                    W.results = [];
                    setProgress(0, fmt(L("cc_imp_reading_sheet"), 1, W.total), false);
                    readNext(gen);
                } else {
                    parseFailed((result && result.message) || L("cc_error"));
                }
            }).fail(function (xhr) {
                if (gen === W.gen) {
                    parseFailed((xhr.responseJSON && xhr.responseJSON.message) || (xhr.status === 403 ? L("cc_forbidden") : L("cc_error")));
                }
            });
        }

        //read the next sheets, one request at a time, until the server says "done"
        function readNext(gen) {
            if (gen !== W.gen) {
                return;
            }

            post(urls.import_parse_next, {token: W.token, batch: 2}).done(function (r) {
                if (gen !== W.gen) {
                    return;
                }

                W.retries = 0;
                W.parsedCount = r.parsed;
                W.total = r.total;
                W.summaries = W.summaries.concat(r.sheets || []);
                setProgress(r.total ? Math.round(r.parsed / r.total * 100) : 100, fmt(L("cc_imp_reading_sheet"), Math.max(1, r.parsed), r.total), false);
                if (r.done) {
                    W.calendars = r.calendars || W.calendars;
                    W.perms = r.perms || W.perms;
                    W.reading = false;
                    modal.busy = false;
                    initSheets(r.sheets || [], r.groups || []);
                    W.step = 2;
                    render();
                    refreshPreview(true);
                } else {
                    readNext(gen);
                }
            }).fail(function (err) {
                if (gen !== W.gen) {
                    return;
                }

                //a network hiccup: try the same sheets once more (reading is safe to repeat)
                if (W.retries < 1 && err.status !== 200 && err.status !== 403) {
                    W.retries++;
                    setTimeout(function () {
                        readNext(gen);
                    }, 600);
                    return;
                }

                parseFailed(err.message);
            });
        }

        function cancelReading() {
            W.gen++;
            W.reading = false;
            modal.busy = false;
            W.token = "";
            render();
        }

        function parseFailed(message) {
            W.reading = false;
            modal.busy = false;
            render();
            modal.setError(message);
        }

        /* ---------- step 3: run ---------- */

        function runImport() {
            var payload = buildPayload(true);
            if (!payload.groups.length) {
                modal.setError(L("cc_imp_nothing_selected"));
                return;
            }

            W.seq++;
            W.step = 3;
            W.running = true;
            W.runError = "";
            modal.busy = true;
            render();

            post(urls.import_run, {payload: JSON.stringify(payload)}).done(function (result) {
                //the same run id is kept so a retry of the failed clients belongs to the same import
                if (result.run_id) {
                    W.runId = result.run_id;
                }
                W.allOk = !!result.all_ok;

                var fresh = result.results || [];
                var keys = {};
                $.each(fresh, function (i, r) {
                    keys[r.key] = true;
                    if (r.success) {
                        W.changed = true;
                        $.each(r.logs || [], function (j, l) {
                            var s = sheetBy(l.index);
                            if (s) {
                                s.done = true;
                                s.checked = false;
                            }
                        });
                    }
                });

                var kept = $.grep(W.results || [], function (r) {
                    return r.success && !keys[r.key];
                });
                W.results = kept.concat(fresh);
                if ($.grep(W.results, function (r) {
                    return !r.success;
                }).length === 0) {
                    W.allOk = true;
                }
            }).fail(function (err) {
                W.runError = err.message;
            }).always(function () {
                W.running = false;
                modal.busy = false;
                render();
            });
        }

        /* ---------- events ---------- */

        $root.on("change", "[data-act='client']", function () {
            var v = $(this).val();
            W.force = v === "auto" ? 0 : parseInt(v, 10);
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

        /* options */
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
        $root.on("change", "[data-act='opt-nodate']", function () {
            W.options.import_without_date = $(this).prop("checked");
            refreshPreview();
        });
        $root.on("change", "[data-act='opt-carry']", function () {
            W.options.carry_blank_date = $(this).prop("checked");
            refreshPreview();
        });
        $root.on("change", "[data-act='opt-swap']", function () {
            W.options.fix_swapped_dates = $(this).prop("checked");
            refreshPreview();
        });
        $root.on("change", "[data-act='opt-blank']", function () {
            W.options.blank_status = $(this).val();
            refreshPreview();
        });
        $root.on("change", "[data-act='opt-targets']", function () {
            W.options.set_targets_from_counts = $(this).prop("checked");
            refreshPreview();
        });
        $root.on("change", "[data-act='opt-ignore']", function () {
            W.options.special_ignore = $.trim($(this).val());
            refreshPreview();
        });

        /* table */
        $root.on("input", "[data-act='search']", function () {
            W.search = $(this).val();
            renderTable();
        });
        $root.on("click", "[data-act='sel-all'], [data-act='sel-none']", function () {
            var on = $(this).attr("data-act") === "sel-all";
            $.each(visibleGroups(), function (i, g) {
                $.each(g.sheets, function (j, s) {
                    if (eligible(s, g)) {
                        s.checked = on;
                    }
                });
            });
            ensureDetail();
            renderTable();
            refreshPreview(true);
        });

        $root.on("change", "[data-act='check']", function () {
            var s = sheetBy(parseInt($(this).attr("data-index"), 10));
            if (s) {
                s.checked = $(this).prop("checked");
                s.autoOff = false;
                ensureDetail();
                renderTable();
                refreshPreview();
            }
        });
        $root.on("change", "[data-act='gcheck']", function () {
            var g = groupByKey(groupList(), $(this).attr("data-gkey"));
            var on = $(this).prop("checked");
            if (g) {
                $.each(g.sheets, function (i, s) {
                    if (eligible(s, g)) {
                        s.checked = on;
                        s.autoOff = false;
                    }
                });
                ensureDetail();
                renderTable();
                refreshPreview();
            }
        });

        //renaming the client of a group renames all its sheets; renaming one sheet moves just that sheet
        $root.on("change", "[data-act='gclient']", function () {
            var g = groupByKey(groupList(), $(this).attr("data-gkey"));
            if (g) {
                setGroupName(g, $(this).val());
                normalizeChecks();
                ensureDetail();
                renderTable();
                refreshPreview(true);
            }
        });
        $root.on("change", "[data-act='sclient']", function () {
            var s = sheetBy(parseInt($(this).attr("data-index"), 10));
            var name = $.trim($(this).val());
            if (s && name !== "") {
                s.client_name = name;
                normalizeChecks();
                ensureDetail();
                renderTable();
                refreshPreview(true);
            }
        });

        $root.on("change", "[data-act='hdr']", function () {
            var s = sheetBy(parseInt($(this).attr("data-index"), 10));
            if (s) {
                var n = parseInt($(this).val(), 10);
                s.header_override = n >= 1 ? n : 0;
                refreshPreview();
            }
        });

        //click a row: open its client for review (columns + preview)
        $root.on("click", "tr[data-row]", function (e) {
            if ($(e.target).closest("input, select, button, label, a, textarea").length) {
                return;
            }

            W.detailKey = $(this).attr("data-gkey");
            W.detailIndex = parseInt($(this).attr("data-index"), 10);
            var g = groupByKey(groupList(), W.detailKey);
            if (!g || !g.importable) {
                W.detailKey = "";
                W.detailIndex = -1;
                renderTable();
                renderDetail();
                return;
            }
            renderTable();
            renderDetail();
            refreshPreview(true);
        });

        /* detail panel */
        $root.on("click", "[data-act='dsheet']", function () {
            W.detailIndex = parseInt($(this).attr("data-index"), 10);
            renderTable();
            renderDetail();
            refreshPreview(true);
        });
        $root.on("change", "[data-act='target']", function () {
            var key = $(this).attr("data-gkey");
            var meta = metaFor(key);
            meta.target = $(this).val();
            meta.mapping = {}; //the columns of the chosen client are different
            normalizeChecks();
            renderTable();
            refreshPreview(true);
        });

        function colOf(entry, index) {
            var found = null;
            $.each((entry && entry.columns) || [], function (i, c) {
                if (c.index === index) {
                    found = c;
                }
            });
            return found;
        }

        //the mapping that the user changed, kept by normalized column name for every sheet of the client
        function mapEntry(key, colKey, col) {
            var meta = metaFor(key);
            if (!meta.mapping[colKey]) {
                var cur = (col && col.map) || {target: "skip", type: "", label: ""};
                meta.mapping[colKey] = {target: cur.target, type: cur.type || "", label: cur.label || ""};
            }
            return meta.mapping[colKey];
        }

        $root.on("change", "[data-act='map']", function () {
            var key = $(this).attr("data-gkey");
            var g = groupByKey(groupList(), key);
            var col = colOf(previewEntry(g || {key: key}), parseInt($(this).attr("data-col"), 10));
            var m = mapEntry(key, $(this).attr("data-key"), col);
            m.target = $(this).val();
            if (m.target === "new" && col) {
                m.label = m.label || col.header;
                m.type = m.type || ($.inArray(col.detected_type, NEW_TYPES) !== -1 ? col.detected_type : "text");
            }
            refreshPreview(true);
        });
        $root.on("change", "[data-act='mapname']", function () {
            var key = $(this).attr("data-gkey");
            var g = groupByKey(groupList(), key);
            var col = colOf(previewEntry(g || {key: key}), parseInt($(this).attr("data-col"), 10));
            mapEntry(key, $(this).attr("data-key"), col).label = $.trim($(this).val());
            refreshPreview();
        });
        $root.on("change", "[data-act='maptype']", function () {
            var key = $(this).attr("data-gkey");
            var g = groupByKey(groupList(), key);
            var col = colOf(previewEntry(g || {key: key}), parseInt($(this).attr("data-col"), 10));
            mapEntry(key, $(this).attr("data-key"), col).type = $(this).val();
            refreshPreview();
        });
        $root.on("click", "[data-act='map-reset']", function () {
            metaFor($(this).attr("data-gkey")).mapping = {};
            refreshPreview(true);
        });

        /* value merges and "keep as text" */
        $root.on("change", "[data-act='merge']", function () {
            var meta = metaFor($(this).attr("data-gkey"));
            var tkey = $(this).attr("data-tkey");
            meta.merges[tkey] = meta.merges[tkey] || {};
            meta.merges[tkey][$(this).attr("data-from")] = $(this).prop("checked");
            refreshPreview();
        });
        $root.on("change", "[data-act='leave-text']", function () {
            metaFor($(this).attr("data-gkey")).leave[$(this).attr("data-tkey")] = $(this).prop("checked");
            refreshPreview();
        });

        $root.on("click", "[data-act='why']", function () {
            var key = $(this).attr("data-gkey");
            W.why[key] = !W.why[key];
            renderDetail();
        });
        $root.on("click", "[data-act='rwhy']", function () {
            var i = parseInt($(this).attr("data-i"), 10);
            W.why["r" + i] = !W.why["r" + i];
            render();
        });

        /* result */
        function afterUndo(message) {
            W.changed = true;
            toast(message || L("cc_saved"));
        }

        $root.on("click", "[data-act='undo-all']", function () {
            var t = computeTotals();
            confirmModal(fmt(L("cc_imp_undo_all_confirm"), nf(t.imported)), function () {
                post(urls.import_undo, {run_id: W.runId}).done(function (result) {
                    $.each(W.results || [], function (i, r) {
                        $.each(r.logs || [], function (j, l) {
                            l.undone = true;
                        });
                    });
                    afterUndo(result.message);
                }).fail(function (err) {
                    toast(err.message, "error");
                }).always(function () {
                    render();
                });
            }, L("cc_imp_undo_yes"));
        });

        $root.on("click", "[data-act='undo-sheet']", function () {
            var r = W.results && W.results[parseInt($(this).attr("data-i"), 10)];
            var l = r && r.logs && r.logs[parseInt($(this).attr("data-l"), 10)];
            if (!l) {
                return;
            }

            confirmModal(fmt(L("cc_imp_undo_confirm"), nf(l.imported)), function () {
                post(urls.import_undo, {log_id: l.log_id}).done(function (result) {
                    l.undone = true;
                    afterUndo(result.message);
                }).fail(function (err) {
                    toast(err.message, "error");
                }).always(function () {
                    render();
                });
            }, L("cc_imp_undo_yes"));
        });

        $root.on("click", "[data-act='goto']", function () {
            var r = W.results && W.results[parseInt($(this).attr("data-i"), 10)];
            if (!r) {
                return;
            }

            W.changed = false;
            modal.close();
            ctx.goTo(r.calendar_id, r.first_ym || "");
        });

        //"N items have no date": the Without date popup of that client
        $root.on("click", "[data-act='nodate']", function () {
            var r = W.results && W.results[parseInt($(this).attr("data-i"), 10)];
            if (!r) {
                return;
            }

            W.changed = false;
            modal.close();
            if (ctx.openUndated) {
                ctx.openUndated(r.calendar_id);
            } else {
                ctx.goTo(r.calendar_id, "");
            }
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
            undatedCount: 0,
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
                state.undatedCount = result.undated_count || 0;
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
                    return esc(value);
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
            //the server sends at most 6 tiles (the last one can be "Others"); never show more
            $.each((state.tiles || []).slice(0, 6), function (i, t) {
                var bar = "";
                var sub = esc(L("cc_this_month"));
                if (t.target > 0) {
                    var pct = Math.min(100, Math.round(t.count / t.target * 100));
                    sub = esc(sprintf1(L("cc_of_n_planned"), t.target));
                    bar = "<div class='cc-bar'><div class='cc-bar-fill' style='width:" + pct + "%'></div></div>";
                }

                //"Others" is a sum of several types: no target can be set on it
                var clickable = state.perms.admin_manage && !t.is_others;
                var tag = clickable ? "button type='button'" : "div";
                var close = clickable ? "button" : "div";
                html += "<" + tag + " class='cc-tile" + (clickable ? " cc-tile-click" : "") + (t.is_others ? " cc-tile-others" : "") + "' data-type='" + esc(t.label) + "'" + (clickable ? " title='" + esc(L("cc_set_target")) + "'" : "") + ">" +
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
            renderUndatedButton();
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

        function firstExtraColumnDetail(item, columns) {
            var detail = "";
            $.each(columns, function (j, col) {
                if (col.is_core || col.field_key === "date" || col.field_key === "title" || col.field_key === "status" || col.field_key === "type") {
                    return;
                }
                var v = itemValue(item, col);
                if (v === "" || v === null || v === undefined || ($.isArray(v) && !v.length) || v === false) {
                    return;
                }

                var strVal = "";
                if ($.isArray(v)) {
                    strVal = v.join(", ");
                } else if (col.input_type === "check") {
                    strVal = v ? (L("yes") || "Yes") : "";
                } else if (col.input_type === "date") {
                    strVal = fmtDate(v);
                } else {
                    strVal = String(v).trim();
                }

                if (strVal !== "") {
                    detail = col.label + ": " + strVal;
                    return false;
                }
            });
            return detail;
        }

        function showItemViewModal(item, columns) {
            var perms = state.perms;
            var dateStr = item.date ? fmtLongDate(item.date) : (L("cc_undated_no_date") || "No date yet");
            var pills = pillHtml(item.status) + (item.type ? pillHtml(item.type) : "");
            var special = (item.date && state.specialDays && state.specialDays[item.date]) ? state.specialDays[item.date] : "";

            var headHtml = "<div class='cc-view-head'>" +
                "<h3 class='cc-view-title'>" + esc(item.title) + "</h3>" +
                "<div class='cc-view-subhead'>" +
                    "<span class='cc-view-date'>" + esc(dateStr) + "</span>" +
                    (pills ? "<span class='cc-view-pills'>" + pills + "</span>" : "") +
                "</div>" +
                (special ? "<div class='cc-view-special'>" + esc(L("cc_special_day") || "Special day") + ": " + esc(special) + "</div>" : "") +
                ((!item.date && item.src_date_text) ? "<div class='cc-view-src'>" + esc(fmt(L("cc_undated_from_sheet") || "From sheet: %s", item.src_date_text)) + "</div>" : "") +
            "</div>";

            var bodyHtml = "<div class='cc-view-blocks'>";
            var blockCount = 0;
            var modalCopyValues = [];
            var cols = columns || visibleColumns();

            $.each(cols, function (i, col) {
                if (col.is_core || col.field_key === "date" || col.field_key === "title" || col.field_key === "status" || col.field_key === "type") {
                    return;
                }
                var val = itemValue(item, col);
                if (val === "" || val === null || val === undefined || ($.isArray(val) && !val.length) || val === false) {
                    return;
                }

                var isLong = (col.input_type === "long") || (typeof val === "string" && (val.indexOf("\n") !== -1 || val.length > 60));
                var valHtml = "";

                if (col.input_type === "select") {
                    valHtml = pillHtml(val);
                } else if (col.input_type === "multi" || $.isArray(val)) {
                    var itemsArr = $.isArray(val) ? val : [val];
                    var pHtml = "";
                    $.each(itemsArr, function (k, p) {
                        if (p !== "" && p !== null && p !== undefined) {
                            pHtml += pillHtml(p) + " ";
                        }
                    });
                    valHtml = pHtml;
                } else if (col.input_type === "link" || (typeof val === "string" && /^https?:\/\//i.test(val.trim()))) {
                    var linkUrl = typeof val === "string" ? val.trim() : "";
                    if (/^https?:\/\//i.test(linkUrl)) {
                        valHtml = "<a href='" + esc(linkUrl) + "' target='_blank' rel='noopener noreferrer'>" + esc(val) + "</a>";
                    } else {
                        valHtml = renderBold(esc(val));
                    }
                } else if (col.input_type === "check") {
                    valHtml = esc(L("yes") || "Yes");
                } else if (col.input_type === "date") {
                    valHtml = esc(fmtDate(val));
                } else if (col.input_type === "file") {
                    var fileName = String(val).split("/").pop();
                    valHtml = "<a href='" + esc(fileUrl(val)) + "' target='_blank' rel='noopener noreferrer'>" + esc(/^https?:\/\//i.test(val) ? val : fileName) + "</a>";
                } else {
                    valHtml = renderBold(esc(val));
                }

                var copyAttr = esc(val).replace(/\n/g, "&#10;").replace(/\r/g, "&#13;");
                bodyHtml += "<div class='cc-view-block'>" +
                    "<div class='cc-view-block-head'>" +
                        "<div class='cc-view-block-label'>" + esc(col.label) + "</div>" +
                        (isLong && typeof val === "string" ? "<button type='button' class='cc-btn cc-btn-sm cc-view-copy-btn' data-copy-idx='" + blockCount + "' data-copy-val='" + copyAttr + "'>" + esc(L("copy") || "Copy") + "</button>" : "") +
                    "</div>" +
                    "<div class='cc-view-block-value'>" + valHtml + "</div>" +
                "</div>";
                modalCopyValues[blockCount] = val;
                blockCount++;
            });

            if (!blockCount) {
                bodyHtml += "<div class='cc-muted'>" + esc(L("cc_no_additional_details") || "No additional details.") + "</div>";
            }
            bodyHtml += "</div>";

            var modal = openModal({
                title: item.title,
                titleHtml: headHtml,
                className: "cc-modal-view-item",
                body: bodyHtml
            });

            var $dialog = modal.$el;
            var $foot = modal.$foot;
            $foot.empty();
            if (perms.edit) {
                $foot.append("<a class='cc-btn cc-btn-primary cc-view-edit-btn' href='" + esc(itemFormUrl(item.id)) + "'>" + esc(L("edit")) + "</a>");
            }
            if (perms["delete"]) {
                $foot.append("<button type='button' class='cc-btn cc-btn-danger-ghost cc-view-delete-btn' data-id='" + item.id + "'>" + esc(L("delete")) + "</button>");
            }
            $foot.append("<button type='button' class='cc-btn cc-modal-close-btn'>" + esc(L("close")) + "</button>");

            $dialog.on("click", ".cc-modal-close-btn", function () {
                modal.close();
            });

            $dialog.on("click", ".cc-view-delete-btn", function () {
                var delId = $(this).data("id");
                confirmModal(L("cc_delete_item_confirm"), function () {
                    post(urls.delete_item, {id: delId, calendar_id: state.calendarId}).done(function () {
                        toast(L("cc_deleted"));
                        modal.close();
                        reloadKeepingState();
                    }).fail(function (err) {
                        toast(err.message, "error");
                    });
                });
            });

            $dialog.on("click", ".cc-view-copy-btn", function (e) {
                e.preventDefault();
                var $btn = $(this);
                var idx = $btn.data("copy-idx");
                var textToCopy = (idx !== undefined && modalCopyValues[idx] !== undefined) ? modalCopyValues[idx] : ($btn.attr("data-copy-val") || "");

                function doSuccess() {
                    var origText = $btn.text();
                    $btn.text("Copied").prop("disabled", true);
                    toast("Copied");
                    setTimeout(function () {
                        $btn.text(origText).prop("disabled", false);
                    }, 1500);
                }

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(textToCopy).then(function () {
                        doSuccess();
                    }).catch(function () {
                        fallbackCopy(textToCopy, doSuccess);
                    });
                } else {
                    fallbackCopy(textToCopy, doSuccess);
                }
            });

            function fallbackCopy(text, onSuccess) {
                var ta = document.createElement("textarea");
                ta.value = text;
                ta.style.position = "fixed";
                ta.style.opacity = "0";
                document.body.appendChild(ta);
                ta.select();
                try {
                    document.execCommand("copy");
                    onSuccess();
                } catch (e) {
                }
                document.body.removeChild(ta);
            }
        }

        function openItemViewModal(itemOrId, customColumns) {
            var item = null;
            var id = 0;
            if (typeof itemOrId === "object" && itemOrId !== null) {
                item = itemOrId;
                id = item.id;
            } else {
                id = parseInt(itemOrId, 10);
                $.each(state.items, function (i, it) {
                    if (parseInt(it.id, 10) === id) {
                        item = it;
                        return false;
                    }
                });
            }

            var columns = customColumns || state.columns;

            if (item && item.values) {
                showItemViewModal(item, columns);
            } else if (id) {
                var url = (urls && urls.item_data) ? urls.item_data : (config.base_url || "") + "index.php/content_calendar/item_data";
                post(url, {id: id, calendar_id: state.calendarId}).done(function (res) {
                    if (res && res.item) {
                        showItemViewModal(res.item, res.columns || columns);
                    } else {
                        toast(L("cc_item_not_found") || "Item not found", "error");
                    }
                }).fail(function (err) {
                    toast(err && err.message ? err.message : "Error", "error");
                });
            }
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
            if (perms.edit || special) {
                html += "<div class='cc-special'><label class='cc-label' for='cc-special-input'>" + esc(L("cc_special_day_for_date")) + "</label>";
                if (perms.edit) {
                    html += "<input type='text' id='cc-special-input' class='cc-input cc-input-full' maxlength='120' value='" + esc(special) + "' placeholder='" + esc(L("cc_special_placeholder")) + "'>";
                } else {
                    html += "<div class='cc-special-readonly'>" + esc(special) + "</div>";
                }
                html += "</div>";
            }

            if (!items.length) {
                html += "<div class='cc-empty-day'>" + esc(L("cc_nothing_planned")) + "</div>";
            }

            var columns = visibleColumns();
            $.each(items, function (i, item) {
                html += "<div class='cc-item' data-id='" + item.id + "'>";
                var pills = pillHtml(item.status) + (item.type ? pillHtml(item.type) : "");
                if (pills) {
                    html += "<div class='cc-item-pills'>" + pills + "</div>";
                }
                html += "<div class='cc-item-title'>" + esc(item.title) + "</div>";

                var detailText = firstExtraColumnDetail(item, columns);
                if (detailText !== "") {
                    html += "<div class='cc-item-detail'>" + esc(detailText) + "</div>";
                }

                html += "<div class='cc-item-actions'>";
                html += "<button type='button' class='cc-btn cc-btn-sm cc-item-view' data-id='" + item.id + "'>" + esc(L("cc_view") || (L("view") ? (L("view").charAt(0).toUpperCase() + L("view").slice(1)) : "View")) + "</button>";
                if (perms.edit) {
                    html += "<a class='cc-btn cc-btn-sm' href='" + esc(itemFormUrl(item.id)) + "'>" + esc(L("edit")) + "</a>";
                }
                if (perms["delete"]) {
                    html += "<button type='button' class='cc-btn cc-btn-sm cc-btn-danger-ghost cc-item-delete' data-id='" + item.id + "'>" + esc(L("delete")) + "</button>";
                }
                html += "</div>";

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

        /* ---------- Without date (content that has no publish date yet) ---------- */

        function renderUndatedButton() {
            var n = parseInt(state.undatedCount, 10) || 0;
            $("#cc-undated-open").text(fmt(L("cc_without_date_n"), nf(n))).toggleClass("has-undated", n > 0);
        }

        function setUndatedCount(n) {
            state.undatedCount = Math.max(0, parseInt(n, 10) || 0);
            renderUndatedButton();
        }

        //jump to the month and day of a date (the month exists: the server adds it when a date is assigned)
        function goToDate(date) {
            var p = parseYmd(date);
            if (!p.y || !p.m) {
                return;
            }

            state.view = "month";
            state.selectedDate = date;
            load({year: p.y, month: p.m}, true);
        }

        function undatedModal() {
            var calendarId = state.calendarId;
            var calendar = currentCalendar();
            var canEdit = !!state.perms.edit;
            var canDelete = !!state.perms["delete"];
            var us = {items: [], total: 0, q: "", loading: false, rid: 0, selected: {}, timer: null};
            var modal;

            function selectedIds() {
                return $.map(us.selected, function (on, id) {
                    return on ? parseInt(id, 10) : null;
                });
            }

            function cardHtml(item) {
                var h = "<div class='cc-item cc-undated-card' data-id='" + item.id + "'>";
                if (canEdit) {
                    h += "<label class='cc-undated-pick'><input type='checkbox' class='cc-undated-check' aria-label='" + esc(L("cc_undated_select")) + "'" + (us.selected[item.id] ? " checked" : "") + "></label>";
                }

                h += "<div class='cc-undated-main'>";
                var pills = pillHtml(item.type) + pillHtml(item.status);
                if (pills) {
                    h += "<div class='cc-item-pills'>" + pills + "</div>";
                }

                h += "<div class='cc-item-title'>" + esc(item.title) + "</div>";

                if (item.src_date_text) {
                    h += "<div class='cc-undated-src'>" + esc(fmt(L("cc_undated_from_sheet"), item.src_date_text)) + "</div>";
                }

                var rows = "";
                $.each(visibleColumns(), function (j, col) {
                    if (col.is_core) {
                        return;
                    }
                    var v = valueHtml(col, itemValue(item, col));
                    if (v !== "") {
                        var displayV = (col.input_type === "long" || col.input_type === "text" || !col.input_type) ? renderBold(v) : v;
                        rows += "<div class='cc-kv-row'><dt>" + esc(col.label) + "</dt><dd>" + displayV + "</dd></div>";
                    }
                });
                if (rows) {
                    h += "<dl class='cc-kv'>" + rows + "</dl>";
                }

                if (canEdit) {
                    h += "<div class='cc-undated-assign'><input type='date' class='cc-input cc-undated-date' aria-label='" + esc(L("cc_assign_date")) + "'>" +
                            "<button type='button' class='cc-btn cc-btn-primary cc-btn-sm cc-undated-assign-btn' disabled>" + esc(L("cc_assign_date")) + "</button></div>";
                }

                h += "<div class='cc-item-actions'>";
                h += "<button type='button' class='cc-btn cc-btn-sm cc-item-view' data-id='" + item.id + "'>" + esc(L("cc_view") || (L("view") ? (L("view").charAt(0).toUpperCase() + L("view").slice(1)) : "View")) + "</button>";
                if (canEdit) {
                    h += "<a class='cc-btn cc-btn-sm' href='" + esc(itemFormUrl(item.id)) + "'>" + esc(L("edit")) + "</a>";
                }
                if (canDelete) {
                    h += "<button type='button' class='cc-btn cc-btn-sm cc-btn-danger-ghost cc-undated-delete'>" + esc(L("delete")) + "</button>";
                }
                h += "</div>";

                return h + "</div></div>";
            }

            //counter, empty state, Show more, bulk bar
            function refreshMeta() {
                var $el = modal.$el;
                $el.find(".cc-undated-counter").text(fmt(L("cc_undated_showing"), nf(us.items.length), nf(us.total)));

                $el.find(".cc-undated-empty").remove();
                if (!us.items.length && !us.loading) {
                    $el.find(".cc-undated-list").html("<div class='cc-empty-day cc-undated-empty'>" + esc(us.q ? L("cc_undated_empty_search") : L("cc_undated_empty")) + "</div>");
                }

                $el.find(".cc-undated-more").toggleClass("hide", us.items.length >= us.total).find(".cc-btn").prop("disabled", us.loading);

                var ids = selectedIds();
                $el.find(".cc-undated-bulk").toggleClass("hide", !canEdit || !us.items.length);
                $el.find(".cc-undated-selected").text(fmt(L("cc_undated_selected_n"), ids.length));
                $el.find(".cc-undated-all").prop("checked", us.items.length > 0 && ids.length === us.items.length);
                $el.find(".cc-undated-bulk-btn").prop("disabled", !ids.length || !$.trim($el.find(".cc-undated-bulk-date").val()));
            }

            function fetchItems(reset) {
                var rid = ++us.rid;
                var offset = reset ? 0 : us.items.length;
                us.loading = true;
                modal.$el.find(".cc-undated-more .cc-btn").prop("disabled", true);

                post(urls.undated_list, {calendar_id: calendarId, q: us.q, offset: offset}).done(function (r) {
                    if (rid !== us.rid) {
                        return;
                    }

                    $.extend(state.colors, r.colors || {});
                    var $list = modal.$el.find(".cc-undated-list");
                    if (reset) {
                        us.items = [];
                        us.selected = {};
                        $list.empty();
                    }

                    $list.find(".cc-undated-empty").remove();
                    var html = "";
                    $.each(r.items || [], function (i, item) {
                        us.items.push(item);
                        html += cardHtml(item);
                    });
                    $list.append(html);
                    us.total = r.total;
                    if (!us.q) {
                        setUndatedCount(r.undated_count);
                    }
                }).fail(function (err) {
                    if (rid === us.rid) {
                        toast(err.message, "error");
                    }
                }).always(function () {
                    if (rid === us.rid) {
                        us.loading = false;
                        refreshMeta();
                    }
                });
            }

            function removeCards(ids) {
                $.each(ids, function (i, id) {
                    modal.$el.find(".cc-undated-card[data-id='" + id + "']").remove();
                    delete us.selected[id];
                });

                us.items = $.grep(us.items, function (item) {
                    return $.inArray(item.id, ids) === -1;
                });
            }

            function assign(ids, date, bulk, $btn) {
                if (!ids.length || !date) {
                    toast(L("cc_pick_a_date"), "error");
                    return;
                }

                $btn.prop("disabled", true);
                var data = bulk ? {calendar_id: calendarId, ids: ids, publish_date: date} : {calendar_id: calendarId, item_id: ids[0], publish_date: date};
                post(bulk ? urls.undated_assign_bulk : urls.undated_assign, data).done(function (r) {
                    var assigned = $.map(r.assigned || [], function (id) {
                        return parseInt(id, 10);
                    });

                    removeCards(assigned);
                    us.total = Math.max(0, us.total - assigned.length);
                    setUndatedCount(r.undated_count);

                    if (assigned.length) {
                        var message = assigned.length > 1 ? fmt(L("cc_undated_moved_n"), assigned.length, fmtDate(date)) : fmt(L("cc_undated_moved"), fmtDate(date));
                        toastAction(message, L("cc_go_to_date"), function () {
                            modal.close();
                            goToDate(date);
                        });
                        reloadKeepingState();
                    } else {
                        toast(r.message || L("cc_undated_none_moved"), "error");
                    }

                    //everything on screen was moved but more is waiting: load the next page
                    if (!us.items.length && us.total > 0) {
                        fetchItems(true);
                    } else {
                        refreshMeta();
                    }
                }).fail(function (err) {
                    $btn.prop("disabled", false);
                    toast(err.message, "error");
                });
            }

            var body = "<p class='cc-undated-sub'>" + esc(calendar ? calendar.name : "") + "</p>" +
                    "<div class='cc-undated-tools'><input type='search' class='cc-input cc-undated-search' maxlength='120' placeholder='" + esc(L("cc_undated_search")) + "' aria-label='" + esc(L("cc_undated_search")) + "'>" +
                    "<span class='cc-undated-counter' aria-live='polite'></span></div>" +
                    (canEdit ? "<div class='cc-undated-bulk hide'><label class='cc-check'><input type='checkbox' class='cc-undated-all'> <span>" + esc(L("cc_undated_select_all")) + "</span></label>" +
                            "<span class='cc-muted cc-undated-selected'></span>" +
                            "<input type='date' class='cc-input cc-undated-bulk-date' aria-label='" + esc(L("cc_assign_date")) + "'>" +
                            "<button type='button' class='cc-btn cc-btn-primary cc-btn-sm cc-undated-bulk-btn' disabled>" + esc(L("cc_undated_assign_selected")) + "</button></div>" : "") +
                    "<div class='cc-undated-list'></div>" +
                    "<div class='cc-undated-more hide'><button type='button' class='cc-btn'>" + esc(L("cc_show_more")) + "</button></div>";

            modal = openModal({
                title: L("cc_undated_title"),
                className: "cc-modal-undated",
                body: body,
                focus: ".cc-undated-search",
                buttons: [{label: L("close"), className: ""}],
                onOpen: function (m) {
                    //openModal calls onOpen before returning, so assign modal first
                    modal = m;
                    var $el = m.$el;

                    $el.on("input", ".cc-undated-search", function () {
                        var q = $.trim($(this).val());
                        clearTimeout(us.timer);
                        us.timer = setTimeout(function () {
                            if (q !== us.q) {
                                us.q = q;
                                fetchItems(true);
                            }
                        }, 300);
                    });

                    $el.on("click", ".cc-undated-more .cc-btn", function () {
                        fetchItems(false);
                    });

                    //per card: the Assign button waits for a date
                    $el.on("input change", ".cc-undated-date", function () {
                        $(this).closest(".cc-undated-assign").find(".cc-undated-assign-btn").prop("disabled", !$(this).val());
                    });

                    $el.on("keydown", ".cc-undated-date, .cc-undated-bulk-date", function (e) {
                        if (e.key === "Enter") {
                            e.preventDefault();
                            $(this).siblings(".cc-btn-primary").not(":disabled").trigger("click");
                        }
                    });

                    $el.on("click", ".cc-undated-assign-btn", function () {
                        var $card = $(this).closest(".cc-undated-card");
                        assign([parseInt($card.data("id"), 10)], $card.find(".cc-undated-date").val(), false, $(this));
                    });

                    $el.on("change", ".cc-undated-check", function () {
                        var id = parseInt($(this).closest(".cc-undated-card").data("id"), 10);
                        if (this.checked) {
                            us.selected[id] = true;
                        } else {
                            delete us.selected[id];
                        }
                        refreshMeta();
                    });

                    $el.on("change", ".cc-undated-all", function () {
                        var on = this.checked;
                        us.selected = {};
                        $.each(us.items, function (i, item) {
                            if (on) {
                                us.selected[item.id] = true;
                            }
                        });
                        $el.find(".cc-undated-check").prop("checked", on);
                        refreshMeta();
                    });

                    $el.on("input change", ".cc-undated-bulk-date", refreshMeta);

                    $el.on("click", ".cc-undated-bulk-btn", function () {
                        assign(selectedIds(), $el.find(".cc-undated-bulk-date").val(), true, $(this));
                    });

                    $el.on("click", ".cc-item-view", function () {
                        var id = parseInt($(this).data("id"), 10);
                        var found = null;
                        $.each(us.items, function (i, it) {
                            if (parseInt(it.id, 10) === id) {
                                found = it;
                                return false;
                            }
                        });
                        if (found) {
                            openItemViewModal(found, visibleColumns());
                        } else {
                            openItemViewModal(id);
                        }
                    });

                    $el.on("click", ".cc-undated-delete", function () {
                        var id = parseInt($(this).closest(".cc-undated-card").data("id"), 10);
                        confirmModal(L("cc_delete_item_confirm"), function () {
                            post(urls.delete_item, {id: id, calendar_id: calendarId}).done(function () {
                                toast(L("cc_deleted"));
                                removeCards([id]);
                                us.total = Math.max(0, us.total - 1);
                                setUndatedCount(state.undatedCount - 1);
                                if (!us.items.length && us.total > 0) {
                                    fetchItems(true);
                                } else {
                                    refreshMeta();
                                }
                                reloadKeepingState();
                            }).fail(function (err) {
                                toast(err.message, "error");
                            });
                        });
                    });

                    fetchItems(true);
                },
                onClose: function () {
                    clearTimeout(us.timer);
                    us.rid++;
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
                openUndated: function (calendarId) {
                    state.calendarId = calendarId;
                    state.filter = {type: "", goal: "", status: ""};
                    state.selectedDate = "";
                    load({calendar_id: calendarId, year: 0, month: 0}, false).done(function () {
                        if (state.loaded && state.calendarId) {
                            undatedModal();
                        }
                    });
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

        $("#cc-undated-open").on("click", function () {
            if (state.loaded && state.calendarId) {
                undatedModal();
            }
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

        $app.on("click", ".cc-item-view", function () {
            var id = parseInt($(this).data("id"), 10);
            openItemViewModal(id);
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
            var date = $("#cc-no-date").is(":checked") ? "" : currentValue("date");
            var type = currentValue("type");
            var title = currentValue("title");
            $("#cc-preview-date").text(date ? fmtDate(date) : "");
            var text = (type ? type + ": " : "") + (title || L("cc_sample_title"));
            $("#cc-preview-chip").attr("style", chipStyle(colors, type)).text(text);
        }

        $form.on("input change", "input, select, textarea", updatePreview);

        //"No date yet": the date field is disabled (so it is not sent) while the box is ticked
        function applyNoDate() {
            var on = $("#cc-no-date").is(":checked");
            var $date = $form.find("[name='f[date]']");
            $date.prop("disabled", on);
            if (on) {
                fieldError("date", "");
            }
            updatePreview();
        }

        $form.on("change", "#cc-no-date", applyNoDate);
        applyNoDate();

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
