<?php
helper('content_calendar');
$task_id = !empty($model_info->id) ? (int)$model_info->id : 0;
if (!$task_id) {
    return;
}
$link_info = cc_get_task_link_info($task_id);
if (!$link_info) {
    return;
}

$user = $login_user ?? (isset($this->login_user) ? $this->login_user : null);
$user_id = $user ? (int)$user->id : 0;
$is_admin = !empty($user->is_admin);
$has_cc_edit = function_exists('cc_can') && cc_can('edit', $user);
$is_assignee = (!empty($model_info->assigned_to) && (int)$model_info->assigned_to === $user_id);
$collab_ids = !empty($model_info->collaborators) ? array_filter(explode(',', (string)$model_info->collaborators)) : array();
$is_collab = in_array((string)$user_id, $collab_ids, true);
$can_change_status = ($is_admin || $has_cc_edit || $is_assignee || $is_collab);
?>

<style>
.cc-task-details-content-block {
    margin-bottom: 15px;
}
.cc-top-block-container {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 10px 14px;
}
.cc-top-block-pills-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 8px;
}
.cc-top-block-meta-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
}
.cc-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 600;
    line-height: 1.4;
    padding: 2px 8px;
    border-radius: 999px;
    white-space: nowrap;
    user-select: none;
    border: 1px solid transparent;
}
.cc-pill-btn {
    cursor: pointer;
    background: transparent;
    border: 1px solid rgba(0, 0, 0, 0.08);
    transition: all 0.15s ease;
}
.cc-pill-btn:hover {
    filter: brightness(0.95);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}
