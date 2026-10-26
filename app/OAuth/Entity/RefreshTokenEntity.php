<?php

declare(strict_types=1);

namespace App\OAuth\Entity;

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;

class RefreshTokenEntity implements RefreshTokenEntityInterface
{
    use EntityTrait;
    use RefreshTokenTrait;

    /** 父令牌 ID，用于检测重放并撤销整条链 */
    private ?string $parentId = null;

    public function setParentId(?string $parentId): void
    {
        $this->parentId = $parentId;
    }

    public function getParentId(): ?string
    {
        return $this->parentId;
    }
}
