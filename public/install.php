<?php

declare(strict_types=1);

/**
 * CourseShop 安装向导（独立入口，不经过路由）
 *
 * 访问 /install.php 即可开始安装：
 *   第 1 步 环境检查 → 第 2 步 数据库配置 → 第 3 步 创建管理员 → 第 4 步 完成
 *
 * 安装完成后会在 storage/ 下写入 installed.lock，重复访问将提示已安装。
 * 出于安全考虑，上线后建议直接删除本文件。
 */

use App\Support\Csrf;
use App\Support\Env;
use App\Support\Installer;
use App\Support\Validator;

require dirname(__DIR__) . '/app/bootstrap.php';

$installed = Installer::isInstalled();
$step      = (int) ($_GET['step'] ?? 1);
$errors    = [];
$old       = [];

// 兜底防护：安装锁文件缺失时，只要 .env 已存在（说明站点曾部署过）或库中已有管理员，
// 同样视为「已安装」，阻止重新执行安装（schema.sql 以 DROP TABLE 开头，会清空线上数据）。
//
// 这里刻意**不做**「未完成安装」放行：曾为解开「站点跳安装页、安装页又拒绝安装」的死锁
// 而放行「.env 已写但无管理员」，但那会让任何匿名访客抢注管理员（并获得 super_admin）。
// 恢复为严格拦截；未完成安装请按页面提示删除 .env 后重新安装。
$adminExists   = Installer::hasExistingAdmin();
$blockedByData = !$installed && (Installer::envExists() || $adminExists);
$installLocked = $installed || $blockedByData;

// 锁文件缺失但库中已有管理员（安装确实完成）时自动补写安装锁，
// 避免每次访问安装页都停留在「已阻止重装」的提示页。
$lockRepaired = false;
$lockRepairFailed = false;

if ($blockedByData) {
    $lockRepaired = Installer::ensureLock();
    $lockRepairFailed = !$lockRepaired;

    if ($lockRepaired) {
        $installed     = true;
        $blockedByData = false;
    }
}

