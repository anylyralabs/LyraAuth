<?php

declare(strict_types=1);

namespace App\Support;

use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * 对称加密工具。
 *
 * 用于加密 TOTP 密钥、SMTP 密码等敏感字段。密钥由 APP_KEY 经 BLAKE2b 派生，
 * 使用 sodium 的 secretbox（XSalsa20-Poly1305），密文带版本前缀便于将来轮换。
 */
class Crypto
{
    private const PREFIX = 'v1.';

    private string $key;

    public function __construct(ContainerInterface $container)
    {
        $config = $container->get(ConfigInterface::class);
        $appKey = (string) $config->get('app_key', '');

        if (strlen($appKey) < 16) {
            throw new RuntimeException('APP_KEY 未配置或过短，无法进行加密操作。');
        }

        // 32 字节对称密钥，salt 固定以便跨进程一致
        $this->key = sodium_crypto_generichash('lyraauth-encryption', $appKey, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    public function decrypt(string $payload): string
    {
        if (! str_starts_with($payload, self::PREFIX)) {
            throw new RuntimeException('密文格式无法识别。');
        }

        $raw = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('密文已损坏。');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);

        if ($plaintext === false) {
            throw new RuntimeException('解密失败，APP_KEY 可能已更换。');
        }

        return $plaintext;
    }

    /** 生成不可逆摘要，用于存储令牌、恢复码等只需要比对的值 */
    public static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    /** 生成 URL 安全的随机令牌 */
    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
