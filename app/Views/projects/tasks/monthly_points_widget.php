<?php
$widget_id = isset($widget_id) ? $widget_id : "monthly-task-points-widget";
$theme = isset($theme) ? $theme : "light";
$default_user_id = isset($default_user_id) ? $default_user_id : (isset($login_user->id) ? $login_user->id : 0);
$month_options = get_task_points_month_options(24);
$current_month = parse_task_points_month();
$initial_points_raw = get_user_monthly_task_points($default_user_id, $current_month["year"], $current_month["month"]);
$initial_points = format_task_point_value($initial_points_raw);
$user_selector = isset($user_selector) ? $user_selector : "[name='specific_user_id']";
?>

<div id="<?php echo $widget_id; ?>" class="monthly-task-points-widget monthly-task-points-<?php echo $theme; ?>" data-default-user="<?php echo $default_user_id; ?>" data-user-selector="<?php echo htmlspecialchars($user_selector); ?>">
    <label class="monthly-task-points-month-label" for="<?php echo $widget_id; ?>-month"><?php echo app_lang("task_points_month"); ?></label>
    <select id="<?php echo $widget_id; ?>-month" class="form-control task-points-month-filter">
        <?php foreach ($month_options as $option) { ?>
            <option value="<?php echo $option["value"]; ?>" <?php echo ($option["value"] === $current_month["value"]) ? "selected" : ""; ?>>
                <?php echo $option["text"]; ?>
            </option>
        <?php } ?>
    </select>
    <div class="monthly-task-points-total">
        <div class="task-points-month-value strong <?php echo get_task_point_highlight_class($initial_points_raw); ?>"><?php echo $initial_points; ?></div>
        <div class="task-points-month-caption small"><?php echo app_lang("selected_month_task_points"); ?></div>
    </div>
</div>
