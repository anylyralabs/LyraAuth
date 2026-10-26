<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id
 * @property string $code_hash
 * @property int $client_id
 * @property int $user_id
 * @property string|null $scopes
 * @property string $redirect_uri
 * @property bool $used
 */
class OauthAuthCode extends Model
{
    protected ?string $table = 'oauth_auth_codes';

    protected array $casts = [
        'used' => 'boolean',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
