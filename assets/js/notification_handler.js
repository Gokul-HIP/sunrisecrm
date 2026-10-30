// Browsers block audio until the user interacts with the page.
// Keep an unlocked AudioContext + preloaded buffer so project MP3 can play from poll timers.
window.NotificationSoundEngine = {
    ctx: null,
    buffer: null,
    unlocked: false,
    loading: false,
    lastSrc: "",
    pendingPlay: false,
    lastPlayAt: 0,

    _getVolume: function () {
        var volumeSetting = AppHelper && AppHelper.settings ? AppHelper.settings.notificationSoundVolume : "9";
        if (volumeSetting === "" || volumeSetting === null || typeof volumeSetting === "undefined") {
            volumeSetting = "9";
        }
        var volume = Number("0." + volumeSetting);
        if (!volume || volume < 0) {
            volume = 0.9;
        }
        if (volume > 1) {
            volume = 1;
        }
        return volume;
    },

    _ensureContext: function () {
        var AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) {
            return null;
        }
        if (!this.ctx) {
            this.ctx = new AudioCtx();
        }
        if (this.ctx.state === "suspended") {
            this.ctx.resume();
        }
        return this.ctx;
    },

    unlock: function () {
        try {
            this._ensureContext();

            var player = this._ensureHtmlPlayer();
            if (player) {
                player.muted = true;
                var playPromise = player.play();
                if (playPromise && typeof playPromise.then === "function") {
                    playPromise.then(function () {
                        player.pause();
                        player.currentTime = 0;
                        player.muted = false;
                    }).catch(function () {
                        player.muted = false;
                    });
                } else {
                    player.pause();
                    player.currentTime = 0;
                    player.muted = false;
                }
            }

            this.unlocked = true;
            this.preload();
        } catch (err) {
        }
    },

    _ensureHtmlPlayer: function () {
        if (!(AppHelper && AppHelper.notificationSoundSrc)) {
            return null;
        }
        var player = document.getElementById("notificationPlayer");
        if (!player || player.getAttribute("src") !== AppHelper.notificationSoundSrc) {
            if (player) {
                $(player).remove();
            }
            $("<audio></audio>").attr({
                src: AppHelper.notificationSoundSrc,
                id: "notificationPlayer",
                preload: "auto",
                playsinline: "playsinline"
            }).appendTo("body");
            player = document.getElementById("notificationPlayer");
            try {
                player.load();
            } catch (e) {
            }
        }
        return player;
    },

    preload: function () {
        if (!(AppHelper && AppHelper.notificationSoundSrc)) {
            return;
        }

        this._ensureHtmlPlayer();

        var ctx = this._ensureContext();
        if (!ctx) {
            return;
        }
        if (this.loading || (this.buffer && this.lastSrc === AppHelper.notificationSoundSrc)) {
            return;
        }

        this.loading = true;
        var self = this;
        var src = AppHelper.notificationSoundSrc;

        fetch(src, {cache: "force-cache"}).then(function (response) {
            if (!response.ok) {
                throw new Error("sound fetch failed");
            }
            return response.arrayBuffer();
        }).then(function (arrayBuffer) {
            return ctx.decodeAudioData(arrayBuffer.slice(0));
        }).then(function (decoded) {
            self.buffer = decoded;
            self.lastSrc = src;
            self.loading = false;
            if (self.pendingPlay) {
                self.pendingPlay = false;
                self.play(true);
            }
        }).catch(function () {
            self.loading = false;
            if (self.pendingPlay) {
                self.pendingPlay = false;
                self._playHtml(0);
            }
        });
    },

    _playWebAudio: function (volume) {
        var ctx = this._ensureContext();
        if (!(ctx && this.buffer)) {
            return false;
        }
        try {
            if (ctx.state === "suspended") {
                ctx.resume();
            }
            var source = ctx.createBufferSource();
            var gain = ctx.createGain();
            gain.gain.value = volume;
            source.buffer = this.buffer;
            source.connect(gain);
            gain.connect(ctx.destination);
            source.start(0);
            return true;
        } catch (err) {
            return false;
        }
    },

    _playHtml: function (attempt) {
        var self = this;
        var volume = this._getVolume();
        var player = this._ensureHtmlPlayer();
        if (!player) {
            return;
        }

        var tryPlay = function () {
            try {
                player.muted = false;
                player.volume = volume;
                if (player.readyState < 2) {
                    player.load();
                }
                try {
                    player.currentTime = 0;
                } catch (e) {
                }
                var playPromise = player.play();
                if (playPromise && typeof playPromise.then === "function") {
                    playPromise.catch(function () {
                        if (attempt < 4) {
                            setTimeout(function () {
                                self.unlock();
                                self._playHtml(attempt + 1);
                            }, 250 * (attempt + 1));
                        }
                    });
                }
            } catch (err) {
                if (attempt < 4) {
                    setTimeout(function () {
                        self._playHtml(attempt + 1);
                    }, 250 * (attempt + 1));
                }
            }
        };

        if (player.readyState >= 2) {
            tryPlay();
        } else {
            var onReady = function () {
                player.removeEventListener("canplaythrough", onReady);
                player.removeEventListener("loadeddata", onReady);
                tryPlay();
            };
            player.addEventListener("canplaythrough", onReady);
            player.addEventListener("loadeddata", onReady);
            try {
                player.load();
            } catch (e) {
            }
            setTimeout(tryPlay, 300);
        }
    },

    play: function (fromPending) {
        if (typeof isWindowsNotificationSoundEnabled === "function" && isWindowsNotificationSoundEnabled()) {
            return;
        }

        var volume = this._getVolume();
        if (!volume) {
            return;
        }

        // Avoid accidental double-beep within 400ms
        var now = Date.now();
        if (!fromPending && (now - this.lastPlayAt) < 400) {
            return;
        }
        this.lastPlayAt = now;

        // If sound file URL changed (user replaced mp3), drop old buffer/player
        if (AppHelper.notificationSoundSrc && this.lastSrc && this.lastSrc.split("?")[0] !== String(AppHelper.notificationSoundSrc).split("?")[0]) {
            this.buffer = null;
            this.lastSrc = "";
            var oldPlayer = document.getElementById("notificationPlayer");
            if (oldPlayer) {
                $(oldPlayer).remove();
            }
        }

        this._ensureContext();
        if (!this.unlocked) {
            this.unlock();
        }

        // Prefer HTML audio for custom/replaced MP3 files (Web Audio decode often fails)
        this._playHtml(0);

        // Warm Web Audio buffer in background for next time
        if (!this.buffer) {
            this.preload();
        }
    }
};

