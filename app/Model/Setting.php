<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property bool $is_encrypted
 * @property string $group
 */
class Setting extends Model
{
    protected ?string $table = 'settings';

    protected array $casts = [
        'is_encrypted' => 'boolean',
    ];
}
