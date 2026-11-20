<?php
$cards = array(
    "view" => array("title" => "cc_perm_view", "help" => "cc_perm_view_help"),
    "add" => array("title" => "cc_perm_add", "help" => "cc_perm_add_help"),
    "edit" => array("title" => "cc_perm_edit", "help" => "cc_perm_edit_help"),
    "delete" => array("title" => "cc_perm_delete", "help" => "cc_perm_delete_help"),
    "manage_options" => array("title" => "cc_perm_manage_options", "help" => "cc_perm_manage_options_help"),
    "add_client" => array("title" => "cc_perm_add_client", "help" => "cc_perm_add_client_help"),
    "add_column" => array("title" => "cc_perm_add_column", "help" => "cc_perm_add_column_help")
);
?>
<div id="page-content" class="page-wrapper clearfix">
    <div class="row">
        <div class="col-sm-3 col-lg-2">
            <?php
            $tab_view['active_tab'] = "content_calendar_access";
            echo view("settings/tabs", $tab_view);
            ?>
        </div>

        <div class="col-sm-9 col-lg-10">
            <div class="card">
                <div class="card-header">
                    <h4><?php echo esc(cc_lang("content_calendar_access")); ?></h4>
                    <div class="text-off mt5"><?php echo esc(cc_lang("cc_admins_full_access")); ?></div>
                </div>
                <div class="card-body">
                    <div class="alert alert-info mb0">
                        <i data-feather="info" class="icon-16"></i>
                        <?php echo esc(cc_lang("cc_implied_view_note")); ?>
                    </div>
                </div>
            </div>

            <div class="row">
                <?php foreach ($cards as $key => $card) { ?>
                    <div class="col-lg-6 mb20">
                        <div class="card cc-access-card h-100" data-key="<?php echo esc($key, 'attr'); ?>">
                            <div class="card-header d-flex align-items-center justify-content-between">
                                <h4 class="mb0"><?php echo esc(cc_lang($card["title"])); ?></h4>
                                <span class="badge bg-primary cc-access-count" data-count-for="<?php echo esc($key, 'attr'); ?>">0 <?php echo esc(cc_lang("cc_selected")); ?></span>
                            </div>
                            <div class="card-body">
                                <p class="text-off"><?php echo esc(cc_lang($card["help"])); ?></p>

                                <select multiple="multiple" class="cc-access-select" id="cc-access-<?php echo esc($key, 'attr'); ?>" style="width:100%" data-placeholder="<?php echo esc(cc_lang('cc_search_staff'), 'attr'); ?>">
                                    <?php foreach ($staff as $user) { ?>
                                        <option value="<?php echo (int) $user["id"]; ?>"
                                                data-avatar="<?php echo esc($user["avatar"], 'attr'); ?>"
                                                data-job="<?php echo esc($user["job_title"], 'attr'); ?>"
                                                <?php echo in_array($user["id"], $selected[$key], true) ? "selected" : ""; ?>>
                                                    <?php echo esc($user["name"]); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="card-footer">
                                <button type="button" class="btn btn-default cc-access-all"><?php echo esc(cc_lang("cc_select_all")); ?></button>
                                <button type="button" class="btn btn-default cc-access-clear"><?php echo esc(cc_lang("cc_clear")); ?></button>
                                <button type="button" class="btn btn-primary float-end cc-access-save"><span data-feather="check-circle" class="icon-16"></span> <?php echo esc(cc_lang("save")); ?></button>
                            </div>
                        </div>
                    </div>
                <?php } ?>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
    $(document).ready(function () {
        var savedMessage = <?php echo json_encode(cc_lang("cc_saved"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var selectedLabel = <?php echo json_encode(cc_lang("cc_selected"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var errorMessage = <?php echo json_encode(cc_lang("cc_error"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function formatUser(option) {
            if (!option.id) {
                return option.text;
            }

            var $el = $(option.element);
            var $row = $("<span class='d-flex align-items-center'></span>");
            var avatar = $el.data("avatar");
            if (avatar) {
                $row.append($("<img class='avatar-xs rounded-circle mr10' style='width:24px;height:24px;margin-right:8px;' alt=''>").attr("src", avatar));
            }

            var $text = $("<span></span>").text(option.text);
            var job = $el.data("job");
            if (job) {
                $text.append($("<small class='text-off'></small>").text(" - " + job));
            }

            $row.append($text);
            return $row;
        }

        function updateCount($card) {
            var count = $card.find(".cc-access-select").val();
            count = count ? count.length : 0;
            $card.find(".cc-access-count").text(count + " " + selectedLabel);
        }

        $(".cc-access-card").each(function () {
            var $card = $(this);
            var $select = $card.find(".cc-access-select");

            $select.select2({
                closeOnSelect: false,
                templateResult: formatUser,
                templateSelection: function (option) {
                    return option.text;
                }
            });

            $select.on("change", function () {
                updateCount($card);
            });

            updateCount($card);

            $card.find(".cc-access-all").on("click", function () {
                var all = [];
                $select.find("option").each(function () {
                    all.push($(this).val());
                });
                $select.val(all).trigger("change");
            });

            $card.find(".cc-access-clear").on("click", function () {
                $select.val(null).trigger("change");
            });

            $card.find(".cc-access-save").on("click", function () {
                var $button = $(this);
                $button.prop("disabled", true);

                var postData = {
                    permission_key: $card.data("key"),
                    user_ids: $select.val() || []
                };

                $.ajax({
                    url: "<?php echo_uri("settings/save_content_calendar_access"); ?>",
                    type: "POST",
                    dataType: "json",
                    data: postData,
                    success: function (result) {
                        $button.prop("disabled", false);
                        if (result && result.success) {
                            appAlert.success(savedMessage, {duration: 4000});
                        } else {
                            appAlert.error((result && result.message) || errorMessage);
                        }
                    },
                    error: function () {
                        $button.prop("disabled", false);
                        appAlert.error(errorMessage);
                    }
                });
            });
        });
    });
</script>
