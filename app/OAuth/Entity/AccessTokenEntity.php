<?php

declare(strict_types=1);

namespace App\OAuth\Entity;

use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\CryptKeyInterface;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;
use RuntimeException;

/**
 * 访问令牌实体。
 *
 * 不使用框架自带的 AccessTokenTrait：它把 JWT 构造写死在私有方法里，
 * 无法注入 iss / auth_time / amr / sid 等 OIDC 声明。这里自行构造，
 * 保证访问令牌与 id_token 由同一把 RSA 密钥、同一 kid 签发。
 */
class AccessTokenEntity implements AccessTokenEntityInterface
{
    use EntityTrait;
    use TokenEntityTrait;

    private CryptKeyInterface $privateKey;

    /** 附加到 JWT 的自定义声明 */
    private array $customClaims = [];

    /** 签发时的登录会话 ID，用于单点登出 */
    private ?string $sessionId = null;

    /** 密钥 ID，写入 JWT 头 kid */
    private ?string $keyId = null;

    /** 已签发的 JWT 缓存，保证同一令牌的字符串稳定 */
    private ?string $cachedJwt = null;

    public function setCustomClaims(array $claims): void
    {
        $this->customClaims = $claims;
    }

    /** @return array<string, mixed> */
    public function getCustomClaims(): array
    {
        return $this->customClaims;
    }

    public function setSessionId(?string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function setKeyId(?string $keyId): void
    {
        $this->keyId = $keyId;
    }

    public function setPrivateKey(CryptKeyInterface $privateKey): void
    {
        $this->privateKey = $privateKey;
    }

    /** JWT 形式，供资源服务器本地验签 */
    public function toString(): string
    {
        // 缓存结果：调用方（如 id_token 的 at_hash）需要与响应体中的字符串完全一致，
        // 而每次重建会生成新的 iat，导致内容不同
        if ($this->cachedJwt !== null) {
            return $this->cachedJwt;
        }

        $keyContents = $this->privateKey->getKeyContents();
        if ($keyContents === '') {
            throw new RuntimeException('RSA 私钥为空，无法签发令牌。');
        }

        $configuration = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($keyContents, $this->privateKey->getPassPhrase() ?? ''),
            InMemory::plainText('empty', 'empty')
        );

        $builder = $configuration->builder()
            ->permittedFor($this->getClient()->getIdentifier())
            ->identifiedBy($this->getIdentifier())
            ->issuedBy((string) ($this->customClaims['iss'] ?? ''))
            ->issuedAt(new DateTimeImmutable())
            ->canOnlyBeUsedAfter(new DateTimeImmutable())
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo($this->getSubjectIdentifier())
            ->withClaim('scopes', array_map(
                static fn ($scope) => $scope->getIdentifier(),
                $this->getScopes()
            ));

        if ($this->keyId !== null && $this->keyId !== '') {
            $builder = $builder->withHeader('kid', $this->keyId);
        }

        foreach ($this->customClaims as $name => $value) {
            // iss 属注册声明，已通过 issuedBy() 设置，不能走 withClaim
            if ($name === 'iss' || $value === null) {
                continue;
            }
            $builder = $builder->withClaim($name, $value);
        }

        return $this->cachedJwt = $builder
            ->getToken($configuration->signer(), $configuration->signingKey())
            ->toString();
    }

    /**
     * 客户端凭证模式没有用户，sub 回退为客户端 ID。
     */
    private function getSubjectIdentifier(): string
    {
        return $this->getUserIdentifier() ?? $this->getClient()->getIdentifier();
    }
}
