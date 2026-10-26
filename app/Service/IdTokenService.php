<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\OauthClient;
use App\Model\User;
use Hyperf\Contract\ConfigInterface;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use RuntimeException;

/**
 * id_token 签发。
 *
 * 与访问令牌共用同一把 RSA 密钥与 kid；声明遵循 OIDC Core 1.0 §2：
 * iss / sub / aud / exp / iat / auth_time / nonce / amr / azp / at_hash / sid。
 *
 * sub 使用 users.uuid，保证 id_token 与 userinfo 中的用户标识一致。
 */
class IdTokenService
{
    public function __construct(
        private readonly OAuthKeyService $keys,
        private readonly ConfigInterface $config
    ) {
    }

    /**
     * 签发 id_token。
     *
     * @param string $accessToken 已签发的访问令牌字符串，用于计算 at_hash
     * @param array{sid?: ?string, amr?: list<string>, auth_time?: int} $context
     */
    public function issue(
        User $user,
        OauthClient $client,
        string $accessToken,
        int $expiresAt,
        array $context,
        ?string $nonce
    ): string {
        $privateKey = $this->keys->privateKey();
        $keyContents = $privateKey->getKeyContents();
        if ($keyContents === '') {
            throw new RuntimeException('缺少 RSA 私钥，无法签发 id_token。');
        }

        $configuration = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($keyContents, $privateKey->getPassPhrase() ?? ''),
            InMemory::plainText('empty', 'empty')
        );

        $now = new \DateTimeImmutable();
        $expiry = (new \DateTimeImmutable())->setTimestamp($expiresAt);

        $builder = $configuration->builder()
            ->issuedBy($this->issuer())
            ->permittedFor($client->client_id)
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->issuedAt($now)
            ->expiresAt($expiry)
            ->relatedTo($user->uuid)
            ->withClaim('azp', $client->client_id)
            ->withClaim('auth_time', (int) ($context['auth_time'] ?? $now->getTimestamp()))
            ->withClaim('amr', $context['amr'] ?? ['pwd'])
            ->withClaim('at_hash', $this->atHash($accessToken));

        if ($nonce !== null && $nonce !== '') {
            $builder = $builder->withClaim('nonce', $nonce);
        }

        if (($context['sid'] ?? null) !== null) {
            $builder = $builder->withClaim('sid', $context['sid']);
        }

        return $builder
            ->withHeader('kid', $this->keys->keyId())
            ->getToken($configuration->signer(), $configuration->signingKey())
            ->toString();
    }

    /**
     * at_hash：对访问令牌的 ASCII 字节取 SHA-256，取左半，base64url 编码（OIDC Core §3.1.3.6）。
     *
     * 注意：哈希输入是令牌字符串本身（签名的 JWT），不是解码后的载荷，也不是原始二进制。
     */
    public function atHash(string $accessToken): string
    {
        $hash = hash('sha256', $accessToken, true);

        return rtrim(strtr(base64_encode(substr($hash, 0, 16)), '+/', '-_'), '=');
    }

    public function issuer(): string
    {
        return rtrim((string) $this->config->get('app_url', 'http://localhost:9501'), '/');
    }
}
