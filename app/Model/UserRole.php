<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property int $role_id
 */
class UserRole extends Model
{
    protected ?string $table = 'user_roles';

    protected array $casts = [
        'granted_at' => 'datetime',
    ];
}
