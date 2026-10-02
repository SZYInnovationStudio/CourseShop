<?php

declare(strict_types=1);

/**
 * 页脚：站点信息、快捷入口、备案信息、版权署名
 *
 * 备案号 / 公网安备号为空时不渲染对应内容。
 */

use App\Support\Setting;

$siteName   = Setting::string('site_name', 'CourseShop');
$siteDesc   = Setting::string('site_description', '');
// 页脚署名为站点固定标识，不允许后台修改
$signature  = '由SZY创新工作室开发（©' . date('Y') . ' SZY创新工作室，保留所有权利。）';
$beian      = Setting::string('site_beian', '');
$gongan     = Setting::string('site_gongan', '');
$gonganUrl  = Setting::string('site_gongan_url', '');
$sourceUrl  = Setting::string('footer_open_source_url', '');
?>
<footer class="site-footer">
    <div class="container">
        <div class="footer-grid">
            <div class="footer-brand">
                <p class="footer-title"><?= e($siteName) ?></p>
                <p class="text-muted mb-0"><?= e($siteDesc) ?></p>
            </div>

            <?php // 移动端可折叠分组（桌面端始终展开，详见 app.css / app.js） ?>
            <details class="footer-group" data-collapse-mobile open>
                <summary class="footer-title">快速入口</summary>
                <ul class="footer-list">
                    <li><a href="<?= url('/') ?>">首页</a></li>
                    <li><a href="<?= url('/courses') ?>">全部课程</a></li>
                    <li><a href="<?= url('/my/courses') ?>">我的课程</a></li>
                </ul>
            </details>

            <details class="footer-group" data-collapse-mobile open>
                <summary class="footer-title">帮助与支持</summary>
                <ul class="footer-list">
                    <li><a href="<?= url('/login') ?>">登录账号</a></li>
                    <li><a href="<?= url('/register') ?>">注册账号</a></li>
                    <?php // 指向创建工单页（/ticket 仅注册了 POST），未登录时由 auth 中间件重定向到登录页 ?>
                    <li><a href="<?= url('/ticket/create') ?>">提交工单</a></li>
                </ul>
            </details>

            <details class="footer-group" data-collapse-mobile open>
                <summary class="footer-title">政策与协议</summary>
                <ul class="footer-list">
                    <li><a href="<?= url('/agreements/terms') ?>">用户协议</a></li>
                    <li><a href="<?= url('/agreements/privacy') ?>">隐私政策</a></li>
                    <li><a href="<?= url('/agreements/refund') ?>">退款政策</a></li>
                </ul>
            </details>
        </div>

        <div class="footer-bottom">
            <p class="mb-0"><?= e($signature) ?></p>
            <div class="footer-bottom__links">
                <?php if ($beian !== ''): ?>
                    <a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"><?= e($beian) ?></a>
                <?php endif; ?>
                <?php if ($gongan !== ''): ?>
                    <a href="<?= e($gonganUrl !== '' ? $gonganUrl : 'https://beian.mps.gov.cn/') ?>" target="_blank" rel="noopener noreferrer"><?= e($gongan) ?></a>
                <?php endif; ?>
                <?php if ($sourceUrl !== ''): ?>
                    <a href="<?= e($sourceUrl) ?>" target="_blank" rel="noopener noreferrer">开源地址</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</footer>
