<?php

declare(strict_types=1);

namespace App\Service;

use Hyperf\Redis\Redis;
use Psr\Container\ContainerInterface;

/**
 * 基于 Redis 的固定窗口限流。
 *
 * 用于登录、找回密码、令牌端点等敏感入口。计数键包含业务前缀，
 * 与缓存、会话数据隔离。
 */
class RateLimiter
{
    private const PREFIX = 'rl:';

    private Redis $redis;

    public function __construct(ContainerInterface $container)
    {
        $this->redis = $container->get(Redis::class);
    }

    /**
     * 记一次尝试，返回是否仍然允许。
     *
     * @param string $key 业务键，如 login:ip:1.2.3.4
     * @param int $maxAttempts 窗口内允许次数
     * @param int $decaySeconds 窗口长度（秒）
     */
    public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $redisKey = self::PREFIX . $key;
        $count = $this->increment($redisKey, $decaySeconds);

        return $count <= $maxAttempts;
    }

    /** 只查询当前计数，不增加 */
    public function attempts(string $key): int
    {
        $value = $this->redis->get(self::PREFIX . $key);

        return is_numeric($value) ? (int) $value : 0;
    }

    /** 剩余可用次数 */
    public function remaining(string $key, int $maxAttempts): int
    {
        return max(0, $maxAttempts - $this->attempts($key));
    }

    /** 超过限制后还需等待的秒数 */
    public function availableIn(string $key, int $decaySeconds): int
    {
        $ttl = $this->redis->ttl(self::PREFIX . $key);

        return is_int($ttl) && $ttl > 0 ? $ttl : $decaySeconds;
    }

    /** 成功后清空计数 */
    public function clear(string $key): void
    {
        $this->redis->del(self::PREFIX . $key);
    }

    /** 触发锁定，用于反复失败后的惩罚性封禁 */
    public function lock(string $key, int $seconds): void
    {
        $this->redis->setex(self::PREFIX . $key, $seconds, '9999');
    }

    public function isLocked(string $key): bool
    {
        return $this->attempts($key) >= 9999;
    }

    private function increment(string $redisKey, int $decaySeconds): int
    {
        $count = $this->redis->incr($redisKey);
        if ($count === 1) {
            $this->redis->expire($redisKey, $decaySeconds);
        }

        return (int) $count;
    }
}
