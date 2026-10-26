<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\OauthClient;
use App\Model\OauthScope;
use App\Model\User;
use App\OAuth\Entity\ClientEntity;
use App\OAuth\Entity\DeviceCodeEntity;
use App\OAuth\Repository\AccessTokenRepository;
use App\OAuth\ResponseType\OidcBearerTokenResponse;
use App\OAuth\Repository\AuthCodeRepository;
use App\OAuth\Repository\ClientRepository;
use App\OAuth\Repository\DeviceCodeRepository;
use App\OAuth\Repository\RefreshTokenRepository;
use App\OAuth\Repository\ScopeRepository;
use Hyperf\Contract\ConfigInterface;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\ClientCredentialsGrant;
use League\OAuth2\Server\Grant\DeviceCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * OAuth2 授权服务器装配。
 *
 * 授权码、刷新轮换、客户端凭证、设备码四条流共用同一个 AuthorizationServer；
 * 访问令牌为 RS256 JWT，声明中带 iss / sid / auth_time / amr，供 OIDC 与单点登出使用。
 */
class OAuthServerService
{
    private ?AuthorizationServer $server = null;

    public function __construct(
        private readonly ConfigInterface $config,
        private readonly OAuthKeyService $keys,
        private readonly ClientRepositoryInterface $clients,
        private readonly ScopeRepositoryInterface $scopes,
        private readonly AccessTokenRepositoryInterface $accessTokens,
        private readonly RefreshTokenRepositoryInterface $refreshTokens,
        private readonly AuthCodeRepositoryInterface $authCodes,
        private readonly DeviceCodeRepositoryInterface $deviceCodes
    ) {
    }

    public function server(): AuthorizationServer
    {
        if ($this->server !== null) {
            return $this->server;
        }

        $server = new AuthorizationServer(
            $this->clients,
            $this->accessTokens,
            $this->scopes,
            $this->keys->privateKey(),
            $this->encryptionKey(),
            new OidcBearerTokenResponse()
        );

        // 公共客户端强制 PKCE：league 默认 requireCodeChallengeForPublicClients = true
        $authCodeGrant = new AuthCodeGrant(
            $this->authCodes,
            $this->refreshTokens,
            new \DateInterval('PT' . $this->ttl('auth_code', 60) . 'S')
        );
        $server->enableGrantType($authCodeGrant, new \DateInterval('PT' . $this->ttl('access_token', 900) . 'S'));

        // 刷新令牌轮换：league 9.x 默认每次刷新都签发新令牌并吊销旧链
        $refreshGrant = new RefreshTokenGrant($this->refreshTokens);
        $server->enableGrantType($refreshGrant, new \DateInterval('PT' . $this->ttl('access_token', 900) . 'S'));

        // 客户端凭证
        $server->enableGrantType(new ClientCredentialsGrant(), new \DateInterval('PT' . $this->ttl('access_token', 900) . 'S'));

        // 设备码（RFC 8628）
        $deviceGrant = new DeviceCodeGrant(
            $this->deviceCodes,
            $this->refreshTokens,
            new \DateInterval('PT' . $this->ttl('device_code', 600) . 'S'),
            $this->baseUrl() . '/device',
            5
        );
        $server->enableGrantType($deviceGrant, new \DateInterval('PT' . $this->ttl('access_token', 900) . 'S'));

        return $this->server = $server;
    }

    /** 解析授权请求（校验 client_id / redirect_uri / scope / PKCE） */
    public function validateAuthorizationRequest(ServerRequestInterface $request): \League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface
    {
        return $this->server()->validateAuthorizationRequest($request);
    }

    /** 用户同意后完成授权请求 */
    public function completeAuthorizationRequest(
        \League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface $authRequest,
        ResponseInterface $response
    ): ResponseInterface {
        return $this->server()->completeAuthorizationRequest($authRequest, $response);
    }

    /** 令牌端点 */
    public function respondToAccessTokenRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->server()->respondToAccessTokenRequest($request, $response);
    }

    /** 设备授权端点（返回 device_code / user_code） */
    public function respondToDeviceAuthorizationRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->server()->respondToDeviceAuthorizationRequest($request, $response);
    }

    /** 用户批准或拒绝设备码 */
    public function completeDeviceAuthorizationRequest(string $deviceCode, string $userId, bool $approved): void
    {
        $this->server()->completeDeviceAuthorizationRequest($deviceCode, $userId, $approved);
    }

    /** 为授权请求附加用户与登录态信息 */
    public function approveAuthorization(\League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface $authRequest, User $user): void
    {
        $authRequest->setUser(new class($user->uuid) implements \League\OAuth2\Server\Entities\UserEntityInterface {
            public function __construct(private readonly string $identifier)
            {
            }

            public function getIdentifier(): string
            {
                return $this->identifier;
            }
        });
        $authRequest->setAuthorizationApproved(true);
    }

    public function issuer(): string
    {
        $configured = (string) $this->config->get('oauth.issuer', '');

        return $configured !== '' ? rtrim($configured, '/') : $this->baseUrl();
    }

    public function baseUrl(): string
    {
        return rtrim((string) $this->config->get('app_url', 'http://localhost:9501'), '/');
    }

    public function ttl(string $name, int $default): int
    {
        $map = [
            'access_token' => 'oauth.access_token_ttl',
            'refresh_token' => 'oauth.refresh_token_ttl',
            'auth_code' => 'oauth.auth_code_ttl',
            'device_code' => 'oauth.device_code_ttl',
        ];

        return (int) $this->config->get($map[$name] ?? '', $default);
    }

    /** league 用对称密钥加密授权码/刷新令牌 payload */
    public function encryptionKey(): string
    {
        $appKey = (string) $this->config->get('app_key', '');
        if (strlen($appKey) < 32) {
            throw new \RuntimeException('APP_KEY 长度不足，无法作为 OAuth 加密密钥。');
        }

        return hash('sha256', 'lyraauth-oauth-encryption:' . $appKey);
    }

    /**
     * 解密 league 生成的授权码 / 刷新令牌 / 访问令牌载荷。
     *
     * @return array<string, mixed>|null
     */
    public function decryptPayload(string $ciphertext): ?array
    {
        try {
            $plain = \Defuse\Crypto\Crypto::decryptWithPassword($ciphertext, $this->encryptionKey());
            $payload = json_decode($plain, true);

            return is_array($payload) ? $payload : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
