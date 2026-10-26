<?php

declare(strict_types=1);

namespace App\OAuth\Repository;

use App\Model\OauthAccessToken;
use App\Model\User;
use App\OAuth\Entity\AccessTokenEntity;
use App\Service\OAuthContext;
use App\Service\SessionGuard;
use App\Service\TokenClaimService;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;

class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function __construct(
        private readonly SessionGuard $guard,
        private readonly TokenClaimService $claims,
        private readonly OAuthContext $context
    ) {
    }

    /**
     * 创建令牌实体时即注入 OIDC 声明。
     *
     * league 在 grant 内部直接调用 toString() 签发，没有插入点，
     * 因此 iss / auth_time / amr / sid 必须在这一步写入。
     */
    public function getNewToken(
        ClientEntityInterface $clientEntity,
        array $scopes,
        ?string $userIdentifier = null
    ): AccessTokenEntityInterface {
        $token = new AccessTokenEntity();
        $token->setClient($clientEntity);

        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }

        if ($userIdentifier !== null && $userIdentifier !== '') {
            $token->setUserIdentifier($userIdentifier);
        }

        $sessionId = null;
        $authTime = 0;
        $amr = [];

        // 优先使用授权阶段传递的上下文（令牌请求没有会话 Cookie）
        $authContext = $this->context->authContext();
        if (is_array($authContext)) {
            $sessionId = $authContext['sid'] ?? null;
            $authTime = (int) ($authContext['auth_time'] ?? 0);
            $amr = (array) ($authContext['amr'] ?? []);
        } elseif ($this->guard->isStarted() && $this->guard->check()) {
            $sessionId = $this->guard->currentSessionId();
            $authTime = $this->guard->authTime();
            $amr = $this->guard->amr();
        }

        $this->claims->decorate(
            $token,
            is_string($sessionId) && $sessionId !== '' ? $sessionId : null,
            $amr !== [] ? $amr : ['pwd'],
            $authTime > 0 ? $authTime : time()
        );

        return $token;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $client = \App\Model\OauthClient::query()
            ->where('client_id', $accessTokenEntity->getClient()->getIdentifier())
            ->first();

        if ($client === null) {
            return;
        }

        $userIdentifier = $accessTokenEntity->getUserIdentifier();
        $user = $userIdentifier !== null
            ? User::query()->where('uuid', $userIdentifier)->first()
            : null;

        $scopes = array_map(
            static fn ($scope) => $scope->getIdentifier(),
            $accessTokenEntity->getScopes()
        );

        OauthAccessToken::create([
            'jti' => $accessTokenEntity->getIdentifier(),
            'client_id' => $client->id,
            'user_id' => $user?->id,
            'scopes' => implode(' ', $scopes),
            'session_id' => $accessTokenEntity instanceof AccessTokenEntity
                ? ($accessTokenEntity->getSessionId() ?: null)
                : null,
            'expires_at' => $accessTokenEntity->getExpiryDateTime()->format('Y-m-d H:i:s'),
        ]);
    }

    public function revokeAccessToken(string $tokenId): void
    {
        OauthAccessToken::query()
            ->where('jti', $tokenId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => date('Y-m-d H:i:s')]);
    }

    public function isAccessTokenRevoked(string $tokenId): bool
    {
        $token = OauthAccessToken::query()->where('jti', $tokenId)->first();

        // 未落库视为已吊销，避免内存态令牌被误用
        if ($token === null) {
            return true;
        }

        return $token->revoked_at !== null;
    }
}
