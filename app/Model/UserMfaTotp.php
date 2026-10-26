<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $secret_ciphertext
 * @property string|null $confirmed_at
 * @property int $last_used_counter
 */
class UserMfaTotp extends Model
{
    protected ?string $table = 'user_mfa_totp';

    protected array $casts = [
        'confirmed_at' => 'datetime',
        'last_used_counter' => 'integer',
    ];

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
