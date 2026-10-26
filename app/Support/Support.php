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
}