(function () {
    // Keep unlocking on interaction so Chrome does not re-suspend audio
    var keepUnlocked = function () {
        if (window.NotificationSoundEngine) {
            window.NotificationSoundEngine.unlock();
            if (window.NotificationSoundEngine.unlocked && typeof dismissNotificationSoundUnlockBanner === "function") {
                dismissNotificationSoundUnlockBanner(true);
            }
        }
    };
    $(document).on("click.notificationAudio keydown.notificationAudio touchstart.notificationAudio", keepUnlocked);

    document.addEventListener("visibilitychange", function () {
        if (!document.hidden && window.NotificationSoundEngine) {
            window.NotificationSoundEngine.unlock();
            if (window.NotificationSoundEngine.unlocked && typeof dismissNotificationSoundUnlockBanner === "function") {
                dismissNotificationSoundUnlockBanner(true);
            }
        }
    });

    window.addEventListener("focus", function () {
        if (window.NotificationSoundEngine) {
            window.NotificationSoundEngine.unlock();
            if (window.NotificationSoundEngine.unlocked && typeof dismissNotificationSoundUnlockBanner === "function") {
                dismissNotificationSoundUnlockBanner(true);
            }
        }
    });
})();

requestDesktopNotificationPermission = function () {
    try {
        if (!("Notification" in window)) {
            return Promise.resolve("denied");
        }
        if (Notification.permission === "granted") {
            return Promise.resolve("granted");
        }
        if (Notification.permission === "default") {
            return Notification.requestPermission();
        }
        return Promise.resolve(Notification.permission);
    } catch (err) {
        return Promise.resolve("denied");
    }
};

