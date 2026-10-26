<?php

declare(strict_types=1);

namespace App\Service;

/**
 * 单个令牌/授权请求期间的上下文。
 *
 * Hyperf 容器默认不是单例，同一接口每次 get() 都可能得到新实例；
 * 因此授权与令牌交换之间的 sid / amr / auth_time / nonce 需要显式传递。
 *
 * 注册为单例（config/autoload/dependencies.php），并在每个请求入口重置，
 * 保证不会跨请求串数据。
 */
class OAuthContext
{
    /** @var array{sid?: ?string, amr?: list<string>, auth_time?: int}|null */
    private ?array $authContext = null;

    private ?string $nonce = null;

    /** @param array{sid?: ?string, amr?: list<string>, auth_time?: int} $context */
    public function setAuthContext(array $context): void
    {
        $this->authContext = $context;
    }

    /** @return array{sid?: ?string, amr?: list<string>, auth_time?: int}|null */
    public function authContext(): ?array
    {
        return $this->authContext;
    }

    public function setNonce(?string $nonce): void
    {
        $this->nonce = $nonce;
    }

    public function nonce(): ?string
    {
        return $this->nonce;
    }

    public function clear(): void
    {
        $this->authContext = null;
        $this->nonce = null;
    }
}
