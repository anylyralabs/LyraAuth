<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\User;
use App\Model\UserSession;
use App\Support\Crypto;
use Hyperf\Contract\SessionInterface;

/**
 * 会话与登录态。
 *
 * Redis 存会话数据（框架 SessionMiddleware 负责），MySQL 存会话元数据用于
 * “登录设备”列表与远程下线。数据库记录是权威来源：每次请求校验其是否被撤销，
 * 因此“退出所有设备”能立即生效。
 */
class SessionGuard
{
    public const SESSION_USER_ID = 'auth_user_id';

    public const SESSION_SESSION_ID = 'auth_session_id';

    public const SESSION_LOGIN_AT = 'auth_login_at';

    public const SESSION_LAST_SEEN = 'auth_last_seen';

    public const SESSION_AUTH_TIME = 'auth_time';

    public const SESSION_AMR = 'auth_amr';

    public const SESSION_MFA_PENDING = 'auth_mfa_pending';

    public const SESSION_MFA_USER_ID = 'auth_mfa_user_id';

    public const SESSION_MFA_REMEMBER = 'auth_mfa_remember';

    public const SESSION_TRUSTED_DEVICE = 'auth_trusted_device';

    public const SESSION_IDLE_TIMEOUT = 7200;      // 闲置 2 小时

    public const SESSION_ABSOLUTE_TIMEOUT = 43200; // 绝对 12 小时

    public function __construct(private readonly SessionInterface $session)
    {
    }

    public function isStarted(): bool
    {
        return $this->session->isStarted();
    }

    /** 当前请求是否已登录（并校验会话有效性） */
    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function id(): ?int
    {
        $value = $this->session->get(self::SESSION_USER_ID);

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
    }

    public function user(): ?User
    {
        $userId = $this->id();
        if ($userId === null) {
            return null;
        }

        $sessionId = $this->session->get(self::SESSION_SESSION_ID);
        if (! is_string($sessionId) || $sessionId === '') {
            $this->logout();

            return null;
        }

        $record = UserSession::query()
            ->where('session_id', $sessionId)
            ->where('user_id', $userId)
            ->first();

        // 会话记录缺失或已撤销：立即失效
        if ($record === null || ! $record->isActive()) {
            $this->logout();

            return null;
        }

        // 闲置超时
        $lastSeen = (int) $this->session->get(self::SESSION_LAST_SEEN, 0);
        $now = time();
        if ($lastSeen > 0 && $now - $lastSeen > self::SESSION_IDLE_TIMEOUT) {
            $this->logout();

            return null;
        }

        // 绝对超时
        $loginAt = (int) $this->session->get(self::SESSION_LOGIN_AT, 0);
        if ($loginAt > 0 && $now - $loginAt > self::SESSION_ABSOLUTE_TIMEOUT) {
            $this->logout();

            return null;
        }

        $user = User::query()->find($userId);
        if ($user === null || ! $user->isActive()) {
            $this->logout();

            return null;
        }

        $this->session->set(self::SESSION_LAST_SEEN, $now);
        // 每 5 分钟才写一次库，避免每个请求都产生 UPDATE
        if ($record->last_activity_at === null || $now - $record->last_activity_at->getTimestamp() > 300) {
            $record->last_activity_at = date('Y-m-d H:i:s', $now);
            $record->save();
        }

        return $user;
    }

    /** 登录成功：重置会话 ID 防固定攻击，并写入元数据 */
    public function login(User $user, array $amr = ['pwd'], ?string $ip = null, ?string $userAgent = null): void
    {
        // 登录前的目标地址（例如 OAuth 授权页）会被 invalidate() 清空，
        // 先取出、登录后写回，否则用户登录后会被送到默认页而不是原目标。
        $intended = $this->session->get('url.intended');
        $csrf = $this->session->get('_csrf_token');

        $this->session->invalidate();
        $this->session->regenerateToken();

        if (is_string($intended) && $intended !== '') {
            $this->session->set('url.intended', $intended);
        }
        if (is_string($csrf) && $csrf !== '') {
            $this->session->set('_csrf_token', $csrf);
        }

        $sessionId = bin2hex(random_bytes(32));
        $now = time();

        $this->session->set(self::SESSION_USER_ID, $user->id);
        $this->session->set(self::SESSION_SESSION_ID, $sessionId);
        $this->session->set(self::SESSION_LOGIN_AT, $now);
        $this->session->set(self::SESSION_LAST_SEEN, $now);
        $this->session->set(self::SESSION_AUTH_TIME, $now);
        $this->session->set(self::SESSION_AMR, $amr);
        $this->session->forget(self::SESSION_MFA_PENDING);
        $this->session->forget(self::SESSION_MFA_USER_ID);

        UserSession::create([
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'ip_address' => $ip,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            'device_label' => UserSession::describe($userAgent),
            'last_activity_at' => date('Y-m-d H:i:s', $now),
            'expires_at' => date('Y-m-d H:i:s', $now + self::SESSION_ABSOLUTE_TIMEOUT),
        ]);
    }

