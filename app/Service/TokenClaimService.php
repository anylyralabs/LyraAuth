<?php

declare(strict_types=1);

namespace App\Service;

use App\OAuth\Entity\AccessTokenEntity;
use Hyperf\Contract\ConfigInterface;

/**
 * 访问令牌声明注入。
 *
 * 独立于 OAuthServerService 存在，避免
 * AccessTokenRepository → OAuthServerService → AccessTokenRepository 的循环依赖。
 */
class TokenClaimService
{
    public function __construct(
        private readonly OAuthKeyService $keys,
        private readonly ConfigInterface $config
    ) {
    }

    /**
     * 把签发者、认证上下文写入令牌。
     *
     * @param list<string> $amr 认证方法：pwd / totp / passkey / recovery / trusted_device
     */
    public function decorate(AccessTokenEntity $token, ?string $sessionId, array $amr, int $authTime): void
    {
        $token->setPrivateKey($this->keys->privateKey());
        $token->setKeyId($this->keys->keyId());
        $token->setSessionId($sessionId);
        $token->setCustomClaims([
            'iss' => $this->issuer(),
            'auth_time' => $authTime,
            'amr' => $amr,
            'sid' => $sessionId,
        ]);
    }

    public function issuer(): string
    {
        return rtrim((string) $this->config->get('app_url', 'http://localhost:9501'), '/');
    }
}