// How long before auto-close (only used when AUTO_CLOSE_DESKTOP_NOTIFICATION is true)
var DESKTOP_NOTIFICATION_TIMEOUT_MS = 5000; // 5 seconds

/**
 * Keep toast in Windows Notification Center?
 * false = do NOT call close() after a few seconds (stays in Windows Notification tab)
 * true  = auto-close after DESKTOP_NOTIFICATION_TIMEOUT_MS (removes it from Notification Center)
 */
var AUTO_CLOSE_DESKTOP_NOTIFICATION = false;

/**
 * Windows notification sound toggle
 * true  = use Windows/Chrome system sound (project MP3 is NOT played)
 * false = mute Windows sound and play files/system/notification.mp3 from this project
 */
var USE_WINDOWS_NOTIFICATION_SOUND = false;

isWindowsNotificationSoundEnabled = function () {
    if (typeof AppHelper !== "undefined" && AppHelper.settings && typeof AppHelper.settings.useWindowsNotificationSound !== "undefined") {
        return AppHelper.settings.useWindowsNotificationSound === true || AppHelper.settings.useWindowsNotificationSound === "1" || AppHelper.settings.useWindowsNotificationSound === 1;
    }
    return !!USE_WINDOWS_NOTIFICATION_SOUND;
};

shouldAutoCloseDesktopNotification = function () {
    if (typeof AppHelper !== "undefined" && AppHelper.settings && typeof AppHelper.settings.autoCloseDesktopNotification !== "undefined") {
        return AppHelper.settings.autoCloseDesktopNotification === true || AppHelper.settings.autoCloseDesktopNotification === "1" || AppHelper.settings.autoCloseDesktopNotification === 1;
    }
    return !!AUTO_CLOSE_DESKTOP_NOTIFICATION;
};

