<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Relations\BelongsTo;

/**
 * OAuth2 客户端（对外表现为一个应用）。
 *
 * @property int $id
 * @property string $uuid
 * @property string $client_id
 * @property string|null $client_secret_hash
 * @property string $name
 * @property string|null $description
 * @property string $client_type
 * @property array $redirect_uris
 * @property array|null $post_logout_redirect_uris
 * @property array $grant_types
 * @property string $token_endpoint_auth_method
 * @property bool $require_pkce
 * @property bool $is_first_party
 * @property bool $is_active
 */
class OauthClient extends Model
{
    public const TYPE_CONFIDENTIAL = 'confidential';

    public const TYPE_PUBLIC = 'public';

    public const AUTH_BASIC = 'client_secret_basic';

    public const AUTH_POST = 'client_secret_post';

    public const AUTH_NONE = 'none';

    public const GRANT_AUTHORIZATION_CODE = 'authorization_code';

    public const GRANT_REFRESH_TOKEN = 'refresh_token';

    public const GRANT_CLIENT_CREDENTIALS = 'client_credentials';

    public const GRANT_DEVICE_CODE = 'urn:ietf:params:oauth:grant-type:device_code';

    protected ?string $table = 'oauth_clients';

    protected array $casts = [
        'redirect_uris' => 'array',
        'post_logout_redirect_uris' => 'array',
        'grant_types' => 'array',
        'require_pkce' => 'boolean',
        'is_first_party' => 'boolean',
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected array $hidden = ['client_secret_hash'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function isPublic(): bool
    {
        return $this->client_type === self::TYPE_PUBLIC;
    }

    public function allowsGrant(string $grantType): bool
    {
        return in_array($grantType, (array) $this->grant_types, true);
    }

    public function hasRedirectUri(string $uri): bool
    {
        return in_array($uri, (array) $this->redirect_uris, true);
    }

    public function hasPostLogoutRedirectUri(string $uri): bool
    {
        return in_array($uri, (array) ($this->post_logout_redirect_uris ?? []), true);
    }

    /** 展示用首字母 */
    public function initial(): string
    {
        $name = trim($this->name);

        return $name === '' ? '·' : mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
    }
}
