<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 站点配色方案
 *
 * 预设若干套浅色 / 深色成对的配色，后台「主题外观」中切换。
 * 每套配色覆盖品牌色、强调色、背景、前景（卡片）、边框与文字层级，
 * 布局层通过 Theme::css() 以 CSS 变量覆盖 app.css 中的默认值。
 */
final class Theme
{
    public const DEFAULT_PALETTE = 'morandi';

    /**
     * 全部预设配色
     *
     * 颜色键与 CSS 变量一一对应：下划线转连字符并加上 --color- 前缀
     * （如 primary_strong -> --color-primary-strong）。
     *
     * @return array<string, array{label: string, desc: string, light: array<string, string>, dark: array<string, string>}>
     */
    public static function palettes(): array
    {
        return [
            'morandi' => [
                'label' => '莫兰迪绿',
                'desc'  => '低饱和自然绿，沉稳耐看',
                'light' => [
                    'primary'          => '#4F6F52',
                    'primary_strong'   => '#3F5A42',
                    'primary_soft'     => '#EAF0EA',
                    'primary_contrast' => '#FFFFFF',
                    'accent'           => '#8A6434',
                    'accent_soft'      => '#F4ECE2',
                    'bg'               => '#F6F7F5',
                    'bg_alt'           => '#EFF2EE',
                    'surface'          => '#FFFFFF',
                    'surface_2'        => '#FBFCFB',
                    'border'           => '#E3E7E1',
                    'border_strong'    => '#CFD6CD',
                    'text'             => '#22282A',
                    'text_muted'       => '#6B7472',
                    'text_faint'       => '#6E7774',
                    'text_placeholder' => '#98A19E',
                ],
                'dark' => [
                    'primary'          => '#7BA37E',
                    'primary_strong'   => '#93B996',
                    'primary_soft'     => '#26302A',
                    'primary_contrast' => '#10140F',
                    'accent'           => '#D9AE72',
                    'accent_soft'      => '#2F271C',
                    'bg'               => '#141715',
                    'bg_alt'           => '#191D1B',
                    'surface'          => '#1C201E',
                    'surface_2'        => '#232826',
                    'border'           => '#2E3532',
                    'border_strong'    => '#3C4441',
                    'text'             => '#E7EAE7',
                    'text_muted'       => '#A2ABA6',
                    'text_faint'       => '#7F8883',
                    'text_placeholder' => '#77807B',
                ],
            ],
            'mist' => [
                'label' => '雾霾蓝',
                'desc'  => '冷静克制的灰蓝，专注耐读',
                'light' => [
                    'primary'          => '#4A6D8C',
                    'primary_strong'   => '#3A5670',
                    'primary_soft'     => '#E8EFF5',
                    'primary_contrast' => '#FFFFFF',
                    'accent'           => '#9A5F3D',
                    'accent_soft'      => '#F4EAE3',
                    'bg'               => '#F5F7F9',
                    'bg_alt'           => '#EDF1F4',
                    'surface'          => '#FFFFFF',
                    'surface_2'        => '#FAFBFC',
                    'border'           => '#DFE5EA',
                    'border_strong'    => '#C8D2DA',
                    'text'             => '#202A32',
                    'text_muted'       => '#63707A',
                    'text_faint'       => '#697680',
                    'text_placeholder' => '#96A3AD',
                ],
                'dark' => [
                    'primary'          => '#7FA6C4',
                    'primary_strong'   => '#9BBDD7',
                    'primary_soft'     => '#24313B',
                    'primary_contrast' => '#0C1319',
                    'accent'           => '#D9A47E',
                    'accent_soft'      => '#31251E',
                    'bg'               => '#11161B',
                    'bg_alt'           => '#161C22',
                    'surface'          => '#1B2128',
                    'surface_2'        => '#222A32',
                    'border'           => '#2B343D',
                    'border_strong'    => '#3A4651',
                    'text'             => '#E5E9ED',
                    'text_muted'       => '#A1AAB4',
                    'text_faint'       => '#858F9A',
                    'text_placeholder' => '#76808A',
                ],
            ],
            'terracotta' => [
                'label' => '赤陶暖阳',
                'desc'  => '温暖陶土色，亲切有质感',
                'light' => [
                    'primary'          => '#A75D45',
                    'primary_strong'   => '#884835',
                    'primary_soft'     => '#F7E9E3',
                    'primary_contrast' => '#FFFFFF',
                    'accent'           => '#4F6F52',
                    'accent_soft'      => '#E9F0E9',
                    'bg'               => '#F9F6F3',
                    'bg_alt'           => '#F3ECE7',
                    'surface'          => '#FFFFFF',
                    'surface_2'        => '#FCFAF8',
                    'border'           => '#E8DED6',
                    'border_strong'    => '#D6C6BA',
                    'text'             => '#2B2420',
                    'text_muted'       => '#76675E',
                    'text_faint'       => '#7C6D64',
                    'text_placeholder' => '#AC9D94',
                ],
                'dark' => [
                    'primary'          => '#D98D73',
                    'primary_strong'   => '#E7A78F',
                    'primary_soft'     => '#36241E',
                    'primary_contrast' => '#150D09',
                    'accent'           => '#93B996',
                    'accent_soft'      => '#25302A',
                    'bg'               => '#191412',
                    'bg_alt'           => '#1F1815',
                    'surface'          => '#241C18',
                    'surface_2'        => '#2C231E',
                    'border'           => '#3A2E27',
                    'border_strong'    => '#4B3C34',
                    'text'             => '#EDE5DF',
                    'text_muted'       => '#B3A49C',
                    'text_faint'       => '#9A8B81',
                    'text_placeholder' => '#86776E',
                ],
            ],
            'lavender' => [
                'label' => '黛紫疏影',
                'desc'  => '柔和灰紫，安静有格调',
                'light' => [
                    'primary'          => '#7A5C96',
                    'primary_strong'   => '#624A7B',
                    'primary_soft'     => '#F0EAF6',
                    'primary_contrast' => '#FFFFFF',
                    'accent'           => '#976126',
                    'accent_soft'      => '#F5EDE1',
                    'bg'               => '#F8F6FA',
                    'bg_alt'           => '#F1EDF5',
                    'surface'          => '#FFFFFF',
                    'surface_2'        => '#FBF9FC',
                    'border'           => '#E5DEEB',
                    'border_strong'    => '#D2C6DC',
                    'text'             => '#282231',
                    'text_muted'       => '#6E6578',
                    'text_faint'       => '#746B7E',
                    'text_placeholder' => '#A397AE',
                ],
                'dark' => [
                    'primary'          => '#B79AD4',
                    'primary_strong'   => '#CBB2E1',
                    'primary_soft'     => '#2B2335',
                    'primary_contrast' => '#110E18',
                    'accent'           => '#D7AE73',
                    'accent_soft'      => '#33291D',
                    'bg'               => '#16131B',
                    'bg_alt'           => '#1B1721',
                    'surface'          => '#211C27',
                    'surface_2'        => '#29222F',
                    'border'           => '#332B3B',
                    'border_strong'    => '#453A4F',
                    'text'             => '#EAE5EF',
                    'text_muted'       => '#ABA0B5',
                    'text_faint'       => '#94899E',
                    'text_placeholder' => '#81758B',
                ],
            ],
            'jade' => [
                'label' => '墨玉青',
                'desc'  => '沉静青碧，清透而稳重',
                'light' => [
                    'primary'          => '#2F6F6A',
                    'primary_strong'   => '#245854',
                    'primary_soft'     => '#E4F0EE',
                    'primary_contrast' => '#FFFFFF',
                    'accent'           => '#A45C24',
                    'accent_soft'      => '#F6EDE3',
                    'bg'               => '#F4F8F7',
                    'bg_alt'           => '#ECF2F1',
                    'surface'          => '#FFFFFF',
                    'surface_2'        => '#FAFCFC',
                    'border'           => '#DDE6E4',
                    'border_strong'    => '#C6D5D2',
                    'text'             => '#1F2A28',
                    'text_muted'       => '#5F706C',
                    'text_faint'       => '#657A75',
                    'text_placeholder' => '#95A8A3',
                ],
                'dark' => [
                    'primary'          => '#6FB3AC',
                    'primary_strong'   => '#8DCBC4',
                    'primary_soft'     => '#1D302E',
                    'primary_contrast' => '#07100F',
                    'accent'           => '#DFA96B',
                    'accent_soft'      => '#33291D',
                    'bg'               => '#111716',
                    'bg_alt'           => '#161D1B',
                    'surface'          => '#1A2220',
                    'surface_2'        => '#212A28',
                    'border'           => '#293532',
                    'border_strong'    => '#384744',
                    'text'             => '#E2EAE8',
                    'text_muted'       => '#9EACA8',
                    'text_faint'       => '#7B8A86',
                    'text_placeholder' => '#73827E',
                ],
            ],
            'graphite' => [
                'label' => '石墨鎏金',
                'desc'  => '中性石墨灰配暖金，简洁利落',
                'light' => [
                    'primary'          => '#49515B',
                    'primary_strong'   => '#373E46',
                    'primary_soft'     => '#ECEEF1',
                    'primary_contrast' => '#FFFFFF',
                    'accent'           => '#8C6A2F',
                    'accent_soft'      => '#F3EEE2',
                    'bg'               => '#F6F6F7',
                    'bg_alt'           => '#EEEEF0',
                    'surface'          => '#FFFFFF',
                    'surface_2'        => '#FBFBFC',
                    'border'           => '#E2E3E6',
                    'border_strong'    => '#CDCFD4',
                    'text'             => '#202328',
                    'text_muted'       => '#646A72',
                    'text_faint'       => '#6A7178',
                    'text_placeholder' => '#99A0A7',
                ],
                'dark' => [
                    'primary'          => '#9AA6B4',
                    'primary_strong'   => '#B5C0CB',
                    'primary_soft'     => '#252A31',
                    'primary_contrast' => '#0D1014',
                    'accent'           => '#D4B36A',
                    'accent_soft'      => '#302A1D',
                    'bg'               => '#14161A',
                    'bg_alt'           => '#191C21',
                    'surface'          => '#1E2126',
                    'surface_2'        => '#262A30',
                    'border'           => '#2E333A',
                    'border_strong'    => '#3E444C',
                    'text'             => '#E6E8EB',
                    'text_muted'       => '#A3A9B1',
                    'text_faint'       => '#8B9199',
                    'text_placeholder' => '#777D85',
                ],
            ],
        ];
    }

