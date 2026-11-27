<?php echo form_open(get_uri("team_groups/save"), array("id" => "team-group-form", "class" => "general-form", "role" => "form")); ?>
<div class="modal-body clearfix">
    <div class="container-fluid">
        <input type="hidden" name="id" value="<?php echo $model_info->id ? $model_info->id : ''; ?>" />
        
        <div class="form-group">
            <div class="row">
                <label for="title" class="col-md-3"><?php echo app_lang('group_name'); ?></label>
                <div class="col-md-9">
                    <?php
                    echo form_input(array(
                        "id" => "title",
                        "name" => "title",
                        "value" => $model_info->title ? $model_info->title : '',
                        "class" => "form-control",
                        "placeholder" => app_lang('group_name'),
                        "autofocus" => true,
                        "data-rule-required" => true,
                        "data-msg-required" => app_lang("field_required"),
                    ));
                    ?>
                </div>
            </div>
        </div>

        <div class="form-group">
            <div class="row">
                <label for="group_members_select" class="col-md-3"><?php echo app_lang('members'); ?></label>
                <div class="col-md-9">
                    <select name="members[]" id="group_members_select" class="form-control select2" multiple="multiple" data-placeholder="<?php echo app_lang('members'); ?>" style="width: 100%;">
                        <?php foreach ($staff_members as $member) { ?>
                            <?php
                            $full_name = trim($member->first_name . " " . $member->last_name);
                            if ($member->job_title) {
                                $full_name .= " (" . $member->job_title . ")";
                            }
                            $is_selected = in_array((int) $member->id, $selected_members, true) ? "selected='selected'" : "";
                            ?>
                            <option value="<?php echo (int) $member->id; ?>" <?php echo $is_selected; ?>>
                                <?php echo esc($full_name); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal-footer">
    <button type="button" class="btn btn-default" data-bs-dismiss="modal"><span data-feather="x" class="icon-16"></span> <?php echo app_lang('close'); ?></button>
    <button type="submit" class="btn btn-primary"><span data-feather="check-circle" class="icon-16"></span> <?php echo app_lang('save'); ?></button>
</div>
<?php echo form_close(); ?>

<script type="text/javascript">
    $(document).ready(function () {
        $("#team-group-form").appForm({
            onSuccess: function (result) {
                appAlert.success(result.message, {duration: 10000});

                // Update left menu immediately without manual refresh
                if (result.is_new) {
                    var $teamUl = $("#sidebar-menu li.main a[href*='team_groups']").closest('li.main').find('ul');
                    if ($teamUl.length) {
                        var newLi = $('<li><a href="' + result.view_url + '"><i data-feather="minus" width="12"></i> <span>' + result.title + '</span></a></li>');
                        $teamUl.append(newLi);
                        feather.replace();
                    }
                    window.location.href = result.view_url;
                } else {
                    var $groupLink = $("#sidebar-menu a[href*='team_groups/view/" + result.id + "']");
                    if ($groupLink.length) {
                        $groupLink.find('span').text(result.title);
                    }
                    location.reload();
                }
            }
        });

        $("#group_members_select").select2();

        setTimeout(function () {
            $("#title").focus();
        }, 200);
    });
</script>
