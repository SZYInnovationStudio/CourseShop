-- ============================================================
-- CourseShop 初始化数据（可重复执行，使用 INSERT IGNORE 保证幂等）
-- 由安装向导在导入 schema.sql 后自动执行
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 系统设置默认值
-- ------------------------------------------------------------
INSERT IGNORE INTO `settings` (`key`, `value`, `group_name`, `description`) VALUES
-- 站点信息
('site_name',              'CourseShop',                                     'site',     '站点名称'),
('site_description',       '专注实用技术的在线课程平台',                       'site',     '站点简介'),
('site_logo',              '',                                               'site',     '站点 Logo 地址，留空使用文字标识与默认图标'),
('site_keywords',          '课程,在线学习,编程,video',                        'site',     'SEO 关键词'),
('site_beian',             '',                                               'site',     'ICP 备案号（留空则不显示）'),
('site_gongan',            '',                                               'site',     '公网安备号（留空则不显示）'),
('site_gongan_url',        'https://beian.mps.gov.cn',                        'site',     '公网安备查询地址'),
('footer_open_source_url', 'https://github.com/SZYInnovationStudio/CourseShop', 'site',  '开源地址'),
-- 注册与账户
('register_enabled',       '1',                                              'security', '是否开放新用户注册'),
('force_email_bind',       '0',                                              'security', '是否强制绑定邮箱'),
('login_fail_captcha_threshold', '3',                                        'security', '登录失败多少次后强制图形验证码'),
('login_max_fail',         '10',                                             'security', '同一账号/IP 连续失败上限（超出临时锁定）'),
('login_lock_minutes',     '10',                                             'security', '登录失败锁定时长（分钟）'),
('session_lifetime',       '7200',                                           'security', '会话有效期（秒）'),
('allow_multi_device',     '1',                                              'security', '是否允许多设备同时在线'),
-- 图形验证码
('captcha_enabled',        '1',                                              'captcha',  '是否启用图形验证码'),
('captcha_length',         '4',                                              'captcha',  '验证码字符长度'),
('captcha_expire_seconds', '300',                                            'captcha',  '验证码有效期（秒）'),
('captcha_case_sensitive', '0',                                              'captcha',  '是否区分大小写'),
-- 支付
('epay_enabled',           '0',                                              'payment',  '是否启用易支付'),
('epay_api_url',           'https://zpayz.cn',                               'payment',  '易支付接口地址（不含结尾斜杠）'),
('epay_pid',               '',                                               'payment',  '易支付商户 ID'),
('epay_key',               '',                                               'payment',  '易支付商户密钥'),
('epay_sign_type',         'MD5',                                            'payment',  '签名算法：MD5'),
('epay_wxpay_enabled',     '1',                                              'payment',  '开启微信支付通道'),
('epay_alipay_enabled',    '1',                                              'payment',  '开启支付宝支付通道'),
('epay_notify_ip_whitelist','',                                              'payment',  '异步通知 IP 白名单（逗号分隔，留空不校验）'),
('order_expire_minutes',   '15',                                             'payment',  '未支付订单自动关闭时间（分钟）'),
-- 邮件
('mail_enabled',           '0',                                              'mail',     '是否启用邮件发送'),
('mail_queue_enabled',     '1',                                              'mail',     '是否使用队列异步发送邮件'),
('mail_host',              '',                                               'mail',     'SMTP 服务器地址'),
('mail_port',              '465',                                            'mail',     'SMTP 端口'),
('mail_encryption',        'ssl',                                            'mail',     '加密方式：ssl/tls/none'),
('mail_username',          '',                                               'mail',     'SMTP 账号'),
('mail_password',          '',                                               'mail',     'SMTP 密码'),
('mail_from_address',      '',                                               'mail',     '发件人邮箱'),
('mail_from_name',         'CourseShop',                                     'mail',     '发件人名称'),
('mail_code_ttl',          '600',                                            'mail',     '邮箱验证码有效期（秒）'),
-- 工单
('ticket_enabled',         '1',                                              'ticket',   '是否允许提交工单'),
('ticket_attachment_types','jpg,jpeg,png,gif,pdf,zip,rar,7z,txt',            'ticket',   '工单附件允许的扩展名'),
('ticket_attachment_max_mb','10',                                            'ticket',   '工单附件大小上限（MB）'),
-- 课程与套餐
('packages_enabled',       '1',                                              'catalog',  '是否在前台显示优惠套餐'),
-- 视频
('video_signed_ttl',       '1800',                                           'video',    '视频访问签名有效期（秒）'),
('video_storage_driver',   'local',                                          'video',    '视频存储驱动：local/oss/cos/s3'),
('video_preview_frame',    '1',                                              'video',    '是否自动截取第一帧作为课程封面（需 ffmpeg）'),
('ffmpeg_path',            '',                                               'video',    'ffmpeg 可执行文件路径（留空则自动查找）'),
-- 主题
('theme_default_mode',     'system',                                         'theme',    '默认主题：light/dark/system'),
('theme_primary_color',    '#4F6F52',                                        'theme',    '主强调色'),
('theme_allow_user_switch','1',                                              'theme',    '是否允许用户自行切换主题'),
-- 日志
('log_retention_days',     '365',                                            'log',      '日志保留天数');

