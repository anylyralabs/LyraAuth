<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $purpose
 * @property string $token_hash
 * @property string|null $channel
 * @property \Carbon\Carbon|null $expires_at
 * @property \Carbon\Carbon|null $consumed_at
 */
class VerificationToken extends Model
{
    public const PURPOSE_EMAIL_VERIFY = 'email_verify';

    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    public const PURPOSE_INVITE = 'invite';

    protected ?string $table = 'verification_tokens';

    protected array $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at !== null
            && $this->expires_at->getTimestamp() > time();
    }
}
