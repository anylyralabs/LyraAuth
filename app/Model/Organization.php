<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Relations\BelongsTo;
use Hyperf\Database\Model\Relations\BelongsToMany;
use Hyperf\Database\Model\Relations\HasMany;
use Hyperf\Database\Model\SoftDeletes;

/**
 * @property int $id
 * @property string $uuid
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property int $owner_id
 * @property string $status
 */
class Organization extends Model
{
    use SoftDeletes;

    protected ?string $table = 'organizations';

    protected array $casts = [
        'deleted_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class, 'organization_id');
    }

    public function memberUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_members', 'organization_id', 'user_id')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function roleOf(int $userId): ?string
    {
        $member = $this->members()->where('user_id', $userId)->first();

        return $member?->role;
    }

    public function acceptsManagementBy(int $userId): bool
    {
        $role = $this->roleOf($userId);

        return in_array($role, [OrganizationMember::ROLE_OWNER, OrganizationMember::ROLE_ADMIN], true);
    }
}
