<?php

declare(strict_types=1);

namespace App\OAuth\Entity;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

/**
 * OAuth2 客户端实体。
 *
 * 额外携带 isFirstParty / requirePkce / grantTypes，
 * 供授权页与自定义校验使用。
 */
class ClientEntity implements ClientEntityInterface
{
    use ClientTrait;
    use EntityTrait;

    public array $grantTypes = [];

    public bool $requirePkce = true;

    public bool $isFirstParty = false;

    public array $postLogoutRedirectUris = [];

    public string $logoColor = '#2F4D5E';

    public ?string $description = null;

    public function __construct(
        string $identifier,
        string $name,
        string|array $redirectUri,
        bool $isConfidential
    ) {
        $this->identifier = $identifier;
        $this->name = $name;
        $this->redirectUri = $redirectUri;
        $this->isConfidential = $isConfidential;
    }

    public function supportsGrantType(string $grantType): bool
    {
        return in_array($grantType, $this->grantTypes, true);
    }
}
