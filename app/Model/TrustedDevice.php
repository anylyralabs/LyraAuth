<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string|null $device_label
 * @property \Carbon\Carbon|null $expires_at
 */
class TrustedDevice extends Model
{
    protected ?string $table = 'trusted_devices';

    protected array $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->getTimestamp() <= time();
    }
}
