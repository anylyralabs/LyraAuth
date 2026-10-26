<?php

declare(strict_types=1);

namespace App\Service;

use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;

/**
 * 微软账号 OAuth2 授权（用于 SMTP XOAUTH2）。
 *
 * 流程：
 * 1. buildAuthorizationUrl() 生成授权链接，用户在浏览器里手动同意；
 * 2. 微软回调 /admin/settings/mail/oauth/callback?code=...&state=...；
 * 3. exchangeCode() 换取 access_token + refresh_token，持久化到 settings；
 * 4. MailService 每次发信前用 accessToken() 取令牌（过期自动刷新）。
 *
 * 说明：
 * - 个人账号使用 consumers 端点，工作/学校账号可用 organizations 或具体租户 ID；
 * - 需要 offline_access 才能拿到 refresh_token；
 * - SMTP 权限的 scope 是 https://outlook.office.com/SMTP.Send（非 Graph 的 Mail.Send）。
 */
class MicrosoftOAuthService
{
    /** 个人 Microsoft 账户的默认租户端点 */
    public const DEFAULT_TENANT = 'consumers';

    /** SMTP 发信权限（v1 scope，v2 端点接受完整 URL 形式） */
    public const SCOPE = 'https://outlook.office.com/SMTP.Send offline_access openid profile email';

    /** 提前多少秒视为过期，避免临界点失败 */
    private const EXPIRY_SKEW = 120;

    public function __construct(
        private readonly ConfigInterface $config,
        private readonly SettingsService $settings,
        private readonly ContainerInterface $container
    ) {
    }

    // ------------------------------------------------------------ 配置读写

    public function clientId(): string
    {
        return trim((string) $this->settings->get('mail_oauth_client_id', ''));
    }

    public function clientSecret(): string
    {
        return (string) $this->settings->get('mail_oauth_client_secret', '');
    }

    public function tenant(): string
    {
        $tenant = trim((string) $this->settings->get('mail_oauth_tenant', self::DEFAULT_TENANT));

        return $tenant !== '' ? $tenant : self::DEFAULT_TENANT;
    }

    public function redirectUri(): string
    {
        return rtrim((string) $this->config->get('app_url', 'http://localhost:9501'), '/')
            . '/admin/settings/mail/oauth/callback';
    }

    public function isConfigured(): bool
    {
        return $this->clientId() !== '';
    }

    public function isAuthorized(): bool
    {
        return $this->refreshToken() !== '';
    }

    public function refreshToken(): string
    {
        return (string) $this->settings->get('mail_oauth_refresh_token', '');
    }

    /** 授权账号的邮箱（用于展示与 XOAUTH2 用户名） */
    public function accountEmail(): string
    {
        return (string) $this->settings->get('mail_oauth_account', '');
    }

