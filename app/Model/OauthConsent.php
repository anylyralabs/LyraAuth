<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property int $client_id
 * @property string $scopes
 */
class OauthConsent extends Model
{
    protected ?string $table = 'oauth_consents';

    protected array $casts = [
        'last_granted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
