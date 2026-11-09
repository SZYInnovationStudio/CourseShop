/**
 * CourseShop Service Worker
 *
 * 缓存策略：
 * - 页面导航：网络优先，离线时回退到离线提示页
 * - 静态资源（/assets/ 下的样式、脚本、图标）：缓存优先，后台静默更新
 * - 其余请求（如视频流、接口）：不接管，直接放行
 */
'use strict';

// 修改此版本号即可让旧缓存自动失效（每次发布涉及离线页/预缓存资源时递增）
var CACHE_NAME = 'courseshop-v2';

// 预缓存资源（相对路径以 Service Worker 所在目录为基准，天然兼容子目录部署）
var PRECACHE_URLS = [
    './offline.html',
    './assets/icons/icon-192.png',
    './assets/icons/icon-512.png',
    './assets/icons/maskable-512.png'
];

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function (cache) {
            // 逐个缓存：单个资源失败不会导致整体失败；用 cache.put 强制覆盖旧副本
            return Promise.all(PRECACHE_URLS.map(function (url) {
                return fetch(url, { cache: 'reload' }).then(function (response) {
                    if (!response || response.status !== 200) {
                        return null;
                    }

                    return cache.put(url, response);
                }).catch(function () {
                    return null;
                });
            }));
        }).then(function () {
            // 新版本立即接管，无需等待旧页面关闭
            return self.skipWaiting();
        })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.map(function (key) {
                return key === CACHE_NAME ? null : caches.delete(key);
            }));
        }).then(function () {
            // 清理同名缓存里带 ?v= 的旧静态资源版本，避免版本号变化后条目无限累积
            return caches.open(CACHE_NAME).then(function (cache) {
                return cache.keys().then(function (requests) {
                    return Promise.all(requests.map(function (request) {
                        if (new URL(request.url).search.indexOf('v=') !== -1) {
                            return cache.delete(request);
                        }

                        return null;
                    }));
                });
            });
        }).then(function () {
            return self.clients.claim();
        })
    );
});

self.addEventListener('fetch', function (event) {
    var request = event.request;

    // 只处理 GET，且不接管跨域请求
    if (request.method !== 'GET') {
        return;
    }

    var url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    // 页面导航：网络优先，失败时展示离线页
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(function () {
                return caches.match('./offline.html').then(function (cached) {
                    // 离线页尚未缓存时给出兜底响应，避免浏览器报错
                    return cached || new Response('网络连接不可用，请检查网络后重试。', {
                        status: 503,
                        headers: { 'Content-Type': 'text/plain; charset=utf-8' }
                    });
                });
            })
        );
        return;
    }

    // 静态资源：缓存优先 + 后台更新
    if (url.pathname.indexOf('/assets/') !== -1) {
        event.respondWith(
            caches.match(request).then(function (cached) {
                var network = fetch(request).then(function (response) {
                    if (response && response.status === 200 && response.type === 'basic') {
                        var copy = response.clone();
                        caches.open(CACHE_NAME).then(function (cache) {
                            cache.put(request, copy);
                        });
                    }

                    return response;
                }).catch(function () {
                    // 网络不可用时，若有缓存则继续使用
                    return cached;
                });

                return cached || network;
            })
        );
    }
});
