<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Log;
use App\Support\Cache;
use App\Support\Csrf;
use App\Support\I18n;
use App\Support\ImageStorage;
use App\Support\Request;
use App\Support\SecurityHeaders;
use App\Support\Setting;
use RuntimeException;

/**
 * 后台系统设置
 *
 * 按分组维护 settings 表键值：站点信息 / 注册安全 / 图形验证码 / 安全响应头 / 邮件 / 支付 / 视频 / 工单 / 错误监控 / 主题 / 多语言。
 * 每个分组独立保存：GET /admin/settings?tab=xxx 展示，POST /admin/settings/{group} 保存。
 *
 * 字段类型：
 *   text/email/number/color  普通输入框
 *   password                 密钥类字段，留空表示保持原值
 *   bool                     复选框（未勾选即关闭）
 *   select                   下拉选择（options 为 值 => 文案）
 *   checkboxes               多选框组，以英文逗号串存储（options 为 值 => 文案，min_selected 为最少选择数）
 *   textarea                 多行文本
 *   image                    图片字段：可填写地址或上传本地图片（subdir 指定存储子目录）
 */
final class SettingsController extends AdminController
{
    /** 默认打开的分组 */
    private const DEFAULT_GROUP = 'site';

    /**
     * 设置页（按 ?tab= 展示对应分组）
     */
    public function index(): void
    {
        $groups = self::groups();

        $active = Request::string('tab', self::DEFAULT_GROUP);
        if (!isset($groups[$active])) {
            $active = self::DEFAULT_GROUP;
        }

        $this->view('admin.settings.index', [
            'pageTitle' => '系统设置',
            'groups'    => $groups,
            'active'    => $active,
            'values'    => $this->currentValues($groups[$active]['fields']),
        ]);
    }

    /**
     * 保存指定分组的设置
     */
    public function update(string $group): void
    {
        Csrf::check();

        $groups  = self::groups();
        $backUrl = url('/admin/settings?tab=' . $group);

        if (!isset($groups[$group])) {
            $this->fail(url('/admin/settings'), '设置分组不存在。');
        }

        $fields = $groups[$group]['fields'];

        $this->validateGroup($fields, $backUrl);

        $values   = [];
        $replaced = [];

        foreach ($fields as $field) {
            $key  = (string) $field['key'];
            $type = (string) ($field['type'] ?? 'text');

            if ($type === 'bool') {
                $values[$key] = Request::bool($key, false) ? '1' : '0';
                continue;
            }

            // 多选字段：仅保留合法选项并去重，以英文逗号串存储
            if ($type === 'checkboxes') {
                $values[$key] = implode(',', self::pickedOptions($field));
                continue;
            }

            // 图片字段：上传优先于地址；被替换的旧图在设置写入成功后再清理（CS-20）
            if ($type === 'image') {
                $current = Setting::string($key, '');

                try {
                    $uploaded = ImageStorage::saveUploaded(
                        $key . '_file',
                        (string) ($field['subdir'] ?? 'covers')
                    );
                } catch (RuntimeException $e) {
                    $this->fail($backUrl, $e->getMessage(), Request::all());
                }

                if ($uploaded !== null) {
                    $replaced[] = $current;
                }

                $values[$key] = $uploaded ?? Request::string($key);
                continue;
            }

            $value = Request::string($key);

            // 密钥类字段留空表示保持原值，不覆盖
            if (!empty($field['secret']) && $value === '') {
                continue;
            }

            $values[$key] = $value;
        }

        Setting::setMany($values);
        Setting::flush();

        // 设置已落库，此时才删除被替换的旧图（CS-20）
        foreach ($replaced as $old) {
            ImageStorage::delete($old);
        }

        Log::recordOperation('settings.update', 'settings', null, [
            'group'  => $group,
            'fields' => array_keys($values),
        ]);

        $this->success($backUrl, '「' . (string) $groups[$group]['label'] . '」设置已保存。');
    }

    /**
     * 清空全部缓存（设置、首页推荐等），清空后按需自动重建
     */
    public function clearCache(): void
    {
        Csrf::check();

        Cache::flush();

        Log::recordOperation('settings.cache_clear', 'settings');

        $this->success(url('/admin/settings'), '缓存已清空，系统会在下次访问时自动重建。');
    }

