<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Web 安装向导核心逻辑
 *
 * 职责：
 * 1. 环境检查（PHP 版本、扩展、目录可写、SQL 文件可读）
 * 2. 创建数据库并导入 database/schema.sql 与 database/seed.sql
 * 3. 写入 .env 配置文件（Env 类只读，这里自行实现序列化）
 * 4. 创建初始管理员账号
 * 5. 写入安装锁，防止重复安装
 *
 * 所有方法都可能抛出异常，调用方（public/install.php）必须整体 try/catch，
 * 避免 ErrorHandler 把告警转成异常后直接渲染 500 错误页。
 */
final class Installer
{
    /** 最低 PHP 版本要求 */
    public const MIN_PHP = '8.1.0';

    /** 必需的 PHP 扩展：扩展名 => 说明 */
    private const REQUIRED_EXTENSIONS = [
        'pdo_mysql' => 'PDO MySQL 驱动',
        'mbstring'  => '多字节字符串处理',
        'gd'        => '图形验证码生成',
        'fileinfo'  => '上传文件类型检测',
        'openssl'   => '邮件加密与签名',
    ];

    /** 相对 BASE_PATH 的需要可写的目录 */
    private const WRITABLE_DIRS = [
        'storage',
        'storage/cache',
        'storage/logs',
        'storage/uploads',
        'storage/private',
        'storage/private/videos',
    ];

    // ------------------------------------------------------------------
    // 安装状态
    // ------------------------------------------------------------------

    public static function lockFile(): string
    {
        return STORAGE_PATH . '/installed.lock';
    }

    public static function isInstalled(): bool
    {
        return is_file(self::lockFile());
    }

    /**
     * .env 配置文件是否已存在
     *
     * 安装向导成功写入 .env 是「站点已部署」的强信号：即使安装锁文件丢失，
     * 只要存在 .env，就禁止通过公开安装入口重新安装并覆盖线上数据库配置。
     */
    public static function envExists(): bool
    {
        return is_file(self::envPath());
    }

    /**
     * 数据库是否已存在管理员账号
     *
     * 用于在安装锁文件缺失时兜底：只要库中已有管理员，就视为「已安装」，
     * 阻止再次执行安装脚本（schema.sql 以 DROP TABLE 开头，会清空数据）。
     */
    public static function hasExistingAdmin(): bool
    {
        try {
            [$ready] = self::databaseReady();

            if (!$ready) {
                return false;
            }

            $count = (int) Database::scalar(
                "SELECT COUNT(*) FROM `users`
                  WHERE `deleted_at` IS NULL
                    AND (`is_admin` = 1 OR `role` IN ('admin', 'super_admin'))"
            );

            return $count > 0;
        } catch (Throwable) {
            // 无法判断数据库状态时采取「保守」策略：已有 .env 说明曾安装过，
            // 按已安装处理以阻止重装；连 .env 都没有才视为全新安装放行。
            return self::envExists();
        }
    }

