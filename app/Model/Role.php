<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property bool $is_system
 */
class Role extends Model
{
    public const ADMIN = 'admin';

    public const USER = 'user';

    protected ?string $table = 'roles';

    protected array $casts = [
        'is_system' => 'boolean',
    ];
}