    /**
     * 读取当前分组各字段的值（用于表单回显）
     *
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, string>
     */
    private function currentValues(array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            $key     = (string) $field['key'];
            $type    = (string) ($field['type'] ?? 'text');
            $default = $field['default'] ?? '';

            if ($type === 'bool') {
                $values[$key] = Setting::bool($key, (bool) $default) ? '1' : '0';
            } elseif ($type === 'number') {
                $values[$key] = (string) Setting::int($key, (int) $default);
            } else {
                $values[$key] = Setting::string($key, (string) $default);
            }
        }

        return $values;
    }

    /**
     * 校验某分组字段
     *
     * @param array<int, array<string, mixed>> $fields
     */
    private function validateGroup(array $fields, string $backUrl): void
    {
        $validator = $this->validator(Request::all());

        foreach ($fields as $field) {
            $key   = (string) $field['key'];
            $label = (string) $field['label'];
            $type  = (string) ($field['type'] ?? 'text');

            if ($type === 'bool') {
                continue;
            }

            // 多选字段：校验必须落在合法选项内，并满足最少选择数
            if ($type === 'checkboxes') {
                $min = (int) ($field['min_selected'] ?? 0);

                if ($min > 0 && count(self::pickedOptions($field)) < $min) {
                    $this->fail($backUrl, '「' . $label . '」至少需要选择 ' . $min . ' 项。', Request::all());
                }

                continue;
            }

            // 密钥字段留空时跳过校验（保持原值）
            if (!empty($field['secret']) && Request::string($key) === '') {
                continue;
            }

            if (!empty($field['required'])) {
                $validator->required($key, $label);
            }
            if (isset($field['max'])) {
                $validator->max($key, (int) $field['max'], $label);
            }
            if ($type === 'number') {
                $validator->integer(
                    $key,
                    $label,
                    (int) ($field['min'] ?? PHP_INT_MIN),
                    (int) ($field['max_value'] ?? PHP_INT_MAX)
                );
            }
            if ($type === 'email') {
                $validator->email($key, $label);
            }
            if ($type === 'select' && isset($field['options'])) {
                $validator->in($key, array_keys($field['options']), $label);

                // 默认语言必须落在本次已启用的语言集合内
                if ($key === 'i18n_default_locale') {
                    $raw     = Request::input('i18n_locales', []);
                    $enabled = array_map('strval', is_array($raw) ? $raw : [$raw]);

                    if (!in_array(Request::string($key), $enabled, true)) {
                        $this->fail($backUrl, '默认语言必须从已启用的语言中选择。', Request::all());
                    }
                }
            }
        }

        if ($validator->fails()) {
            $this->fail($backUrl, (string) $validator->firstError(), Request::all());
        }
    }

    /**
     * 读取多选字段的已选值：过滤掉非法选项并去重
     *
     * @param array<string, mixed> $field
     * @return array<int, string>
     */
    private static function pickedOptions(array $field): array
    {
        $raw = Request::input((string) $field['key'], []);
        $raw = is_array($raw) ? $raw : [$raw];

        $allowed = array_map('strval', array_keys((array) ($field['options'] ?? [])));
        $picked  = [];

        foreach ($raw as $item) {
            $item = (string) $item;

            if (in_array($item, $allowed, true) && !in_array($item, $picked, true)) {
                $picked[] = $item;
            }
        }

        return $picked;
    }

    /**
     * 设置分组定义
     *
     * @return array<string, array{label: string, desc: string, fields: array<int, array<string, mixed>>}>
     */
    private static function groups(): array
    {
        $localeOptions = [];
        foreach (I18n::supported() as $code => $meta) {
            $localeOptions[$code] = (string) $meta['label'];
        }

        return [
            'site' => [
                'label' => '站点信息',
                'desc'  => '站点名称、SEO 信息与页脚展示内容。',
                'fields' => [
                    ['key' => 'site_name', 'label' => '站点名称', 'type' => 'text', 'required' => true, 'max' => 50, 'default' => 'CourseShop'],
                    ['key' => 'site_hero_title', 'label' => '首页主标题', 'type' => 'text', 'max' => 100, 'default' => '系统学习，从入门到实战', 'hint' => '显示在首页顶部的大标题。'],
                    ['key' => 'site_description', 'label' => '站点简介', 'type' => 'textarea', 'max' => 255, 'default' => '', 'hint' => '用于首页与搜索结果的站点描述。'],
                    ['key' => 'site_keywords', 'label' => 'SEO 关键词', 'type' => 'text', 'max' => 255, 'default' => '', 'hint' => '多个关键词用英文逗号分隔。'],
                    ['key' => 'site_logo', 'label' => '站点 Logo', 'type' => 'image', 'subdir' => 'logo', 'max' => 255, 'default' => '', 'hint' => '可直接填写图片地址，或选择本地图片上传；留空则使用文字标识与默认图标。'],
                    ['key' => 'site_beian', 'label' => 'ICP 备案号', 'type' => 'text', 'max' => 50, 'default' => '', 'hint' => '留空则不显示。'],
                    ['key' => 'site_gongan', 'label' => '公网安备号', 'type' => 'text', 'max' => 50, 'default' => '', 'hint' => '留空则不显示。'],
                    ['key' => 'site_gongan_url', 'label' => '公网安备查询地址', 'type' => 'text', 'max' => 255, 'default' => 'https://beian.mps.gov.cn'],
                ],
            ],
            'security' => [
                'label' => '注册安全',
                'desc'  => '注册开关、邮箱绑定要求与登录防护策略。',
                'fields' => [
                    ['key' => 'register_enabled', 'label' => '开放新用户注册', 'type' => 'bool', 'default' => true, 'hint' => '关闭后前台将不再显示注册入口，也无法注册。'],
                    ['key' => 'register_email_verify', 'label' => '注册需邮箱验证', 'type' => 'bool', 'default' => true, 'hint' => '开启后注册必须完成邮箱验证码校验才会创建账号，可有效抑制批量注册；关闭则注册无需邮箱。'],
                    ['key' => 'force_email_bind', 'label' => '强制绑定邮箱', 'type' => 'bool', 'default' => false, 'hint' => '开启后，除管理员外未绑定邮箱的账号无法使用站内功能。'],
                    ['key' => 'login_fail_captcha_threshold', 'label' => '登录失败触发验证码阈值', 'type' => 'number', 'min' => 1, 'max_value' => 100, 'default' => 3, 'hint' => '同一账号/IP 连续失败达到该次数后强制图形验证码。'],
                    ['key' => 'login_max_fail', 'label' => '连续失败上限', 'type' => 'number', 'min' => 1, 'max_value' => 10000, 'default' => 10, 'hint' => '达到上限后临时锁定。'],
                    ['key' => 'login_lock_minutes', 'label' => '锁定时长（分钟）', 'type' => 'number', 'min' => 1, 'max_value' => 1440, 'default' => 10],
                    ['key' => 'session_lifetime', 'label' => '会话有效期（秒）', 'type' => 'number', 'min' => 300, 'max_value' => 2592000, 'default' => 7200],
                ],
            ],
            'captcha' => [
                'label' => '图形验证码',
                'desc'  => '验证码的启用、长度与有效期。',
                'fields' => [
                    ['key' => 'captcha_enabled', 'label' => '启用图形验证码', 'type' => 'bool', 'default' => true],
                    ['key' => 'captcha_length', 'label' => '验证码长度', 'type' => 'number', 'min' => 4, 'max_value' => 6, 'default' => 4],
                    ['key' => 'captcha_expire_seconds', 'label' => '有效期（秒）', 'type' => 'number', 'min' => 30, 'max_value' => 3600, 'default' => 300],
                    ['key' => 'captcha_case_sensitive', 'label' => '区分大小写', 'type' => 'bool', 'default' => false],
                ],
            ],
            'headers' => [
                'label' => '安全响应头',
                'desc'  => '内容安全策略（CSP）。X-Content-Type-Options、X-Frame-Options、Referrer-Policy 等基线安全头始终下发，无法关闭。',
                'fields' => [
                    ['key' => 'security_csp', 'label' => '内容安全策略（CSP）', 'type' => 'textarea', 'max' => 2000, 'default' => SecurityHeaders::DEFAULT_CSP, 'hint' => '留空或清空则回退内置默认策略；如站点嵌入了第三方资源导致异常，可在此调整。'],
                ],
            ],
            'mail' => [
                'label' => '邮件设置',
                'desc'  => '用于发送邮箱验证码等通知的原生 SMTP 配置。',
                'fields' => [
                    ['key' => 'mail_enabled', 'label' => '启用邮件发送', 'type' => 'bool', 'default' => false],
                    ['key' => 'mail_queue_enabled', 'label' => '使用队列发送', 'type' => 'bool', 'default' => true, 'hint' => '开启后普通邮件改为入队异步投递，需运行 bin/queue-worker.php（或配置计划任务），否则邮件不会被发出；邮箱验证码类邮件始终同步发送。'],
                    ['key' => 'mail_host', 'label' => 'SMTP 服务器', 'type' => 'text', 'max' => 255, 'default' => '', 'hint' => '例如 smtp.qq.com。'],
                    ['key' => 'mail_port', 'label' => 'SMTP 端口', 'type' => 'number', 'min' => 1, 'max_value' => 65535, 'default' => 465],
                    ['key' => 'mail_encryption', 'label' => '加密方式', 'type' => 'select', 'default' => 'ssl', 'options' => ['ssl' => 'SSL', 'tls' => 'TLS', 'none' => '不加密']],
                    ['key' => 'mail_username', 'label' => 'SMTP 账号', 'type' => 'text', 'max' => 255, 'default' => ''],
                    ['key' => 'mail_password', 'label' => 'SMTP 密码 / 授权码', 'type' => 'password', 'secret' => true, 'max' => 255, 'default' => '', 'hint' => '出于安全考虑不回显，留空表示保持原值。'],
                    ['key' => 'mail_from_address', 'label' => '发件人邮箱', 'type' => 'email', 'max' => 255, 'default' => '', 'hint' => '留空时使用 SMTP 账号作为发件人。'],
                    ['key' => 'mail_from_name', 'label' => '发件人名称', 'type' => 'text', 'max' => 100, 'default' => 'CourseShop'],
                    ['key' => 'mail_code_ttl', 'label' => '验证码有效期（秒）', 'type' => 'number', 'min' => 60, 'max_value' => 86400, 'default' => 600],
                ],
            ],
            'payment' => [
                'label' => '支付设置',
                'desc'  => '易支付接口配置与订单支付通道开关。',
                'fields' => [
                    ['key' => 'epay_enabled', 'label' => '启用易支付', 'type' => 'bool', 'default' => false, 'hint' => '关闭时用户无法发起在线支付。'],
                    ['key' => 'epay_api_url', 'label' => '接口地址', 'type' => 'text', 'max' => 255, 'default' => '', 'hint' => '不含结尾斜杠，例如 https://zpayz.cn。'],
                    ['key' => 'epay_pid', 'label' => '商户 ID', 'type' => 'text', 'max' => 64, 'default' => ''],
                    ['key' => 'epay_key', 'label' => '商户密钥', 'type' => 'password', 'secret' => true, 'max' => 255, 'default' => '', 'hint' => '用于签名校验，留空表示保持原值。'],
                    ['key' => 'epay_sign_type', 'label' => '签名算法', 'type' => 'select', 'default' => 'MD5', 'options' => ['MD5' => 'MD5']],
                    ['key' => 'epay_wxpay_enabled', 'label' => '开启微信支付', 'type' => 'bool', 'default' => true],
                    ['key' => 'epay_alipay_enabled', 'label' => '开启支付宝', 'type' => 'bool', 'default' => true],
                    ['key' => 'epay_notify_ip_whitelist', 'label' => '异步通知 IP 白名单', 'type' => 'textarea', 'max' => 1000, 'default' => '', 'hint' => '多个 IP 用英文逗号分隔，留空表示不校验。'],
                    ['key' => 'order_expire_minutes', 'label' => '订单超时时间（分钟）', 'type' => 'number', 'min' => 1, 'max_value' => 1440, 'default' => 15, 'hint' => '未支付订单超过该时长后自动关闭。'],
                ],
            ],
            'catalog' => [
                'label' => '课程与套餐',
                'desc'  => '前台商品展示开关。',
                'fields' => [
                    ['key' => 'packages_enabled', 'label' => '显示优惠套餐', 'type' => 'bool', 'default' => true, 'hint' => '关闭后前台隐藏套餐入口（导航栏、首页推荐、移动端菜单）且套餐页不可访问；已产生的套餐订单仍可在「我的订单」查看与支付。'],
                ],
            ],
            'video' => [
                'label' => '视频设置',
                'desc'  => '视频访问签名与存储方式。',
                'fields' => [
                    ['key' => 'video_signed_ttl', 'label' => '访问签名有效期（秒）', 'type' => 'number', 'min' => 60, 'max_value' => 86400, 'default' => 1800],
                    ['key' => 'ffmpeg_path', 'label' => 'ffmpeg 路径', 'type' => 'text', 'max' => 255, 'default' => '', 'hint' => '留空则自动在 PATH 中查找。'],
                    ['key' => 'video_hls_enabled', 'label' => '启用 HLS 转码', 'type' => 'bool', 'default' => true, 'hint' => '开启后章节视频可异步转码为 HLS（m3u8），播放自动流式加载；未转码时回退 mp4。'],
                    ['key' => 'video_hls_segment_seconds', 'label' => 'HLS 分片时长（秒）', 'type' => 'number', 'min' => 2, 'max_value' => 60, 'default' => 10],
                ],
            ],
            'ticket' => [
                'label' => '工单设置',
                'desc'  => '工单提交开关与附件上传限制。',
                'fields' => [
                    ['key' => 'ticket_enabled', 'label' => '开放工单提交', 'type' => 'bool', 'default' => true, 'hint' => '关闭后用户无法提交普通工单，但账号申诉入口始终可用。'],
                    ['key' => 'ticket_attachment_types', 'label' => '允许的附件类型', 'type' => 'text', 'max' => 255, 'default' => 'jpg,jpeg,png,gif,pdf,zip,rar,7z,txt', 'hint' => '多个扩展名用英文逗号分隔，不带点。'],
                    ['key' => 'ticket_attachment_max_mb', 'label' => '单个附件大小上限（MB）', 'type' => 'number', 'min' => 1, 'max_value' => 100, 'default' => 10],
                ],
            ],
            'monitor' => [
                'label' => '错误监控',
                'desc'  => '站点发生 5xx 严重错误时的上报钩子（默认关闭，不依赖第三方 SDK）。',
                'fields' => [
                    ['key' => 'monitor_enabled', 'label' => '启用错误监控', 'type' => 'bool', 'default' => false, 'hint' => '开启后，5xx 错误会按下方通道上报。'],
                    ['key' => 'monitor_channel', 'label' => '上报通道', 'type' => 'select', 'default' => 'log', 'options' => ['log' => '日志文件', 'webhook' => 'Webhook'], 'hint' => '日志文件写入 storage/logs/monitor-*.log；Webhook 以 JSON POST 上报。'],
                    ['key' => 'monitor_webhook', 'label' => 'Webhook 地址', 'type' => 'text', 'max' => 255, 'default' => '', 'hint' => '通道选择 Webhook 时必填，支持钉钉/飞书/Sentry 等自定义接收端。'],
                    ['key' => 'monitor_timeout', 'label' => '上报超时（秒）', 'type' => 'number', 'min' => 1, 'max_value' => 30, 'default' => 3],
                ],
            ],
            'theme' => [
                'label' => '主题外观',
                'desc'  => '站点默认配色与主题切换策略。',
                'fields' => [
                    ['key' => 'theme_default_mode', 'label' => '默认主题', 'type' => 'select', 'default' => 'system', 'options' => ['system' => '跟随系统', 'light' => '浅色', 'dark' => '深色']],
                    ['key' => 'theme_primary_color', 'label' => '主强调色', 'type' => 'color', 'default' => '#4F6F52'],
                    ['key' => 'theme_allow_user_switch', 'label' => '允许用户自行切换主题', 'type' => 'bool', 'default' => true],
                ],
            ],
            'i18n' => [
                'label' => '多语言',
                'desc'  => '选择前台可用的语言并设置默认语言。启用两种及以上语言时前台会显示语言切换菜单；仅启用一种语言时菜单隐藏。后台管理界面始终使用简体中文。',
                'fields' => [
                    ['key' => 'i18n_locales', 'label' => '启用语言', 'type' => 'checkboxes', 'min_selected' => 1, 'default' => 'zh-CN', 'options' => $localeOptions, 'hint' => '至少启用一种语言；未启用的语言不会出现在前台切换菜单中。'],
                    ['key' => 'i18n_default_locale', 'label' => '默认语言', 'type' => 'select', 'default' => 'zh-CN', 'options' => $localeOptions, 'hint' => '访客语言无法判断时的兜底语言，须为已启用语言之一。'],
                ],
            ],
        ];
    }
}
