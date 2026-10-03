<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * 二维码生成（QR Code）
 *
 * 纯 PHP 实现的字节模式编码器，纠错等级 M，支持版本 1-10（最多 213 字节，
 * 足够容纳 otpauth 链接），输出内联 SVG，不依赖 GD / 第三方库，可完全离线使用。
 */
final class QrCode
{
    /** 纠错等级 M（格式信息中的 2 位编码） */
    private const EC_LEVEL = 0b00;

    /** 空白边距（模块数），标准要求不少于 4 */
    private const QUIET_ZONE = 4;

    /**
     * 版本 => [每块纠错码字数, 组1块数, 组1数据码字数, 组2块数, 组2数据码字数]
     *
     * @var array<int, array{0: int, 1: int, 2: int, 3: int, 4: int}>
     */
    private const BLOCKS = [
        1  => [10, 1, 16, 0, 0],
        2  => [16, 1, 28, 0, 0],
        3  => [26, 1, 44, 0, 0],
        4  => [18, 2, 32, 0, 0],
        5  => [24, 2, 43, 0, 0],
        6  => [16, 4, 27, 0, 0],
        7  => [18, 4, 31, 0, 0],
        8  => [22, 2, 38, 2, 39],
        9  => [22, 3, 36, 2, 37],
        10 => [26, 4, 43, 1, 44],
    ];