    public function authorizedAt(): ?string
    {
        $value = $this->settings->get('mail_oauth_authorized_at', '');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** 保存应用凭据（client_id / secret / tenant） */
    public function saveApplication(string $clientId, string $clientSecret, string $tenant): void
    {
        $this->settings->set('mail_oauth_client_id', trim($clientId), 'mail');
        if ($clientSecret !== '') {
            $this->settings->set('mail_oauth_client_secret', $clientSecret, 'mail', true);
        }
        $this->settings->set('mail_oauth_tenant', trim($tenant) !== '' ? trim($tenant) : self::DEFAULT_TENANT, 'mail');
    }

    /** 撤销授权（清空令牌，保留应用凭据） */
    public function revokeAuthorization(): void
    {
        foreach ([
            'mail_oauth_refresh_token',
            'mail_oauth_access_token',
            'mail_oauth_id_token',
            'mail_oauth_expires_at',
            'mail_oauth_account',
            'mail_oauth_authorized_at',
        ] as $key) {
            $this->settings->set($key, null, 'mail');
        }
    }

    // ------------------------------------------------------------ 授权流程

    /**
     * 生成授权链接。
     *
     * 使用 PKCE（S256）：即使不填 client_secret 也能工作，填了则额外带上密钥。
     *
     * @return array{url: string, state: string, code_verifier: string}
     */
    public function buildAuthorizationUrl(): array
    {
        $state = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $params = [
            'client_id' => $this->clientId(),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'response_mode' => 'query',
            'scope' => self::SCOPE,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            // 每次都显示同意页，便于切换账号或重新授权
            'prompt' => 'select_account',
        ];

        return [
            'url' => $this->authorizeEndpoint() . '?' . http_build_query($params),
            'state' => $state,
            'code_verifier' => $verifier,
        ];
    }

    /**
     * 用授权码换取令牌。
     *
     * @return array{ok: bool, email?: string, error?: string}
     */
    public function exchangeCode(string $code, string $codeVerifier): array
    {
        $payload = [
            'client_id' => $this->clientId(),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'code_verifier' => $codeVerifier,
            'scope' => self::SCOPE,
        ];

        $secret = $this->clientSecret();
        if ($secret !== '') {
            $payload['client_secret'] = $secret;
        }

        $result = $this->postTokenEndpoint($payload);
        if (! isset($result['access_token'])) {
            return ['ok' => false, 'error' => $this->describeError($result)];
        }

        $this->storeTokens($result);

        return ['ok' => true, 'email' => $this->accountEmail()];
    }

    /**
     * 取可用的访问令牌，过期则自动刷新。
     *
     * @return array{ok: bool, token?: string, error?: string}
     */
    public function accessToken(): array
    {
        $cached = (string) $this->settings->get('mail_oauth_access_token', '');
        $expiresAt = (int) $this->settings->get('mail_oauth_expires_at', 0);

        if ($cached !== '' && $expiresAt > time() + self::EXPIRY_SKEW) {
            return ['ok' => true, 'token' => $this->decryptIfNeeded('mail_oauth_access_token', $cached)];
        }

        return $this->refresh();
    }

    /**
     * 用 refresh_token 换新令牌。
     *
     * @return array{ok: bool, token?: string, error?: string}
     */
    public function refresh(): array
    {
        $refreshToken = $this->refreshToken();
        if ($refreshToken === '') {
            return ['ok' => false, 'error' => '尚未授权微软账号。'];
        }

        $payload = [
            'client_id' => $this->clientId(),
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->decryptIfNeeded('mail_oauth_refresh_token', $refreshToken),
            'scope' => self::SCOPE,
        ];

        $secret = $this->clientSecret();
        if ($secret !== '') {
            $payload['client_secret'] = $secret;
        }

        $result = $this->postTokenEndpoint($payload);
        if (! isset($result['access_token'])) {
            return ['ok' => false, 'error' => $this->describeError($result)];
        }

        $this->storeTokens($result);

        return ['ok' => true, 'token' => (string) $result['access_token']];
    }

    // -------------------------------------------------------------- 内部实现

    private function authorizeEndpoint(): string
    {
        return sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/authorize', rawurlencode($this->tenant()));
    }

    private function tokenEndpoint(): string
    {
        return sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/token', rawurlencode($this->tenant()));
    }

    /** @return array<string, mixed> */
    private function postTokenEndpoint(array $payload): array
    {
        $client = new \Swoole\Coroutine\Http\Client('login.microsoftonline.com', 443, true);
        $client->set([
            'timeout' => 20,
            'ssl_verify_peer' => true,
        ]);

        // 路径中的租户 ID 允许字母、数字、点与连字符，不做 URL 编码以免破坏路径
        $path = '/' . $this->tenant() . '/oauth2/v2.0/token';

        try {
            // Swoole 的 post() 只接受 (path, body)，请求头必须用 setHeaders 设置
            $client->setHeaders([
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ]);

            $client->post($path, http_build_query($payload));

            $status = $client->statusCode;
            $body = (string) $client->body;
        } finally {
            $client->close();
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return ['error' => 'invalid_response', 'error_description' => '令牌端点返回了非 JSON 内容（HTTP ' . $status . '）'];
        }

        return $decoded;
    }

    /**
     * 保存令牌。
     *
     * refresh_token 加密存储；access_token 也加密，避免设置页泄露即可长期使用。
     *
     * @param array<string, mixed> $result
     */
    private function storeTokens(array $result): void
    {
        $crypto = $this->container->get(\App\Support\Crypto::class);

        $accessToken = (string) ($result['access_token'] ?? '');
        $expiresIn = (int) ($result['expires_in'] ?? 3600);

        if ($accessToken !== '') {
            $this->settings->set('mail_oauth_access_token', $crypto->encrypt($accessToken), 'mail');
            $this->settings->set('mail_oauth_expires_at', (string) (time() + $expiresIn), 'mail');
        }

        // id_token 里带 preferred_username（账号邮箱），加密保存供展示
        $idToken = (string) ($result['id_token'] ?? '');
        if ($idToken !== '') {
            $this->settings->set('mail_oauth_id_token', $crypto->encrypt($idToken), 'mail');
        }

        // 微软只在首次授权或需要时返回新的 refresh_token，未返回则保留旧的
        $newRefresh = (string) ($result['refresh_token'] ?? '');
        if ($newRefresh !== '') {
            $this->settings->set('mail_oauth_refresh_token', $crypto->encrypt($newRefresh), 'mail');
            $this->settings->set('mail_oauth_authorized_at', date('Y-m-d H:i:s'), 'mail');
        }

        $email = $this->extractEmail($accessToken);
        if ($email !== '') {
            $this->settings->set('mail_oauth_account', $email, 'mail');
        }
    }

    /** 从 id_token 中解析邮箱（避免额外调用 Graph） */
    private function extractEmail(string $accessToken): string
    {
        $idToken = (string) $this->settings->get('mail_oauth_id_token', '');
        if ($idToken === '') {
            return '';
        }

        $parts = explode('.', $this->decryptIfNeeded('mail_oauth_id_token', $idToken));
        if (count($parts) !== 3) {
            return '';
        }

        $payload = strtr($parts[1], '-_', '+/');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $claims = json_decode((string) base64_decode($payload, true), true);

        if (! is_array($claims)) {
            return '';
        }

        foreach (['preferred_username', 'email', 'upn', 'unique_name'] as $key) {
            if (! empty($claims[$key]) && is_string($claims[$key])) {
                return $claims[$key];
            }
        }

        return '';
    }

    private function decryptIfNeeded(string $key, string $value): string
    {
        if ($value === '') {
            return '';
        }

        // 加密值统一带 v1. 前缀
        if (! str_starts_with($value, 'v1.')) {
            return $value;
        }

        try {
            return $this->container->get(\App\Support\Crypto::class)->decrypt($value);
        } catch (\Throwable) {
            return '';
        }
    }

    /** @param array<string, mixed> $result */
    private function describeError(array $result): string
    {
        $error = (string) ($result['error'] ?? 'unknown_error');
        $description = (string) ($result['error_description'] ?? '');
        $codes = $result['error_codes'] ?? null;

        $text = $error;
        if ($description !== '') {
            // 微软的描述常带换行与跟踪 ID，截断便于展示
            $text .= '：' . mb_substr(preg_replace('/\s+/', ' ', $description) ?? $description, 0, 300);
        }
        if (is_array($codes) && $codes !== []) {
            $text .= '（错误码 ' . implode(', ', array_map('strval', $codes)) . '）';
        }

        return $text;
    }
}
