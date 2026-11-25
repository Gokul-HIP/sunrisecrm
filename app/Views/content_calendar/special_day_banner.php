<?php
/**
 * Content Calendar Special Day Dashboard Banner.
 *
 * Replaces the first widget row on staff dashboards when today has at least one distinct special day
 * and the dashboard banner setting is enabled.
 */
$bg_desktop = ROOTPATH . "assets/images/content_calendar/special_day_bg.jpg";
$bg_mobile = ROOTPATH . "assets/images/content_calendar/special_day_bg_mobile.jpg";
$has_desktop_img = file_exists($bg_desktop);
$has_mobile_img = file_exists($bg_mobile);
?>
<div id="cc-special-day-banner-row" class="clearfix row mb15" style="display:none;">
    <div class="col-12">
        <div class="card cc-special-day-banner-card position-relative overflow-hidden" id="cc-special-day-banner-card">
            <canvas id="cc-confetti-canvas"></canvas>
            <div class="cc-banner-inner d-flex align-items-center justify-content-between p20">
                <div class="cc-banner-left pe-3">
                    <div class="cc-banner-prefix text-white-50 text-uppercase small fw-bold mb-1">Today is</div>
                    <h3 class="cc-banner-title text-white mb-0 fw-bold"><span id="cc-special-day-text"></span><span id="cc-typewriter-cursor" class="cc-blinking-cursor">|</span></h3>
                </div>
                <div class="cc-banner-controls d-flex align-items-center flex-shrink-0 ms-auto">
                    <button type="button" class="btn btn-sm btn-outline-light me-2" id="cc-banner-show-cards"><i data-feather="grid" class="icon-14 me-1"></i> <?php echo app_lang("cc_show_my_cards", "Show my cards"); ?></button>
                    <button type="button" class="btn-close btn-close-white" id="cc-banner-close-btn" aria-label="Close" title="Close banner"></button>
                </div>
            </div>
        </div>
    </div>
</div>

<style id="cc-special-day-banner-css">
#cc-special-day-banner-card {
    border-radius: var(--bs-card-border-radius, 4px);
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    border: none;
    min-height: 110px;
    position: relative;
    overflow: hidden;
    color: #ffffff;
    /* CRM orange theme gradient fallback with high contrast */
    background: linear-gradient(135deg, #1f1412 0%, #431407 35%, #9a3412 70%, #ea580c 100%);
    background-size: cover;
    background-position: right center;
    background-repeat: no-repeat;
}

<?php if ($has_desktop_img) { ?>
#cc-special-day-banner-card {
    background-image: linear-gradient(to right, rgba(15, 10, 10, 0.92) 0%, rgba(25, 15, 12, 0.85) 45%, rgba(0, 0, 0, 0.4) 80%, rgba(0, 0, 0, 0.2) 100%), url('<?php echo base_url("assets/images/content_calendar/special_day_bg.jpg"); ?>');
}
<?php } ?>

<?php if ($has_mobile_img) { ?>
@media (max-width: 576px) {
    #cc-special-day-banner-card {
        background-image: linear-gradient(to bottom, rgba(15, 10, 10, 0.92) 0%, rgba(25, 15, 12, 0.85) 60%, rgba(0, 0, 0, 0.5) 100%), url('<?php echo base_url("assets/images/content_calendar/special_day_bg_mobile.jpg"); ?>');
    }
}
<?php } ?>

.cc-banner-inner {
    position: relative;
    z-index: 2;
    min-height: 110px;
}

.cc-banner-prefix {
    font-size: 13px;
    letter-spacing: 0.75px;
    opacity: 0.9;
    text-shadow: 0 1px 2px rgba(0,0,0,0.6);
}

.cc-banner-title {
    font-size: 24px;
    line-height: 1.3;
    letter-spacing: -0.2px;
    text-shadow: 0 2px 4px rgba(0,0,0,0.7);
    word-break: break-word;
}

