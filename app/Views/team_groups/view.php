<div id="page-content" class="page-wrapper clearfix">
    <div class="card">
        <div class="page-title clearfix rounded-top">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center">
                    <h4 class="mb-0 fw-bold"><?php echo esc($group->title); ?></h4>
                    <span class="badge bg-primary rounded-pill ms-2" id="group-member-count-badge" style="font-size: 13px; font-weight: 500;">
                        <?php echo count($members) . " " . (count($members) === 1 ? app_lang('member') : app_lang('members')); ?>
                    </span>
                </div>
                
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <div class="d-flex align-items-center me-2">
                        <label for="group-month-filter" class="me-2 mb-0 text-off" style="font-size: 13px;"><i data-feather="calendar" class="icon-14"></i></label>
                        <select id="group-month-filter" class="form-select form-select-sm" style="width: auto; min-width: 160px;">
                            <?php foreach ($month_options as $opt) { ?>
                                <option value="<?php echo $opt['value']; ?>" <?php echo ($opt['value'] === $selected_month) ? "selected='selected'" : ""; ?>>
                                    <?php echo esc($opt['text']); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <?php if ($can_manage_groups) { ?>
                        <?php echo modal_anchor(get_uri("team_groups/modal_form/" . $group->id), "<i data-feather='edit' class='icon-16'></i> " . app_lang('edit_group'), array("class" => "btn btn-default btn-sm", "title" => app_lang('edit_group'))); ?>
                        
                        <button type="button" class="btn btn-danger btn-sm delete-group-btn delete-team-group-btn" id="delete-group-btn" data-group-id="<?php echo $group->id; ?>" data-group-title="<?php echo esc($group->title); ?>">
                            <i data-feather="trash-2" class="icon-16"></i> <?php echo app_lang('delete_group'); ?>
                        </button>
                    <?php } ?>
                </div>
            </div>
        </div>

        <div class="card-body">
            <!-- 4 Summary Cards -->
            <div class="row mb-4 team-group-summary-cards">
                <div class="col-sm-6 col-lg-3 mb-3">
                    <div class="card h-100 bg-light border-0 shadow-none">
                        <div class="card-body d-flex align-items-center">
                            <div class="rounded-circle bg-primary bg-opacity-10 p-3 me-3 text-primary d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                <i data-feather="users" class="icon-24"></i>
                            </div>
                            <div>
                                <h3 class="mb-0 fw-bold"><?php echo $summary['members_count']; ?></h3>
                                <span class="text-off" style="font-size: 13px;"><?php echo app_lang('members'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-sm-6 col-lg-3 mb-3">
                    <div class="card h-100 bg-light border-0 shadow-none">
                        <div class="card-body d-flex align-items-center">
                            <div class="rounded-circle bg-info bg-opacity-10 p-3 me-3 text-info d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                <i data-feather="check-circle" class="icon-24"></i>
                            </div>
                            <div>
                                <h3 class="mb-0 fw-bold"><?php echo $summary['open_tasks']; ?></h3>
                                <span class="text-off" style="font-size: 13px;"><?php echo app_lang('open_tasks'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-sm-6 col-lg-3 mb-3">
                    <div class="card h-100 bg-light border-0 shadow-none">
                        <div class="card-body d-flex align-items-center">
                            <div class="rounded-circle bg-danger bg-opacity-10 p-3 me-3 text-danger d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                <i data-feather="alert-triangle" class="icon-24"></i>
                            </div>
                            <div>
                                <h3 class="mb-0 fw-bold <?php echo $summary['overdue_tasks'] > 0 ? 'text-danger' : ''; ?>"><?php echo $summary['overdue_tasks']; ?></h3>
                                <span class="text-off" style="font-size: 13px;"><?php echo app_lang('overdue_tasks'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-sm-6 col-lg-3 mb-3">
                    <div class="card h-100 bg-light border-0 shadow-none">
                        <div class="card-body d-flex align-items-center">
                            <div class="rounded-circle bg-success bg-opacity-10 p-3 me-3 text-success d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                <i data-feather="award" class="icon-24"></i>
                            </div>
                            <div>
                                <h3 class="mb-0 fw-bold text-success"><?php echo (float) $summary['total_points']; ?></h3>
                                <span class="text-off" style="font-size: 13px;">
                                    <?php echo app_lang('points'); ?> (<?php echo date("M Y", strtotime($selected_month . "-01")); ?>)
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Members Table with Per-Status Columns -->
            <div class="table-responsive">
                <table id="team-group-members-table" class="table table-striped table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="min-width: 180px;"><?php echo app_lang('member'); ?></th>
                            <th style="min-width: 130px;"><?php echo app_lang('job_title'); ?></th>
                            <?php foreach ($task_statuses as $status) { ?>
                                <th class="text-center" style="min-width: 80px;">
                                    <?php 
                                    if ($status->key_name === "done" || (int)$status->id === 3) {
                                        echo app_lang('done') . " (" . date("M Y", strtotime($selected_month . "-01")) . ")";
                                    } else {
                                        echo $status->key_name ? app_lang($status->key_name) : esc($status->title);
                                    }
                                    ?>
                                </th>
                            <?php } ?>
                            <th class="text-center" style="min-width: 80px;"><?php echo app_lang('overdue_tasks'); ?></th>
                            <th class="text-center" style="min-width: 90px;"><?php echo app_lang('points'); ?></th>
                            <th class="text-center" style="min-width: 110px;"><?php echo app_lang('today_status'); ?></th>
                            <th class="text-center" style="min-width: 80px;"><?php echo app_lang('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($members)) { ?>
                            <tr>
                                <td colspan="<?php echo 6 + count($task_statuses); ?>" class="text-center text-off py-4">
                                    <?php echo app_lang('no_data_found'); ?>
                                </td>
                            </tr>
                        <?php } else { ?>
                            <?php foreach ($members as $member) { ?>
                                <?php
                                $m_id = (int) $member->id;
                                $m_metric = isset($metrics[$m_id]) ? $metrics[$m_id] : array(
                                    "status_counts" => array(),
                                    "open_tasks" => 0,
                                    "overdue_tasks" => 0,
                                    "completed_tasks" => 0,
                                    "points" => 0,
                                    "status_text" => app_lang("absent"),
                                    "status_class" => "bg-secondary"
                                );
                                $full_name = trim($member->first_name . " " . $member->last_name);
                                ?>
                                <tr id="group-member-row-<?php echo $m_id; ?>">
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <span class="avatar avatar-xs me-2">
                                                <img src="<?php echo get_avatar($member->image); ?>" alt="..." />
                                            </span>
                                            <div>
                                                <?php echo get_team_member_profile_link($member->id, $full_name, array("class" => "dark fw-bold")); ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo $member->job_title ? esc($member->job_title) : "<span class='text-off'>-</span>"; ?>
                                    </td>
                                    <?php foreach ($task_statuses as $status) { ?>
                                        <?php
                                        $sid = (int) $status->id;
                                        $count = isset($m_metric['status_counts'][$sid]) ? (int) $m_metric['status_counts'][$sid] : 0;
                                        if ($status->key_name === "in_review" || $sid === 10) {
                                            $link = get_uri('tasks/in_review/all?specific_user_id=' . $m_id);
                                        } else {
                                            $link = get_uri('projects/all_tasks?specific_user_id=' . $m_id . '&status_id=' . $sid);
                                        }
                                        ?>
                                        <td class="text-center">
                                            <?php if ($count > 0) { ?>
                                                <a href="<?php echo $link; ?>" class="badge bg-light text-dark border px-2 py-1 text-decoration-none">
                                                    <?php echo $count; ?>
                                                </a>
                                            <?php } else { ?>
                                                <a href="<?php echo $link; ?>" class="text-off text-decoration-none">0</a>
                                            <?php } ?>
                                        </td>
                                    <?php } ?>
                                    <td class="text-center">
                                        <?php 
                                        $overdue_link = get_uri('projects/all_tasks?specific_user_id=' . $m_id . '&deadline=expired');
                                        if ($m_metric['overdue_tasks'] > 0) { ?>
                                            <a href="<?php echo $overdue_link; ?>" class="badge bg-danger px-2 py-1 text-white text-decoration-none">
                                                <?php echo $m_metric['overdue_tasks']; ?>
                                            </a>
                                        <?php } else { ?>
                                            <a href="<?php echo $overdue_link; ?>" class="text-off text-decoration-none">0</a>
                                        <?php } ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="fw-bold <?php echo $m_metric['points'] > 0 ? 'text-success' : 'text-off'; ?>">
                                            <?php echo (float) $m_metric['points']; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $m_metric['status_class']; ?> px-2 py-1">
                                            <?php echo esc($m_metric['status_text']); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-inline-flex align-items-center gap-1">
                                            <a href="<?php echo get_uri('projects/all_tasks?specific_user_id=' . $member->id); ?>" 
                                               class="btn btn-default btn-sm p-1 view-tasks-btn" 
                                               title="<?php echo app_lang('view_tasks'); ?>" 
                                               data-bs-toggle="tooltip">
                                                <i data-feather="external-link" class="icon-16"></i>
                                            </a>

                                            <?php if ($can_manage_groups) { ?>
                                                <a href="#" 
                                                   class="btn btn-default btn-sm p-1 text-danger remove-member-btn remove-group-member-btn" 
                                                   data-group-id="<?php echo $group->id; ?>" 
                                                   data-user-id="<?php echo $member->id; ?>" 
                                                   title="<?php echo app_lang('remove_from_group'); ?>" 
                                                   data-bs-toggle="tooltip">
                                                    <i data-feather="x" class="icon-16"></i>
                                                </a>
                                            <?php } ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
    $(document).ready(function () {
        // Month filter reload
        $("#group-month-filter").on("change", function () {
            var selectedMonth = $(this).val();
            window.location.href = "<?php echo get_uri('team_groups/view/' . $group->id); ?>?month=" + encodeURIComponent(selectedMonth);
        });

        // Delete group handler
        $("#delete-group-btn").on("click", function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $btn = $(this);
            var groupId = $btn.data("group-id");

            $btn.appConfirmation({
                title: "<?php echo app_lang('confirm_delete_group'); ?>",
                btnConfirmLabel: "<?php echo app_lang('yes'); ?>",
                btnCancelLabel: "<?php echo app_lang('no'); ?>",
                onConfirm: function () {
                    appLoader.show();
                    $.ajax({
                        url: "<?php echo get_uri('team_groups/delete'); ?>",
                        type: "POST",
                        dataType: "json",
                        data: {id: groupId},
                        success: function (res) {
                            if (res.success) {
                                $("#sidebar-menu a[href*='team_groups/view/" + groupId + "']").closest('li').remove();
                                appAlert.success(res.message, {duration: 10000});
                                setTimeout(function () {
                                    window.location.href = "<?php echo get_uri('team_members'); ?>";
                                }, 500);
                            } else {
                                appAlert.error(res.message);
                                appLoader.hide();
                            }
                        },
                        error: function () {
                            appAlert.error(appLang.error_occurred);
                            appLoader.hide();
                        }
                    });
                }
            });
        });

        // Remove member handler
        $(".remove-member-btn").on("click", function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $btn = $(this);
            var groupId = $btn.data("group-id");
            var userId = $btn.data("user-id");

            $btn.appConfirmation({
                title: "<?php echo app_lang('confirm_remove_member'); ?>",
                btnConfirmLabel: "<?php echo app_lang('yes'); ?>",
                btnCancelLabel: "<?php echo app_lang('no'); ?>",
                onConfirm: function () {
                    appLoader.show();
                    $.ajax({
                        url: "<?php echo get_uri('team_groups/remove_member'); ?>",
                        type: "POST",
                        dataType: "json",
                        data: {
                            group_id: groupId,
                            user_id: userId
                        },
                        success: function (res) {
                            if (res.success) {
                                $("#group-member-row-" + userId).fadeOut(300, function () {
                                    $(this).remove();
                                    // Update count badge
                                    var remaining = $("#team-group-members-table tbody tr[id^='group-member-row-']").length;
                                    var label = remaining + " " + (remaining === 1 ? "<?php echo app_lang('member'); ?>" : "<?php echo app_lang('members'); ?>");
                                    $("#group-member-count-badge").text(label);
                                });
                                appAlert.success(res.message, {duration: 10000});
                            } else {
                                appAlert.error(res.message);
                            }
                            appLoader.hide();
                        },
                        error: function () {
                            appAlert.error(appLang.error_occurred);
                            appLoader.hide();
                        }
                    });
                }
            });
        });
    });
</script>