    /**
     * 写入安装锁（记录安装时间与站点信息）
     */
    public static function lock(string $adminUsername): void
    {
        $payload = json_encode([
            'installed_at'    => date('Y-m-d H:i:s'),
            'php_version'     => PHP_VERSION,
            'app_url'         => (string) Config::get('app.url', ''),
            'admin_username'  => $adminUsername,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if (@file_put_contents(self::lockFile(), (string) $payload, LOCK_EX) === false) {
            throw new RuntimeException('无法写入安装锁文件，请检查 storage/ 目录权限。');
        }
    }

    // ------------------------------------------------------------------
    // 环境检查
    // ------------------------------------------------------------------

    /**
     * 逐项检查运行环境
     *
     * @return array<int, array{label: string, ok: bool, detail: string, required: bool}>
     */
    public static function requirements(): array
    {
        $checks = [];

        $checks[] = self::check(
            'PHP 版本 ≥ ' . self::MIN_PHP,
            version_compare(PHP_VERSION, self::MIN_PHP, '>='),
            '当前版本 ' . PHP_VERSION,
            true
        );

        foreach (self::REQUIRED_EXTENSIONS as $extension => $description) {
            $loaded   = extension_loaded($extension);
            $checks[] = self::check(
                $description . '（' . $extension . '）',
                $loaded,
                $loaded ? '已启用' : '未启用，请在 php.ini 中开启',
                true
            );
        }

        // 目录可写（不存在时尝试自动创建）
        foreach (self::WRITABLE_DIRS as $relative) {
            $path = BASE_PATH . '/' . $relative;

            if (!is_dir($path)) {
                $created = @mkdir($path, 0755, true) || is_dir($path);
                $checks[] = self::check(
                    $relative . '/ 可写',
                    $created && is_writable($path),
                    $created ? '已自动创建' : '不存在且无法创建',
                    true
                );
                continue;
            }

            $writable = is_writable($path);
            $checks[] = self::check($relative . '/ 可写', $writable, $writable ? '可写' : '不可写，请调整目录权限', true);
        }

        // .env 可写（不存在时要求项目根目录可写）
        $envPath    = self::envPath();
        $envWritable = is_file($envPath) ? is_writable($envPath) : is_writable(BASE_PATH);
        $checks[]   = self::check('.env 配置文件可写', $envWritable, $envWritable ? '可写' : '不可写，请调整权限', true);

        // SQL 脚本可读
        foreach (['database/schema.sql', 'database/seed.sql'] as $relative) {
            $readable = is_readable(BASE_PATH . '/' . $relative);
            $checks[] = self::check($relative . ' 可读', $readable, $readable ? '可读' : '文件缺失或不可读', true);
        }

        // 上传限制提示（非必需项，仅作提醒）
        $checks[] = self::check(
            '上传限制 post_max_size = ' . (string) ini_get('post_max_size')
                . '，upload_max_filesize = ' . (string) ini_get('upload_max_filesize'),
            true,
            '如需上传大体积视频，请在 php.ini 中调大这两项并重启服务',
            false
        );

        return $checks;
    }

    /**
     * 必需项是否全部通过
     *
     * @param array<int, array{label: string, ok: bool, detail: string, required: bool}> $checks
     */
    public static function requirementsPassed(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['required'] && !$check['ok']) {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------
    // 数据库安装
    // ------------------------------------------------------------------

    /**
     * 创建数据库、导入结构与初始数据
     *
     * @param  array{host: string, port: int, database: string, username: string, password: string, charset: string} $db
     * @return array{schema: int, seed: int} 各脚本执行的语句条数
     */
    public static function installDatabase(array $db): array
    {
        $database = (string) $db['database'];
        $charset  = self::normalizeCharset((string) ($db['charset'] ?? ''));

        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
            throw new RuntimeException('数据库名只能包含字母、数字与下划线，且不超过 64 个字符。');
        }

        // 1. 先不指定库名连接，创建数据库（已存在则跳过）
        $server = self::connect($db, false);
        $server->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` DEFAULT CHARACTER SET %s COLLATE %s_unicode_ci',
            $database,
            $charset,
            $charset
        ));

        // 2. 连接目标库，导入结构与初始数据
        $pdo = self::connect($db, true);
        $pdo->exec('SET NAMES ' . $charset);

        // 安全检查：目标库若已存在本站业务表，说明这里不是全新数据库。
        // schema.sql 以 DROP TABLE 开头，绝不允许从公开安装入口覆盖既有数据，
        // 必须改用空数据库或在离线维护窗口手动处理。
        $existing = self::existingBusinessTables($pdo, $database);
        if ($existing !== []) {
            throw new RuntimeException(
                '目标数据库已存在 CourseShop 数据表（' . implode('、', $existing)
                . '），为防止清空数据已中止安装。请改用空数据库，或在离线维护窗口手动处理。'
            );
        }

        return [
            'schema' => self::runSqlFile($pdo, BASE_PATH . '/database/schema.sql'),
            'seed'   => self::runSqlFile($pdo, BASE_PATH . '/database/seed.sql'),
        ];
    }

    /**
     * 数据库是否已就绪（用于步骤守卫）
     *
     * @return array{0: bool, 1: string}
     */
    public static function databaseReady(): array
    {
        try {
            $count = (int) Database::scalar(
                "SELECT COUNT(*) FROM `information_schema`.`tables`
                  WHERE `table_schema` = DATABASE()
                    AND `table_name` IN ('users', 'settings', 'courses')"
            );
        } catch (Throwable $e) {
            return [false, '数据库尚未就绪：' . $e->getMessage()];
        }

        if ($count < 3) {
            return [false, '数据表不完整，请重新执行「数据库配置」步骤。'];
        }

        return [true, '数据库已就绪'];
    }

    /**
     * 创建初始管理员账号，返回用户 ID
     */
    public static function createAdmin(string $username, string $password, ?string $email = null, ?string $nickname = null): int
    {
        if (Database::scalar('SELECT COUNT(*) FROM `users` WHERE `username` = ?', [$username]) > 0) {
            throw new RuntimeException('该用户名已存在，请更换后重试。');
        }

        if ($email !== null && Database::scalar('SELECT COUNT(*) FROM `users` WHERE `email` = ?', [$email]) > 0) {
            throw new RuntimeException('该邮箱已被占用，请更换后重试。');
        }

        $userId = Database::insert(
            'INSERT INTO `users`
                (`username`, `nickname`, `email`, `password_hash`, `role`, `is_admin`, `status`,
                 `email_verified_at`, `register_ip`, `register_ua`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, ?, \'admin\', 1, 1, NOW(), ?, ?, NOW(), NOW())',
            [
                $username,
                $nickname ?? $username,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                self::clientIp(),
                self::userAgent(),
            ]
        );

        // 为初始管理员绑定 super_admin 角色（RBAC），确保其拥有全部权限
        $roleId = (int) Database::scalar(
            "SELECT `id` FROM `roles` WHERE `code` = 'super_admin' AND `deleted_at` IS NULL LIMIT 1"
        );

        if ($roleId > 0) {
            Database::execute(
                'INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)',
                [$userId, $roleId]
            );
        }

        return $userId;
    }

    // ------------------------------------------------------------------
    // .env 写入
    // ------------------------------------------------------------------

    public static function envPath(): string
    {
        return BASE_PATH . '/.env';
    }

    /**
     * 生成 32 位随机应用密钥
     */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * 根据当前请求推导默认站点地址
     */
    public static function defaultAppUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');

        return ($https ? 'https://' : 'http://') . $host;
    }

    /**
     * 写入 .env（已存在则覆盖）
     *
     * @param array{app_url: string, app_key: string, db_host: string, db_port: int,
     *              db_database: string, db_username: string, db_password: string, db_charset: string} $values
     */
    public static function writeEnv(array $values): void
    {
        $path = self::envPath();

        if (is_file($path) && !is_writable($path)) {
            throw new RuntimeException('.env 文件不可写，请调整权限后重试。');
        }

        if (!is_file($path) && !is_writable(BASE_PATH)) {
            throw new RuntimeException('项目根目录不可写，无法生成 .env 文件。');
        }

        $content = self::buildEnvContent($values);

        if (@file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException('写入 .env 失败，请检查目录权限。');
        }
    }

    /**
     * @param array{app_url: string, app_key: string, db_host: string, db_port: int,
     *              db_database: string, db_username: string, db_password: string, db_charset: string} $values
     */
    private static function buildEnvContent(array $values): string
    {
        // 默认按「生产环境 + 关闭调试」生成，避免上线后泄露重置验证码与调用栈；
        // 仅当站点地址为本地开发地址时，才生成 local + debug 便于联调。
        $host    = strtolower((string) parse_url((string) ($values['app_url'] ?? ''), PHP_URL_HOST));
        $isLocal = $host === ''
            || $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test');

        $lines = [
            '# ============================================================',
            '# CourseShop 环境配置（由安装向导于 ' . date('Y-m-d H:i:s') . ' 自动生成）',
            '# 修改数据库连接信息后需重新访问站点使其生效。',
            '# ============================================================',
            '',
            '# 运行环境：local | production（正式上线请改为 production）',
            'APP_ENV=' . ($isLocal ? 'local' : 'production'),
            '',
            '# 调试模式：生产环境必须为 false',
            'APP_DEBUG=' . ($isLocal ? 'true' : 'false'),
            '',
            '# 站点根地址（不要以 / 结尾），用于生成支付回调地址',
            'APP_URL=' . self::envValue($values['app_url']),
            '',
            '# 应用密钥：用于签名一次性令牌，请勿泄露',
            'APP_KEY=' . self::envValue($values['app_key']),
            '',
            '# 时区',
            'APP_TIMEZONE=Asia/Shanghai',
            '',
            '# ---------------- 数据库 ----------------',
            'DB_HOST=' . self::envValue($values['db_host']),
            'DB_PORT=' . (int) $values['db_port'],
            'DB_DATABASE=' . self::envValue($values['db_database']),
            'DB_USERNAME=' . self::envValue($values['db_username']),
            'DB_PASSWORD=' . self::envValue($values['db_password']),
            'DB_CHARSET=' . self::envValue($values['db_charset'] !== '' ? $values['db_charset'] : 'utf8mb4'),
            '',
            '# ---------------- 会话 ----------------',
            'SESSION_NAME=courseshop_session',
            'SESSION_LIFETIME=7200',
            '',
            '# ---------------- 上传限制 ----------------',
            'UPLOAD_MAX_VIDEO_MB=2048',
            'UPLOAD_MAX_ATTACHMENT_MB=10',
            '',
            '# ---------------- 视频存储 ----------------',
            'VIDEO_DISK=local',
            'VIDEO_LOCAL_ROOT=',
            'VIDEO_URL_TTL=7200',
            '',
        ];

        return implode("\n", $lines);
    }

    /**
     * 序列化单个配置值：包含空白或 # 时使用双引号包裹
     */
    private static function envValue(string $value): string
    {
        // 换行会破坏 .env 结构，直接剔除
        $value = str_replace(["\r", "\n"], '', $value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/[\s#"\'\\\\]/', $value) === 1) {
            return '"' . str_replace('"', '', $value) . '"';
        }

        return $value;
    }

    // ------------------------------------------------------------------
    // 内部工具
    // ------------------------------------------------------------------

    /**
     * @param array{host: string, port: int, database: string, username: string, password: string, charset: string} $db
     */
    private static function connect(array $db, bool $withDatabase): PDO
    {
        $charset = self::normalizeCharset((string) ($db['charset'] ?? ''));

        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s',
            (string) $db['host'],
            (int) $db['port'],
            $charset
        );

        if ($withDatabase) {
            $dsn .= ';dbname=' . (string) $db['database'];
        }

        try {
            return new PDO($dsn, (string) $db['username'], (string) $db['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('数据库连接失败：' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 目标库中已存在的 CourseShop 业务表
     *
     * 用于在导入（含 DROP TABLE）前阻止从公开入口覆盖既有数据。
     *
     * @return array<int, string>
     */
    private static function existingBusinessTables(PDO $pdo, string $database): array
    {
        $known = [
            'users', 'courses', 'chapters', 'packages', 'package_courses',
            'orders', 'order_logs', 'enrollments', 'coupons', 'coupon_usages',
            'refunds', 'tickets', 'ticket_replies', 'ticket_attachments',
            'settings', 'migrations',
        ];

        $placeholders = implode(', ', array_fill(0, count($known), '?'));
        $statement = $pdo->prepare(
            'SELECT `table_name` FROM `information_schema`.`tables`
              WHERE `table_schema` = ?
                AND `table_name` IN (' . $placeholders . ')'
        );
        $statement->execute(array_merge([$database], $known));

        $found = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $found[] = (string) $table;
        }

        return $found;
    }

    /**
     * 执行一个 SQL 脚本文件，返回成功执行的语句条数
     */
    private static function runSqlFile(PDO $pdo, string $file): int
    {
        if (!is_readable($file)) {
            throw new RuntimeException('SQL 文件不可读：' . basename($file));
        }

        $content = (string) file_get_contents($file);
        // 去掉 UTF-8 BOM
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        $count = 0;
        foreach (self::splitStatements($content) as $statement) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                throw new RuntimeException(
                    sprintf('执行 SQL 失败：%s（语句：%s…）', $e->getMessage(), mb_substr($statement, 0, 80)),
                    0,
                    $e
                );
            }
            $count++;
        }

        return $count;
    }

    /**
     * 按分号切分 SQL 脚本
     *
     * 正确处理：单引号 / 双引号 / 反引号包裹的字符串、反斜杠转义、`--` 与 `#` 行注释、块注释。
     *
     * @return array<int, string>
     */
    private static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer     = '';
        $length     = strlen($sql);
        $quote      = '';   // 当前字符串定界符：' " ` 之一，空表示不在字符串内

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($quote === '') {
                // 行注释：-- 或 #
                if (($char === '-' && $next === '-') || $char === '#') {
                    while ($i < $length && $sql[$i] !== "\n") {
                        $i++;
                    }
                    $buffer .= "\n";
                    continue;
                }

                // 块注释：/* ... */
                if ($char === '/' && $next === '*') {
                    $end = strpos($sql, '*/', $i + 2);
                    $i   = $end === false ? $length : $end + 1;
                    continue;
                }

                // 语句结束
                if ($char === ';') {
                    $trimmed = trim($buffer);
                    if ($trimmed !== '') {
                        $statements[] = $trimmed;
                    }
                    $buffer = '';
                    continue;
                }

                if ($char === "'" || $char === '"' || $char === '`') {
                    $quote = $char;
                }

                $buffer .= $char;
                continue;
            }

            // 字符串内部：反斜杠转义，连同下一个字符一起吞掉
            if ($char === '\\') {
                $buffer .= $char . $next;
                $i++;
                continue;
            }

            if ($char === $quote) {
                // 连续两个定界符视为字面量（如 ''）
                if ($next === $quote) {
                    $buffer .= $char . $next;
                    $i++;
                    continue;
                }
                $quote = '';
            }

            $buffer .= $char;
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    /**
     * 归一化字符集：仅允许字母、数字与下划线（防止拼接进 SQL 时被注入），非法值回退 utf8mb4
     */
    private static function normalizeCharset(string $charset): string
    {
        return preg_match('/^[A-Za-z0-9_]{1,32}$/', $charset) === 1 ? $charset : 'utf8mb4';
    }

    /**
     * @return array{label: string, ok: bool, detail: string, required: bool}
     */
    private static function check(string $label, bool $ok, string $detail, bool $required): array
    {
        return [
            'label'    => $label,
            'ok'       => $ok,
            'detail'   => $detail,
            'required' => $required,
        ];
    }

    private static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    private static function userAgent(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'installer'), 0, 255);
    }
}
