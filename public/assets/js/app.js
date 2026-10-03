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

        var setupHls = function (url, fallbackUrl) {
            var instance = null;

            var fallback = function () {
                if (instance) {
                    try {
                        instance.destroy();
                    } catch (e) {
                        /* 忽略销毁异常 */
                    }
                    instance = null;
                }

                if (fallbackUrl !== '' && video.getAttribute('src') !== fallbackUrl) {
                    video.src = fallbackUrl;
                    video.load();
                }
            };

            var start = function () {
                if (!window.Hls || !window.Hls.isSupported()) {
                    return false;
                }

                instance = new window.Hls({ maxBufferLength: 30, enableWorker: true });

                instance.on(window.Hls.Events.ERROR, function (event, data) {
                    if (data && data.fatal) {
                        fallback();
                    }
                });

                instance.loadSource(url);
                instance.attachMedia(video);
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
        video.addEventListener('loadedmetadata', function () {
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
        });

        /* ---------------- 自动播放（由上一节自动跳转而来时） ---------------- */
        var playHint = player.querySelector('[data-playhint]');
        var autoPlayNext = player.getAttribute('data-autoplay') === '1';

        var showPlayHint = function () {
            if (playHint) {
                playHint.hidden = false;
            }
        };

        if (playHint) {
            var playHintButton = playHint.querySelector('[data-playhint-button]');

            if (playHintButton) {
                playHintButton.addEventListener('click', function () {
                    playHint.hidden = true;
                    video.play();
                });
            }
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
        };

        var startAutoNext = function () {
            if (!autoNextBox || !autoNextCountdown || nextUrl === '') {
                return;
            }

            var template = autoNextBox.getAttribute('data-countdown-template') || '%d';
            var remaining = AUTO_NEXT_SECONDS;

            autoNextBox.hidden = false;
            autoNextCountdown.textContent = template.replace('%d', String(remaining));

            autoNextTimer = window.setInterval(function () {
                remaining -= 1;

                if (remaining <= 0) {
                    window.clearInterval(autoNextTimer);
                    autoNextTimer = null;
                    window.location.href = nextUrlAuto;
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

            startAutoNext();
        });

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
