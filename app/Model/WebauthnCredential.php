<?php

declare(strict_types=1);

namespace App\Model;

/**
 * WebAuthn 凭据（Passkey）。
 *
 * credential_id 与 public_key 均以 base64 存储，便于与 webauthn-lib 的
 * Uint8Array 互相转换。
 *
 * @property int $id
 * @property int $user_id
 * @property string $credential_id
 * @property string $public_key
 * @property int $sign_count
 * @property string|null $device_name
 */
class WebauthnCredential extends Model
{
    protected ?string $table = 'webauthn_credentials';

    protected array $casts = [
        'sign_count' => 'integer',
        'backup_eligible' => 'boolean',
        'backup_state' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public function publicKeyBytes(): string
    {
        return base64_decode($this->public_key, true) ?: '';
    }
}
