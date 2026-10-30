<?php
$task_title = array();
$task_data = array();
$task_status_color = array();
foreach ($member_task_statuses as $task_status) {
    $task_title[] = $task_status->key_name ? app_lang($task_status->key_name) : $task_status->title;
    $task_data[] = (int) $task_status->total;
    $task_status_color[] = $task_status->color;
}
?>
<div class="team-member-task-chart">
    <div class="row align-items-center">
        <div class="col-5">
            <div class="team-member-donut-wrap">
                <canvas id="member-task-status-chart" height="140"></canvas>
                <span class="team-member-donut-total" id="member-task-chart-total"><?php echo array_sum($task_data); ?></span>
            </div>
        </div>
        <div class="col-7">
            <?php foreach ($member_task_statuses as $task_status) { ?>
                <div class="d-flex align-items-center mb-1 team-member-legend-row">
                    <span class="color-tag border-circle me-2 wh10" style="background-color: <?php echo $task_status->color; ?>;"></span>
                    <span class="chart-label"><?php echo $task_status->key_name ? app_lang($task_status->key_name) : $task_status->title; ?></span>
                    <span class="chart-count strong ms-auto"><?php echo $task_status->total; ?></span>
                </div>
            <?php } ?>
        </div>
    </div>
</div>

<style>
    .team-member-task-chart {
        padding: 12px 16px;
        min-height: 168px;
        background: transparent;
    }
    .team-member-task-chart .color-tag {
        display: inline-block;
        width: 10px;
        height: 10px;
        flex-shrink: 0;
    }
    .team-member-task-chart,
    .team-member-task-chart .chart-label,
    .team-member-task-chart .chart-count {
        color: #fff !important;
        font-size: 13px;
    }
    .cover-widget .team-member-task-chart {
        text-align: left;
    }
</style>

<script type="text/javascript">
    $(document).ready(function () {
        var chartEl = document.getElementById("member-task-status-chart");
        if (!chartEl || typeof Chart === "undefined") {
            return;
        }

        if (window.memberTaskStatusChart && typeof window.memberTaskStatusChart.destroy === "function") {
            window.memberTaskStatusChart.destroy();
        }

        window.memberTaskStatusChart = new Chart(chartEl, {
            type: "doughnut",
            data: {
                labels: <?php echo json_encode($task_title); ?>,
                datasets: [{
                    data: <?php echo json_encode($task_data); ?>,
                    backgroundColor: <?php echo json_encode($task_status_color); ?>,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutoutPercentage: 87,
                legend: {
                    display: false
                },
                tooltips: {
                    callbacks: {
                        title: function (tooltipItem, data) {
                            return data.labels[tooltipItem[0].index];
                        },
                        label: function () {
                            return "";
                        },
                        afterLabel: function (tooltipItem, data) {
                            var dataset = data.datasets[0];
                            var total = 0;
                            $.each(dataset.data, function (i, value) {
                                total += parseInt(value, 10) || 0;
                            });
                            if (!total) {
                                return "(0%)";
                            }
                            var value = dataset.data[tooltipItem.index];
                            return "(" + Math.round((value / total) * 100) + "%)";
                        }
                    }
                },
                animation: {
                    animateScale: true
                }
            }
        });
    });
</script>