.cc-pill-btn:focus-visible {
    outline: 2px solid #2563eb;
    outline-offset: 1px;
}
.cc-pill-chevron {
    flex-shrink: 0;
    opacity: 0.7;
    margin-left: 2px;
}
.cc-pill-spinner {
    display: inline-block;
    width: 10px;
    height: 10px;
    border: 2px solid currentColor;
    border-right-color: transparent;
    border-radius: 50%;
    animation: cc-spin 0.6s linear infinite;
    margin-right: 4px;
}
@keyframes cc-spin {
    to { transform: rotate(360deg); }
}
.cc-status-popover {
    position: absolute;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
    min-width: 170px;
    max-width: 260px;
    padding: 4px;
    display: flex;
    flex-direction: column;
    gap: 2px;
    z-index: 10600;
}
.cc-status-option {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 6px 10px;
    font-size: 13px;
    color: #374151;
    background: transparent;
    border: none;
    border-radius: 6px;
    text-align: left;
    cursor: pointer;
    transition: background 0.1s ease;
}
.cc-status-option:hover,
.cc-status-option:focus {
    background: #f1f5f9;
    color: #0f172a;
    outline: none;
}
.cc-status-option.active {
    font-weight: 600;
    color: #1e40af;
    background: #eff6ff;
}
.cc-status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}
.cc-status-name {
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.cc-status-check {
    color: #2563eb;
    display: inline-flex;
    align-items: center;
}
@media (max-width: 576px) {
    .cc-top-block-meta-row {
        flex-direction: column;
        align-items: flex-start;
    }
    .cc-top-block-meta-row .cc-btn-open-calendar {
        width: 100%;
        text-align: center;
        justify-content: center;
    }
}
</style>

<div class="col-md-12 cc-task-details-content-block">
    <?php if (!empty($link_info->is_linked)) { ?>
        <div class="cc-top-block-container">
            <!-- First row: Type pill and Status pill directly under the title -->
            <div class="cc-top-block-pills-row">
                <?php if (!empty($link_info->content_type)) { ?>
                    <span class="cc-pill cc-type-pill" style="<?php echo esc($link_info->type_pill_style); ?>">
                        <span class="cc-pill-text"><?php echo esc($link_info->content_type); ?></span>
                    </span>
                <?php } ?>

                <?php if ($can_change_status) { ?>
                    <button type="button" class="cc-pill cc-pill-btn cc-task-status-btn"
                            data-task-id="<?php echo $task_id; ?>"
                            data-status="<?php echo esc($link_info->status); ?>"
                            data-options="<?php echo esc(json_encode($link_info->status_options ?: array())); ?>"
                            style="<?php echo esc($link_info->status_pill_style); ?>"
                            aria-haspopup="listbox" aria-expanded="false" title="<?php echo esc(cc_lang('cc_set_status') ?: 'Change status'); ?>">
                        <span class="cc-pill-text"><?php echo esc($link_info->status ?: (cc_lang('cc_no_status') ?: 'No status')); ?></span>
                        <svg class="cc-pill-chevron" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </button>
                <?php } else { ?>
                    <span class="cc-pill" style="<?php echo esc($link_info->status_pill_style); ?>">
                        <span class="cc-pill-text"><?php echo esc($link_info->status ?: (cc_lang('cc_no_status') ?: 'No status')); ?></span>
                    </span>
                <?php } ?>
            </div>

            <!-- Second row: Client • Date • Content title, and Open in Content Calendar button -->
            <div class="cc-top-block-meta-row">
                <div class="d-flex align-items-center flex-wrap gap-1 font-13 text-secondary">
                    <span class="strong text-dark"><?php echo esc($link_info->calendar_name); ?></span>
                    <span class="text-muted mx-1">•</span>
                    <span><?php echo esc($link_info->formatted_date); ?></span>
                    <span class="text-muted mx-1">•</span>
                    <span class="text-dark"><?php echo esc($link_info->title); ?></span>
                </div>
                <a href="<?php echo esc($link_info->link_url); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-default btn-sm cc-btn-open-calendar">
                    <i data-feather="calendar" class="icon-14"></i> Open in Content Calendar
                </a>
            </div>
        </div>
    <?php } else if (!empty($link_info->content_type)) { ?>
        <!-- Manual task: readable Type pill -->
        <div class="d-flex align-items-center gap-2">
            <span class="cc-pill cc-type-pill" style="<?php echo esc($link_info->type_pill_style); ?>">
                <span class="cc-pill-text"><?php echo esc($link_info->content_type); ?></span>
            </span>
        </div>
    <?php } ?>
</div>

<script type="text/javascript">
(function () {
    if (window.feather) { feather.replace(); }

    var statusUrl = "<?php echo get_uri('content_calendar/task_set_status'); ?>";
    var activeStatusBtn = null;

    function esc(s) {
        if (s === null || s === undefined) return "";
        return $("<div>").text(String(s)).html();
    }

    function srgbToLin(c) {
        c = c / 255.0;
        return (c <= 0.04045) ? (c / 12.92) : Math.pow((c + 0.055) / 1.055, 2.4);
    }
    function getLum(hex) {
        hex = String(hex || '').replace('#', '');
        if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
        var r = parseInt(hex.substring(0, 2), 16) || 0;
        var g = parseInt(hex.substring(2, 4), 16) || 0;
        var b = parseInt(hex.substring(4, 6), 16) || 0;
        return 0.2126 * srgbToLin(r) + 0.7152 * srgbToLin(g) + 0.0722 * srgbToLin(b);
    }
    function getContrastRatio(hex1, hex2) {
        var l1 = getLum(hex1);
        var l2 = getLum(hex2);
        return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    }
    function computePillStyle(name, customBg, customFg) {
        if (!name && !customBg) {
            return "background: #f3f4f6; color: #374151; font-weight: 600; font-size: 11px; padding: 2px 8px; border-radius: 999px; line-height: 1.4; display: inline-flex; align-items: center;";
        }
        var bg = customBg || '#f3f4f6';
        var fg = customFg || '#374151';
        try {
            var cr = getContrastRatio(bg, fg);
            if (cr < 4.5) {
                var cDark = getContrastRatio(bg, '#0f172a');
                var cLight = getContrastRatio(bg, '#ffffff');
                fg = (cDark >= 4.5 && cDark >= cLight) ? '#0f172a' : (cLight >= 4.5 ? '#ffffff' : (cDark >= 4.5 ? '#0f172a' : (cDark > cLight ? '#0f172a' : '#ffffff')));
            }
        } catch (e) {
            fg = '#1e293b';
        }
        return "background: " + bg + "; color: " + fg + "; font-weight: 600; font-size: 11px; padding: 2px 8px; border-radius: 999px; line-height: 1.4; display: inline-flex; align-items: center;";
    }

    function closePopover(restoreFocus) {
        var $pop = $("#cc-task-status-popover");
        if ($pop.length) {
            $pop.remove();
        }
        if (activeStatusBtn) {
            activeStatusBtn.attr("aria-expanded", "false");
            if (restoreFocus) {
                activeStatusBtn.focus();
            }
            activeStatusBtn = null;
        }
    }

    function openPopover($btn) {
        if (activeStatusBtn && activeStatusBtn[0] === $btn[0]) {
            closePopover(false);
            return;
        }
        closePopover(false);

        activeStatusBtn = $btn;
        $btn.attr("aria-expanded", "true");

        var curStatus = $btn.attr("data-status") || "";
        var options = [];
        try {
            options = JSON.parse($btn.attr("data-options") || "[]");
        } catch (e) {
            options = [];
        }

        if (!options || !options.length) {
            options = ["Planned", "In design", "Scheduled", "Posted"];
        }

        var html = "<div id='cc-task-status-popover' class='cc-status-popover' role='listbox' aria-label='Choose status'>";
        $.each(options, function (i, opt) {
            var isSelected = (opt.toLowerCase() === curStatus.toLowerCase());
            var dotColor = "#9ca3af";
            var optLower = opt.toLowerCase();
            if (optLower === "planned") dotColor = "#9ca3af";
            else if (optLower === "in design") dotColor = "#6366f1";
            else if (optLower === "scheduled") dotColor = "#f59e0b";
            else if (optLower === "posted") dotColor = "#10b981";

            html += "<button type='button' class='cc-status-option" + (isSelected ? " active" : "") + "' role='option' aria-selected='" + (isSelected ? "true" : "false") + "' data-status='" + esc(opt) + "'>";
            html += "<span class='cc-status-dot' style='background:" + dotColor + "'></span>";
            html += "<span class='cc-status-name'>" + esc(opt) + "</span>";
            if (isSelected) {
                html += "<span class='cc-status-check'><svg width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><polyline points='20 6 9 17 4 12'></polyline></svg></span>";
            }
            html += "</button>";
        });
        html += "</div>";

        var $pop = $(html).appendTo("body");

        var btnOffset = $btn.offset();
        var btnWidth = $btn.outerWidth();
        var btnHeight = $btn.outerHeight();
        var popWidth = $pop.outerWidth();
        var popHeight = $pop.outerHeight();
        var winWidth = $(window).width();
        var winHeight = $(window).height();
        var scrollTop = $(window).scrollTop();

        var top = btnOffset.top + btnHeight + 4;
        if (top + popHeight > scrollTop + winHeight - 10) {
            var upward = btnOffset.top - popHeight - 4;
            if (upward >= scrollTop + 10) {
                top = upward;
            }
        }

        var left = btnOffset.left;
        if (left + popWidth > winWidth - 10) {
            left = btnOffset.left + btnWidth - popWidth;
        }
        if (left + popWidth > winWidth - 10) {
            left = winWidth - popWidth - 10;
        }
        if (left < 10) {
            left = 10;
        }

        $pop.css({
            top: top + "px",
            left: left + "px",
            zIndex: 10600
        });

        var $active = $pop.find(".cc-status-option.active");
        if ($active.length) {
            $active.focus();
        } else {
            $pop.find(".cc-status-option:first").focus();
        }
    }

    function selectStatus($btn, newStatus) {
        closePopover(false);

        var taskId = parseInt($btn.attr("data-task-id"), 10);
        var oldStatus = $btn.attr("data-status") || "";
        var oldStyle = $btn.attr("style") || "";

        if (newStatus === oldStatus) {
            $btn.focus();
            return;
        }

        // Optimistic update
        var chevron = "<svg class='cc-pill-chevron' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'></polyline></svg>";
        var spinner = "<span class='cc-pill-spinner'></span>";
        $btn.attr("data-status", newStatus);
        $btn.html(spinner + "<span class='cc-pill-text'>" + esc(newStatus) + "</span>" + chevron);

        var csrfToken = $('input[name="rise_csrf_token"]').val() || $('meta[name="rise_csrf_token"]').attr('content') || '';
        var postData = {
            task_id: taskId,
            status: newStatus
        };
        if (csrfToken) {
            postData.rise_csrf_token = csrfToken;
        }

        $.ajax({
            url: statusUrl,
            type: "POST",
            data: postData,
            dataType: "json",
            success: function (res) {
                if (res && res.success) {
                    var styleStr = res.style || (res.bg_color ? computePillStyle(newStatus, res.bg_color, res.text_color) : $btn.attr("style"));
                    $btn.attr("style", styleStr)
                        .attr("data-status", newStatus)
                        .html("<span class='cc-pill-text'>" + esc(newStatus) + "</span>" + chevron);
                    if (window.appAlert) {
                        appAlert.success(res.message || "Status updated", {duration: 2500});
                    }
                } else {
                    revertStatus($btn, oldStatus, oldStyle, (res && res.message) ? res.message : "Failed to update status");
                }
            },
            error: function (xhr) {
                var msg = "Failed to update status";
                try {
                    var errRes = JSON.parse(xhr.responseText);
                    if (errRes && errRes.message) msg = errRes.message;
                } catch (e) {}
                revertStatus($btn, oldStatus, oldStyle, msg);
            }
        });
    }

    function revertStatus($btn, oldStatus, oldStyle, msg) {
        var chevron = "<svg class='cc-pill-chevron' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'></polyline></svg>";
        $btn.attr("style", oldStyle)
            .attr("data-status", oldStatus)
            .html("<span class='cc-pill-text'>" + esc(oldStatus || "No status") + "</span>" + chevron);
        if (window.appAlert) {
            appAlert.error(msg, {duration: 3500});
        }
    }

    // Event bindings
    $(document).off("click.ccTaskStatusBtn", ".cc-task-status-btn").on("click.ccTaskStatusBtn", ".cc-task-status-btn", function (e) {
        e.preventDefault();
        e.stopPropagation();
        openPopover($(this));
    });

    $(document).off("click.ccTaskStatusOpt", ".cc-status-option").on("click.ccTaskStatusOpt", ".cc-status-option", function (e) {
        e.preventDefault();
        e.stopPropagation();
        var st = $(this).attr("data-status");
        if (activeStatusBtn) {
            selectStatus(activeStatusBtn, st);
        }
    });

    $(document).off("click.ccTaskStatusOutside").on("click.ccTaskStatusOutside", function (e) {
        if (!$(e.target).closest("#cc-task-status-popover, .cc-task-status-btn").length) {
            closePopover(false);
        }
    });

    $(document).off("keydown.ccTaskStatusKey").on("keydown.ccTaskStatusKey", function (e) {
        var $pop = $("#cc-task-status-popover");
        if ($pop.length) {
            var $options = $pop.find(".cc-status-option");
            var $focused = $options.filter(":focus");
            var idx = $options.index($focused);

            if (e.key === "Escape" || e.keyCode === 27) {
                e.preventDefault();
                closePopover(true);
            } else if (e.key === "ArrowDown" || e.keyCode === 40) {
                e.preventDefault();
                var nextIdx = (idx + 1) % $options.length;
                $options.eq(nextIdx).focus();
            } else if (e.key === "ArrowUp" || e.keyCode === 38) {
                e.preventDefault();
                var prevIdx = (idx - 1 + $options.length) % $options.length;
                $options.eq(prevIdx).focus();
            } else if (e.key === "Tab" || e.keyCode === 9) {
                closePopover(false);
            } else if ((e.key === "Enter" || e.keyCode === 13) && $focused.length) {
                e.preventDefault();
                $focused.trigger("click");
            }
        }
    });
})();
</script>
