<?php

declare(strict_types=1);

namespace App\Controller\OAuth;

use App\Controller\AbstractController;
use App\Service\AuditService;
use App\Service\OAuthServerService;
use App\OAuth\Repository\ClientRepository;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\PostMapping;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 令牌端点与相关机器接口。
 *
 * 这些端点不依赖会话 Cookie，也不做 CSRF 校验，
 * 客户端身份由 client_secret 或 PKCE 保证。
 */
#[Controller]
class TokenController extends AbstractController
{
    public function __construct(
        private readonly OAuthServerService $oauth,
        private readonly ClientRepository $clients,
        private readonly \App\OAuth\Repository\AuthCodeRepository $authCodes,
        private readonly \App\OAuth\Repository\DeviceCodeRepository $deviceCodes,
        private readonly \App\Service\OAuthContext $oauthContext,
        private readonly AuditService $audit
    ) {
    }

    #[PostMapping(path: '/oauth/token')]
    public function token(ServerRequestInterface $request): ResponseInterface
    {
        $parsed = $this->parsedBody($request);
        $response = \Hyperf\Context\ResponseContext::get();

        // 授权码流程：令牌请求没有会话 Cookie，从授权码记录恢复会话上下文
        if (($parsed['grant_type'] ?? '') === 'authorization_code') {
            $clientId = (string) ($parsed['client_id'] ?? $this->basicAuthClientId($request) ?? '');
            $context = $clientId !== '' ? $this->authCodes->latestAuthContext($clientId) : null;
            if ($context !== null) {
                $this->oauthContext->setAuthContext($context);
                $this->oauthContext->setNonce($context['nonce'] ?? null);
            }
        }

        if (($parsed['grant_type'] ?? '') === 'refresh_token' && isset($parsed['refresh_token'])) {
            $decrypted = $this->oauth->decryptPayload((string) $parsed['refresh_token']);
            if ($decrypted !== null && isset($decrypted['refresh_token_id'])) {
                $record = \App\Model\OauthRefreshToken::query()
                    ->where('jti', (string) $decrypted['refresh_token_id'])
                    ->first();
                if ($record !== null && $record->session_id !== null) {
                    $this->oauthContext->setAuthContext([
                        'sid' => $record->session_id,
                        'amr' => ['pwd'],
                        'auth_time' => time(),
                    ]);
                }
            }
        }

        // 设备码流程：轮询请求不带 Cookie，从已批准的记录恢复会话
        if (($parsed['grant_type'] ?? '') === 'urn:ietf:params:oauth:grant-type:device_code' && isset($parsed['device_code'])) {
            $context = $this->deviceCodes->authContextForDeviceCode((string) $parsed['device_code']);
            if ($context !== null) {
                $this->oauthContext->setAuthContext($context);
            }
        }

        try {
            $result = $this->oauth->respondToAccessTokenRequest(
                $request->withParsedBody($parsed),
                $response
            );

            $clientId = (string) ($parsed['client_id'] ?? $this->basicAuthClientId($request) ?? '');
            if ($clientId !== '') {
                $this->clients->touchLastUsed($clientId);
            }

            $this->audit->log('oauth.token.issued', 'success', null, 'oauth_client', $clientId, [
                'grant_type' => (string) ($parsed['grant_type'] ?? ''),
            ]);

            return $this->noStore($result);
        } catch (OAuthServerException $e) {
            $this->audit->log('oauth.token.failed', 'failure', null, null, null, [
                'error' => $e->getErrorType(),
                'hint' => $e->getHint(),
            ]);

            return $this->noStore($e->generateHttpResponse($response));
        }
    }

    #[PostMapping(path: '/oauth/device_authorization')]
    public function deviceAuthorization(ServerRequestInterface $request): ResponseInterface
    {
        $parsed = $this->parsedBody($request);
        $response = \Hyperf\Context\ResponseContext::get();

        try {
            return $this->noStore(
                $this->oauth->respondToDeviceAuthorizationRequest($request->withParsedBody($parsed), $response)
            );
        } catch (OAuthServerException $e) {
            return $this->noStore($e->generateHttpResponse($response));
        }
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

    /** 从 Basic 认证头取 client_id */
    private function basicAuthClientId(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if (! str_starts_with(strtolower($header), 'basic ')) {
            return null;
        }

        $decoded = base64_decode(substr($header, 6), true);
        if ($decoded === false || ! str_contains($decoded, ':')) {
            return null;
        }

        [$id] = explode(':', $decoded, 2);

        return $id !== '' ? $id : null;
    }

    private function noStore(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}
