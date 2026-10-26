<?php

declare(strict_types=1);

namespace App\OAuth\Entity;

use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\Traits\AuthCodeTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

class AuthCodeEntity implements AuthCodeEntityInterface
{
    use AuthCodeTrait;
    use EntityTrait;
    use TokenEntityTrait;

    /** 授权时的 IdP 会话 ID，用于令牌签发时写入 sid */
    private ?string $sessionId = null;

    /** 认证方法快照，跨请求保留 amr */
    private array $amr = [];

    private int $authTime = 0;

    public function setSessionId(?string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    /** @param list<string> $amr */
    public function setAmr(array $amr): void
    {
        $this->amr = $amr;
    }

    /** @return list<string> */
    public function getAmr(): array
    {
        return $this->amr;
    }

    public function setAuthTime(int $authTime): void
    {
        $this->authTime = $authTime;
    }

    public function getAuthTime(): int
    {
        return $this->authTime;
    }
}
