<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id
 * @property string $jti
 * @property int $client_id
 * @property int|null $user_id
 * @property string $scopes
 * @property string|null $session_id
 */
class OauthAccessToken extends Model
{
    protected ?string $table = 'oauth_access_tokens';

    protected array $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at !== null && $this->expires_at->getTimestamp() > time();
    }
}
