<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id
 * @property string $token_hash
 * @property string $jti
 * @property int $client_id
 * @property int $user_id
 * @property int|null $parent_id
 */
class OauthRefreshToken extends Model
{
    protected ?string $table = 'oauth_refresh_tokens';

    protected array $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