    public function logout(): void
    {
        $sessionId = $this->session->get(self::SESSION_SESSION_ID);
        if (is_string($sessionId) && $sessionId !== '') {
            UserSession::query()
                ->where('session_id', $sessionId)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => date('Y-m-d H:i:s')]);
        }

        $this->session->clear();
        $this->session->invalidate();
    }

    /** 撤销指定会话（用于“登录设备”页） */
    public function revokeSession(int $userId, string $sessionId): bool
    {
        $affected = UserSession::query()
            ->where('session_id', $sessionId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => date('Y-m-d H:i:s')]);

        return $affected > 0;
    }

    /** 退出全部设备，可保留当前会话 */
    public function revokeAll(int $userId, ?string $exceptSessionId = null): int
    {
        $query = UserSession::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at');

        if ($exceptSessionId !== null) {
            $query->where('session_id', '!=', $exceptSessionId);
        }

        return $query->update(['revoked_at' => date('Y-m-d H:i:s')]);
    }

    public function currentSessionId(): ?string
    {
        $value = $this->session->get(self::SESSION_SESSION_ID);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function authTime(): int
    {
        return (int) $this->session->get(self::SESSION_AUTH_TIME, 0);
    }

    /** 认证方法列表：pwd / totp / passkey / recovery */
    public function amr(): array
    {
        $amr = $this->session->get(self::SESSION_AMR, []);

        return is_array($amr) ? array_values(array_filter($amr, 'is_string')) : [];
    }

    public function pushAmr(string $method): void
    {
        $amr = $this->amr();
        if (! in_array($method, $amr, true)) {
            $amr[] = $method;
            $this->session->set(self::SESSION_AMR, $amr);
        }
    }

    /** 待二次验证：密码已通过但尚未完成 MFA */
    public function beginMfaChallenge(int $userId, bool $rememberDevice): void
    {
        $this->session->set(self::SESSION_MFA_PENDING, true);
        $this->session->set(self::SESSION_MFA_USER_ID, $userId);
        $this->session->set(self::SESSION_MFA_REMEMBER, $rememberDevice);
    }

    public function pendingMfaUserId(): ?int
    {
        if ($this->session->get(self::SESSION_MFA_PENDING) !== true) {
            return null;
        }
        $value = $this->session->get(self::SESSION_MFA_USER_ID);

        return is_numeric($value) ? (int) $value : null;
    }

    public function shouldRememberDevice(): bool
    {
        return $this->session->get(self::SESSION_MFA_REMEMBER) === true;
    }

    public function clearMfaChallenge(): void
    {
        $this->session->forget(self::SESSION_MFA_PENDING);
        $this->session->forget(self::SESSION_MFA_USER_ID);
        $this->session->forget(self::SESSION_MFA_REMEMBER);
    }

    /** 一次性提示消息 */
    public function flash(string $type, string $message): void
    {
        $this->session->set('flash', ['type' => $type, 'message' => $message]);
    }

    /** 表单回填与错误信息 */
    public function withErrors(array $errors, array $old = []): void
    {
        $this->session->set('_errors', $errors);
        $this->session->set('_old_input', $old);
    }

    public function clearErrors(): void
    {
        $this->session->forget('_errors');
        $this->session->forget('_old_input');
    }

    /** 记住登录前的目标地址，用于登录后跳回 */
    public function rememberIntended(string $url): void
    {
        $this->session->set('url.intended', $url);
    }

    public function pullIntended(): ?string
    {
        $value = $this->session->get('url.intended');
        $this->session->forget('url.intended');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** 只读取登录前目标地址，不消费（用于渲染期解析来源客户端） */
    public function peekIntended(): ?string
    {
        $value = $this->session->get('url.intended');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function regenerateCsrfToken(): string
    {
        return $this->session->regenerateToken();
    }

    // ---------------------------------------------------------- 一次性展示数据

    /** 暂存待确认的 TOTP 密钥（明文只存会话，不落库） */
    public function rememberTotpSetup(string $secret, string $uri): void
    {
        $this->session->set('totp_setup', ['secret' => $secret, 'uri' => $uri, 'at' => time()]);
    }

    public function pullTotpSetup(): ?array
    {
        $data = $this->session->get('totp_setup');

        return is_array($data) && isset($data['secret'], $data['uri']) ? $data : null;
    }

    public function forgetTotpSetup(): void
    {
        $this->session->forget('totp_setup');
    }

    /** 暂存刚生成的恢复码，仅展示一次 */
    public function rememberRecoveryCodes(array $codes): void
    {
        $this->session->set('recovery_codes_plain', $codes);
    }

    /** 读取并清除；刷新页面后不再显示 */
    public function pullRecoveryCodes(): array
    {
        $codes = $this->session->get('recovery_codes_plain');
        $this->session->forget('recovery_codes_plain');

        return is_array($codes) ? array_values($codes) : [];
    }

    // ------------------------------------------------- 微软邮件 OAuth 授权

    /** 暂存授权请求的 state 与 PKCE verifier */
    public function rememberMailOAuth(string $stateKey, string $verifierKey, string $atKey, string $state, string $verifier): void
    {
        $this->session->set($stateKey, $state);
        $this->session->set($verifierKey, $verifier);
        $this->session->set($atKey, time());
    }

    /**
     * 取出并清除授权请求数据；超过 $ttl 秒视为失效。
     *
     * @return array{state: string, verifier: string}|null
     */
    public function pullMailOAuth(string $stateKey, string $verifierKey, string $atKey, int $ttl): ?array
    {
        $state = $this->session->get($stateKey);
        $verifier = $this->session->get($verifierKey);
        $at = (int) $this->session->get($atKey, 0);

        $this->session->forget($stateKey);
        $this->session->forget($verifierKey);
        $this->session->forget($atKey);

        if (! is_string($state) || $state === '' || ! is_string($verifier) || $verifier === '') {
            return null;
        }

        if ($at === 0 || time() - $at > $ttl) {
            return null;
        }

        return ['state' => $state, 'verifier' => $verifier];
    }

    // ------------------------------------------------------ WebAuthn 挑战

    /** 保存 WebAuthn 挑战（一次性使用） */
    public function rememberWebauthnChallenge(string $key, object $options, mixed $extra = null): void
    {
        $this->session->set($key, [
            'options' => $options,
            'extra' => $extra,
            'at' => time(),
        ]);
    }

    /** @return array{0: object, 1: mixed, 2: int}|null [选项, 附加数据, 创建时间] */
    public function pullWebauthnChallenge(string $key): ?array
    {
        $data = $this->session->get($key);
        $this->session->forget($key);

        if (! is_array($data) || ! isset($data['options'], $data['at'])) {
            return null;
        }

        return [$data['options'], $data['extra'] ?? null, (int) $data['at']];
    }

    public function rememberWebauthnError(string $message): void
    {
        $this->session->set('webauthn_error', mb_substr($message, 0, 200));
    }

    public function pullWebauthnError(): ?string
    {
        $message = $this->session->get('webauthn_error');
        $this->session->forget('webauthn_error');

        return is_string($message) ? $message : null;
    }

    /** 二次验证失败提示，仅展示一次 */
    public function rememberMfaError(string $message): void
    {
        $this->session->set('mfa_error', $message);
    }

    public function pullMfaError(): ?string
    {
        $message = $this->session->get('mfa_error');
        $this->session->forget('mfa_error');

        return is_string($message) ? $message : null;
    }

    /** 新建或轮换后暂存客户端密钥，仅展示一次 */
    public function rememberClientSecret(string $secret): void
    {
        $this->session->set('client_secret_plain', $secret);
    }

    public function pullClientSecret(): ?string
    {
        $secret = $this->session->get('client_secret_plain');
        $this->session->forget('client_secret_plain');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /** 同意页需要跨请求保留 nonce（POST 没有 query string） */
    public function rememberAuthNonce(string $nonce): void
    {
        $this->session->set('oauth.auth_nonce', mb_substr($nonce, 0, 255));
    }

    /** 取出并清除 nonce */
    public function pullAuthNonce(): ?string
    {
        $nonce = $this->session->get('oauth.auth_nonce');
        $this->session->forget('oauth.auth_nonce');

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }

    // ------------------------------------------------- OAuth 授权请求暂存

    /**
     * 暂存授权请求对象。
     *
     * AuthorizationRequest 是普通对象，序列化后存会话；设置时间戳防止陈旧请求被复用。
     */
    public function rememberOAuthRequest(string $key, string $atKey, object $authRequest): void
    {
        $this->session->set($key, $authRequest);
        $this->session->set($atKey, time());
    }

    /** 取出并清除；超过 $ttl 秒视为失效 */
    public function pullOAuthRequest(string $key, string $atKey, int $ttl): ?object
    {
        $authRequest = $this->session->get($key);
        $at = (int) $this->session->get($atKey, 0);

        $this->session->forget($key);
        $this->session->forget($atKey);

        if (! is_object($authRequest) || $at === 0 || time() - $at > $ttl) {
            return null;
        }

        return $authRequest;
    }
}
