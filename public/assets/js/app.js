/**
 * CourseShop 前端脚本
 *
 * 原生 ES6+，无任何第三方依赖。包含：
 * 1. 深浅色主题切换（localStorage 持久化）
 * 2. 移动端导航抽屉
 * 3. 提示条自动消失与手动关闭
 * 4. data-confirm 二次确认
 * 5. data-auto-submit 表单自动提交
 * 6. 密码可见切换
 * 7. 图形验证码点击刷新
 * 8. 订单支付状态轮询
 * 9. 学习页播放进度记录与续播
 */
(function () {
    'use strict';

    var THEME_KEY = 'courseshop-theme';
    var root = document.documentElement;

    /* ---------------- 主题切换 ---------------- */
    function currentTheme() {
        return root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }

    function applyTheme(theme) {
        root.setAttribute('data-theme', theme);
        try {
            localStorage.setItem(THEME_KEY, theme);
        } catch (e) {
            /* 隐私模式下 localStorage 可能不可用，忽略 */
        }
        persistTheme(theme);
    }

    // 登录用户切换主题时同步保存到账号，多设备生效
    function persistTheme(theme) {
        var trigger = document.querySelector('[data-theme-save]');
        var endpoint = trigger ? trigger.getAttribute('data-theme-save') : '';

        if (!endpoint) {
            return;
        }

        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var token = csrfMeta ? csrfMeta.getAttribute('content') : '';
        var body = new URLSearchParams();
        body.set('mode', theme);

        fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': token
            },
            credentials: 'same-origin',
            body: body.toString()
        }).catch(function () {
            /* 保存失败不打扰用户，本地偏好已生效 */
        });
    }

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-theme-toggle]');
        if (!toggle) {
            return;
        }

        applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
    });

    /* ---------------- 移动端抽屉（前台导航 / 后台侧边栏） ---------------- */
    function bindDrawer(toggleSelector, drawerSelector, options) {
        var config = options || {};
        var drawer = document.querySelector(drawerSelector);
        if (!drawer) {
            return;
        }

        var toggles = document.querySelectorAll(toggleSelector);
        var bodyClass = config.bodyClass || '';

        function setOpen(open) {
            drawer.classList.toggle('is-open', open);

            toggles.forEach(function (item) {
                if (item.hasAttribute('aria-expanded')) {
                    item.setAttribute('aria-expanded', open ? 'true' : 'false');
                }

                // 提供 data-label-open / data-label-close 时同步无障碍标签
                var label = item.getAttribute(open ? 'data-label-close' : 'data-label-open');
                if (label) {
                    item.setAttribute('aria-label', label);
                }
            });

            // 遮罩（body::before）与背景滚动锁定都挂在 body 上
            if (bodyClass !== '') {
                document.body.classList.toggle(bodyClass, open);
            }
        }

        toggles.forEach(function (toggle) {
            toggle.addEventListener('click', function () {
                setOpen(!drawer.classList.contains('is-open'));
            });
        });

        if (!config.dismissable) {
            return;
        }

        // 点击时忽略的区域（触发按钮所在容器），默认前台页头
        var ignoreSelector = config.ignoreSelector || '.site-header';

        // Esc 关闭
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && drawer.classList.contains('is-open')) {
                setOpen(false);
            }
        });

        // 点击抽屉、触发区与忽略区域以外的位置（含遮罩）关闭
        document.addEventListener('click', function (event) {
            if (!drawer.classList.contains('is-open')) {
                return;
            }

            if (ignoreSelector && event.target.closest(ignoreSelector)) {
                return;
            }

            if (drawer.contains(event.target)) {
                return;
            }

            setOpen(false);
        });

        // 放大到桌面断点后自动收起，避免残留的背景滚动锁定
        var breakpoint = config.breakpoint || 768;
        var desktop = window.matchMedia('(min-width: ' + breakpoint + 'px)');
        var onBreakpoint = function (event) {
            if (event.matches) {
                setOpen(false);
            }
        };

        if (typeof desktop.addEventListener === 'function') {
            desktop.addEventListener('change', onBreakpoint);
        } else if (typeof desktop.addListener === 'function') {
            desktop.addListener(onBreakpoint);
        }
    }

    bindDrawer('[data-nav-toggle]', '[data-drawer]', { bodyClass: 'menu-open', dismissable: true });
    bindDrawer('[data-admin-toggle]', '[data-admin-sidebar]', {
        bodyClass: 'admin-menu-open',
        dismissable: true,
        ignoreSelector: '.admin-topbar',
        breakpoint: 1024
    });

    /* ---------------- 顶部用户下拉菜单 ---------------- */
    (function () {
        var menu = document.querySelector('[data-user-menu]');
        if (!menu) {
            return;
        }

        var toggle = menu.querySelector('[data-user-menu-toggle]');
        var panel = menu.querySelector('[data-user-menu-panel]');
        if (!toggle || !panel) {
            return;
        }

        function setOpen(open) {
            menu.classList.toggle('is-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        toggle.addEventListener('click', function () {
            setOpen(!menu.classList.contains('is-open'));
        });

        // 点击菜单外部收起
        document.addEventListener('click', function (event) {
            if (menu.classList.contains('is-open') && !menu.contains(event.target)) {
                setOpen(false);
            }
        });

        // Esc 收起并归还焦点
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && menu.classList.contains('is-open')) {
                setOpen(false);
                toggle.focus();
            }
        });
    })();

    /* ---------------- 语言切换下拉 ---------------- */
    (function () {
        var menu = document.querySelector('[data-lang-menu]');
        if (!menu) {
            return;
        }

        // 点击菜单外部收起
        document.addEventListener('click', function (event) {
            if (menu.open && !menu.contains(event.target)) {
                menu.open = false;
            }
        });

        // Esc 收起并归还焦点
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && menu.open) {
                menu.open = false;
                var summary = menu.querySelector('summary');
                if (summary) {
                    summary.focus();
                }
            }
        });
    })();

    /* ---------------- 提示条 ---------------- */
    function dismissAlert(element) {
        element.style.transition = 'opacity 200ms ease';
        element.style.opacity = '0';
        window.setTimeout(function () {
            if (element.parentNode) {
                element.parentNode.removeChild(element);
            }
        }, 200);
    }

    document.addEventListener('click', function (event) {
        var closeButton = event.target.closest('[data-flash-close]');
        if (!closeButton) {
            return;
        }

        var alert = closeButton.closest('[data-flash]');
        if (alert) {
            dismissAlert(alert);
        }
    });

    window.setTimeout(function () {
        document.querySelectorAll('[data-flash]').forEach(function (alert) {
            dismissAlert(alert);
        });
    }, 5000);

    /* ---------------- 二次确认 ---------------- */
    document.addEventListener('submit', function (event) {
        var form = event.target;
        var message = form.getAttribute('data-confirm');

        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[data-confirm]');
        if (!link) {
            return;
        }

        if (!window.confirm(link.getAttribute('data-confirm'))) {
            event.preventDefault();
        }
    });

    /* ---------------- 表单自动提交（排序下拉等） ---------------- */
    document.addEventListener('change', function (event) {
        var control = event.target.closest('[data-auto-submit]');
        if (control && control.form) {
            control.form.submit();
        }
    });

    /* ---------------- 后台批量选择 ---------------- */
    (function () {
        var form = document.querySelector('[data-batch-form]');
        if (!form) {
            return;
        }

        var selectAll = document.querySelector('[data-batch-select-all]');
        var counter = form.querySelector('[data-batch-count]');
        // 复选框通过 form 属性关联到表单，DOM 上位于表格内，因此从文档范围查询
        var checkboxes = Array.prototype.slice.call(document.querySelectorAll('[data-batch-checkbox]'));

        if (!checkboxes.length) {
            return;
        }

        function selectable() {
            return checkboxes.filter(function (box) {
                return !box.disabled;
            });
        }

        function refresh() {
            var count = checkboxes.filter(function (box) {
                return box.checked && !box.disabled;
            }).length;

            if (counter) {
                counter.textContent = String(count);
            }

            var all = selectable();

            if (selectAll) {
                selectAll.checked = all.length > 0 && count === all.length;
                selectAll.indeterminate = count > 0 && count < all.length;
            }
        }

        checkboxes.forEach(function (box) {
            box.addEventListener('change', refresh);
        });

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                var checked = selectAll.checked;

                selectable().forEach(function (box) {
                    box.checked = checked;
                });

                refresh();
            });
        }

        refresh();
    })();

    /* ---------------- 密码可见切换 ---------------- */
    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-password-toggle]');
        if (!toggle) {
            return;
        }

        var group = toggle.closest('.input-group');
        var input = group ? group.querySelector('input') : null;

        if (!input) {
            return;
        }

        var visible = input.type === 'text';
        input.type = visible ? 'password' : 'text';
        var showLabel = toggle.getAttribute('data-label-show') || '显示密码';
        var hideLabel = toggle.getAttribute('data-label-hide') || '隐藏密码';
        toggle.setAttribute('aria-label', visible ? showLabel : hideLabel);
    });

    /* ---------------- 复制到剪贴板 ---------------- */
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-copy-target]');
        if (!button) {
            return;
        }

        var input = document.getElementById(button.getAttribute('data-copy-target'));
        if (!input) {
            return;
        }

        var original = button.getAttribute('data-copy-label') || button.textContent;
        button.setAttribute('data-copy-label', original);

        var copiedLabel = button.getAttribute('data-copied-label') || '已复制';

        var done = function () {
            button.textContent = copiedLabel;
            window.setTimeout(function () {
                button.textContent = original;
            }, 1500);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(input.value || '').then(done).catch(function () {
                input.select();
                document.execCommand('copy');
                done();
            });
            return;
        }

        input.select();
        document.execCommand('copy');
        done();
    });

    /* ---------------- 图形验证码点击刷新 ---------------- */
    document.addEventListener('click', function (event) {
        var image = event.target.closest('[data-captcha-image]');
        if (!image) {
            return;
        }

        // 去掉上一次的时间戳，再附加新的，强制浏览器重新请求
        var base = (image.getAttribute('src') || '').split('#')[0].split('&_t=')[0];
        var glue = base.indexOf('?') === -1 ? '?' : '&';

        image.setAttribute('src', base + glue + '_t=' + Date.now());
    });

    /* ---------------- 颜色选择器预览（系统设置） ---------------- */
    document.addEventListener('input', function (event) {
        var picker = event.target.closest('[data-color-preview]');
        if (!picker) {
            return;
        }

        var wrapper = picker.closest('.settings-color');
        var output = wrapper ? wrapper.querySelector('[data-color-value]') : null;

        if (output) {
            output.textContent = String(picker.value || '').toUpperCase();
        }
    });

    /* ---------------- 订单支付状态轮询 ---------------- */
    var watcher = document.querySelector('[data-order-watch]');

    if (watcher) {
        var statusUrl = watcher.getAttribute('data-status-url');
        var POLL_INTERVAL = 3000;

        var poll = function () {
            fetch(statusUrl, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
                .then(function (response) {
                    return response.ok ? response.json() : null;
                })
                .then(function (data) {
                    if (!data) {
                        return;
                    }

                    // 已支付或订单已关闭时刷新页面，展示最终状态
                    if (data.paid || data.status === 'closed' || data.status === 'refunded') {
                        window.location.reload();
                        return;
                    }

                    window.setTimeout(poll, POLL_INTERVAL);
                })
                .catch(function () {
                    window.setTimeout(poll, POLL_INTERVAL);
                });
        };

        window.setTimeout(poll, POLL_INTERVAL);
    }

    /* ---------------- 学习页播放进度记录与续播 ---------------- */
    var player = document.querySelector('[data-video-player]');
    var video = player ? player.querySelector('video') : null;

    if (player && video) {
        var progressUrl = player.getAttribute('data-progress-url') || '';
        var resumeSeconds = parseFloat(player.getAttribute('data-resume')) || 0;
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
        var REPORT_INTERVAL = 10000;
        var lastSent = -1;

        /* HLS 渐进增强：Safari / iOS 用原生播放，其余浏览器懒加载 hls.js，失败回退 mp4 */
        var HLS_SCRIPT_URL = 'https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js';
        var HLS_SCRIPT_FLAG = 'data-hls-script';

        var loadHlsScript = function (callback) {
            if (window.Hls) {
                callback(null);
                return;
            }

            // 已有相同 script 标签时复用，避免并发章节切换重复加载
            var existing = document.querySelector('script[' + HLS_SCRIPT_FLAG + ']');
            if (existing) {
                existing.addEventListener('load', function () { callback(null); });
                existing.addEventListener('error', function () { callback(new Error('hls.js load failed')); });
                return;
            }

            var script = document.createElement('script');
            script.src = HLS_SCRIPT_URL;
            script.async = true;
            script.setAttribute(HLS_SCRIPT_FLAG, '1');
            script.addEventListener('load', function () { callback(null); });
            script.addEventListener('error', function () { callback(new Error('hls.js load failed')); });
            document.head.appendChild(script);
        };

        // 当前 hls.js 实例：页内切换章节换源前必须先销毁，避免旧实例继续拉流
        var hlsInstance = null;

        var destroyHls = function () {
            if (hlsInstance) {
                try {
                    hlsInstance.destroy();
                } catch (e) {
                    /* 忽略销毁异常 */
                }

                hlsInstance = null;
            }
        };

        var setupHls = function (url, fallbackUrl) {
            var fallback = function () {
                destroyHls();

                if (fallbackUrl !== '' && video.getAttribute('src') !== fallbackUrl) {
                    video.src = fallbackUrl;
                    video.load();
                }
            };

            var start = function () {
                if (!window.Hls || !window.Hls.isSupported()) {
                    return false;
                }

                hlsInstance = new window.Hls({ maxBufferLength: 30, enableWorker: true });

                hlsInstance.on(window.Hls.Events.ERROR, function (event, data) {
                    if (data && data.fatal) {
                        fallback();
                    }
                });

                hlsInstance.loadSource(url);
                hlsInstance.attachMedia(video);
                return true;
            };

            if (start()) {
                return;
            }

            loadHlsScript(function (error) {
                if (error || !start()) {
                    fallback();
                }
            });
        };

        // 页内换源：Safari 用原生 HLS，其余浏览器走 hls.js，最终兜底 mp4
        var applySource = function (mp4, hls) {
            destroyHls();

            if (hls !== '' && video.canPlayType('application/vnd.apple.mpegurl') !== '') {
                video.src = hls;
                video.load();
                return;
            }

            if (hls !== '' && (window.MediaSource || window.WebKitMediaSource)) {
                video.removeAttribute('src');
                video.load();
                setupHls(hls, mp4);
                return;
            }

            video.src = mp4;
            video.load();
        };

        var hlsUrl = player.getAttribute('data-hls') || '';
        var mp4Url = player.getAttribute('data-mp4') || '';

        if (hlsUrl !== '') {
            if (video.canPlayType('application/vnd.apple.mpegurl') !== '') {
                // Safari / iOS：浏览器原生支持 HLS
                video.src = hlsUrl;
            } else if (window.MediaSource || window.WebKitMediaSource) {
                // 其他支持 MSE 的浏览器：清空 mp4 源后交给 hls.js 接管
                video.removeAttribute('src');
                video.load();
                setupHls(hlsUrl, mp4Url);
            }
        }

        // 续播：元数据加载完成后再跳转；距离结尾不足 5 秒则从头播放
        var applyResume = function () {
            if (resumeSeconds <= 0 || !isFinite(video.duration)) {
                return;
            }

            if (resumeSeconds >= video.duration - 5) {
                return;
            }

            try {
                video.currentTime = resumeSeconds;
            } catch (e) {
                /* 部分浏览器在元数据未就绪时禁止设置 currentTime，忽略 */
            }
        };

        // 脚本延迟执行期间元数据可能已就绪（defer + preload="metadata"），
        // 此时直接应用续播位置，避免监听错过导致从 0 开始播放
        if (video.readyState >= 1) {
            applyResume();
        } else {
            video.addEventListener('loadedmetadata', applyResume);
        }

        /* ---------------- 自动播放（由上一节自动跳转而来时） ---------------- */
        var playHint = player.querySelector('[data-playhint]');
        var autoPlayNext = player.getAttribute('data-autoplay') === '1';

        // 浮层（自动播放提示 / 自动下一节）显示时隐藏中央播放按钮，避免两个播放按钮互相叠加
        var syncOverlayClass = function () {
            var hintVisible = playHint && !playHint.hidden;
            var nextVisible = autoNextBox && !autoNextBox.hidden;
            player.classList.toggle('has-overlay', !!(hintVisible || nextVisible));
        };

        var showPlayHint = function () {
            if (playHint) {
                playHint.hidden = false;
                syncOverlayClass();
            }
        };

        if (playHint) {
            var playHintButton = playHint.querySelector('[data-playhint-button]');

            if (playHintButton) {
                playHintButton.addEventListener('click', function () {
                    playHint.hidden = true;
                    syncOverlayClass();
                    video.play();
                });
            }

            // 用户通过控件条或画面点击自行播放时同样收起提示
            video.addEventListener('play', function () {
                playHint.hidden = true;
                syncOverlayClass();
            });
        }

        if (autoPlayNext) {
            var attemptPlay = function () {
                var promise = video.play();

                // 浏览器拦截自动播放（无用户手势）时，给一个「点击继续播放」入口
                if (promise && typeof promise.catch === 'function') {
                    promise.catch(showPlayHint);
                }
            };

            if (video.readyState >= 1) {
                attemptPlay();
            } else {
                video.addEventListener('loadedmetadata', attemptPlay, { once: true });
            }
        }

        var report = function (force) {
            var position = Math.max(0, Math.floor(video.currentTime || 0));
            var duration = Math.max(0, Math.floor(video.duration || 0));

            if (position <= 0 && duration <= 0) {
                return;
            }

            // 非强制上报时，位置没变化就跳过，避免产生无意义的请求
            if (!force && position === lastSent) {
                return;
            }

            lastSent = position;

            var body = new URLSearchParams();
            body.set('position', String(position));
            body.set('duration', String(duration));

            fetch(progressUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken
                },
                credentials: 'same-origin',
                body: body.toString(),
                keepalive: true
            })
                .then(function (response) {
                    if (!response.ok) {
                        return;
                    }

                    var status = player.querySelector('[data-video-status]');
                    if (!status) {
                        return;
                    }

                    status.hidden = false;
                    status.classList.add('is-visible');
                    window.setTimeout(function () {
                        status.classList.remove('is-visible');
                    }, 1600);
                })
                .catch(function () {
                    /* 上报失败不打扰用户，下次定时上报会自动补齐 */
                });
        };

        // 播放中每 10 秒上报一次
        window.setInterval(function () {
            if (!video.paused && !video.ended) {
                report(false);
            }
        }, REPORT_INTERVAL);

        video.addEventListener('pause', function () { report(true); });
        video.addEventListener('ended', function () { report(true); });

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                report(true);
            }
        });

        window.addEventListener('pagehide', function () { report(true); });

        /* ---------------- 播完自动进入下一节（5 秒倒计时，可随时取消） ---------------- */
        var nextUrl = player.getAttribute('data-next-url') || '';
        // 自动跳转时带上 autoplay 标记，让下一节尝试自动播放
        var nextUrlAuto = nextUrl === '' ? '' : nextUrl + (nextUrl.indexOf('?') === -1 ? '?' : '&') + 'autoplay=1';
        var autoNextBox = player.querySelector('[data-autonext]');
        var autoNextCountdown = autoNextBox ? autoNextBox.querySelector('[data-autonext-countdown]') : null;
        var autoNextTimer = null;
        var AUTO_NEXT_SECONDS = 5;

        var cancelAutoNext = function () {
            if (autoNextTimer) {
                window.clearInterval(autoNextTimer);
                autoNextTimer = null;
            }

            if (autoNextBox) {
                autoNextBox.hidden = true;
            }

            syncOverlayClass();
        };

        var startAutoNext = function () {
            if (!autoNextBox || !autoNextCountdown || nextUrl === '') {
                return;
            }

            // 先清理可能已存在的倒计时，避免 ended 重复触发时叠加多个定时器
            cancelAutoNext();

            var template = autoNextBox.getAttribute('data-countdown-template') || '%d';
            var remaining = AUTO_NEXT_SECONDS;

            autoNextBox.hidden = false;
            syncOverlayClass();
            autoNextCountdown.textContent = template.replace('%d', String(remaining));

            autoNextTimer = window.setInterval(function () {
                remaining -= 1;

                if (remaining <= 0) {
                    window.clearInterval(autoNextTimer);
                    autoNextTimer = null;
                    gotoNextChapter();
                    return;
                }

                autoNextCountdown.textContent = template.replace('%d', String(remaining));
            }, 1000);
        };

        if (autoNextBox) {
            var autoNextCancel = autoNextBox.querySelector('[data-autonext-cancel]');

            if (autoNextCancel) {
                autoNextCancel.addEventListener('click', cancelAutoNext);
            }

            // 全屏下点「立即播放」同样走页内切换，避免整页跳转退出全屏
            var autoNextPlay = autoNextBox.querySelector('[data-autonext-play]');

            if (autoNextPlay) {
                autoNextPlay.addEventListener('click', function (event) {
                    if (inFullscreen() && nextUrl !== '') {
                        event.preventDefault();
                        switchChapter(nextUrl);
                    }
                });
            }
        }

        // 用户回看、重播或拖动进度时取消自动切换
        ['play', 'seeking'].forEach(function (name) {
            video.addEventListener(name, cancelAutoNext);
        });

        video.addEventListener('ended', function () {
            var status = player.querySelector('[data-video-status]');
            var finishedLabel = status ? status.getAttribute('data-label-finished') : '';

            // 最后一节没有下一节可播，改为提示本章已学完
            if (nextUrl === '' && status && finishedLabel) {
                status.textContent = finishedLabel;
            }

            // iPhone 原生视频全屏（webkitEnterFullscreen）下页面浮层不可见：
            // 播放结束后主动退出全屏，让「自动下一节」倒计时在当前页面正常显示
            if (video.webkitDisplayingFullscreen && typeof video.webkitExitFullscreen === 'function') {
                try {
                    video.webkitExitFullscreen();
                } catch (e) {
                    /* 退出失败时忽略，不影响后续逻辑 */
                }
            }

            // 收起倍速菜单：避免倒计时与页内切换后残留上一节的浮层
            if (typeof closeSpeedMenu === 'function') {
                closeSpeedMenu();
            }

            startAutoNext();
        });

        /* ---------------- 页内切换章节：全屏下自动下一节时保持全屏，不做整页跳转 ---------------- */
        var inFullscreen = function () {
            return !!(document.fullscreenElement || document.webkitFullscreenElement);
        };

        var switchChapter = function (url) {
            var target = url + (url.indexOf('?') === -1 ? '?' : '&') + 'autoplay=1';
            var spinnerEl = player.querySelector('[data-player-spinner]');

            // 收起倒计时浮层与倍速菜单，并给出加载指示，等待下一节的页面数据
            if (autoNextBox) {
                autoNextBox.hidden = true;
            }

            if (typeof closeSpeedMenu === 'function') {
                closeSpeedMenu();
            }

            syncOverlayClass();

            if (spinnerEl) {
                spinnerEl.hidden = false;
            }

            // 复用学习页本身：服务端渲染的数据（签名地址、续播位置、权限）即唯一事实来源
            fetch(url, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('bad status');
                    }

                    return response.text();
                })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var nextPlayer = doc.querySelector('[data-video-player]');
                    var nextVideo = nextPlayer ? nextPlayer.querySelector('video') : null;

                    if (!nextPlayer || !nextVideo) {
                        throw new Error('player not found');
                    }

                    var nextMp4 = nextPlayer.getAttribute('data-mp4') || '';
                    var nextHls = nextPlayer.getAttribute('data-hls') || '';

                    if (nextMp4 === '' && nextHls === '') {
                        throw new Error('source not found');
                    }

                    // 同步播放器上的服务端数据：进度上报地址、续播位置、下一节信息
                    ['data-progress-url', 'data-resume', 'data-hls', 'data-mp4', 'data-next-url'].forEach(function (name) {
                        player.setAttribute(name, nextPlayer.getAttribute(name) || '');
                    });

                    progressUrl = player.getAttribute('data-progress-url') || progressUrl;
                    resumeSeconds = parseFloat(player.getAttribute('data-resume')) || 0;
                    lastSent = -1;
                    nextUrl = player.getAttribute('data-next-url') || '';
                    nextUrlAuto = nextUrl === '' ? '' : nextUrl + (nextUrl.indexOf('?') === -1 ? '?' : '&') + 'autoplay=1';

                    // 更新「自动下一节」浮层的标题、倒计时模板与立即播放链接
                    var nextAutoNext = nextPlayer.querySelector('[data-autonext]');

                    if (autoNextBox && nextAutoNext) {
                        autoNextBox.setAttribute(
                            'data-countdown-template',
                            nextAutoNext.getAttribute('data-countdown-template') || '%d'
                        );

                        var nextTitleEl = nextAutoNext.querySelector('.player__autonext-title');
                        var currentTitleEl = autoNextBox.querySelector('.player__autonext-title');

                        if (nextTitleEl && currentTitleEl) {
                            currentTitleEl.textContent = nextTitleEl.textContent;
                        }

                        var nextPlayEl = nextAutoNext.querySelector('[data-autonext-play]');
                        var currentPlayEl = autoNextBox.querySelector('[data-autonext-play]');

                        if (nextPlayEl && currentPlayEl) {
                            currentPlayEl.setAttribute('href', nextPlayEl.getAttribute('href') || '');
                        }
                    }

                    // 同步页面外壳：章节标题、上/下一节按钮、试看提示、续播说明、目录高亮、面包屑
                    var currentMain = document.querySelector('.learn__main');
                    var nextMain = doc.querySelector('.learn__main');

                    if (currentMain && nextMain) {
                        var currentHead = currentMain.querySelector('.learn__head');
                        var nextHead = nextMain.querySelector('.learn__head');

                        if (currentHead && nextHead) {
                            currentHead.replaceWith(nextHead);
                        }

                        var syncBlock = function (selector) {
                            var current = currentMain.querySelector(selector);
                            var next = nextMain.querySelector(selector);

                            if (current && next) {
                                current.replaceWith(next);
                            } else if (current) {
                                current.remove();
                            } else if (next) {
                                var anchor = currentMain.querySelector('.learn__head');

                                if (anchor) {
                                    anchor.insertAdjacentElement('afterend', next);
                                }
                            }
                        };

                        syncBlock('.learn__resume');
                        syncBlock('.alert');
                    }

                    var currentAside = document.querySelector('.learn__aside');
                    var nextAside = doc.querySelector('.learn__aside');

                    if (currentAside && nextAside) {
                        currentAside.replaceWith(nextAside);
                    }

                    var currentCrumb = document.querySelector('nav.breadcrumb');
                    var nextCrumb = doc.querySelector('nav.breadcrumb');

                    if (currentCrumb && nextCrumb) {
                        currentCrumb.replaceWith(nextCrumb);
                    }

                    if (doc.title !== '') {
                        document.title = doc.title;
                    }

                    // 地址栏替换为当前章节：不新增历史记录，避免后退回到未更新的旧章节
                    try {
                        window.history.replaceState(null, '', url);
                    } catch (e) {
                        /* 忽略 */
                    }

                    // 换源并续播；自动播放被浏览器拦截时由「点击继续播放」浮层兜底
                    var nextPoster = nextVideo.getAttribute('poster');

                    if (nextPoster) {
                        video.setAttribute('poster', nextPoster);
                    } else {
                        video.removeAttribute('poster');
                    }

                    // 续播位置随换源重新生效（首次加载时可能已错过 loadedmetadata 监听）
                    video.addEventListener('loadedmetadata', function () {
                        applyResume();
                    }, { once: true });

                    applySource(nextMp4, nextHls);

                    var promise = video.play();

                    if (promise && typeof promise.catch === 'function') {
                        promise.catch(showPlayHint);
                    }
                })
                .catch(function () {
                    // 任何异常都退回整页跳转，保证一定能继续播放
                    window.location.href = target;
                });
        };

        var gotoNextChapter = function () {
            if (nextUrl === '') {
                return;
            }

            // 容器全屏下改为页内切换章节：整页跳转必然退出全屏
            if (inFullscreen()) {
                switchChapter(nextUrl);
                return;
            }

            window.location.href = nextUrlAuto;
        };

        /* ---------------- 自定义播放器控件（初始化成功后接管原生控件） ---------------- */
        var controlsBar = player.querySelector('[data-player-controls]');
        var playButton = player.querySelector('[data-player-play]');
        var muteButton = player.querySelector('[data-player-mute]');
        var fullButton = player.querySelector('[data-player-fullscreen]');
        var bigPlayButton = player.querySelector('[data-player-bigplay]');
        var spinner = player.querySelector('[data-player-spinner]');
        var seek = player.querySelector('[data-player-seek]');
        var currentTimeLabel = player.querySelector('[data-player-current]');
        var durationLabel = player.querySelector('[data-player-duration]');

        if (controlsBar && playButton && seek) {
            var formatClock = function (value) {
                if (!isFinite(value) || value < 0) {
                    value = 0;
                }

                var total = Math.floor(value);
                var hours = Math.floor(total / 3600);
                var minutes = Math.floor((total % 3600) / 60);
                var seconds = total % 60;

                var pad = function (part) {
                    return part < 10 ? '0' + part : String(part);
                };

                return (hours > 0 ? hours + ':' + pad(minutes) : String(minutes)) + ':' + pad(seconds);
            };

            /* 播放 / 暂停 */
            var syncPlayState = function () {
                var paused = video.paused;
                player.classList.toggle('is-paused', paused);

                var label = playButton.getAttribute(paused ? 'data-label-play' : 'data-label-pause');
                if (label) {
                    playButton.setAttribute('aria-label', label);
                }
            };

            var togglePlay = function () {
                if (video.paused) {
                    var promise = video.play();

                    // 自动播放被拦截时静默处理，由「点击继续播放」浮层兜底
                    if (promise && typeof promise.catch === 'function') {
                        promise.catch(function () {});
                    }
                } else {
                    video.pause();
                }
            };

            playButton.addEventListener('click', togglePlay);

            if (bigPlayButton) {
                bigPlayButton.addEventListener('click', togglePlay);
            }

            // 点击画面任意位置播放 / 暂停（移动端主要交互）
            video.addEventListener('click', togglePlay);

            video.addEventListener('play', syncPlayState);
            video.addEventListener('pause', syncPlayState);
            video.addEventListener('ended', syncPlayState);

            /* 进度条 */
            var seeking = false;

            var renderProgress = function () {
                if (seeking) {
                    return;
                }

                var duration = isFinite(video.duration) ? video.duration : 0;
                var ratio = duration > 0 ? (video.currentTime || 0) / duration : 0;
                var value = Math.max(0, Math.min(1000, Math.round(ratio * 1000)));

                seek.value = String(value);
                seek.style.setProperty('--player-fill', (value / 10) + '%');

                if (currentTimeLabel) {
                    currentTimeLabel.textContent = formatClock(video.currentTime || 0);
                }
            };

            var renderDuration = function () {
                if (durationLabel) {
                    durationLabel.textContent = formatClock(video.duration || 0);
                }

                renderProgress();
            };

            seek.addEventListener('input', function () {
                seeking = true;

                var duration = video.duration;
                var ratio = Number(seek.value) / 1000;

                if (isFinite(duration) && duration > 0) {
                    try {
                        video.currentTime = ratio * duration;
                    } catch (e) {
                        /* 元数据未就绪时忽略 */
                    }
                }

                seek.style.setProperty('--player-fill', (ratio * 100) + '%');

                if (currentTimeLabel) {
                    currentTimeLabel.textContent = formatClock(ratio * (isFinite(duration) ? duration : 0));
                }
            });

            var finishSeek = function () {
                seeking = false;
                renderProgress();
            };

            seek.addEventListener('change', finishSeek);
            seek.addEventListener('pointerup', finishSeek);
            // 触摸被系统中断（来电、手势返回等）时同样结束拖动状态，避免进度条卡住
            seek.addEventListener('pointercancel', finishSeek);
            seek.addEventListener('blur', finishSeek);

            video.addEventListener('timeupdate', renderProgress);
            video.addEventListener('seeked', renderProgress);
            video.addEventListener('durationchange', renderDuration);
            video.addEventListener('loadedmetadata', renderDuration);

            if (video.readyState >= 1) {
                renderDuration();
            }

            /* 静音 */
            var syncMuteState = function () {
                player.classList.toggle('is-muted', video.muted || video.volume === 0);
            };

            if (muteButton) {
                muteButton.addEventListener('click', function () {
                    video.muted = !video.muted;
                });
            }

            video.addEventListener('volumechange', syncMuteState);

            /* 倍速播放：选择后记录到本地，切换章节后保持同一倍速 */
            var RATE_KEY = 'courseshop-playback-rate';
            var speedButton = player.querySelector('[data-player-speed]');
            var speedLabel = player.querySelector('[data-player-speed-label]');
            var speedMenu = player.querySelector('[data-player-speed-menu]');
            var speedWrap = player.querySelector('[data-player-speed-wrap]');
            var speedItems = speedMenu ? speedMenu.querySelectorAll('[data-rate]') : [];
            var rateMenuOpen = false;
            var desiredRate = 1;

            try {
                var savedRate = parseFloat(window.localStorage.getItem(RATE_KEY) || '');

                if (isFinite(savedRate) && savedRate > 0) {
                    desiredRate = savedRate;
                }
            } catch (e) {
                /* 隐私模式下 localStorage 不可用，用默认倍速 */
            }

            var applyRate = function (rate, persist) {
                desiredRate = rate;
                // 同步 defaultPlaybackRate：HLS 接管 / 切换视频源后浏览器会回落到该值
                video.defaultPlaybackRate = rate;
                video.playbackRate = rate;

                if (persist) {
                    try {
                        window.localStorage.setItem(RATE_KEY, String(rate));
                    } catch (e) {
                        /* 忽略持久化失败 */
                    }
                }
            };

            var syncRate = function () {
                var rate = video.playbackRate || desiredRate;

                if (speedLabel) {
                    speedLabel.textContent = String(rate) + 'x';
                }

                speedItems.forEach(function (item) {
                    var itemRate = parseFloat(item.getAttribute('data-rate') || '');
                    item.classList.toggle('is-active', Math.abs(itemRate - rate) < 0.001);
                });
            };

            var closeSpeedMenu = function () {
                if (!speedMenu || !rateMenuOpen) {
                    return;
                }

                speedMenu.hidden = true;
                rateMenuOpen = false;

                if (speedButton) {
                    speedButton.setAttribute('aria-expanded', 'false');
                }

                // 收起后恢复正常空闲计时（展开期间控件条被锁定为常显）
                wakeControls();
            };

            if (speedButton && speedMenu) {
                speedButton.addEventListener('click', function () {
                    rateMenuOpen = !rateMenuOpen;
                    speedMenu.hidden = !rateMenuOpen;
                    speedButton.setAttribute('aria-expanded', rateMenuOpen ? 'true' : 'false');

                    // 展开时取消已排定的自动隐藏，避免菜单随控件条一起淡出
                    if (rateMenuOpen) {
                        wakeControls();
                    }
                });

                speedItems.forEach(function (item) {
                    item.addEventListener('click', function () {
                        var rate = parseFloat(item.getAttribute('data-rate') || '');

                        if (isFinite(rate) && rate > 0) {
                            applyRate(rate, true);
                        }

                        closeSpeedMenu();
                    });
                });
            }

            // 点击别处或按 Esc 收起倍速菜单
            document.addEventListener('click', function (event) {
                if (rateMenuOpen && speedWrap && !speedWrap.contains(event.target)) {
                    closeSpeedMenu();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeSpeedMenu();
                }
            });

            video.addEventListener('ratechange', syncRate);

            applyRate(desiredRate, false);
            syncRate();

            // HLS 接管等换源场景浏览器可能重置倍率，元数据就绪后再校正一次
            video.addEventListener('loadedmetadata', function () {
                applyRate(desiredRate, false);
                syncRate();
            });

            /* 全屏：容器全屏优先（可锁定横屏、自定义控件在横屏下继续可用）；
               iPhone 不支持容器全屏，回退到原生视频全屏（webkitEnterFullscreen） */
            var fullscreenElement = function () {
                return document.fullscreenElement || document.webkitFullscreenElement || null;
            };

            var lockLandscape = function () {
                var orientation = window.screen && window.screen.orientation;

                if (!orientation || typeof orientation.lock !== 'function') {
                    return;
                }

                // 竖屏视频不强制横屏，避免两侧出现大面积黑边
                if (video.videoWidth > 0 && video.videoWidth < video.videoHeight) {
                    return;
                }

                try {
                    var promise = orientation.lock('landscape');

                    if (promise && typeof promise.catch === 'function') {
                        promise.catch(function () {});
                    }
                } catch (e) {
                    /* 桌面端等不支持方向锁定时忽略 */
                }
            };

            var enterNativeFullscreen = function () {
                if (typeof video.webkitEnterFullscreen === 'function') {
                    try {
                        video.webkitEnterFullscreen();
                    } catch (e) {
                        /* 忽略 */
                    }
                }
            };

            var syncFullscreenState = function () {
                var active = !!fullscreenElement() || !!video.webkitDisplayingFullscreen;
                player.classList.toggle('is-fullscreen', active);

                if (fullButton) {
                    var label = fullButton.getAttribute(active ? 'data-label-exit' : 'data-label-enter');
                    if (label) {
                        fullButton.setAttribute('aria-label', label);
                    }
                }
            };

            if (fullButton) {
                fullButton.addEventListener('click', function () {
                    if (fullscreenElement()) {
                        var exit = document.exitFullscreen || document.webkitExitFullscreen;

                        if (typeof exit === 'function') {
                            try {
                                var exiting = exit.call(document);

                                if (exiting && typeof exiting.catch === 'function') {
                                    exiting.catch(function () {});
                                }
                            } catch (e) {
                                /* 忽略 */
                            }
                        }

                        return;
                    }

                    var request = player.requestFullscreen || player.webkitRequestFullscreen;

                    if (typeof request === 'function') {
                        try {
                            var result = request.call(player);

                            if (result && typeof result.then === 'function') {
                                result.then(lockLandscape).catch(enterNativeFullscreen);
                            } else {
                                lockLandscape();
                            }
                        } catch (e) {
                            enterNativeFullscreen();
                        }
                    } else {
                        enterNativeFullscreen();
                    }
                });
            }

            document.addEventListener('fullscreenchange', syncFullscreenState);
            document.addEventListener('webkitfullscreenchange', syncFullscreenState);
            video.addEventListener('webkitbeginfullscreen', syncFullscreenState);
            video.addEventListener('webkitendfullscreen', syncFullscreenState);

            /* 播放中无操作时自动隐藏控件条与光标 */
            var IDLE_DELAY = 3500;
            var idleTimer = null;

            var scheduleIdle = function () {
                if (idleTimer) {
                    window.clearTimeout(idleTimer);
                    idleTimer = null;
                }

                // 倍速菜单展开期间不自动隐藏控件条，避免菜单跟着一起消失
                if (rateMenuOpen || video.paused || video.ended) {
                    player.classList.remove('is-idle');
                    return;
                }

                idleTimer = window.setTimeout(function () {
                    player.classList.add('is-idle');
                }, IDLE_DELAY);
            };

            var wakeControls = function () {
                player.classList.remove('is-idle');
                scheduleIdle();
            };

            video.addEventListener('play', scheduleIdle);
            video.addEventListener('pause', wakeControls);
            video.addEventListener('seeked', wakeControls);

            ['pointermove', 'pointerdown'].forEach(function (name) {
                player.addEventListener(name, wakeControls);
            });

            /* 缓冲指示：等待数据时显示 */
            var setSpinner = function (visible) {
                if (spinner) {
                    spinner.hidden = !visible;
                }
            };

            video.addEventListener('waiting', function () { setSpinner(true); });
            video.addEventListener('stalled', function () { setSpinner(true); });
            video.addEventListener('playing', function () { setSpinner(false); });
            video.addEventListener('canplay', function () { setSpinner(false); });
            video.addEventListener('pause', function () { setSpinner(false); });
            video.addEventListener('error', function () { setSpinner(false); });

            /* 初始化完成：移除原生控件，启用自绘控件条（失败时保留原生控件兜底） */
            video.removeAttribute('controls');

            syncPlayState();
            syncMuteState();
            syncFullscreenState();
            renderDuration();

            player.classList.add('is-custom');
        }

        /* ---------------- 键盘快捷键（鼠标悬停播放器或视频获得焦点时生效） ---------------- */
        var playerActive = false;

        player.addEventListener('mouseenter', function () { playerActive = true; });
        player.addEventListener('mouseleave', function () { playerActive = false; });
        video.addEventListener('focus', function () { playerActive = true; });
        video.addEventListener('blur', function () { playerActive = false; });

        document.addEventListener('keydown', function (event) {
            var target = event.target;

            // 输入框内不拦截，避免影响站内搜索等操作
            if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA'
                || target.tagName === 'SELECT' || target.isContentEditable)) {
                return;
            }

            if (event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }

            if (event.key === 'Escape') {
                if (autoNextBox && !autoNextBox.hidden) {
                    cancelAutoNext();
                }

                return;
            }

            if (!playerActive) {
                return;
            }

            if (event.key === ' ' || event.key === 'k' || event.key === 'K') {
                if (video.paused) {
                    video.play();
                } else {
                    video.pause();
                }

                event.preventDefault();
                return;
            }

            if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                var step = event.key === 'ArrowLeft' ? -5 : 5;
                var limit = isFinite(video.duration) ? Math.max(0, video.duration - 0.5) : Infinity;
                var position = Math.min(Math.max(0, (video.currentTime || 0) + step), limit);

                try {
                    video.currentTime = position;
                } catch (e) {
                    /* 元数据未就绪时忽略 */
                }

                event.preventDefault();
            }
        });
    }

    /* ---------------- 移动端底部抽屉（筛选 / 排序等） ---------------- */
    (function () {
        var openSheet = null;

        /** 显示 / 隐藏对应抽屉的遮罩层 */
        function showScrim(name, visible) {
            var scrim = document.querySelector('[data-sheet-scrim="' + name + '"]');
            if (scrim) {
                scrim.hidden = !visible;
            }
        }

        /** 关闭当前打开的抽屉 */
        function closeSheet() {
            if (!openSheet) {
                return;
            }

            openSheet.classList.remove('is-open');
            showScrim(openSheet.getAttribute('data-sheet'), false);
            document.body.classList.remove('sheet-open');
            openSheet = null;
        }

        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-sheet-toggle]');
            if (trigger) {
                var name = trigger.getAttribute('data-sheet-toggle');
                var sheet = document.querySelector('[data-sheet="' + name + '"]');
                if (sheet) {
                    openSheet = sheet;
                    sheet.classList.add('is-open');
                    showScrim(name, true);
                    document.body.classList.add('sheet-open');
                }
                return;
            }

            // 关闭按钮 / 遮罩：关闭抽屉
            if (event.target.closest('[data-sheet-close]') || event.target.closest('[data-sheet-scrim]')) {
                closeSheet();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeSheet();
            }
        });

        // 放大到桌面断点时收起抽屉，避免残留的滚动锁与遮罩
        var desktop = window.matchMedia('(min-width: 768px)');
        var onBreakpoint = function (event) {
            if (event.matches) {
                closeSheet();
            }
        };

        if (typeof desktop.addEventListener === 'function') {
            desktop.addEventListener('change', onBreakpoint);
        } else if (typeof desktop.addListener === 'function') {
            desktop.addListener(onBreakpoint);
        }
    })();

    /* ---------------- 顶部返回按钮 ---------------- */
    (function () {
        var back = document.querySelector('[data-history-back]');
        if (!back) {
            return;
        }

        // 服务端只在非顶级页面渲染该按钮，出现即显示；
        // 页面可指定固定返回目标（如学习页返回课程目录），避免反复回退历史
        var target = back.getAttribute('data-back-url') || '';
        back.classList.add('is-visible');

        back.addEventListener('click', function () {
            if (target !== '') {
                window.location.assign(target);
                return;
            }

            window.history.back();
        });
    })();

    /* ---------------- 数字输入框：阻止滚轮/方向键误改数值 ---------------- */
    (function () {
        // type="number" 的输入框在聚焦时，鼠标滚轮划过、按 ↑/↓ 都会静默 ±step，
        // 例如价格 10 会被悄悄改成 9.99。这里拦截这两个非显式输入的操作。
        function isNumberInput(el) {
            return !!el && el.tagName === 'INPUT' && el.type === 'number';
        }

        document.addEventListener('wheel', function (event) {
            // 仅拦截聚焦状态（原生步进只在此状态下触发），未聚焦时不影响页面滚动
            if (!isNumberInput(event.target) || event.target !== document.activeElement) {
                return;
            }

            event.preventDefault();
            event.target.blur();
        }, { passive: false });

        document.addEventListener('keydown', function (event) {
            if (!isNumberInput(event.target)) {
                return;
            }

            if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
                event.preventDefault();
            }
        });
    })();

    /* ---------------- 页脚分组折叠（仅移动端默认收起） ---------------- */
    (function () {
        var groups = document.querySelectorAll('details[data-collapse-mobile]');
        if (groups.length === 0) {
            return;
        }

        if (window.matchMedia('(max-width: 767px)').matches) {
            groups.forEach(function (group) {
                group.open = false;
            });
        }
    })();
})();
