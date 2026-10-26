<?php

declare(strict_types=1);

namespace App\Controller\OAuth;

use App\Controller\AbstractController;
use App\Model\OauthAccessToken;
use App\Model\OauthRefreshToken;
use App\OAuth\Repository\ClientRepository;
use App\OAuth\Repository\RefreshTokenRepository;
use App\Service\AuditService;
use App\Service\OAuthServerService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\PostMapping;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 令牌吊销（RFC 7009）与自省（RFC 7662）。
 *
 * 两者都要求客户端认证；自省只对资源服务器开放，
 * 未注册或已过期的令牌一律返回 active=false。
 */
#[Controller]
class TokenAdminController extends AbstractController
{
    public function __construct(
        private readonly ClientRepository $clients,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly OAuthServerService $oauth,
        private readonly AuditService $audit
    ) {
    }

    #[PostMapping(path: '/oauth/revoke')]
    public function revoke(ServerRequestInterface $request): ResponseInterface
    {
        $parsed = $this->parsedBody($request);
        $token = (string) ($parsed['token'] ?? '');
        $tokenType = (string) ($parsed['token_type_hint'] ?? '');

        // 按 RFC 7009，无论令牌是否存在都返回 200
        if ($token === '') {
            return $this->emptyOk();
        }

        $clientId = $this->authenticateClient($request, $parsed);
        if ($clientId === null) {
            return $this->jsonError('invalid_client', '客户端认证失败。', 401);
        }

        // 访问令牌是 JWT：先按签发记录反查 jti
        $revoked = false;

        if ($tokenType !== 'refresh_token') {
            $revoked = $this->revokeAccessToken($token, $clientId);
        }

        if (! $revoked) {
            $decrypted = $this->oauth->decryptPayload($token);
            if ($decrypted !== null && isset($decrypted['refresh_token_id'])) {
                $record = $this->refreshTokens->findByJti((string) $decrypted['refresh_token_id']);
                if ($record !== null && $this->clientMatches($record->client_id, $clientId)) {
                    $this->refreshTokens->revokeChain((string) $decrypted['refresh_token_id']);
                    $revoked = true;
                }
            }
        }

        $this->audit->log('oauth.token.revoked', $revoked ? 'success' : 'failure', null, 'oauth_client', $clientId, [
            'token_type_hint' => $tokenType,
        ]);

        return $this->emptyOk();
    }

    #[PostMapping(path: '/oauth/introspect')]
    public function introspect(ServerRequestInterface $request): ResponseInterface
    {
        $parsed = $this->parsedBody($request);
        $token = (string) ($parsed['token'] ?? '');

        $clientId = $this->authenticateClient($request, $parsed);
        if ($clientId === null) {
            return $this->jsonError('invalid_client', '客户端认证失败。', 401);
        }

        if ($token === '') {
            return $this->response->json(['active' => false]);
        }

        $result = $this->introspectAccessToken($token, $clientId);

        return $this->response
            ->withHeader('Cache-Control', 'no-store')
            ->json($result ?? ['active' => false]);
    }

    // ------------------------------------------------------------------ 内部

    /**
     * 校验访问令牌：解析 JWT 载荷后核对数据库记录。
     *
     * @return array<string, mixed>|null
     */
    private function introspectAccessToken(string $token, string $clientId): ?array
    {
        $claims = $this->decodeJwtPayload($token);
        if ($claims === null || ! isset($claims['jti'])) {
            return null;
        }

        $record = OauthAccessToken::query()->where('jti', (string) $claims['jti'])->first();
        if ($record === null || ! $record->isActive()) {
            return null;
        }

        $client = \App\Model\OauthClient::query()->find($record->client_id);
        if ($client === null || $client->client_id !== $clientId) {
            return null;
        }

        $user = $record->user_id !== null ? \App\Model\User::query()->find($record->user_id) : null;

        return [
            'active' => true,
            'scope' => $record->scopes,
            'client_id' => $client->client_id,
            'token_type' => 'Bearer',
            'exp' => $record->expires_at?->getTimestamp(),
            'iat' => (int) ($claims['iat'] ?? 0),
            'sub' => $user?->uuid,
            'aud' => $client->client_id,
            'iss' => (string) ($claims['iss'] ?? ''),
            'jti' => $record->jti,
        ];
    }

    private function revokeAccessToken(string $token, string $clientId): bool
    {
        $claims = $this->decodeJwtPayload($token);
        if ($claims === null || ! isset($claims['jti'])) {
            return false;
        }

        $record = OauthAccessToken::query()->where('jti', (string) $claims['jti'])->first();
        if ($record === null) {
            return false;
        }

        if (! $this->clientMatches($record->client_id, $clientId)) {
            return false;
        }

        if ($record->revoked_at === null) {
            $record->revoked_at = date('Y-m-d H:i:s');
            $record->save();
        }

        return true;
    }

    /** @return array<string, mixed>|null */
    private function decodeJwtPayload(string $token): ?array
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

    /**
     * 客户端认证：支持 client_secret_basic 与 client_secret_post。
     *
     * @param array<string, mixed> $parsed
     */
    private function authenticateClient(ServerRequestInterface $request, array $parsed): ?string
    {
        $header = $request->getHeaderLine('Authorization');

        if (str_starts_with(strtolower($header), 'basic ')) {
            $decoded = base64_decode(substr($header, 6), true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$id, $secret] = explode(':', $decoded, 2);
                // Basic 头里可能做了 urlencode
                $id = urldecode($id);
                $secret = urldecode($secret);
                if ($this->clients->validateClient($id, $secret, null)) {
                    return $id;
                }
            }
        }

        $id = (string) ($parsed['client_id'] ?? '');
        $secret = isset($parsed['client_secret']) ? (string) $parsed['client_secret'] : null;

        if ($id !== '' && $this->clients->validateClient($id, $secret, null)) {
            return $id;
        }

        return null;
    }

    private function clientMatches(int $internalClientId, string $clientId): bool
    {
        $client = \App\Model\OauthClient::query()->find($internalClientId);

        return $client !== null && $client->client_id === $clientId;
    }

    /** @return array<string, mixed> */
    private function parsedBody(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed !== []) {
            return $parsed;
        }

        parse_str((string) $request->getBody(), $parsed);

        return is_array($parsed) ? $parsed : [];
    }

    private function emptyOk(): ResponseInterface
    {
        return $this->response
            ->withStatus(200)
            ->withHeader('Cache-Control', 'no-store')
            ->raw('');
    }

    private function jsonError(string $error, string $message, int $status): ResponseInterface
    {
        return $this->response
            ->withStatus($status)
            ->withHeader('Cache-Control', 'no-store')
            ->json(['error' => $error, 'message' => $message]);
    }
}
