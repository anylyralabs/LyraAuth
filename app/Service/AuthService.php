<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\LoginAttempt;
use App\Model\User;
use App\Support\Crypto;

/**
 * 账号密码认证与登录风控。
 *
 * 设计要点：
 * - 用户不存在时也执行一次哈希校验，避免通过响应时间枚举账号。
 * - 失败信息对外统一，不区分“账号不存在”与“密码错误”。
 * - 按 IP 与账号双维度限流，并保留登录尝试记录供审计与后台排查。
 */
class AuthService
{
    /** 单账号连续失败上限 */
    public const MAX_ATTEMPTS_PER_ACCOUNT = 5;

    /** 单 IP 连续失败上限 */
    public const MAX_ATTEMPTS_PER_IP = 20;

    /** 失败计数窗口（秒） */
    public const FAILURE_WINDOW = 900;

    /** 达到上限后的锁定时长（秒） */
    public const LOCK_DURATION = 900;

    /** 用于防时序枚举的占位哈希（argon2id，对应随机口令） */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$ZHVtbXlzYWx0ZHVtbXk$3g2kXk1rZ0hFQ2xqS0V4d0FvQm5uY3pFbG1uR2hJd0FvQm5uY3A';

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly AuditService $audit
    ) {
    }

    /** 校验密码，必要时自动升级哈希算法 */
    public static function hashPassword(string $password): string
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 2,
        ]);

        if (! is_string($hash)) {
            throw new \RuntimeException('密码哈希失败。');
        }

        return $hash;
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        if ($hash === '' || ! str_starts_with($hash, '$argon2')) {
            // 兼容历史数据：bcrypt 等算法仍可校验
            return $hash !== '' && password_verify($password, $hash);
        }

        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 2,
        ]);
    }

    /** 按邮箱或用户名查找用户 */
    public function findByIdentifier(string $identifier): ?User
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        return User::query()
            ->where('email', $identifier)
            ->orWhere('username', $identifier)
            ->first();
    }

    public function isLocked(string $identifier, string $ip): bool
    {
        $accountKey = 'login:account:' . mb_strtolower(trim($identifier));

        return $this->limiter->isLocked($accountKey) || $this->limiter->isLocked('login:ip:' . $ip);
    }

    /**
     * 尝试登录。
     *
     * @return array{ok: bool, user?: User, reason?: string}
     */
    public function attempt(string $identifier, string $password, string $ip, ?string $userAgent = null): array
    {
        $identifier = trim($identifier);
        $accountKey = 'login:account:' . mb_strtolower($identifier);
        $ipKey = 'login:ip:' . $ip;

        if ($this->limiter->isLocked($accountKey) || $this->limiter->isLocked($ipKey)) {
            $this->recordAttempt($identifier, null, $ip, $userAgent, 'locked');

            return ['ok' => false, 'reason' => 'locked'];
        }

        $user = $this->findByIdentifier($identifier);

        if ($user === null) {
            // 恒定时间：对不存在的账号同样执行一次校验
            self::verifyPassword($password, self::DUMMY_HASH);
            $this->registerFailure($accountKey, $ipKey);
            $this->recordAttempt($identifier, null, $ip, $userAgent, 'bad_credentials');

            return ['ok' => false, 'reason' => 'bad_credentials'];
        }

        if (! $user->isActive()) {
            self::verifyPassword($password, $user->password_hash ?: self::DUMMY_HASH);
            $this->recordAttempt($identifier, $user->id, $ip, $userAgent, 'blocked');

            return ['ok' => false, 'reason' => 'blocked'];
        }

        if (! self::verifyPassword($password, $user->password_hash)) {
            $this->registerFailure($accountKey, $ipKey);
            $this->recordAttempt($identifier, $user->id, $ip, $userAgent, 'bad_credentials');

            return ['ok' => false, 'reason' => 'bad_credentials'];
        }

        // 成功：清空失败计数并升级哈希
        if ($this->needsRehash($user->password_hash)) {
            $user->password_hash = self::hashPassword($password);
            $user->save();
        }

        $this->limiter->clear($accountKey);
        $this->recordAttempt($identifier, $user->id, $ip, $userAgent, 'success');

        return ['ok' => true, 'user' => $user];
    }

    /** 失败计数与阶梯锁定 */
    private function registerFailure(string $accountKey, string $ipKey): void
    {
        $accountCount = $this->limiter->attempt($accountKey, self::MAX_ATTEMPTS_PER_ACCOUNT, self::FAILURE_WINDOW);
        $this->limiter->attempt($ipKey, self::MAX_ATTEMPTS_PER_IP, self::FAILURE_WINDOW);

        if (! $accountCount) {
            $this->limiter->lock($accountKey, self::LOCK_DURATION);
            $this->audit->log('auth.login.locked', 'denied', null, 'user', null, ['reason' => 'too_many_failures']);
        }
    }

    private function recordAttempt(string $identifier, ?int $userId, string $ip, ?string $userAgent, string $result): void
    {
        try {
            LoginAttempt::create([
                'identifier' => mb_substr($identifier, 0, 255),
                'user_id' => $userId,
                'ip_address' => $ip,
                'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
                'result' => $result,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // 记录失败不影响认证流程
        }

        // 传入用户对象，审计里才能显示到具体账号而不是「系统」
        $actor = $userId !== null ? User::query()->find($userId) : null;

        $this->audit->log(
            'auth.login.' . ($result === 'success' ? 'success' : 'failure'),
            $result === 'success' ? 'success' : 'failure',
            $actor,
            'user',
            $userId !== null ? (string) $userId : null,
            [
                'identifier' => $identifier,
                'result' => $result,
            ]
        );
    }

    /** 更新最近登录信息 */
    public function touchLogin(User $user, string $ip): void
    {
        $user->last_login_at = date('Y-m-d H:i:s');
        $user->last_login_ip = $ip;
        $user->save();
    }
}
