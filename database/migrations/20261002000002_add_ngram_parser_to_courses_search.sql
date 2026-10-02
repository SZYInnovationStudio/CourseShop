-- ============================================================
-- 为 courses 全文索引启用 ngram 分词器
--
-- 背景：`courses` 表的 `ft_courses_search (title, summary)` 原先使用默认解析器，
-- 无法正确切分中文，导致 `MATCH ... AGAINST` 查询中文时命中失败、只能回退 LIKE。
-- MySQL 5.7+ 内置 ngram 分词器（按 n 元组切分，适配中日韩），MariaDB 无此插件。
--
-- 这里用条件化 DDL 兼容两者：
--   1) 存在 ngram 插件        —— 仅 MySQL 满足；
--   2) 全文索引确实存在        —— 避免非标准库结构下 DROP INDEX 报错。
-- 两者同时满足才重建索引为 `WITH PARSER ngram`，否则执行无副作用的空操作。
-- ============================================================

SET @ngram_available = (
  SELECT COUNT(*) FROM `information_schema`.`PLUGINS`
  WHERE `PLUGIN_NAME` = 'ngram' AND `PLUGIN_STATUS` = 'ACTIVE'
);

SET @ft_index_exists = (
  SELECT COUNT(*) FROM `information_schema`.`STATISTICS`
  WHERE `TABLE_SCHEMA` = DATABASE()
    AND `TABLE_NAME` = 'courses'
    AND `INDEX_NAME` = 'ft_courses_search'
);

SET @ngram_sql = IF(
  @ngram_available > 0 AND @ft_index_exists > 0,
  'ALTER TABLE `courses` DROP INDEX `ft_courses_search`, ADD FULLTEXT KEY `ft_courses_search` (`title`, `summary`) WITH PARSER ngram',
  'SET @ngram_noop = 0'
);

PREPARE ngram_stmt FROM @ngram_sql;
EXECUTE ngram_stmt;
DEALLOCATE PREPARE ngram_stmt;