    /**
     * 校正图形中心坐标（版本 1 无）
     *
     * @var array<int, array<int, int>>
     */
    private const ALIGNMENT = [
        1  => [],
        2  => [6, 18],
        3  => [6, 22],
        4  => [6, 26],
        5  => [6, 30],
        6  => [6, 34],
        7  => [6, 22, 38],
        8  => [6, 24, 42],
        9  => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** @var array<int, int> */
    private static array $exp = [];

    /** @var array<int, int> */
    private static array $log = [];

    /**
     * 生成内联 SVG（含静默区），尺寸自适应
     */
    public static function svg(string $text, int $size = 220, string $dark = '#111827', string $light = '#ffffff'): string
    {
        $matrix = self::matrix($text);
        $count  = count($matrix);
        $total  = $count + self::QUIET_ZONE * 2;

        $path = '';

        for ($y = 0; $y < $count; $y++) {
            for ($x = 0; $x < $count; $x++) {
                if ($matrix[$y][$x]) {
                    $path .= 'M' . ($x + self::QUIET_ZONE) . ' ' . ($y + self::QUIET_ZONE) . 'h1v1h-1z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '"'
            . ' viewBox="0 0 ' . $total . ' ' . $total . '" shape-rendering="crispEdges" focusable="false" aria-hidden="true">'
            . '<rect width="' . $total . '" height="' . $total . '" fill="' . $light . '"/>'
            . '<path d="' . $path . '" fill="' . $dark . '"/>'
            . '</svg>';
    }

    /**
     * 生成二维码模块矩阵（true 表示深色）
     *
     * @return array<int, array<int, bool>>
     */
    public static function matrix(string $text): array
    {
        self::initGf();

        $version   = self::pickVersion(strlen($text));

        if ($version === null) {
            throw new RuntimeException('内容过长，无法生成二维码。');
        }

        $bits      = self::dataBits($text, $version);
        $codewords = self::interleavedCodewords($bits, $version);

        $best        = null;
        $bestPenalty = null;

        for ($mask = 0; $mask < 8; $mask++) {
            $matrix  = self::build($version, $codewords, $mask);
            $penalty = self::penalty($matrix);

            if ($bestPenalty === null || $penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best        = $matrix;
            }
        }

        /** @var array<int, array<int, bool>> $best */
        return $best;
    }

    private static function initGf(): void
    {
        if (self::$exp !== []) {
            return;
        }

        $x = 1;

        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;

            $x <<= 1;
            if (($x & 0x100) !== 0) {
                $x ^= 0x11D;
            }
        }

        for ($i = 255; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return self::$exp[(self::$log[$a] + self::$log[$b]) % 255];
    }

    /**
     * 内容能否在本编码器支持的最高版本内容纳
     *
     * 供调用方（如两步验证绑定页）在超长时改用更短的绑定信息，避免直接抛出异常。
     */
    public static function fits(string $text): bool
    {
        return self::pickVersion(strlen($text)) !== null;
    }

    private static function pickVersion(int $bytes): ?int
    {
        foreach (self::BLOCKS as $version => $spec) {
            $dataCodewords = $spec[2] * $spec[1] + $spec[4] * $spec[3];
            $overhead      = $version >= 10 ? 3 : 2;

            if ($bytes + $overhead <= $dataCodewords) {
                return (int) $version;
            }
        }

        return null;
    }

    /**
     * 生成数据位流（模式指示符 + 字符计数 + 数据 + 结束符 + 填充码字）
     *
     * @return array<int, int>
     */
    private static function dataBits(string $text, int $version): array
    {
        $spec          = self::BLOCKS[$version];
        $dataCodewords = $spec[2] * $spec[1] + $spec[4] * $spec[3];
        $capacityBits  = $dataCodewords * 8;

        $bits = [0, 1, 0, 0];

        $countBits = $version >= 10 ? 16 : 8;
        for ($i = $countBits - 1; $i >= 0; $i--) {
            $bits[] = (strlen($text) >> $i) & 1;
        }

        foreach (str_split($text) as $char) {
            $code = ord($char);
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = ($code >> $i) & 1;
            }
        }

        $terminator = min(4, $capacityBits - count($bits));
        for ($i = 0; $i < $terminator; $i++) {
            $bits[] = 0;
        }

        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $pads = [0xEC, 0x11];
        $index = 0;

        while (count($bits) < $capacityBits) {
            $byte = $pads[$index % 2];

            for ($i = 7; $i >= 0; $i--) {
                $bits[] = ($byte >> $i) & 1;
            }

            $index++;
        }

        return $bits;
    }

    /**
     * 分块计算纠错码并交错排列
     *
     * @param  array<int, int> $bits
     * @return array<int, int>
     */
    private static function interleavedCodewords(array $bits, int $version): array
    {
        $spec = self::BLOCKS[$version];
        [$ecPerBlock, $blocks1, $data1, $blocks2, $data2] = $spec;

        $dataCodewords = [];

        for ($i = 0, $total = count($bits); $i < $total; $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | $bits[$i + $j];
            }
            $dataCodewords[] = $byte;
        }

        $dataBlocks = [];
        $ecBlocks   = [];
        $offset     = 0;

        for ($b = 0; $b < $blocks1; $b++) {
            $block        = array_slice($dataCodewords, $offset, $data1);
            $offset      += $data1;
            $dataBlocks[] = $block;
            $ecBlocks[]   = self::ecCodewords($block, $ecPerBlock);
        }

        for ($b = 0; $b < $blocks2; $b++) {
            $block        = array_slice($dataCodewords, $offset, $data2);
            $offset      += $data2;
            $dataBlocks[] = $block;
            $ecBlocks[]   = self::ecCodewords($block, $ecPerBlock);
        }

        $result  = [];
        $maxData = max($data1, $data2);

        for ($i = 0; $i < $maxData; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        return $result;
    }

    /**
     * Reed-Solomon 纠错码字
     *
     * @param  array<int, int> $data
     * @return array<int, int>
     */
    private static function ecCodewords(array $data, int $ecCount): array
    {
        $generator = [1];

        for ($i = 0; $i < $ecCount; $i++) {
            $next = array_fill(0, count($generator) + 1, 0);

            foreach ($generator as $index => $coefficient) {
                $next[$index]     ^= $coefficient;
                $next[$index + 1] ^= self::gfMul($coefficient, self::$exp[$i]);
            }

            $generator = $next;
        }

        $remainder = array_merge($data, array_fill(0, $ecCount, 0));
        $length    = count($data);

        for ($i = 0; $i < $length; $i++) {
            $factor = $remainder[$i];

            if ($factor === 0) {
                continue;
            }

            foreach ($generator as $index => $coefficient) {
                $remainder[$i + $index] ^= self::gfMul($coefficient, $factor);
            }
        }

        return array_slice($remainder, $length, $ecCount);
    }

    /**
     * 构建指定掩码下的完整矩阵
     *
     * @param  array<int, int> $codewords
     * @return array<int, array<int, bool>>
     */
    private static function build(int $version, array $codewords, int $mask): array
    {
        $size = 17 + 4 * $version;

        /** @var array<int, array<int, bool|null>> $matrix */
        $matrix = array_fill(0, $size, array_fill(0, $size, null));

        self::placeFinder($matrix, 0, 0, $size);
        self::placeFinder($matrix, $size - 7, 0, $size);
        self::placeFinder($matrix, 0, $size - 7, $size);

        foreach (self::ALIGNMENT[$version] as $centerRow) {
            foreach (self::ALIGNMENT[$version] as $centerCol) {
                if ($matrix[$centerRow][$centerCol] !== null) {
                    continue;
                }

                for ($dr = -2; $dr <= 2; $dr++) {
                    for ($dc = -2; $dc <= 2; $dc++) {
                        $matrix[$centerRow + $dr][$centerCol + $dc] = max(abs($dr), abs($dc)) !== 1;
                    }
                }
            }
        }

        for ($i = 8; $i < $size - 8; $i++) {
            if ($matrix[6][$i] === null) {
                $matrix[6][$i] = $i % 2 === 0;
            }

            if ($matrix[$i][6] === null) {
                $matrix[$i][6] = $i % 2 === 0;
            }
        }

        self::placeFormat($matrix, $mask, $size);

        if ($version >= 7) {
            self::placeVersion($matrix, $version, $size);
        }

        self::placeData($matrix, $codewords, $mask, $size);

        /** @var array<int, array<int, bool>> $matrix */
        return $matrix;
    }

    /**
     * @param array<int, array<int, bool|null>> $matrix
     */
    private static function placeFinder(array &$matrix, int $row, int $col, int $size): void
    {
        for ($r = -1; $r <= 7; $r++) {
            if ($row + $r < 0 || $row + $r >= $size) {
                continue;
            }

            for ($c = -1; $c <= 7; $c++) {
                if ($col + $c < 0 || $col + $c >= $size) {
                    continue;
                }

                $matrix[$row + $r][$col + $c] = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6))
                    || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6))
                    || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
            }
        }
    }

    /**
     * @param array<int, array<int, bool|null>> $matrix
     */
    private static function placeFormat(array &$matrix, int $mask, int $size): void
    {
        $bits = self::formatBits((self::EC_LEVEL << 3) | $mask);

        for ($i = 0; $i < 15; $i++) {
            $dark = (($bits >> $i) & 1) === 1;

            if ($i < 6) {
                $matrix[$i][8] = $dark;
            } elseif ($i < 8) {
                $matrix[$i + 1][8] = $dark;
            } else {
                $matrix[$size - 15 + $i][8] = $dark;
            }
        }

        for ($i = 0; $i < 15; $i++) {
            $dark = (($bits >> $i) & 1) === 1;

            if ($i < 8) {
                $matrix[8][$size - $i - 1] = $dark;
            } elseif ($i < 9) {
                $matrix[8][7] = $dark;
            } else {
                $matrix[8][15 - $i - 1] = $dark;
            }
        }

        $matrix[$size - 8][8] = true;
    }

    /**
     * @param array<int, array<int, bool|null>> $matrix
     */
    private static function placeVersion(array &$matrix, int $version, int $size): void
    {
        $bits = self::versionBits($version);

        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) === 1;

            $matrix[intdiv($i, 3)][$i % 3 + $size - 11] = $dark;
            $matrix[$i % 3 + $size - 11][intdiv($i, 3)] = $dark;
        }
    }

    /**
     * 按标准顺序填充数据位（跳过功能图形，逐列之字形）
     *
     * @param array<int, array<int, bool|null>> $matrix
     * @param array<int, int>                   $codewords
     */
    private static function placeData(array &$matrix, array $codewords, int $mask, int $size): void
    {
        $byteIndex = 0;
        $bitIndex  = 7;
        $row       = $size - 1;
        $direction = -1;

        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--;
            }

            while (true) {
                for ($c = 0; $c < 2; $c++) {
                    $target = $col - $c;

                    if ($matrix[$row][$target] !== null) {
                        continue;
                    }

                    $dark = false;

                    if ($byteIndex < count($codewords)) {
                        $dark = ((($codewords[$byteIndex] >> $bitIndex) & 1) === 1);
                    }

                    if (self::maskBit($mask, $row, $target)) {
                        $dark = !$dark;
                    }

                    $matrix[$row][$target] = $dark;
                    $bitIndex--;

                    if ($bitIndex === -1) {
                        $byteIndex++;
                        $bitIndex = 7;
                    }
                }

                $row += $direction;

                if ($row < 0 || $row >= $size) {
                    $row      -= $direction;
                    $direction = -$direction;
                    break;
                }
            }
        }
    }

    private static function maskBit(int $mask, int $row, int $col): bool
    {
        return match ($mask) {
            0 => ($row + $col) % 2 === 0,
            1 => $row % 2 === 0,
            2 => $col % 3 === 0,
            3 => ($row + $col) % 3 === 0,
            4 => (intdiv($row, 2) + intdiv($col, 3)) % 2 === 0,
            5 => (($row * $col) % 2) + (($row * $col) % 3) === 0,
            6 => (((($row * $col) % 2) + (($row * $col) % 3)) % 2) === 0,
            7 => (((($row * $col) % 3) + (($row + $col) % 2)) % 2) === 0,
            default => false,
        };
    }

    /**
     * 掩码惩罚分（分数越低越好）
     *
     * @param array<int, array<int, bool>> $matrix
     */
    private static function penalty(array $matrix): float
    {
        $size   = count($matrix);
        $points = 0.0;

        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size; $col++) {
                $same = 0;

                for ($r = -1; $r <= 1; $r++) {
                    if ($row + $r < 0 || $row + $r >= $size) {
                        continue;
                    }

                    for ($c = -1; $c <= 1; $c++) {
                        if ($col + $c < 0 || $col + $c >= $size || ($r === 0 && $c === 0)) {
                            continue;
                        }

                        if ($matrix[$row][$col] === $matrix[$row + $r][$col + $c]) {
                            $same++;
                        }
                    }
                }

                if ($same > 5) {
                    $points += 3 + $same - 5;
                }
            }
        }

        for ($row = 0; $row < $size - 1; $row++) {
            for ($col = 0; $col < $size - 1; $col++) {
                $count = (int) $matrix[$row][$col]
                    + (int) $matrix[$row + 1][$col]
                    + (int) $matrix[$row][$col + 1]
                    + (int) $matrix[$row + 1][$col + 1];

                if ($count === 0 || $count === 4) {
                    $points += 3;
                }
            }
        }

        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size - 6; $col++) {
                if ($matrix[$row][$col] && !$matrix[$row][$col + 1] && $matrix[$row][$col + 2]
                    && $matrix[$row][$col + 3] && $matrix[$row][$col + 4]
                    && !$matrix[$row][$col + 5] && $matrix[$row][$col + 6]) {
                    $points += 40;
                }
            }
        }

        for ($col = 0; $col < $size; $col++) {
            for ($row = 0; $row < $size - 6; $row++) {
                if ($matrix[$row][$col] && !$matrix[$row + 1][$col] && $matrix[$row + 2][$col]
                    && $matrix[$row + 3][$col] && $matrix[$row + 4][$col]
                    && !$matrix[$row + 5][$col] && $matrix[$row + 6][$col]) {
                    $points += 40;
                }
            }
        }

        $dark = 0;

        foreach ($matrix as $row) {
            foreach ($row as $module) {
                if ($module) {
                    $dark++;
                }
            }
        }

        $points += (abs(100 * $dark / $size / $size - 50) / 5) * 10;

        return $points;
    }

    private static function bchDigit(int $data): int
    {
        $digit = 0;

        while ($data !== 0) {
            $digit++;
            $data >>= 1;
        }

        return $digit;
    }

    private static function formatBits(int $data): int
    {
        $value = $data << 10;

        while (self::bchDigit($value) - self::bchDigit(0x537) >= 0) {
            $value ^= 0x537 << (self::bchDigit($value) - self::bchDigit(0x537));
        }

        return (($data << 10) | $value) ^ 0x5412;
    }

    private static function versionBits(int $version): int
    {
        $value = $version << 12;

        while (self::bchDigit($value) - self::bchDigit(0x1F25) >= 0) {
            $value ^= 0x1F25 << (self::bchDigit($value) - self::bchDigit(0x1F25));
        }

        return ($version << 12) | $value;
    }
}