-- ============================================================
-- CourseShop 数据库结构（MySQL 5.7+ / MariaDB 10.4+ 兼容）
-- 引擎：InnoDB   字符集：utf8mb4   排序规则：utf8mb4_unicode_ci
-- 约定：金额统一使用 int 存储「分」；所有表含 created_at/updated_at/软删除字段
-- 注意：本文件由安装向导自动执行，也可手动执行。
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 一、用户与权限
-- ============================================================

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `username`            VARCHAR(50)     NOT NULL COMMENT '登录名',
  `nickname`            VARCHAR(50)     NOT NULL DEFAULT '' COMMENT '昵称',
  `email`               VARCHAR(190)    DEFAULT NULL COMMENT '邮箱（未绑定时为空）',
  `password_hash`       VARCHAR(255)    NOT NULL COMMENT '密码哈希（password_hash）',
  `avatar`              VARCHAR(255)    DEFAULT NULL COMMENT '头像相对路径',
  `role`                VARCHAR(20)     NOT NULL DEFAULT 'user' COMMENT '角色：user/admin/P2 扩展 super_admin/support/operator',
  `is_admin`            TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否管理员：1 是',
  `status`              TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '账户状态：1 正常 0 禁用',
  `email_verified_at`   DATETIME        DEFAULT NULL COMMENT '邮箱验证时间，NULL 表示未绑定/未验证',
  `ban_type`            VARCHAR(10)     NOT NULL DEFAULT 'none' COMMENT '封禁类型：none/temp/permanent',
  `banned_until`        DATETIME        DEFAULT NULL COMMENT '临时封禁到期时间',
  `ban_reason`          VARCHAR(255)    DEFAULT NULL COMMENT '当前封禁原因',
  `dark_mode`           VARCHAR(10)     NOT NULL DEFAULT 'system' COMMENT '主题偏好：light/dark/system',
  `two_factor_secret`   VARCHAR(64)     DEFAULT NULL COMMENT '2FA 密钥（P2）',
  `two_factor_enabled`  TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否启用 2FA（P2）',
  `two_factor_last_step` BIGINT UNSIGNED DEFAULT NULL COMMENT '2FA 上次通过校验的时间步（防重放）',
  `last_login_at`       DATETIME        DEFAULT NULL COMMENT '最后登录时间',
  `last_login_ip`       VARCHAR(45)     DEFAULT NULL COMMENT '最后登录 IP',
  `register_ip`         VARCHAR(45)     DEFAULT NULL COMMENT '注册 IP',
  `register_ua`         VARCHAR(255)    DEFAULT NULL COMMENT '注册 User-Agent',
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at`          DATETIME        DEFAULT NULL COMMENT '软删除时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_username` (`username`),
  UNIQUE KEY `uk_users_email` (`email`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_ban` (`ban_type`, `banned_until`),
  KEY `idx_users_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户表';

-- P2：RBAC 角色与权限
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(50)     NOT NULL COMMENT '角色标识：super_admin/admin/support/operator/user',
  `name`        VARCHAR(50)     NOT NULL COMMENT '角色名称',
  `description` VARCHAR(255)    DEFAULT NULL COMMENT '说明',
  `is_system`   TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否系统内置角色（不可删除）',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`  DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_roles_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色表（P2）';

DROP TABLE IF EXISTS `permissions`;
CREATE TABLE `permissions` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(100)    NOT NULL COMMENT '权限标识，如 course.create',
  `name`        VARCHAR(100)    NOT NULL COMMENT '权限名称',
  `group_name`  VARCHAR(50)     NOT NULL DEFAULT 'general' COMMENT '权限分组',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`  DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_permissions_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='权限表（P2）';

DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE `role_permissions` (
  `role_id`       BIGINT UNSIGNED NOT NULL,
  `permission_id` BIGINT UNSIGNED NOT NULL,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`role_id`, `permission_id`),
  KEY `idx_rp_permission` (`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色权限关联（P2）';

DROP TABLE IF EXISTS `user_roles`;
CREATE TABLE `user_roles` (
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `role_id`    BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `role_id`),
  KEY `idx_ur_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户角色关联（P2）';

-- P2：登录设备
DROP TABLE IF EXISTS `login_devices`;
CREATE TABLE `login_devices` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        BIGINT UNSIGNED NOT NULL,
  `session_token`  VARCHAR(64)     NOT NULL COMMENT '会话指纹',
  `ip`             VARCHAR(45)     DEFAULT NULL,
  `ua`             VARCHAR(255)    DEFAULT NULL,
  `last_active_at` DATETIME        DEFAULT NULL,
  `revoked_at`     DATETIME        DEFAULT NULL,
  `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_login_devices_token` (`session_token`),
  KEY `idx_login_devices_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='登录设备（P2）';

-- ============================================================
-- 二、课程
-- ============================================================

DROP TABLE IF EXISTS `course_categories`;
CREATE TABLE `course_categories` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id`  BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '父分类，0 为顶级',
  `name`       VARCHAR(50)     NOT NULL COMMENT '分类名称',
  `sort`       INT             NOT NULL DEFAULT 0 COMMENT '排序，越小越前',
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_categories_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='课程分类';

DROP TABLE IF EXISTS `course_tags`;
CREATE TABLE `course_tags` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(50)     NOT NULL COMMENT '标签名称',
  `sort`       INT             NOT NULL DEFAULT 0,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_course_tags_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='课程标签';

DROP TABLE IF EXISTS `courses`;
CREATE TABLE `courses` (
  `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`                 VARCHAR(150)    NOT NULL COMMENT '课程名称',
  `subtitle`              VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '副标题',
  `category_id`           BIGINT UNSIGNED DEFAULT NULL COMMENT '分类 ID',
  `summary`               VARCHAR(500)    NOT NULL DEFAULT '' COMMENT '简介',
  `content`               MEDIUMTEXT      COMMENT '详情（Markdown）',
  `cover`                 VARCHAR(255)    DEFAULT NULL COMMENT '课程封面/预览图（默认取第一章节视频首帧）',
  `price`                 INT             NOT NULL DEFAULT 0 COMMENT '售价（分）',
  `original_price`        INT             NOT NULL DEFAULT 0 COMMENT '划线价（分），0 表示不展示',
  `preview_enabled`       TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否允许试看',
  `preview_chapter_count` INT             NOT NULL DEFAULT 0 COMMENT '允许试看的章节数（前 N 节）',
  `status`                VARCHAR(10)     NOT NULL DEFAULT 'draft' COMMENT '状态：draft 草稿 / published 上架 / offline 下架',
  `sort`                  INT             NOT NULL DEFAULT 0 COMMENT '排序权重，越大越前',
  `view_count`            INT             NOT NULL DEFAULT 0 COMMENT '浏览量',
  `sales_count`           INT             NOT NULL DEFAULT 0 COMMENT '销量（已支付订单数）',
  `created_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`            DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_courses_status_sort` (`status`, `sort`),
  KEY `idx_courses_category` (`category_id`),
  KEY `idx_courses_deleted` (`deleted_at`),
  FULLTEXT KEY `ft_courses_search` (`title`, `summary`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='课程表';

DROP TABLE IF EXISTS `course_tag_relations`;
CREATE TABLE `course_tag_relations` (
  `course_id`  BIGINT UNSIGNED NOT NULL,
  `tag_id`     BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`course_id`, `tag_id`),
  KEY `idx_ctr_tag` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='课程-标签关联';

DROP TABLE IF EXISTS `chapters`;
CREATE TABLE `chapters` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `course_id`     BIGINT UNSIGNED NOT NULL COMMENT '所属课程',
  `title`         VARCHAR(150)    NOT NULL COMMENT '章节名称',
  `video_path`    VARCHAR(255)    DEFAULT NULL COMMENT '视频存储键（非公开路径）',
  `video_disk`    VARCHAR(20)     NOT NULL DEFAULT 'local' COMMENT '存储驱动：local/oss/cos/s3',
  `duration`      INT             NOT NULL DEFAULT 0 COMMENT '时长（秒）',
  `file_size`     BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '文件大小（字节）',
  `is_preview`    TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否试看章节',
  `sort`          INT             NOT NULL DEFAULT 0 COMMENT '排序，越小越前',
  `status`        TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '状态：1 正常 0 隐藏',
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_chapters_course_sort` (`course_id`, `sort`),
  KEY `idx_chapters_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='课程章节';

-- P2：视频转码任务
DROP TABLE IF EXISTS `video_transcodes`;
CREATE TABLE `video_transcodes` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chapter_id`  BIGINT UNSIGNED NOT NULL,
  `target`      VARCHAR(20)     NOT NULL DEFAULT 'hls' COMMENT '目标格式',
  `status`      VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending/running/success/failed',
  `progress`    TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '进度 0-100',
  `output_path` VARCHAR(255)    DEFAULT NULL,
  `error`       TEXT,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`  DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_transcodes_chapter` (`chapter_id`),
  KEY `idx_transcodes_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='视频转码任务（P2）';

-- P2：课程套餐（多门课程打包售卖）
DROP TABLE IF EXISTS `packages`;
CREATE TABLE `packages` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`          VARCHAR(150)    NOT NULL COMMENT '套餐名称',
  `subtitle`       VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '副标题',
  `summary`        VARCHAR(500)    NOT NULL DEFAULT '' COMMENT '简介',
  `content`        MEDIUMTEXT      COMMENT '详情（Markdown）',
  `cover`          VARCHAR(255)    DEFAULT NULL COMMENT '封面图',
  `price`          INT             NOT NULL DEFAULT 0 COMMENT '套餐售价（分）',
  `original_price` INT             NOT NULL DEFAULT 0 COMMENT '划线价（分），0 表示不展示',
  `is_recommend`   TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否首页推荐',
  `status`         VARCHAR(10)     NOT NULL DEFAULT 'draft' COMMENT '状态：draft 草稿 / published 上架 / offline 下架',
  `sort`           INT             NOT NULL DEFAULT 0 COMMENT '排序权重，越大越前',
  `sales_count`    INT             NOT NULL DEFAULT 0 COMMENT '销量（已支付订单数）',
  `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`     DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_packages_status_sort` (`status`, `sort`),
  KEY `idx_packages_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='课程套餐（P2）';

DROP TABLE IF EXISTS `package_courses`;
CREATE TABLE `package_courses` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `package_id` BIGINT UNSIGNED NOT NULL,
  `course_id`  BIGINT UNSIGNED NOT NULL,
  `sort`       INT             NOT NULL DEFAULT 0,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_package_courses` (`package_id`, `course_id`),
  KEY `idx_package_courses_course` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='套餐-课程关联（P2）';

-- ============================================================
-- 三、订单、支付与已购
-- ============================================================

DROP TABLE IF EXISTS `orders`;
CREATE TABLE `orders` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no`          VARCHAR(32)     NOT NULL COMMENT '商户订单号（唯一）',
  `user_id`           BIGINT UNSIGNED NOT NULL COMMENT '下单用户',
  `course_id`         BIGINT UNSIGNED DEFAULT NULL COMMENT '课程 ID（套餐订单为 NULL）',
  `package_id`        BIGINT UNSIGNED DEFAULT NULL COMMENT '套餐 ID（套餐订单专用，P2）',
  `course_title`      VARCHAR(150)    NOT NULL DEFAULT '' COMMENT '下单时课程/套餐名快照',
  `amount`            INT             NOT NULL DEFAULT 0 COMMENT '应付金额（分）',
  `original_amount`   INT             NOT NULL DEFAULT 0 COMMENT '原价（分）',
  `discount_amount`   INT             NOT NULL DEFAULT 0 COMMENT '优惠金额（分）',
  `coupon_id`         BIGINT UNSIGNED DEFAULT NULL COMMENT '使用的优惠券（P2）',
  `pay_type`          VARCHAR(20)     NOT NULL DEFAULT '' COMMENT '支付方式：wxpay/alipay',
  `status`            VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT '状态：pending/paying/paid/completed/closed/refunded/deleted',
  `trade_no`          VARCHAR(64)     DEFAULT NULL COMMENT '易支付平台订单号',
  `api_trade_no`      VARCHAR(64)     DEFAULT NULL COMMENT '第三方（微信/支付宝）订单号',
  `client_ip`         VARCHAR(45)     DEFAULT NULL COMMENT '下单 IP',
  `device`            VARCHAR(20)     NOT NULL DEFAULT 'pc' COMMENT '设备类型',
  `expire_at`         DATETIME        DEFAULT NULL COMMENT '支付超时时间',
  `paid_at`           DATETIME        DEFAULT NULL COMMENT '支付时间',
  `closed_at`         DATETIME        DEFAULT NULL COMMENT '关闭时间',
  `refund_amount`     INT             NOT NULL DEFAULT 0 COMMENT '已退款金额（分）',
  `refunded_at`       DATETIME        DEFAULT NULL COMMENT '退款时间',
  `notify_raw`        TEXT            COMMENT '最近一次异步通知原始数据',
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`        DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_orders_no` (`order_no`),
  KEY `idx_orders_user` (`user_id`, `status`),
  KEY `idx_orders_course` (`course_id`),
  KEY `idx_orders_package` (`package_id`),
  KEY `idx_orders_status_expire` (`status`, `expire_at`),
  KEY `idx_orders_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单表';

DROP TABLE IF EXISTS `order_logs`;
CREATE TABLE `order_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`    BIGINT UNSIGNED NOT NULL,
  `order_no`    VARCHAR(32)     NOT NULL DEFAULT '',
  `from_status` VARCHAR(20)     NOT NULL DEFAULT '',
  `to_status`   VARCHAR(20)     NOT NULL DEFAULT '',
  `operator`    VARCHAR(50)     NOT NULL DEFAULT 'system' COMMENT '操作者：system/user/admin:ID',
  `remark`      VARCHAR(255)    NOT NULL DEFAULT '',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_logs_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单状态流转日志';

DROP TABLE IF EXISTS `enrollments`;
CREATE TABLE `enrollments` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `course_id`   BIGINT UNSIGNED NOT NULL,
  `order_id`    BIGINT UNSIGNED DEFAULT NULL COMMENT '开通来源订单',
  `granted_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '开通时间',
  `expire_at`   DATETIME        DEFAULT NULL COMMENT '授权到期时间，NULL 表示永久',
  `status`      TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '1 有效 0 失效',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`  DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_enrollments_user_course` (`user_id`, `course_id`),
  KEY `idx_enrollments_course` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='已购课程授权';

DROP TABLE IF EXISTS `play_progress`;
CREATE TABLE `play_progress` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `course_id`   BIGINT UNSIGNED NOT NULL,
  `chapter_id`  BIGINT UNSIGNED NOT NULL,
  `position`    INT             NOT NULL DEFAULT 0 COMMENT '播放位置（秒）',
  `duration`    INT             NOT NULL DEFAULT 0 COMMENT '视频总时长（秒）',
  `finished`    TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否看完',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_progress_user_chapter` (`user_id`, `chapter_id`),
  KEY `idx_progress_course` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='播放进度';

-- P2：优惠券
DROP TABLE IF EXISTS `coupons`;
CREATE TABLE `coupons` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`           VARCHAR(32)     NOT NULL COMMENT '优惠码',
  `name`           VARCHAR(100)    NOT NULL DEFAULT '' COMMENT '优惠券名称',
  `type`           VARCHAR(20)     NOT NULL DEFAULT 'fixed' COMMENT 'fixed 满减 / percent 折扣',
  `value`          INT             NOT NULL DEFAULT 0 COMMENT 'fixed：减免分；percent：折扣百分比 1-99',
  `min_amount`     INT             NOT NULL DEFAULT 0 COMMENT '最低可用金额（分）',
  `course_id`      BIGINT UNSIGNED DEFAULT NULL COMMENT '限定课程，NULL 为全场',
  `total_quantity` INT             NOT NULL DEFAULT 0 COMMENT '发行总量，0 为不限',
  `used_quantity`  INT             NOT NULL DEFAULT 0 COMMENT '已使用数量',
  `per_user_limit` INT             NOT NULL DEFAULT 1 COMMENT '每人限用次数',
  `start_at`       DATETIME        DEFAULT NULL,
  `end_at`         DATETIME        DEFAULT NULL,
  `is_active`      TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`     DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_coupons_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='优惠券（P2）';

DROP TABLE IF EXISTS `coupon_usages`;
CREATE TABLE `coupon_usages` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `coupon_id`  BIGINT UNSIGNED NOT NULL,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `order_id`   BIGINT UNSIGNED DEFAULT NULL,
  `amount`     INT             NOT NULL DEFAULT 0 COMMENT '抵扣金额（分）',
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_coupon_usages_coupon_order` (`coupon_id`, `order_id`),
  KEY `idx_coupon_usages_user` (`user_id`, `coupon_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='优惠券使用记录（P2）';

-- P2：退款单
DROP TABLE IF EXISTS `refunds`;
CREATE TABLE `refunds` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `refund_no`   VARCHAR(32)     NOT NULL COMMENT '退款单号',
  `order_id`    BIGINT UNSIGNED NOT NULL,
  `order_no`    VARCHAR(32)     NOT NULL,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `amount`      INT             NOT NULL DEFAULT 0 COMMENT '退款金额（分）',
  `reason`      VARCHAR(255)    NOT NULL DEFAULT '',
  `status`      VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending/success/failed',
  `api_result`  TEXT            COMMENT '易支付返回原文',
  `operator_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`  DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_refunds_no` (`refund_no`),
  KEY `idx_refunds_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='退款记录（P2）';

-- ============================================================
-- 四、工单、封禁
-- ============================================================

DROP TABLE IF EXISTS `tickets`;
CREATE TABLE `tickets` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_no`     VARCHAR(32)     NOT NULL COMMENT '工单号',
  `user_id`       BIGINT UNSIGNED NOT NULL,
  `type`          VARCHAR(10)     NOT NULL DEFAULT 'normal' COMMENT '类型：normal 普通 / appeal 申诉',
  `category`      VARCHAR(50)     NOT NULL DEFAULT '' COMMENT '问题分类',
  `title`         VARCHAR(150)    NOT NULL COMMENT '标题',
  `content`       TEXT            NOT NULL COMMENT '正文',
  `status`        VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending/processing/replied/closed',
  `priority`      TINYINT         NOT NULL DEFAULT 1 COMMENT '优先级：0 低 1 普通 2 高 3 紧急',
  `reply_count`   INT             NOT NULL DEFAULT 0 COMMENT '回复数',
  `last_reply_at` DATETIME        DEFAULT NULL,
  `closed_at`     DATETIME        DEFAULT NULL,
  `closed_by`     BIGINT UNSIGNED DEFAULT NULL,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tickets_no` (`ticket_no`),
  KEY `idx_tickets_user` (`user_id`, `status`),
  KEY `idx_tickets_status` (`status`, `priority`),
  KEY `idx_tickets_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='工单表';

DROP TABLE IF EXISTS `ticket_replies`;
CREATE TABLE `ticket_replies` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`   BIGINT UNSIGNED NOT NULL,
  `user_id`     BIGINT UNSIGNED NOT NULL COMMENT '回复人',
  `is_admin`    TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否管理员回复',
  `content`     TEXT            NOT NULL COMMENT '回复内容',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`  DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ticket_replies_ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='工单回复';

DROP TABLE IF EXISTS `ticket_attachments`;
CREATE TABLE `ticket_attachments` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`   BIGINT UNSIGNED NOT NULL,
  `reply_id`    BIGINT UNSIGNED DEFAULT NULL COMMENT '所属回复，NULL 为工单正文附件',
  `user_id`     BIGINT UNSIGNED NOT NULL COMMENT '上传人',
  `file_name`   VARCHAR(255)    NOT NULL COMMENT '原始文件名',
  `file_path`   VARCHAR(255)    NOT NULL COMMENT '存储键（非公开）',
  `file_size`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `mime_type`   VARCHAR(100)    NOT NULL DEFAULT '' COMMENT 'MIME 类型',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at`  DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_attachments_ticket` (`ticket_id`),
  KEY `idx_attachments_reply` (`reply_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='工单附件';

DROP TABLE IF EXISTS `bans`;
CREATE TABLE `bans` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       BIGINT UNSIGNED NOT NULL,
  `type`          VARCHAR(10)     NOT NULL DEFAULT 'temp' COMMENT 'temp 临时 / permanent 永久',
  `reason`        VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '封禁原因',
  `banned_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expired_at`    DATETIME        DEFAULT NULL COMMENT '到期时间，NULL 表示永久',
  `operator_id`   BIGINT UNSIGNED DEFAULT NULL COMMENT '操作人',
  `unbanned_at`   DATETIME        DEFAULT NULL COMMENT '解封时间',
  `unbanned_by`   BIGINT UNSIGNED DEFAULT NULL COMMENT '解封操作人',
  `unban_reason`  VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '解封原因',
  `status`        TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '1 生效中 0 已解除',
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_bans_user` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='封禁记录';

-- ============================================================
-- 五、站点配置、公告、协议
-- ============================================================

DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key`         VARCHAR(100)    NOT NULL COMMENT '配置键',
  `value`       TEXT            COMMENT '配置值',
  `group_name`  VARCHAR(50)     NOT NULL DEFAULT 'general' COMMENT '分组：site/mail/payment/captcha/security/theme',
  `description` VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '说明',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_settings_key` (`key`),
  KEY `idx_settings_group` (`group_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统设置（键值）';

DROP TABLE IF EXISTS `announcements`;
CREATE TABLE `announcements` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`        VARCHAR(150)    NOT NULL COMMENT '公告标题',
  `content`      MEDIUMTEXT      COMMENT '公告正文（Markdown）',
  `is_active`    TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '是否启用',
  `published_at` DATETIME        DEFAULT NULL COMMENT '发布时间',
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`   DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_announcements_active` (`is_active`, `published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='站点公告';

DROP TABLE IF EXISTS `agreements`;
CREATE TABLE `agreements` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`         VARCHAR(20)     NOT NULL COMMENT '类型：terms 用户协议 / privacy 隐私政策 / refund 退款政策',
  `version`      VARCHAR(20)     NOT NULL COMMENT '版本号，如 1.0.0',
  `title`        VARCHAR(150)    NOT NULL,
  `content`      MEDIUMTEXT      COMMENT '正文（Markdown）',
  `effective_at` DATETIME        DEFAULT NULL COMMENT '生效日期',
  `is_current`   TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '是否当前生效版本',
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`   DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_agreements_type_version` (`type`, `version`),
  KEY `idx_agreements_current` (`type`, `is_current`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='协议版本记录';

DROP TABLE IF EXISTS `user_agreements`;
CREATE TABLE `user_agreements` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      BIGINT UNSIGNED NOT NULL,
  `agreement_id` BIGINT UNSIGNED NOT NULL,
  `type`         VARCHAR(20)     NOT NULL,
  `version`      VARCHAR(20)     NOT NULL,
  `ip`           VARCHAR(45)     DEFAULT NULL,
  `ua`           VARCHAR(255)    DEFAULT NULL,
  `agreed_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '同意时间',
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_agreements_user` (`user_id`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户协议同意记录';

-- ============================================================
-- 六、验证码、令牌与日志
-- ============================================================

DROP TABLE IF EXISTS `email_verifications`;
CREATE TABLE `email_verifications` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED DEFAULT NULL,
  `email`      VARCHAR(190)    NOT NULL,
  `code`       VARCHAR(10)     NOT NULL COMMENT '6 位验证码',
  `purpose`    VARCHAR(20)     NOT NULL DEFAULT 'bind' COMMENT 'bind 绑定 / register 注册 / reset 找回 / change 换绑',
  `token`      VARCHAR(64)     DEFAULT NULL COMMENT '一次性令牌',
  `attempts`   TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '校验尝试次数',
  `ip`         VARCHAR(45)     DEFAULT NULL,
  `expires_at` DATETIME        NOT NULL COMMENT '过期时间',
  `used_at`    DATETIME        DEFAULT NULL COMMENT '使用时间',
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email_verifications_email` (`email`, `purpose`),
  KEY `idx_email_verifications_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邮箱验证码';

DROP TABLE IF EXISTS `login_logs`;
CREATE TABLE `login_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED DEFAULT NULL,
  `username`   VARCHAR(50)     NOT NULL DEFAULT '' COMMENT '尝试登录的账号',
  `ip`         VARCHAR(45)     DEFAULT NULL,
  `ua`         VARCHAR(255)    DEFAULT NULL,
  `status`     VARCHAR(10)     NOT NULL DEFAULT 'success' COMMENT 'success/fail',
  `message`    VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '失败原因等',
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_logs_user` (`user_id`),
  KEY `idx_login_logs_ip_time` (`ip`, `created_at`),
  KEY `idx_login_logs_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='登录日志';

DROP TABLE IF EXISTS `operation_logs`;
CREATE TABLE `operation_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED DEFAULT NULL,
  `username`    VARCHAR(50)     NOT NULL DEFAULT '',
  `action`      VARCHAR(100)    NOT NULL DEFAULT '' COMMENT '操作标识，如 course.update',
  `target_type` VARCHAR(50)     NOT NULL DEFAULT '' COMMENT '目标类型',
  `target_id`   BIGINT UNSIGNED DEFAULT NULL,
  `detail`      TEXT            COMMENT '操作详情（JSON）',
  `ip`          VARCHAR(45)     DEFAULT NULL,
  `ua`          VARCHAR(255)    DEFAULT NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_operation_logs_user` (`user_id`),
  KEY `idx_operation_logs_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='操作日志';

DROP TABLE IF EXISTS `payment_logs`;
CREATE TABLE `payment_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`   BIGINT UNSIGNED DEFAULT NULL,
  `order_no`   VARCHAR(32)     NOT NULL DEFAULT '',
  `action`     VARCHAR(20)     NOT NULL DEFAULT '' COMMENT 'create/return/notify/query/refund',
  `status`     VARCHAR(20)     NOT NULL DEFAULT '' COMMENT 'success/fail',
  `raw`        TEXT            COMMENT '原始请求/响应内容',
  `ip`         VARCHAR(45)     DEFAULT NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payment_logs_order` (`order_id`),
  KEY `idx_payment_logs_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='支付日志';

DROP TABLE IF EXISTS `mail_logs`;
CREATE TABLE `mail_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `to_email`   VARCHAR(190)    NOT NULL DEFAULT '',
  `subject`    VARCHAR(255)    NOT NULL DEFAULT '',
  `status`     VARCHAR(10)     NOT NULL DEFAULT 'success' COMMENT 'success/fail',
  `error`      TEXT,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mail_logs_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邮件日志';

DROP TABLE IF EXISTS `captcha_logs`;
CREATE TABLE `captcha_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scene`      VARCHAR(20)     NOT NULL DEFAULT '' COMMENT '场景：register/login/bind/reset',
  `ip`         VARCHAR(45)     DEFAULT NULL,
  `status`     VARCHAR(10)     NOT NULL DEFAULT 'issued' COMMENT 'issued 下发 / passed 通过 / failed 失败',
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_captcha_logs_ip_time` (`ip`, `created_at`),
  KEY `idx_captcha_logs_scene` (`scene`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='验证码日志';

-- P2：队列任务
DROP TABLE IF EXISTS `jobs`;
CREATE TABLE `jobs` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue`        VARCHAR(50)     NOT NULL DEFAULT 'default' COMMENT '队列名：mail/transcode/payment',
  `payload`      TEXT            NOT NULL COMMENT '任务载荷（JSON）',
  `attempts`     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已尝试次数',
  `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 3,
  `available_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '可执行时间',
  `reserved_at`  DATETIME        DEFAULT NULL COMMENT '锁定时间',
  `failed_at`    DATETIME        DEFAULT NULL COMMENT '失败时间',
  `last_error`   TEXT,
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_jobs_queue` (`queue`, `available_at`, `reserved_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='队列任务（P2）';

-- ============================================================
-- 附：迁移记录表（供 bin/migrate.php 记录已应用的增量迁移）
-- ============================================================
DROP TABLE IF EXISTS `migrations`;
CREATE TABLE `migrations` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `filename`   VARCHAR(255)    NOT NULL COMMENT '迁移文件名（含扩展名）',
  `checksum`   CHAR(64)        NOT NULL DEFAULT '' COMMENT '文件内容 SHA-256 校验和',
  `applied_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '应用时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_migrations_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='迁移记录表';

-- ============================================================
-- 附：外键约束（统一在所有表创建完成后添加，避免建表顺序依赖）
-- 说明：业务层使用软删除，外键主要用于兜底引用完整性、防止产生孤儿数据。
-- ============================================================
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_rp_role`       FOREIGN KEY (`role_id`)       REFERENCES `roles` (`id`)       ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE;

ALTER TABLE `user_roles`
  ADD CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

ALTER TABLE `login_devices`
  ADD CONSTRAINT `fk_login_devices_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `courses`
  ADD CONSTRAINT `fk_courses_category` FOREIGN KEY (`category_id`) REFERENCES `course_categories` (`id`) ON DELETE SET NULL;

ALTER TABLE `course_tag_relations`
  ADD CONSTRAINT `fk_ctr_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`)     ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ctr_tag`    FOREIGN KEY (`tag_id`)    REFERENCES `course_tags` (`id`) ON DELETE CASCADE;

ALTER TABLE `chapters`
  ADD CONSTRAINT `fk_chapters_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE;

ALTER TABLE `video_transcodes`
  ADD CONSTRAINT `fk_transcodes_chapter` FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`id`) ON DELETE CASCADE;

ALTER TABLE `package_courses`
  ADD CONSTRAINT `fk_package_courses_package` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_package_courses_course`  FOREIGN KEY (`course_id`)  REFERENCES `courses` (`id`)  ON DELETE CASCADE;

ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`)     ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_orders_course`  FOREIGN KEY (`course_id`)  REFERENCES `courses` (`id`)   ON DELETE SET NULL,
  ADD CONSTRAINT `fk_orders_package` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`)  ON DELETE SET NULL,
  ADD CONSTRAINT `fk_orders_coupon`  FOREIGN KEY (`coupon_id`)  REFERENCES `coupons` (`id`)   ON DELETE SET NULL;

ALTER TABLE `order_logs`
  ADD CONSTRAINT `fk_order_logs_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

ALTER TABLE `enrollments`
  ADD CONSTRAINT `fk_enrollments_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`)  ON DELETE CASCADE,
  ADD CONSTRAINT `fk_enrollments_course`  FOREIGN KEY (`course_id`)  REFERENCES `courses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_enrollments_order`   FOREIGN KEY (`order_id`)   REFERENCES `orders` (`id`) ON DELETE SET NULL;

ALTER TABLE `play_progress`
  ADD CONSTRAINT `fk_progress_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`)    ON DELETE CASCADE,
  ADD CONSTRAINT `fk_progress_course`  FOREIGN KEY (`course_id`)  REFERENCES `courses` (`id`)  ON DELETE CASCADE,
  ADD CONSTRAINT `fk_progress_chapter` FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`id`) ON DELETE CASCADE;

ALTER TABLE `coupons`
  ADD CONSTRAINT `fk_coupons_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE SET NULL;

ALTER TABLE `coupon_usages`
  ADD CONSTRAINT `fk_coupon_usages_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_coupon_usages_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`)   ON DELETE CASCADE,
  ADD CONSTRAINT `fk_coupon_usages_order`  FOREIGN KEY (`order_id`)  REFERENCES `orders` (`id`)  ON DELETE SET NULL;

ALTER TABLE `refunds`
  ADD CONSTRAINT `fk_refunds_order`    FOREIGN KEY (`order_id`)    REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_refunds_user`     FOREIGN KEY (`user_id`)     REFERENCES `users` (`id`)  ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_refunds_operator` FOREIGN KEY (`operator_id`) REFERENCES `users` (`id`)  ON DELETE SET NULL;

ALTER TABLE `tickets`
  ADD CONSTRAINT `fk_tickets_user`      FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_tickets_closed_by` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `ticket_replies`
  ADD CONSTRAINT `fk_ticket_replies_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ticket_replies_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`)   ON DELETE CASCADE;

ALTER TABLE `ticket_attachments`
  ADD CONSTRAINT `fk_attachments_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`)        ON DELETE CASCADE,
  ADD CONSTRAINT `fk_attachments_reply`  FOREIGN KEY (`reply_id`)  REFERENCES `ticket_replies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_attachments_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`)          ON DELETE CASCADE;

ALTER TABLE `bans`
  ADD CONSTRAINT `fk_bans_user`        FOREIGN KEY (`user_id`)     REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_bans_operator`    FOREIGN KEY (`operator_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_bans_unbanned_by` FOREIGN KEY (`unbanned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `user_agreements`
  ADD CONSTRAINT `fk_user_agreements_user`      FOREIGN KEY (`user_id`)      REFERENCES `users` (`id`)      ON DELETE CASCADE,
  ADD CONSTRAINT `fk_user_agreements_agreement` FOREIGN KEY (`agreement_id`) REFERENCES `agreements` (`id`) ON DELETE CASCADE;

ALTER TABLE `email_verifications`
  ADD CONSTRAINT `fk_email_verifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `login_logs`
  ADD CONSTRAINT `fk_login_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `operation_logs`
  ADD CONSTRAINT `fk_operation_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `payment_logs`
  ADD CONSTRAINT `fk_payment_logs_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;
