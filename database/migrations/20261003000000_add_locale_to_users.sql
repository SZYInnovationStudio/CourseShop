-- ============================================================
-- 为 users 增加语言偏好字段
--
-- 记录登录用户在前台选择的界面语言，用于跨设备同步。
-- 幂等：先查询 information_schema，列不存在时才 ALTER。
-- ============================================================

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'locale'
);

SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `locale` VARCHAR(10) NOT NULL DEFAULT ''zh-CN'' COMMENT ''语言偏好：zh-CN/zh-TW/en/es/ja'' AFTER `dark_mode`',
  'SELECT 1'
);

PREPARE add_locale_stmt FROM @ddl;

EXECUTE add_locale_stmt;

DEALLOCATE PREPARE add_locale_stmt;
