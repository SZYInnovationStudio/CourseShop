<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Captcha;
use App\Support\Request;

/**
 * 图形验证码图片输出
 *
 * 每次请求都会刷新当前会话对应场景的验证码，因此前端点击图片即视为「换一张」。
 */
final class CaptchaController extends Controller
{
    private const WIDTH = 120;

    private const HEIGHT = 40;

    public function image(): void
    {
        if (!Captcha::enabled()) {
            abort(404, t('图形验证码未启用。'));
        }

        $code  = Captcha::issue(Request::string('scene', 'login'));
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        // 背景
        imagefilledrectangle(
            $image,
            0,
            0,
            self::WIDTH,
            self::HEIGHT,
            imagecolorallocate($image, 246, 247, 245)
        );

        // 干扰线
        for ($i = 0; $i < 5; $i++) {
            imageline(
                $image,
                random_int(0, self::WIDTH),
                random_int(0, self::HEIGHT),
                random_int(0, self::WIDTH),
                random_int(0, self::HEIGHT),
                imagecolorallocate($image, random_int(150, 200), random_int(160, 205), random_int(150, 200))
            );
        }

        // 噪点
        for ($i = 0; $i < 80; $i++) {
            imagesetpixel(
                $image,
                random_int(0, self::WIDTH - 1),
                random_int(0, self::HEIGHT - 1),
                imagecolorallocate($image, random_int(120, 200), random_int(130, 205), random_int(120, 200))
            );
        }

        // 逐字符绘制（字体 5 单字符约 9x15 像素，横向均匀铺开）
        $length = strlen($code);
        $step   = (int) floor((self::WIDTH - 20) / max(1, $length));

        for ($i = 0; $i < $length; $i++) {
            imagestring(
                $image,
                5,
                10 + $i * $step,
                random_int(12, 18),
                $code[$i],
                imagecolorallocate($image, random_int(40, 90), random_int(60, 110), random_int(45, 95))
            );
        }

        if (!headers_sent()) {
            header('Content-Type: image/png');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
        }

        // PHP 8.0 起 GD 图像资源由 GC 自动回收，imagedestroy() 在 8.5 已废弃，故不再调用
        imagepng($image);
    }
}
