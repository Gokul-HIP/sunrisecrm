<?php
$running_timers = isset($running_timers) ? $running_timers : array();
if (!$running_timers || !count($running_timers)) {
    return;
}
?>
<div class="task-running-timers mb10" id="task-running-timers-<?php echo (int) $model_info->id; ?>">
    <?php foreach ($running_timers as $timer) {
        $name = isset($timer->user_name) ? trim($timer->user_name) : "";
        if ($name === "") {
            $name = "User";
        }
        $avatar = get_avatar(isset($timer->user_image) ? $timer->user_image : "");
        $elapsed_sec = get_open_timer_elapsed_seconds($timer->start_time);
        $elapsed_label = format_open_timer_elapsed($elapsed_sec);
        $started_local = format_open_timer_started_local($timer->start_time);
        $started_full = format_to_datetime($timer->start_time);
        ?>
        <div class="task-running-timer d-flex align-items-center flex-wrap mb5"
             data-elapsed-seconds="<?php echo (int) $elapsed_sec; ?>"
             style="gap:8px;">
            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#dc3545;flex-shrink:0;"></span>
            <span class="avatar avatar-xs" style="flex-shrink:0;"><img src="<?php echo $avatar; ?>" alt="..."></span>
            <strong><?php echo htmlspecialchars($name); ?></strong>
            <span class="text-danger"><?php echo app_lang("timer_running"); ?></span>
            <span class="text-off" title="<?php echo htmlspecialchars($started_full); ?>"><?php echo app_lang("started_at"); ?> <?php echo htmlspecialchars($started_local); ?></span>
            <span class="task-running-timer-elapsed badge bg-danger"><?php echo htmlspecialchars($elapsed_label); ?></span>
        </div>
    <?php } ?>
</div>
<script type="text/javascript">
    (function () {
        var root = document.getElementById("task-running-timers-<?php echo (int) $model_info->id; ?>");
        if (!root || root.getAttribute("data-tick-bound") === "1") {
            return;
        }
        root.setAttribute("data-tick-bound", "1");

        function formatElapsed(sec) {
            sec = Math.max(0, Math.floor(sec));
            if (sec >= 86400) {
                var d = Math.floor(sec / 86400);
                var h = Math.floor((sec % 86400) / 3600);
                return d + "d" + (h > 0 ? (" " + h + "h") : "");
            }
            var hh = Math.floor(sec / 3600);
            var mm = Math.floor((sec % 3600) / 60);
            var ss = sec % 60;
            return (hh < 10 ? "0" : "") + hh + ":" + (mm < 10 ? "0" : "") + mm + ":" + (ss < 10 ? "0" : "") + ss;
        }

        var rows = root.querySelectorAll(".task-running-timer");
        var startedAt = Date.now();
        setInterval(function () {
            var drift = (Date.now() - startedAt) / 1000;
            for (var i = 0; i < rows.length; i++) {
                var base = parseInt(rows[i].getAttribute("data-elapsed-seconds") || "0", 10);
                var el = rows[i].querySelector(".task-running-timer-elapsed");
                if (el) {
                    el.textContent = formatElapsed(base + drift);
                }
            }
        }, 1000);
    })();
</script>