.cc-blinking-cursor {
    display: inline-block;
    font-weight: 300;
    margin-left: 2px;
    color: #ff9800;
    animation: cc-blink 0.8s infinite;
}

@keyframes cc-blink {
    0%, 100% { opacity: 1; }
    50% { opacity: 0; }
}

#cc-confetti-canvas {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    z-index: 5;
}

#cc-banner-show-cards {
    border-color: rgba(255, 255, 255, 0.7);
    color: #ffffff;
    font-size: 12px;
    font-weight: 500;
    padding: 6px 14px;
    border-radius: 20px;
    background: rgba(0, 0, 0, 0.25);
    backdrop-filter: blur(4px);
    transition: all 0.2s ease;
}

#cc-banner-show-cards:hover {
    background: #ffffff;
    color: #212529;
    border-color: #ffffff;
}

#cc-banner-close-btn {
    opacity: 0.75;
    transition: opacity 0.2s;
    font-size: 11px;
}

#cc-banner-close-btn:hover {
    opacity: 1;
}

@media (max-width: 576px) {
    #cc-special-day-banner-card, .cc-banner-inner {
        min-height: auto;
        height: auto;
    }
    .cc-banner-inner {
        flex-direction: column;
        align-items: flex-start !important;
        padding: 16px;
    }
    .cc-banner-title {
        font-size: 18px;
        margin-bottom: 12px;
    }
    .cc-banner-controls {
        width: 100%;
        margin-left: 0 !important;
        margin-top: 10px;
        justify-content: space-between;
    }
}
</style>

