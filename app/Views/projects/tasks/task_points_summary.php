<div id="<?php echo isset($summary_id) ? $summary_id : "task-points-summary"; ?>" class="task-points-summary">
    <div class="task-points-summary-dates hide"></div>
    <div class="task-points-summary-values">
        <div class="task-points-summary-item">
            <span class="task-points-summary-label"><?php echo app_lang("total_points"); ?></span>
            <span class="task-points-given-value points-zero">0</span>
        </div>
        <div class="task-points-summary-item">
            <span class="task-points-summary-label"><?php echo app_lang("total_score"); ?></span>
            <span class="task-points-gained-value points-zero">0</span>
        </div>
    </div>
</div>
