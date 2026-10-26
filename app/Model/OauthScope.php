<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id
 * @property string $identifier
 * @property string $name
 * @property string|null $description
 * @property bool $is_default
 * @property bool $is_system
 */
class OauthScope extends Model
{
    public const OPENID = 'openid';

    public const PROFILE = 'profile';

    public const EMAIL = 'email';

    public const ROLES = 'roles';

    public const ORGANIZATIONS = 'organizations';

    public const OFFLINE_ACCESS = 'offline_access';

    protected ?string $table = 'oauth_scopes';

    protected array $casts = [
        'is_default' => 'boolean',
        'is_system' => 'boolean',
    ];
}
