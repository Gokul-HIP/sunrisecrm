<div id="page-content" class="page-wrapper clearfix">
    <div class="row">
        <div class="col-sm-3 col-lg-2">
            <?php
            $tab_view['active_tab'] = "task_points_rules";
            echo view("settings/tabs", $tab_view);
            ?>
        </div>

        <div class="col-sm-9 col-lg-10">
            <div class="card">
                <div class="page-title clearfix">
                    <h4><?php echo app_lang('task_points_rules'); ?></h4>
                    <div class="title-button-group">
                        <?php echo modal_anchor(get_uri("task_rules/modal_form"), "<i data-feather='plus-circle' class='icon-16'></i> " . app_lang('add_task_rule'), array("class" => "btn btn-default", "title" => app_lang('add_task_rule'))); ?>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="task-rules-table" class="display" cellspacing="0" width="100%">
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
    $(document).ready(function () {
        var deptDropdown = [
            {id: "", text: "- <?php echo app_lang('department'); ?> -"},
            <?php
            foreach (task_rules_departments() as $d) {
                echo '{id: "' . $d . '", text: "' . $d . '"},';
            }
            ?>
        ];

        $("#task-rules-table").appTable({
            source: '<?php echo_uri("task_rules/list_data") ?>',
            filterDropdown: [
                {name: "department", class: "w200", options: deptDropdown}
            ],
            columns: [
                {title: '<?php echo app_lang("department"); ?>', "class": "w15p"},
                {title: '<?php echo app_lang("sub_department"); ?>', "class": "w15p"},
                {title: '<?php echo app_lang("group_name"); ?>', "class": "w15p"},
                {title: '<?php echo app_lang("task"); ?>', "class": "all"},
                {title: '<?php echo app_lang("keywords"); ?>', "class": "w20p"},
                {title: '<?php echo app_lang("points"); ?>', "class": "w10p text-center"},
                {title: '<?php echo app_lang("time"); ?>', "class": "w10p text-center"},
                {title: '<i data-feather="menu" class="icon-16"></i>', "class": "text-center option w100"}
            ],
            printColumns: [0, 1, 2, 3, 4, 5, 6],
            xlsColumns: [0, 1, 2, 3, 4, 5, 6]
        });
    });
</script>
