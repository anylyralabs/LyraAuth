<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\AuditLog;
use App\Model\User;
use Hyperf\Context\Context;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Container\ContainerInterface;

/**
 * 审计日志。
 *
 * 记录安全相关动作（登录、令牌签发、配置变更等）。meta 做脱敏处理，
 * 禁止把令牌、密码、密钥写进数据库。
 */
class AuditService
{
    /** 允许写入 meta 的键白名单之外的键一律丢弃 */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'access_token',
        'refresh_token',
        'id_token',
        'client_secret',
        'secret',
        'code',
        'code_verifier',
        'totp',
        'recovery_code',
    ];

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function log(
        string $action,
        string $result = 'success',
        ?User $actor = null,
        ?string $targetType = null,
        ?string $targetId = null,
        array $meta = []
    ): void {
        try {
            $request = $this->request();

            AuditLog::create([
                'actor_id' => $actor?->id,
                'actor_label' => $actor !== null ? sprintf('%s <%s>', $actor->username, $actor->email) : null,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId !== null ? (string) $targetId : null,
                'result' => $result,
                'ip_address' => $this->clientIp($request),
                'user_agent' => $this->userAgent($request),
                'meta' => $meta === [] ? null : json_encode($this->sanitize($meta), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // 审计写入失败不应影响主流程，交由日志系统兜底
        }
    }

    private function sanitize(array $meta): array
    {
        $clean = [];
        foreach ($meta as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                $clean[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = $this->sanitize($value);
                continue;
            }
            if (is_string($value) && strlen($value) > 500) {
                $clean[$key] = mb_substr($value, 0, 500) . '…';
                continue;
            }
            $clean[$key] = $value;
        }

        return $clean;
    }

    private function request(): ?RequestInterface
    {
        try {
            return $this->container->get(RequestInterface::class);
        } catch (\Throwable) {
            return null;
        }
    }

    /** 取真实客户端 IP，仅信任 Swoole 提供的 remote_addr */
    public function clientIp(?RequestInterface $request = null): ?string
    {
        $request ??= $this->request();
        if ($request === null) {
            return null;
        }

        $server = $request->getServerParams();

        return isset($server['remote_addr']) ? (string) $server['remote_addr'] : null;
    }

    public function userAgent(?RequestInterface $request = null): ?string
    {
        $request ??= $this->request();
        if ($request === null) {
            return null;
        }

        $ua = $request->getHeaderLine('User-Agent');

        return $ua === '' ? null : mb_substr($ua, 0, 255);
    }
}
