<div id="page-content" class="page-wrapper clearfix">
    <div class="card">
        <div class="page-title clearfix">
            <h1><?php echo app_lang("tasks_in_review_queue"); ?></h1>
            <div class="title-button-group">
                <?php
                if ($is_admin) {
                    $mine_active = ($filter !== "all") ? "active" : "";
                    $all_active = ($filter === "all") ? "active" : "";
                    echo '<div class="btn-group me-2" role="group">';
                    echo anchor(get_uri("projects/tasks_in_review/mine"), app_lang("my_review_queue"), array("class" => "btn btn-default $mine_active"));
                    echo anchor(get_uri("projects/tasks_in_review/all"), app_lang("all_review_queue"), array("class" => "btn btn-default $all_active"));
                    echo '</div>';
                }
                ?>
                <button type="button" id="clear-review-filters-top" class="btn btn-default">
                    <i data-feather="rotate-ccw" class="icon-16"></i> <?php echo app_lang("clear_filters"); ?>
                </button>
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

<!-- Modal for Approve Task (optional message + files via Messages uploader) -->
<div class="modal fade" id="approve-task-modal" tabindex="-1" role="dialog" aria-labelledby="approveTaskModalLabel">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div id="approve-task-dropzone" class="post-dropzone">
                <div class="modal-header">
                    <h4 class="modal-title" id="approveTaskModalLabel"><?php echo app_lang("approve_task_modal_title"); ?></h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="approve-task-id" value="" />
                    <div class="form-group">
                        <label for="approve-task-message" class="mb5"><?php echo app_lang("optional_message_to_assignee"); ?></label>
                        <textarea id="approve-task-message" name="approval_message" class="form-control" rows="4" placeholder="<?php echo app_lang("optional_message_to_assignee"); ?>..."></textarea>
                    </div>
                    <div class="form-group">
                        <?php echo view("includes/dropzone_preview"); ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-default upload-file-button float-start me-auto btn-sm round" type="button" style="color:#7988a2"><i data-feather='camera' class='icon-16'></i> <?php echo app_lang("upload_file"); ?></button>
                    <button type="button" class="btn btn-default" id="approve-task-cancel-btn" data-bs-dismiss="modal"><?php echo app_lang("cancel"); ?></button>
                    <button type="button" id="submit-approve-task-btn" class="btn btn-success"><?php echo app_lang("approve"); ?></button>
                </div>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
    $(document).ready(function () {
        var currentFilter = "<?php echo $filter; ?>";
        var sourceUrl = "<?php echo_uri('projects/tasks_in_review_list_data'); ?>/" + currentFilter;
        var approveSubmitting = false;
        var approveDropzone = null;

        $("#tasks-in-review-table").appTable({
            source: sourceUrl,
            serverSide: true,
            order: [[0, "desc"]],
            filterDropdown: [
                {name: "specific_user_id", class: "w200", options: <?php echo $team_members_dropdown; ?>, searchable: true},
                {name: "project_id", class: "w200", options: <?php echo $projects_dropdown; ?>},
                {name: "submitted_filter", class: "w150", options: <?php echo $submitted_dropdown; ?>}
            ],
            rangeDatepicker: [{startDate: {name: "from_date", value: ""}, endDate: {name: "to_date", value: ""}, showClearButton: true}],
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
                initFeather();
            }
        });

        // Clear filters functionality
        var clearFilters = function (e) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            var settings = window.InstanceCollection && window.InstanceCollection["tasks-in-review-table"];
            if (!settings) {
                return;
            }

            // Reset filterParams
            settings.filterParams.specific_user_id = "";
            settings.filterParams.project_id = "";
            settings.filterParams.submitted_filter = "";
            settings.filterParams.from_date = "";
            settings.filterParams.to_date = "";
            settings.filterParams.start_date = "";
            settings.filterParams.end_date = "";

            // Reset UI elements
            var $wrapper = $("#tasks-in-review-table").closest(".dataTables_wrapper");
            $wrapper.find("[name='specific_user_id'], [name='project_id'], [name='submitted_filter']").each(function () {
                var $el = $(this);
                if ($el.data("select2")) {
                    $el.val("").trigger("change.select2");
                } else {
                    $el.val("");
                }
            });

            // Reset datepickers
            $wrapper.find(".input-daterange input, .datepicker-custom-selector input").val("");
            $wrapper.find(".datepicker-custom-selector button").each(function () {
                var defaultText = $(this).attr("title") || "";
                if (defaultText) {
                    $(this).html(defaultText + "<span class='ml10 dropdown-toggle'></span>");
                }
            });

            // Reset search input if present
            var $search = $wrapper.find("input[type=search], .custom-filter-search");
            if ($search.length) {
                $search.val("");
                if (settings.search && settings.search.name) {
                    settings.filterParams[settings.search.name] = "";
                }
            }

            // Clear URL query parameters
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', window.location.pathname);
            }

            $("#tasks-in-review-table").appTable({reload: true});
        };

        $(document).on("click", "#clear-review-filters-top, #clear-review-filters-btn", clearFilters);

        // Messages upload endpoints + same Dropzone component as Messages feature.
        // Init once per modal show; destroy on hide so Upload File works every open.
        var uploadUrl = "<?php echo get_uri("messages/upload_file"); ?>";
        var validationUrl = "<?php echo get_uri("messages/validate_message_file"); ?>";
        var approveDropzonePreviewHtml = (function () {
            var $host = $("#approve-task-dropzone .modal-body .form-group").has(".post-file-dropzone-scrollbar").first();
            if (!$host.length) {
                $host = $("#approve-task-dropzone .modal-body .form-group").last();
            }
            return $host.length ? $host.html() : "";
        })();

        function getApprovePreviewHost() {
            var $host = $("#approve-task-dropzone .modal-body .form-group").has(".post-file-dropzone-scrollbar").first();
            if (!$host.length) {
                $host = $("#approve-task-dropzone .modal-body .form-group").last();
            }
            return $host;
        }

        function destroyApproveDropzone() {
            var $dz = $("#approve-task-dropzone");
            var instances = [];
            if (approveDropzone) {
                instances.push(approveDropzone);
            }
            try {
                if (typeof Dropzone !== "undefined" && $dz.length && $dz[0].dropzone) {
                    instances.push($dz[0].dropzone);
                }
            } catch (e) { /* ignore */ }

            instances.forEach(function (dz) {
                try {
                    if (dz && typeof dz.removeAllFiles === "function") {
                        dz.removeAllFiles(true);
                    }
                    if (dz && typeof dz.destroy === "function") {
                        dz.destroy();
                    }
                } catch (e) { /* ignore stale instance */ }
            });
            approveDropzone = null;
            try {
                if ($dz[0]) {
                    delete $dz[0].dropzone;
                }
            } catch (e) { /* ignore */ }

            $dz.find("input[name='file_names[]'], input[name='file_sizes[]']").remove();
            $dz.find("input.dz-hidden-input").remove();
            $(document).find("input.dz-hidden-input").filter(function () {
                return $(this).closest("#approve-task-dropzone").length === 0 && !$(this).closest(".dropzone, .post-dropzone").length;
            }).remove();
            $dz.removeClass("dz-clickable dz-started dz-max-files-reached");

            // Restore pristine preview markup (attachDropzoneWithForm removes the template row)
            if (approveDropzonePreviewHtml) {
                getApprovePreviewHost().html(approveDropzonePreviewHtml);
            }
            $dz.find(".upload-file-button").prop("disabled", false).removeAttr("disabled").removeAttr("id");
        }

        function initApproveDropzone() {
            destroyApproveDropzone();
            if (typeof attachDropzoneWithForm !== "function") {
                return;
            }
            try {
                approveDropzone = attachDropzoneWithForm("#approve-task-dropzone", uploadUrl, validationUrl);
            } catch (e) {
                // Retry once after a hard markup restore if a stale instance blocked attach
                destroyApproveDropzone();
                approveDropzone = attachDropzoneWithForm("#approve-task-dropzone", uploadUrl, validationUrl);
            }
            $("#approve-task-dropzone").find(".upload-file-button").prop("disabled", false).removeAttr("disabled");
        }

        function resetApproveModalFields() {
            $("#approve-task-id").val("");
            $("#approve-task-message").val("");
            $("#approve-task-cancel-btn, #submit-approve-task-btn").prop("disabled", false);
            $("#approve-task-dropzone").find(".upload-file-button").prop("disabled", false).removeAttr("disabled");
            approveSubmitting = false;
        }

        function setApproveButtonsDisabled(disabled) {
            $("#approve-task-cancel-btn, #submit-approve-task-btn").prop("disabled", !!disabled);
            $("#approve-task-dropzone").find(".upload-file-button").prop("disabled", !!disabled);
        }

        // Open Approve modal (optional award confirm first)
        $(document).on("click", ".approve-task-btn", function () {
            var $btn = $(this);
            var taskId = $btn.data("id");
            if (!taskId || approveSubmitting) return;

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

            resetApproveModalFields();
            $("#approve-task-id").val(taskId);
            $("#approve-task-modal").modal("show");
        });

        // Tear down as soon as close starts (before transition) so a quick re-open
        // cannot race a late hidden.bs.modal destroy against a fresh init.
        $("#approve-task-modal").on("hide.bs.modal", function () {
            destroyApproveDropzone();
        });

        $("#approve-task-modal").on("shown.bs.modal", function () {
            initApproveDropzone();
            if (typeof feather !== "undefined" && feather.replace) {
                feather.replace();
            }
        });

        $("#approve-task-modal").on("hidden.bs.modal", function () {
            destroyApproveDropzone();
            if (!approveSubmitting) {
                resetApproveModalFields();
            } else {
                // Approval still in flight: keep submit guard, but never leave upload stuck disabled
                $("#approve-task-dropzone").find(".upload-file-button").prop("disabled", false).removeAttr("disabled");
            }
        });

        // Submit Approve (message/files optional)
        $("#submit-approve-task-btn").on("click", function () {
            var taskId = $("#approve-task-id").val();
            if (!taskId || approveSubmitting) return;

            approveSubmitting = true;
            setApproveButtonsDisabled(true);
            appLoader.show();

            var formData = new FormData();
            formData.append("approval_message", $("#approve-task-message").val() || "");
            if (typeof AppHelper !== "undefined" && AppHelper.csrfTokenName) {
                formData.append(AppHelper.csrfTokenName, AppHelper.csrfHash);
            }
            $("#approve-task-dropzone").find("input[name='file_names[]']").each(function () {
                formData.append("file_names[]", $(this).val());
            });
            $("#approve-task-dropzone").find("input[name='file_sizes[]']").each(function () {
                formData.append("file_sizes[]", $(this).val() || "");
            });

            $.ajax({
                url: "<?php echo_uri('projects/approve_task_review'); ?>/" + taskId,
                type: "POST",
                data: formData,
                processData: false,
                contentType: false,
                dataType: "json",
                success: function (response) {
                    appLoader.hide();
                    $("#approve-task-modal").modal("hide");
                    approveSubmitting = false;
                    resetApproveModalFields();
                    if (response.success) {
                        if (response.message_warning) {
                            appAlert.warning(response.message, {duration: 8000});
                        } else {
                            appAlert.success(response.message, {duration: 5000});
                        }
                        $("#tasks-in-review-table").appTable({reload: true, keepPage: true});
                    } else {
                        appAlert.error(response.message);
                    }
                },
                error: function () {
                    appLoader.hide();
                    approveSubmitting = false;
                    setApproveButtonsDisabled(false);
                    appAlert.error("<?php echo app_lang('error_occurred'); ?>");
                }
            });
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
                        $("#tasks-in-review-table").appTable({reload: true, keepPage: true});
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
