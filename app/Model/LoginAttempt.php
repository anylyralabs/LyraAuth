<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id
 * @property string|null $identifier
 * @property int|null $user_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string $result
 * @property string|null $created_at
 */
class LoginAttempt extends Model
{
    protected ?string $table = 'login_attempts';

    public bool $timestamps = false;
}
