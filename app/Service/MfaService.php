<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\MfaRecoveryCode;
use App\Model\TrustedDevice;
use App\Model\User;
use App\Model\UserMfaTotp;
use App\Model\UserSession;
use App\Support\Crypto;
use OTPHP\TOTP;

/**
 * 多因素认证。
 *
 * TOTP 密钥以 APP_KEY 加密存库；恢复码只存 SHA-256 摘要；
 * 信任设备用高熵令牌 + 摘要校验，默认 30 天有效。
 */
class MfaService
{
    /** 信任设备有效期（秒） */
    public const TRUSTED_DEVICE_TTL = 2592000; // 30 天

    /** 恢复码数量 */
    public const RECOVERY_CODE_COUNT = 10;

    /** 允许的时间步偏差（±1 个 30 秒窗口） */
    private const TOTP_WINDOW = 1;

    public function __construct(private readonly Crypto $crypto)
    {
    }

    public function hasTotp(User $user): bool
    {
        $record = UserMfaTotp::query()->where('user_id', $user->id)->first();

        return $record !== null && $record->isConfirmed();
    }

    public function hasPasskey(User $user): bool
    {
        return $user->webauthnCredentials()->exists();
    }

    public function hasRecoveryCodes(User $user): bool
    {
        return MfaRecoveryCode::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->exists();
    }

    /** 是否需要二次验证：启用了 TOTP 或被管理员强制要求 */
    public function requiresChallenge(User $user): bool
    {
        return $this->hasTotp($user) || ($user->mfa_enforced && ! $this->hasPasskey($user));
    }

    public function enabled(User $user): bool
    {
        return $this->hasTotp($user) || $this->hasPasskey($user);
    }

    // ---------------------------------------------------------------- TOTP

