<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * 课程关键词检索
 *
 * MySQL 5.7+/8.0 默认支持 ngram 中文分词，且 `courses` 表已建立
 * `ft_courses_search (title, summary)` 全文索引，此时优先使用
 * `MATCH ... AGAINST (? IN BOOLEAN MODE)`；MariaDB 等不支持 ngram 的数据库
 * 无法对中文正确分词，则回退到 `LIKE` 模糊匹配，保证检索功能始终可用。
 */
final class Search
{
    /** 全文索引名（与 database/schema.sql 保持一致） */
    private const COURSE_INDEX = 'ft_courses_search';

    /** 是否已完成能力探测（null 表示尚未探测） */
    private static ?bool $fulltextReady = null;

    /**
     * 当前数据库是否可用课程全文索引
     *
     * 需同时满足三个条件：
     *   1) 数据库支持 ngram 分词器（仅 MySQL 提供，MariaDB 无此系统变量）；
     *   2) 全文索引 `ft_courses_search` 存在；
     *   3) 该索引确实使用了 ngram 解析器（`WITH PARSER ngram`）。
     *
     * 条件 3 不可省略：若索引由默认解析器建立，对中文无法正确切词，
     * 此时 MATCH 查询会漏掉结果，必须回退到 LIKE。
     */
    public static function fulltextReady(): bool
    {
        if (self::$fulltextReady !== null) {
            return self::$fulltextReady;
        }

        try {
            // ngram 分词器仅 MySQL 提供，MariaDB 无此系统变量
            if (Database::select("SHOW VARIABLES LIKE 'ngram_token_size'") === []) {
                return self::$fulltextReady = false;
            }

            $indexCount = (int) Database::scalar(
                'SELECT COUNT(*) FROM `information_schema`.`STATISTICS`
                  WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ? AND `INDEX_NAME` = ?',
                ['courses', self::COURSE_INDEX]
            );

            if ($indexCount <= 0) {
                return self::$fulltextReady = false;
            }

            return self::$fulltextReady = self::indexUsesNgramParser();
        } catch (Throwable) {
            return self::$fulltextReady = false;
        }
    }

    /**
     * 判断 `ft_courses_search` 索引是否使用 ngram 解析器
     *
     * SHOW CREATE TABLE 会把每个索引单独成行，形如：
     *   FULLTEXT KEY `ft_courses_search` (`title`,`summary`) /*!50100 WITH PARSER `ngram` *\/
     */
    private static function indexUsesNgramParser(): bool
    {
        $row = Database::first('SHOW CREATE TABLE `courses`');
        $createSql = is_array($row) ? (string) ($row['Create Table'] ?? '') : '';

        if ($createSql === '') {
            return false;
        }

        foreach (preg_split('/\r?\n/', $createSql) ?: [] as $line) {
            if (str_contains($line, self::COURSE_INDEX)) {
                return preg_match('/WITH\s+PARSER\s+`?ngram`?/i', $line) === 1;
            }
        }

        return false;
    }

    /**
     * 前台课程关键词条件：标题 + 简介
     *
     * @param  array<int, mixed> $bindings 追加绑定参数（引用传入）
     */
    public static function courseKeyword(string $keyword, array &$bindings): string
    {
        if (self::fulltextReady()) {
            $bindings[] = self::booleanQuery($keyword);

            return 'MATCH(c.title, c.summary) AGAINST (? IN BOOLEAN MODE)';
        }

        return self::likeCondition($keyword, ['c.title', 'c.summary'], $bindings);
    }

    /**
     * 后台课程关键词条件：标题 + 副标题
     *
     * `courses` 表的全文索引只覆盖 (title, summary)，无法用于副标题，
     * 因此后台检索始终使用 LIKE（后台检索量小，性能可接受）。
     *
     * @param  array<int, mixed> $bindings 追加绑定参数（引用传入）
     */
    public static function adminCourseKeyword(string $keyword, array &$bindings): string
    {
        return self::likeCondition($keyword, ['c.title', 'c.subtitle'], $bindings);
    }

    /**
     * 拼接 LIKE 条件（多列之间为 OR）
     *
     * @param  array<int, string> $columns
     * @param  array<int, mixed>  $bindings
     */
    private static function likeCondition(string $keyword, array $columns, array &$bindings): string
    {
        $pattern = self::likePattern($keyword);
        $parts   = [];

        foreach ($columns as $column) {
            $parts[]    = $column . ' LIKE ?';
            $bindings[] = $pattern;
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * 构造 LIKE 模糊匹配模式
     *
     * 转义用户输入中的 % 与 _，使其按字面量匹配，避免通配符被滥用（如输入 % 命中全表）。
     */
    public static function likePattern(string $keyword): string
    {
        return '%' . self::escapeLike($keyword) . '%';
    }

    /**
     * 将用户输入整理为 BOOLEAN MODE 查询串
     *
     * 以空白拆分关键词，全部作为必需前缀匹配（`+词*`），并剔除布尔运算符，
     * 避免用户输入的特殊字符导致全文检索语法错误。
     */
    private static function booleanQuery(string $keyword): string
    {
        $terms = preg_split('/\s+/u', trim($keyword)) ?: [];
        $parts = [];

        foreach ($terms as $term) {
            $term = preg_replace('/[+\-><()~*"@]+/u', ' ', $term) ?? '';
            $term = trim($term);

            if ($term !== '') {
                $parts[] = '+' . $term . '*';
            }
        }

        return implode(' ', $parts);
    }

    /**
     * 转义 LIKE 通配符，使用户输入中的 % 与 _ 按字面量匹配
     */
    private static function escapeLike(string $keyword): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);
    }
}
