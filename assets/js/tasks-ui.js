(function (window, $) {
    "use strict";

    if (!$) {
        return;
    }

    var COL = {
        id: 0,
        title: 1,
        score: 2,
        start: 3,
        deadline: 4,
        project: 5,
        assigned: 6,
        collab: 7,
        status: 8,
        created: 9
    };

    var DEFAULT_AVATAR = /avatar\.jpg|avatar-bot|bitbucket_logo|github_logo/i;

    var isTopTools = function ($tools) {
        return $tools.length
            && $tools.find(".custom-toolbar, .dataTables_length, .datatable-export").length
            && !$tools.find(".dataTables_paginate, .dataTables_info").length;
    };

    var isPagerTools = function ($tools) {
        return $tools.length && $tools.find(".dataTables_paginate, .dataTables_info").length;
    };

    var restorePagination = function ($root) {
        var $table = $root.find("#task-table");
        var $wrapper = $table.closest(".dataTables_wrapper");
        if (!$wrapper.length) {
            return;
        }

        $root.find("#tasks-filter-card .datatable-tools").each(function () {
            var $tools = $(this);
            if (isPagerTools($tools)) {
                $wrapper.append($tools);
            }
        });

        $wrapper.children(".datatable-tools").filter(function () {
            return isPagerTools($(this));
        }).addClass("tasks-table-footer");
    };

    var clearTheadSticky = function ($root) {
        $root = $root || $(".tasks-ui").first();
        $root.find("#task-table thead th, .dataTables_scrollHead thead th").css({
            position: "static",
            top: "auto",
            zIndex: "",
            visibility: "visible",
            height: "auto"
        });
    };

    var adjustTaskTable = function () {
        try {
            var table = $("#task-table").DataTable();
            if (table && table.columns) {
                table.columns.adjust();
            }
            if (table && table.fixedHeader && typeof table.fixedHeader.adjust === "function") {
                table.fixedHeader.adjust();
            }
        } catch (e) {
        }
        $("#task-table, .dataTables_scrollHeadInner, .dataTables_scrollHeadInner table").css({
            width: "100%",
            minWidth: "0"
        });
    };

    var COL_CLASS_MAP = [
        { match: /c-id|tasks-col-id/, add: "c-id c-chk tasks-col-id" },
        { match: /c-title|tasks-col-title/, add: "c-title tasks-col-title" },
        { match: /c-score|tasks-col-score/, add: "c-score tasks-col-score" },
        { match: /c-start|tasks-col-start/, add: "c-start tasks-col-start" },
        { match: /c-deadline|tasks-col-deadline/, add: "c-deadline tasks-col-deadline" },
        { match: /c-milestone|tasks-col-milestone/, add: "c-milestone tasks-col-milestone" },
        { match: /c-project|tasks-col-project/, add: "c-project tasks-col-project" },
        { match: /c-assignee|tasks-col-assigned/, add: "c-assignee tasks-col-assigned" },
        { match: /c-collab|tasks-col-collab/, add: "c-collab tasks-col-collab tasks-col-muted" },
        { match: /c-status|tasks-col-status/, add: "c-status tasks-col-status" },
        { match: /c-created|tasks-col-created/, add: "c-created tasks-col-created" },
        { match: /c-actions|tasks-col-option|\boption\b/, add: "c-actions tasks-col-option" }
    ];

    var applyColClasses = function ($cells) {
        if (!$cells || !$cells.length) {
            return;
        }
        $cells.each(function () {
            var $cell = $(this);
            var cls = $cell.attr("class") || "";
            COL_CLASS_MAP.forEach(function (item) {
                if (item.match.test(cls)) {
                    $cell.addClass(item.add);
                }
            });
        });
        if (!$cells.filter(".c-title, .tasks-col-title").length && $cells.length >= 9) {
            $cells.eq(COL.id).addClass("c-id c-chk tasks-col-id");
            $cells.eq(COL.title).addClass("c-title tasks-col-title");
            $cells.eq(COL.score).addClass("c-score tasks-col-score");
            $cells.eq(COL.start).addClass("c-start tasks-col-start");
            $cells.eq(COL.deadline).addClass("c-deadline tasks-col-deadline");
            $cells.eq(COL.project).addClass("c-project tasks-col-project");
            $cells.eq(COL.assigned).addClass("c-assignee tasks-col-assigned");
            $cells.eq(COL.collab).addClass("c-collab tasks-col-collab tasks-col-muted");
            $cells.eq(COL.status).addClass("c-status tasks-col-status");
            $cells.eq(COL.created).addClass("c-created tasks-col-created");
            $cells.last().addClass("c-actions tasks-col-option");
        }
    };

    var AUTO_HIDE = ["collab", "milestone", "created", "start", "score", "assignee-name", "project", "deadline"];
    var AUTO_HIDE_CLASSES = AUTO_HIDE.map(function (name) {
        return "auto-hide-" + name;
    }).join(" ");

    var columnBox = function (table, selector) {
        var el = table.querySelector("thead th" + selector + ", tbody td" + selector);
        if (!el || !el.offsetParent) {
            return 0;
        }
        return Math.ceil(el.getBoundingClientRect().width);
    };

    var fitTaskTable = function ($root) {
        $root = $root && $root.length ? $root : $(".tasks-ui").has("#task-table").first();
        var $card = $root.find(".tasks-table-card").first();
        var $table = $root.find("#task-table").first();
        if (!$card.length || !$table.length || !$table[0].isConnected) {
            return;
        }

        $table.removeClass(AUTO_HIDE_CLASSES);

        if ($card[0].clientWidth < 720) {
            return;
        }

        var tableEl = $table[0];
        var cardW = $card[0].clientWidth;
        var titleEl = tableEl.querySelector("tbody td.c-title, tbody td.tasks-col-title, thead th.c-title");
        var titleW = titleEl ? titleEl.getBoundingClientRect().width : 0;
        var overflow = tableEl.scrollWidth - cardW;
        var need = Math.max(0, overflow) + Math.max(0, 220 - titleW);
        if (need <= 1) {
            return;
        }

        var widths = {
            collab: columnBox(tableEl, ".c-collab") || columnBox(tableEl, ".tasks-col-collab"),
            milestone: columnBox(tableEl, ".c-milestone") || columnBox(tableEl, ".tasks-col-milestone"),
            created: columnBox(tableEl, ".c-created") || columnBox(tableEl, ".tasks-col-created"),
            start: columnBox(tableEl, ".c-start") || columnBox(tableEl, ".tasks-col-start"),
            score: columnBox(tableEl, ".c-score") || columnBox(tableEl, ".tasks-col-score"),
            "assignee-name": 120,
            project: columnBox(tableEl, ".c-project") || columnBox(tableEl, ".tasks-col-project"),
            deadline: columnBox(tableEl, ".c-deadline") || columnBox(tableEl, ".tasks-col-deadline")
        };

        var saved = 0;
        AUTO_HIDE.forEach(function (name) {
            if (saved >= need) {
                return;
            }
            var w = widths[name] || 0;
            if (w <= 0 && name !== "assignee-name") {
                return;
            }
            $table.addClass("auto-hide-" + name);
            saved += w || 80;
        });
    };

    var scheduleFitTaskTable = function ($root) {
        if (window._tasksUiFitRaf) {
            cancelAnimationFrame(window._tasksUiFitRaf);
        }
        window._tasksUiFitRaf = requestAnimationFrame(function () {
            window._tasksUiFitRaf = 0;
            $(".tasks-ui").has("#task-table").each(function () {
                fitTaskTable($(this));
            });
        });
    };

    var markTheadColumns = function ($root) {
        var $ths = $root.find("#task-table thead tr").first().children("th");
        applyColClasses($ths);
        $root.find(".dataTables_scrollHead thead tr").first().children("th").each(function (i) {
            var cls = $ths.eq(i).attr("class");
            if (cls) {
                $(this).addClass(cls);
            }
        });
    };

    var wrapSegTabs = function ($root) {
        var $titleBar = $root.find(".nav.nav-tabs.title").first();
        if (!$titleBar.length || $titleBar.find(".tasks-seg-track").length) {
            return;
        }
        var $tabs = $titleBar.children("li").not(".title-tab, .task-status-summary");
        if ($tabs.length) {
            $tabs.wrapAll('<li class="tasks-seg-track"><ul class="tasks-seg-list"></ul></li>');
        }
    };

    var markHeaderButtons = function ($root) {
        $root.find(".title-button-group > a.btn").each(function () {
            var $btn = $(this);
            var label = (($btn.attr("title") || "") + " " + $btn.text()).toLowerCase();
            var title = $.trim($btn.attr("title") || $btn.text());
            if (title && !$btn.attr("aria-label")) {
                $btn.attr("aria-label", title);
            }
            if (!$btn.attr("title") && title) {
                $btn.attr("title", title);
            }
            if (label.indexOf("add task") !== -1 && label.indexOf("multiple") === -1) {
                $btn.addClass("tasks-btn-primary").removeClass("tasks-btn-outline");
            } else if (!$btn.hasClass("batch-update-btn") && !$btn.hasClass("batch-active-btn") && !$btn.hasClass("batch-cancel-btn")) {
                $btn.addClass("tasks-btn-outline");
            }
        });
    };

    var boxOf = function ($el) {
        if (!$el || !$el.length) {
            return $();
        }
        var $box = $el.closest(".DTTT_container, .dataTables_length, .dataTables_filter, .datatable-export");
        return $box.length ? $box : $el;
    };

    var findDateRow = function ($root, $wrapper) {
        var $dateRow = $root.find(".created-date-filter-row").first();
        if (!$dateRow.length && $wrapper && $wrapper.length) {
            $dateRow = $wrapper.find(".created-date-filter-row").first();
        }
        if (!$dateRow.length) {
            $dateRow = $(".created-date-filter-row").first();
        }
        return $dateRow;
    };

    var placeDateRow = function ($card, $dateRow) {
        if (!$card.length || !$dateRow.length) {
            return;
        }
        var $rowBRight = $card.find(".tasks-filter-row-b-right");
        if ($rowBRight.length) {
            if (!$rowBRight.find($dateRow).length) {
                $rowBRight.append($dateRow);
            }
            return;
        }
        if (!$card.find($dateRow).length) {
            $card.append($dateRow);
        }
    };

    var organizeKanbanFilters = function ($root) {
        var $card = $root.find("#tasks-filter-card");
        var $filters = $root.find("#kanban-filters");
        if (!$card.length || !$filters.length) {
            return;
        }

        var $reload = $root.find("#reload-kanban-button");
        if ($reload.length && !$card.find($reload).length) {
            $card.prepend($reload);
        }
        if (!$card.find($filters).length) {
            $card.append($filters);
        }

        $reload.addClass("tasks-reload-btn");

        var $dateRow = findDateRow($root, $filters);
        placeDateRow($card, $dateRow);

        if ($card.data("tasks-ui-organized")) {
            placeDateRow($card, findDateRow($root, $filters));
            return;
        }

        if (!$card.find("[name='project_id'], [name='quick_filter'], [name='specific_user_id'], .custom-filter-search").length) {
            return;
        }

        var $rowA = $('<div class="tasks-filter-row-a"></div>');
        var $rowB = $('<div class="tasks-filter-row-b"></div>');
        var $rowBRight = $('<div class="tasks-filter-row-b-right"></div>');

        $rowA.append($reload);
        $rowA.append(boxOf($card.find("[name='quick_filter']")));
        $rowA.append(boxOf($card.find("[name='project_id']")));
        $rowA.append(boxOf($card.find("[name='priority_id']")));
        $rowA.append(boxOf($card.find("[name='milestone_id']")));
        $rowA.append(boxOf($card.find("[name='specific_user_id']")));
        $rowA.append(boxOf($card.find("[name='deadline']")));
        $rowA.append(boxOf($card.find(".custom-filter-search")).addClass("tasks-kanban-search"));

        $card.find(".DTTT_container").each(function () {
            if ($(this).closest(".created-date-filter-row, .created-date-filter-wrap, .tasks-filter-row-a, .tasks-filter-row-b").length) {
                return;
            }
            $rowA.append(this);
        });

        $dateRow = findDateRow($root, $filters);
        if ($dateRow.length) {
            $dateRow.find(".input-group-addon").text("-");
            $rowBRight.append($dateRow);
        }
        $rowB.append($rowBRight);

        $card.append($rowA).append($rowB);
        $card.data("tasks-ui-organized", true);
    };

    var organizeFilters = function ($root) {
        var $card = $root.find("#tasks-filter-card");
        if (!$card.length) {
            return;
        }

        if ($root.find("#kanban-filters").length) {
            organizeKanbanFilters($root);
            return;
        }

        var $table = $root.find("#task-table");
        var $wrapper = $table.closest(".dataTables_wrapper");
        if (!$wrapper.length) {
            return;
        }

        restorePagination($root);

        var $tools = $wrapper.children(".datatable-tools").filter(function () {
            return isTopTools($(this));
        }).first();
        if ($tools.length && !$card.find($tools).length) {
            $card.prepend($tools);
        }

        var $dateRow = findDateRow($root, $wrapper);
        placeDateRow($card, $dateRow);

        var $summary = $root.find("#task-points-summary");
        if ($summary.length && !$card.find($summary).length) {
            $card.append($summary);
        }

        if ($card.data("tasks-ui-organized")) {
            placeDateRow($card, findDateRow($root, $wrapper));
            return;
        }

        if (!$card.find(".dataTables_length, [name='project_id'], .dataTables_filter").length) {
            return;
        }

        var $rowA = $('<div class="tasks-filter-row-a"></div>');
        var $rowB = $('<div class="tasks-filter-row-b"></div>');
        var $rowBRight = $('<div class="tasks-filter-row-b-right"></div>');

        var onMemberProfile = $root.closest(".team-member-view-page").length > 0;

        $rowA.append(boxOf($card.find(".dataTables_length")));
        $rowA.append($card.find(".column-show-hide-popover").closest(".DTTT_container"));
        if (onMemberProfile) {
            $rowA.append(boxOf($card.find("[name='quick_filter']")));
        }
        $rowA.append(boxOf($card.find("[name='project_id']")));
        $rowA.append(boxOf($card.find("[name='priority_id']")));
        $rowA.append(boxOf($card.find("[name='milestone_id']")));
        $rowA.append(boxOf($card.find("[name='specific_user_id']")));
        $rowA.append(boxOf($card.find("[name='deadline']")));
        $rowA.append(boxOf($card.find("[data-name='status_id']").first()));
        if (!$rowA.find(".filter-multi-select").length) {
            $rowA.append($card.find(".filter-multi-select").closest(".DTTT_container"));
        }
        $rowA.append($card.find(".datatable-export, .dt-buttons").first().closest(".DTTT_container, .datatable-export"));
        $rowA.append(boxOf($card.find(".dataTables_filter")));
        $card.find(".DTTT_container").each(function () {
            if ($(this).closest(".created-date-filter-row, .created-date-filter-wrap").length) {
                return;
            }
            if (!$(this).closest(".tasks-filter-row-a, .tasks-filter-row-b").length) {
                $rowA.append(this);
            }
        });

        $rowB.append($summary);
        if (!onMemberProfile) {
            $rowBRight.append(boxOf($card.find("[name='quick_filter']")));
        }
        $dateRow = findDateRow($root, $wrapper);
        if ($dateRow.length) {
            $dateRow.find(".input-group-addon").text("-");
            $rowBRight.append($dateRow);
        }
        $rowB.append($rowBRight);

        $card.append($rowA).append($rowB);
        $card.data("tasks-ui-organized", true);
        restorePagination($root);

        $wrapper.children(".datatable-tools, .task-list-sticky-top, .created-date-filter-row").each(function () {
            var $el = $(this);
            if ($el.closest("#tasks-filter-card").length || isPagerTools($el)) {
                return;
            }
            if (!$el.find("select, input, button, .select2-container, .dataTables_paginate, .dataTables_info").length) {
                $el.hide();
            }
        });
    };

    var initialsFrom = function (name) {
        var parts = $.trim(name || "").split(/\s+/).filter(Boolean);
        if (!parts.length) {
            return "";
        }
        if (parts.length === 1) {
            return parts[0].charAt(0).toUpperCase();
        }
        return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
    };

    var styleAvatar = function ($avatar, name) {
        if (!$avatar.length) {
            return;
        }
        var $img = $avatar.find("img");
        var src = $img.attr("src") || "";
        var initials = initialsFrom(name);

        var showInitials = function () {
            $avatar.removeClass("has-photo").addClass("no-photo");
            if (initials) {
                $avatar.attr("data-initials", initials);
            }
            $img.hide();
        };

        var showPhoto = function () {
            $avatar.addClass("has-photo").removeClass("no-photo").removeAttr("data-initials");
            $img.show();
        };

        if (!$img.length || !src || DEFAULT_AVATAR.test(src)) {
            showInitials();
            return;
        }

        showPhoto();
        $img.off("error.tasksUi").on("error.tasksUi", showInitials);
    };

    var statusKind = function (text) {
        text = (text || "").toLowerCase();
        if (text.indexOf("review") !== -1) {
            return "is-review";
        }
        if (text.indexOf("to do") !== -1 || text.indexOf("todo") !== -1) {
            return "is-todo";
        }
        return "is-todo";
    };

    var enhanceRows = function ($root) {
        var $table = $root.find("#task-table");
        if (!$table.length) {
            return;
        }

        $table.find("tbody tr").each(function () {
            var $row = $(this);
            if ($row.data("tasks-ui-ready")) {
                $row.find("td.option a.edit, td.option a.delete").css({
                    display: "inline-flex",
                    visibility: "visible",
                    opacity: 1
                });
                return;
            }
            var $tds = $row.children("td");
            applyColClasses($tds);

            var $idCell = $row.find("td.c-id, td.tasks-col-id").first();
            if (!$idCell.length) {
                $idCell = $tds.eq(COL.id);
            }
            $idCell.contents().each(function () {
                if (this.nodeType === 3 && $.trim(this.nodeValue)) {
                    var idText = $.trim(this.nodeValue).replace(/^#/, "");
                    $(this).replaceWith('<span class="task-id-num">#' + idText + "</span>");
                }
            });
            if (!$idCell.children(".tasks-id-line").length) {
                $idCell.contents().wrapAll('<span class="tasks-id-line"></span>');
            }

            var style = $idCell.attr("style") || "";
            var colorMatch = style.match(/border-left:\s*5px solid\s*([^;!]+)/i);
            if (colorMatch) {
                $idCell.css("border-left", "none");
                $idCell.css("--stripe", $.trim(colorMatch[1]));
            }

            var $title = $row.find("td.c-title, td.tasks-col-title").first();
            if (!$title.length) {
                $title = $tds.eq(COL.title);
            }
            $title.find(".badge").not(".task-points-badge").each(function () {
                var $badge = $(this);
                var name = $.trim($badge.text()).toLowerCase();
                if (/^\d+$/.test(name)) {
                    $badge.addClass("tasks-count-badge");
                    return;
                }
                if (name === "backend") {
                    $badge.addClass("tasks-tag-backend").removeAttr("style");
                } else if (name === "development") {
                    $badge.addClass("tasks-tag-dev").removeAttr("style");
                } else {
                    $badge.addClass("tasks-tag");
                }
            });

            var $score = $row.find("td.c-score, td.tasks-col-score").first();
            if (!$score.length) {
                $score = $tds.eq(COL.score);
            }
            var $pts = $score.find(".task-points-badge");
            var $red = $score.find(".task-points-reduction");
            if ($pts.length && !$pts.parent().hasClass("tasks-points-pill")) {
                $pts.add($red).wrapAll('<span class="tasks-points-pill"></span>');
            }

            var $titleTags = $title.find(".badge, .tasks-tag, .tasks-count-badge").not(".task-points-badge");
            if ($titleTags.length && !$title.find(".tasks-title-tags").length) {
                $titleTags.wrapAll('<span class="tasks-title-tags"></span>');
            }

            var priText = ($title.find(".priority-badge").next(".small").text() || $title.find(".sub-task-icon.priority-badge").attr("title") || "").toLowerCase();
            if (priText.indexOf("high") !== -1 || priText.indexOf("urgent") !== -1 || priText.indexOf("critical") !== -1) {
                $row.addClass("tasks-priority-high");
            } else if ($title.find(".priority-badge").length) {
                $row.addClass("tasks-priority-normal");
            }

            var $project = $row.find("td.c-project, td.tasks-col-project").find("a");
            if ($project.length) {
                $project.text($project.text().replace(/\s*\|\s*/g, " · "));
            }

            var $assigned = $row.find("td.c-assignee, td.tasks-col-assigned").first();
            if (!$assigned.length) {
                $assigned = $tds.eq(COL.assigned);
            }
            var assignedName = $.trim($assigned.text());
            styleAvatar($assigned.find(".avatar").first(), assignedName);
            var $assignedLink = $assigned.find("a").first();
            if ($assignedLink.length && assignedName && !$assignedLink.attr("title")) {
                $assignedLink.attr("title", assignedName);
            }
            if ($assignedLink.length && !$assignedLink.find(".name").length) {
                $assignedLink.contents().filter(function () {
                    return this.nodeType === 3 && $.trim(this.nodeValue);
                }).wrap('<span class="name"></span>');
            }

            $row.find("td.c-collab, td.tasks-col-collab").find(".avatar").each(function () {
                var $av = $(this);
                styleAvatar($av, $av.closest("a").attr("title") || $av.attr("title") || "");
            });

            var $status = $row.find("td.c-status, td.tasks-col-status").first();
            if (!$status.length) {
                $status = $tds.eq(COL.status);
            }
            var $statusLink = $status.find("a");
            var statusText = $.trim($status.text());
            var kind = statusKind(statusText);
            if ($statusLink.length) {
                $statusLink.addClass("tasks-status " + kind);
            } else if (statusText && statusText !== "-") {
                $status.contents().filter(function () {
                    return this.nodeType === 3 && $.trim(this.nodeValue);
                }).wrap('<span class="tasks-status ' + kind + '"></span>');
            }

            $row.data("tasks-ui-ready", true);
        });
    };

    var syncKanbanStatusCounts = function ($root) {
        if (!$root.find("#kanban-container").length) {
            return;
        }
        var todo = 0;
        var progress = 0;
        var review = 0;
        var done = 0;
        $root.find(".kanban-col").each(function () {
            var $title = $(this).find(".kanban-col-title").clone();
            $title.find(".kanban-item-count").remove();
            var title = $.trim($title.text()).toLowerCase();
            var count = parseInt($(this).find(".kanban-item-count").first().text(), 10) || 0;
            if (title.indexOf("to do") !== -1 || title === "todo") {
                todo = count;
            } else if (title.indexOf("in progress") !== -1) {
                progress = count;
            } else if (title.indexOf("review") !== -1) {
                review = count;
            } else if (title === "done") {
                done = count;
            }
        });
        $root.find(".task-todo-count").text(todo);
        $root.find(".task-in-progress-count").text(progress);
        $root.find(".task-in-review-count").text(review);
        $root.find(".task-done-count").text(done);
    };

    var enhanceKanbanCards = function ($root) {
        if ($root.find(".kanban-sortable-chosen, .kanban-sortable-ghost").length) {
            return;
        }

        $root.find("a.kanban-item").each(function () {
            var $item = $(this);
            if ($item.data("tasks-ui-ready")) {
                return;
            }

            $item.contents().filter(function () {
                return this.nodeType === 3 && $.trim(this.nodeValue);
            }).first().wrap('<span class="tasks-kanban-title"></span>');

            $item.find(".badge").not(".task-points-badge").each(function () {
                var $badge = $(this);
                var name = $.trim($badge.text()).toLowerCase();
                if (/^\d+$/.test(name) || /\d+\s*\/\s*\d+/.test(name)) {
                    $badge.addClass("tasks-count-badge");
                    return;
                }
                if (name === "backend") {
                    $badge.addClass("tasks-tag-backend").removeAttr("style");
                } else if (name === "development") {
                    $badge.addClass("tasks-tag-dev").removeAttr("style");
                } else if (!$badge.hasClass("rounded-pill")) {
                    $badge.addClass("tasks-tag");
                }
            });

            var $pts = $item.find(".task-points-badge");
            var $red = $item.find(".task-points-reduction");
            if ($pts.length && !$pts.parent().hasClass("tasks-points-pill")) {
                $pts.add($red).wrapAll('<span class="tasks-points-pill"></span>');
            }

            var $pri = $item.find(".priority-badge").first();
            if ($pri.length) {
                var priText = ($pri.attr("title") || $pri.attr("data-bs-original-title") || "").toLowerCase();
                if (priText.indexOf("high") !== -1 || priText.indexOf("urgent") !== -1 || priText.indexOf("critical") !== -1) {
                    $item.addClass("tasks-priority-high");
                } else {
                    $item.addClass("tasks-priority-normal");
                }
                var style = $pri.attr("style") || "";
                var colorMatch = style.match(/background(?:-color)?:\s*([^;]+)/i);
                if (colorMatch) {
                    $item.css("--stripe", $.trim(colorMatch[1]));
                }
            }

            var $avatar = $item.children(".avatar").first();
            if (!$avatar.length) {
                $avatar = $item.find("> .tasks-kanban-footer > .avatar, .avatar").first();
            }
            styleAvatar($avatar, $avatar.attr("title") || $item.find(".avatar").attr("title") || "");

            if (!$item.children(".tasks-kanban-footer").length) {
                var $dates = $item.children(".clearfix").has(".mt10, .text-danger, .text-warning").first();
                if (!$dates.length) {
                    $dates = $item.children(".clearfix").first();
                }
                var $footer = $('<div class="tasks-kanban-footer"></div>');
                $item.append($footer);
                if ($dates.length) {
                    $footer.append($dates);
                }
                if ($avatar.length) {
                    $footer.append($avatar);
                }
            }

            $item.data("tasks-ui-ready", true);
        });
    };

    var patchKanbanSizer = function () {
        if (typeof window.adjustViewHeightWidth !== "function" || window.adjustViewHeightWidth._tasksUiPatched) {
            return;
        }
        var orig = window.adjustViewHeightWidth;
        window.adjustViewHeightWidth = function () {
            orig.apply(this, arguments);
            $(".tasks-ui #kanban-container").css({
                width: "",
                minWidth: ""
            });
            $(".tasks-ui #kanban-wrapper").css({
                overflowX: "auto"
            });
        };
        window.adjustViewHeightWidth._tasksUiPatched = true;
        window.adjustViewHeightWidth();
    };

    var observeKanbanBoard = function () {
        if (!window.MutationObserver) {
            return;
        }
        $(".tasks-ui #load-kanban").each(function () {
            if (this._tasksUiObserved) {
                return;
            }
            this._tasksUiObserved = true;
            var timer;
            new MutationObserver(function () {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    $(".tasks-ui").each(function () {
                        enhanceKanbanCards($(this));
                        syncKanbanStatusCounts($(this));
                    });
                }, 40);
            }).observe(this, { childList: true, subtree: true });
        });
    };

    window.initTasksUiLayout = function () {
        var $roots = $(".tasks-ui");
        if (!$roots.length) {
            return;
        }

        $("body").addClass("tasks-ui-page");
        $roots.each(function () {
            var $root = $(this);
            wrapSegTabs($root);
            markHeaderButtons($root);
            organizeFilters($root);
            enhanceRows($root);
            enhanceKanbanCards($root);
            syncKanbanStatusCounts($root);
            markTheadColumns($root);
            clearTheadSticky($root);
            adjustTaskTable();
            scheduleFitTaskTable($root);
            patchKanbanSizer();
            observeKanbanBoard();
            if (window.ResizeObserver) {
                $root.find(".tasks-table-card").each(function () {
                    if (this._tasksUiFitObserved) {
                        return;
                    }
                    this._tasksUiFitObserved = true;
                    new ResizeObserver(function () {
                        scheduleFitTaskTable();
                    }).observe(this);
                });
            }
        });
    };

    $(function () {
        if (window.initTasksUiLayout) {
            window.initTasksUiLayout();
            setTimeout(window.initTasksUiLayout, 150);
            setTimeout(window.initTasksUiLayout, 400);
            setTimeout(window.initTasksUiLayout, 800);
        }

        if (window._tasksUiEventsBound) {
            return;
        }
        window._tasksUiEventsBound = true;

        $(document).on("draw.dt", "#task-table", function () {
            setTimeout(function () {
                $(".tasks-ui").each(function () {
                    var $root = $(this);
                    restorePagination($root);
                    enhanceRows($root);
                    markTheadColumns($root);
                    clearTheadSticky($root);
                });
                adjustTaskTable();
                scheduleFitTaskTable();
            }, 0);
        });

        $(window).on("resize.tasksUi", function () {
            $(".tasks-ui").each(function () {
                clearTheadSticky($(this));
            });
            adjustTaskTable();
            scheduleFitTaskTable();
            if (typeof window.adjustViewHeightWidth === "function") {
                window.adjustViewHeightWidth();
            }
        });

        $(document).on("shown.bs.tab", "[data-bs-target='#tab-tasks-info'], [data-bs-target='#tab-tasks-kanban-info']", function () {
            setTimeout(window.initTasksUiLayout, 0);
            setTimeout(window.initTasksUiLayout, 300);
        });

        if (window.ResizeObserver) {
            var page = document.getElementById("page-content");
            if (page) {
                var resizeTimer;
                new ResizeObserver(function () {
                    clearTimeout(resizeTimer);
                    resizeTimer = setTimeout(function () {
                        adjustTaskTable();
                        scheduleFitTaskTable();
                    }, 50);
                }).observe(page);
            }
            document.querySelectorAll(".tasks-ui .tasks-table-card").forEach(function (card) {
                if (card._tasksUiFitObserved) {
                    return;
                }
                card._tasksUiFitObserved = true;
                new ResizeObserver(function () {
                    scheduleFitTaskTable();
                }).observe(card);
            });
        }
    });
})(window, window.jQuery);
