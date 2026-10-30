<?php echo view("includes/cropbox"); ?>
<?php load_css(array("assets/css/profile-header-ui.css", "assets/css/tasks-ui.css")); ?>
<?php load_js(array("assets/js/tasks-ui.js")); ?>
<div id="page-content" class="clearfix team-member-view-page">
    <?php $show_task_chart = !empty($show_member_tasks) && !empty($member_task_statuses); ?>
    <div class="profile-header-ui-wrap">
        <div class="bg-success clearfix profile-header-ui">
            <div class="profile-header-grid<?php echo $show_task_chart ? "" : " is-compact"; ?>">
                <div class="profile-header-profile">
                    <?php echo view("users/profile_image_section"); ?>
                </div>

                <?php if ($show_task_chart) { ?>
                    <div class="profile-header-chart">
                        <?php echo view("team_members/task_status_chart", array("member_task_statuses" => $member_task_statuses)); ?>
                    </div>
                <?php } ?>

                <div class="profile-header-stats cover-widget">
                    <div id="team-member-cover-widgets">
                        <?php
                        if ($show_projects_count) {
                            echo count_project_status_widget($user_info->id);
                        }

                        echo count_total_time_widget($user_info->id);
                        ?>
                    </div>
                </div>

                <?php if ($show_task_chart) { ?>
                    <div class="profile-header-score" id="member-monthly-task-points">
                        <?php echo view("projects/tasks/monthly_points_widget", array(
                            "widget_id" => "member-monthly-points",
                            "theme" => "light",
                            "default_user_id" => $user_info->id,
                            "user_selector" => ".team-member-view-page [name='specific_user_id']"
                        )); ?>
                    </div>
                <?php } ?>
            </div>
        </div>
    </div>


    <ul id="team-member-view-tabs" data-bs-toggle="ajax-tab" class="nav nav-tabs scrollable-tabs rounded-0" role="tablist">

        <?php if ($show_timeline) { ?>
            <li><a role="presentation" data-bs-toggle="tab" href="javascript:;" data-bs-target="#tab-timeline"> <?php echo app_lang('timeline'); ?></a></li>
        <?php } ?>

        <?php if ($show_general_info) { ?>
            <li><a  role="presentation" data-bs-toggle="tab" href="<?php echo_uri("team_members/general_info/" . $user_info->id); ?>" data-bs-target="#tab-general-info"> <?php echo app_lang('general_info'); ?></a></li>
        <?php } ?>

        <?php if ($show_general_info) { ?>
            <li><a  role="presentation" data-bs-toggle="tab" href="<?php echo_uri("team_members/social_links/" . $user_info->id); ?>" data-bs-target="#tab-social-links"> <?php echo app_lang('social_links'); ?></a></li>
        <?php } ?>

        <?php if ($show_job_info) { ?>
            <li><a  role="presentation" data-bs-toggle="tab" href="<?php echo_uri("team_members/job_info/" . $user_info->id); ?>" data-bs-target="#tab-job-info"> <?php echo app_lang('job_info'); ?></a></li>
        <?php } ?>

        <?php if ($show_account_settings) { ?>
            <li><a role="presentation" data-bs-toggle="tab" href="<?php echo_uri("team_members/account_settings/" . $user_info->id); ?>" data-bs-target="#tab-account-settings"> <?php echo app_lang('account_settings'); ?></a></li>
        <?php } ?>

        <?php if ($login_user->id == $user_info->id) { ?>
            <li><a role="presentation" data-bs-toggle="tab" href="<?php echo_uri("team_members/my_preferences/" . $user_info->id); ?>" data-bs-target="#tab-my-preferences"> <?php echo app_lang('my_preferences'); ?></a></li>
        <?php } ?>
        <?php if ($login_user->id == $user_info->id) { ?>
            <li><a role="presentation" data-bs-toggle="tab" href="<?php echo_uri("left_menus/index/user"); ?>" data-bs-target="#tab-user-left-menu"> <?php echo app_lang('left_menu'); ?></a></li>
        <?php } ?>

        <?php if ($show_general_info) { ?>
            <li><a  role="presentation" data-bs-toggle="tab" href="<?php echo_uri("team_members/files/" . $user_info->id); ?>" data-bs-target="#tab-files"> <?php echo app_lang('files'); ?></a></li>
        <?php } ?>

        <?php if ($show_projects) { ?>
            <li><a role="presentation" data-bs-toggle="tab" href="<?php echo_uri("team_members/projects_info/" . $user_info->id); ?>" data-bs-target="#tab-projects-info"><?php echo app_lang('projects'); ?></a></li>
        <?php } ?> 

        <?php if ($show_attendance) { ?>
            <li><a role="presentation" data-bs-toggle="tab" href="<?php echo_uri("team_members/attendance_info/" . $user_info->id); ?>" data-bs-target="#tab-attendance-info"> <?php echo app_lang('attendance'); ?></a></li>
        <?php } ?>

        <?php if (!empty($show_member_tasks)) { ?>
            <li><a role="presentation" data-bs-toggle="tab" href="<?php echo_uri("team_members/tasks_info/" . $user_info->id); ?>" data-bs-target="#tab-tasks-info"><?php echo app_lang('tasks'); ?></a></li>
            <li><a role="presentation" data-bs-toggle="tab" href="<?php echo_uri("team_members/tasks_kanban_info/" . $user_info->id); ?>" data-bs-target="#tab-tasks-kanban-info"><?php echo app_lang('kanban'); ?></a></li>
        <?php } ?>
        <?php if ($show_leave) { ?>
            <li><a role="presentation" data-bs-toggle="tab" href="<?php echo_uri("team_members/leave_info/" . $user_info->id); ?>" data-bs-target="#tab-leave-info"><?php echo app_lang('leaves'); ?></a></li>
        <?php } ?>
        <?php if ($show_expense_info) { ?>
            <li><a role="presentation" data-bs-toggle="tab" href="<?php echo_uri("team_members/expense_info/" . $user_info->id); ?>" data-bs-target="#tab-expense-info"><?php echo app_lang('expenses'); ?></a></li>
        <?php } ?>

        <?php
        $hook_tabs = array();
        $hook_tabs = app_hooks()->apply_filters('app_filter_staff_profile_ajax_tab', $hook_tabs, $user_info->id);
        $hook_tabs = is_array($hook_tabs) ? $hook_tabs : array();
        foreach ($hook_tabs as $hook_tab) {
            ?>
            <li><a role="presentation" data-bs-toggle="tab" href="<?php echo get_array_value($hook_tab, 'url') ?>" data-bs-target="#<?php echo get_array_value($hook_tab, 'target') ?>"><?php echo get_array_value($hook_tab, 'title') ?></a></li>
            <?php
        }
        ?>

    </ul>

    <div class="tab-content">
        <div role="tabpanel" class="tab-pane fade pl15 pr15 mb15" id="tab-timeline">
            <?php echo timeline_widget(array("limit" => 20, "offset" => 0, "is_first_load" => true, "user_id" => $user_info->id)); ?>
        </div>
        <div role="tabpanel" class="tab-pane fade" id="tab-general-info"></div>
        <div role="tabpanel" class="tab-pane fade" id="tab-files"></div>
        <div role="tabpanel" class="tab-pane fade" id="tab-social-links"></div>
        <div role="tabpanel" class="tab-pane fade" id="tab-job-info"></div>
        <div role="tabpanel" class="tab-pane fade" id="tab-account-settings"></div>
        <div role="tabpanel" class="tab-pane fade" id="tab-my-preferences"></div>
        <div role="tabpanel" class="tab-pane fade" id="tab-user-left-menu"></div>
        <div role="tabpanel" class="tab-pane fade" id="tab-projects-info"></div>
        <div role="tabpanel" class="tab-pane fade" id="tab-attendance-info"></div>
        <div role="tabpanel" class="tab-pane fade" id="tab-tasks-info"></div>
        <div role="tabpanel" class="tab-pane fade" id="tab-tasks-kanban-info"></div>
        <div role="tabpanel" class="tab-pane fade" id="tab-leave-info"></div>
        <div role="tabpanel" class="tab-pane fade" id="tab-expense-info"></div>
        <?php
        foreach ($hook_tabs as $hook_tab) {
            ?>
            <div role="tabpanel" class="tab-pane fade" id="<?php echo get_array_value($hook_tab, 'target') ?>"></div>
            <?php
        }
        ?>
    </div>