-- ------------------------------------------------------------
-- 默认协议（用户协议 / 隐私政策 / 退款政策）
-- ------------------------------------------------------------
INSERT IGNORE INTO `agreements` (`type`, `version`, `title`, `content`, `effective_at`, `is_current`) VALUES
('terms', '1.0.0', '用户协议', '## 一、总则\r\n\r\n欢迎使用 CourseShop（以下简称“本平台”）。在使用本平台服务前，请您仔细阅读本协议全部内容。您注册、登录或使用本平台任一服务，即视为您已阅读并同意本协议。\r\n\r\n## 二、账号管理\r\n\r\n1. 您应提供真实、准确、完整的注册信息，并及时更新。\r\n2. 账号仅限本人使用，不得出租、出借、转让或售卖。\r\n3. 您需妥善保管账号与密码，因保管不善造成的损失由您自行承担。\r\n\r\n## 三、课程与内容\r\n\r\n1. 本平台课程内容受著作权法保护，仅授予您个人非商业性的在线学习权利。\r\n2. 禁止录制、下载、转售、传播课程内容，违者本平台有权封禁账号并追究法律责任。\r\n\r\n## 四、支付与退款\r\n\r\n1. 课程价格以下单时页面显示为准，支付成功后自动开通。\r\n2. 虚拟内容具有即时交付特性，退款规则以《退款政策》为准。\r\n\r\n## 五、行为规范\r\n\r\n您不得利用本平台从事任何违法违规活动，不得干扰平台正常运行。\r\n\r\n## 六、协议变更\r\n\r\n本平台有权根据法律法规及业务需要更新本协议，更新后将通过站内公告提示。', NOW(), 1),
('privacy', '1.0.0', '隐私政策', '## 一、我们收集的信息\r\n\r\n1. 账号信息：用户名、邮箱、密码（加密存储）。\r\n2. 使用信息：登录时间、登录 IP、浏览与播放记录。\r\n3. 交易信息：订单号、支付方式、支付状态（不存储您的银行卡号与支付密码）。\r\n\r\n## 二、信息的使用\r\n\r\n我们仅将上述信息用于提供课程服务、保障账号安全、完成订单处理与必要的服务通知。\r\n\r\n## 三、信息的存储与保护\r\n\r\n1. 数据存储于中国境内服务器。\r\n2. 我们采用密码哈希、传输加密、访问控制等措施保护您的信息安全。\r\n\r\n## 四、信息的共享\r\n\r\n除完成支付所必需的支付服务商外，我们不会向任何第三方出售或提供您的个人信息，法律法规另有规定的除外。\r\n\r\n## 五、您的权利\r\n\r\n您有权查询、更正、删除您的个人信息，也可申请注销账号。注销后我们将对您的个人信息进行匿名化处理，并依法保留交易记录。\r\n\r\n## 六、联系我们\r\n\r\n如对本政策有疑问，可通过站内工单与我们联系。', NOW(), 1),
('refund', '1.0.0', '退款政策', '## 一、适用范围\r\n\r\n本政策适用于本平台所有在线课程订单。\r\n\r\n## 二、可退款情形\r\n\r\n1. 支付成功但课程未成功开通，且平台无法在 24 小时内修复的，可全额退款。\r\n2. 同一课程重复支付（含重复下单后均支付成功）的，多余订单可全额退款。\r\n3. 课程内容与页面描述存在实质不符，经核实后可退款。\r\n\r\n## 三、不予退款情形\r\n\r\n1. 课程已实际观看超过 10% 或已下载相关资料。\r\n2. 因个人原因（如时间冲突、设备不兼容）放弃学习。\r\n3. 账号因违规被封禁的。\r\n\r\n## 四、退款流程\r\n\r\n1. 通过站内工单提交退款申请，并说明订单号与理由。\r\n2. 平台在 3 个工作日内完成审核。\r\n3. 审核通过后按原支付渠道退回，到账时间以支付渠道为准。', NOW(), 1);

