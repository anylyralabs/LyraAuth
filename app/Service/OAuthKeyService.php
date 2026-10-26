<?php

declare(strict_types=1);

namespace App\Service;

use League\OAuth2\Server\CryptKey;
use RuntimeException;

/**
 * OAuth2 / OIDC 签名密钥管理。
 *
 * RSA 密钥以文件形式存于 storage/keys/oauth/，权限 0600，不进数据库也不进 Git。
 * JWT 头写入 kid，JWKS 可同时暴露多把公钥，便于平滑轮换。
 */
class OAuthKeyService
{
    private const ACTIVE_FILE = 'active.key';

    private string $directory;

    public function __construct()
    {
        $this->directory = BASE_PATH . '/storage/keys/oauth';
    }

    /** 确保密钥存在，返回 kid */
    public function ensureKeys(): string
    {
        $kid = $this->activeKeyId();
        if ($kid !== null) {
            return $kid;
        }

        return $this->rotate();
    }

    /**
     * 生成新密钥并设为当前使用。
     *
     * 旧公钥保留在 JWKS 中（grace 期内仍可验签），旧私钥标记为 retired。
     */
    public function rotate(int $bits = 2048): string
    {
        $this->ensureDirectory();

        $kid = date('YmdHis') . '-' . bin2hex(random_bytes(4));

        $resource = openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false) {
            throw new RuntimeException('RSA 密钥生成失败：' . openssl_error_string());
        }

        $privatePem = '';
        if (! openssl_pkey_export($resource, $privatePem)) {
            throw new RuntimeException('RSA 私钥导出失败：' . openssl_error_string());
        }

        $details = openssl_pkey_get_details($resource);
        if ($details === false || ! isset($details['key'])) {
            throw new RuntimeException('无法读取 RSA 公钥。');
        }

        $this->write($this->privateKeyPath($kid), $privatePem);
        $this->write($this->publicKeyPath($kid), $details['key']);
        $this->write(BASE_PATH . '/storage/keys/oauth/current', $kid);

        return $kid;
    }

    public function activeKeyId(): ?string
    {
        $file = BASE_PATH . '/storage/keys/oauth/current';
        if (! is_file($file)) {
            return null;
        }

        $kid = trim((string) file_get_contents($file));
        if ($kid === '' || ! is_file($this->privateKeyPath($kid))) {
            return null;
        }

        return $kid;
    }

    /** 供 league 使用的私钥对象 */
    public function privateKey(): CryptKey
    {
        $kid = $this->ensureKeys();

        return new CryptKey($this->privateKeyPath($kid), null, false);
    }

    public function publicKey(): CryptKey
    {
        $kid = $this->ensureKeys();

        return new CryptKey($this->publicKeyPath($kid), null, false);
    }

    /** 当前密钥 ID，用于 JWT 头 */
    public function keyId(): string
    {
        return $this->ensureKeys();
    }

    /**
     * JWKS 文档。
     *
     * 只暴露公钥，且仅包含仍存在公钥文件的密钥（最多保留最近 3 把）。
     *
     * @return array{keys: list<array<string, string>>}
     */
    public function jwks(): array
    {
        $keys = [];
        // 首次访问时按需生成密钥，避免依赖先跑某个命令
        $this->ensureKeys();
        $kids = $this->availableKeyIds();

        foreach (array_slice($kids, 0, 3) as $kid) {
            $pem = @file_get_contents($this->publicKeyPath($kid));
            if ($pem === false) {
                continue;
            }

            $resource = openssl_pkey_get_public($pem);
            if ($resource === false) {
                continue;
            }
            $details = openssl_pkey_get_details($resource);
            if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
                continue;
            }

            $keys[] = [
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => $kid,
                // RFC 7517：Base64urlUInt，去掉前导零
                'n' => $this->base64url(ltrim($details['rsa']['n'], "\x00")),
                'e' => $this->base64url(ltrim($details['rsa']['e'], "\x00")),
            ];
        }

        return ['keys' => $keys];
    }

    /** @return list<string> 新的在前 */
    public function availableKeyIds(): array
    {
        $files = glob(BASE_PATH . '/storage/keys/oauth/*.private.pem') ?: [];
        $kids = array_map(
            static fn (string $path) => basename($path, '.private.pem'),
            $files
        );

        rsort($kids);

        return array_values($kids);
    }

    private function privateKeyPath(string $kid): string
    {
        return $this->directory . '/' . $this->safeKid($kid) . '.private.pem';
    }

    private function publicKeyPath(string $kid): string
    {
        return $this->directory . '/' . $this->safeKid($kid) . '.public.pem';
    }

    /** kid 只允许安全字符，避免路径穿越 */
    private function safeKid(string $kid): string
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/', $kid) !== 1) {
            throw new RuntimeException('非法的密钥标识。');
        }

        return $kid;
    }

    private function ensureDirectory(): void
    {
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0700, true);
        }
    }

    private function write(string $path, string $contents): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('无法写入密钥文件：' . $path);
        }
        chmod($path, 0600);
        fwrite($handle, $contents);
        fclose($handle);
    }

    private function base64url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
