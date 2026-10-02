-- ============================================================
-- 迁移记录表（示例迁移）
--
-- 本文件既是可被真实执行的首个迁移，也作为后续迁移文件的范例：
--   - 文件名格式：YYYYMMDDHHMMSS_描述.sql（按文件名升序执行）
--   - 必须幂等，可重复执行不报错
--   - 可包含多条 SQL 语句（迁移执行器按分号切分逐条执行，并跳过注释中的分号）
-- ============================================================

CREATE TABLE IF NOT EXISTS `migrations` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `filename`   VARCHAR(255)    NOT NULL COMMENT '迁移文件名（含扩展名）',
  `checksum`   CHAR(64)        NOT NULL DEFAULT '' COMMENT '文件内容 SHA-256 校验和',
  `applied_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '应用时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_migrations_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='迁移记录表';