showBrowserNotification = function (data) {
    if (!data) {
        return;
    }

    var useWindowsSound = isWindowsNotificationSoundEnabled();
    var notificationId = String(data.notification_id || ("n-" + Date.now()));

    // Always try project sound first (even if toast is deduped later, sound still attempts once per call site)
    // Toast lock below prevents duplicate Windows popups across tabs.
    var allowToast = true;
    try {
        var lockKey = "sunrise_desktop_notif_lock";
        var lock = {};
        try {
            lock = JSON.parse(localStorage.getItem(lockKey) || "{}") || {};
        } catch (e) {
            lock = {};
        }
        var now = Date.now();
        var sameIdRecently = String(lock.id) === notificationId && (now - (lock.at || 0)) < 20000;
        var anyToastVeryRecently = (now - (lock.at || 0)) < 2500;
        if (sameIdRecently || anyToastVeryRecently) {
            allowToast = false;
        } else {
            localStorage.setItem(lockKey, JSON.stringify({id: notificationId, at: now}));
        }
    } catch (e) {
    }

    // Play project sound even when toast is suppressed as duplicate (only one tab should call this usually)
    if (!useWindowsSound) {
        playNotification();
        // Retry once shortly after — covers suspended AudioContext / buffer not ready
        setTimeout(function () {
            playNotification();
        }, 350);
    }

    if (!allowToast) {
        return;
    }

    var notificationData = {
        icon: data.icon,
        body: data.message,
        tag: "sunrise-crm-" + notificationId,
        renotify: false,
        requireInteraction: false,
        silent: !useWindowsSound
    };

    var openNotification = function () {
        try {
            if (typeof isMobile === "function" && isMobile()) {
                try {
                    navigator.serviceWorker.register(AppHelper.serviceWorkerUrl);
                    navigator.serviceWorker.ready.then(function (registration) {
                        notificationData.vibrate = [100, 50, 100];
                        notificationData.data = {baseUrl: AppHelper.baseUrl};
                        registration.showNotification(data.title, notificationData);
                    });
                } catch (err) {
                    console.log(err);
                }
                return;
            }

            var notification = new Notification(data.title || "Sunrise CRM", notificationData);

            if (shouldAutoCloseDesktopNotification()) {
                var timeout = (data.notificationTimeout * 1) || DESKTOP_NOTIFICATION_TIMEOUT_MS;
                setTimeout(function () {
                    try {
                        notification.close();
                    } catch (e) {
                    }
                }, timeout);
            }

            notification.onclick = function () {
                window.focus();
                if ($(".task-preview").length) {
                    // Avoid duplicate task/reminders DOM when opening a task from a desktop notification.
                    $("#page-content").remove();
                }
                if (data.url_attributes && data.notification_id) {
                    var link = "<a id='push-notification-link-" + data.notification_id + "' " + data.url_attributes + "></a>";
                    $("#default-navbar").append(link);
                    var $linkId = $("#push-notification-link-" + data.notification_id);

                    if (!data.isReminder) {
                        $.ajax({url: AppHelper.settings.pushNotficationMarkAsReadUrl + '/' + data.notification_id});
                    }

                    if ($linkId.attr("data-act")) {
                        $linkId.trigger("click");
                    } else if ($linkId.attr("href") && $linkId.attr("href") !== "#") {
                        window.location.href = $linkId.attr("href");
                    }

                    $linkId.remove();
                }
                notification.close();
            };
        } catch (err) {
        }
    };

    if (!("Notification" in window)) {
        return;
    }

    if (Notification.permission === "granted") {
        openNotification();
    } else if (Notification.permission !== "denied") {
        requestDesktopNotificationPermission().then(function (permission) {
            if (permission === "granted") {
                openNotification();
            }
        });
    }
};

$(document).ready(function () {
    requestDesktopNotificationPermission();
    if (window.NotificationSoundEngine) {
        // Pre-create audio element early; unlock still needs a user gesture
        window.NotificationSoundEngine._ensureHtmlPlayer();
    }
    $(document).on("click.desktopNotifPerm keydown.desktopNotifPerm", function () {
        requestDesktopNotificationPermission();
        if (window.NotificationSoundEngine) {
            window.NotificationSoundEngine.unlock();
            if (window.NotificationSoundEngine.unlocked && typeof dismissNotificationSoundUnlockBanner === "function") {
                dismissNotificationSoundUnlockBanner(true);
            }
        }
    });

    // One-time banner: unlock project notification sounds after browser autoplay lock
    initNotificationSoundUnlockBanner();
});

var NOTIF_SOUND_UNLOCK_STORAGE_KEY = "notif_sound_unlocked";

dismissNotificationSoundUnlockBanner = function (rememberSession) {
    var $banner = $("#notif-sound-unlock-banner");
    if ($banner.length) {
        $banner.removeClass("is-visible").addClass("is-hiding");
        setTimeout(function () {
            $banner.remove();
        }, 200);
    }
    if (rememberSession) {
        try {
            // localStorage = once per browser profile (not every page load / session)
            localStorage.setItem(NOTIF_SOUND_UNLOCK_STORAGE_KEY, "1");
        } catch (e) {
        }
    }
};

playNearSilentUnlockTest = function () {
    // Tiny near-silent beep confirms the user-gesture unlock succeeded (does not use engine internals)
    try {
        var AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) {
            return;
        }
        var ctx = new AudioCtx();
        if (ctx.state === "suspended") {
            ctx.resume();
        }
        var oscillator = ctx.createOscillator();
        var gain = ctx.createGain();
        gain.gain.value = 0.0001;
        oscillator.frequency.value = 440;
        oscillator.connect(gain);
        gain.connect(ctx.destination);
        oscillator.start();
        oscillator.stop(ctx.currentTime + 0.05);
        setTimeout(function () {
            try {
                ctx.close();
            } catch (e) {
            }
        }, 100);
    } catch (err) {
    }
};

