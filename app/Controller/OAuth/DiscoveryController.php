<?php

declare(strict_types=1);

namespace App\Controller\OAuth;

use App\Controller\AbstractController;
use App\Model\OauthAccessToken;
use App\Model\User;
use App\Service\OAuthKeyService;
use App\Service\OAuthServerService;
use App\Service\SettingsService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * OIDC 发现文档、JWKS 与 userinfo。
 */
#[Controller]
class DiscoveryController extends AbstractController
{
    private const SUPPORTED_SCOPES = ['openid', 'profile', 'email', 'roles', 'organizations', 'offline_access'];

    public function __construct(
        private readonly OAuthServerService $oauth,
        private readonly OAuthKeyService $keys,
        private readonly SettingsService $settings
    ) {
    }

    #[GetMapping(path: '/.well-known/openid-configuration')]
    public function openidConfiguration(): ResponseInterface
    {
        return $this->respond($this->configuration());
    }

    #[GetMapping(path: '/.well-known/oauth-authorization-server')]
    public function authorizationServerMetadata(): ResponseInterface
    {
        // RFC 8414 与 OIDC 发现字段高度重合，取公共部分
        $config = $this->configuration();
        unset($config['userinfo_endpoint'], $config['id_token_signing_alg_values_supported'], $config['subject_types_supported']);

        return $this->respond($config);
    }

    #[GetMapping(path: '/.well-known/jwks.json')]
    public function jwks(): ResponseInterface
    {
        return $this->respond($this->keys->jwks(), 3600);
    }

    #[GetMapping(path: '/userinfo')]
    public function userinfo(ServerRequestInterface $request): ResponseInterface
    {
        $token = $this->bearerToken($request);
        if ($token === null) {
            return $this->unauthorized('缺少访问令牌。');
        }

        $claims = $this->oauth->decryptPayload($token);
        if ($claims === null) {
            // 访问令牌是 JWT，不是密文，改用 JWT 载荷解析
            $claims = $this->decodeJwt($token);
        }

        if ($claims === null || ! isset($claims['jti'])) {
            return $this->unauthorized('访问令牌无效。');
        }

        $record = OauthAccessToken::query()->where('jti', (string) $claims['jti'])->first();
        if ($record === null || ! $record->isActive()) {
            return $this->unauthorized('访问令牌已过期或已被吊销。');
        }

        $client = \App\Model\OauthClient::query()->find($record->client_id);
        $user = $record->user_id !== null ? User::query()->find($record->user_id) : null;

        if ($user === null || $client === null) {
            return $this->unauthorized('令牌未关联用户。');
        }

        $scopes = array_filter(explode(' ', (string) $record->scopes));
        if (! in_array('openid', $scopes, true)) {
            return $this->jsonError('invalid_scope', '该令牌未授予 openid scope。', 403);
        }

        $info = ['sub' => $user->uuid];

        if (in_array('profile', $scopes, true)) {
            $info['name'] = $user->display_name;
            $info['preferred_username'] = $user->username;
            $info['locale'] = $user->locale;
            $info['updated_at'] = $user->updated_at?->getTimestamp();
        }

        if (in_array('email', $scopes, true)) {
            $info['email'] = $user->email;
            $info['email_verified'] = $user->isEmailVerified();
        }

        if (in_array('roles', $scopes, true)) {
            $info['roles'] = $this->platformRoles($user);
        }

        if (in_array('organizations', $scopes, true)) {
            $info['organizations'] = $this->organizations($user);
        }

        return $this->respond($info);
    }

    // ------------------------------------------------------------------ 内部

    /** @return array<string, mixed> */
    private function configuration(): array
    {
        $issuer = $this->oauth->baseUrl();

        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/oauth/authorize',
            'token_endpoint' => $issuer . '/oauth/token',
            'userinfo_endpoint' => $issuer . '/userinfo',
            'jwks_uri' => $issuer . '/.well-known/jwks.json',
            'end_session_endpoint' => $issuer . '/oauth/logout',
            'revocation_endpoint' => $issuer . '/oauth/revoke',
            'introspection_endpoint' => $issuer . '/oauth/introspect',
            'device_authorization_endpoint' => $issuer . '/oauth/device_authorization',
            'scopes_supported' => self::SUPPORTED_SCOPES,
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => [
                'authorization_code',
                'refresh_token',
                'client_credentials',
                'urn:ietf:params:oauth:grant-type:device_code',
            ],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'revocation_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'introspection_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'code_challenge_methods_supported' => ['S256'],
            'claims_supported' => [
                'sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'nonce',
                'name', 'preferred_username', 'email', 'email_verified',
                'roles', 'organizations', 'amr', 'sid', 'at_hash',
            ],
            'claims_parameter_supported' => false,
            'request_parameter_supported' => false,
            'request_uri_parameter_supported' => false,
            'display_values_supported' => ['page'],
            'ui_locales_supported' => ['zh-CN', 'en'],
            'service_documentation' => $issuer . '/',
            'prompt_values_supported' => ['none', 'login', 'consent'],
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    /** @return list<string> */
    private function platformRoles(User $user): array
    {
        if ($user->isAdmin()) {
            return ['admin'];
        }

        $roles = \App\Model\Role::query()
            ->join('user_roles', 'user_roles.role_id', '=', 'roles.id')
            ->where('user_roles.user_id', $user->id)
            ->pluck('roles.slug')
            ->all();

        return array_values(array_unique(array_merge(['user'], $roles)));
    }

    /** @return list<array{id: string, name: string, role: string}> */
    private function organizations(User $user): array
    {
        $rows = \App\Model\OrganizationMember::query()
            ->join('organizations', 'organizations.id', '=', 'organization_members.organization_id')
            ->where('organization_members.user_id', $user->id)
            ->whereNull('organizations.deleted_at')
            ->get(['organizations.uuid', 'organizations.name', 'organization_members.role']);

        return $rows->map(static fn ($row) => [
            'id' => (string) $row->uuid,
            'name' => (string) $row->name,
            'role' => (string) $row->role,
        ])->all();
    }

    private function bearerToken(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if (! str_starts_with(strtolower($header), 'bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token !== '' ? $token : null;
    }

    /** @return array<string, mixed>|null */
    private function decodeJwt(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = strtr($parts[1], '-_', '+/');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return null;
        }

        $claims = json_decode($decoded, true);

        return is_array($claims) ? $claims : null;
    }

    private function respond(array $data, int $maxAge = 0): ResponseInterface
    {
        $response = $this->response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', $maxAge > 0 ? 'public, max-age=' . $maxAge : 'no-store')
            ->json($data);

        return $response;
    }

    private function unauthorized(string $message): ResponseInterface
    {
        return $this->response
            ->withStatus(401)
            ->withHeader('WWW-Authenticate', 'Bearer error="invalid_token"')
            ->json(['error' => 'invalid_token', 'message' => $message]);
    }

    private function jsonError(string $error, string $message, int $status): ResponseInterface
    {
        return $this->response->withStatus($status)->json([
            'error' => $error,
            'error_description' => $message,
        ]);
    }
}
