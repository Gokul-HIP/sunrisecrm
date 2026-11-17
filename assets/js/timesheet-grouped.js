/**
 * Phase 22 — Timesheets Details grouped view: Member → Project → Task → entries.
 * Excel/Print still POST live toolbar filters to timesheet_grouped_export.
 */
(function (window, $) {
    "use strict";

    function esc(s) {
        return $("<div>").text(s == null ? "" : String(s)).html();
    }

    function convertDateToYMD(date) {
        if (!date) {
            return "";
        }
        if (/^\d{4}-\d{2}-\d{2}$/.test(date)) {
            return date;
        }
        if (window.moment && typeof getJsDateFormat === "function") {
            var momentFmt = getJsDateFormat().toUpperCase();
            var parsed = moment(date, momentFmt, true);
            if (parsed.isValid()) {
                return parsed.format("YYYY-MM-DD");
            }
        }
        var dateFormat = (window.AppHelper && AppHelper.settings && AppHelper.settings.dateFormat) ? AppHelper.settings.dateFormat : "Y-m-d";
        dateFormat = String(dateFormat).toLowerCase();
        var separator = dateFormat.charAt(1);
        var dateFormatArray = dateFormat.split(separator);
        var yearIndex = 0, monthIndex = 1, dayIndex = 2;
        if (dateFormatArray[1] === "y") yearIndex = 1;
        else if (dateFormatArray[2] === "y") yearIndex = 2;
        if (dateFormatArray[0] === "m") monthIndex = 0;
        else if (dateFormatArray[2] === "m") monthIndex = 2;
        if (dateFormatArray[0] === "d") dayIndex = 0;
        else if (dateFormatArray[1] === "d") dayIndex = 1;
        var dateValue = String(date).split(separator);
        if (dateValue.length < 3) {
            dateValue = String(date).split(/[.\-/]/);
            if (dateValue.length === 3 && dateValue[2].length === 4) {
                return dateValue[2] + "-" + dateValue[1] + "-" + dateValue[0];
            }
            return date;
        }
        return dateValue[yearIndex] + "-" + dateValue[monthIndex] + "-" + dateValue[dayIndex];
    }

    function TimesheetGrouped(opts) {
        this.opts = opts || {};
        this.$root = $(opts.root);
        this.$list = this.$root.find("[data-tg-list]");
        this.$caption = this.$root.find("[data-tg-caption]");
        this.$loading = this.$root.find("[data-tg-loading]");
        this.$empty = this.$root.find("[data-tg-empty]");
        this.mode = "grouped";
        this.cache = { projects: {}, tasks: {}, entries: {} };
        this.bind();
    }

    TimesheetGrouped.prototype.bind = function () {
        var self = this;
        this.$root.on("click", "[data-tg-mode]", function () {
            self.setMode($(this).data("tg-mode"));
        });
        this.$root.on("click", "[data-tg-expand-all]", function () {
            self.expandAllVisible();
        });
        this.$root.on("click", "[data-tg-collapse-all]", function () {
            self.collapseAll();
        });
        this.$root.on("click", "[data-tg-filters-toggle]", function () {
            self.$root.find("[data-tg-filters]").toggleClass("is-collapsed");
        });
        this.$root.on("click", "[data-tg-member-toggle]", function (e) {
            e.preventDefault();
            self.toggleMember($(this).closest("[data-member-id]").first());
        });
        this.$root.on("click", "[data-tg-project-toggle]", function (e) {
            e.preventDefault();
            self.toggleProject($(this).closest(".tg-project").first());
        });
        this.$root.on("keydown", "[data-tg-member-toggle],[data-tg-project-toggle],[data-tg-entries-toggle]", function (e) {
            if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                $(this).trigger("click");
            }
        });
        this.$root.on("click", "[data-tg-entries-toggle]", function (e) {
            e.preventDefault();
            self.toggleEntries($(this));
        });
        this.$root.on("click", "[data-tg-apply]", function () {
            self.reload();
        });
        this.$root.on("keydown", "[name='tg_search']", function (e) {
            if (e.key === "Enter") {
                e.preventDefault();
                self.reload();
            }
        });
        this.$root.on("click", "[data-tg-export]", function () {
            self.exportFile($(this).data("tg-export") || "xlsx");
        });
    };

    TimesheetGrouped.prototype.exportFile = function (format) {
        var url = this.opts.exportUrl;
        if (!url) {
            return;
        }
        var filters = this.filters();
        var $form = $("<form>").attr({
            method: "POST",
            action: url,
            target: format === "print" ? "_blank" : "_self"
        }).css("display", "none");
        var fields = $.extend({}, filters, { format: format || "xlsx" });
        if (window.AppHelper && AppHelper.csrfTokenName && AppHelper.csrfHash) {
            fields[AppHelper.csrfTokenName] = AppHelper.csrfHash;
        }
        $.each(fields, function (k, v) {
            $form.append($("<input>").attr({ type: "hidden", name: k, value: v == null ? "" : v }));
        });
        $("body").append($form);
        $form.trigger("submit");
        setTimeout(function () { $form.remove(); }, 2000);
    };

    TimesheetGrouped.prototype.setMode = function (mode) {
        this.mode = mode === "table" ? "table" : "grouped";
        this.$root.find("[data-tg-mode]").removeClass("is-active").attr("aria-pressed", "false");
        this.$root.find('[data-tg-mode="' + this.mode + '"]').addClass("is-active").attr("aria-pressed", "true");
        if (this.mode === "grouped") {
            $("#timesheet-grouped-pane").removeClass("is-hidden");
            $("#timesheet-table-pane").addClass("is-hidden");
            this.$caption.removeClass("is-hidden");
            this.$root.find("[data-tg-grouped-only]").show();
        } else {
            $("#timesheet-grouped-pane").addClass("is-hidden");
            $("#timesheet-table-pane").removeClass("is-hidden");
            this.$caption.addClass("is-hidden");
            this.$root.find("[data-tg-grouped-only]").hide();
            if ($.fn.DataTable && $.fn.DataTable.isDataTable("#all-project-timesheet-table")) {
                $("#all-project-timesheet-table").DataTable().columns.adjust();
            }
        }
    };

    TimesheetGrouped.prototype.filters = function () {
        return {
            start_date: convertDateToYMD(this.$root.find("[name='tg_start_date']").val() || ""),
            end_date: convertDateToYMD(this.$root.find("[name='tg_end_date']").val() || ""),
            project_id: this.$root.find("[name='tg_project_id']").val() || "",
            client_id: this.$root.find("[name='tg_client_id']").val() || "",
            user_id: this.$root.find("[name='tg_user_id']").val() || "",
            search: this.$root.find("[name='tg_search']").val() || ""
        };
    };

    TimesheetGrouped.prototype.updateCaption = function () {
        var f = this.filters();
        var from = this.$root.find("[name='tg_start_date']").val() || f.start_date || "—";
        var to = this.$root.find("[name='tg_end_date']").val() || f.end_date || "—";
        var tpl = this.opts.captionTemplate || "Tasks created between {from} and {to}. Time shows all time logged on these tasks.";
        this.$caption.text(tpl.replace("{from}", from).replace("{to}", to));
    };

    TimesheetGrouped.prototype.reload = function () {
        var self = this;
        this.cache = { projects: {}, tasks: {}, entries: {} };
        this.updateCaption();
        this.$list.empty();
        this.$empty.hide();
        this.$loading.show();
        var filters = this.filters();
        if (typeof this.opts.onFiltersApplied === "function") {
            this.opts.onFiltersApplied(filters);
        }
        $.ajax({
            url: this.opts.membersRootUrl || this.opts.projectsUrl,
            type: "POST",
            dataType: "json",
            data: filters,
            success: function (res) {
                self.$loading.hide();
                if (!res || !res.success) {
                    self.$empty.show().text((res && res.message) || self.opts.errorMessage || "Something went wrong. Please try again.");
                    return;
                }
                if (!res.data || !res.data.length) {
                    self.$empty.show().text(self.opts.emptyMessage || "No tasks created in this range");
                    return;
                }
                self.$empty.hide();
                res.data.forEach(function (m) {
                    self.$list.append(self.renderMemberRoot(m));
                });
                if (window.feather) {
                    feather.replace();
                }
            },
            error: function (xhr) {
                self.$loading.hide();
                var msg = self.opts.errorMessage || "Something went wrong. Please try again.";
                if (xhr && xhr.status) {
                    msg += " (" + xhr.status + ")";
                }
                self.$empty.show().text(msg);
            }
        });
    };

    TimesheetGrouped.prototype.renderMemberRoot = function (m) {
        var uid = m.user_id;
        var avatar = m.avatar
            ? '<img class="tg-avatar tg-avatar-lg" src="' + esc(m.avatar) + '" alt="">'
            : '<span class="tg-avatar tg-avatar-lg" style="background:#ddd;display:inline-block;"></span>';
        return ''
            + '<article class="tg-member tg-member-root" data-member-id="' + uid + '">'
            + '  <button type="button" class="tg-row tg-row-member" data-tg-member-toggle aria-expanded="false" aria-controls="tg-mem-panel-' + uid + '" id="tg-mem-btn-' + uid + '">'
            + '    <span class="tg-chevron" aria-hidden="true"><i data-feather="chevron-right" class="icon-16"></i></span>'
            + avatar
            + '    <span class="tg-title">' + esc(String(m.name || "").trim()) + '</span>'
            + '    <span class="tg-meta">'
            + '      <span class="tg-chip" data-chip="projects">' + esc(m.project_count) + ' projects</span>'
            + '      <span class="tg-chip" data-chip="tasks">' + esc(m.task_count) + ' tasks</span>'
            + '    </span>'
            + '    <span class="tg-nums">'
            + '      <span>' + esc(m.total_time) + '</span>'
            + '      <span class="tg-muted">' + esc(m.points_earned_label) + ' pts</span>'
            + '    </span>'
            + '  </button>'
            + '  <div class="tg-panel" id="tg-mem-panel-' + uid + '" role="region" hidden></div>'
            + '</article>';
    };

    TimesheetGrouped.prototype.toggleMember = function ($mem) {
        var self = this;
        var $btn = $mem.find("[data-tg-member-toggle]").first();
        var $panel = $mem.find("> .tg-panel");
        var open = $btn.attr("aria-expanded") === "true";
        if (open) {
            $btn.attr("aria-expanded", "false");
            $panel.removeClass("is-open").attr("hidden", true);
            $mem.removeClass("is-open");
            return;
        }
        $btn.attr("aria-expanded", "true");
        $panel.addClass("is-open").removeAttr("hidden");
        $mem.addClass("is-open");
        var uid = $mem.data("member-id");
        if (this.cache.projects[uid] !== undefined) {
            $panel.html(this.cache.projects[uid]);
            if (window.feather) feather.replace();
            return;
        }
        $panel.html('<div class="tg-loading"><div class="spinner-border"></div></div>');
        var data = this.filters();
        data.user_id = uid;
        $.ajax({
            url: this.opts.memberProjectsUrl || this.opts.membersUrl,
            type: "POST",
            dataType: "json",
            data: data,
            success: function (res) {
                if (!res || !res.success) {
                    $panel.html('<div class="tg-empty">Unable to load projects</div>');
                    return;
                }
                if (typeof res.project_count !== "undefined") {
                    $mem.find('[data-chip="projects"]').text(res.project_count + " projects");
                }
                if (typeof res.task_count !== "undefined") {
                    $mem.find('[data-chip="tasks"]').text(res.task_count + " tasks");
                }
                var html = "";
                (res.data || []).forEach(function (p) {
                    html += self.renderProjectUnderMember(uid, p);
                });
                if (!html) {
                    html = '<div class="tg-empty">No projects</div>';
                }
                self.cache.projects[uid] = html;
                $panel.html(html);
                if (window.feather) feather.replace();
            },
            error: function () {
                $panel.html('<div class="tg-empty">Unable to load projects</div>');
            }
        });
    };

    TimesheetGrouped.prototype.renderProjectUnderMember = function (memberId, p) {
        var pid = p.project_id;
        return ''
            + '<div class="tg-project tg-project-nested" data-project-id="' + pid + '" data-member-id="' + memberId + '">'
            + '  <button type="button" class="tg-row tg-row-project" data-tg-project-toggle aria-expanded="false" aria-controls="tg-proj-panel-' + memberId + '-' + pid + '">'
            + '    <span class="tg-chevron" aria-hidden="true"><i data-feather="chevron-right" class="icon-16"></i></span>'
            + '    <span class="tg-title">' + esc(String(p.project_title || "").trim())
            + (p.client_name ? '<span class="tg-sub">' + esc(String(p.client_name).trim()) + '</span>' : '')
            + '</span>'
            + '    <span class="tg-meta"><span class="tg-chip" data-chip="tasks">' + esc(p.task_count) + ' tasks</span></span>'
            + '    <span class="tg-nums">'
            + '      <span>' + esc(p.total_time) + '</span>'
            + '      <span class="tg-muted">' + esc(p.points_earned_label) + ' pts</span>'
            + '    </span>'
            + '  </button>'
            + '  <div class="tg-panel" id="tg-proj-panel-' + memberId + '-' + pid + '" role="region" hidden></div>'
            + '</div>';
    };

    TimesheetGrouped.prototype.toggleProject = function ($proj) {
        var self = this;
        var $btn = $proj.find("[data-tg-project-toggle]").first();
        var $panel = $proj.find("> .tg-panel");
        var open = $btn.attr("aria-expanded") === "true";
        if (open) {
            $btn.attr("aria-expanded", "false");
            $panel.removeClass("is-open").attr("hidden", true);
            $proj.removeClass("is-open");
            return;
        }
        $btn.attr("aria-expanded", "true");
        $panel.addClass("is-open").removeAttr("hidden");
        $proj.addClass("is-open");
        var pid = $proj.data("project-id");
        var uid = $proj.data("member-id");
        var key = uid + ":" + pid;
        if (this.cache.tasks[key]) {
            $panel.html(this.cache.tasks[key]);
            if (window.feather) feather.replace();
            return;
        }
        $panel.html('<div class="tg-loading"><div class="spinner-border"></div></div>');
        var data = this.filters();
        data.project_id = pid;
        data.user_id = uid;
        $.ajax({
            url: this.opts.tasksUrl,
            type: "POST",
            dataType: "json",
            data: data,
            success: function (res) {
                if (!res || !res.success) {
                    $panel.html('<div class="tg-empty">Unable to load tasks</div>');
                    return;
                }
                var html = "";
                (res.data || []).forEach(function (t) {
                    html += self.renderTask(pid, uid, t);
                });
                if (!html) {
                    html = '<div class="tg-empty">No tasks</div>';
                }
                $proj.find('[data-chip="tasks"]').first().text((res.data || []).length + " tasks");
                self.cache.tasks[key] = html;
                $panel.html(html);
                if (window.feather) feather.replace();
            },
            error: function () {
                $panel.html('<div class="tg-empty">Unable to load tasks</div>');
            }
        });
    };

    TimesheetGrouped.prototype.renderTask = function (projectId, userId, t) {
        var overdue = t.deadline_overdue ? " is-overdue" : "";
        var openTask = '<a href="javascript:;" class="tg-link-btn" title="Open task"'
            + ' data-post-id="' + t.task_id + '"'
            + ' data-act="ajax-modal"'
            + ' data-title="Task #' + t.task_id + '"'
            + ' data-action-url="' + esc(this.opts.taskViewUrl) + '"'
            + ' data-modal-lg="1">Open task</a>';

        return ''
            + '<div class="tg-task" data-task-id="' + t.task_id + '" data-project-id="' + projectId + '" data-member-id="' + userId + '">'
            + '  <div class="tg-task-grid">'
            + '    <div><div class="tg-label">Task</div><div class="tg-task-title">' + esc(String(t.title || "").trim()) + '</div></div>'
            + '    <div><div class="tg-label">Score</div><div class="tg-score">' + (t.score_html || "-") + '</div></div>'
            + '    <div><div class="tg-label">Start</div>' + esc(t.start_date) + '</div>'
            + '    <div><div class="tg-label">Deadline</div><span class="tg-deadline' + overdue + '">' + (t.deadline || "-") + '</span></div>'
            + '    <div><div class="tg-label">Collaborators</div><div class="tg-avatar-stack">' + (t.collaborators_html || "-") + '</div></div>'
            + '    <div><div class="tg-label">Created</div>' + esc(t.created_at) + '</div>'
            + '    <div><div class="tg-label">Archived</div><span class="tg-nums">' + esc(t.archived_score) + '</span></div>'
            + '    <div><div class="tg-label">Time</div><span class="tg-nums">' + esc(t.total_time) + '</span></div>'
            + '    <div class="tg-task-actions">'
            + openTask
            + '      <button type="button" class="tg-link-btn" data-tg-entries-toggle aria-expanded="false">Time entries (' + esc(t.entry_count) + ')</button>'
            + '    </div>'
            + '  </div>'
            + '  <div class="tg-entries" hidden></div>'
            + '</div>';
    };

    TimesheetGrouped.prototype.toggleEntries = function ($btn) {
        var self = this;
        var $task = $btn.closest("[data-task-id]");
        var $box = $task.find("> .tg-entries");
        var open = $btn.attr("aria-expanded") === "true";
        if (open) {
            $btn.attr("aria-expanded", "false");
            $box.attr("hidden", true).empty();
            return;
        }
        $btn.attr("aria-expanded", "true");
        $box.removeAttr("hidden");
        var pid = $task.data("project-id");
        var uid = $task.data("member-id");
        var tid = $task.data("task-id");
        var key = pid + ":" + uid + ":" + tid;
        if (this.cache.entries[key]) {
            $box.html(this.cache.entries[key]);
            if (window.feather) feather.replace();
            return;
        }
        $box.html('<div class="tg-loading"><div class="spinner-border"></div></div>');
        $.ajax({
            url: this.opts.entriesUrl,
            type: "POST",
            dataType: "json",
            data: {
                project_id: pid,
                user_id: uid,
                task_id: tid
            },
            success: function (res) {
                if (!res || !res.success) {
                    $box.html('<div class="tg-empty">Unable to load entries</div>');
                    return;
                }
                var html = self.renderEntries(res.data || []);
                self.cache.entries[key] = html;
                $box.html(html);
                if (window.feather) feather.replace();
            },
            error: function () {
                $box.html('<div class="tg-empty">Unable to load entries</div>');
            }
        });
    };

    TimesheetGrouped.prototype.renderEntries = function (rows) {
        if (!rows.length) {
            return '<div class="tg-empty">No time entries</div>';
        }
        var html = '<table class="tg-entries-table"><thead><tr>'
            + '<th>Date</th><th>End time</th><th class="tg-num">Total</th><th>Note</th><th></th>'
            + '</tr></thead><tbody>';
        rows.forEach(function (r) {
            html += '<tr>'
                + '<td data-label="Date">' + esc(r.date) + '</td>'
                + '<td data-label="End time">' + esc(r.end_time) + '</td>'
                + '<td class="tg-num" data-label="Total">' + esc(r.total) + '</td>'
                + '<td data-label="Note">' + esc(r.note) + '</td>'
                + '<td data-label="">' + (r.options_html || "") + '</td>'
                + '</tr>';
        });
        html += '</tbody></table>';
        return html;
    };

    // Expand only currently visible level: members, or projects of open members — never auto-load all tasks
    TimesheetGrouped.prototype.expandAllVisible = function () {
        var self = this;
        var $openMembers = this.$list.find(".tg-member-root.is-open");
        if ($openMembers.length) {
            $openMembers.find(".tg-project-nested").each(function () {
                var $p = $(this);
                if ($p.find("[data-tg-project-toggle]").attr("aria-expanded") !== "true") {
                    self.toggleProject($p);
                }
            });
            return;
        }
        this.$list.find(".tg-member-root").each(function () {
            var $m = $(this);
            if ($m.find("[data-tg-member-toggle]").attr("aria-expanded") !== "true") {
                self.toggleMember($m);
            }
        });
    };

    TimesheetGrouped.prototype.collapseAll = function () {
        this.$list.find("[aria-expanded='true']").each(function () {
            var $el = $(this);
            $el.attr("aria-expanded", "false");
            $el.closest(".tg-member-root, .tg-project-nested, .tg-task").find("> .tg-panel, > .tg-entries").removeClass("is-open").attr("hidden", true).filter(".tg-entries").empty();
            $el.closest(".tg-member-root, .tg-project-nested").removeClass("is-open");
        });
    };

    window.TimesheetGrouped = TimesheetGrouped;
})(window, jQuery);
