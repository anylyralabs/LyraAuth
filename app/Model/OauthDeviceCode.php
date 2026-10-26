<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id
 * @property string $device_code_hash
 * @property string $user_code
 * @property int $client_id
 * @property string $scopes
 * @property int|null $user_id
 * @property string|null $session_id
 * @property string $status
 * @property int $interval
 */
class OauthDeviceCode extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DENIED = 'denied';

    public const STATUS_USED = 'used';

    protected ?string $table = 'oauth_device_codes';

    protected array $casts = [
        'interval' => 'integer',
        'last_polled_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
