<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id
 * @property int|null $actor_id
 * @property string|null $actor_label
 * @property string $action
 * @property string|null $target_type
 * @property string|null $target_id
 * @property string $result
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array|null $meta
 * @property string|null $created_at
 */
class AuditLog extends Model
{
    protected ?string $table = 'audit_logs';

    public bool $timestamps = false;

    protected array $casts = [
        'meta' => 'array',
    ];
}
