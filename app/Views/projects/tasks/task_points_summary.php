<div id="<?php echo isset($summary_id) ? $summary_id : "task-points-summary"; ?>" class="task-points-summary">
    <div class="task-points-summary-dates hide"></div>
    <div class="task-points-summary-values">
        <div class="task-points-summary-item">
            <span class="task-points-summary-label"><?php echo app_lang("earned_score") ?: "Earned score"; ?></span>
            <span class="task-points-gained-value task-total-score-value points-zero">0</span>
        </div>
        <div class="task-points-summary-item">
            <span class="task-points-summary-label">Solo</span>
            <span class="task-points-metric-wrap">
                <span class="task-solo-earned-value points-zero">0</span>
                <span class="task-points-sep">/</span>
                <span class="task-solo-overall-value text-muted">0</span>
            </span>
        </div>
        <div class="task-points-summary-item">
            <span class="task-points-summary-label">Collab</span>
            <span class="task-points-metric-wrap">
                <span class="task-collab-earned-value points-zero">0</span>
                <span class="task-points-sep">/</span>
                <span class="task-collab-overall-value text-muted">0</span>
            </span>
        </div>
        <div class="task-points-summary-item hide">
            <span class="task-points-summary-label"><?php echo app_lang("total_points"); ?></span>
            <span class="task-points-given-value points-zero">0</span>
        </div>
    </div>
</div>