initNotificationSoundUnlockBanner = function () {
    // Only needed when using project MP3 (Windows system sound does not need this unlock)
    if (typeof isWindowsNotificationSoundEnabled === "function" && isWindowsNotificationSoundEnabled()) {
        return;
    }

    try {
        if (localStorage.getItem(NOTIF_SOUND_UNLOCK_STORAGE_KEY) === "1") {
            return;
        }
    } catch (e) {
    }

    if (window.NotificationSoundEngine && window.NotificationSoundEngine.unlocked) {
        return;
    }

    if ($("#notif-sound-unlock-banner").length) {
        return;
    }

    var $banner = $(
        '<div id="notif-sound-unlock-banner" class="notif-sound-unlock-banner" role="status">' +
            '<span class="notif-sound-unlock-text">Enable notification sounds</span>' +
            '<button type="button" class="btn btn-primary btn-sm notif-sound-unlock-btn">Enable</button>' +
            '<button type="button" class="notif-sound-unlock-dismiss" aria-label="Dismiss">&times;</button>' +
        '</div>'
    );

    $("body").append($banner);
    setTimeout(function () {
        $banner.addClass("is-visible");
    }, 30);

    $banner.on("click", ".notif-sound-unlock-btn", function (e) {
        e.preventDefault();
        e.stopPropagation();

        if (window.NotificationSoundEngine) {
            window.NotificationSoundEngine.unlock();
        }
        playNearSilentUnlockTest();
        dismissNotificationSoundUnlockBanner(true);
    });

    $banner.on("click", ".notif-sound-unlock-dismiss", function (e) {
        e.preventDefault();
        e.stopPropagation();
        dismissNotificationSoundUnlockBanner(true);
    });
};

playNotification = function () {
    if (window.NotificationSoundEngine) {
        window.NotificationSoundEngine.play();
    }
};

showDesktopNotifications = function (desktopNotifications) {
    if (!desktopNotifications || !desktopNotifications.length) {
        return;
    }

    // Always only the newest toast (status change can create started + updated together)
    var latest = desktopNotifications[0];
    if (typeof showBrowserNotification === "function") {
        showBrowserNotification(latest);
    }
};

scheduleNotificationCheck = function (params) {
    if (!params || params._pollStopped) {
        return;
    }

    if (params._pollTimer) {
        clearTimeout(params._pollTimer);
    }

    var push_notification = params.pushNotification || false;
    var pusherReady = AppHelper.settings.enablePushNotification && AppHelper.settings.pusherKey && AppHelper.settings.pusherCluster;

    if (push_notification && pusherReady) {
        return;
    }

    var check_notification_after_every = (params.checkNotificationAfterEvery * 1000) || 15000;
    if (check_notification_after_every < 10000) {
        check_notification_after_every = 10000;
    }
    if (params.isMessageNotification) {
        check_notification_after_every = 5000;
    }

    params._pollTimer = setTimeout(function () {
        params.showPushNotification = true;
        if (params.notificationCountUrl) {
            params.notificationUrl = params.notificationCountUrl;
        }
        checkNotifications(params);
    }, check_notification_after_every);
};

