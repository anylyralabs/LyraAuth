<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Relations\HasMany;
use Hyperf\Database\Model\SoftDeletes;

/**
 * 用户。
 *
 * 对外标识使用 uuid（即 OIDC 的 sub），自增 id 只在内部使用。
 *
 * @property int $id
 * @property string $uuid
 * @property string $username
 * @property string $email
 * @property string $display_name
 * @property string $password_hash
 * @property string $status
 * @property string $platform_role
 * @property string $avatar_color
 */
class User extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_PENDING = 'pending';

    public const ROLE_USER = 'user';

    public const ROLE_ADMIN = 'admin';

    protected ?string $table = 'users';

    protected array $hidden = ['password_hash', 'remember_token'];

    protected array $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'password_changed_at' => 'datetime',
        'must_change_password' => 'boolean',
        'mfa_enforced' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class, 'user_id');
    }

    public function totp(): \Hyperf\Database\Model\Relations\HasOne
    {
        return $this->hasOne(UserMfaTotp::class, 'user_id');
    }

    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(MfaRecoveryCode::class, 'user_id');
    }

    public function webauthnCredentials(): HasMany
    {
        return $this->hasMany(WebauthnCredential::class, 'user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMember::class, 'user_id');
    }

    public function trustedDevices(): HasMany
    {
        return $this->hasMany(TrustedDevice::class, 'user_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isAdmin(): bool
    {
        return $this->platform_role === self::ROLE_ADMIN;
    }

    public function isEmailVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    /** 头像占位字符：优先显示名首字 */
    public function initials(): string
    {
        $name = trim((string) ($this->display_name ?: $this->username));
        if ($name === '') {
            return '·';
        }
        $first = mb_substr($name, 0, 1, 'UTF-8');

        return mb_strtoupper($first, 'UTF-8');
    }
}
