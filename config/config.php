<?php

declare(strict_types=1);

/**
 * 应用基础配置
 *
 * 这里只存放「部署时确定、运行期不常改」的配置（来自 .env）。
 * 站点名称、配色、支付、邮件等运营配置全部存放在数据库 settings 表中，由后台管理，
 * 不要在代码里硬编码。
 */

use App\Support\Env;

return [
    'app' => [
        'name'     => 'CourseShop',
        'env'      => (string) Env::get('APP_ENV', 'production'),
        'debug'    => Env::getBool('APP_DEBUG', false),
        // 站点根地址，末尾不带斜杠
        'url'      => rtrim((string) Env::get('APP_URL', 'http://127.0.0.1:8090'), '/'),
        'key'      => (string) Env::get('APP_KEY', ''),
        'timezone' => (string) Env::get('APP_TIMEZONE', 'Asia/Shanghai'),
        // 可信反向代理 IP / CIDR 列表（逗号分隔，如 "10.0.0.0/8,192.168.1.10"）。
        // 仅当直连方（REMOTE_ADDR）命中列表时，才采信 X-Forwarded-For / X-Real-IP 头。
        'trusted_proxies' => array_values(array_filter(
            array_map('trim', explode(',', (string) Env::get('TRUSTED_PROXIES', ''))),
            static fn (string $value): bool => $value !== ''
        )),
    ],

    'session' => [
        'name'     => (string) Env::get('SESSION_NAME', 'courseshop_session'),
        'lifetime' => Env::getInt('SESSION_LIFETIME', 7200),
    ],

    'database' => [
        'host'     => (string) Env::get('DB_HOST', '127.0.0.1'),
        'port'     => Env::getInt('DB_PORT', 3306),
        'database' => (string) Env::get('DB_DATABASE', 'courseshop'),
        'username' => (string) Env::get('DB_USERNAME', 'root'),
        'password' => (string) Env::get('DB_PASSWORD', ''),
        'charset'  => (string) Env::get('DB_CHARSET', 'utf8mb4'),
    ],

    'upload' => [
        'max_video_mb'      => Env::getInt('UPLOAD_MAX_VIDEO_MB', 2048),
        'max_attachment_mb' => Env::getInt('UPLOAD_MAX_ATTACHMENT_MB', 10),
    ],

    'cache' => [
        // 缓存驱动：file（默认，零依赖文件缓存）/ array（进程内数组，仅当次请求有效）
        'driver' => (string) Env::get('CACHE_DRIVER', 'file'),
        // 文件缓存目录，留空则使用 storage/cache
        'path'   => (string) Env::get('CACHE_PATH', ''),
        // 默认有效期（秒）
        'ttl'    => Env::getInt('CACHE_TTL', 300),
    ],

    'video' => [
        // 存储驱动：local（P0 默认）/ oss / cos / s3（对象存储为 P2 预留）
        'disk'       => (string) Env::get('VIDEO_DISK', 'local'),
        // 本地视频根目录，留空则使用 storage/private/videos
        'local_root' => (string) Env::get('VIDEO_LOCAL_ROOT', ''),
        // 播放地址签名有效期（秒）
        'url_ttl'    => Env::getInt('VIDEO_URL_TTL', 7200),
        // ffmpeg 可执行文件路径，留空则在 PATH 中查找（HLS 转码 / 首帧截取）
        'ffmpeg_path' => (string) Env::get('VIDEO_FFMPEG_PATH', ''),
        // HLS 分片时长（秒），后台可覆盖
        'hls_segment_seconds' => Env::getInt('VIDEO_HLS_SEGMENT_SECONDS', 10),
    ],

    'monitor' => [
        // 错误监控开关（默认关闭），后台「错误监控」分组可覆盖
        'enabled' => Env::getBool('MONITOR_ENABLED', false),
        // 上报通道：log（默认，写入独立日志）/ webhook（JSON POST）
        'channel' => (string) Env::get('MONITOR_CHANNEL', 'log'),
        // webhook 通道的上报地址
        'webhook' => (string) Env::get('MONITOR_WEBHOOK', ''),
        // 上报超时（秒）
        'timeout' => Env::getInt('MONITOR_TIMEOUT', 3),
    ],
];
