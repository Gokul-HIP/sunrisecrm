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
?>
<div class="col-md-12 mb15 cc-task-details-content-block">
    <?php if (!empty($link_info->is_linked)) { ?>
        <div class="p-2 rounded d-flex align-items-center flex-wrap gap-2" style="background: #f8fafc; border: 1px solid #e2e8f0;">
            <?php if (!empty($link_info->content_type)) { ?>
                <span class="cc-pill" style="background: <?php echo esc($link_info->type_color); ?>22; color: <?php echo esc($link_info->type_color); ?>; font-weight: 700; font-size: 11px; padding: 2px 8px; border-radius: 999px;">
                    <?php echo esc($link_info->content_type); ?>
                </span>
            <?php } ?>
            <span class="text-muted font-13">•</span>
            <span class="strong text-dark font-13"><?php echo esc($link_info->calendar_name); ?></span>
            <span class="text-muted font-13">•</span>
            <span class="text-muted font-13"><?php echo esc($link_info->formatted_date); ?></span>
            <span class="text-muted font-13">•</span>
            <span class="text-dark font-13"><?php echo esc($link_info->title); ?></span>
            <a href="<?php echo esc($link_info->link_url); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-default btn-sm ms-auto cc-btn-open-calendar">
                <i data-feather="calendar" class="icon-14"></i> Open in Content Calendar
            </a>
        </div>
    <?php } else if (!empty($link_info->content_type)) { ?>
        <div class="d-flex align-items-center gap-2">
            <span class="cc-pill" style="background: <?php echo esc($link_info->type_color); ?>22; color: <?php echo esc($link_info->type_color); ?>; font-weight: 700; font-size: 11px; padding: 2px 8px; border-radius: 999px;">
                <?php echo esc($link_info->content_type); ?>
            </span>
        </div>
    <?php } ?>
</div>
<script type="text/javascript">
    if (window.feather) { feather.replace(); }
</script>