-- ------------------------------------------------------------
-- 角色与权限（P2 RBAC 使用，P0 通过 users.is_admin 判定）
-- ------------------------------------------------------------
INSERT IGNORE INTO `roles` (`id`, `code`, `name`, `description`, `is_system`) VALUES
(1, 'super_admin', '超级管理员', '拥有全部权限，不可被删除', 1),
(2, 'admin',       '管理员',     '日常运营管理权限',         1),
(3, 'support',     '客服',       '工单与用户协助权限',       1),
(4, 'operator',    '运营',       '课程与订单运营权限',       1),
(5, 'user',        '普通用户',   '前台基础权限',             1);

INSERT IGNORE INTO `permissions` (`code`, `name`, `group_name`) VALUES
('dashboard.view',      '查看仪表盘',   'dashboard'),
('user.view',           '查看用户',     'user'),
('user.manage',         '管理用户',     'user'),
('user.ban',            '封禁用户',     'user'),
('course.view',         '查看课程',     'course'),
('course.manage',       '管理课程',     'course'),
('chapter.manage',      '管理章节',     'course'),
('order.view',          '查看订单',     'order'),
('order.manage',        '管理订单',     'order'),
('order.refund',        '订单退款',     'order'),
('coupon.view',         '查看优惠券',   'coupon'),
('coupon.manage',       '管理优惠券',   'coupon'),
('package.view',        '查看套餐',     'package'),
('package.manage',      '管理套餐',     'package'),
('report.view',         '查看经营报表', 'report'),
('ticket.view',         '查看工单',     'ticket'),
('ticket.reply',        '回复工单',     'ticket'),
('ticket.manage',       '管理工单',     'ticket'),
('setting.view',        '查看设置',     'setting'),
('setting.manage',      '修改设置',     'setting'),
('log.view',            '查看日志',     'system'),
('rbac.manage',         '角色权限管理', 'system');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, `id` FROM `permissions`;

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, `id` FROM `permissions` WHERE `code` IN
('dashboard.view','user.view','user.manage','user.ban','course.view','course.manage','chapter.manage',
 'order.view','order.manage','coupon.view','coupon.manage','package.view','package.manage','report.view','ticket.view','ticket.reply','ticket.manage','setting.view','log.view');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 3, `id` FROM `permissions` WHERE `code` IN
('dashboard.view','user.view','ticket.view','ticket.reply','ticket.manage','order.view');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 4, `id` FROM `permissions` WHERE `code` IN
('dashboard.view','course.view','course.manage','chapter.manage','order.view','coupon.view','package.view','report.view');

-- 说明：本文件仅包含系统默认配置（设置、协议、角色与权限），不含任何演示课程 / 分类 / 标签 / 公告。