checkNotifications = function (params, updateStatus) {
    if (!(params && params.notificationUrl)) {
        return;
    }

    if (!params.notificationCountUrl) {
        params.notificationCountUrl = params.notificationUrl;
    }

    var data = {check_notification: 1};
    if (params.isMessageNotification) {
        data = {active_message_id: getCookie("active_chat_id")};
    }

    $.ajax({
        url: params.notificationUrl,
        type: "POST",
        data: data,
        dataType: 'json',
        success: function (result) {
            if (!result || !result.success) {
                return;
            }

            if (typeof result.total_notifications !== "undefined") {
                var totalNotifications = (result.total_notifications * 1) || 0;
                var previousTotal = params.notificationSelector.attr("data-total");
                if (typeof previousTotal === "undefined" || previousTotal === null || previousTotal === "") {
                    previousTotal = "0";
                }
                var prevCount = parseInt(previousTotal, 10) || 0;
                var isNew = !!params.showPushNotification && totalNotifications > prevCount;

                var payloads = result.desktop_notifications || [];
                var maxPayloadId = 0;
                payloads.forEach(function (item) {
                    var id = parseInt(item.notification_id, 10) || 0;
                    if (id > maxPayloadId) {
                        maxPayloadId = id;
                    }
                });
                if (typeof params.lastDesktopNotificationId === "undefined") {
                    params.lastDesktopNotificationId = 0;
                }

                if (totalNotifications) {
                    if (params.isMessageNotification && window.prepareUnreadMessageChatBox) {
                        window.prepareUnreadMessageChatBox(result.total_notifications);
                    }

                    params.notificationSelector.html("<i data-feather='" + params.icon + "' class='icon'></i> <span class='badge bg-danger up'>" + result.total_notifications + "</span>");
                    feather.replace();

                    if (isNew) {
                        var showedDesktopToast = false;

                        if (!params.isMessageNotification) {
                            var fresh = payloads.filter(function (item) {
                                return (parseInt(item.notification_id, 10) || 0) > params.lastDesktopNotificationId;
                            });

                            if (!fresh.length && payloads.length) {
                                var delta = Math.max(1, totalNotifications - prevCount);
                                fresh = payloads.slice(0, delta);
                            }

                            if (fresh.length > 1) {
                                fresh = [fresh[0]];
                            }

                            if (fresh.length) {
                                showDesktopNotifications(fresh);
                                showedDesktopToast = true;
                            }
                        } else if (params.isMessageNotification) {
                            showDesktopNotifications([{
                                title: "Sunrise CRM",
                                message: "You have a new message",
                                icon: "",
                                notification_id: "msg-" + totalNotifications + "-" + Date.now()
                            }]);
                            showedDesktopToast = true;
                        }

                        if (!showedDesktopToast) {
                            playNotification();
                        }
                    }

                    if (maxPayloadId > params.lastDesktopNotificationId) {
                        params.lastDesktopNotificationId = maxPayloadId;
                    }

                    params.notificationSelector.attr("data-total", result.total_notifications);
                } else {
                    params.notificationSelector.attr("data-total", "0");
                    if (!updateStatus && params.notificationSelector.find(".badge").length) {
                        params.notificationSelector.html("<i data-feather='" + params.icon + "' class='icon'></i>");
                        feather.replace();
                    }
                }

                if (!params.showPushNotification && maxPayloadId > params.lastDesktopNotificationId) {
                    params.lastDesktopNotificationId = maxPayloadId;
                }
            }

            if (typeof result.notification_list !== "undefined") {
                params.notificationSelector.parent().find(".dropdown-details").html(result.notification_list);
            }

            if (!updateStatus && params.notificationUrlForMobile) {
                $.ajax({
                    url: params.notificationUrlForMobile,
                    type: "POST",
                    data: data,
                    dataType: 'json',
                    success: function (customResultForMobile) {
                        if (customResultForMobile.success) {
                            params.notificationSelector.parent().find(".dropdown-details").html(customResultForMobile.notification_list);
                        }
                    }
                });
            }

            if (updateStatus) {
                $.ajax({
                    url: params.notificationStatusUpdateUrl,
                    success: function () {
                        params.notificationSelector.html("<i data-feather='" + params.icon + "' class='icon'></i>");
                        feather.replace();
                        params.notificationSelector.attr("data-total", "0");
                    }
                });
            }
        },
        complete: function () {
            if (!updateStatus) {
                scheduleNotificationCheck(params);
            }
        }
    });
};