    /**
     * 创建未确认的 TOTP 密钥，返回密钥与 otpauth URI。
     *
     * @return array{secret: string, uri: string}
     */
    public function createTotpSecret(User $user, string $issuer): array
    {
        // RFC 4226 建议 HMAC-SHA1 使用 160 位密钥：32 个 base32 字符
        $secret = self::randomBase32(32);

        $totp = TOTP::create($secret, 30, 'sha1', 6);
        $totp->setLabel($user->email);
        $totp->setIssuer($issuer);

        UserMfaTotp::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'secret_ciphertext' => $this->crypto->encrypt($secret),
                'confirmed_at' => null,
                'last_used_counter' => 0,
            ]
        );

        return [
            'secret' => $secret,
            'uri' => $totp->getProvisioningUri(),
        ];
    }

    /** 生成 base32 密钥（去掉易混淆字符 0/1/O/I 的影响，仅用标准字母表） */
    private static function randomBase32(int $length): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; ++$i) {
            $secret .= $alphabet[random_int(0, 31)];
        }

        return $secret;
    }

    /** 校验 TOTP 验证码，成功则标记已确认 */
    public function confirmTotp(User $user, string $code): bool
    {
        $record = UserMfaTotp::query()->where('user_id', $user->id)->first();
        if ($record === null) {
            return false;
        }

        $counter = $this->verifyTotpCounter($record, $code);
        if ($counter === null) {
            return false;
        }

        $record->confirmed_at = $record->confirmed_at ?? date('Y-m-d H:i:s');
        $record->last_used_counter = $counter;
        $record->save();

        return true;
    }

    /** 登录时的 TOTP 校验，带防重放 */
    public function verifyTotp(User $user, string $code): bool
    {
        $record = UserMfaTotp::query()->where('user_id', $user->id)->first();
        if ($record === null || ! $record->isConfirmed()) {
            return false;
        }

        $counter = $this->verifyTotpCounter($record, $code);
        if ($counter === null) {
            return false;
        }

        $record->last_used_counter = max($record->last_used_counter, $counter);
        $record->save();

        return true;
    }

    /**
     * 验证并返回对应时间步，失败返回 null。
     *
     * 通过遍历窗口内的候选时间步，避免 OTPHP 的 verify 无法给出步数的限制。
     */
    private function verifyTotpCounter(UserMfaTotp $record, string $code): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return null;
        }

        try {
            $secret = $this->crypto->decrypt($record->secret_ciphertext);
        } catch (\Throwable) {
            return null;
        }

        $totp = TOTP::create($secret, 30, 'sha1', 6);
        $now = time();
        $currentStep = intdiv($now, 30);

        for ($offset = -self::TOTP_WINDOW; $offset <= self::TOTP_WINDOW; ++$offset) {
            $step = $currentStep + $offset;
            $candidate = $totp->at($step * 30);
            if (hash_equals($candidate, $code) && $step > $record->last_used_counter) {
                return $step;
            }
        }

        return null;
    }

    public function disableTotp(User $user): void
    {
        UserMfaTotp::query()->where('user_id', $user->id)->delete();
        MfaRecoveryCode::query()->where('user_id', $user->id)->delete();
    }

    // ------------------------------------------------------------ 恢复码

    /**
     * 生成新的恢复码，返回明文（仅展示一次）。
     *
     * 使用固定字符集而非 base64 截断：后者可能因填充剔除而产生不足 8 位的码。
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        MfaRecoveryCode::query()->where('user_id', $user->id)->delete();

        // 去掉易混淆的 0/O、1/I/L
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; ++$i) {
            $raw = '';
            for ($j = 0; $j < 8; ++$j) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            // 形如 4F7K-2M9Q，便于抄录
            $code = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
            $codes[] = $code;

            MfaRecoveryCode::create([
                'user_id' => $user->id,
                'code_hash' => Crypto::hash($this->normalizeRecoveryCode($code)),
            ]);
        }

        return $codes;
    }

    /** 消费一个恢复码 */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $normalized = $this->normalizeRecoveryCode($code);
        if ($normalized === '') {
            return false;
        }

        $record = MfaRecoveryCode::query()
            ->where('user_id', $user->id)
            ->where('code_hash', Crypto::hash($normalized))
            ->whereNull('used_at')
            ->first();

        if ($record === null) {
            return false;
        }

        $record->used_at = date('Y-m-d H:i:s');
        $record->save();

        return true;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return MfaRecoveryCode::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->count();
    }

    private function normalizeRecoveryCode(string $code): string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

        return strlen($clean) === 8 ? $clean : '';
    }

    // --------------------------------------------------------- 信任设备

    /** 生成信任设备令牌并返回明文，同时写入 Cookie 由控制器负责 */
    public function trustDevice(User $user, ?string $label): string
    {
        $plain = Crypto::randomToken(32);

        TrustedDevice::create([
            'user_id' => $user->id,
            'token_hash' => Crypto::hash($plain),
            'device_label' => $label !== null ? mb_substr($label, 0, 100) : null,
            'last_used_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', time() + self::TRUSTED_DEVICE_TTL),
        ]);

        return $plain;
    }

    /** 当前浏览器是否已是被信任设备 */
    public function isTrustedDevice(User $user, ?string $plainToken = null): bool
    {
        $plainToken ??= $this->readTrustedDeviceCookie();
        if ($plainToken === null) {
            return false;
        }

        $record = TrustedDevice::query()
            ->where('user_id', $user->id)
            ->where('token_hash', Crypto::hash($plainToken))
            ->first();

        if ($record === null || $record->isExpired()) {
            return false;
        }

        $record->last_used_at = date('Y-m-d H:i:s');
        $record->save();

        return true;
    }

    public function forgetTrustedDevice(User $user, string $plainToken): void
    {
        TrustedDevice::query()
            ->where('user_id', $user->id)
            ->where('token_hash', Crypto::hash($plainToken))
            ->delete();
    }

    public function revokeAllTrustedDevices(User $user): int
    {
        return TrustedDevice::query()->where('user_id', $user->id)->delete();
    }

    private function readTrustedDeviceCookie(): ?string
    {
        $cookies = \Hyperf\Context\Context::get(\Psr\Http\Message\ServerRequestInterface::class)?->getCookieParams() ?? [];

        return isset($cookies['lyraauth_trusted']) ? (string) $cookies['lyraauth_trusted'] : null;
    }

    // -------------------------------------------------------------- 统计

    /** 供账号安全页展示 */
    public function summary(User $user): array
    {
        return [
            'totp' => $this->hasTotp($user),
            'passkey_count' => $user->webauthnCredentials()->count(),
            'recovery_codes' => $this->remainingRecoveryCodes($user),
            'active_sessions' => UserSession::query()
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', date('Y-m-d H:i:s'))
                ->count(),
        ];
    }
}
