<div id="page-content" class="page-wrapper clearfix">
    <div class="card">
        <div class="page-title clearfix">
            <h1><?php echo app_lang("tasks_in_review_queue"); ?></h1>
            <div class="title-button-group">
                <?php
                if ($is_admin) {
                    $mine_active = ($filter !== "all") ? "active" : "";
                    $all_active = ($filter === "all") ? "active" : "";
                    echo '<div class="btn-group" role="group">';
                    echo anchor(get_uri("projects/tasks_in_review/mine"), app_lang("my_review_queue"), array("class" => "btn btn-default $mine_active"));
                    echo anchor(get_uri("projects/tasks_in_review/all"), app_lang("all_review_queue"), array("class" => "btn btn-default $all_active"));
                    echo '</div>';
                }
                ?>
            </div>
        </div>

        <div class="table-responsive">
            <table id="tasks-in-review-table" class="display" cellspacing="0" width="100%">
            </table>
        </div>
    </div>
</div>

<!-- Modal for Request Changes Comment -->
<div class="modal fade" id="request-changes-modal" tabindex="-1" role="dialog" aria-labelledby="requestChangesModalLabel">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title" id="requestChangesModalLabel"><?php echo app_lang("request_changes"); ?></h4>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="change-request-task-id" value="" />
                <div class="form-group">
                    <label for="change-request-comment" class="mb5"><?php echo app_lang("optional_review_comment"); ?></label>
                    <textarea id="change-request-comment" class="form-control" rows="4" placeholder="<?php echo app_lang("optional_review_comment"); ?>..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-bs-dismiss="modal"><?php echo app_lang("cancel"); ?></button>
                <button type="button" id="submit-request-changes-btn" class="btn btn-warning text-white"><?php echo app_lang("request_changes"); ?></button>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
    $(document).ready(function () {
        var currentFilter = "<?php echo $filter; ?>";
        var sourceUrl = "<?php echo_uri('projects/tasks_in_review_list_data'); ?>/" + currentFilter;

        $("#tasks-in-review-table").appTable({
            source: sourceUrl,
            serverSide: true,
            order: [[0, "desc"]],
            columns: [
                {title: '<?php echo app_lang("id"); ?>', "class": "w10p", order_by: "id"},
                {title: '<?php echo app_lang("title"); ?>', order_by: "title"},
                {title: '<?php echo app_lang("project"); ?>', "class": "w15p", order_by: "project"},
                {title: '<?php echo app_lang("assigned_to"); ?>', "class": "w15p", order_by: "assigned_to"},
                {title: '<?php echo app_lang("submitted_at"); ?>', "class": "w15p", order_by: "submitted_for_review_at"},
                {title: '<?php echo app_lang("deadline"); ?>', "class": "w15p", order_by: "deadline"},
                {title: '<?php echo app_lang("points"); ?>', "class": "w10p text-center", order_by: "points"},
                {title: '<i data-feather="menu" class="icon-16"></i>', "class": "text-center option w150"}
            ],
            onRanderComplete: function () {
                feather.replace();
            }
        });

        // Approve action — confirm when projected award is 0 or less than original
        $(document).on("click", ".approve-review-task-btn", function () {
            var $btn = $(this);
            var taskId = $btn.data("id");
            if (!taskId) return;

            var doApprove = function () {
                appLoader.show();
                $.ajax({
                    url: "<?php echo_uri('projects/approve_task_review'); ?>/" + taskId,
                    type: "POST",
                    dataType: "json",
                    success: function (response) {
                        appLoader.hide();
                        if (response.success) {
                            appAlert.success(response.message, {duration: 5000});
                            $("#tasks-in-review-table").appTable({reload: true});
                        } else {
                            appAlert.error(response.message);
                        }
                    },
                    error: function () {
                        appLoader.hide();
                        appAlert.error("<?php echo app_lang('error_occurred'); ?>");
                    }
                });
            };

            var needsConfirm = String($btn.data("confirm-needed")) === "1";
            if (needsConfirm) {
                var award = $btn.data("award") || "0";
                var original = $btn.data("original") || "0";
                var name = $btn.data("assignee-name") || "";
                var msg = "<?php echo app_lang('approve_award_confirm'); ?>";
                msg = msg.replace("%s", award).replace("%s", original).replace("%s", name);
                if (!confirm(msg)) {
                    return;
                }
            }
            doApprove();
        });

        // Open Request Changes modal
        $(document).on("click", ".request-changes-task-btn", function () {
            var taskId = $(this).data("id");
            $("#change-request-task-id").val(taskId);
            $("#change-request-comment").val("");
            $("#request-changes-modal").modal("show");
        });

        // Submit Request Changes
        $("#submit-request-changes-btn").on("click", function () {
            var taskId = $("#change-request-task-id").val();
            var comment = $("#change-request-comment").val();
            if (!taskId) return;

            appLoader.show();
            $.ajax({
                url: "<?php echo_uri('projects/request_task_changes'); ?>/" + taskId,
                type: "POST",
                data: {comment: comment},
                dataType: "json",
                success: function (response) {
                    appLoader.hide();
                    $("#request-changes-modal").modal("hide");
                    if (response.success) {
                        appAlert.success(response.message, {duration: 5000});
                        $("#tasks-in-review-table").appTable({reload: true});
                    } else {
                        appAlert.error(response.message);
                    }
                },
                error: function () {
                    appLoader.hide();
                    $("#request-changes-modal").modal("hide");
                    appAlert.error("<?php echo app_lang('error_occurred'); ?>");
                }
            });
        });
    });
</script>
