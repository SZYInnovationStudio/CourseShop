-- ============================================================
-- 为 users 增加 2FA 防重放字段
--
-- 记录两步验证上次通过校验的「时间步」，同一用户同一时间步只允许通过一次，
-- 防止有效窗口内的动态口令被重复使用（重放）。
-- 幂等：先查询 information_schema，列不存在时才 ALTER。
-- ============================================================

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'two_factor_last_step'
);

SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `two_factor_last_step` BIGINT UNSIGNED DEFAULT NULL COMMENT ''2FA 上次通过校验的时间步（防重放）'' AFTER `two_factor_enabled`',
  'SELECT 1'
);

PREPARE two_factor_step_stmt FROM @ddl;

EXECUTE two_factor_step_stmt;

DEALLOCATE PREPARE two_factor_step_stmt;