// ----------------------------------------------------------------------
// 处理表单提交
// ----------------------------------------------------------------------
if (!$installLocked) {
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = (string) ($_POST['action'] ?? '');
            $old    = $_POST;

            if (!Csrf::verify($_POST['_token'] ?? null)) {
                $errors[] = '安全校验失败，请刷新页面后重新提交。';
            } elseif ($action === 'install_database') {
                $db = [
                    'host'     => trim((string) ($_POST['db_host'] ?? '')),
                    'port'     => (int) ($_POST['db_port'] ?? 3306),
                    'database' => trim((string) ($_POST['db_database'] ?? '')),
                    'username' => trim((string) ($_POST['db_username'] ?? '')),
                    'password' => (string) ($_POST['db_password'] ?? ''),
                    'charset'  => 'utf8mb4',
                ];
                $appUrl = rtrim(trim((string) ($_POST['app_url'] ?? '')), '/');

                $validator = (new Validator($_POST))
                    ->required('db_host', '数据库主机')
                    ->integer('db_port', '数据库端口', 1, 65535)
                    ->required('db_database', '数据库名')
                    ->required('db_username', '数据库账号')
                    ->required('app_url', '站点地址');

                if ($validator->fails()) {
                    $errors[] = (string) $validator->firstError();
                } else {
                    Installer::installDatabase($db);
                    Installer::writeEnv([
                        'app_url'     => $appUrl,
                        'app_key'     => Installer::generateKey(),
                        'db_host'     => $db['host'],
                        'db_port'     => $db['port'],
                        'db_database' => $db['database'],
                        'db_username' => $db['username'],
                        'db_password' => $db['password'],
                        'db_charset'  => $db['charset'],
                    ]);

                    header('Location: install.php?step=3');
                    exit;
                }
            } elseif ($action === 'create_admin') {
                [$dbReady, $dbMessage] = Installer::databaseReady();

                if (!$dbReady) {
                    $errors[] = $dbMessage;
                } else {
                    $validator = (new Validator($_POST))
                        ->required('username', '管理员用户名')
                        ->username('username', '管理员用户名')
                        ->required('password', '登录密码')
                        ->min('password', 8, '登录密码')
                        ->same('password_confirm', 'password', '两次输入的密码')
                        ->email('email', '邮箱');

                    if ($validator->fails()) {
                        $errors[] = (string) $validator->firstError();
                    } else {
                        $email = trim((string) ($_POST['email'] ?? ''));

                        Installer::createAdmin(
                            (string) $_POST['username'],
                            (string) $_POST['password'],
                            $email === '' ? null : $email,
                            trim((string) ($_POST['nickname'] ?? '')) ?: null
                        );
                        Installer::lock((string) $_POST['username']);

                        header('Location: install.php?step=4');
                        exit;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// ----------------------------------------------------------------------
// 展示数据
// ----------------------------------------------------------------------
$requirements   = Installer::requirements();
$requirementsOk = Installer::requirementsPassed($requirements);

$dbReady   = true;
$dbMessage = '';
if (!$installLocked) {
    try {
        [$dbReady, $dbMessage] = Installer::databaseReady();
    } catch (Throwable $e) {
        $dbReady   = false;
        $dbMessage = $e->getMessage();
    }

    // 步骤守卫：环境未通过 / 数据库未就绪时回退到对应步骤
    if ($step > 4) {
        $step = 1;
    }
    if ($step >= 3 && !$dbReady) {
        $step = 2;
    }
    if ($step >= 2 && !$requirementsOk) {
        $step = 1;
    }
}

$success = $installed && $step === 4;

/** 表单回填：优先提交值，其次 .env 已有值，最后默认值 */
$keep = static function (string $key, string $default = '') use ($old): string {
    if (array_key_exists($key, $old)) {
        return (string) $old[$key];
    }

    return $default;
};

$defaultDb = [
    'host'     => (string) Env::get('DB_HOST', '127.0.0.1'),
    'port'     => (string) Env::get('DB_PORT', '3306'),
    'database' => (string) Env::get('DB_DATABASE', 'courseshop'),
    'username' => (string) Env::get('DB_USERNAME', 'root'),
    'app_url'  => (string) Env::get('APP_URL', Installer::defaultAppUrl()),
];

$stepLabels = [1 => '环境检查', 2 => '数据库配置', 3 => '创建管理员', 4 => '安装完成'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>安装向导 · CourseShop</title>
<link rel="stylesheet" href="assets/css/app.css">
<style>
    .install-body { min-height: 100vh; background: var(--color-bg-alt); padding: var(--space-6) var(--space-4); }
    .install-wrap { max-width: 760px; margin: 0 auto; }
    .install-head { text-align: center; margin-bottom: var(--space-6); }
    .install-head h1 { font-size: var(--text-2xl); margin: 0 0 var(--space-2); }
    .install-steps { display: flex; gap: var(--space-2); list-style: none; margin: 0 0 var(--space-5); padding: 0; }
    .install-steps li { flex: 1; text-align: center; font-size: var(--text-xs); color: var(--color-faint); padding: var(--space-2) var(--space-1); border-radius: var(--radius-sm); background: var(--color-surface); border: 1px solid var(--color-border); }
    .install-steps li.is-active { color: var(--color-primary); border-color: var(--color-primary); background: var(--color-primary-soft); font-weight: 600; }
    .install-steps li.is-done { color: var(--color-success); border-color: var(--color-success); }
    .install-check { display: flex; align-items: flex-start; gap: var(--space-3); padding: var(--space-3) 0; border-bottom: 1px dashed var(--color-border); }
    .install-check:last-child { border-bottom: 0; }
    .install-check__icon { flex: 0 0 18px; }
    .install-check__icon svg { display: block; width: 18px; height: 18px; }
    .install-check__icon.ok { color: var(--color-success); }
    .install-check__icon.bad { color: var(--color-danger); }
    .install-check__label { font-weight: 600; }
    .install-check__detail { font-size: var(--text-xs); color: var(--color-muted); }
    .install-actions { margin-top: var(--space-5); display: flex; gap: var(--space-3); justify-content: flex-end; flex-wrap: wrap; }
    .install-done__icon { line-height: 1; text-align: center; color: var(--color-success); margin-bottom: var(--space-3); }
    .install-done__icon svg { display: inline-block; width: 48px; height: 48px; vertical-align: middle; }
</style>
</head>
<body class="install-body">
<div class="install-wrap">
    <header class="install-head">
        <h1>CourseShop 安装向导</h1>
        <p class="text-muted">SZY 创新工作室 · 课程售卖系统</p>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert--danger" style="margin-bottom: var(--space-4);">
            <?php foreach ($errors as $message): ?>
                <div><?= e($message) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php if ($installLocked && !$success): ?>
    <div class="card">
        <div class="card__body">
            <h2 class="card__header" style="margin-top:0;">系统已安装</h2>
            <?php if ($installed): ?>
                <p class="text-muted">检测到 <code>storage/installed.lock</code>，为安全起见安装向导已锁定，无法重复安装。</p>
                <?php if ($lockRepaired): ?>
                    <p class="text-muted">本次已自动补写此前缺失的安装锁（通常由安装时目录写入失败引起），无需再处理。</p>
                <?php endif; ?>
            <?php else: ?>
                <p class="text-muted">未检测到安装锁，但站点配置或数据库显示系统已安装，为安全起见已阻止重新安装，避免误清空数据。</p>
                <?php if ($lockRepairFailed): ?>
                    <p class="text-muted">自动补写安装锁失败，请确认 <code>storage/installed.lock</code> 所在目录可写（Web 进程用户需有写权限），或手动创建该空文件。</p>
                <?php endif; ?>
            <?php endif; ?>
            <p class="text-muted">如需重新安装：请先备份并清空数据库；若安装仅进行到「写入 .env」而未创建管理员，删除 <code>.env</code> 后重新访问本页面即可继续安装。</p>
            <div class="install-actions">
                <a class="btn btn--outline" href="install.php?step=4">查看安装信息</a>
                <a class="btn" href="<?= e(url('/admin')) ?>">进入管理后台</a>
            </div>
        </div>
    </div>
<?php elseif ($success): ?>
    <ol class="install-steps">
        <?php foreach ($stepLabels as $number => $label): ?>
            <li class="<?= $number < 4 ? 'is-done' : 'is-active' ?>"><?= $number ?>. <?= e($label) ?></li>
        <?php endforeach; ?>
    </ol>
    <div class="card">
        <div class="card__body">
            <div class="install-done__icon" aria-hidden="true">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M8.5 12.5l2.5 2.5 4.5-5"></path></svg>
            </div>
            <h2 style="text-align:center; margin-top:0;">安装完成</h2>
            <p class="text-muted" style="text-align:center;">管理员账号已创建，现在可以登录后台开始配置站点。</p>
            <div class="install-actions" style="justify-content:center;">
                <a class="btn btn--lg" href="<?= e(url('/admin')) ?>">进入管理后台</a>
                <a class="btn btn--outline btn--lg" href="<?= e(url('/')) ?>">访问前台首页</a>
            </div>
            <hr style="border:0; border-top:1px solid var(--color-border); margin: var(--space-5) 0;">
            <p class="install-note">安全建议：</p>
            <ul class="install-note">
                <li>上线后请删除 <code>public/install.php</code>，或确认 <code>storage/installed.lock</code> 存在以阻止重复安装。</li>
                <li>正式环境请修改 <code>.env</code>：<code>APP_ENV=production</code>、<code>APP_DEBUG=false</code>。</li>
                <li>请为站点配置 HTTPS，避免密码与支付回调被窃听。</li>
            </ul>
        </div>
    </div>
<?php else: ?>
    <ol class="install-steps">
        <?php foreach ($stepLabels as $number => $label): ?>
            <li class="<?= $number === $step ? 'is-active' : ($number < $step ? 'is-done' : '') ?>"><?= $number ?>. <?= e($label) ?></li>
        <?php endforeach; ?>
    </ol>

    <?php if ($step === 1): ?>
        <div class="card">
            <div class="card__body">
                <h2 class="card__header" style="margin-top:0;">环境检查</h2>
                <?php foreach ($requirements as $check): ?>
                    <div class="install-check">
                        <span class="install-check__icon <?= $check['ok'] ? 'ok' : 'bad' ?>" aria-hidden="true">
                            <?php if ($check['ok']): ?>
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg>
                            <?php elseif ($check['required']): ?>
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg>
                            <?php else: ?>
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v4"></path><path d="M12 16h.01"></path></svg>
                            <?php endif; ?>
                        </span>
                        <span>
                            <span class="install-check__label"><?= e($check['label']) ?></span>
                            <?php if (!$check['required']): ?><span class="badge badge--info">提示</span><?php endif; ?>
                            <div class="install-check__detail"><?= e($check['detail']) ?></div>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="card__footer">
                <div class="install-actions" style="margin-top:0;">
                    <?php if ($requirementsOk): ?>
                        <a class="btn" href="install.php?step=2">下一步：数据库配置</a>
                    <?php else: ?>
                        <button class="btn" type="button" disabled>请先解决必需项</button>
                        <a class="btn btn--outline" href="install.php?step=1">重新检查</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php elseif ($step === 2): ?>
        <div class="card">
            <div class="card__body">
                <h2 class="card__header" style="margin-top:0;">数据库配置</h2>
                <p class="form-hint">填写 MySQL / MariaDB 连接信息。若数据库不存在，向导会自动创建；为保护数据，若目标库已存在本站数据表，安装将被拒绝。</p>
                <form method="post" action="install.php?step=2">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="install_database">

                    <div class="flex gap-3 flex-wrap">
                        <div class="form-group grow">
                            <label class="form-label" for="db_host">数据库主机 <span class="required">*</span></label>
                            <input class="input" type="text" id="db_host" name="db_host" value="<?= e($keep('db_host', $defaultDb['host'])) ?>" required>
                        </div>
                        <div class="form-group" style="width:120px;">
                            <label class="form-label" for="db_port">端口 <span class="required">*</span></label>
                            <input class="input" type="number" id="db_port" name="db_port" min="1" max="65535" value="<?= e($keep('db_port', $defaultDb['port'])) ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="db_database">数据库名 <span class="required">*</span></label>
                        <input class="input" type="text" id="db_database" name="db_database" value="<?= e($keep('db_database', $defaultDb['database'])) ?>" required>
                    </div>

                    <div class="flex gap-3 flex-wrap">
                        <div class="form-group grow">
                            <label class="form-label" for="db_username">数据库账号 <span class="required">*</span></label>
                            <input class="input" type="text" id="db_username" name="db_username" value="<?= e($keep('db_username', $defaultDb['username'])) ?>" autocomplete="off" required>
                        </div>
                        <div class="form-group grow">
                            <label class="form-label" for="db_password">数据库密码</label>
                            <input class="input" type="password" id="db_password" name="db_password" value="" autocomplete="new-password">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="app_url">站点地址 <span class="required">*</span></label>
                        <input class="input" type="url" id="app_url" name="app_url" value="<?= e($keep('app_url', $defaultDb['app_url'])) ?>" required>
                        <span class="form-hint">用于生成支付回调地址，末尾不要带斜杠。</span>
                    </div>

                    <div class="install-actions">
                        <a class="btn btn--ghost" href="install.php?step=1">上一步</a>
                        <button class="btn" type="submit">开始安装</button>
                    </div>
                </form>
            </div>
        </div>
    <?php elseif ($step === 3): ?>
        <div class="card">
            <div class="card__body">
                <h2 class="card__header" style="margin-top:0;">创建管理员账号</h2>
                <p class="form-hint">该账号拥有后台全部权限，请妥善保管密码。用户名 3-20 位字母、数字或下划线，密码至少 8 位。</p>
                <form method="post" action="install.php?step=3">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="create_admin">

                    <div class="flex gap-3 flex-wrap">
                        <div class="form-group grow">
                            <label class="form-label" for="username">管理员用户名 <span class="required">*</span></label>
                            <input class="input" type="text" id="username" name="username" value="<?= e($keep('username', 'admin')) ?>" autocomplete="off" required>
                        </div>
                        <div class="form-group grow">
                            <label class="form-label" for="nickname">昵称</label>
                            <input class="input" type="text" id="nickname" name="nickname" value="<?= e($keep('nickname')) ?>" autocomplete="off">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="email">邮箱（选填）</label>
                        <input class="input" type="email" id="email" name="email" value="<?= e($keep('email')) ?>" autocomplete="off">
                        <span class="form-hint">填写后会直接标记为已验证；管理员不受「强制绑定邮箱」限制。</span>
                    </div>

                    <div class="flex gap-3 flex-wrap">
                        <div class="form-group grow">
                            <label class="form-label" for="password">登录密码 <span class="required">*</span></label>
                            <input class="input" type="password" id="password" name="password" autocomplete="new-password" required>
                        </div>
                        <div class="form-group grow">
                            <label class="form-label" for="password_confirm">确认密码 <span class="required">*</span></label>
                            <input class="input" type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required>
                        </div>
                    </div>

                    <div class="install-actions">
                        <a class="btn btn--ghost" href="install.php?step=2">上一步</a>
                        <button class="btn" type="submit">完成安装</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