</div>

<?php echo view("projects/tasks/monthly_points_widget_js"); ?>
<script type="text/javascript">
    $(document).ready(function () {
        $(".upload").change(function () {
            if (typeof FileReader == 'function' && !$(this).hasClass("hidden-input-file")) {
                showCropBox(this);
            } else {
                $("#profile-image-form").submit();
            }
        });
        $("#profile_image").change(function () {
            $("#profile-image-form").submit();
        });


        $("#profile-image-form").appForm({
            isModal: false,
            beforeAjaxSubmit: function (data) {
                $.each(data, function (index, obj) {
                    if (obj.name === "profile_image") {
                        var profile_image = replaceAll(":", "~", data[index]["value"]);
                        data[index]["value"] = profile_image;
                    }
                });
            },
            onSuccess: function (result) {
                if (typeof FileReader == 'function' && !result.reload_page) {
                    appAlert.success(result.message, {duration: 10000});
                } else {
                    location.reload();
                }
            }
        });

        var $profileDetails = $("#profile-image-section > .box-content").not(".profile-image").first();
        if ($profileDetails.length && !$profileDetails.find("#profile-member-name").length) {
            $profileDetails.prepend($("#profile-member-name"));
        }

        setTimeout(function () {
            var tab = "<?php echo $tab; ?>";
            if (tab === "general") {
                $("[data-bs-target='#tab-general-info']").trigger("click");
            } else if (tab === "account") {
                $("[data-bs-target='#tab-account-settings']").trigger("click");
            } else if (tab === "social") {
                $("[data-bs-target='#tab-social-links']").trigger("click");
            } else if (tab === "job_info") {
                $("[data-bs-target='#tab-job-info']").trigger("click");
            } else if (tab === "my_preferences") {
                $("[data-bs-target='#tab-my-preferences']").trigger("click");
            } else if (tab === "left_menu") {
                $("[data-bs-target='#tab-user-left-menu']").trigger("click");
            } else if (tab === "tasks") {
                $("[data-bs-target='#tab-tasks-info']").trigger("click");
            } else if (tab === "kanban") {
                $("[data-bs-target='#tab-tasks-kanban-info']").trigger("click");
            }
        }, 210);

        var syncingMemberFilter = false;
        var currentMemberId = "<?php echo $user_info->id; ?>";

        var updateMemberTaskChart = function (chart) {
            if (!chart) {
                return;
            }

            var $legend = $(".team-member-view-page .team-member-task-chart .col-7");
            if ($legend.length && chart.statuses) {
                $legend.empty();
                var total = 0;
                $.each(chart.statuses, function (i, status) {
                    total += parseInt(status.total, 10) || 0;
                    $legend.append(
                        '<div class="d-flex align-items-center mb-1 team-member-legend-row">' +
                        '<span class="color-tag border-circle me-2 wh10" style="background-color: ' + status.color + ';"></span>' +
                        '<span class="chart-label">' + $("<div>").text(status.title).html() + '</span>' +
                        '<span class="chart-count strong ms-auto">' + status.total + '</span>' +
                        '</div>'
                    );
                });
                $("#member-task-chart-total").text(total);
            }

            if (window.memberTaskStatusChart) {
                window.memberTaskStatusChart.data.labels = chart.labels;
                window.memberTaskStatusChart.data.datasets[0].data = chart.data;
                window.memberTaskStatusChart.data.datasets[0].backgroundColor = chart.colors;
                window.memberTaskStatusChart.update();
            }
        };

        var applyTeamMemberHeader = function (result) {
            if (!result || !result.success || !result.user) {
                return;
            }

            currentMemberId = String(result.user.id);
            $("#profile-member-name").text(result.user.name);
            $("#profile-image-preview").attr("src", result.user.image);
            $("#profile-member-email").text(result.user.email);
            $("#profile-member-phone").text(result.user.phone);

            if (result.user.job_title) {
                $("#profile-member-job").text(result.user.job_title);
                $("#profile-member-job-wrap").show();
            } else {
                $("#profile-member-job-wrap").hide();
            }

            if (result.chart) {
                updateMemberTaskChart(result.chart);
            }

            if (typeof result.widgets_html !== "undefined") {
                $("#team-member-cover-widgets").html(result.widgets_html);
            }

            if ($("#member-monthly-points").length) {
                $("#member-monthly-points").data("default-user", currentMemberId);
                $("#member-monthly-points").find(".task-points-month-filter").trigger("change");
            }

            $("#team-member-view-tabs a[href]").each(function () {
                var href = $(this).attr("href") || "";
                if (href.indexOf("team_members/") !== -1) {
                    $(this).attr("href", href.replace(/\/\d+(\/?)$/, "/" + currentMemberId + "$1"));
                }
            });

            if (window.history && window.history.replaceState) {
                var nextUrl = window.location.href.replace(/team_members\/view\/\d+/, "team_members/view/" + currentMemberId);
                window.history.replaceState({}, "", nextUrl);
            }
        };

        var loadTeamMemberHeader = function (userId) {
            if (!userId || String(userId) === String(currentMemberId)) {
                return;
            }

            $.ajax({
                url: "<?php echo_uri("team_members/profile_header_data"); ?>/" + userId,
                type: "GET",
                dataType: "json",
                success: applyTeamMemberHeader
            });
        };

        $(document).on("change", ".team-member-view-page #tab-tasks-info [name='specific_user_id'], .team-member-view-page #tab-tasks-kanban-info [name='specific_user_id']", function () {
            var userId = $(this).val();
            if (!userId || syncingMemberFilter) {
                return;
            }

            syncingMemberFilter = true;
            $(".team-member-view-page [name='specific_user_id']").not(this).each(function () {
                if ($(this).val() !== userId) {
                    $(this).val(userId).trigger("change");
                }
            });
            syncingMemberFilter = false;

            loadTeamMemberHeader(userId);
        });

        if (typeof initMonthlyTaskPointsWidget === "function") {
            initMonthlyTaskPointsWidget("member-monthly-points");
        }

    });
</script>