<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 通用小工具。
 */
final class Support
{
    /** 生成 RFC 4122 v4 UUID */
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * 生成可读的强随机密码，避免易混淆字符。
     */
    public static function randomPassword(int $length = 20): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $password = '';
        for ($i = 0; $i < $length; ++$i) {
            $password .= $alphabet[random_int(0, $max)];
        }

        // 保证同时含字母与数字，满足自身校验规则
        return $password . random_int(10, 99);
    }

    /** 生成客户端 ID */
    public static function clientId(): string
    {
        return 'lyra_' . bin2hex(random_bytes(12));
    }

    /** 生成客户端密钥 */
    public static function clientSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * 依据背景色亮度选择可读的前景色。
     *
     * 用于用户头像与客户端图标：允许用户选择任意颜色，但文字必须保持可读。
     */
    public static function contrastText(string $background, string $dark = '#14171A', string $light = '#FFFFFF'): string
    {
        $hex = ltrim(trim($background), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return $light;
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        // 感知亮度（ITU-R BT.601）：浅色背景改用深色字
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

        return $luminance > 0.62 ? $dark : $light;
    }
}