<script id="cc-special-day-banner-js">
(function () {
    var AUTO_RESTORE_SECONDS = 20;
    var specialDays = <?php echo json_encode(array_values($special_days)); ?> || [];
    var userId = <?php echo (int) $login_user_id; ?>;
    var todayDate = "<?php echo (new \DateTime('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d'); ?>";
    var dismissKey = "cc_sd_dismissed_" + userId + "_" + todayDate;

    // 1. Check dismissal in localStorage
    try {
        if (localStorage.getItem(dismissKey) === "1") {
            return; // Dismissed earlier today, keep normal cards visible
        }
    } catch (e) {}

    if (!specialDays || !specialDays.length) {
        return;
    }

    $(document).ready(function () {
        var $firstRow = $(".dashboards-row:first");
        var $bannerRow = $("#cc-special-day-banner-row");
        var $textEl = $("#cc-special-day-text");
        var $cursor = $("#cc-typewriter-cursor");
        var isDismissed = false;
        var restoreTimer = null;

        if (!$firstRow.length || !$bannerRow.length) {
            return;
        }

        // Measure first row height to match exact height on desktop
        var firstRowHeight = $firstRow.outerHeight();
        if (firstRowHeight && $(window).width() > 768) {
            $("#cc-special-day-banner-card").css("min-height", firstRowHeight + "px");
            $(".cc-banner-inner").css("min-height", firstRowHeight + "px");
        }

        // Hide normal first row and show banner
        $firstRow.hide();
        $bannerRow.show();
        if (window.feather) feather.replace();

        function restoreCards(manual) {
            if (isDismissed) return;
            isDismissed = true;
            if (restoreTimer) clearTimeout(restoreTimer);

            if (manual) {
                try {
                    localStorage.setItem(dismissKey, "1");
                } catch (e) {}
            }

            $bannerRow.slideUp(250, function () {
                $bannerRow.remove();
                $firstRow.slideDown(250, function () {
                    if (window.feather) feather.replace();
                });
            });
        }

        $("#cc-banner-show-cards").on("click", function () {
            restoreCards(true);
        });

        $("#cc-banner-close-btn").on("click", function () {
            restoreCards(true);
        });

        // Auto-restore after 20s
        restoreTimer = setTimeout(function () {
            restoreCards(false);
        }, AUTO_RESTORE_SECONDS * 1000);

        // Reduced motion check
        var prefersReducedMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

        if (prefersReducedMotion) {
            // Static text, no animation, no confetti
            $cursor.hide();
            $textEl.text(specialDays.join(", "));
            return;
        }

        // Typewriter animation
        var dayIdx = 0;
        var charIdx = 0;
        var isDeleting = false;
        var typeSpeed = 55;
        var eraseSpeed = 30;
        var pauseAtEnd = 2200;
        var pauseBeforeType = 350;

        function typeLoop() {
            if (isDismissed) return;
            var currentWord = specialDays[dayIdx];

            if (!isDeleting) {
                charIdx++;
                $textEl.text(currentWord.substring(0, charIdx));
                if (charIdx >= currentWord.length) {
                    if (specialDays.length > 1) {
                        isDeleting = true;
                        setTimeout(typeLoop, pauseAtEnd);
                    }
                    return;
                }
                setTimeout(typeLoop, typeSpeed);
            } else {
                charIdx--;
                $textEl.text(currentWord.substring(0, charIdx));
                if (charIdx <= 0) {
                    isDeleting = false;
                    dayIdx = (dayIdx + 1) % specialDays.length;
                    setTimeout(typeLoop, pauseBeforeType);
                    return;
                }
                setTimeout(typeLoop, eraseSpeed);
            }
        }

        typeLoop();

        // Confetti burst ("pop blast")
        // Light: at most ~60 particles, ~1.5 seconds, canvas removed afterwards
        try {
            runConfettiBurst();
        } catch (e) {}

        function runConfettiBurst() {
            var canvas = document.getElementById("cc-confetti-canvas");
            if (!canvas) return;
            var ctx = canvas.getContext("2d");
            var card = document.getElementById("cc-special-day-banner-card");
            if (!ctx || !card) return;

            var w = canvas.width = card.offsetWidth;
            var h = canvas.height = card.offsetHeight;

            var particleCount = 55;
            var particles = [];
            var colors = ["#ff5722", "#ffb300", "#4caf50", "#00bcd4", "#e91e63", "#ffffff", "#ffeb3b"];

            for (var i = 0; i < particleCount; i++) {
                var angle = Math.random() * Math.PI * 2;
                var speed = 2.5 + Math.random() * 4.5;
                particles.push({
                    x: w * 0.25 + (Math.random() - 0.5) * 80,
                    y: h * 0.45 + (Math.random() - 0.5) * 40,
                    vx: Math.cos(angle) * speed,
                    vy: Math.sin(angle) * speed - 1.5,
                    size: 3 + Math.random() * 4,
                    color: colors[Math.floor(Math.random() * colors.length)],
                    alpha: 1,
                    rotation: Math.random() * 360,
                    rotSpeed: (Math.random() - 0.5) * 12
                });
            }

            var startTime = performance.now();
            var duration = 1500; // 1.5 seconds

            function renderConfetti(now) {
                var elapsed = now - startTime;
                if (elapsed >= duration || isDismissed) {
                    if (canvas && canvas.parentNode) canvas.parentNode.removeChild(canvas);
                    return;
                }

                ctx.clearRect(0, 0, w, h);
                var progress = elapsed / duration;
                var currentAlpha = 1 - Math.pow(progress, 2);

                for (var j = 0; j < particles.length; j++) {
                    var p = particles[j];
                    p.x += p.vx;
                    p.y += p.vy;
                    p.vy += 0.08;
                    p.vx *= 0.98;
                    p.rotation += p.rotSpeed;

                    ctx.save();
                    ctx.translate(p.x, p.y);
                    ctx.rotate((p.rotation * Math.PI) / 180);
                    ctx.fillStyle = p.color;
                    ctx.globalAlpha = Math.max(0, currentAlpha);
                    ctx.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * 0.7);
                    ctx.restore();
                }

                requestAnimationFrame(renderConfetti);
            }

            requestAnimationFrame(renderConfetti);
        }
    });
})();
</script>
