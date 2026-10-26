<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\User;
use App\Model\VerificationToken;
use App\Support\Crypto;

/**
 * 一次性令牌（邮箱验证 / 密码重置 / 邀请）。
 *
 * 数据库只存 SHA-256 摘要，明文令牌仅出现在邮件链接中。
 */
class VerificationTokenService
{
    public const TTL_EMAIL_VERIFY = 86400;   // 24 小时
    public const TTL_PASSWORD_RESET = 3600;  // 1 小时
    public const TTL_INVITE = 604800;        // 7 天

    /** 生成令牌并落库，返回明文供邮件使用 */
    public function issue(User $user, string $purpose, ?string $channel = null, ?int $ttl = null): string
    {
        $ttl ??= match ($purpose) {
            VerificationToken::PURPOSE_PASSWORD_RESET => self::TTL_PASSWORD_RESET,
            VerificationToken::PURPOSE_INVITE => self::TTL_INVITE,
            default => self::TTL_EMAIL_VERIFY,
        };

        // 同一用途的旧令牌立即失效，避免多份有效链接
        VerificationToken::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => date('Y-m-d H:i:s')]);

        $plain = Crypto::randomToken(32);

        VerificationToken::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'token_hash' => Crypto::hash($plain),
            'channel' => $channel,
            'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
        ]);

        return $plain;
    }

    /** 校验并消费令牌，成功返回对应用户 */
    public function consume(string $plain, string $purpose): ?User
    {
        if ($plain === '') {
            return null;
        }

        $record = VerificationToken::query()
            ->where('token_hash', Crypto::hash($plain))
            ->where('purpose', $purpose)
            ->first();

        if ($record === null || ! $record->isUsable()) {
            return null;
        }

        $user = User::query()->find($record->user_id);
        if ($user === null) {
            return null;
        }

        $record->consumed_at = date('Y-m-d H:i:s');
        $record->save();

        return $user;
    }

    /** 只查询，不消费（用于展示“链接是否仍然有效”） */
    public function peek(string $plain, string $purpose): ?User
    {
        if ($plain === '') {
            return null;
        }

        $record = VerificationToken::query()
            ->where('token_hash', Crypto::hash($plain))
            ->where('purpose', $purpose)
            ->first();

        if ($record === null || ! $record->isUsable()) {
            return null;
        }

        return User::query()->find($record->user_id);
    }
}