    /**
     * 后台下拉 / 单选项文案
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::palettes() as $key => $palette) {
            $options[$key] = $palette['label'] . ' · ' . $palette['desc'];
        }

        return $options;
    }

    /**
     * 当前选中的配色键（非法或缺失时回退默认）
     */
    public static function current(): string
    {
        $key = Setting::string('theme_palette', self::DEFAULT_PALETTE);

        return isset(self::palettes()[$key]) ? $key : self::DEFAULT_PALETTE;
    }

    /**
     * 当前配色定义
     *
     * @return array{label: string, desc: string, light: array<string, string>, dark: array<string, string>}
     */
    public static function palette(): array
    {
        return self::palettes()[self::current()];
    }

    /**
     * 当前配色的浅色主色（用于 theme-color / PWA 清单）
     */
    public static function primary(): string
    {
        return self::palette()['light']['primary'];
    }

    /**
     * 当前配色的浅色前景色（用于 PWA 启动背景）
     */
    public static function surface(): string
    {
        return self::palette()['light']['surface'];
    }

    /**
     * 生成覆盖 app.css 默认变量的样式片段
     */
    public static function css(): string
    {
        $palette = self::palette();
        $player  = $palette['dark']['primary'];

        return ':root{' . self::variables($palette['light'], $player) . self::shadows(false) . '}'
            . 'html[data-theme="dark"]{' . self::variables($palette['dark'], $player) . self::shadows(true) . '}';
    }

