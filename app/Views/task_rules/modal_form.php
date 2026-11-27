<?php echo form_open(get_uri("task_rules/save"), array("id" => "task-rule-form", "class" => "general-form", "role" => "form")); ?>
<div class="modal-body clearfix">
    <div class="container-fluid">
        <input type="hidden" name="id" value="<?php echo $model_info->id; ?>" />

        <div class="form-group">
            <div class="row">
                <label for="department" class="col-md-3"><?php echo app_lang('department'); ?></label>
                <div class="col-md-9">
                    <?php
                    $dept_options = array("" => "- " . app_lang('department') . " -");
                    foreach (task_rules_departments() as $d) {
                        $dept_options[$d] = $d;
                    }
                    echo form_dropdown("department", $dept_options, array($model_info->department), "id='modal_rule_department' class='form-control select2' data-rule-required='true' data-msg-required='" . app_lang('field_required') . "'");
                    ?>
                </div>
            </div>
        </div>

        <div class="form-group" id="modal_rule_sub_dept_wrap" style="<?php echo ($model_info->department === 'Digital Marketing') ? '' : 'display: none;'; ?>">
            <div class="row">
                <label for="sub_department" class="col-md-3"><?php echo app_lang('sub_department'); ?></label>
                <div class="col-md-9">
                    <?php
                    $sub_dept_options = array("" => "-");
                    foreach (task_rules_sub_departments() as $sd) {
                        $sub_dept_options[$sd] = $sd;
                    }
                    echo form_dropdown("sub_department", $sub_dept_options, array($model_info->sub_department), "id='modal_rule_sub_department' class='form-control select2'");
                    ?>
                </div>
            </div>
        </div>

        <div class="form-group">
            <div class="row">
                <label for="group_name" class="col-md-3"><?php echo app_lang('group_name'); ?></label>
                <div class="col-md-9">
                    <?php
                    echo form_input(array(
                        "id" => "group_name",
                        "name" => "group_name",
                        "value" => $model_info->group_name,
                        "class" => "form-control",
                        "placeholder" => app_lang('group_name')
                    ));
                    ?>
                </div>
            </div>
        </div>

        <div class="form-group">
            <div class="row">
                <label for="task" class="col-md-3"><?php echo app_lang('task'); ?></label>
                <div class="col-md-9">
                    <?php
                    echo form_input(array(
                        "id" => "task",
                        "name" => "task",
                        "value" => $model_info->task,
                        "class" => "form-control",
                        "placeholder" => app_lang('task'),
                        "data-rule-required" => true,
                        "data-msg-required" => app_lang("field_required"),
                    ));
                    ?>
                </div>
            </div>
        </div>

        <div class="form-group">
            <div class="row">
                <label for="keywords" class="col-md-3"><?php echo app_lang('keywords'); ?></label>
                <div class="col-md-9">
                    <?php
                    echo form_textarea(array(
                        "id" => "keywords",
                        "name" => "keywords",
                        "value" => $model_info->keywords,
                        "class" => "form-control",
                        "placeholder" => app_lang('keywords')
                    ));
                    ?>
                </div>
            </div>
        </div>

        <div class="form-group">
            <div class="row">
                <label for="points_min" class="col-md-3"><?php echo app_lang('points_min'); ?></label>
                <div class="col-md-3">
                    <?php
                    echo form_input(array(
                        "id" => "points_min",
                        "name" => "points_min",
                        "value" => $model_info->points_min,
                        "class" => "form-control",
                        "type" => "number",
                        "placeholder" => "MIN"
                    ));
                    ?>
                </div>
                <label for="points_max" class="col-md-3"><?php echo app_lang('points_max'); ?></label>
                <div class="col-md-3">
                    <?php
                    echo form_input(array(
                        "id" => "points_max",
                        "name" => "points_max",
                        "value" => $model_info->points_max,
                        "class" => "form-control",
                        "type" => "number",
                        "placeholder" => "MAX"
                    ));
                    ?>
                </div>
            </div>
        </div>

        <div class="form-group">
            <div class="row">
                <label for="minutes_min" class="col-md-3"><?php echo app_lang('minutes_min'); ?></label>
                <div class="col-md-3">
                    <?php
                    echo form_input(array(
                        "id" => "minutes_min",
                        "name" => "minutes_min",
                        "value" => $model_info->minutes_min,
                        "class" => "form-control",
                        "type" => "number",
                        "placeholder" => "MIN"
                    ));
                    ?>
                </div>
                <label for="minutes_max" class="col-md-3"><?php echo app_lang('minutes_max'); ?></label>
                <div class="col-md-3">
                    <?php
                    echo form_input(array(
                        "id" => "minutes_max",
                        "name" => "minutes_max",
                        "value" => $model_info->minutes_max,
                        "class" => "form-control",
                        "type" => "number",
                        "placeholder" => "MAX"
                    ));
                    ?>
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
        $("#task-rule-form").appForm({
            onSuccess: function (result) {
                $("#task-rules-table").appTable({newData: result.data, dataId: result.id});
            }
        });

        $("#modal_rule_department, #modal_rule_sub_department").select2();

        $("#modal_rule_department").on("change", function() {
            if ($(this).val() === "Digital Marketing") {
                $("#modal_rule_sub_dept_wrap").slideDown(150);
            } else {
                $("#modal_rule_sub_dept_wrap").slideUp(150);
                $("#modal_rule_sub_department").val("").trigger("change.select2");
            }
        });
    });
</script>
