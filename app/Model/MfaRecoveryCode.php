<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $code_hash
 * @property string|null $used_at
 */
class MfaRecoveryCode extends Model
{
    protected ?string $table = 'mfa_recovery_codes';

    protected array $casts = [
        'used_at' => 'datetime',
    ];

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}