    /**
     * 将配色数组转为 CSS 变量声明
     *
     * @param array<string, string> $colors
     */
    private static function variables(array $colors, string $playerAccent): string
    {
        $css = '';

        foreach ($colors as $name => $value) {
            $css .= '--color-' . str_replace('_', '-', $name) . ':' . $value . ';';
        }

        // 播放器常驻深色底，进度条始终使用亮一档的品牌色
        return $css . '--player-accent:' . $playerAccent . ';';
    }

    /**
     * 阴影统一使用中性色，避免与彩色背景产生偏色
     */
    private static function shadows(bool $dark): string
    {
        if ($dark) {
            return '--shadow-xs:0 1px 2px rgba(0,0,0,.4);'
                . '--shadow-sm:0 2px 8px rgba(0,0,0,.45);'
                . '--shadow-md:0 10px 28px rgba(0,0,0,.5);'
                . '--shadow-lg:0 20px 52px rgba(0,0,0,.6);';
        }

        return '--shadow-xs:0 1px 2px rgba(20,24,28,.05);'
            . '--shadow-sm:0 2px 6px rgba(20,24,28,.06);'
            . '--shadow-md:0 8px 24px rgba(20,24,28,.08);'
            . '--shadow-lg:0 18px 48px rgba(20,24,28,.12);';
    }
}
