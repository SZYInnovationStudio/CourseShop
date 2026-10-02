<?php

declare(strict_types=1);

namespace App\Support;

/**
 * ID 列表归一化工具
 *
 * 用于后台批量操作：把表单提交的 ids[] 处理成安全可用的正整数数组，
 * 过滤非标量、负数、零与重复项，并可限制最大数量，避免恶意超大请求。
 */
final class Ids
{
    /**
     * 归一化 ID 列表
     *
     * @param array<int|string, mixed> $values 原始输入（可能含数组/对象等脏数据）
     * @param int                      $limit  最大保留数量，0 表示不限制
     *
     * @return array<int, int> 去重后的正整数 ID 列表
     */
    public static function normalize(array $values, int $limit = 0): array
    {
        $ids = [];

        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $id = (int) $value;

            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        $ids = array_keys($ids);

        if ($limit > 0 && count($ids) > $limit) {
            $ids = array_slice($ids, 0, $limit);
        }

        return $ids;
    }
}
